<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the runs say, one at a time and all together.
 *
 * Every figure here is read back from what the engine reported and never
 * re-derived, which is why an unknown cost stays unknown all the way to the
 * screen: the column is NULL, the count of unpriced steps is carried beside
 * the total, and nothing anywhere turns that into a zero.
 *
 * Scoping is not optional. Each query carries MSRWA_Rights::scope_sql(), so a
 * writer without view_all sees their own work and nobody else's, on every
 * screen, including this one.
 */
final class MSRWA_Ledger {

	private static function tables() { return MSRWA_DB::tables(); }

	/** The state of play right now: what is moving, what is waiting to be read. */
	public static function now() {
		global $wpdb;
		$t = self::tables();
		$scope = MSRWA_Rights::scope_sql( 'r.owner_id' );

		$row = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN r.status IN ('queued','running') THEN 1 ELSE 0 END) moving,
				SUM(CASE WHEN r.status = 'done' AND r.draft_post_id > 0 THEN 1 ELSE 0 END) to_read,
				SUM(CASE WHEN r.status = 'done' AND r.approved = 0 THEN 1 ELSE 0 END) reserved,
				SUM(CASE WHEN r.status = 'failed' THEN 1 ELSE 0 END) failed,
				COUNT(*) total
			FROM {$t['runs']} r WHERE {$scope}", ARRAY_A );

		return array(
			'moving' => (int) ( $row['moving'] ?? 0 ),
			'to_read' => (int) ( $row['to_read'] ?? 0 ),
			'reserved' => (int) ( $row['reserved'] ?? 0 ),
			'failed' => (int) ( $row['failed'] ?? 0 ),
			'total' => (int) ( $row['total'] ?? 0 ),
		);
	}

	/** What has been spent over a window, and how much of it cannot be verified. */
	public static function spend( $days = 0 ) {
		global $wpdb;
		$t = self::tables();
		$scope = MSRWA_Rights::scope_sql( 'r.owner_id' );
		$since = $days > 0 ? $wpdb->prepare( ' AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', (int) $days ) : '';

		$row = $wpdb->get_row(
			"SELECT SUM(r.cost_usd) spend, COUNT(*) runs, AVG(r.cost_usd) average, AVG(r.seconds) seconds
			FROM {$t['runs']} r WHERE {$scope}{$since}", ARRAY_A );

		$unpriced = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$t['steps']} s INNER JOIN {$t['runs']} r ON r.id = s.run_id
			WHERE {$scope}{$since} AND s.cost_usd IS NULL AND (s.input_tokens > 0 OR s.output_tokens > 0)" );

		return array(
			'spend_usd' => round( (float) ( $row['spend'] ?? 0 ), 4 ),
			'runs' => (int) ( $row['runs'] ?? 0 ),
			'average_usd' => round( (float) ( $row['average'] ?? 0 ), 4 ),
			'seconds' => round( (float) ( $row['seconds'] ?? 0 ), 1 ),
			// A step on a model with no published rate cost an unknown amount,
			// not nothing. Saying how many there were is the only honest way to
			// present a total that is missing some of its parts.
			'unpriced_steps' => $unpriced,
		);
	}

	/** Where the money and the minutes go, step by step. */
	public static function by_step( $days = 0 ) {
		global $wpdb;
		$t = self::tables();
		$scope = MSRWA_Rights::scope_sql( 'r.owner_id' );
		$since = $days > 0 ? $wpdb->prepare( ' AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', (int) $days ) : '';

		return (array) $wpdb->get_results(
			"SELECT s.step, s.bucket, COUNT(*) runs, AVG(s.seconds) seconds, AVG(s.cost_usd) cost, SUM(s.cost_usd) spend,
				AVG(s.input_tokens) input_tokens, AVG(s.output_tokens) output_tokens,
				SUM(CASE WHEN s.error_message <> '' THEN 1 ELSE 0 END) failures,
				SUM(CASE WHEN s.cost_usd IS NULL THEN 1 ELSE 0 END) unpriced,
				AVG(CASE WHEN s.total > 0 THEN s.passed * 1.0 / s.total END) score
			FROM {$t['steps']} s INNER JOIN {$t['runs']} r ON r.id = s.run_id
			WHERE {$scope}{$since}
			GROUP BY s.step, s.bucket ORDER BY spend DESC", ARRAY_A );
	}

	/**
	 * Where the money and the minutes actually go, by the engine's own buckets.
	 *
	 * The step table answers *which step*, which is a long list. This answers
	 * *which part of the product* — the article, the featured image, the
	 * Facebook image, the rest — and that is the question somebody looking at a
	 * bill asks first.
	 */
	public static function by_bucket( $days = 0 ) {
		global $wpdb;
		$t = self::tables();
		$scope = MSRWA_Rights::scope_sql( 'r.owner_id' );
		$since = $days > 0 ? $wpdb->prepare( ' AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', (int) $days ) : '';

		return (array) $wpdb->get_results(
			"SELECT s.bucket, COUNT(*) steps, SUM(s.cost_usd) spend, SUM(s.seconds) seconds,
				SUM(CASE WHEN s.cost_usd IS NULL THEN 1 ELSE 0 END) unpriced
			FROM {$t['steps']} s INNER JOIN {$t['runs']} r ON r.id = s.run_id
			WHERE {$scope}{$since}
			GROUP BY s.bucket ORDER BY spend DESC", ARRAY_A );
	}

	/**
	 * The same window, one window earlier.
	 *
	 * A figure on its own says what a recipe costs; the same figure against the
	 * fortnight before says whether it is getting worse. Nothing here decides
	 * whether the difference means anything — the screen refuses to draw a
	 * comparison from a handful of runs, which is where that judgement belongs.
	 */
	public static function previously( $days ) {
		global $wpdb;
		if ( $days <= 0 ) { return array( 'runs' => 0, 'average_usd' => 0.0, 'seconds' => 0.0 ); }
		$t = self::tables();
		$scope = MSRWA_Rights::scope_sql( 'r.owner_id' );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) runs, AVG(r.cost_usd) average, AVG(r.seconds) seconds
			FROM {$t['runs']} r
			WHERE {$scope}
				AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				AND r.created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
			2 * (int) $days, (int) $days ), ARRAY_A );

		return array(
			'runs' => (int) ( $row['runs'] ?? 0 ),
			'average_usd' => round( (float) ( $row['average'] ?? 0 ), 4 ),
			'seconds' => round( (float) ( $row['seconds'] ?? 0 ), 1 ),
		);
	}

	/** Which model actually answered, how much of its input was served from cache. */
	public static function by_model( $days = 0 ) {
		global $wpdb;
		$t = self::tables();
		$scope = MSRWA_Rights::scope_sql( 'r.owner_id' );
		$since = $days > 0 ? $wpdb->prepare( ' AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', (int) $days ) : '';

		return (array) $wpdb->get_results(
			"SELECT c.provider, c.model, COUNT(*) calls, SUM(c.input_tokens) input_tokens,
				SUM(c.output_tokens) output_tokens, SUM(c.cached_tokens) cached_tokens,
				SUM(c.cost_usd) spend, AVG(c.seconds) seconds,
				SUM(CASE WHEN c.priced = 0 THEN 1 ELSE 0 END) unpriced
			FROM {$t['calls']} c INNER JOIN {$t['runs']} r ON r.id = c.run_id
			WHERE {$scope}{$since}
			GROUP BY c.provider, c.model ORDER BY spend DESC", ARRAY_A );
	}

	/** Spend per day, for a window a person can actually read. */
	public static function by_day( $days = 14 ) {
		global $wpdb;
		$t = self::tables();
		$scope = MSRWA_Rights::scope_sql( 'r.owner_id' );

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(r.created_at) day, COUNT(*) runs, SUM(r.cost_usd) spend, AVG(r.seconds) seconds
			FROM {$t['runs']} r
			WHERE {$scope} AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			GROUP BY DATE(r.created_at) ORDER BY day ASC", max( 1, (int) $days ) ), ARRAY_A );
	}

	/**
	 * Which named check fails, and how often.
	 *
	 * The question a single run cannot answer, and the one that decides whether
	 * a prompt needs changing: a check that fails once in six is noise, and one
	 * that fails six times in six is a contract the prompt is not keeping.
	 */
	public static function checks( $days = 0 ) {
		global $wpdb;
		$t = self::tables();
		$scope = MSRWA_Rights::scope_sql( 'r.owner_id' );
		$since = $days > 0 ? $wpdb->prepare( ' AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', (int) $days ) : '';

		// How often each step ran is a counting question, answered by counting.
		$seen = array();
		foreach ( (array) $wpdb->get_results(
			"SELECT s.step, COUNT(*) runs FROM {$t['steps']} s INNER JOIN {$t['runs']} r ON r.id = s.run_id
			WHERE {$scope}{$since} GROUP BY s.step", ARRAY_A ) as $row ) {
			$seen[ $row['step'] ] = (int) $row['runs'];
		}

		// Only the steps that actually failed a check have their JSON read, and
		// the count that decides it is a column rather than a parse. On a site
		// with a year of runs this is the difference between reading a few rows
		// and reading every blob ever stored.
		$rows = (array) $wpdb->get_results(
			"SELECT s.step, s.checks_json FROM {$t['steps']} s INNER JOIN {$t['runs']} r ON r.id = s.run_id
			WHERE {$scope}{$since} AND s.checks_failed > 0 ORDER BY s.id DESC LIMIT 5000", ARRAY_A );

		$tally = array();
		foreach ( $rows as $row ) {
			foreach ( (array) json_decode( (string) $row['checks_json'], true ) as $label => $check ) {
				if ( ! is_array( $check ) || ! empty( $check['pass'] ) ) { continue; }
				$key = $row['step'] . '|' . $label;
				if ( ! isset( $tally[ $key ] ) ) {
					$tally[ $key ] = array( 'step' => $row['step'], 'check' => (string) $label, 'seen' => (int) ( $seen[ $row['step'] ] ?? 0 ), 'failed' => 0 );
				}
				$tally[ $key ]['failed']++;
			}
		}

		$tally = array_values( array_filter( $tally, static function ( $entry ) { return $entry['failed'] > 0 && $entry['seen'] > 0; } ) );
		usort( $tally, static function ( $a, $b ) { return ( $b['failed'] / $b['seen'] ) <=> ( $a['failed'] / $a['seen'] ); } );
		return $tally;
	}

	/** What the judge decided, across every run that reached it. */
	public static function verdicts( $days = 0 ) {
		global $wpdb;
		$t = self::tables();
		$scope = MSRWA_Rights::scope_sql( 'r.owner_id' );
		$since = $days > 0 ? $wpdb->prepare( ' AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', (int) $days ) : '';

		$rows = (array) $wpdb->get_col(
			"SELECT a.content_json FROM {$t['artifacts']} a INNER JOIN {$t['runs']} r ON r.id = a.run_id
			WHERE {$scope}{$since} AND a.artifact_key = 'approval'" );

		$out = array( 'judged' => 0, 'approved' => 0, 'findings' => 0, 'blocking' => 0, 'targets' => array() );
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
				$out['targets'][ $target ][ $value ] = (int) ( $out['targets'][ $target ][ $value ] ?? 0 ) + 1;
			}
		}
		return $out;
	}

	/**
	 * The runs a screen lists, filtered the way a person filters.
	 *
	 * Returns the rows and the total, because a list that cannot say how many
	 * there are cannot be paged.
	 */
	public static function runs( array $filters = array() ) {
		global $wpdb;
		$t = self::tables();
		$where = array( MSRWA_Rights::scope_sql( 'r.owner_id' ) );

		$status = (string) ( $filters['status'] ?? '' );
		if ( 'moving' === $status ) { $where[] = "r.status IN ('queued','running')"; }
		elseif ( 'attention' === $status ) { $where[] = "(r.status = 'failed' OR (r.status = 'done' AND r.approved = 0))"; }
		elseif ( '' !== $status ) { $where[] = $wpdb->prepare( 'r.status = %s', $status ); }

		if ( ! empty( $filters['batch'] ) ) { $where[] = $wpdb->prepare( 'r.batch_id = %d', (int) $filters['batch'] ); }
		if ( ! empty( $filters['owner'] ) && MSRWA_Rights::may_see_everything() ) { $where[] = $wpdb->prepare( 'r.owner_id = %d', (int) $filters['owner'] ); }
		if ( ! empty( $filters['search'] ) ) { $where[] = $wpdb->prepare( 'r.label LIKE %s', '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%' ); }

		$clause = implode( ' AND ', $where );
		$per_page = max( 5, min( 100, (int) ( $filters['per_page'] ?? 25 ) ) );
		$page = max( 1, (int) ( $filters['page'] ?? 1 ) );

		// Only what the reader may be shown. A writer is never billed at, so the
		// money columns are not fetched for them either: a figure that cannot
		// be displayed has no business crossing the wire, and `SELECT *` on a
		// table holding longtext is wasteful besides.
		$columns = 'r.id, r.batch_id, r.owner_id, r.label, r.status, r.step, r.steps_done, r.steps_total, r.approved, r.priority, r.draft_post_id, r.error_message, r.created_at';
		if ( MSRWA_Rights::may_see_money() ) { $columns .= ', r.cost_usd, r.seconds'; }

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT {$columns} FROM {$t['runs']} r WHERE {$clause} ORDER BY r.id DESC LIMIT %d OFFSET %d",
			$per_page, ( $page - 1 ) * $per_page ), ARRAY_A );

		return array(
			'runs' => $rows,
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['runs']} r WHERE {$clause}" ),
			'page' => $page,
			'per_page' => $per_page,
		);
	}
}
