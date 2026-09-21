<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The ledger as a file, for questions this plugin's screens do not answer.
 *
 * Three shapes, because three different questions get asked: one row per
 * recipe for "what did last month cost", one per step for "where does the money
 * go", one per call for "which model is actually billed". Whatever a
 * spreadsheet is better at than a screen.
 *
 * Streamed rather than assembled, so a year of runs does not have to fit in
 * memory before anybody can download it.
 */
final class MSRWA_Export {

	public static function shapes() {
		return array(
			'runs' => __( 'Une ligne par recette', 'ms-recipes-writer-ai' ),
			'steps' => __( 'Une ligne par étape', 'ms-recipes-writer-ai' ),
			'calls' => __( 'Une ligne par appel', 'ms-recipes-writer-ai' ),
		);
	}

	public static function send() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet export.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_export' );

		$shape = isset( $_GET['shape'] ) ? sanitize_key( wp_unslash( $_GET['shape'] ) ) : 'runs';
		if ( ! array_key_exists( $shape, self::shapes() ) ) { $shape = 'runs'; }
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="msrwa-' . $shape . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		// Excel reads a CSV as the local codepage unless a byte-order mark says
		// otherwise, which turns every accent into rubbish on the machines this
		// file is most likely to be opened on.
		fwrite( $out, "\xEF\xBB\xBF" );

		foreach ( self::rows( $shape, $days ) as $row ) { fputcsv( $out, $row ); }
		fclose( $out );
		exit;
	}

	/** Header first, then the rows, in pages so nothing is held in memory. */
	private static function rows( $shape, $days ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$scope = MSRWA_Rights::scope_sql( 'r.owner_id' );
		$since = $days > 0 ? $wpdb->prepare( ' AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', (int) $days ) : '';

		$queries = array(
			'runs' => array(
				array( 'recette', 'lot', 'titre', 'etat', 'profil', 'langue', 'etapes', 'cout_usd', 'secondes', 'juge_approuve', 'brouillon', 'cree_le' ),
				"SELECT r.id, r.batch_id, r.label, r.status, b.profile, b.language, CONCAT(r.steps_done,'/',r.steps_total) steps,
					r.cost_usd, r.seconds, r.approved, r.draft_post_id, r.created_at
				FROM {$t['runs']} r LEFT JOIN {$t['batches']} b ON b.id = r.batch_id
				WHERE {$scope}{$since} ORDER BY r.id DESC",
			),
			'steps' => array(
				array( 'recette', 'etape', 'poste', 'fournisseur', 'modele', 'secondes', 'tentatives', 'tokens_entree', 'tokens_sortie', 'cout_usd', 'controles_reussis', 'controles_total', 'controles_echoues', 'erreur' ),
				"SELECT s.run_id, s.step, s.bucket, s.provider, s.model, s.seconds, s.attempts,
					s.input_tokens, s.output_tokens, s.cost_usd, s.passed, s.total, s.checks_failed, s.error_message
				FROM {$t['steps']} s INNER JOIN {$t['runs']} r ON r.id = s.run_id
				WHERE {$scope}{$since} ORDER BY s.id DESC",
			),
			'calls' => array(
				array( 'recette', 'etape', 'fournisseur', 'modele', 'niveau', 'secondes', 'tokens_entree', 'tokens_cache', 'tokens_sortie', 'cout_usd', 'tarif_connu', 'statut' ),
				"SELECT c.run_id, c.step, c.provider, c.model, c.tier, c.seconds,
					c.input_tokens, c.cached_tokens, c.output_tokens, c.cost_usd, c.priced, c.status
				FROM {$t['calls']} c INNER JOIN {$t['runs']} r ON r.id = c.run_id
				WHERE {$scope}{$since} ORDER BY c.id DESC",
			),
		);

		list( $header, $sql ) = $queries[ $shape ];
		yield $header;

		$offset = 0;
		$page = 500;
		while ( true ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT %d OFFSET %d', $page, $offset ), ARRAY_N );
			if ( ! $rows ) { return; }
			foreach ( $rows as $row ) {
				// An unknown cost is written as nothing at all, never as a zero:
				// a spreadsheet that sums a column of zeroes reports a total
				// that was never true.
				yield array_map( static function ( $value ) { return null === $value ? '' : $value; }, $row );
			}
			if ( count( $rows ) < $page ) { return; }
			$offset += $page;
		}
	}

	/** The links the analysis screen offers. */
	public static function links( $days ) {
		$out = array();
		foreach ( self::shapes() as $shape => $label ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=msrwa_export&shape=' . $shape . '&days=' . (int) $days ), 'msrwa_export' );
			$out[] = '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		return implode( ' ', $out );
	}
}
