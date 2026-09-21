<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The laboratory: whole engine runs, driven by cron, watched from the admin.
 *
 * A run takes some four and a half minutes and no PHP request survives that, so
 * the work is cut where the engine already cuts it — at the dependency wave. One
 * cron tick runs one wave and writes down everything it produced; the next tick
 * picks the run up from there. Nothing is held in memory between ticks, so a
 * request that dies costs at most the wave it was in, and the run resumes.
 *
 * A lab run produces nothing a reader will ever see. It exists to be compared
 * with other runs, which is why it is kept out of the editorial job tables
 * entirely rather than filtered out of them afterwards.
 */
final class MSRWA_Lab {

	/** How long one wave may hold a run before another worker may take it over. */
	const LEASE_MINUTES = 15;

	private static function table() {
		$tables = MSRWA_DB::tables();
		return $tables['lab_runs'];
	}

	/** Who may spend money in here. Running the lab is running the models. */
	public static function may_run() { return current_user_can( 'manage_options' ); }

	/**
	 * Records a run and asks cron to start it. Nothing is called yet: the
	 * administrator gets the page back immediately and watches it from there.
	 */
	public static function create( array $brief, array $config = array(), $label = '' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$steps = MSRWA_Engine_Steps::names( (array) ( $config['steps'] ?? array() ) );
		$wpdb->insert( self::table(), array(
			'owner_id' => get_current_user_id(),
			'label' => (string) ( '' !== $label ? $label : ( $brief['title'] ?? 'Sans titre' ) ),
			'brief_json' => wp_json_encode( $brief ),
			'config_json' => wp_json_encode( $config ),
			'result_json' => wp_json_encode( self::blank_state() ),
			'status' => 'queued', 'step' => '', 'steps_done' => 0, 'steps_total' => count( $steps ),
			'cost_usd' => 0, 'seconds' => 0, 'workspace' => '',
			'created_at' => $now, 'updated_at' => $now,
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%s', '%s', '%s' ) );
		$id = (int) $wpdb->insert_id;
		if ( $id ) { self::queue( $id, 5 ); }
		return $id;
	}

	/**
	 * What a run knows before it has run anything. The steps, calls, events and
	 * artifacts live in their own tables; this holds only what has no row.
	 */
	private static function blank_state() {
		return array( 'ok' => true, 'errors' => array(), 'totals' => array() );
	}

	public static function queue( $id, $delay = 5 ) {
		wp_schedule_single_event( time() + max( 1, (int) $delay ), 'msrwa_lab_step', array( absint( $id ) ) );
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * The runs this user may see. An administrator sees the laboratory's whole
	 * history, because comparing runs is the point of it; anyone else sees their
	 * own, under the same rule the editorial lists follow.
	 */
	public static function recent( $limit = 30 ) {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		if ( current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' ) ) {
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE owner_id = %d ORDER BY id DESC LIMIT %d', get_current_user_id(), $limit ), ARRAY_A );
	}

	public static function may_see( array $run ) {
		return (int) $run['owner_id'] === get_current_user_id() || current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' );
	}

	public static function cancel( $id ) {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'cancelled', lock_token = NULL, lock_until = NULL, updated_at = %s WHERE id = %d AND status NOT IN ('done','failed','cancelled')", current_time( 'mysql', true ), absint( $id ) ) );
	}

	/**
	 * One tick: claim the run, carry it forward by one wave, hand it back.
	 *
	 * The claim is a conditional UPDATE, so two workers arriving together cannot
	 * both spend money on the same run — the second finds nothing to claim and
	 * goes away.
	 */
	public static function tick( $id ) {
		$id = absint( $id );
		$token = wp_generate_password( 32, false, false );
		if ( ! self::claim( $id, $token ) ) { return; }

		// A wave is an HTTP call to a provider, not PHP work, but a host that
		// counts it anyway would kill the run mid-wave. Ask for the room, and
		// keep going if the browser that triggered cron has gone.
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
		ignore_user_abort( true );

		$run = self::get( $id );
		if ( ! $run ) { return; }
		try {
			self::advance( $run, $token );
		} catch ( Throwable $error ) {
			// The engine does not throw; WordPress, the database and PHP itself
			// still can. A run that died is a run that says why.
			self::finish( $id, 'failed', $error->getMessage() );
		}
	}

	private static function claim( $id, $token ) {
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET lock_token = %s, lock_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . (int) self::LEASE_MINUTES . " MINUTE), status = 'running', updated_at = %s"
			. " WHERE id = %d AND status IN ('queued','running') AND (lock_until IS NULL OR lock_until < UTC_TIMESTAMP())",
			$token, current_time( 'mysql', true ), absint( $id ) ) );
		return (bool) $updated;
	}

	/**
	 * Runs whatever may run now, then writes down what happened.
	 *
	 * The engine is asked for one wave at a time with `only`, and handed back
	 * every artifact produced so far, so it resumes exactly where the last tick
	 * stopped. The budget passed is what is left of it, not the original figure.
	 */
	private static function advance( array $run, $token ) {
		$id = (int) $run['id'];
		$brief = (array) json_decode( (string) $run['brief_json'], true );
		$config = self::configure( (array) json_decode( (string) $run['config_json'], true ) );
		$state = self::state( $id );
		$artifacts = (array) $state['artifacts'];
		$registry = (array) ( $config['steps'] ?? array() );

		$done = array();
		foreach ( (array) $state['steps'] as $step ) { $done[] = (string) $step['step']; }
		$remaining = array_values( array_diff( MSRWA_Engine_Steps::names( $registry ), $done ) );
		if ( ! $remaining ) { self::finish( $id, empty( $state['ok'] ) ? 'failed' : 'done', '' ); return; }

		$wave = MSRWA_Engine_Steps::ready( $artifacts, $remaining, $registry );
		if ( ! $wave ) { self::finish( $id, 'failed', 'En attente de : ' . implode( ', ', MSRWA_Engine_Steps::missing( $remaining[0], $artifacts, $registry ) ) . ', qui n’est jamais arrivé.' ); return; }

		$spent = (float) $run['cost_usd'];
		$budget = (float) ( $config['limits']['budget_usd'] ?? 0 );
		if ( $budget > 0 && $spent >= $budget ) { self::finish( $id, 'failed', sprintf( 'Budget de %.4f $ atteint avant %s.', $budget, implode( ', ', $wave ) ) ); return; }
		if ( $budget > 0 ) { $config['limits']['budget_usd'] = max( 0.000001, $budget - $spent ); }

		$workspace = self::workspace( $id );
		self::touch( $id, array( 'step' => implode( ', ', $wave ), 'workspace' => $workspace ) );
		$result = MSRWA_Engine::run(
			array_merge( $brief, array( 'artifacts' => $artifacts ) ),
			array( 'config' => $config, 'only' => $wave, 'workspace' => $workspace )
		);

		self::absorb( $id, $state, $result->to_array(), count( $wave ) );

		$run = self::get( $id );
		if ( ! $run || 'running' !== $run['status'] ) { return; }
		self::release( $id, $token );
		self::queue( $id, 2 );
	}

	/**
	 * Writes down everything one tick produced.
	 *
	 * The engine reports more than a run's outcome: a figure and a scorecard per
	 * step, a token count and a price per call, an event per thing that happened,
	 * and the artifacts themselves. All of it is kept, and kept in rows rather
	 * than in one blob, so the laboratory can be asked what the review costs
	 * across every run rather than only what happened in run twelve.
	 */
	private static function absorb( $id, array $state, array $tick, $wave_size ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$now = current_time( 'mysql', true );

		foreach ( (array) ( $tick['steps'] ?? array() ) as $step ) {
			$usage = (array) ( $step['usage'] ?? array() );
			$wpdb->insert( $t['lab_steps'], array(
				'run_id' => absint( $id ), 'step' => (string) $step['step'],
				'provider' => (string) $step['provider'], 'model' => (string) $step['model'],
				'seconds' => (float) $step['seconds'], 'attempts' => (int) $step['attempts'],
				'input_tokens' => (int) ( $usage['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
				// A model with no published rate costs an unknown amount, not nothing:
				// null is the honest column value and the totals count it separately.
				'cost_usd' => null === $step['cost_usd'] ? null : (float) $step['cost_usd'],
				'bucket' => MSRWA_Engine_Steps::bucket( (string) $step['step'] ),
				'status' => (string) $step['status'],
				'passed' => null === $step['passed'] ? null : (int) $step['passed'],
				'total' => null === $step['total'] ? null : (int) $step['total'],
				'checks_json' => wp_json_encode( MSRWA_DB::sanitize_persisted_data( (array) $step['checks'] ) ),
				'error_message' => (string) $step['error'], 'created_at' => $now,
			) );
		}

		// Each tick is one wave of a longer run, so its own opening and closing
		// lines would read as a run starting and finishing over and over.
		foreach ( (array) ( $tick['events'] ?? array() ) as $event ) {
			if ( in_array( (string) $event['kind'], array( 'start', 'config', 'finish' ), true ) ) { continue; }
			$data = MSRWA_DB::sanitize_persisted_data( (array) ( $event['data'] ?? array() ) );
			$wpdb->insert( $t['lab_events'], array(
				'run_id' => absint( $id ), 'at_seconds' => (float) $event['at'], 'kind' => (string) $event['kind'],
				'step' => (string) $event['step'], 'message' => (string) $event['message'],
				'data_json' => wp_json_encode( $data ), 'created_at' => $now,
			) );
			if ( 'call' === (string) $event['kind'] ) { self::record_call( $id, (string) $event['step'], $data, $now ); }
		}

		foreach ( (array) ( $tick['artifacts'] ?? array() ) as $key => $value ) {
			self::record_artifact( $id, (string) $key, $value, $now );
		}

		$state['ok'] = ! empty( $state['ok'] ) && ! empty( $tick['ok'] );
		$state['errors'] = array_merge( (array) ( $state['errors'] ?? array() ), (array) ( $tick['errors'] ?? array() ) );
		$totals = self::totals( self::steps( $id ) );

		$wpdb->update( self::table(), array(
			'result_json' => wp_json_encode( array( 'ok' => (bool) $state['ok'], 'errors' => MSRWA_DB::sanitize_persisted_data( $state['errors'] ), 'totals' => $totals ) ),
			'steps_done' => (int) $totals['steps'],
			'cost_usd' => (float) $totals['cost_usd'],
			'seconds' => (float) $totals['seconds'],
			'updated_at' => $now,
		), array( 'id' => absint( $id ) ), array( '%s', '%d', '%f', '%f', '%s' ), array( '%d' ) );
	}

	/** One provider call, as the engine reported it: who answered, how much of it was cached, what it cost. */
	private static function record_call( $id, $step, array $data, $now ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$usage = (array) ( $data['usage'] ?? array() );
		$wpdb->insert( $t['lab_calls'], array(
			'run_id' => absint( $id ), 'step' => $step,
			'provider' => (string) ( $data['provider'] ?? '' ), 'model' => (string) ( $data['model'] ?? '' ),
			'tier' => (string) ( $data['tier'] ?? '' ), 'endpoint' => (string) ( $data['endpoint'] ?? '' ),
			'seconds' => (float) ( $data['seconds'] ?? 0 ),
			'input_tokens' => (int) ( $usage['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
			'cached_tokens' => (int) ( $usage['cached_input_tokens'] ?? 0 ),
			'cost_usd' => isset( $data['cost_usd'] ) && null !== $data['cost_usd'] ? (float) $data['cost_usd'] : null,
			'priced' => empty( $data['priced'] ) ? 0 : 1,
			'status' => (string) ( $data['status'] ?? '' ), 'created_at' => $now,
		) );
	}

	/**
	 * An artifact, under its own name, replacing any earlier version of itself.
	 *
	 * A step may produce the same artifact twice — an image redrawn after a
	 * refusal is the same featured image, not a second one — and what the run
	 * ends with is what the reader would have got.
	 */
	private static function record_artifact( $id, $key, $value, $now ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$json = (string) wp_json_encode( MSRWA_DB::sanitize_persisted_data( $value ) );
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $t['lab_artifacts'] . ' (run_id, artifact_key, content_json, bytes, created_at, updated_at) VALUES (%d, %s, %s, %d, %s, %s)'
			. ' ON DUPLICATE KEY UPDATE content_json = VALUES(content_json), bytes = VALUES(bytes), updated_at = VALUES(updated_at)',
			absint( $id ), $key, $json, strlen( $json ), $now, $now ) );
	}

	/** The steps a run has run, oldest first. */
	public static function steps( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $t['lab_steps'] . ' WHERE run_id = %d ORDER BY id ASC', absint( $id ) ), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'step' => $row['step'], 'model' => $row['model'], 'provider' => $row['provider'],
				'seconds' => (float) $row['seconds'], 'attempts' => (int) $row['attempts'],
				'usage' => array( 'input_tokens' => (int) $row['input_tokens'], 'output_tokens' => (int) $row['output_tokens'] ),
				'cost_usd' => null === $row['cost_usd'] ? null : (float) $row['cost_usd'],
				'status' => $row['status'],
				'passed' => null === $row['passed'] ? null : (int) $row['passed'],
				'total' => null === $row['total'] ? null : (int) $row['total'],
				'checks' => (array) json_decode( (string) $row['checks_json'], true ),
				'error' => (string) $row['error_message'],
			);
		}
		return $out;
	}

	public static function calls( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $t['lab_calls'] . ' WHERE run_id = %d ORDER BY id ASC', absint( $id ) ), ARRAY_A );
	}

	public static function events( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $t['lab_events'] . ' WHERE run_id = %d ORDER BY id ASC', absint( $id ) ), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array( 'at' => (float) $row['at_seconds'], 'kind' => $row['kind'], 'step' => $row['step'], 'message' => $row['message'], 'data' => (array) json_decode( (string) $row['data_json'], true ) );
		}
		return $out;
	}

	public static function artifacts( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT artifact_key, content_json FROM ' . $t['lab_artifacts'] . ' WHERE run_id = %d ORDER BY id ASC', absint( $id ) ), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) { $out[ $row['artifact_key'] ] = json_decode( (string) $row['content_json'], true ); }
		return $out;
	}

	/**
	 * The whole run in the shape the engine returns and the report renders.
	 *
	 * Assembled from the rows rather than kept as a second copy: the tables are
	 * the record, and this is the view of it the rest of the code already knows
	 * how to read.
	 */
	public static function state( $id ) {
		$run = self::get( $id );
		$stored = $run ? (array) json_decode( (string) $run['result_json'], true ) : array();
		$steps = self::steps( $id );
		return array(
			'ok' => ! isset( $stored['ok'] ) || (bool) $stored['ok'],
			'totals' => self::totals( $steps ),
			'steps' => $steps,
			'artifacts' => self::artifacts( $id ),
			'errors' => (array) ( $stored['errors'] ?? array() ),
			'events' => self::events( $id ),
		);
	}

	/** Deletes a run and everything the engine reported about it. */
	public static function delete( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		foreach ( array( 'lab_steps', 'lab_calls', 'lab_events', 'lab_artifacts' ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t[ $table ] . ' WHERE run_id = %d', absint( $id ) ) );
		}
		return (bool) $wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	/** The run's totals, summed from its own steps rather than from any one tick. */
	private static function totals( array $steps ) {
		$result = new MSRWA_Result();
		foreach ( $steps as $step ) { $result->steps[] = $step; }
		return $result->totals();
	}

	private static function touch( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $fields, array( 'id' => absint( $id ) ) );
	}

	private static function release( $id, $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET lock_token = NULL, lock_until = NULL WHERE id = %d AND lock_token = %s', absint( $id ), $token ) );
	}

	private static function finish( $id, $status, $message ) {
		global $wpdb;
		$wpdb->update( self::table(), array(
			'status' => $status, 'step' => '', 'error_message' => $message,
			'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ),
		), array( 'id' => absint( $id ) ), array( '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
	}

	/**
	 * Puts a run that lost its worker back in the queue.
	 *
	 * A lease expires when a request was killed mid-wave. The wave it was in is
	 * paid for and lost, but everything before it was written down, so the run
	 * resumes from the last step that finished rather than from the beginning.
	 */
	public static function recover_expired() {
		global $wpdb;
		$stuck = (array) $wpdb->get_col( 'SELECT id FROM ' . self::table() . " WHERE status = 'running' AND lock_until IS NOT NULL AND lock_until < UTC_TIMESTAMP() LIMIT 20" );
		foreach ( $stuck as $id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'queued', lock_token = NULL, lock_until = NULL, updated_at = %s WHERE id = %d", current_time( 'mysql', true ), absint( $id ) ) );
			self::queue( (int) $id, 5 );
		}
	}

	/**
	 * The engine configuration a run starts from: whatever the Moteur screen has
	 * overridden, then the keys, then whatever the run itself asked for.
	 *
	 * The API keys are handed to the engine directly under `settings`, the one
	 * branch of the configuration that never reaches a stored record. WordPress
	 * keeps them encrypted in its own table and has no environment to export
	 * them into.
	 */
	public static function configure( array $overrides = array() ) {
		$settings = class_exists( 'MSRWA_Settings' ) ? (array) MSRWA_Settings::get() : array();
		$keys = array();
		foreach ( array( 'openai' => 'openai_key', 'gemini' => 'gemini_key', 'anthropic' => 'claude_key' ) as $provider => $field ) {
			$value = (string) ( $settings[ $field ] ?? '' );
			if ( '' !== trim( $value ) ) { $keys[ $provider ] = trim( $value ); }
		}
		$base = class_exists( 'MSRWA_Lab_Config' ) ? MSRWA_Lab_Config::stored() : array();
		if ( $keys ) { $base['settings'] = array_merge( (array) ( $base['settings'] ?? array() ), array( 'keys' => $keys ) ); }
		return self::merge( $base, $overrides );
	}

	/** Deep merge, so a run may override one limit without restating the rest. */
	private static function merge( array $base, array $over ) {
		foreach ( $over as $key => $value ) {
			$base[ $key ] = is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ? self::merge( $base[ $key ], $value ) : $value;
		}
		return $base;
	}

	/** Where a run's generated images are written, under the uploads directory. */
	public static function workspace( $id ) {
		$uploads = wp_upload_dir();
		$path = trailingslashit( $uploads['basedir'] ) . 'msrwa-lab/' . absint( $id );
		if ( ! is_dir( $path ) ) { wp_mkdir_p( $path ); }
		return $path;
	}
}
