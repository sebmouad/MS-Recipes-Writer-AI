<?php
// Uninstalling must leave nothing behind. An option added in one release and
// forgotten in `uninstall.php` is a row that outlives the plugin for good, so
// the list is checked against the code rather than maintained by hand.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $class ) { require_once $class; }
require __DIR__ . '/bootstrap.php';

$uninstall = (string) file_get_contents( MSRWA_DIR . 'uninstall.php' );
$wanted = array();

// Every literal handed straight to an option function.
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $file ) {
	preg_match_all( "/(?:update|get|delete)_option\(\s*'(msrwa_[a-z_]+)'/", (string) file_get_contents( $file ), $found );
	foreach ( $found[1] as $option ) { $wanted[ $option ] = true; }
}

// And every class constant that holds one, which is how the longer-lived
// options are named.
foreach ( get_declared_classes() as $class ) {
	if ( 0 !== strpos( $class, 'MSRWA_' ) ) { continue; }
	foreach ( ( new ReflectionClass( $class ) )->getConstants() as $value ) {
		if ( is_string( $value ) && 0 === strpos( $value, 'msrwa_' ) && false === strpos( $value, ' ' ) ) { $wanted[ $value ] = true; }
	}
}

msrwa_test_assert( count( $wanted ) > 4, 'The scan found the options at all.' );
foreach ( array_keys( $wanted ) as $option ) {
	// Hooks and capabilities share the prefix and are cleared elsewhere in the
	// file, so the check is simply that the name appears somewhere in it.
	msrwa_test_contains( $uninstall, "'" . $option . "'", 'uninstall.php accounts for ' . $option . '.' );
}

// Capabilities are granted by name rather than through a constant, so they are
// read from the code that grants them.
preg_match_all( "/add_cap\(\s*'(msrwa_[a-z_]+)'/", (string) file_get_contents( MSRWA_DIR . 'includes/class-msrwa-plugin.php' ), $granted );
foreach ( array_unique( $granted[1] ) as $capability ) {
	msrwa_test_contains( $uninstall, "'" . $capability . "'", 'uninstall.php gives back ' . $capability . '.' );
}

// Including from every role it was granted to.
preg_match_all( "/'(administrator|editor|author|writer|contributor)'/", (string) file_get_contents( MSRWA_DIR . 'includes/class-msrwa-plugin.php' ), $roles );
foreach ( array_unique( $roles[1] ) as $role ) {
	msrwa_test_contains( $uninstall, "'" . $role . "'", 'uninstall.php reaches the ' . $role . ' role.' );
}

foreach ( array( 'batches', 'runs', 'steps', 'calls', 'events', 'artifacts' ) as $table ) {
	msrwa_test_contains( $uninstall, "'" . $table . "'", 'uninstall.php drops the ' . $table . ' table.' );
}

// The drafts and the media library are somebody's articles, not plugin state.
msrwa_test_missing( $uninstall, 'wp_delete_post', 'Uninstalling never deletes an article somebody may have published.' );
msrwa_test_missing( $uninstall, 'wp_delete_attachment', 'Uninstalling never deletes a reader’s media.' );

// Every table the plugin creates is dropped: the catalogue, added later, was not.
if ( ! class_exists( 'MSRWA_DB' ) ) { require_once dirname( __DIR__ ) . '/includes/class-msrwa-db.php'; }
$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? new MSRWA_Fake_Wpdb();
foreach ( array_keys( MSRWA_DB::tables() ) as $table ) {
	msrwa_test_contains( $uninstall, "'" . $table . "'", 'Uninstalling drops the ' . $table . ' table.' );
}

// How much goes is the site's choice, made beforehand: by default everything.
msrwa_test_contains( $uninstall, "get_option( 'msrwa_uninstall', 'all' )", 'uninstall.php follows the choice made on the settings screen, all by default.' );
msrwa_test_contains( $uninstall, "delete_post_meta_by_key( '_msrwa_run_id' )", 'With the runs gone, the drafts lose their link to them, and only that.' );
$scope_of = static function ( $choice ) use ( $uninstall ) {
	// Runs uninstall.php against a counting double, for one stored choice.
	$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
	$GLOBALS['msrwa_test_options'] = array( 'msrwa_uninstall' => $choice, 'msrwa_settings' => array( 'site_language' => 'en' ), 'msrwa_schema' => 11 );
	$GLOBALS['msrwa_test_meta_dropped'] = array();
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'ms-recipes-writer-ai' ); }
	$GLOBALS['msrwa_test_caps'] = array( 'activate_plugins' );
	include MSRWA_DIR . 'uninstall.php';
	return array( 'log' => $GLOBALS['wpdb']->log(), 'options' => $GLOBALS['msrwa_test_options'], 'meta' => $GLOBALS['msrwa_test_meta_dropped'] );
};
if ( ! function_exists( 'delete_post_meta_by_key' ) ) { function delete_post_meta_by_key( $key ) { $GLOBALS['msrwa_test_meta_dropped'][] = $key; return true; } }
if ( ! function_exists( 'get_role' ) ) { function get_role( $role ) { return null; } }
$all = $scope_of( 'all' );
msrwa_test_contains( $all['log'], 'DROP TABLE IF EXISTS wp_msrwa_runs', 'All: the work goes.' );
msrwa_test_assert( ! isset( $all['options']['msrwa_settings'] ) && ! isset( $all['options']['msrwa_uninstall'] ), 'All: nothing of the plugin’s options stays.' );
$settings = $scope_of( 'settings' );
msrwa_test_contains( $settings['log'], 'wp_msrwa_catalog', 'Settings: the catalogue goes.' );
msrwa_test_missing( $settings['log'], 'wp_msrwa_runs', 'Settings: the work stays.' );
msrwa_test_assert( ! isset( $settings['options']['msrwa_settings'] ) && isset( $settings['options']['msrwa_schema'] ) && ! $settings['meta'], 'Settings: the settings go, what describes the work stays, the drafts keep their link.' );
$nothing = $scope_of( 'nothing' );
msrwa_test_missing( $nothing['log'], 'DROP TABLE', 'Nothing: not a table.' );
msrwa_test_assert( isset( $nothing['options']['msrwa_settings'], $nothing['options']['msrwa_uninstall'] ), 'Nothing: every option stays, the choice included.' );

msrwa_test_done( 'uninstall removes what the site chose, everything by default' );
