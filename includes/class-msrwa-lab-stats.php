<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the laboratory's runs say once there is more than one of them.
 *
 * A single run answers "what happened". These answer the questions that decide
 * what to change: which step costs the most, which model is actually being
 * billed, how often a check fails, whether the judge ever approves. Every
 * figure here is read from what the engine reported, never re-derived.
 */
final class MSRWA_Lab_Stats {

	/** Runs worth counting: the ones that actually ran something. */
	private static function scope( $days = 0 ) {
		$where = " WHERE r.status IN ('done','failed')";
		if ( $days > 0 ) { $where .= $GLOBALS['wpdb']->prepare( ' AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', (int) $days ); }
		return $where;
	}

	public static function summary( $days = 0 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$row = $wpdb->get_row( 'SELECT COUNT(*) runs, SUM(r.cost_usd) spend, AVG(r.cost_usd) cost, AVG(r.seconds) seconds FROM ' . $t['lab_runs'] . ' r' . self::scope( $days ), ARRAY_A );
		return array(
			'runs' => (int) ( $row['runs'] ?? 0 ),
			'spend_usd' => round( (float) ( $row['spend'] ?? 0 ), 4 ),
			'cost_usd' => round( (float) ( $row['cost'] ?? 0 ), 4 ),
			'seconds' => round( (float) ( $row['seconds'] ?? 0 ), 1 ),
		);
	}

	/** Where a run's money and minutes go, step by step. */
	public static function by_step( $days = 0 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		return (array) $wpdb->get_results(
			'SELECT s.step, s.bucket, COUNT(*) runs, AVG(s.seconds) seconds, AVG(s.cost_usd) cost, SUM(s.cost_usd) spend,'
			. ' AVG(s.input_tokens) input_tokens, AVG(s.output_tokens) output_tokens,'
			. ' SUM(CASE WHEN s.error_message <> \'\' THEN 1 ELSE 0 END) failures,'
			. ' AVG(CASE WHEN s.total > 0 THEN s.passed / s.total END) score'
			. ' FROM ' . $t['lab_steps'] . ' s INNER JOIN ' . $t['lab_runs'] . ' r ON r.id = s.run_id'
			. self::scope( $days ) . ' GROUP BY s.step, s.bucket ORDER BY spend DESC', ARRAY_A );
	}

	/** Which model actually answered, how much of its input was cached, what it cost. */
	public static function by_model( $days = 0 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		return (array) $wpdb->get_results(
			'SELECT c.provider, c.model, COUNT(*) calls, SUM(c.input_tokens) input_tokens, SUM(c.output_tokens) output_tokens,'
			. ' SUM(c.cached_tokens) cached_tokens, SUM(c.cost_usd) spend, AVG(c.seconds) seconds,'
			. ' SUM(CASE WHEN c.priced = 0 THEN 1 ELSE 0 END) unpriced'
			. ' FROM ' . $t['lab_calls'] . ' c INNER JOIN ' . $t['lab_runs'] . ' r ON r.id = c.run_id'
			. self::scope( $days ) . ' GROUP BY c.provider, c.model ORDER BY spend DESC', ARRAY_A );
	}

	/**
	 * Which named check fails, and how often.
	 *
	 * This is the question a single run cannot answer and the one that decides
	 * whether a prompt needs changing: a check that fails in one run of six is
	 * noise, and one that fails in six of six is a contract the prompt is not
	 * keeping.
	 */
	public static function checks( $days = 0 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$rows = (array) $wpdb->get_results(
			'SELECT s.step, s.checks_json FROM ' . $t['lab_steps'] . ' s INNER JOIN ' . $t['lab_runs'] . ' r ON r.id = s.run_id'
			. self::scope( $days ) . ' AND s.checks_json IS NOT NULL', ARRAY_A );

		$tally = array();
		foreach ( $rows as $row ) {
			foreach ( (array) json_decode( (string) $row['checks_json'], true ) as $label => $check ) {
				if ( ! is_array( $check ) ) { continue; }
				$key = $row['step'] . '|' . $label;
				if ( ! isset( $tally[ $key ] ) ) { $tally[ $key ] = array( 'step' => $row['step'], 'check' => (string) $label, 'seen' => 0, 'failed' => 0 ); }
				$tally[ $key ]['seen']++;
				if ( empty( $check['pass'] ) ) { $tally[ $key ]['failed']++; }
			}
		}
		$tally = array_values( array_filter( $tally, static function ( $entry ) { return $entry['failed'] > 0; } ) );
		usort( $tally, static function ( $a, $b ) { return ( $b['failed'] / $b['seen'] ) <=> ( $a['failed'] / $a['seen'] ); } );
		return $tally;
	}

	/**
	 * What the final judge decided, across every run that reached it.
	 *
	 * Including how often each artifact came back good — the figure that says
	 * whether a refusal is a real signal or a reviewer that must always find
	 * something.
	 */
	public static function verdicts( $days = 0 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$rows = (array) $wpdb->get_col(
			'SELECT a.content_json FROM ' . $t['lab_artifacts'] . " a INNER JOIN " . $t['lab_runs'] . ' r ON r.id = a.run_id'
			. self::scope( $days ) . " AND a.artifact_key = 'approval'" );

		$out = array( 'judged' => 0, 'approved' => 0, 'findings' => 0, 'blocking' => 0, 'artifacts' => array() );
		foreach ( $rows as $json ) {
			$verdict = json_decode( (string) $json, true );
			if ( ! is_array( $verdict ) ) { continue; }
			$out['judged']++;
			if ( ! empty( $verdict['approved'] ) ) { $out['approved']++; }
			foreach ( (array) ( $verdict['findings'] ?? array() ) as $finding ) {
				$out['findings']++;
				if ( 'blocking' === ( $finding['severity'] ?? '' ) ) { $out['blocking']++; }
			}
			foreach ( array( 'article', 'featured_image', 'facebook_image', 'consistency' ) as $target ) {
				$value = (string) ( ( (array) ( $verdict[ $target ] ?? array() ) )['verdict'] ?? '' );
				if ( '' === $value ) { continue; }
				if ( ! isset( $out['artifacts'][ $target ] ) ) { $out['artifacts'][ $target ] = array(); }
				$out['artifacts'][ $target ][ $value ] = (int) ( $out['artifacts'][ $target ][ $value ] ?? 0 ) + 1;
			}
		}
		return $out;
	}
}
