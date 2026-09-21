<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the engine produced, turned into a draft an editor can open.
 *
 * A draft, never a published post, and never phrased as approved: the engine's
 * judge decides whether an article holds together, not whether this site wants
 * it. The verdict travels with the draft so the editor reads it before
 * publishing rather than after.
 */
final class MSRWA_Draft {

	/**
	 * Creates the draft for a finished run, once.
	 *
	 * A run that produced no article produces no draft — a post with a title and
	 * nothing under it is worse than no post, because it looks like work that
	 * succeeded.
	 */
	public static function create( $run_id ) {
		$run = MSRWA_Run::get( $run_id );
		if ( ! $run || (int) $run['draft_post_id'] ) { return 0; }

		$artifacts = MSRWA_Run::artifacts( $run_id );
		$article = (array) ( $artifacts['proofread'] ?? $artifacts['corrected'] ?? $artifacts['article'] ?? array() );
		$html = (string) ( $article['content_html'] ?? '' );
		if ( '' === trim( $html ) ) { return 0; }

		$canonical = (array) ( $artifacts['canonical'] ?? array() );
		$brief = (array) json_decode( (string) $run['brief_json'], true );
		$title = (string) ( $canonical['title'] ?? $article['title'] ?? $brief['title'] ?? 'Recette' );

		$post_id = wp_insert_post( array(
			'post_type' => 'post', 'post_status' => 'draft',
			'post_title' => wp_strip_all_tags( $title ),
			'post_content' => $html,
			'post_excerpt' => wp_strip_all_tags( (string) ( $article['excerpt'] ?? '' ) ),
			'post_author' => (int) $run['owner_id'],
		), true );
		if ( is_wp_error( $post_id ) || ! $post_id ) { return 0; }

		self::remember( $post_id, $run_id, $canonical, (array) ( $artifacts['approval'] ?? array() ) );
		self::attach_images( $post_id, $run_id, $artifacts, $title );

		global $wpdb;
		$t = MSRWA_DB::tables();
		$wpdb->update( $t['runs'], array( 'draft_post_id' => (int) $post_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $run_id ) ) );
		return (int) $post_id;
	}

	/** The recipe and the verdict, kept with the post an editor will read. */
	private static function remember( $post_id, $run_id, array $canonical, array $approval ) {
		update_post_meta( $post_id, '_msrwa_run_id', (int) $run_id );
		if ( $canonical ) { update_post_meta( $post_id, '_msrwa_recipe', wp_slash( wp_json_encode( $canonical ) ) ); }
		if ( ! $approval ) { return; }
		// `approved` here is the engine judge's, never this site's editorial
		// decision. The meta key says so, and the screen that prints it says so.
		update_post_meta( $post_id, '_msrwa_judge_approved', empty( $approval['approved'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_msrwa_judge_report', wp_slash( wp_json_encode( MSRWA_DB::sanitize( $approval ) ) ) );
	}

	/**
	 * Brings the generated images into the media library.
	 *
	 * The engine writes them to the run's workspace as files; the library is
	 * where WordPress expects an image to live, and a featured image that is not
	 * an attachment cannot be set.
	 */
	private static function attach_images( $post_id, $run_id, array $artifacts, $title ) {
		foreach ( array( 'featured' => 'Image à la une', 'facebook' => 'Image Facebook' ) as $kind => $label ) {
			$image = (array) ( $artifacts[ $kind ] ?? array() );
			$path = (string) ( $image['path'] ?? '' );
			if ( '' === $path || ! is_readable( $path ) ) { continue; }

			$attachment = self::sideload( $path, $post_id, $title . ' — ' . $label, (string) ( $image['mime'] ?? 'image/webp' ) );
			if ( ! $attachment ) { continue; }
			if ( 'featured' === $kind ) { set_post_thumbnail( $post_id, $attachment ); }
			update_post_meta( $post_id, '_msrwa_' . $kind . '_image_id', (int) $attachment );
		}
	}

	/** Copies one generated file into the uploads directory as an attachment. */
	private static function sideload( $path, $post_id, $title, $mime ) {
		$uploaded = wp_upload_bits( sanitize_file_name( basename( $path ) ), null, (string) file_get_contents( $path ) );
		if ( ! empty( $uploaded['error'] ) ) { return 0; }

		$attachment = wp_insert_attachment( array(
			'post_mime_type' => $mime, 'post_title' => wp_strip_all_tags( $title ),
			'post_content' => '', 'post_status' => 'inherit',
		), $uploaded['file'], $post_id, true );
		if ( is_wp_error( $attachment ) || ! $attachment ) { return 0; }

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $uploaded['file'] ) );
		return (int) $attachment;
	}
}
