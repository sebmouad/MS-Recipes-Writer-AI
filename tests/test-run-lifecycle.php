<?php
// A stopped recipe can be picked back up, and picking it up must not pay again
// for what already succeeded. That is the whole reason the steps are rows.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'i18n', 'profile', 'db' );
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-batch.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-run.php';

msrwa_test_as_admin();

// Only a stopped run may be resumed; one still moving must not be disturbed.
msrwa_test_assert( MSRWA_Run::may_retry( array( 'status' => 'failed', 'owner_id' => 1 ) ), 'A failed recipe can be resumed.' );
msrwa_test_assert( MSRWA_Run::may_retry( array( 'status' => 'cancelled', 'owner_id' => 1 ) ), 'A stopped recipe can be resumed.' );
msrwa_test_assert( ! MSRWA_Run::may_retry( array( 'status' => 'running', 'owner_id' => 1 ) ), 'A recipe in flight is never restarted underneath itself.' );
msrwa_test_assert( ! MSRWA_Run::may_retry( array( 'status' => 'done', 'owner_id' => 1 ) ), 'A finished recipe is not re-run by accident.' );

// A writer cannot resume somebody else's work.
msrwa_test_as_editor( 7 );
msrwa_test_assert( ! MSRWA_Run::may_retry( array( 'status' => 'failed', 'owner_id' => 8 ) ), 'A writer cannot resume another writer’s recipe.' );
msrwa_test_assert( MSRWA_Run::may_retry( array( 'status' => 'failed', 'owner_id' => 7 ) ), 'A writer can resume their own.' );

// Resuming removes only the steps that errored. Everything that succeeded —
// and everything it produced — stays, so nothing is paid for twice.
msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT * FROM', array( array( 'id' => 12, 'batch_id' => 3, 'owner_id' => 1, 'status' => 'failed' ) ) );
MSRWA_Run::retry( 12 );
$log = $GLOBALS['wpdb']->log();

msrwa_test_contains( $log, "DELETE FROM wp_msrwa_steps WHERE run_id = 12 AND error_message <> ''", 'Only the steps that errored are cleared.' );
msrwa_test_missing( $log, 'DELETE FROM wp_msrwa_artifacts', 'What was produced is kept, so it is not generated again.' );
msrwa_test_missing( $log, 'DELETE FROM wp_msrwa_calls', 'What was billed stays on the record.' );
msrwa_test_contains( $log, 'UPDATE wp_msrwa_runs', 'The recipe is put back in the queue.' );
msrwa_test_contains( $log, 'msrwa_batches', 'A batch with work in it again is no longer finished.' );

msrwa_test_done( 'resuming a stopped recipe' );
