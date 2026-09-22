<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One recipe going through the engine, and everything the engine said about it.
 *
 * A whole run takes some four and a half minutes and no PHP request survives
 * that, so the work is cut where the engine already cuts it — at the dependency
 * wave. One cron tick runs the waves that are ready, writes down what they
 * produced, and schedules the next tick. Nothing is held between ticks: a
 * request the host kills costs at most the wave it was in, and the run resumes
 * from the last step that finished.
 *
 * Every figure the engine reports is kept, in rows rather than one blob. A blob
 * answers what happened in run twelve; rows answer what the review costs across
 * every run, which is the question worth asking.
 */
final class MSRWA_Run {

	/** How long one wave may hold a run before another worker may take it over. */
	const LEASE_MINUTES = 15;

	private static function table() {
		$t = MSRWA_DB::tables();
		return $t['runs'];
	}

	public static function create( $batch_id, $owner_id, array $brief, array $config, array $steps = array() ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( self::table(), array(
			'batch_id' => absint( $batch_id ), 'owner_id' => absint( $owner_id ),
			'label' => mb_substr( (string) ( $brief['title'] ?? 'Sans titre' ), 0, 190 ),
			'brief_json' => wp_json_encode( $brief ),
			'result_json' => wp_json_encode( array( 'ok' => true, 'errors' => array() ) ),
			'status' => 'queued', 'step' => '', 'steps_done' => 0,
			'steps_total' => count( $steps ? $steps : MSRWA_Engine_Steps::names( (array) ( $config['steps'] ?? array() ) ) ),
			'cost_usd' => 0, 'seconds' => 0, 'workspace' => '',
			'created_at' => $now, 'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		if ( $id ) { self::queue( $id, 5 ); }
		return $id;
	}

	public static function queue( $id, $delay = 5 ) {
		if ( wp_next_scheduled( 'msrwa_run_step', array( absint( $id ) ) ) ) { return; }
		wp_schedule_single_event( time() + max( 1, (int) $delay ), 'msrwa_run_step', array( absint( $id ) ) );
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ), ARRAY_A );
		return $row ? $row : null;
	}

	public static function for_batch( $batch_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE batch_id = %d ORDER BY id ASC', absint( $batch_id ) ), ARRAY_A );
	}

	public static function may_see( array $run ) {
		return (int) $run['owner_id'] === get_current_user_id() || current_user_can( 'manage_options' );
	}

	public static function cancel( $id ) {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'cancelled', lock_token = NULL, lock_until = NULL, updated_at = %s WHERE id = %d AND status NOT IN ('done','failed','cancelled')", current_time( 'mysql', true ), absint( $id ) ) );
	}

	/**
	 * One tick: claim the run, carry it forward, hand it back.
	 *
	 * The claim is a conditional UPDATE, so two workers arriving together cannot
	 * both spend money on the same run — the second finds nothing to claim.
	 */
	public static function tick( $id ) {
		$id = absint( $id );
		$token = wp_generate_password( 32, false, false );
		if ( ! self::claim( $id, $token ) ) { return; }

		// A wave is time on the wire, not PHP work, but a host that counts it
		// anyway would kill the run mid-wave. Ask for the room, and keep going
		// if whatever triggered cron has gone.
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
		ignore_user_abort( true );

		$run = self::get( $id );
		if ( ! $run ) { return; }
		try {
			self::advance( $run, $token );
		} catch ( Throwable $error ) {
			// The engine does not throw; WordPress, the database and PHP still can.
			self::finish( $id, 'failed', $error->getMessage() );
		}
	}

	private static function claim( $id, $token ) {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET lock_token = %s, lock_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . (int) self::LEASE_MINUTES . " MINUTE), status = 'running', updated_at = %s"
			. " WHERE id = %d AND status IN ('queued','running') AND (lock_until IS NULL OR lock_until < UTC_TIMESTAMP())",
			$token, current_time( 'mysql', true ), absint( $id ) ) );
	}

	/**
	 * Runs whatever may run now, then writes down what happened.
	 *
	 * The engine is asked for one wave with `only`, and handed every artifact
	 * produced so far, so it resumes where the last tick stopped. The budget
	 * passed is what is left of it, not the figure the batch started with.
	 */
	private static function advance( array $run, $token ) {
		$id = (int) $run['id'];
		$brief = (array) json_decode( (string) $run['brief_json'], true );
		$config = MSRWA_Batch::config_for( (int) $run['batch_id'] );
		$state = self::state( $id );
		$artifacts = (array) $state['artifacts'];
		$registry = (array) ( $config['steps'] ?? array() );

		$done = array();
		foreach ( (array) $state['steps'] as $step ) { $done[] = (string) $step['step']; }

		// The batch's profile decides which steps this run has: an article-only
		// batch must not sit waiting for an image it never asked anyone to draw.
		$batch = MSRWA_Batch::get( (int) $run['batch_id'] );
		$wanted = MSRWA_Profile::steps( $batch ? $batch['profile'] : MSRWA_Profile::FULL, $registry );
		$remaining = array_values( array_diff( $wanted, $done ) );
		if ( ! $remaining ) { self::complete( $id, $state ); return; }

		$wave = MSRWA_Engine_Steps::ready( $artifacts, $remaining, $registry );
		if ( ! $wave ) { self::finish( $id, 'failed', 'En attente de : ' . implode( ', ', MSRWA_Engine_Steps::missing( $remaining[0], $artifacts, $registry ) ) . ', qui n’est jamais arrivé.' ); return; }

		$spent = (float) $run['cost_usd'];
		$budget = (float) ( $config['limits']['budget_usd'] ?? 0 );
		if ( $budget > 0 && $spent >= $budget ) { self::finish( $id, 'failed', sprintf( 'Plafond de %.4f $ atteint avant %s.', $budget, implode( ', ', $wave ) ) ); return; }
		if ( $budget > 0 ) { $config['limits']['budget_usd'] = max( 0.000001, $budget - $spent ); }

		$workspace = self::workspace( $id );
		self::touch( $id, array( 'step' => mb_substr( implode( ', ', $wave ), 0, 120 ), 'workspace' => $workspace ) );

		$result = MSRWA_Engine::run(
			array_merge( $brief, array( 'artifacts' => $artifacts ) ),
			array( 'config' => $config, 'only' => $wave, 'workspace' => $workspace )
		);

		self::absorb( $id, $state, $result->to_array() );

		$run = self::get( $id );
		if ( ! $run || 'running' !== $run['status'] ) { return; }
		self::release( $id, $token );
		self::queue( $id, 2 );
	}

	/**
	 * Writes down everything one tick produced, in one statement per table.
	 *
	 * A wave reports several steps and a dozen events, and each one used to be
	 * its own round trip. They are collected first and inserted together, which
	 * also means a tick either records its wave or does not — rather than
	 * stopping halfway through a list of events.
	 */
	private static function absorb( $id, array $state, array $tick ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$now = current_time( 'mysql', true );
		$id = absint( $id );

		$steps = array();
		foreach ( (array) ( $tick['steps'] ?? array() ) as $step ) {
			$usage = (array) ( $step['usage'] ?? array() );
			$checks = (array) $step['checks'];
			$failed = 0;
			foreach ( $checks as $check ) { if ( is_array( $check ) && empty( $check['pass'] ) ) { $failed++; } }

			$steps[] = array(
				'run_id' => $id, 'step' => (string) $step['step'],
				'provider' => (string) $step['provider'], 'model' => (string) $step['model'],
				'seconds' => (float) $step['seconds'], 'attempts' => (int) $step['attempts'],
				'input_tokens' => (int) ( $usage['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
				// A model with no published rate cost an unknown amount, not
				// nothing. NULL is the honest column value and everything that
				// reads it keeps the distinction.
				'cost_usd' => null === $step['cost_usd'] ? null : (float) $step['cost_usd'],
				'bucket' => MSRWA_Engine_Steps::bucket( (string) $step['step'] ),
				'status' => (string) $step['status'],
				'passed' => null === $step['passed'] ? null : (int) $step['passed'],
				'total' => null === $step['total'] ? null : (int) $step['total'],
				'checks_json' => (string) wp_json_encode( MSRWA_DB::sanitize( $checks ) ),
				// Counted here so that asking which check keeps failing reads a
				// number instead of parsing every blob ever stored.
				'checks_failed' => $failed,
				'error_message' => (string) $step['error'], 'created_at' => $now,
			);
		}
		MSRWA_DB::insert_many( $t['steps'], $steps );

		$events = array();
		$calls = array();
		foreach ( (array) ( $tick['events'] ?? array() ) as $event ) {
			// Each tick is one wave of a longer run: its own opening and closing
			// lines would read as a run starting and finishing over and over.
			if ( in_array( (string) $event['kind'], array( 'start', 'config', 'finish' ), true ) ) { continue; }
			$data = MSRWA_DB::sanitize( (array) ( $event['data'] ?? array() ) );
			$events[] = array(
				'run_id' => $id, 'at_seconds' => (float) $event['at'], 'kind' => (string) $event['kind'],
				'step' => (string) $event['step'], 'message' => (string) $event['message'],
				'data_json' => (string) wp_json_encode( $data ), 'created_at' => $now,
			);
			if ( 'call' === (string) $event['kind'] ) { $calls[] = self::call_row( $id, (string) $event['step'], $data, $now ); }
		}
		MSRWA_DB::insert_many( $t['events'], $events );
		MSRWA_DB::insert_many( $t['calls'], $calls );

		foreach ( (array) ( $tick['artifacts'] ?? array() ) as $key => $value ) { self::record_artifact( $id, (string) $key, $value, $now ); }

		$ok = ! empty( $state['ok'] ) && ! empty( $tick['ok'] );
		$errors = array_merge( (array) ( $state['errors'] ?? array() ), (array) ( $tick['errors'] ?? array() ) );
		$totals = self::totals( self::steps( $id ) );

		$wpdb->update( self::table(), array(
			'result_json' => wp_json_encode( array( 'ok' => (bool) $ok, 'errors' => MSRWA_DB::sanitize( $errors ) ) ),
			'steps_done' => (int) $totals['steps'],
			'cost_usd' => (float) $totals['cost_usd'],
			'seconds' => (float) $totals['seconds'],
			'updated_at' => $now,
		), array( 'id' => $id ), array( '%s', '%d', '%f', '%f', '%s' ), array( '%d' ) );
	}

	/** One provider call as the engine reported it, ready to be inserted with its neighbours. */
	private static function call_row( $id, $step, array $data, $now ) {
		$usage = (array) ( $data['usage'] ?? array() );
		return array(
			'run_id' => absint( $id ), 'step' => (string) $step,
			'provider' => (string) ( $data['provider'] ?? '' ), 'model' => (string) ( $data['model'] ?? '' ),
			'tier' => (string) ( $data['tier'] ?? '' ), 'endpoint' => (string) ( $data['endpoint'] ?? '' ),
			'seconds' => (float) ( $data['seconds'] ?? 0 ),
			'input_tokens' => (int) ( $usage['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
			'cached_tokens' => (int) ( $usage['cached_input_tokens'] ?? 0 ),
			'cost_usd' => isset( $data['cost_usd'] ) && null !== $data['cost_usd'] ? (float) $data['cost_usd'] : null,
			'priced' => empty( $data['priced'] ) ? 0 : 1,
			'status' => (string) ( $data['status'] ?? '' ), 'created_at' => $now,
		);
	}

	/** An artifact under its own name, replacing any earlier version of itself. */
	private static function record_artifact( $id, $key, $value, $now ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$json = (string) wp_json_encode( MSRWA_DB::sanitize( $value ) );
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $t['artifacts'] . ' (run_id, artifact_key, content_json, bytes, created_at, updated_at) VALUES (%d, %s, %s, %d, %s, %s)'
			. ' ON DUPLICATE KEY UPDATE content_json = VALUES(content_json), bytes = VALUES(bytes), updated_at = VALUES(updated_at)',
			absint( $id ), $key, $json, strlen( $json ), $now, $now ) );
	}

	public static function steps( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $t['steps'] . ' WHERE run_id = %d ORDER BY id ASC', absint( $id ) ), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'step' => $row['step'], 'model' => $row['model'], 'provider' => $row['provider'],
				'bucket' => $row['bucket'],
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
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $t['calls'] . ' WHERE run_id = %d ORDER BY id ASC', absint( $id ) ), ARRAY_A );
	}

	public static function events( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $t['events'] . ' WHERE run_id = %d ORDER BY id ASC', absint( $id ) ), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array( 'at' => (float) $row['at_seconds'], 'kind' => $row['kind'], 'step' => $row['step'], 'message' => $row['message'], 'data' => (array) json_decode( (string) $row['data_json'], true ) );
		}
		return $out;
	}

	/**
	 * Artifacts WordPress stores, that this plugin therefore need not.
	 *
	 * `article` and `corrected` are superseded the moment proofreading runs:
	 * nothing reads them and they were sixty kilobytes of the same text twice.
	 * `canonical` and `approval` live in post meta, which this plugin wrote
	 * itself and no editor edits, so that copy is as good as this one.
	 *
	 * `proofread` is deliberately NOT in this list, and that is the whole
	 * argument. post_content is what the editor has since changed; this is what
	 * the machine actually produced. Keeping both is what makes "did the model
	 * write that claim, or did someone add it" a question with an answer — and
	 * every measurement of the engine's quality depends on reading its own
	 * output rather than a corrected version of it. It also means a run survives
	 * an editor deleting the draft.
	 *
	 * They stay while the run is in flight either way: the engine reads them
	 * back on every cron tick, and until the draft exists they are the only
	 * copy there is.
	 */
	private static function kept_by_wordpress() {
		return array( 'article', 'corrected', 'canonical', 'approval' );
	}

	public static function artifacts( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT artifact_key, content_json FROM ' . $t['artifacts'] . ' WHERE run_id = %d ORDER BY id ASC', absint( $id ) ), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) { $out[ $row['artifact_key'] ] = json_decode( (string) $row['content_json'], true ); }
		return self::rehydrate( $id, $out );
	}

	/**
	 * Reads back from WordPress what this plugin stopped storing.
	 *
	 * So every screen, and the full report, keep working as though nothing had
	 * been removed — the copy they read is simply the one an editor may since
	 * have corrected, which is the more useful one anyway.
	 */
	private static function rehydrate( $id, array $artifacts ) {
		$run = self::get( $id );
		$post_id = $run ? (int) $run['draft_post_id'] : 0;
		if ( ! $post_id ) { return $artifacts; }

		$post = get_post( $post_id );
		if ( ! $post ) { return $artifacts; }

		// What the reader will actually get, beside what the machine wrote. The
		// two differ as soon as an editor touches the draft, and a screen that
		// showed only one of them would be answering the wrong question.
		$artifacts['published'] = array( 'title' => $post->post_title, 'content_html' => $post->post_content );
		if ( ! isset( $artifacts['proofread'] ) ) {
			$artifacts['proofread'] = $artifacts['published'];
		}
		if ( ! isset( $artifacts['canonical'] ) ) {
			$recipe = json_decode( (string) get_post_meta( $post_id, '_msrwa_recipe', true ), true );
			if ( is_array( $recipe ) ) { $artifacts['canonical'] = $recipe; }
		}
		if ( ! isset( $artifacts['approval'] ) ) {
			$verdict = json_decode( (string) get_post_meta( $post_id, '_msrwa_judge_report', true ), true );
			if ( is_array( $verdict ) ) { $artifacts['approval'] = $verdict; }
		}
		foreach ( array( 'featured', 'facebook' ) as $kind ) {
			if ( ! isset( $artifacts[ $kind ] ) ) { continue; }
			$attachment = (int) get_post_meta( $post_id, '_msrwa_' . $kind . '_image_id', true );
			if ( ! $attachment ) { continue; }
			// The generated file was removed once the media library had it, so
			// anything reading a path is pointed at the library's copy.
			$artifacts[ $kind ]['path'] = (string) get_attached_file( $attachment );
			$artifacts[ $kind ]['attachment_id'] = $attachment;
		}
		return $artifacts;
	}

	/**
	 * Drops what WordPress now holds, once it actually holds it.
	 *
	 * Called after the draft exists, never before: until then these rows are
	 * the only copy, and the next cron tick needs them.
	 */
	public static function release_stored( $id ) {
		global $wpdb;
		$run = self::get( $id );
		if ( ! $run || ! (int) $run['draft_post_id'] ) { return 0; }

		$t = MSRWA_DB::tables();
		$keys = self::kept_by_wordpress();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$removed = (int) $wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . $t['artifacts'] . ' WHERE run_id = %d AND artifact_key IN (' . $placeholders . ')',
			array_merge( array( absint( $id ) ), $keys ) ) );

		// The engine wrote each image into the run's workspace and the draft
		// copied it into the media library. Two files, one of which nothing will
		// ever open again.
		foreach ( array( 'featured', 'facebook' ) as $kind ) {
			if ( ! get_post_meta( (int) $run['draft_post_id'], '_msrwa_' . $kind . '_image_id', true ) ) { continue; }
			$stored = self::artifact_path( $id, $kind );
			if ( '' !== $stored && is_file( $stored ) ) { wp_delete_file( $stored ); }
		}
		return $removed;
	}

	/** The workspace path an image artifact was written to, if it is still recorded. */
	private static function artifact_path( $id, $kind ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$json = $wpdb->get_var( $wpdb->prepare( 'SELECT content_json FROM ' . $t['artifacts'] . ' WHERE run_id = %d AND artifact_key = %s', absint( $id ), $kind ) );
		$artifact = json_decode( (string) $json, true );
		$path = is_array( $artifact ) ? (string) ( $artifact['path'] ?? '' ) : '';

		// Never delete outside this run's own directory, whatever a stored path
		// happens to say.
		$root = realpath( self::workspace( $id ) );
		$real = $path ? realpath( $path ) : false;
		if ( ! $root || ! $real || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) { return ''; }
		return $real;
	}

	/**
	 * The whole run in the shape the engine returns and the report renders.
	 * Assembled from the rows rather than kept as a second copy.
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

	/** The run's totals, summed over its own steps rather than over one tick. */
	private static function totals( array $steps ) {
		$result = new MSRWA_Result();
		foreach ( $steps as $step ) { $result->steps[] = $step; }
		return $result->totals();
	}

	/** Every step has run: record the verdict and hand the result to the draft. */
	private static function complete( $id, array $state ) {
		$approval = (array) ( $state['artifacts']['approval'] ?? array() );
		$approved = array_key_exists( 'approved', $approval ) ? (int) ! empty( $approval['approved'] ) : null;
		self::touch( $id, array( 'approved' => $approved ) );
		self::finish( $id, empty( $state['ok'] ) ? 'failed' : 'done', '' );
		MSRWA_Draft::create( (int) $id );
		MSRWA_Batch::settle( (int) ( self::get( $id )['batch_id'] ?? 0 ) );
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
		), array( 'id' => absint( $id ) ) );
		if ( in_array( $status, array( 'failed', 'cancelled' ), true ) ) { MSRWA_Batch::settle( (int) ( self::get( $id )['batch_id'] ?? 0 ) ); }
	}

	/**
	 * Removes one recipe and everything the engine reported about it.
	 *
	 * The draft it produced is left alone: it is an ordinary post, somebody may
	 * have published it, and deleting a record of a run is not a reason to
	 * delete a reader's article.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$run = self::get( $id );
		if ( ! $run || ! self::may_see( $run ) ) { return false; }
		if ( in_array( (string) $run['status'], array( 'queued', 'running' ), true ) ) { return false; }

		foreach ( array( 'steps', 'calls', 'events', 'artifacts' ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t[ $table ] . ' WHERE run_id = %d', absint( $id ) ) );
		}
		$wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );
		MSRWA_Batch::settle( (int) $run['batch_id'] );
		return true;
	}

	/** Whether this run can be picked up again, and by this person. */
	public static function may_retry( array $run ) {
		return in_array( (string) $run['status'], array( 'failed', 'cancelled' ), true ) && self::may_see( $run );
	}

	/**
	 * Picks a stopped run back up from where it stopped.
	 *
	 * Only the steps that errored are removed; everything that succeeded stays,
	 * artifacts included. So a run that died at the collage redraws the collage
	 * and does not pay again for research, the article or the images that came
	 * out fine — which is the whole reason the steps are rows rather than a
	 * blob in the first place.
	 *
	 * The events of the failed attempt are kept. What went wrong the first time
	 * is usually why it went wrong the second.
	 */
	public static function retry( $id ) {
		global $wpdb;
		$run = self::get( $id );
		if ( ! $run || ! self::may_retry( $run ) ) { return false; }

		$t = MSRWA_DB::tables();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t['steps'] . " WHERE run_id = %d AND error_message <> ''", absint( $id ) ) );

		$wpdb->update( self::table(), array(
			'status' => 'queued', 'step' => '', 'error_message' => '',
			'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ),
		), array( 'id' => absint( $id ) ) );

		self::queue( (int) $id, 2 );
		MSRWA_Batch::reopen( (int) $run['batch_id'] );
		return true;
	}

	/**
	 * Puts a run that lost its worker back in the queue.
	 *
	 * A lease expires when a request was killed mid-wave. That wave is paid for
	 * and lost, but everything before it was written down, so the run resumes
	 * from the last step that finished rather than from the beginning.
	 */
	public static function recover_expired() {
		global $wpdb;
		update_option( 'msrwa_watchdog_at', current_time( 'mysql', true ), false );
		$stuck = (array) $wpdb->get_col( 'SELECT id FROM ' . self::table() . " WHERE status = 'running' AND lock_until IS NOT NULL AND lock_until < UTC_TIMESTAMP() LIMIT 20" );
		foreach ( $stuck as $id ) {
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'queued', lock_token = NULL, lock_until = NULL, updated_at = %s WHERE id = %d AND status = 'running' AND lock_until IS NOT NULL AND lock_until < UTC_TIMESTAMP()", current_time( 'mysql', true ), absint( $id ) ) );
			if ( $changed ) { self::queue( (int) $id, 5 ); }
		}
		$queued = (array) $wpdb->get_col( 'SELECT id FROM ' . self::table() . " WHERE status = 'queued' ORDER BY updated_at ASC LIMIT 100" );
		foreach ( $queued as $id ) { self::queue( (int) $id, 5 ); }
	}

	/** Where a run's generated images are written, under the uploads directory. */
	public static function workspace( $id ) {
		$uploads = wp_upload_dir();
		$path = trailingslashit( $uploads['basedir'] ) . 'msrwa/' . absint( $id );
		if ( ! is_dir( $path ) ) { wp_mkdir_p( $path ); }
		return $path;
	}
}
