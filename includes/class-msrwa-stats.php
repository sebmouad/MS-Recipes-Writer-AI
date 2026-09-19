<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Stats {
	public static function summary( $days = 7, $owner_id = 0 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$days = min( 365, max( 1, absint( $days ) ) );
		$where_jobs = "created_at >= UTC_TIMESTAMP() - INTERVAL {$days} DAY";
		$where_calls = "started_at >= UTC_TIMESTAMP() - INTERVAL {$days} DAY";
		$args_jobs = array();
		$args_calls = array();
		if ( $owner_id ) { $where_jobs .= ' AND owner_id = %d'; $args_jobs[] = absint( $owner_id ); }
		if ( $owner_id ) { $where_calls .= ' AND job_id IN (SELECT id FROM ' . $t['jobs'] . ' WHERE owner_id = %d)'; $args_calls[] = absint( $owner_id ); }
		$jobs_sql = "SELECT status, COUNT(*) AS count FROM {$t['jobs']} WHERE {$where_jobs} GROUP BY status";
		$calls_sql = "SELECT provider, model, operation, status, COUNT(*) AS count, COALESCE(SUM(cost_estimate),0) AS cost, COALESCE(SUM(input_tokens),0) AS input_tokens, COALESCE(SUM(output_tokens),0) AS output_tokens FROM {$t['calls']} WHERE {$where_calls} GROUP BY provider, model, operation, status";
		$jobs = $args_jobs ? $wpdb->get_results( $wpdb->prepare( $jobs_sql, $args_jobs ), ARRAY_A ) : $wpdb->get_results( $jobs_sql, ARRAY_A );
		$calls = $args_calls ? $wpdb->get_results( $wpdb->prepare( $calls_sql, $args_calls ), ARRAY_A ) : $wpdb->get_results( $calls_sql, ARRAY_A );
		$job_counts = array();
		foreach ( $jobs as $row ) { $job_counts[ $row['status'] ] = (int) $row['count']; }
		$total_cost = 0.0;
		foreach ( $calls as $row ) { $total_cost += (float) $row['cost']; }
		return array( 'days' => $days, 'jobs' => $job_counts, 'total_jobs' => array_sum( $job_counts ), 'calls' => $calls, 'estimated_cost_usd' => round( $total_cost, 6 ), 'generated_at' => current_time( 'mysql', true ) );
	}

	public static function events( $page = 1, $per_page = 50, $owner_id = 0 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$page = max( 1, absint( $page ) );
		$per_page = min( 100, max( 1, absint( $per_page ) ) );
		$offset = ( $page - 1 ) * $per_page;
		if ( $owner_id ) {
			$sql = $wpdb->prepare( "SELECT e.* FROM {$t['events']} e LEFT JOIN {$t['jobs']} j ON j.id = e.job_id WHERE (j.owner_id = %d OR e.job_id IS NULL) ORDER BY e.id DESC LIMIT %d OFFSET %d", absint( $owner_id ), $per_page, $offset );
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM {$t['events']} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset );
		}
		return $wpdb->get_results( $sql, ARRAY_A );
	}
}
