<?php
// Acting on several recipes at once, and sending a lot later than now.
//
// Both are places where doing slightly too much is worse than doing nothing:
// a bulk action that ignores ownership, or a schedule that dispatches twice.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $class ) { require_once $class; }
require_once MSRWA_DIR . 'includes/engine/load.php';
require __DIR__ . '/bootstrap.php';

// --- Deleting a recipe --------------------------------------------------

msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT * FROM', array( array( 'id' => 5, 'batch_id' => 2, 'owner_id' => 1, 'status' => 'running' ) ) );
msrwa_test_assert( ! MSRWA_Run::delete( 5 ), 'A recipe still moving is never deleted under its own worker.' );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'DELETE FROM', 'And nothing is removed while it is refused.' );

$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT * FROM', array( array( 'id' => 5, 'batch_id' => 2, 'owner_id' => 1, 'status' => 'failed' ) ) );
msrwa_test_assert( MSRWA_Run::delete( 5 ), 'A stopped recipe can be removed.' );
$log = $GLOBALS['wpdb']->log();
foreach ( array( 'steps', 'calls', 'events', 'artifacts' ) as $table ) {
	msrwa_test_contains( $log, 'DELETE FROM wp_msrwa_' . $table . ' WHERE run_id = 5', 'Its ' . $table . ' go with it.' );
}

// A writer cannot delete another writer's work, whatever they send.
msrwa_test_as_editor( 7 );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT * FROM', array( array( 'id' => 6, 'batch_id' => 2, 'owner_id' => 8, 'status' => 'failed' ) ) );
msrwa_test_assert( ! MSRWA_Run::delete( 6 ), 'A writer cannot delete a recipe that is not theirs.' );
msrwa_test_assert( ! MSRWA_Rights::may_delete(), 'And deleting is an administrator’s in the first place.' );

// --- Scheduling a lot ---------------------------------------------------

msrwa_test_as_admin();
$GLOBALS['msrwa_test_options']['gmt_offset'] = 0;

$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT * FROM', array( array( 'id' => 3, 'owner_id' => 1, 'status' => 'ready' ) ) );
$past = MSRWA_Schedule::when( 3, gmdate( 'Y-m-d H:i:s', time() - 7200 ) );
msrwa_test_assert( is_wp_error( $past ), 'A lot cannot be scheduled for an hour that has gone.' );

$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT * FROM', array( array( 'id' => 3, 'owner_id' => 1, 'status' => 'ready' ) ) );
$future = MSRWA_Schedule::when( 3, gmdate( 'Y-m-d H:i:s', time() + 7200 ) );
msrwa_test_assert( ! is_wp_error( $future ) && $future > time(), 'A future hour is accepted.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'dispatch_at', 'And stored on the lot.' );

// A lot that already went cannot be scheduled after the fact.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT * FROM', array( array( 'id' => 3, 'owner_id' => 1, 'status' => 'running' ) ) );
msrwa_test_assert( is_wp_error( MSRWA_Schedule::when( 3, gmdate( 'Y-m-d H:i:s', time() + 7200 ) ) ), 'A lot already sent cannot be scheduled.' );

// Two cron runs arriving together must not both send the same lot: the claim
// is a conditional UPDATE, and only a row it actually changed is dispatched.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'dispatch_at IS NOT NULL', array( 9 ) );
MSRWA_Schedule::due();
msrwa_test_contains( $GLOBALS['wpdb']->log(), "SET status = 'running', dispatch_at = NULL, updated_at = ", 'The lot is claimed before it is sent.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), "WHERE id = 9 AND status = 'ready'", 'And only if it was still waiting.' );

// Nothing is waiting: nothing is claimed.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
msrwa_test_assert( 0 === MSRWA_Schedule::due(), 'With nothing due, nothing is sent.' );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'UPDATE', 'And nothing is written.' );

msrwa_test_done( 'bulk actions and scheduled lots' );
