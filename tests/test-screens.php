<?php
// Every screen renders, escapes, and shows each reader only what they may see.
// A screen that fatals on an empty site is the first thing a new installation
// meets, so they are all exercised with nothing in the database.
// Production classes first, so the harness's stand-ins step aside: a double
// answering for MSRWA_Settings would be exactly what hides a broken screen.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $class ) { require_once $class; }
require_once MSRWA_DIR . 'includes/engine/load.php';
require __DIR__ . '/bootstrap.php';

function msrwa_render( $callable ) {
	ob_start();
	try { call_user_func( $callable ); } catch ( Throwable $error ) { ob_end_clean(); return array( 'error' => $error->getMessage() ); }
	return array( 'html' => ob_get_clean() );
}

// --- A writer ----------------------------------------------------------

msrwa_test_as_editor( 7 );
foreach ( array(
	'MSRWA_Screen_Pass' => array( 'MSRWA_Screen_Pass', 'render' ),
	'MSRWA_Screen_Compose' => array( 'MSRWA_Screen_Compose', 'render' ),
	'MSRWA_Screen_Articles' => array( 'MSRWA_Screen_Articles', 'render' ),
) as $name => $callable ) {
	$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
	$_GET = array();
	$out = msrwa_render( $callable );
	msrwa_test_assert( isset( $out['html'] ), $name . ' must render for a writer; got: ' . ( $out['error'] ?? '' ) );
	if ( ! isset( $out['html'] ) ) { continue; }
	msrwa_test_contains( $out['html'], 'class="wrap msrwa"', $name . ' uses the shared shell.' );
	msrwa_test_missing( $out['html'], 'page=msrwa-settings&', $name . ' does not link a writer to administrator screens.' );
}

// The screens a writer may not reach refuse before touching the database.
foreach ( array(
	'MSRWA_Screen_Analysis' => array( 'MSRWA_Screen_Analysis', 'render' ),
	'MSRWA_Screen_Engine' => array( 'MSRWA_Screen_Engine', 'render' ),
	'MSRWA_Screen_Settings' => array( 'MSRWA_Screen_Settings', 'render' ),
) as $name => $callable ) {
	$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
	$out = msrwa_render( $callable );
	msrwa_test_assert( isset( $out['error'] ), $name . ' must refuse a writer.' );
	msrwa_test_assert( ! $GLOBALS['wpdb']->queries, $name . ' must refuse before it queries.' );
}

// --- An administrator --------------------------------------------------

msrwa_test_as_admin();
foreach ( array(
	'MSRWA_Screen_Pass' => array( 'MSRWA_Screen_Pass', 'render' ),
	'MSRWA_Screen_Analysis' => array( 'MSRWA_Screen_Analysis', 'render' ),
	'MSRWA_Screen_Engine' => array( 'MSRWA_Screen_Engine', 'render' ),
	'MSRWA_Screen_Settings' => array( 'MSRWA_Screen_Settings', 'render' ),
) as $name => $callable ) {
	$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
	$_GET = array();
	$out = msrwa_render( $callable );
	msrwa_test_assert( isset( $out['html'] ), $name . ' must render for an administrator; got: ' . ( $out['error'] ?? '' ) );
}

// The engine screen must reach every configuration group, or a promise that
// nothing is hardcoded quietly stops being true.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$engine = msrwa_render( array( 'MSRWA_Screen_Engine', 'render' ) );
foreach ( array_keys( array_merge( MSRWA_Engine_Settings::simple(), MSRWA_Engine_Settings::structural() ) ) as $group ) {
	msrwa_test_contains( $engine['html'] ?? '', 'msrwa_engine[' . $group . ']', 'The engine screen has a field for ' . $group . '.' );
}

// A key must never reach a screen, whatever else is on it.
msrwa_test_missing( $engine['html'] ?? '', 'key_env', 'The engine screen does not print where keys are kept.' );

// --- An empty site is not an error -------------------------------------

msrwa_test_contains( ( msrwa_render( array( 'MSRWA_Screen_Pass', 'render' ) )['html'] ?? '' ), 'ms-empty', 'With nothing to show, the pass invites the reader to act.' );

// --- A lot that has not left is still somebody's ------------------------

msrwa_test_as_editor( 7 );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Batch::waiting();
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'owner_id = 7', 'A writer sees only their own lots waiting to leave.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), "status = 'ready'", 'Only lots that have not started are listed.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'ORDER BY (dispatch_at IS NULL), dispatch_at ASC', 'The one with an hour on it comes first.' );

msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Batch::waiting();
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'owner_id =', 'An administrator sees every lot waiting to leave.' );

// The section is silent when there is nothing waiting, rather than printing an
// empty table on the one screen meant to be read at a glance.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
msrwa_test_missing( ( msrwa_render( array( 'MSRWA_Screen_Pass', 'render' ) )['html'] ?? '' ), 'Pas encore parti', 'Nothing waiting, nothing said.' );

// --- Queue order is said where it is true, and only there ---------------

$waiting = array( 'id' => 9, 'batch_id' => 1, 'owner_id' => 1, 'label' => 'Tarte', 'status' => 'queued', 'step' => '', 'steps_done' => 0, 'steps_total' => 10, 'approved' => null, 'priority' => 5, 'draft_post_id' => 0 );
ob_start(); MSRWA_UI::ticket( $waiting, '#' ); $html = ob_get_clean();
msrwa_test_contains( $html, 'passe devant', 'A recipe pushed to the front says so while it waits.' );

$waiting['priority'] = 0;
ob_start(); MSRWA_UI::ticket( $waiting, '#' ); $html = ob_get_clean();
msrwa_test_missing( $html, 'passe devant', 'A recipe in the ordinary order says nothing about order.' );

// Reordering a recipe that is already moving would change nothing, so it is
// never offered.
$waiting['status'] = 'running';
$waiting['priority'] = 5;
ob_start(); MSRWA_UI::ticket( $waiting, '#' ); $html = ob_get_clean();
msrwa_test_missing( $html, 'passe devant', 'A recipe already running is past the queue.' );

msrwa_test_done( 'screens render and refuse correctly' );
