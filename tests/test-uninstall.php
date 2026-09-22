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

msrwa_test_done( 'uninstall leaves nothing behind' );
