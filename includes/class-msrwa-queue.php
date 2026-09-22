<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The queue, as something an operator can hold.
 *
 * Two controls, both of which exist because of the same moment: something is
 * wrong, several recipes are running, and they are spending money while you
 * work out what.
 *
 * A hold stops new waves without losing anything. Runs that were moving go back
 * to waiting, keep every step they finished, and resume exactly where they were
 * when the hold lifts — the engine already works that way, so a hold costs the
 * wave in flight and nothing else.
 *
 * Priority decides who goes first when the queue is longer than one tick can
 * carry. It is a small number on the run, not a separate queue, because two
 * queues drift.
 */
final class MSRWA_Queue {

	const HELD = 'msrwa_queue_held';

	public static function held() { return (bool) get_option( self::HELD ); }

	/**
	 * Stops new waves. Anything mid-wave finishes: killing a provider call in
	 * flight pays for it and keeps nothing.
	 */
	public static function hold() {
		update_option( self::HELD, 1, false );
		return true;
	}

	public static function release() {
		global $wpdb;
		update_option( self::HELD, 0, false );

		// Everything waiting is re-armed at once: cron fires one event per run,
		// and a run with no event scheduled waits forever however healthy it is.
		$t = MSRWA_DB::tables();
		$ids = (array) $wpdb->get_col( "SELECT id FROM {$t['runs']} WHERE status = 'queued' ORDER BY priority DESC, id ASC LIMIT 200" );
		foreach ( $ids as $id ) { MSRWA_Run::queue( (int) $id, 5 ); }
		return count( $ids );
	}

	/**
	 * How much of the queue is waiting, and for how long.
	 *
	 * The oldest waiting run is the number that matters: if it has been waiting
	 * twenty minutes, cron is not running, whatever everything else says.
	 */
	public static function state() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$row = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) waiting,
				SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) working,
				MIN(CASE WHEN status = 'queued' THEN updated_at END) oldest,
				SUM(CASE WHEN status = 'running' AND lock_until < UTC_TIMESTAMP() THEN 1 ELSE 0 END) expired
			FROM {$t['runs']}", ARRAY_A );

		$oldest = (string) ( $row['oldest'] ?? '' );
		return array(
			'held' => self::held(),
			'waiting' => (int) ( $row['waiting'] ?? 0 ),
			'working' => (int) ( $row['working'] ?? 0 ),
			'expired' => (int) ( $row['expired'] ?? 0 ),
			'oldest' => $oldest,
			'waiting_seconds' => $oldest ? max( 0, time() - (int) strtotime( $oldest . ' UTC' ) ) : 0,
		);
	}

	/**
	 * Whether the queue looks stuck.
	 *
	 * Not a guess: work is waiting, nothing is working, and the oldest has been
	 * waiting longer than several cron ticks. On a site nobody visits that is
	 * always cron, and saying so is more use than another green tick.
	 */
	public static function stalled() {
		$state = self::state();
		if ( $state['held'] || ! $state['waiting'] ) { return false; }
		return $state['waiting_seconds'] > 1200;
	}

	public static function prioritise( $run_id, $priority ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$priority = max( -10, min( 10, (int) $priority ) );
		$wpdb->update( $t['runs'], array( 'priority' => $priority ), array( 'id' => absint( $run_id ) ), array( '%d' ), array( '%d' ) );
		return $priority;
	}
}
