<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Queue {
	public static function schedule_batch( $batch_id ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'msrwa_process_batch', array( 'batch_id' => absint( $batch_id ) ), 'ms-recipes-writer-ai' );
		} else {
			wp_schedule_single_event( time() + 10, 'msrwa_process_batch', array( absint( $batch_id ) ) );
		}
	}

	public static function process_batch( $batch_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['batches']} WHERE id = %d", $batch_id ) );
		if ( ! $batch || in_array( $batch->status, array( 'cancelled', 'completed' ), true ) ) { return; }
		$wpdb->update( $t['batches'], array( 'status' => 'running', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $batch_id ), array( '%s', '%s' ), array( '%d' ) );
		MSRWA_DB::event( 'batch_started', $batch_id, 0, array( 'stage' => 'intake' ) );
		// The first release intentionally stops at a durable, inspectable queue state.
		// Provider calls are added only after credentials and a paid-test budget are approved.
		$wpdb->update( $t['batches'], array( 'status' => 'awaiting_admin', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $batch_id ), array( '%s', '%s' ), array( '%d' ) );
		MSRWA_DB::event( 'batch_awaiting_admin', $batch_id, 0, array( 'reason' => 'provider_calls_require_explicit_test_budget' ) );
	}
}
