<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Plugin {

	public static function activate() {
		// The interval this plugin schedules on must be registered before the
		// event is scheduled, and activation does not run boot().
		add_filter( 'cron_schedules', array( __CLASS__, 'intervals' ) );
		MSRWA_DB::install();
		self::caps();
		// Hourly is fine for tidying, but a lot asked for at nine o'clock should
		// not wait until ten, so the same event runs every five minutes.
		if ( ! wp_next_scheduled( 'msrwa_cleanup' ) ) { wp_schedule_event( time() + 300, 'msrwa_five_minutes', 'msrwa_cleanup' ); }
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'msrwa_run_step' );
		wp_clear_scheduled_hook( 'msrwa_cleanup' );
	}

	/**
	 * The same grants as activation, from the same list. Upgrades used to apply
	 * a second, different list — editors lost `msrwa_view_all` here and got it
	 * back on the next activation, and administrators never got `msrwa_manage`
	 * from an upgrade at all.
	 */
	private static function caps() {
		MSRWA_Rights::grant();
		// Left over from earlier versions: a view-all grant on writers, which
		// no longer widens anything, and a view-own capability nothing reads.
		foreach ( array( 'editor', 'author', 'writer' ) as $name ) {
			$role = get_role( $name );
			if ( $role ) { $role->remove_cap( MSRWA_Rights::VIEW_ALL ); $role->remove_cap( 'msrwa_view_own' ); }
		}
	}

	/** Whatever the retention policy says has outlived its usefulness. */
	public static function prune() {
		MSRWA_Retention::sweep();
	}

	/**
	 * Five minutes, because a lot scheduled for nine that leaves at ten has
	 * missed its hour.
	 *
	 * The label is only translated once translations may be loaded. Activation
	 * schedules this event, and activation runs before `init`: asking for a
	 * translation there makes WordPress complain, on every first activation,
	 * about a domain loaded too early.
	 */
	public static function intervals( $schedules ) {
		$schedules['msrwa_five_minutes'] = array(
			'interval' => 300,
			'display' => did_action( 'init' ) ? __( 'Toutes les cinq minutes (MS Recipes Writer)', 'ms-recipes-writer-ai' ) : 'Toutes les cinq minutes (MS Recipes Writer)',
		);
		return $schedules;
	}

	public static function boot() {
		add_filter( 'cron_schedules', array( __CLASS__, 'intervals' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		if ( wp_get_schedule( 'msrwa_cleanup' ) !== 'msrwa_five_minutes' ) {
			wp_clear_scheduled_hook( 'msrwa_cleanup' );
			wp_schedule_event( time() + 300, 'msrwa_five_minutes', 'msrwa_cleanup' );
		}
		add_action( 'rest_api_init', array( 'MSRWA_REST', 'register' ) );
		add_action( 'msrwa_run_step', array( 'MSRWA_Run', 'tick' ) );
		add_action( 'msrwa_cleanup', array( 'MSRWA_Run', 'recover_expired' ) );
		add_action( 'msrwa_cleanup', array( __CLASS__, 'prune' ) );
		add_action( 'msrwa_cleanup', array( 'MSRWA_Schedule', 'due' ) );
		if ( is_admin() ) { MSRWA_Admin::hooks(); MSRWA_Editor::hooks(); }
		MSRWA_Schema::hooks();
		if ( get_option( 'msrwa_db_version' ) !== MSRWA_VERSION ) { MSRWA_DB::install(); self::caps(); }
	}

	public static function schedules( $schedules ) {
		$schedules['msrwa_five_minutes'] = array( 'interval' => 300, 'display' => 'MS Recipes : toutes les cinq minutes' );
		return $schedules;
	}
}
