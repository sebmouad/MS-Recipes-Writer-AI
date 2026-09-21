<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A lot that waits before it spends.
 *
 * The pairing still happens immediately — it is what the writer has to confirm,
 * and it is the cheap half. What waits is the dispatch: the recipes go to the
 * engine at the hour asked for, so a night's work costs nothing while somebody
 * is still deciding whether to send it.
 *
 * Nothing is dispatched from a browser. A scheduled lot is claimed by the same
 * cron that carries everything else, so closing the tab, or the site being idle
 * all evening, changes nothing.
 */
final class MSRWA_Schedule {

	/** When a lot may be sent, in the site's own timezone. */
	public static function when( $batch_id, $local_datetime ) {
		global $wpdb;
		$batch = MSRWA_Batch::get( $batch_id );
		if ( ! $batch || ! MSRWA_Batch::may_see( $batch ) ) { return new WP_Error( 'msrwa_not_found', __( 'Lot introuvable.', 'ms-recipes-writer-ai' ) ); }
		if ( 'ready' !== $batch['status'] ) { return new WP_Error( 'msrwa_not_ready', __( 'Ce lot est déjà lancé.', 'ms-recipes-writer-ai' ) ); }

		$local = trim( (string) $local_datetime );
		if ( '' === $local ) {
			$wpdb->update( MSRWA_DB::tables()['batches'], array( 'dispatch_at' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $batch_id ) ) );
			return 0;
		}

		// The form speaks the site's timezone; the column is UTC, like every
		// other time this plugin stores. Converting on the way in means nothing
		// downstream has to remember which it is holding.
		$timestamp = strtotime( $local . ' ' . self::offset() );
		if ( ! $timestamp ) { return new WP_Error( 'msrwa_bad_time', __( 'Date illisible.', 'ms-recipes-writer-ai' ) ); }
		if ( $timestamp < time() - 60 ) { return new WP_Error( 'msrwa_past', __( 'Cette heure est déjà passée.', 'ms-recipes-writer-ai' ) ); }

		$wpdb->update(
			MSRWA_DB::tables()['batches'],
			array( 'dispatch_at' => gmdate( 'Y-m-d H:i:s', $timestamp ), 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => absint( $batch_id ) )
		);
		return $timestamp;
	}

	private static function offset() {
		$offset = (float) get_option( 'gmt_offset', 0 );
		return sprintf( '%+03d:%02d', (int) $offset, abs( ( $offset - (int) $offset ) * 60 ) );
	}

	/**
	 * Sends every lot whose hour has come.
	 *
	 * Claimed one at a time with a conditional UPDATE, so two cron runs
	 * arriving together cannot both dispatch the same lot and pay twice.
	 */
	public static function due() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$ids = (array) $wpdb->get_col(
			"SELECT id FROM {$t['batches']} WHERE status = 'ready' AND dispatch_at IS NOT NULL AND dispatch_at <= UTC_TIMESTAMP() ORDER BY dispatch_at ASC LIMIT 5" );

		foreach ( $ids as $id ) {
			$claimed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$t['batches']} SET status = 'running', dispatch_at = NULL, updated_at = %s WHERE id = %d AND status = 'ready'",
				current_time( 'mysql', true ), absint( $id ) ) );
			if ( ! $claimed ) { continue; }

			// dispatch() expects to do the claiming itself, so it is put back
			// to ready for the one call and left to move it on.
			$wpdb->query( $wpdb->prepare( "UPDATE {$t['batches']} SET status = 'ready' WHERE id = %d", absint( $id ) ) );
			MSRWA_Batch::dispatch( (int) $id );
		}
		return count( $ids );
	}

	/** What a screen prints about a lot that is waiting. */
	public static function waiting_for( array $batch ) {
		if ( empty( $batch['dispatch_at'] ) ) { return ''; }
		return sprintf(
			/* translators: %s is a date and time. */
			__( 'Envoi programmé le %s. Rien n’est dépensé d’ici là.', 'ms-recipes-writer-ai' ),
			MSRWA_I18N::when( $batch['dispatch_at'] )
		);
	}
}
