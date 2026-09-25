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

	/**
	 * How long one tick keeps starting waves. WordPress's cron fires only on a
	 * visit, so a recipe that handed every wave back to cron waited for a
	 * visitor between each of its seven waves; on a quiet site a lot stalled
	 * whenever nobody was looking. A wave already running is always finished,
	 * and the longest takes some 100 s, so a tick ends inside the 300 s many
	 * hosts allow a request.
	 */
	const TICK_SECONDS = 150;

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

	/**
	 * An artifact a run starts with rather than produces: the editor's own
	 * collage stands where the drawn one would, so every later step and the
	 * draft read it exactly as they would read a drawing.
	 */
	public static function seed( $id, $key, array $value ) { self::record_artifact( absint( $id ), (string) $key, $value, current_time( 'mysql', true ) ); }

	public static function queue( $id, $delay = 5 ) {
		// The event is the safety net; the worker is what runs it now.
		if ( ! wp_next_scheduled( 'msrwa_run_step', array( absint( $id ) ) ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), 'msrwa_run_step', array( absint( $id ) ) );
		}
		if ( class_exists( 'MSRWA_Worker' ) ) { MSRWA_Worker::kick( $id ); }
	}

	/**
	 * Whether cron has let a recipe down: it is waiting, and its tick is gone
	 * or half a minute late. WordPress cron fires only when a request reaches
	 * WordPress and it can call itself back; with DISABLE_WP_CRON and no server
	 * cron, a page cache in front of every visit, or loopback requests blocked,
	 * a queued recipe waited forever.
	 */
	public static function overdue( array $run ) {
		if ( 'queued' !== (string) ( $run['status'] ?? '' ) ) { return false; }
		$next = wp_next_scheduled( 'msrwa_run_step', array( absint( $run['id'] ) ) );
		return ! $next || $next < time() - 30;
	}

	/**
	 * Carries a lot on from the page watching it, when cron does not.
	 *
	 * The lot page asks for this while a recipe is overdue. It runs that
	 * recipe's tick in the request — a wave, bounded as cron's are — and the
	 * lease tick() takes means two tabs cannot run the same step twice.
	 * Returns the run it moved, or 0.
	 */
	public static function nudge( $batch_id ) {
		if ( MSRWA_Queue::held() || '' !== MSRWA_Budget::refusal() ) { return 0; }
		// A recipe left `running` by a killed request is the watchdog's, and the
		// watchdog is cron too.
		self::recover_expired();
		$runs = self::for_batch( $batch_id );
		// The page sends up to three nudges at once: each starts from a
		// different recipe rather than all three queueing on the first's lease.
		shuffle( $runs );
		foreach ( $runs as $run ) {
			if ( ! self::may_see( $run ) || ! self::overdue( $run ) ) { continue; }
			self::tick( (int) $run['id'] );
			return (int) $run['id'];
		}
		return 0;
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
		return MSRWA_Rights::may_see( (int) $run['owner_id'] );
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

		// Two reasons not to start another wave, and one thing to do about
		// either: put the recipe back in the queue with every step it has
		// already finished, and let it resume where it stopped. Nothing is lost
		// and nothing more is spent.
		//
		// The ceilings are read here and not only when a lot is dispatched,
		// because a lot that was affordable when it started can stop being
		// affordable while it runs — which is exactly what a runaway looks like.
		if ( MSRWA_Queue::held() || '' !== MSRWA_Budget::refusal() ) { self::park( $id ); return; }

		$token = wp_generate_password( 32, false, false );
		if ( ! self::claim( $id, $token ) ) { return; }

		// A wave is time on the wire, not PHP work, but a host that counts it
		// anyway would kill the run mid-wave. Ask for the room, and keep going
		// if whatever triggered cron has gone.
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
		ignore_user_abort( true );

		$run = self::get( $id );
		if ( ! $run ) { return; }
		$started = microtime( true );
		try {
			// Each wave is written down before the next starts, so a request the
			// host kills still costs at most the wave it was in.
			while ( self::advance( $run, $token ) ) {
				$run = self::get( $id );
				$carry_on = $run && 'running' === $run['status']
					&& microtime( true ) - $started < self::TICK_SECONDS
					&& ! MSRWA_Queue::held() && '' === MSRWA_Budget::refusal()
					&& self::renew( $id, $token );
				if ( ! $carry_on ) {
					if ( $run && 'running' === $run['status'] ) { self::release( $id, $token ); self::queue( $id, 2 ); }
					return;
				}
			}
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
	 * Returns whether the run has more to do.
	 */
	private static function advance( array $run, $token ) {
		$id = (int) $run['id'];
		$brief = (array) json_decode( (string) $run['brief_json'], true );
		$config = MSRWA_Batch::config_for( (int) $run['batch_id'] );
		$state = self::state( $id );
		$artifacts = (array) $state['artifacts'];
		$lead = (string) ( $brief['collage_lead'] ?? '' );
		$registry = MSRWA_Engine_Steps::for_lead( (array) ( $config['steps'] ?? array() ), $lead );

		$done = array();
		foreach ( (array) $state['steps'] as $step ) { $done[] = (string) $step['step']; }

		// The batch's profile decides which steps this run has: an article-only
		// batch must not sit waiting for an image it never asked anyone to draw.
		$batch = MSRWA_Batch::get( (int) $run['batch_id'] );
		$wanted = MSRWA_Profile::run_steps( $batch ? $batch['profile'] : MSRWA_Profile::FULL, (array) ( $config['steps'] ?? array() ), $lead );
		$remaining = array_values( array_diff( $wanted, $done ) );
		if ( ! $remaining ) { self::complete( $id, $state ); return false; }

		$wave = MSRWA_Engine_Steps::ready( $artifacts, $remaining, $registry );
		if ( ! $wave ) {
			self::finish( $id, 'failed', sprintf(
				/* translators: %s is a comma-separated list of step names. */
				__( 'En attente de : %s, qui n’est jamais arrivé.', 'ms-recipes-writer-ai' ),
				implode( ', ', MSRWA_Engine_Steps::missing( $remaining[0], $artifacts, $registry ) )
			) );
			return false;
		}

		$spent = (float) $run['cost_usd'];
		$budget = (float) ( $config['limits']['budget_usd'] ?? 0 );
		if ( $budget > 0 && $spent >= $budget ) { self::finish( $id, 'failed', sprintf( 'Plafond de %.4f $ atteint avant %s.', $budget, implode( ', ', $wave ) ) ); return false; }
		if ( $budget > 0 ) { $config['limits']['budget_usd'] = max( 0.000001, $budget - $spent ); }

		$workspace = self::workspace( $id );
		self::touch( $id, array( 'step' => mb_substr( implode( ', ', $wave ), 0, 120 ), 'workspace' => $workspace ) );

		$result = MSRWA_Engine::run(
			array_merge( $brief, array( 'artifacts' => $artifacts ) ),
			// The writer's photographs are read from the site's own files: their
			// address is the site's, which need not be public or HTTPS.
			// The engine keeps what it found on the web beside them, so the
			// recipe's record says what it was written from.
			array( 'config' => $config, 'only' => $wave, 'workspace' => $workspace, 'read_image' => MSRWA_Sources::reader( $id ), 'keep_image' => MSRWA_Sources::keeper( $id ) )
		);

		self::absorb( $id, $state, $result->to_array() );
		self::remember( $id, $result, $registry );
		if ( in_array( 'research', $wave, true ) && is_array( $result->artifacts['research'] ?? null ) ) { MSRWA_Sources::complete( $id, $result->artifacts['research'] ); }

		$run = self::get( $id );
		return $run && 'running' === $run['status'];
	}

	/**
	 * Each step of the tick into the job's history: what it was asked, what it
	 * answered, how it scored and what it cost. The working tables release the
	 * article once WordPress holds it; the history keeps this.
	 */
	private static function remember( $id, MSRWA_Result $result, array $registry ) {
		$steps = MSRWA_Engine_Steps::all( $registry );
		foreach ( $result->steps as $step ) {
			$produces = (string) ( $steps[ $step['step'] ]['produces'] ?? '' );
			$answer = '' !== $produces && isset( $result->artifacts[ $produces ] ) ? $result->artifacts[ $produces ] : null;
			// An image is a file in the job's folder; its bytes are not the record.
			if ( is_array( $answer ) ) { unset( $answer['b64_json'], $answer['data'] ); }
			MSRWA_History::run( $id, 'step', array_merge( $step, array( 'answer' => $answer ) ) );
		}
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
				// A provider's own words, which may carry a partial key.
				'error_message' => MSRWA_DB::sanitize( (string) $step['error'] ), 'created_at' => $now,
			);
		}
		MSRWA_DB::insert_many( $t['steps'], $steps );
		$owner = $wpdb->get_row( $wpdb->prepare( 'SELECT id, owner_id, batch_id FROM ' . $t['runs'] . ' WHERE id = %d', $id ), ARRAY_A );
		if ( $owner ) { MSRWA_Spend::steps( $owner, $steps, $now ); }

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
		$recorded = self::steps( $id );
		$totals = self::totals( $recorded );

		$wpdb->update( self::table(), array(
			'result_json' => wp_json_encode( array( 'ok' => (bool) $ok, 'errors' => MSRWA_DB::sanitize( $errors ) ) ),
			// Distinct steps: an image the final approval had redrawn twice read
			// as 12 steps done out of 10.
			'steps_done' => count( array_unique( array_column( $recorded, 'step' ) ) ),
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
			$attachment = (int) get_post_meta( $post_id, MSRWA_Draft::generated_key( $kind ), true );
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
			if ( ! get_post_meta( (int) $run['draft_post_id'], MSRWA_Draft::generated_key( $kind ), true ) ) { continue; }
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
		// The draft first: a run read as done while its draft did not exist yet
		// sent a watcher away with draft #0.
		MSRWA_Draft::create( (int) $id );
		self::finish( $id, empty( $state['ok'] ) ? 'failed' : 'done', '' );
		$run = self::get( $id );
		MSRWA_History::run( $id, 'result', array(
			'status' => (string) ( $run['status'] ?? '' ), 'draft_post_id' => (int) ( $run['draft_post_id'] ?? 0 ),
			'approved' => $approved, 'cost_usd' => (float) ( $run['cost_usd'] ?? 0 ), 'seconds' => (float) ( $run['seconds'] ?? 0 ),
			'images' => array_filter( array(
				'featured' => (int) get_post_meta( (int) ( $run['draft_post_id'] ?? 0 ), MSRWA_Draft::generated_key( 'featured' ), true ),
				'facebook' => (int) get_post_meta( (int) ( $run['draft_post_id'] ?? 0 ), MSRWA_Draft::generated_key( 'facebook' ), true ),
			) ),
			'verdict' => $approval,
		) );
		MSRWA_Batch::settle( (int) ( self::get( $id )['batch_id'] ?? 0 ) );
	}

	private static function touch( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $fields, array( 'id' => absint( $id ) ) );
	}

	/** Extends the lease this tick holds; false when it is no longer this tick's. */
	private static function renew( $id, $token ) {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET lock_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . (int) self::LEASE_MINUTES . ' MINUTE) WHERE id = %d AND lock_token = %s', absint( $id ), $token ) );
	}

	private static function release( $id, $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET lock_token = NULL, lock_until = NULL WHERE id = %d AND lock_token = %s', absint( $id ), $token ) );
	}

	/**
	 * Sends a recipe back to the queue without touching what it has produced.
	 *
	 * Not a failure: nothing about the article went wrong. `updated_at` is
	 * deliberately left alone, so a recipe parked over and over does not look
	 * newer than the ones that have genuinely been waiting behind it.
	 */
	private static function park( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'queued', 'lock_token' => null, 'lock_until' => null ), array( 'id' => absint( $id ) ) );
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
		MSRWA_Sources::forget_run( (int) $id );
		MSRWA_History::forget_run( (int) $id );
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

	/** How many times an editor may have one image of one recipe redrawn. */
	const REDRAWS = 2;

	/** The images of a finished recipe the judge refused, and that may still be redrawn. */
	public static function redrawable( array $run ) {
		$post_id = (int) ( $run['draft_post_id'] ?? 0 );
		if ( ! $post_id || 'done' !== (string) $run['status'] || ! self::may_see( $run ) ) { return array(); }
		$artifacts = self::artifacts( (int) $run['id'] );
		$approval = (array) ( $artifacts['approval'] ?? array() );
		$out = array();
		foreach ( MSRWA_Engine_Score::images_to_retry( $approval ) as $kind ) {
			// The editor's own collage is never drawn over.
			if ( 'facebook' === $kind && ! empty( $artifacts['facebook']['provided'] ) ) { continue; }
			if ( (int) get_post_meta( $post_id, '_msrwa_' . $kind . '_redrawn', true ) < self::REDRAWS ) { $out[] = $kind; }
		}
		return $out;
	}

	/**
	 * Draws one image of a finished recipe again, from the judge's findings.
	 *
	 * The engine no longer redraws on its own: every refusal cost a second
	 * image and a second verdict, a quarter of a recipe, whether or not anybody
	 * minded the finding. The editor reads the findings and decides. The new
	 * image replaces the draft's, the call is recorded with the recipe's other
	 * steps, and it is not judged again — the editor is looking at it.
	 *
	 * Returns '' on success, or why not, in words a writer may read.
	 */
	public static function redraw( $id, $kind ) {
		$run = self::get( $id );
		if ( ! $run || ! in_array( $kind, self::redrawable( $run ), true ) ) { return __( 'Cette image ne peut pas être redessinée.', 'ms-recipes-writer-ai' ); }
		if ( '' !== MSRWA_Budget::refusal() ) { return MSRWA_Budget::refusal(); }

		$id = (int) $run['id'];
		$state = self::state( $id );
		$artifacts = (array) $state['artifacts'];
		$config = MSRWA_Batch::config_for( (int) $run['batch_id'] );
		$step = $kind . '_image';
		$budget = (float) ( $config['limits']['budget_usd'] ?? 0 );
		$last = 0.0;
		foreach ( (array) $state['steps'] as $done ) { if ( $step === $done['step'] ) { $last = (float) $done['cost_usd']; } }
		if ( $budget > 0 && (float) $run['cost_usd'] + $last > $budget ) { return __( 'Le plafond de cette recette est atteint.', 'ms-recipes-writer-ai' ); }

		$approval = (array) ( $artifacts['approval'] ?? array() );
		$findings = MSRWA_Engine_Score::findings_for( $approval, $step );
		if ( 'facebook' === $kind ) { $findings = array_merge( $findings, MSRWA_Engine_Score::findings_for( $approval, 'consistency' ) ); }

		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
		$brief = (array) json_decode( (string) $run['brief_json'], true );
		$result = MSRWA_Engine::run_step( $step, array_merge( $brief, array( 'artifacts' => $artifacts ) ), array(
			'config' => $config, 'workspace' => self::workspace( $id ), 'findings' => $findings,
			'read_image' => MSRWA_Sources::reader( $id ),
		) );
		self::absorb( $id, $state, $result->to_array() );
		self::remember( $id, $result, MSRWA_Engine_Steps::for_lead( (array) ( $config['steps'] ?? array() ), (string) ( $brief['collage_lead'] ?? '' ) ) );

		$image = (array) ( $result->artifacts[ $kind ] ?? array() );
		if ( ! $result->ok || ! $image || ! MSRWA_Draft::replace_image( (int) $run['draft_post_id'], $kind, $image ) ) {
			return __( 'Le nouveau dessin a échoué. Réessayez plus tard.', 'ms-recipes-writer-ai' );
		}
		$post_id = (int) $run['draft_post_id'];
		update_post_meta( $post_id, '_msrwa_' . $kind . '_redrawn', (int) get_post_meta( $post_id, '_msrwa_' . $kind . '_redrawn', true ) + 1 );
		// The draft holds the image now; the workspace copy is not needed again.
		if ( ! empty( $image['path'] ) && is_file( (string) $image['path'] ) ) { wp_delete_file( (string) $image['path'] ); }
		return '';
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
		$stuck = (array) $wpdb->get_col( 'SELECT id FROM ' . self::table() . " WHERE status = 'running' AND lock_until IS NOT NULL AND lock_until < UTC_TIMESTAMP() ORDER BY priority DESC, id ASC LIMIT 20" );
		foreach ( $stuck as $id ) {
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'queued', lock_token = NULL, lock_until = NULL, updated_at = %s WHERE id = %d AND status = 'running' AND lock_until IS NOT NULL AND lock_until < UTC_TIMESTAMP()", current_time( 'mysql', true ), absint( $id ) ) );
			if ( $changed ) { self::queue( (int) $id, 5 ); }
		}
		// Priority first, then age: a recipe somebody pushed to the front of the
		// queue should not wait behind everything that happened to arrive first.
		$queued = (array) $wpdb->get_col( 'SELECT id FROM ' . self::table() . " WHERE status = 'queued' ORDER BY priority DESC, updated_at ASC LIMIT 100" );
		foreach ( $queued as $id ) { self::queue( (int) $id, 5 ); }
		// Between two waves a run is `running` with no lease, and only its cron
		// event carries it on. WordPress keeps every event in one option, so
		// workers finishing together overwrite each other's next event, and a
		// run left that way waited forever. queue() skips a run already armed,
		// and the claim keeps a duplicate from spending anything.
		$between = (array) $wpdb->get_col( 'SELECT id FROM ' . self::table() . " WHERE status = 'running' AND lock_until IS NULL ORDER BY priority DESC, updated_at ASC LIMIT 100" );
		foreach ( $between as $id ) { self::queue( (int) $id, 5 ); }
	}

	/** Where a run's generated images are written, under the uploads directory. */
	public static function workspace( $id ) {
		$uploads = wp_upload_dir();
		$path = trailingslashit( $uploads['basedir'] ) . 'msrwa/' . absint( $id );
		if ( ! is_dir( $path ) ) { wp_mkdir_p( $path ); }
		return $path;
	}
}
