<?php
/**
 * What is removed when the plugin is deleted — and what is deliberately not.
 *
 * Deleting a plugin should not delete a writer's work. The drafts it produced
 * are ordinary WordPress posts and stay, as do the images in the media
 * library: someone published from them, or is about to.
 *
 * What goes is everything only this plugin could read — its tables, its
 * options, its scheduled work and the capabilities it granted — plus the
 * generated files it wrote into its own uploads directory, which nothing else
 * will ever open.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
if ( ! current_user_can( 'activate_plugins' ) ) { exit; }

global $wpdb;
$msrwa_prefix = $wpdb->prefix . 'msrwa_';

foreach ( array( 'batches', 'runs', 'steps', 'calls', 'events', 'artifacts' ) as $msrwa_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $msrwa_prefix . $msrwa_table ); // phpcs:ignore WordPress.DB
}

foreach ( array( 'msrwa_settings', 'msrwa_engine_config', 'msrwa_schema', 'msrwa_db_version', 'msrwa_superseded_dropped', 'msrwa_duplicates_reclaimed', 'msrwa_watchdog_at', 'msrwa_queue_held', 'msrwa_prune_last' ) as $msrwa_option ) {
	delete_option( $msrwa_option );
}

foreach ( array( 'msrwa_run_step', 'msrwa_cleanup' ) as $msrwa_hook ) {
	wp_clear_scheduled_hook( $msrwa_hook );
}

foreach ( array( 'administrator', 'editor', 'author', 'writer' ) as $msrwa_role_name ) {
	$msrwa_role = get_role( $msrwa_role_name );
	if ( ! $msrwa_role ) { continue; }
	foreach ( array( 'msrwa_create', 'msrwa_view_all', 'msrwa_view_own', 'msrwa_manage' ) as $msrwa_capability ) {
		$msrwa_role->remove_cap( $msrwa_capability );
	}
}

// The generated images live under uploads/msrwa/<run>/. They are only ever
// referenced by rows that have just been dropped, so nothing else can be
// reading them — but the walk stays inside that one directory and removes
// nothing it did not expect to find.
$msrwa_uploads = wp_upload_dir();
$msrwa_root = trailingslashit( $msrwa_uploads['basedir'] ) . 'msrwa';
if ( is_dir( $msrwa_root ) ) {
	foreach ( (array) glob( $msrwa_root . '/*', GLOB_ONLYDIR ) as $msrwa_run_dir ) {
		foreach ( (array) glob( $msrwa_run_dir . '/*' ) as $msrwa_file ) {
			if ( is_file( $msrwa_file ) ) { wp_delete_file( $msrwa_file ); }
		}
		@rmdir( $msrwa_run_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	@rmdir( $msrwa_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

// The posts, their featured images and everything in the media library stay.
