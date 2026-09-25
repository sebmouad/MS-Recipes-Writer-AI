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
 *
 * How much goes is chosen beforehand on the settings screen (MSRWA_Reset),
 * since WordPress asks nothing at this moment: `all`, the default, is the
 * above; `settings` keeps the lots, runs, their files and the spending log for
 * a reinstallation; `nothing` keeps everything. The scheduled work and the
 * capabilities go in every case — activation grants them again.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
if ( ! current_user_can( 'activate_plugins' ) ) { exit; }

global $wpdb;
$msrwa_prefix = $wpdb->prefix . 'msrwa_';
$msrwa_scope = (string) get_option( 'msrwa_uninstall', 'all' );
$msrwa_scope = in_array( $msrwa_scope, array( 'all', 'settings', 'nothing' ), true ) ? $msrwa_scope : 'all';
$msrwa_data = 'all' === $msrwa_scope;
$msrwa_settings = 'nothing' !== $msrwa_scope;

// The catalogue is configuration; every other table MSRWA_DB::tables() names
// holds work.
$msrwa_tables = array_merge( $msrwa_settings ? array( 'catalog' ) : array(), $msrwa_data ? array( 'batches', 'runs', 'steps', 'calls', 'events', 'artifacts', 'history', 'spend' ) : array() );
foreach ( $msrwa_tables as $msrwa_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $msrwa_prefix . $msrwa_table ); // phpcs:ignore WordPress.DB
}

// The settings, then what only describes the work: the schema the tables are
// at stays while they do.
$msrwa_options = array_merge(
	$msrwa_settings ? array( 'msrwa_settings', 'msrwa_engine_config' ) : array(),
	$msrwa_data ? array( 'msrwa_schema', 'msrwa_db_version', 'msrwa_superseded_dropped', 'msrwa_duplicates_reclaimed', 'msrwa_watchdog_at', 'msrwa_queue_held', 'msrwa_prune_last', 'msrwa_uninstall' ) : array()
);
foreach ( $msrwa_options as $msrwa_option ) {
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

// uploads/msrwa/ holds the generated images of each run, the photographs each
// recipe was written from and the lots still being paired. Only rows that have
// just been dropped point at any of it. The walk stays inside that one
// directory and follows no link out of it.
// Settings alone take the style references (uploads/msrwa/style); data
// takes the whole folder.
$msrwa_uploads = wp_upload_dir();
$msrwa_root = realpath( trailingslashit( $msrwa_uploads['basedir'] ) . 'msrwa' );
$msrwa_target = $msrwa_data ? $msrwa_root : ( $msrwa_settings && $msrwa_root ? realpath( $msrwa_root . '/style' ) : false );
if ( $msrwa_target && is_dir( $msrwa_target ) ) {
	$msrwa_items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $msrwa_target, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $msrwa_items as $msrwa_item ) {
		if ( $msrwa_item->isLink() || $msrwa_item->isFile() ) { @unlink( $msrwa_item->getPathname() ); } else { @rmdir( $msrwa_item->getPathname() ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	@rmdir( $msrwa_target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

// With the runs gone, a draft's link to one points at nothing — and a run
// numbered the same after a reinstallation would be taken for it.
if ( $msrwa_data ) { delete_post_meta_by_key( '_msrwa_run_id' ); }

// The posts, their featured images and everything in the media library stay.
