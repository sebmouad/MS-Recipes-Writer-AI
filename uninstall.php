<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

// Data is retained by default. A deliberate server-side constant is required
// before an uninstall can remove jobs, logs, settings, and capabilities.
if ( ! defined( 'MSRWA_REMOVE_DATA_ON_UNINSTALL' ) || ! MSRWA_REMOVE_DATA_ON_UNINSTALL ) { return; }

global $wpdb;
$prefix = $wpdb->prefix . 'msrwa_';
foreach ( array( 'settings_history', 'settings', 'prompts', 'models', 'providers', 'snapshots', 'artifacts', 'reservations', 'calls', 'events', 'jobs', 'batches' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
}
foreach ( array( 'administrator', 'editor' ) as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) { foreach ( array( 'msrwa_manage', 'msrwa_view_all', 'msrwa_create', 'msrwa_view_own' ) as $cap ) { $role->remove_cap( $cap ); } }
}
