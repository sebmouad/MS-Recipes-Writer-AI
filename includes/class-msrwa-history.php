<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Everything a job went through, in order, written once and never rewritten.
 *
 * What the writer provided; what the pairing read on each photograph and
 * decided, and what the writer changed; the brief each recipe was handed —
 * its text and its visual reference — and what the engine completed of it;
 * then every step of the creation with its prompt, its answer, its score and
 * its cost; then the result. The tables that run the work keep only what the
 * work still needs and release the rest once WordPress holds the article; the
 * history is the record that stays.
 *
 * Kept twice, as the owner asked: a row per stage in `msrwa_history`, which
 * screens and questions read, and a numbered file per stage under the job's
 * folder, uploads/msrwa/<run>/history/, which a person can open. A lot's own
 * stages are written before any job exists, under its batch with run 0, and
 * copied into each job's folder when the lot is sent. Both follow the
 * artifacts retention age.
 */
final class MSRWA_History {

	/** A stage as a person reads it, in the order they happen. */
	public static function labels() {
		return array(
			'provided' => __( 'Ce que le rédacteur a fourni', 'ms-recipes-writer-ai' ),
			'matching' => __( 'Lecture des photographies et appariement', 'ms-recipes-writer-ai' ),
			'pairing' => __( 'Appariement corrigé par le rédacteur', 'ms-recipes-writer-ai' ),
			'brief' => __( 'Brief remis au moteur', 'ms-recipes-writer-ai' ),
			'web_reference' => __( 'Photographie trouvée par le moteur', 'ms-recipes-writer-ai' ),
			'engine_brief' => __( 'Brief complété par le moteur', 'ms-recipes-writer-ai' ),
			'step' => __( 'Étape de création', 'ms-recipes-writer-ai' ),
			'result' => __( 'Résultat', 'ms-recipes-writer-ai' ),
		);
	}

	/** One of a lot's stages, before any job exists. */
	public static function lot( $lot, $stage, array $data ) {
		self::write( absint( $lot ), 0, $stage, $data, MSRWA_Sources::lot_dir( $lot ) . '/history' );
	}

	/** One of a job's stages. */
	public static function run( $run, $stage, array $data, $lot = 0 ) {
		$run = absint( $run );
		if ( ! $lot && class_exists( 'MSRWA_Run' ) ) {
			$row = MSRWA_Run::get( $run );
			$lot = $row ? (int) $row['batch_id'] : 0;
		}
		self::write( (int) $lot, $run, $stage, $data, self::dir( $run ) );
	}

	public static function dir( $run ) { return MSRWA_Sources::root() . '/' . absint( $run ) . '/history'; }

	/** A lot's stages become the first pages of each of its jobs' folders. */
	public static function inherit( $lot, $run ) {
		$to = self::dir( $run );
		wp_mkdir_p( $to );
		foreach ( (array) glob( MSRWA_Sources::lot_dir( $lot ) . '/history/*.json' ) as $file ) {
			@copy( $file, $to . '/' . basename( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/** A job's whole history, its lot's stages first, as the rows hold it. */
	public static function for_run( $run ) {
		global $wpdb;
		$row = MSRWA_Run::get( $run );
		if ( ! $row ) { return array(); }
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT stage, step, content_json, created_at FROM ' . self::table() . ' WHERE ( batch_id = %d AND run_id = 0 ) OR run_id = %d ORDER BY id ASC',
			(int) $row['batch_id'], absint( $run ) ), ARRAY_A );
		foreach ( $rows as &$one ) {
			$one['content'] = json_decode( (string) $one['content_json'], true );
			unset( $one['content_json'] );
		}
		return $rows;
	}

	public static function forget_lot( $lot ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE batch_id = %d', absint( $lot ) ) );
	}

	public static function forget_run( $run ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE run_id = %d', absint( $run ) ) );
		self::remove_files( $run );
	}

	/** The files of one job's history; the job's photographs and images stay. */
	public static function remove_files( $run ) {
		foreach ( (array) glob( self::dir( $run ) . '/*.json' ) as $file ) { @unlink( $file ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@rmdir( self::dir( $run ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function table() { return MSRWA_DB::tables()['history']; }

	private static function write( $lot, $run, $stage, array $data, $dir ) {
		global $wpdb;
		$step = 'step' === $stage ? sanitize_key( (string) ( $data['step'] ?? '' ) ) : '';
		// Anything a provider or a model wrote may carry a secret; nothing does
		// once it has been through here.
		$content = MSRWA_DB::sanitize( $data );
		$json = (string) wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$wpdb->insert( self::table(), array(
			'batch_id' => $lot, 'run_id' => $run, 'stage' => sanitize_key( $stage ), 'step' => $step,
			'content_json' => $json, 'bytes' => strlen( $json ), 'created_at' => current_time( 'mysql', true ),
		) );

		wp_mkdir_p( $dir );
		$number = count( (array) glob( $dir . '/*.json' ) ) + 1;
		$name = sprintf( '%02d-%s%s.json', $number, sanitize_key( $stage ), '' === $step ? '' : '-' . $step );
		@file_put_contents( $dir . '/' . $name, (string) wp_json_encode( array( 'stage' => $stage, 'at' => gmdate( 'c' ) ) + $content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
