<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Plugin {

	public static function activate() {
		MSRWA_DB::install();
		self::caps();
		if ( ! wp_next_scheduled( 'msrwa_cleanup' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'msrwa_cleanup' ); }
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'msrwa_run_step' );
		wp_clear_scheduled_hook( 'msrwa_cleanup' );
	}

	private static function caps() {
		$admin = get_role( 'administrator' );
		if ( $admin ) { foreach ( array( 'msrwa_create', 'msrwa_view_all' ) as $cap ) { $admin->add_cap( $cap ); } }
		$editor = get_role( 'editor' );
		if ( $editor ) { $editor->add_cap( 'msrwa_create' ); }
	}

	public static function boot() {
		add_action( 'rest_api_init', array( 'MSRWA_REST', 'register' ) );
		add_action( 'msrwa_run_step', array( 'MSRWA_Run', 'tick' ) );
		add_action( 'msrwa_cleanup', array( 'MSRWA_Run', 'recover_expired' ) );
		if ( is_admin() ) { MSRWA_Admin::hooks(); }
		if ( get_option( 'msrwa_db_version' ) !== MSRWA_VERSION ) { MSRWA_DB::install(); }
	}
}
