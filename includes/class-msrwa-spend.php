<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the site has spent, one line per amount, on the day it was spent.
 *
 * The runs table says what each recipe cost, which is what a recipe's screen
 * needs. A ceiling needs something else: every amount paid, whatever became
 * of the work. Summed from the runs, the pairing of a lot's photographs was
 * never counted, a redraw counted on the day its recipe was created, and a
 * deleted lot took its money off the day's total. These lines are kept when
 * lots and recipes are deleted, and retention removes them only after a year.
 */
final class MSRWA_Spend {

	/** Lines older than this are no longer read by any ceiling or screen. */
	const KEEP_DAYS = 400;

	/** Records what one wave of a recipe spent: a line per priced step. */
	public static function steps( array $run, array $steps, $now ) {
		$rows = array();
		foreach ( $steps as $step ) {
			if ( ! isset( $step['cost_usd'] ) || null === $step['cost_usd'] || (float) $step['cost_usd'] <= 0 ) { continue; }
			$rows[] = array(
				'owner_id' => (int) ( $run['owner_id'] ?? 0 ), 'batch_id' => (int) ( $run['batch_id'] ?? 0 ), 'run_id' => (int) ( $run['id'] ?? 0 ),
				'step' => (string) $step['step'], 'cost_usd' => (float) $step['cost_usd'], 'created_at' => (string) $now,
			);
		}
		if ( $rows ) { MSRWA_DB::insert_many( MSRWA_DB::tables()['spend'], $rows ); }
	}

	/** Records what reading and pairing a lot's photographs cost. */
	public static function matching( $owner_id, $batch_id, $cost ) {
		if ( (float) $cost <= 0 ) { return; }
		MSRWA_DB::insert_many( MSRWA_DB::tables()['spend'], array( array(
			'owner_id' => (int) $owner_id, 'batch_id' => (int) $batch_id, 'run_id' => 0,
			'step' => 'matching', 'cost_usd' => (float) $cost, 'created_at' => current_time( 'mysql', true ),
		) ) );
	}

	/**
	 * What was spent over the last $days, the whole site's or, with a scope,
	 * only what the reader may see. Returns the total and the pairing's share.
	 */
	public static function window( $days, $scope = '' ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$where = array( '1=1' );
		if ( $days > 0 ) { $where[] = $wpdb->prepare( 'x.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', (int) $days ); }
		if ( '' !== $scope ) { $where[] = $scope; }
		$row = $wpdb->get_row(
			"SELECT COALESCE(SUM(x.cost_usd), 0) spend, COALESCE(SUM(CASE WHEN x.step = 'matching' THEN x.cost_usd END), 0) matching
			FROM {$t['spend']} x WHERE " . implode( ' AND ', $where ), ARRAY_A );
		return array( 'spend_usd' => round( (float) ( $row['spend'] ?? 0 ), 6 ), 'matching_usd' => round( (float) ( $row['matching'] ?? 0 ), 6 ) );
	}

	/** What one lot has spent so far: its recipes, and the reading of its photographs. */
	public static function for_batch( $batch_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		return round( (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cost_usd), 0) FROM {$t['spend']} WHERE batch_id = %d", absint( $batch_id ) ) ), 6 );
	}

	/**
	 * Fills the table once, from what the site already recorded: every priced
	 * step, on the day it was recorded, and every lot's pairing. Runs only
	 * while the table is empty, so an update never counts anything twice.
	 */
	public static function backfill() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( ! MSRWA_DB::table_exists( $t['spend'] ) || (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['spend']}" ) > 0 ) { return; }
		if ( MSRWA_DB::table_exists( $t['steps'] ) && MSRWA_DB::table_exists( $t['runs'] ) ) {
			$wpdb->query( "INSERT INTO {$t['spend']} (owner_id, batch_id, run_id, step, cost_usd, created_at)
				SELECT r.owner_id, r.batch_id, s.run_id, s.step, s.cost_usd, s.created_at
				FROM {$t['steps']} s INNER JOIN {$t['runs']} r ON r.id = s.run_id WHERE s.cost_usd > 0" );
		}
		if ( ! MSRWA_DB::table_exists( $t['batches'] ) ) { return; }
		$last = 0;
		for ( $guard = 0; $guard < 100; $guard++ ) {
			$lots = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, owner_id, matching_json, created_at FROM {$t['batches']} WHERE id > %d ORDER BY id ASC LIMIT 200", $last ), ARRAY_A );
			if ( ! $lots ) { break; }
			$rows = array();
			foreach ( $lots as $lot ) {
				$last = (int) $lot['id'];
				$cost = (float) ( ( (array) json_decode( (string) $lot['matching_json'], true ) )['cost_usd'] ?? 0 );
				if ( $cost > 0 ) { $rows[] = array( 'owner_id' => (int) $lot['owner_id'], 'batch_id' => $last, 'run_id' => 0, 'step' => 'matching', 'cost_usd' => $cost, 'created_at' => (string) $lot['created_at'] ); }
			}
			if ( $rows ) { MSRWA_DB::insert_many( $t['spend'], $rows ); }
		}
	}

	/** Lines past a year and a month, which nothing reads any more. */
	public static function prune() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t['spend']} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT 5000", self::KEEP_DAYS ) );
	}
}
