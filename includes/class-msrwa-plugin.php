<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Plugin {
	public static function activate() {
		MSRWA_DB::install();
		MSRWA_Catalog::install();
		MSRWA_Settings::install();
		self::caps();
		if ( ! wp_next_scheduled( 'msrwa_cleanup' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'msrwa_cleanup' ); }
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'msrwa_process_batch' );
		wp_clear_scheduled_hook( 'msrwa_process_job' );
		wp_clear_scheduled_hook( 'msrwa_cleanup' );
	}

	private static function caps() {
		$role = get_role( 'administrator' );
		if ( $role ) { foreach ( array( 'msrwa_manage', 'msrwa_view_all' ) as $cap ) { $role->add_cap( $cap ); } }
		foreach ( array( 'editor' ) as $role_name ) { $role = get_role( $role_name ); if ( $role ) { $role->add_cap( 'msrwa_create' ); $role->add_cap( 'msrwa_view_own' ); } }
	}

	public static function boot() {
		add_action( 'rest_api_init', array( 'MSRWA_REST', 'register' ) );
		add_action( 'msrwa_process_batch', array( 'MSRWA_Queue', 'process_batch' ) );
		add_action( 'msrwa_process_job', array( 'MSRWA_Pipeline', 'process_job' ) );
		add_action( 'msrwa_cleanup', array( 'MSRWA_DB', 'purge_expired' ) );
		add_action( 'msrwa_cleanup', array( 'MSRWA_Queue', 'recover_expired' ) );
		if ( is_admin() ) { MSRWA_Admin::hooks(); }
		if ( MSRWA_DB::system_value( 'db_version' ) !== MSRWA_VERSION ) {
			MSRWA_DB::install();
			MSRWA_Catalog::install();
			MSRWA_Settings::install();
			MSRWA_Queue::reconcile_batches();
		}
	}
}
