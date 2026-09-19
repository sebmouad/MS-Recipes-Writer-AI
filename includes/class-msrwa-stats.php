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

	public static function details( $days = 7, $owner_id = 0 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$days = min( 365, max( 1, absint( $days ) ) );
		$job_where = "j.created_at >= UTC_TIMESTAMP() - INTERVAL {$days} DAY";
		$call_where = "c.started_at >= UTC_TIMESTAMP() - INTERVAL {$days} DAY";
		$event_where = "e.created_at >= UTC_TIMESTAMP() - INTERVAL {$days} DAY";
		$job_args = array();
		$call_args = array();
		$event_args = array();
		if ( $owner_id ) {
			$job_where .= ' AND j.owner_id = %d'; $job_args[] = absint( $owner_id );
			$call_where .= ' AND j.owner_id = %d'; $call_args[] = absint( $owner_id );
			$event_where .= ' AND (j.owner_id = %d OR b.owner_id = %d)'; $event_args[] = absint( $owner_id ); $event_args[] = absint( $owner_id );
		}
		$status_sql = "SELECT j.status, COUNT(*) AS count, COALESCE(SUM(j.cost_estimate),0) AS cost FROM {$t['jobs']} j WHERE {$job_where} GROUP BY j.status ORDER BY count DESC";
		$operation_sql = "SELECT c.operation, c.status, COUNT(*) AS count, COALESCE(SUM(c.input_tokens),0) AS input_tokens, COALESCE(SUM(c.output_tokens),0) AS output_tokens, COALESCE(SUM(c.cost_estimate),0) AS cost FROM {$t['calls']} c INNER JOIN {$t['jobs']} j ON j.id = c.job_id WHERE {$call_where} GROUP BY c.operation, c.status ORDER BY cost DESC, count DESC";
		$event_sql = "SELECT e.event_type, COUNT(*) AS count FROM {$t['events']} e LEFT JOIN {$t['jobs']} j ON j.id = e.job_id LEFT JOIN {$t['batches']} b ON b.id = e.batch_id WHERE {$event_where} GROUP BY e.event_type ORDER BY count DESC, e.event_type ASC";
		$statuses = $job_args ? $wpdb->get_results( $wpdb->prepare( $status_sql, $job_args ), ARRAY_A ) : $wpdb->get_results( $status_sql, ARRAY_A );
		$operations = $call_args ? $wpdb->get_results( $wpdb->prepare( $operation_sql, $call_args ), ARRAY_A ) : $wpdb->get_results( $operation_sql, ARRAY_A );
		$events = $event_args ? $wpdb->get_results( $wpdb->prepare( $event_sql, $event_args ), ARRAY_A ) : $wpdb->get_results( $event_sql, ARRAY_A );
		$editors = array();
		if ( ! $owner_id ) {
			$editors = $wpdb->get_results( "SELECT j.owner_id, COUNT(*) AS jobs, SUM(j.status = 'completed') AS completed, COALESCE(SUM(j.cost_estimate),0) AS cost FROM {$t['jobs']} j WHERE j.created_at >= UTC_TIMESTAMP() - INTERVAL {$days} DAY GROUP BY j.owner_id ORDER BY cost DESC, jobs DESC", ARRAY_A );
			foreach ( $editors as &$editor ) {
				$user = get_userdata( (int) $editor['owner_id'] );
				$editor['name'] = $user ? $user->display_name : sprintf( 'Utilisateur #%d', (int) $editor['owner_id'] );
			}
		}
		return array( 'statuses' => $statuses, 'operations' => $operations, 'events' => $events, 'editors' => $editors );
	}

	public static function summary_range( $from, $to, $owner_id = 0 ) {
		$from = self::date_boundary( $from, 'start' );
		$to = self::date_boundary( $to, 'end' );
		if ( ! $from || ! $to || $from >= $to ) { return new WP_Error( 'invalid_stats_range', 'La période statistique est invalide.', array( 'status' => 400 ) ); }
		global $wpdb;
		$t = MSRWA_DB::tables();
		$where_jobs = 'created_at >= %s AND created_at < %s'; $args_jobs = array( $from, $to );
		$where_calls = 'started_at >= %s AND started_at < %s'; $args_calls = array( $from, $to );
		if ( $owner_id ) { $where_jobs .= ' AND owner_id = %d'; $args_jobs[] = absint( $owner_id ); $where_calls .= ' AND job_id IN (SELECT id FROM ' . $t['jobs'] . ' WHERE owner_id = %d)'; $args_calls[] = absint( $owner_id ); }
		$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS count FROM {$t['jobs']} WHERE {$where_jobs} GROUP BY status", $args_jobs ), ARRAY_A );
		$calls = $wpdb->get_results( $wpdb->prepare( "SELECT provider, model, operation, status, COUNT(*) AS count, COALESCE(SUM(cost_estimate),0) AS cost, COALESCE(SUM(input_tokens),0) AS input_tokens, COALESCE(SUM(output_tokens),0) AS output_tokens FROM {$t['calls']} WHERE {$where_calls} GROUP BY provider, model, operation, status", $args_calls ), ARRAY_A );
		$job_counts = array(); foreach ( $jobs as $row ) { $job_counts[ $row['status'] ] = (int) $row['count']; }
		$total_cost = 0.0; foreach ( $calls as $row ) { $total_cost += (float) $row['cost']; }
		return array( 'from' => $from, 'to' => $to, 'jobs' => $job_counts, 'total_jobs' => array_sum( $job_counts ), 'calls' => $calls, 'estimated_cost_usd' => round( $total_cost, 6 ), 'generated_at' => current_time( 'mysql', true ) );
	}

	private static function date_boundary( $value, $side ) {
		$value = sanitize_text_field( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/', $value ) ) { return ''; }
		if ( 10 !== strlen( $value ) ) { return str_replace( 'T', ' ', $value ); }
		try {
			$date = new DateTimeImmutable( $value . ' 00:00:00', new DateTimeZone( 'UTC' ) );
			if ( 'end' === $side ) { $date = $date->modify( '+1 day' ); }
			return $date->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	public static function export( $dataset, $from, $to, $page = 1, $per_page = 100, $owner_id = 0 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$dataset = sanitize_key( $dataset );
		if ( ! in_array( $dataset, array( 'jobs', 'calls', 'events' ), true ) ) { return new WP_Error( 'invalid_export_dataset', 'Jeu de données d’export invalide.', array( 'status' => 400 ) ); }
		$from = self::date_boundary( $from, 'start' );
		$to = self::date_boundary( $to, 'end' );
		if ( ! $from || ! $to || $from >= $to ) { return new WP_Error( 'invalid_export_range', 'La période d’export est invalide.', array( 'status' => 400 ) ); }
		$page = max( 1, absint( $page ) );
		$per_page = min( 500, max( 1, absint( $per_page ) ) );
		$offset = ( $page - 1 ) * $per_page;
		$args = array( $from, $to );
		$where = '';
		$columns = array();
		if ( 'jobs' === $dataset ) {
			$columns = array( 'id', 'batch_id', 'owner_id', 'title', 'status', 'stage', 'correction_cycles', 'attempts', 'cost_estimate', 'draft_post_id', 'error_code', 'created_at', 'updated_at' );
			$where = 'created_at >= %s AND created_at < %s';
			if ( $owner_id ) { $where .= ' AND owner_id = %d'; $args[] = absint( $owner_id ); }
			$sql = "SELECT " . implode( ',', $columns ) . " FROM {$t['jobs']} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d";
		} elseif ( 'calls' === $dataset ) {
			$columns = array( 'id', 'batch_id', 'job_id', 'provider', 'model', 'operation', 'status', 'http_status', 'request_id', 'input_tokens', 'output_tokens', 'cost_estimate', 'uncertain', 'error_code', 'started_at', 'finished_at' );
			$where = 'started_at >= %s AND started_at < %s';
			if ( $owner_id ) { $where .= ' AND job_id IN (SELECT id FROM ' . $t['jobs'] . ' WHERE owner_id = %d)'; $args[] = absint( $owner_id ); }
			$sql = "SELECT " . implode( ',', $columns ) . " FROM {$t['calls']} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d";
		} else {
			$columns = array( 'id', 'batch_id', 'job_id', 'actor_id', 'event_type', 'payload_json', 'created_at' );
			$where = 'created_at >= %s AND created_at < %s';
			if ( $owner_id ) { $where .= ' AND (job_id IN (SELECT id FROM ' . $t['jobs'] . ' WHERE owner_id = %d) OR batch_id IN (SELECT id FROM ' . $t['batches'] . ' WHERE owner_id = %d))'; $args[] = absint( $owner_id ); $args[] = absint( $owner_id ); }
			$sql = "SELECT " . implode( ',', $columns ) . " FROM {$t['events']} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d";
		}
		$args[] = $per_page; $args[] = $offset;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		foreach ( $rows as &$row ) {
			if ( isset( $row['payload_json'] ) ) {
				$decoded = json_decode( (string) $row['payload_json'], true );
				$row['payload'] = is_array( $decoded ) ? $decoded : array();
				unset( $row['payload_json'] );
			}
		}
		return array( 'dataset' => $dataset, 'from' => $from, 'to' => $to, 'page' => $page, 'per_page' => $per_page, 'rows' => $rows );
	}

	public static function events( $page = 1, $per_page = 50, $owner_id = 0 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$page = max( 1, absint( $page ) );
		$per_page = min( 100, max( 1, absint( $per_page ) ) );
		$offset = ( $page - 1 ) * $per_page;
		if ( $owner_id ) {
			$sql = $wpdb->prepare( "SELECT e.* FROM {$t['events']} e LEFT JOIN {$t['jobs']} j ON j.id = e.job_id LEFT JOIN {$t['batches']} b ON b.id = e.batch_id WHERE j.owner_id = %d OR b.owner_id = %d ORDER BY e.id DESC LIMIT %d OFFSET %d", absint( $owner_id ), absint( $owner_id ), $per_page, $offset );
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM {$t['events']} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset );
		}
		return $wpdb->get_results( $sql, ARRAY_A );
	}
}
