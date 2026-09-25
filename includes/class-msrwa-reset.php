<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Starting over, from the settings screen, in two sizes.
 *
 * The settings: what the Réglages, Moteur and Modèles screens hold, and the
 * Facebook style references, back to what ships. The API keys stay unless
 * they are asked to go too — losing them is what stops every lot, and it is
 * rarely what "start over" means.
 *
 * The settings and the data: also every lot, recipe, step, call, event,
 * artifact, history row and spending line, and everything the plugin wrote
 * under uploads/msrwa. As at uninstall, the drafts and the media library stay:
 * someone may publish from them. Their link to a run that no longer exists is
 * removed, so a run numbered the same later is never taken for theirs.
 */
final class MSRWA_Reset {

	/**
	 * What deleting the plugin removes, chosen here in advance — WordPress
	 * gives uninstalling no screen of its own. uninstall.php reads it.
	 */
	const UNINSTALL = 'msrwa_uninstall';

	/** `all` (the default: settings and data), `settings`, or `nothing`. */
	public static function uninstall_choice() {
		$choice = (string) get_option( self::UNINSTALL, 'all' );
		return in_array( $choice, array( 'all', 'settings', 'nothing' ), true ) ? $choice : 'all';
	}

	public static function choose_uninstall( $choice ) {
		$choice = in_array( $choice, array( 'all', 'settings', 'nothing' ), true ) ? $choice : 'all';
		update_option( self::UNINSTALL, $choice, false );
		return $choice;
	}

	/** The tables that hold work, not configuration. */
	const DATA = array( 'batches', 'runs', 'steps', 'calls', 'events', 'artifacts', 'history', 'spend' );

	public static function settings( $forget_keys = false ) {
		$stored = (array) get_option( MSRWA_Settings::OPTION, array() );
		$keys = $forget_keys ? array() : array_intersect_key( $stored, array_flip( array( 'openai_key', 'gemini_key', 'claude_key' ) ) );
		delete_option( MSRWA_Settings::OPTION );
		if ( $keys ) { update_option( MSRWA_Settings::OPTION, $keys, false ); }
		delete_option( MSRWA_Engine_Settings::OPTION );

		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( MSRWA_DB::table_exists( $t['catalog'] ) ) {
			$wpdb->query( 'DELETE FROM ' . $t['catalog'] ); // phpcs:ignore WordPress.DB
			MSRWA_Catalog::seed();
		}
		MSRWA_Sources::forget_styles();
	}

	/** Why the data cannot be cleared now, or '' when it can. */
	public static function refusal() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$busy = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $t['runs'] . " WHERE status IN ('queued','running')" ); // phpcs:ignore WordPress.DB
		if ( $busy ) {
			/* translators: %d is a number of recipes. */
			return sprintf( _n( '%d recette est en cours : attendez qu’elle finisse ou annulez-la avant d’effacer les données.', '%d recettes sont en cours : attendez qu’elles finissent ou annulez-les avant d’effacer les données.', $busy, 'ms-recipes-writer-ai' ), $busy );
		}
		return '';
	}

	public static function everything( $forget_keys = false ) {
		$refused = self::refusal();
		if ( '' !== $refused ) { return new WP_Error( 'msrwa_busy', $refused ); }
		global $wpdb;
		$t = MSRWA_DB::tables();
		// DELETE, not TRUNCATE: the numbering carries on where it was.
		foreach ( self::DATA as $table ) {
			if ( MSRWA_DB::table_exists( $t[ $table ] ) ) { $wpdb->query( 'DELETE FROM ' . $t[ $table ] ); } // phpcs:ignore WordPress.DB
		}
		delete_post_meta_by_key( '_msrwa_run_id' );
		wp_clear_scheduled_hook( 'msrwa_run_step' );
		foreach ( array( 'msrwa_watchdog_at', 'msrwa_queue_held', 'msrwa_prune_last' ) as $option ) { delete_option( $option ); }
		MSRWA_Sources::forget_work();
		self::settings( $forget_keys );
		return true;
	}
}
