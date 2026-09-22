<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * How long each kind of record is worth keeping.
 *
 * Three ages, because three kinds of thing decay at different rates.
 *
 * The timeline is narration: nobody reads the minute-by-minute of a run from
 * last spring, and it is the fastest-growing table there is. The heavy
 * artifacts — the research package, the article as the machine wrote it, the
 * image prompts — are worth months, because they are what a question about
 * quality is answered from. The figures are worth keeping indefinitely: a row
 * in `steps` is a few dozen bytes and is the only record of what a run cost.
 *
 * Every age is a filter, so a site that wants to keep everything sets them to
 * zero and nothing is ever removed.
 */
final class MSRWA_Retention {

	/** Artifacts small enough that keeping them forever costs nothing worth counting. */
	private static function light() {
		return array( 'brief', 'review', 'fact_check', 'approval', 'canonical' );
	}

	public static function policy() {
		return array(
			'events' => (int) apply_filters( 'msrwa_retention_events_days', 90 ),
			'artifacts' => (int) apply_filters( 'msrwa_retention_artifacts_days', 365 ),
			'runs' => (int) apply_filters( 'msrwa_retention_runs_days', 0 ),
		);
	}

	/**
	 * One pass, bounded, over each kind.
	 *
	 * Bounded because this runs on a cron tick on somebody's live site: a pass
	 * that tried to clear a year of backlog at once would be the request that
	 * gets killed, every time, forever. Whatever is left is taken next tick.
	 */
	public static function sweep() {
		$policy = self::policy();
		return array(
			'events' => self::events( $policy['events'] ),
			'artifacts' => self::artifacts( $policy['artifacts'] ),
			'runs' => self::runs( $policy['runs'] ),
		);
	}

	/**
	 * The narration of runs that have long since settled.
	 *
	 * Two statements rather than one join. A multi-table DELETE cannot carry a
	 * LIMIT — MySQL forbids it outright and SQLite has no such syntax at all —
	 * so the rows are chosen first and removed by their own ids. The earlier
	 * one-statement version parsed fine against a test double and deleted
	 * nothing whatsoever on a real database.
	 */
	public static function events( $days, $limit = 2000 ) {
		global $wpdb;
		if ( $days <= 0 ) { return 0; }
		$t = MSRWA_DB::tables();

		$ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT e.id FROM {$t['events']} e INNER JOIN {$t['runs']} r ON r.id = e.run_id
			WHERE r.status NOT IN ('queued','running')
				AND r.updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			ORDER BY e.id ASC LIMIT %d", max( 1, (int) $days ), max( 100, (int) $limit ) ) );

		return self::remove( $t['events'], $ids );
	}

	/**
	 * The heavy artifacts of old runs; the small ones stay.
	 *
	 * What goes is measured in tens of kilobytes each — the research package,
	 * the article, the image prompts. What stays is the verdict, the review and
	 * the recipe, which are what somebody looking at an old article actually
	 * wants, and which together weigh less than a photograph.
	 */
	public static function artifacts( $days, $limit = 500 ) {
		global $wpdb;
		if ( $days <= 0 ) { return 0; }
		$t = MSRWA_DB::tables();
		$light = self::light();
		$placeholders = implode( ',', array_fill( 0, count( $light ), '%s' ) );

		$ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT a.id FROM {$t['artifacts']} a INNER JOIN {$t['runs']} r ON r.id = a.run_id
			WHERE r.status NOT IN ('queued','running')
				AND r.updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				AND a.artifact_key NOT IN ({$placeholders})
			ORDER BY a.id ASC LIMIT %d",
			array_merge( array( max( 1, (int) $days ) ), $light, array( max( 50, (int) $limit ) ) ) ) );

		return self::remove( $t['artifacts'], $ids );
	}

	/** Removes rows by their own ids, which every database agrees on. */
	private static function remove( $table, array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) { return 0; }
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $table . ' WHERE id IN (' . $placeholders . ')', $ids ) );
	}

	/**
	 * Whole runs, and only if a site asks for it.
	 *
	 * Off by default: a `steps` row is the only record of what something cost,
	 * and a plugin that quietly forgets a year of spending is worse than one
	 * that uses a few megabytes. A run that produced a draft still standing is
	 * never removed, whatever its age — the draft points back at it.
	 */
	public static function runs( $days, $limit = 100 ) {
		global $wpdb;
		if ( $days <= 0 ) { return 0; }
		$t = MSRWA_DB::tables();

		$ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$t['runs']}
			WHERE status NOT IN ('queued','running')
				AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				AND draft_post_id = 0
			ORDER BY id ASC LIMIT %d", max( 1, (int) $days ), max( 10, (int) $limit ) ) );

		$removed = 0;
		foreach ( $ids as $id ) {
			foreach ( array( 'steps', 'calls', 'events', 'artifacts' ) as $table ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$t[ $table ]} WHERE run_id = %d", (int) $id ) );
			}
			$removed += (int) $wpdb->delete( $t['runs'], array( 'id' => (int) $id ), array( '%d' ) );
		}
		return $removed;
	}

	/** What the sweep currently holds, for a screen that has to explain itself. */
	public static function weight() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$out = array();
		foreach ( array( 'runs', 'steps', 'calls', 'events', 'artifacts' ) as $table ) {
			$out[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t[ $table ]}" );
		}
		$out['artifact_bytes'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(bytes),0) FROM {$t['artifacts']}" );
		return $out;
	}
}
