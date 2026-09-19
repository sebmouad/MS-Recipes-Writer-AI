<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Plugin {
	public static function activate() {
		MSRWA_DB::install();
		if ( ! get_option( MSRWA_Settings::OPTION, false ) ) { add_option( MSRWA_Settings::OPTION, MSRWA_Settings::defaults(), '', false ); }
		self::caps();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'msrwa_process_batch' );
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
		if ( is_admin() ) { MSRWA_Admin::hooks(); }
		if ( get_option( 'msrwa_db_version' ) !== MSRWA_VERSION ) { MSRWA_DB::install(); }
	}
}
