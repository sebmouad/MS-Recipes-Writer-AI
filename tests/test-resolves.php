<?php
// Every static call in the plugin and the engine must resolve to something
// that exists.
//
// This exists because of a real fatal: the rewrite deleted the settings storage
// layer and took `secret_for_save()` with it, while `sanitize()` went on
// calling it. PHP only notices at the moment of the call, so the suite stayed
// green and saving a key died on a live screen instead.
//
// A call guarded by method_exists() or class_exists() is allowed to be absent:
// that is what the guard is for, and the code around it degrades instead of
// dying. Everything else must resolve.
// The real classes are loaded before the harness, so its doubles stand aside
// and this reads production code. A double that answered for MSRWA_Settings
// would be the very thing that hid the fatal.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) { require_once $file; }
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require __DIR__ . '/bootstrap.php';

$unresolved = array();
foreach ( array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/includes/engine/*.php' ) ) as $file ) {
	$source = file_get_contents( $file );
	if ( ! preg_match( '/^\s*final class (\w+)/m', $source, $found ) ) { continue; }
	$class = $found[1];
	$where = basename( $file );

	preg_match_all( '/(?:self|static)::(\w+)\s*\(/', $source, $own );
	foreach ( array_unique( $own[1] ) as $method ) {
		if ( ! method_exists( $class, $method ) ) { $unresolved[] = $where . ' — ' . $class . '::' . $method . '()'; }
	}

	preg_match_all( '/\b(MSRWA_\w+)::(\w+)\s*\(/', $source, $calls, PREG_SET_ORDER );
	foreach ( $calls as $call ) {
		if ( ! class_exists( $call[1] ) ) {
			if ( false === strpos( $source, "class_exists( '" . $call[1] . "' )" ) ) { $unresolved[] = $where . ' — class ' . $call[1]; }
			continue;
		}
		if ( method_exists( $call[1], $call[2] ) ) { continue; }
		if ( false !== strpos( $source, "method_exists( '" . $call[1] ) ) { continue; }
		$unresolved[] = $where . ' — ' . $call[1] . '::' . $call[2] . '()';
	}
}

$unresolved = array_values( array_unique( $unresolved ) );
msrwa_test_assert( ! $unresolved, count( $unresolved ) . " call(s) resolve to nothing:\n      " . implode( "\n      ", $unresolved ) );

msrwa_test_done( 'every call resolves' );
