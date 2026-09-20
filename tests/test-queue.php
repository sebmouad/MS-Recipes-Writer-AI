<?php
// Scheduling contracts: a batch keeps moving past the concurrency limit, and a
// worker never drops the job it was scheduled for.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'queue' );
msrwa_test_as_admin( 1 );

function msrwa_test_queue_wpdb( $batch_status, $counts, $waiting_ids, $active = 1 ) {
	$wpdb = new MSRWA_Fake_Wpdb();
	$wpdb->default_var( $active )
		->on( 'SELECT status FROM wp_msrwa_batches', $batch_status )
		->on( 'AS total, SUM(status', array( (object) $counts ) )
		->on( "status IN ('queued','retry_wait') AND (lock_until", $waiting_ids );
	$GLOBALS['wpdb'] = $wpdb;
	$GLOBALS['msrwa_test_scheduled'] = array();
	return $wpdb;
}

// A batch larger than the concurrency limit keeps draining as jobs finish.
$wpdb = msrwa_test_queue_wpdb( 'running', array( 'total' => 10, 'completed' => 4, 'cancelled' => 0, 'active' => 6, 'waiting' => 6, 'awaiting_admin' => 0, 'paused' => 0, 'needs_review' => 0 ), array( 5, 6, 7 ) );
MSRWA_Queue::refresh_batch( 3 );
msrwa_test_assert( array( 5, 6, 7 ) === msrwa_test_scheduled_jobs(), 'Finishing a job must release its slot to the jobs still waiting.' );
msrwa_test_contains( $wpdb->log(), 'LIMIT 3', 'Only the free slots may be filled (4 allowed, 1 running).' );

// Slot accounting ignores a lease that already expired.
msrwa_test_contains( implode( ' ', $wpdb->matching( 'COUNT(*)' ) ), 'lock_until > UTC_TIMESTAMP()', 'An expired lease must not keep holding a slot.' );

// A batch nobody may resume is left alone.
foreach ( array( 'paused', 'cancelled', 'completed', 'awaiting_admin' ) as $status ) {
	msrwa_test_queue_wpdb( $status, array(), array( 5 ) );
	msrwa_test_assert( 0 === MSRWA_Queue::fill_slots( 3 ), 'A ' . $status . ' batch must not be scheduled.' );
	msrwa_test_assert( array() === msrwa_test_scheduled_jobs(), 'A ' . $status . ' batch must schedule nothing.' );
}

// Every slot taken: the job comes back instead of being dropped.
$GLOBALS['msrwa_test_scheduled'] = array();
$GLOBALS['wpdb'] = ( new MSRWA_Fake_Wpdb() )->on( 'WHERE id = 9 AND status', 9 );
msrwa_test_assert( true === MSRWA_Queue::requeue_unclaimed( 9 ), 'A job that found no free slot must be re-scheduled.' );
msrwa_test_assert( array( 9 ) === msrwa_test_scheduled_jobs(), 'The re-scheduled job must be the one that could not start.' );

// A job that is no longer waiting (paused, cancelled, already running) is left alone.
$GLOBALS['msrwa_test_scheduled'] = array();
$GLOBALS['wpdb'] = ( new MSRWA_Fake_Wpdb() )->default_var( null );
msrwa_test_assert( false === MSRWA_Queue::requeue_unclaimed( 9 ), 'Only a waiting job may be re-scheduled.' );
msrwa_test_assert( array() === msrwa_test_scheduled_jobs(), 'A job that is not waiting must not be scheduled.' );

msrwa_test_done( 'MSRWA queue scheduling contracts' );
