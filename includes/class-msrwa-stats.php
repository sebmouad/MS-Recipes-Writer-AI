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
		$performance_sql = "SELECT SUM(j.status IN ('completed','needs_review')) AS completed, SUM(j.status IN ('failed','needs_review')) AS failed, COALESCE(SUM(CASE WHEN j.status IN ('completed','needs_review') THEN j.cost_estimate ELSE 0 END),0) AS completed_cost, AVG(CASE WHEN j.status IN ('completed','needs_review') THEN TIMESTAMPDIFF(SECOND, j.created_at, j.updated_at) ELSE NULL END) AS average_completed_seconds, MAX(CASE WHEN j.status IN ('completed','needs_review') THEN TIMESTAMPDIFF(SECOND, j.created_at, j.updated_at) ELSE NULL END) AS max_completed_seconds FROM {$t['jobs']} j WHERE {$job_where}";
		$operation_sql = "SELECT c.operation, c.status, COUNT(*) AS count, COALESCE(SUM(c.input_tokens),0) AS input_tokens, COALESCE(SUM(c.output_tokens),0) AS output_tokens, COALESCE(SUM(c.cost_estimate),0) AS cost FROM {$t['calls']} c INNER JOIN {$t['jobs']} j ON j.id = c.job_id WHERE {$call_where} GROUP BY c.operation, c.status ORDER BY cost DESC, count DESC";
		$event_sql = "SELECT e.event_type, COUNT(*) AS count FROM {$t['events']} e LEFT JOIN {$t['jobs']} j ON j.id = e.job_id LEFT JOIN {$t['batches']} b ON b.id = e.batch_id WHERE {$event_where} GROUP BY e.event_type ORDER BY count DESC, e.event_type ASC";
		$statuses = $job_args ? $wpdb->get_results( $wpdb->prepare( $status_sql, $job_args ), ARRAY_A ) : $wpdb->get_results( $status_sql, ARRAY_A );
		$performance = $job_args ? $wpdb->get_row( $wpdb->prepare( $performance_sql, $job_args ), ARRAY_A ) : $wpdb->get_row( $performance_sql, ARRAY_A );
		$operations = $call_args ? $wpdb->get_results( $wpdb->prepare( $operation_sql, $call_args ), ARRAY_A ) : $wpdb->get_results( $operation_sql, ARRAY_A );
		$events = $event_args ? $wpdb->get_results( $wpdb->prepare( $event_sql, $event_args ), ARRAY_A ) : $wpdb->get_results( $event_sql, ARRAY_A );
		$recent_sql = "SELECT j.id,j.batch_id,j.title,j.status,j.stage,j.artifacts_json,j.draft_post_id,j.article_quality,j.featured_quality,j.facebook_quality,j.quality_checked_at,j.cost_estimate,j.correction_cycles,j.attempts,j.retry_attempts,j.created_at,j.updated_at,a.content_json AS quality_json FROM {$t['jobs']} j LEFT JOIN {$t['artifacts']} a ON a.job_id = j.id AND a.artifact_key = 'editorial_review' AND a.status = 'current' WHERE {$job_where} ORDER BY j.id DESC LIMIT 25";
		$recent_jobs = $job_args ? $wpdb->get_results( $wpdb->prepare( $recent_sql, $job_args ), ARRAY_A ) : $wpdb->get_results( $recent_sql, ARRAY_A );
		// Quality is measured on the articles produced, never on jobs that produced none.
		$quality_sql = "SELECT j.cost_estimate,j.article_quality,j.featured_quality,j.facebook_quality,j.quality_checked_at,a.content_json AS quality_json FROM {$t['jobs']} j LEFT JOIN {$t['artifacts']} a ON a.job_id = j.id AND a.artifact_key = 'editorial_review' AND a.status = 'current' WHERE {$job_where} AND j.draft_post_id > 0";
		$quality_rows = $job_args ? $wpdb->get_results( $wpdb->prepare( $quality_sql, $job_args ), ARRAY_A ) : $wpdb->get_results( $quality_sql, ARRAY_A );
		$quality = array( 'articles' => count( $quality_rows ), 'evaluated' => 0, 'passed' => 0, 'approved' => 0, 'within_target_cost' => 0 );
		$target_cost = (float) ( MSRWA_Settings::get()['target_cost_usd'] ?? 0.10 );
		foreach ( $quality_rows as $quality_row ) {
			$report = json_decode( (string) $quality_row['quality_json'], true );
			if ( is_array( $report ) && $report ) {
				$quality['evaluated']++;
				$quality['passed'] += empty( $report['text_review_passed'] ) ? 0 : 1;
			}
			// The delivery verdict, not the structural gate: what an editor is shown.
			$quality['approved'] += 'good' === ( $quality_row['article_quality'] ?? '' ) ? 1 : 0;
			if ( (float) $quality_row['cost_estimate'] <= $target_cost ) { $quality['within_target_cost']++; }
		}
		foreach ( $recent_jobs as &$recent ) {
			$report = json_decode( (string) $recent['quality_json'], true );
			$recent['quality'] = is_array( $report ) ? $report : array();
			$recent['display_quality'] = MSRWA_Presentation::quality( $recent );
			$recent['display_status'] = MSRWA_Presentation::state( $recent['status'] );
			unset( $recent['artifacts_json'] );
			unset( $recent['quality_json'] );
		}
		unset( $recent );
		$quality['target_cost_usd'] = $target_cost;
		$editors = array();
		if ( ! $owner_id ) {
			$editors = $wpdb->get_results( "SELECT j.owner_id, COUNT(*) AS jobs, SUM(j.status IN ('completed','needs_review')) AS completed, COALESCE(SUM(j.cost_estimate),0) AS cost FROM {$t['jobs']} j WHERE j.created_at >= UTC_TIMESTAMP() - INTERVAL {$days} DAY GROUP BY j.owner_id ORDER BY cost DESC, jobs DESC", ARRAY_A );
			foreach ( $editors as &$editor ) {
				$user = get_userdata( (int) $editor['owner_id'] );
				$editor['name'] = $user ? $user->display_name : sprintf( 'Utilisateur #%d', (int) $editor['owner_id'] );
			}
		}
		$performance = is_array( $performance ) ? $performance : array();
		$completed = absint( $performance['completed'] ?? 0 );
		$completed_cost = (float) ( $performance['completed_cost'] ?? 0 );
		$performance['completed'] = $completed;
		$performance['failed'] = absint( $performance['failed'] ?? 0 );
		$performance['completed_cost'] = $completed_cost;
		$performance['cost_per_completed'] = $completed ? $completed_cost / $completed : 0;
		$performance['average_completed_seconds'] = isset( $performance['average_completed_seconds'] ) ? (float) $performance['average_completed_seconds'] : 0;
		$performance['max_completed_seconds'] = isset( $performance['max_completed_seconds'] ) ? (int) $performance['max_completed_seconds'] : 0;
		return array( 'statuses' => $statuses, 'operations' => $operations, 'events' => $events, 'editors' => $editors, 'performance' => $performance, 'quality' => $quality, 'recent_jobs' => $recent_jobs );
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
			$columns = array( 'id', 'batch_id', 'owner_id', 'title', 'status', 'stage', 'correction_cycles', 'attempts', 'retry_attempts', 'cost_estimate', 'draft_post_id', 'error_code', 'created_at', 'updated_at' );
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
