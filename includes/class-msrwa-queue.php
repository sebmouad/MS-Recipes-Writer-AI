<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Queue {
	public static function acquire_job( $job_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$token = wp_generate_uuid4();
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['jobs']} SET lock_token = %s, lock_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), status = 'running', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status IN ('queued','retry_wait') AND (lock_until IS NULL OR lock_until < UTC_TIMESTAMP())", $token, current_time( 'mysql', true ), absint( $job_id ) ) );
		return $updated ? $token : false;
	}

	public static function release_job( $job_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t['jobs']} SET lock_token = NULL, lock_until = NULL WHERE id = %d", absint( $job_id ) ) );
	}

	public static function active_count() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['jobs']} WHERE status = 'running' OR (status = 'queued' AND lock_until > UTC_TIMESTAMP())" );
	}

	public static function recover_expired() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$ids = $wpdb->get_col( "SELECT id FROM {$t['jobs']} WHERE status = 'running' AND lock_until IS NOT NULL AND lock_until < UTC_TIMESTAMP() LIMIT 100" );
		foreach ( $ids as $id ) {
			$wpdb->update( $t['jobs'], array( 'status' => 'retry_wait', 'error_code' => 'worker_lease_expired', 'error_message' => 'Le bail du worker a expiré ; reprise planifiée.', 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $id ) ), array( '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
			self::schedule_job( $id, 5 );
		}
		return count( $ids );
	}

	public static function refresh_batch( $batch_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$counts = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(status = 'completed') AS completed, SUM(status = 'cancelled') AS cancelled FROM {$t['jobs']} WHERE batch_id = %d", absint( $batch_id ) ) );
		if ( ! $counts ) { return; }
		$completed = (int) $counts->completed;
		$status = ( $completed + (int) $counts->cancelled >= (int) $counts->total && (int) $counts->total > 0 ) ? 'completed' : 'running';
		$wpdb->update( $t['batches'], array( 'status' => $status, 'completed' => $completed, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $batch_id ) ), array( '%s', '%d', '%s' ), array( '%d' ) );
	}

	public static function schedule_batch( $batch_id ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'msrwa_process_batch', array( 'batch_id' => absint( $batch_id ) ), 'ms-recipes-writer-ai' );
		} else {
			wp_schedule_single_event( time() + 10, 'msrwa_process_batch', array( absint( $batch_id ) ) );
		}
	}

	public static function schedule_job( $job_id, $delay = 0 ) {
		$delay = max( 0, absint( $delay ) );
		if ( $delay && function_exists( 'as_schedule_single_action' ) ) { as_schedule_single_action( time() + $delay, 'msrwa_process_job', array( 'job_id' => absint( $job_id ) ), 'ms-recipes-writer-ai' ); }
		elseif ( function_exists( 'as_enqueue_async_action' ) && ! $delay ) { as_enqueue_async_action( 'msrwa_process_job', array( 'job_id' => absint( $job_id ) ), 'ms-recipes-writer-ai' ); }
		else { wp_schedule_single_event( time() + max( 5, $delay ), 'msrwa_process_job', array( absint( $job_id ) ) ); }
	}

	public static function process_batch( $batch_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['batches']} WHERE id = %d", $batch_id ) );
		if ( ! $batch || in_array( $batch->status, array( 'cancelled', 'completed' ), true ) ) { return; }
		$wpdb->update( $t['batches'], array( 'status' => 'running', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $batch_id ), array( '%s', '%s' ), array( '%d' ) );
		MSRWA_DB::event( 'batch_started', $batch_id, 0, array( 'stage' => 'intake' ) );
		$settings = MSRWA_Settings::get();
		if ( empty( $settings['allow_paid_tests'] ) || (float) $settings['test_budget_usd'] <= 0 ) {
			$wpdb->update( $t['batches'], array( 'status' => 'awaiting_admin', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $batch_id ), array( '%s', '%s' ), array( '%d' ) );
			MSRWA_DB::event( 'batch_awaiting_admin', $batch_id, 0, array( 'reason' => 'provider_calls_require_explicit_test_budget' ) );
			return;
		}
		$slots = max( 0, (int) $settings['max_concurrency'] - self::active_count() );
		if ( ! $slots ) { return; }
		$jobs = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t['jobs']} WHERE batch_id = %d AND status = 'queued' AND (lock_until IS NULL OR lock_until < UTC_TIMESTAMP()) ORDER BY id ASC LIMIT %d", $batch_id, $slots ) );
		foreach ( $jobs as $job_id ) { self::schedule_job( $job_id ); }
	}
}
