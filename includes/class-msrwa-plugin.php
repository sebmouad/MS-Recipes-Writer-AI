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
		foreach ( array( 'editor', 'author', 'writer' ) as $name ) {
			$role = get_role( $name );
			if ( $role ) { $role->add_cap( 'msrwa_create' ); $role->add_cap( 'msrwa_view_own' ); $role->remove_cap( 'msrwa_view_all' ); }
		}
	}

	public static function boot() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		if ( wp_get_schedule( 'msrwa_cleanup' ) !== 'msrwa_five_minutes' ) {
			wp_clear_scheduled_hook( 'msrwa_cleanup' );
			wp_schedule_event( time() + 300, 'msrwa_five_minutes', 'msrwa_cleanup' );
		}
		add_action( 'rest_api_init', array( 'MSRWA_REST', 'register' ) );
		add_action( 'msrwa_run_step', array( 'MSRWA_Run', 'tick' ) );
		add_action( 'msrwa_cleanup', array( 'MSRWA_Run', 'recover_expired' ) );
		if ( is_admin() ) { MSRWA_Admin::hooks(); }
		if ( get_option( 'msrwa_db_version' ) !== MSRWA_VERSION ) { MSRWA_DB::install(); self::caps(); }
	}

	public static function schedules( $schedules ) {
		$schedules['msrwa_five_minutes'] = array( 'interval' => 300, 'display' => 'MS Recipes : toutes les cinq minutes' );
		return $schedules;
	}
}
