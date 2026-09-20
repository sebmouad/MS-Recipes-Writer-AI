<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Queue {
	public static function acquire_job( $job_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$token = wp_generate_uuid4();
		if ( ! self::acquire_slot_lock() ) { return false; }
		try {
			$limit = max( 1, (int) MSRWA_Settings::get()['max_concurrency'] );
			$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['jobs']} WHERE status = 'running' AND lock_until > UTC_TIMESTAMP()" );
			if ( $active >= $limit ) { return false; }
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['jobs']} SET lock_token = %s, lock_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), status = 'running', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status IN ('queued','retry_wait') AND (lock_until IS NULL OR lock_until < UTC_TIMESTAMP())", $token, current_time( 'mysql', true ), absint( $job_id ) ) );
		} finally {
			self::release_slot_lock();
		}
		return $updated ? $token : false;
	}

	private static function slot_lock_name() { return 'msrwa_slots_' . ( function_exists( 'get_current_blog_id' ) ? absint( get_current_blog_id() ) : 1 ); }

	private static function acquire_slot_lock() {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', self::slot_lock_name() ) );
	}

	private static function release_slot_lock() {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::slot_lock_name() ) );
	}

	public static function release_job( $job_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t['jobs']} SET lock_token = NULL, lock_until = NULL WHERE id = %d", absint( $job_id ) ) );
	}

	public static function cancel_job( $job_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['jobs']} SET status = 'cancelled', error_code = 'cancelled_by_user', error_message = 'Traitement annulé par l’utilisateur.', lock_token = NULL, lock_until = NULL, updated_at = %s WHERE id = %d AND status NOT IN ('completed','cancelled')", current_time( 'mysql', true ), absint( $job_id ) ) );
		if ( $updated ) {
			$job = $wpdb->get_row( $wpdb->prepare( "SELECT batch_id FROM {$t['jobs']} WHERE id = %d", absint( $job_id ) ) );
			if ( $job ) { self::refresh_batch( $job->batch_id ); MSRWA_DB::event( 'job_cancelled', $job->batch_id, $job_id ); }
		}
		return (bool) $updated;
	}

	public static function pause_batch( $batch_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['batches']} SET status = 'paused', updated_at = %s WHERE id = %d AND status NOT IN ('completed','cancelled')", $now, absint( $batch_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t['jobs']} SET status = 'paused', lock_token = NULL, lock_until = NULL, updated_at = %s WHERE batch_id = %d AND status IN ('queued','retry_wait','running')", $now, absint( $batch_id ) ) );
		if ( $updated ) { MSRWA_DB::event( 'batch_paused', $batch_id ); }
		return (bool) $updated;
	}

	public static function resume_batch( $batch_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['batches']} SET status = 'queued', updated_at = %s WHERE id = %d AND status IN ('paused','awaiting_admin','paused_budget')", $now, absint( $batch_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t['jobs']} SET status = 'queued', error_code = NULL, error_message = NULL, updated_at = %s WHERE batch_id = %d AND status IN ('paused','awaiting_admin','paused_budget')", $now, absint( $batch_id ) ) );
		if ( $updated ) { MSRWA_DB::event( 'batch_resumed', $batch_id ); self::schedule_batch( $batch_id ); }
		return (bool) $updated;
	}

	public static function cancel_batch( $batch_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['batches']} SET status = 'cancelled', updated_at = %s WHERE id = %d AND status NOT IN ('completed','cancelled')", $now, absint( $batch_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t['jobs']} SET status = 'cancelled', error_code = 'cancelled_by_user', error_message = 'Lot annulé par l’utilisateur.', lock_token = NULL, lock_until = NULL, updated_at = %s WHERE batch_id = %d AND status NOT IN ('completed','cancelled')", $now, absint( $batch_id ) ) );
		if ( $updated ) { MSRWA_DB::event( 'batch_cancelled', $batch_id ); }
		return (bool) $updated;
	}

	public static function active_count() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['jobs']} WHERE status = 'running' OR (status = 'queued' AND lock_until > UTC_TIMESTAMP())" );
	}

	/**
	 * Small, read-only queue diagnostic for the Configuration screen. It does
	 * not assume a browser visit is a cron runner and never changes a job.
	 */
	public static function health() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$now = current_time( 'timestamp', true );
		$next_cleanup = wp_next_scheduled( 'msrwa_cleanup' );
		$counts = $wpdb->get_row( "SELECT SUM(status IN ('queued','retry_wait')) AS waiting, SUM(status = 'running') AS running, SUM(status = 'running' AND lock_until IS NOT NULL AND lock_until < UTC_TIMESTAMP()) AS expired, SUM(status IN ('awaiting_admin','awaiting_input','paused_budget','needs_review','failed','uncertain')) AS attention FROM {$t['jobs']}" );
		$last_progress = $wpdb->get_var( "SELECT MAX(created_at) FROM {$t['events']} WHERE event_type IN ('batch_started','draft_completed','job_retry_scheduled','final_review_passed')" );
		$waiting = absint( $counts->waiting ?? 0 );
		$running = absint( $counts->running ?? 0 );
		$expired = absint( $counts->expired ?? 0 );
		$attention = absint( $counts->attention ?? 0 );
		$status = $expired ? 'degraded' : ( $waiting && ! $next_cleanup ? 'warning' : 'healthy' );
		return array(
			'status' => $status,
			'driver' => function_exists( 'as_enqueue_async_action' ) ? 'Action Scheduler' : 'WP-Cron',
			'next_cleanup_at' => $next_cleanup ? gmdate( 'Y-m-d H:i:s', $next_cleanup ) : '',
			'next_cleanup_in_seconds' => $next_cleanup ? max( 0, $next_cleanup - $now ) : 0,
			'last_progress_at' => $last_progress ? (string) $last_progress : '',
			'waiting' => $waiting,
			'running' => $running,
			'expired_workers' => $expired,
			'needs_attention' => $attention,
		);
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
		$counts = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(status = 'completed') AS completed, SUM(status = 'cancelled') AS cancelled, SUM(status IN ('queued','running','retry_wait')) AS active, SUM(status IN ('awaiting_admin','paused_budget')) AS awaiting_admin, SUM(status = 'paused') AS paused, SUM(status IN ('failed','needs_review','awaiting_input','uncertain')) AS needs_review FROM {$t['jobs']} WHERE batch_id = %d", absint( $batch_id ) ) );
		if ( ! $counts ) { return; }
		$completed = (int) $counts->completed;
		$status = ( (int) $counts->cancelled >= (int) $counts->total && (int) $counts->total > 0 ) ? 'cancelled' : ( ( $completed + (int) $counts->cancelled >= (int) $counts->total && (int) $counts->total > 0 ) ? 'completed' : ( (int) $counts->active ? 'running' : ( (int) $counts->awaiting_admin ? 'awaiting_admin' : ( (int) $counts->paused ? 'paused' : ( (int) $counts->needs_review ? 'needs_review' : 'running' ) ) ) ) );
		$wpdb->update( $t['batches'], array( 'status' => $status, 'completed' => $completed, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $batch_id ) ), array( '%s', '%d', '%s' ), array( '%d' ) );
	}

	public static function reconcile_batches( $limit = 500 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t['batches']} WHERE total > 0 ORDER BY id DESC LIMIT %d", min( 1000, max( 1, absint( $limit ) ) ) ) );
		foreach ( $ids as $id ) { self::refresh_batch( $id ); }
		return count( $ids );
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
		// A previously scheduled worker must not silently undo a user pause or
		// resume a batch that is waiting for an explicit decision.
		if ( ! $batch || in_array( $batch->status, array( 'cancelled', 'completed', 'paused', 'awaiting_admin', 'paused_budget', 'needs_review' ), true ) ) { return; }
		$wpdb->update( $t['batches'], array( 'status' => 'running', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $batch_id ), array( '%s', '%s' ), array( '%d' ) );
		MSRWA_DB::event( 'batch_started', $batch_id, 0, array( 'stage' => 'intake' ) );
		$settings = MSRWA_Settings::get();
		$slots = max( 0, (int) $settings['max_concurrency'] - self::active_count() );
		if ( ! $slots ) { return; }
		$jobs = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t['jobs']} WHERE batch_id = %d AND status = 'queued' AND (lock_until IS NULL OR lock_until < UTC_TIMESTAMP()) ORDER BY id ASC LIMIT %d", $batch_id, $slots ) );
		foreach ( $jobs as $job_id ) { self::schedule_job( $job_id ); }
	}
}
