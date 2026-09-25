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

		// "To read" and "to fix" are about drafts: once an editor has published,
		// scheduled, binned or deleted the post, the decision has been made.
		$open = "p.post_status IN ('draft','pending')";
		$row = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN r.status IN ('queued','running') THEN 1 ELSE 0 END) moving,
				SUM(CASE WHEN r.status = 'done' AND {$open} THEN 1 ELSE 0 END) to_read,
				SUM(CASE WHEN r.status = 'done' AND r.approved = 0 AND {$open} THEN 1 ELSE 0 END) reserved,
				SUM(CASE WHEN r.status = 'failed' THEN 1 ELSE 0 END) failed,
				COUNT(*) total
			FROM {$t['runs']} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.draft_post_id WHERE {$scope}", ARRAY_A );

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

		// The average is a finished recipe's: a queued one has cost nothing yet
		// and pulled it down.
		$row = $wpdb->get_row(
			"SELECT COUNT(*) runs, AVG(CASE WHEN r.status IN ('done','failed') THEN r.cost_usd END) average,
				AVG(CASE WHEN r.status IN ('done','failed') THEN r.seconds END) seconds
			FROM {$t['runs']} r WHERE {$scope}{$since}", ARRAY_A );
		// What was spent is read from the spending lines, which count a lot's
		// pairing and outlive a deleted lot; the runs only know their recipes.
		$spent = MSRWA_Spend::window( $days, MSRWA_Rights::scope_sql( 'x.owner_id' ) );

		$unpriced = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$t['steps']} s INNER JOIN {$t['runs']} r ON r.id = s.run_id
			WHERE {$scope}{$since} AND s.cost_usd IS NULL AND (s.input_tokens > 0 OR s.output_tokens > 0)" );

		return array(
			'spend_usd' => round( $spent['spend_usd'], 4 ),
			'matching_usd' => round( $spent['matching_usd'], 4 ),
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
			"SELECT COUNT(*) runs, AVG(CASE WHEN r.status IN ('done','failed') THEN r.cost_usd END) average,
				AVG(CASE WHEN r.status IN ('done','failed') THEN r.seconds END) seconds
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
		$window = max( 1, (int) $days );
		// Money by the day it was spent, from the spending lines (pairings and
		// redraws included); recipes by the day they were created.
		$spend = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(x.created_at) day, SUM(x.cost_usd) spend FROM {$t['spend']} x
			WHERE " . MSRWA_Rights::scope_sql( 'x.owner_id' ) . " AND x.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			GROUP BY DATE(x.created_at)", $window ), ARRAY_A );
		$runs = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(r.created_at) day, COUNT(*) runs FROM {$t['runs']} r
			WHERE " . MSRWA_Rights::scope_sql( 'r.owner_id' ) . " AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			GROUP BY DATE(r.created_at)", $window ), ARRAY_A );
		$days_seen = array();
		foreach ( $spend as $row ) { if ( empty( $row['day'] ) ) { continue; } $days_seen[ (string) $row['day'] ] = array( 'day' => (string) $row['day'], 'runs' => 0, 'spend' => (float) $row['spend'] ); }
		foreach ( $runs as $row ) {
			if ( empty( $row['day'] ) ) { continue; }
			$day = (string) $row['day'];
			$days_seen[ $day ] = array( 'day' => $day, 'runs' => (int) $row['runs'], 'spend' => (float) ( $days_seen[ $day ]['spend'] ?? 0 ) );
		}
		ksort( $days_seen );
		return array_values( $days_seen );
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

		// Once a draft exists the verdict lives in its post meta and this
		// plugin's copy is released, so reading the artifact alone counted one
		// judged run out of twenty-six. The decision itself is on the run row,
		// which is never released; the findings come from wherever the verdict is.
		$rows = (array) $wpdb->get_results(
			"SELECT r.approved, COALESCE(a.content_json, pm.meta_value) AS verdict FROM {$t['runs']} r
			LEFT JOIN {$t['artifacts']} a ON a.run_id = r.id AND a.artifact_key = 'approval'
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = r.draft_post_id AND r.draft_post_id > 0 AND pm.meta_key = '_msrwa_judge_report'
			WHERE {$scope}{$since} AND r.approved IS NOT NULL", ARRAY_A );

		$out = array( 'judged' => 0, 'approved' => 0, 'findings' => 0, 'blocking' => 0, 'targets' => array() );
		foreach ( $rows as $row ) {
			$out['judged']++;
			if ( (int) $row['approved'] ) { $out['approved']++; }
			$verdict = json_decode( (string) $row['verdict'], true );
			if ( ! is_array( $verdict ) ) { continue; }
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
	/**
	 * What became of a recipe's article in WordPress, as one word the screen
	 * filters on: its post may be a draft, scheduled, published, in the bin,
	 * deleted outright, or not written yet.
	 */
	public static function post_bucket_sql() {
		return "CASE WHEN r.draft_post_id = 0 THEN 'none' WHEN p.ID IS NULL THEN 'deleted'"
			. " WHEN p.post_status IN ('publish','private') THEN 'publish' WHEN p.post_status = 'future' THEN 'future'"
			. " WHEN p.post_status = 'trash' THEN 'trash' ELSE 'draft' END";
	}

	public static function post_buckets() { return array( 'draft', 'future', 'publish', 'trash', 'deleted', 'none' ); }

	/** The WHERE clause shared by the list and its counts: scope first, then the filters. */
	private static function run_clause( array $filters, $with_post = true ) {
		global $wpdb;
		$where = array( MSRWA_Rights::scope_sql( 'r.owner_id' ) );

		$status = (string) ( $filters['status'] ?? '' );
		if ( 'moving' === $status ) { $where[] = "r.status IN ('queued','running')"; }
		// A refused article stops asking for a decision once its post is
		// published, scheduled, binned or deleted: somebody decided.
		elseif ( 'attention' === $status ) { $where[] = "(r.status = 'failed' OR (r.status = 'done' AND r.approved = 0 AND (r.draft_post_id = 0 OR p.post_status IN ('draft','pending'))))"; }
		elseif ( '' !== $status ) { $where[] = $wpdb->prepare( 'r.status = %s', $status ); }

		if ( ! empty( $filters['batch'] ) ) { $where[] = $wpdb->prepare( 'r.batch_id = %d', (int) $filters['batch'] ); }
		if ( ! empty( $filters['owner'] ) && MSRWA_Rights::may_see_everything() ) { $where[] = $wpdb->prepare( 'r.owner_id = %d', (int) $filters['owner'] ); }
		// An editor renames the post; the search finds it by either name.
		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[] = $wpdb->prepare( '(r.label LIKE %s OR p.post_title LIKE %s)', $like, $like );
		}
		$post = (string) ( $filters['post'] ?? '' );
		if ( $with_post && in_array( $post, self::post_buckets(), true ) ) { $where[] = $wpdb->prepare( self::post_bucket_sql() . ' = %s', $post ); }
		return implode( ' AND ', $where );
	}

	/** How many of the recipes the reader may see are in each post state, for the tabs. */
	public static function post_counts( array $filters = array() ) {
		global $wpdb;
		$t = self::tables();
		$clause = self::run_clause( $filters, false );
		$counts = array_fill_keys( self::post_buckets(), 0 );
		foreach ( (array) $wpdb->get_results( "SELECT " . self::post_bucket_sql() . " AS bucket, COUNT(*) AS n FROM {$t['runs']} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.draft_post_id WHERE {$clause} GROUP BY bucket", ARRAY_A ) as $row ) {
			if ( isset( $counts[ $row['bucket'] ] ) ) { $counts[ $row['bucket'] ] = (int) $row['n']; }
		}
		return $counts;
	}

	public static function runs( array $filters = array() ) {
		global $wpdb;
		$t = self::tables();
		$clause = self::run_clause( $filters );
		$per_page = max( 5, min( 100, (int) ( $filters['per_page'] ?? 25 ) ) );
		$page = max( 1, (int) ( $filters['page'] ?? 1 ) );

		// Only what the reader may be shown. A writer is never billed at, so the
		// money columns are not fetched for them either: a figure that cannot
		// be displayed has no business crossing the wire, and `SELECT *` on a
		// table holding longtext is wasteful besides.
		$columns = 'r.id, r.batch_id, r.owner_id, r.label, r.status, r.step, r.steps_done, r.steps_total, r.approved, r.priority, r.draft_post_id, r.error_message, r.created_at, '
			. 'p.post_status, p.post_title, p.post_modified_gmt, p.post_date_gmt, ' . self::post_bucket_sql() . ' AS post_bucket';
		if ( MSRWA_Rights::may_see_money() ) { $columns .= ', r.cost_usd, r.seconds'; }

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT {$columns} FROM {$t['runs']} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.draft_post_id WHERE {$clause} ORDER BY r.id DESC LIMIT %d OFFSET %d",
			$per_page, ( $page - 1 ) * $per_page ), ARRAY_A );

		return array(
			'runs' => $rows,
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['runs']} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.draft_post_id WHERE {$clause}" ),
			'page' => $page,
			'per_page' => $per_page,
		);
	}
}
