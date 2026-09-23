<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The engine. Everything a caller needs is here; the rest is detail.
 *
 * Two entry points, one shape:
 *
 *   MSRWA_Engine::run( $input, $options, $observer )       one recipe, every step
 *   MSRWA_Engine::run_step( $name, $input, $options, … )   one step, for the lab
 *
 * Both return a MSRWA_Result: artifacts, per-step figures, totals in four budget
 * buckets, errors, and the events as they happened. Nothing throws and nothing
 * exits — a plugin cannot afford either mid-request — so a failed step is
 * recorded and the run continues wherever continuing still makes sense.
 *
 * $observer, when given, is called with each event the moment it occurs. That is
 * what a progress bar reads, and it is the same stream the report renders later.
 */
final class MSRWA_Engine {

	/**
	 * Runs a recipe from an editor brief.
	 *
	 * Steps run in dependency waves, so the article is written while both images
	 * are drawn, and the three reviews run together afterwards. A wave's steps
	 * are independent by construction — that is what `needs` declares — so a
	 * caller able to run them concurrently may, and this sequential
	 * implementation stays correct either way.
	 */
	public static function run( array $input, array $options = array(), $observer = null ) {
		$result = new MSRWA_Result( $observer );
		$config = self::configure( $options );

		$brief = self::normalise_brief( $input );
		if ( '' === trim( (string) $brief['title'] ) && '' === trim( (string) $brief['text'] ) ) {
			return $result->fail( 'run', 'The brief carries neither a title nor a text; there is nothing to research.' );
		}

		$result->event( 'start', 'run', 'Starting ' . ( '' !== $brief['title'] ? $brief['title'] : 'an untitled brief' ), array( 'config' => $config->to_array() ) );
		$result->artifact( 'config', $config->to_array() );
		$result->artifact( 'brief', $brief );


		$registry = (array) $config->get( 'steps', array() );
		$only = array_values( array_filter( (array) ( $options['only'] ?? array() ) ) );
		$remaining = $only ? array_values( array_intersect( MSRWA_Engine_Steps::names( $registry ), $only ) ) : MSRWA_Engine_Steps::names( $registry );
		foreach ( (array) ( $input['artifacts'] ?? array() ) as $key => $value ) { $result->artifact( $key, $value ); }
		$budget = (float) $config->get( 'limits.budget_usd', 0 );

		// What this run is configured with, and who decided each part of it. A
		// report months later answers "why did it do that" from here.
		$decided = array();
		foreach ( $config->provenance() as $key => $layer ) { if ( 'engine' !== $layer ) { $decided[] = $key . ' (' . $layer . ')'; } }
		$result->event( 'config', 'run', $decided ? 'Overridden by the caller: ' . implode( ', ', $decided ) . '. Everything else is the engine default.' : 'Every value is the engine default; the caller overrode nothing.', array( 'provenance' => $config->provenance() ) );
		$result->event( 'config', 'run', sprintf( '%d steps to run, %s budget: %s.', count( $remaining ), $budget > 0 ? sprintf( '$%.4f', $budget ) : 'no', implode( ', ', $remaining ) ), array( 'steps' => $remaining, 'budget_usd' => $budget ) );

		while ( $remaining ) {
			$wave = MSRWA_Engine_Steps::ready( $result->artifacts, $remaining, $registry );
			if ( ! $wave ) {
				foreach ( $remaining as $name ) {
					$result->fail( $name, 'Waiting on ' . implode( ', ', MSRWA_Engine_Steps::missing( $name, $result->artifacts, $registry ) ) . ', which never arrived.' );
				}
				break;
			}

			$result->event( 'wave', 'run', count( $wave ) > 1 ? 'These may run together: ' . implode( ', ', $wave ) : 'Next: ' . $wave[0], array( 'steps' => $wave ) );

			$spent_so_far = $result->totals();
			if ( $budget > 0 && ( $spent_so_far['cost_usd'] >= $budget || $spent_so_far['unpriced_steps'] > 0 ) ) {
				// An unpriced call already made means the spend is not known to be
				// under the budget, and a budget that cannot be checked is not a
				// budget. Stopping is the conservative reading.
				$why = $spent_so_far['unpriced_steps'] > 0 && $spent_so_far['cost_usd'] < $budget
					? sprintf( '%d call(s) ran on a model with no published rate, so the $%.4f budget can no longer be verified', $spent_so_far['unpriced_steps'], $budget )
					: sprintf( 'Stopped at the $%.4f budget', $budget );
				foreach ( $wave as $name ) { $result->fail( $name, $why . ', before running ' . $name . '.' ); }
				break;
			}

			// Independent steps are asked together; the retries that follow are each
			// step's own business, so they go one at a time.
			$seeds = count( $wave ) > 1 && (int) $config->get( 'limits.concurrency', 4 ) > 1
				? self::attempt_wave( $wave, $config, $result, $options )
				: array();
			foreach ( $wave as $name ) {
				self::perform( $name, $config, $result, $options, $seeds[ $name ] ?? null );
			}

			$remaining = array_values( array_diff( $remaining, $wave ) );
		}

		$totals = $result->totals();
		$result->event( 'finish', 'run', sprintf( '%s: %d steps, %ss, $%.4f', $result->ok ? 'Complete' : 'Finished with errors', $totals['steps'], $totals['seconds'], $totals['cost_usd'] ), $totals );
		return $result;
	}

	/**
	 * One step on its own, which is how the lab measures a prompt.
	 *
	 * Whatever the step needs is passed in as `artifacts`, exactly as an earlier
	 * run would have produced it; nothing is read from disk here.
	 */
	public static function run_step( $name, array $input, array $options = array(), $observer = null ) {
		$result = new MSRWA_Result( $observer );
		$config = self::configure( $options );
		$result->artifact( 'brief', self::normalise_brief( $input ) );
		foreach ( (array) ( $input['artifacts'] ?? array() ) as $key => $value ) { $result->artifact( $key, $value ); }
		self::perform( $name, $config, $result, $options );
		return $result;
	}

	/** Resolves the three configuration layers and points the shared classes at the result. */
	private static function configure( array $options ) {
		$config = MSRWA_Engine_Config::create( (array) ( $options['config'] ?? array() ), (array) ( $options['run'] ?? array() ) );
		$settings = $config->settings();
		if ( $settings ) { MSRWA_Engine_Input::use_settings( $settings ); }
		return $config;
	}

	/**
	 * Runs one step and records it, retrying while its contract says to.
	 *
	 * A step that fails its own scorecard is asked again up to its configured
	 * attempts, and between two attempts it may change what it is asking about:
	 * a refused approval regenerates the images the judge blocked, which is what
	 * makes "retry until approved" converge instead of rolling the dice again.
	 */
	private static function perform( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options, $seed = null ) {
		$registry = (array) $config->get( 'steps', array() );
		$step = MSRWA_Engine_Steps::get( $name, $registry );
		if ( ! $step ) { return $result->fail( $name, 'Unknown step.' ); }

		$missing = MSRWA_Engine_Steps::missing( $name, $result->artifacts, $registry );
		if ( $missing ) { return $result->fail( $name, 'Cannot run without ' . implode( ', ', $missing ) . '.' ); }

		$attempts = $config->attempts( $name );
		$spent = 0.0;
		$seconds = 0.0;
		$outcome = array();

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			// The first attempt may already have been made, alongside the rest of its
			// wave. Its event was announced there.
			if ( 1 === $attempt && is_array( $seed ) ) {
				$outcome = $seed;
			} else {
				$result->event( 'attempt', $name, sprintf( 'Attempt %d of %d: %s', $attempt, $attempts, $step['label'] ) );
				$outcome = self::call( $name, $config, $result, $options );
			}
			$spent += (float) $outcome['cost_usd'];
			$seconds += (float) $outcome['seconds'];
			$outcome['cost_usd'] = round( $spent, 6 );
			$outcome['seconds'] = round( $seconds, 1 );
			$outcome['attempts'] = $attempt;

			if ( '' === $outcome['retry'] || $attempt >= $attempts ) { break; }
			// The budget was only checked between waves, so a refused approval
			// could redraw and ask again past a ceiling the run had nearly
			// reached. The next round is priced from what this one cost — the
			// last attempt plus the images it would redraw — and not begun when
			// it would cross the budget: the verdict so far goes to the editor.
			$budget = (float) $config->get( 'limits.budget_usd', 0 );
			if ( $budget > 0 ) {
				$next = (float) $outcome['cost_usd'] / max( 1, $attempt ) + self::redraw_cost( $outcome, $result );
				if ( $result->totals()['cost_usd'] + $spent + $next > $budget ) {
					$result->event( 'decision', $name, sprintf( 'Not retried: another round would cost about $%.4f and cross the $%.4f budget. The verdict so far goes to the editor.', $next, $budget ) );
					break;
				}
			}
			$result->event( 'retry', $name, $outcome['retry'] );
			// Recorded as its own step inside before_retry(); not this step's cost.
			self::before_retry( $name, $outcome, $config, $result, $options );
		}

		if ( '' === $outcome['error'] && isset( $outcome['artifact'] ) ) { $result->artifact( $step['produces'], $outcome['artifact'] ); }
		unset( $outcome['artifact'], $outcome['retry'] );
		return $result->step( $name, $outcome );
	}

	/**
	 * What changes between a refusal and the next attempt.
	 *
	 * Only the approval step has anything to do here: the images it blocked are
	 * drawn again with its findings as corrections, so the next verdict is passed
	 * different images rather than the same ones. Returns what that cost.
	 */
	/** What redrawing the images a refusal names cost last time they were drawn. */
	private static function redraw_cost( array $outcome, MSRWA_Result $result ) {
		if ( ! is_array( $outcome['artifact'] ?? null ) ) { return 0.0; }
		$cost = 0.0;
		foreach ( MSRWA_Engine_Score::images_to_retry( $outcome['artifact'] ) as $kind ) {
			foreach ( array_reverse( $result->steps ) as $step ) {
				if ( $kind . '_image' === $step['step'] ) { $cost += (float) $step['cost_usd']; break; }
			}
		}
		return $cost;
	}

	private static function before_retry( $name, array $outcome, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options ) {
		if ( 'final_approval' !== $name || ! is_array( $outcome['artifact'] ?? null ) ) { return 0.0; }
		$verdict = $outcome['artifact'];
		$spent = 0.0;
		$judged = self::judged( $result );
		$repairs = MSRWA_Engine_Score::article_repairs( $verdict, (string) ( $judged['content_html'] ?? '' ) );
		if ( $repairs ) {
			$html = (string) $judged['content_html'];
			foreach ( $repairs as $repair ) { $html = self::substitute( $html, $repair['before'], $repair['after'] ); }
			$earlier = (array) ( $judged['approval_repairs'] ?? array() );
			// Written as the proofread article, which is the one the draft is made from.
			$result->artifact( 'proofread', array_merge( $judged, array( 'content_html' => $html, 'approval_repairs' => array_merge( $earlier, $repairs ) ) ) );
			$result->event( 'decision', $name, sprintf( 'Corrected %d sentence(s) the approval quoted, in code, before judging again.', count( $repairs ) ), array( 'repairs' => $repairs ) );
		}
		foreach ( MSRWA_Engine_Score::images_to_retry( $verdict ) as $kind ) {
			$findings = MSRWA_Engine_Score::findings_for( $verdict, $kind . '_image' );
			if ( 'facebook' === $kind ) { $findings = array_merge( $findings, MSRWA_Engine_Score::findings_for( $verdict, 'consistency' ) ); }
			$redrawn = self::call( $kind . '_image', $config, $result, $options, $findings );
			$spent += (float) $redrawn['cost_usd'];
			if ( '' !== $redrawn['error'] ) {
				$result->fail( $kind . '_image', 'Could not redraw for the approval: ' . $redrawn['error'] );
				continue;
			}
			$result->artifact( $kind, $redrawn['artifact'] );
			$result->step( $kind . '_image', array_diff_key( $redrawn, array( 'artifact' => 1, 'retry' => 1 ) ) );
		}
		// The redraws are steps of their own now, and totals() sums every step. What
		// this returns is only for the run's budget arithmetic; adding it to the
		// approval's cost as well charged each redraw twice — 22% of one real run.
		return $spent;
	}

	/**
	 * Everything one step needs in order to be asked, without asking yet.
	 *
	 * Returns either a finished outcome — for the step that calls no model, and
	 * for anything that failed before the wire — or a plan and the function that
	 * reads its answer back. Holding those apart is what lets a whole wave of
	 * independent steps go out at once.
	 */
	private static function prepare( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options, array $findings = array() ) {
		$capability = MSRWA_Engine_Steps::capability( $name, (array) $config->get( 'steps', array() ) );
		if ( 'none' === $capability ) { return self::apply_corrections( $result ); }
		if ( 'image_generation' === $capability ) { return self::draw( $name, $config, $result, $options, $findings ); }
		if ( 'vision' === $capability ) { return self::decide( $name, $config, $result, $options ); }
		return self::write( $name, $config, $result, $options );
	}

	/** Makes one prepared call and reads it back. */
	private static function settle( array $prepared ) {
		if ( ! isset( $prepared['plan'] ) ) { return $prepared; }
		$request = $prepared['plan']['request'];
		$raw = MSRWA_Engine_Call::http( $request['url'], $request['headers'], $request['payload'], (int) $request['timeout'] );
		return call_user_func( $prepared['finish'], MSRWA_Engine_Call::read( $prepared['plan'], $raw ) );
	}

	/** One step, asked and answered. */
	private static function call( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options, array $findings = array() ) {
		return self::settle( self::prepare( $name, $config, $result, $options, $findings ) );
	}

	/**
	 * One attempt at every step of a wave, with the calls made together.
	 *
	 * A wave is independent by construction — that is what `needs` declares — so
	 * the only thing the engine was gaining by asking one at a time was waiting.
	 * The article and both images are 138 seconds in a row and 71 together.
	 *
	 * Only the first attempt is shared. A step that has to be asked again is
	 * asked on its own, because by then it is no longer doing the same thing as
	 * the others.
	 */
	private static function attempt_wave( array $wave, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options ) {
		$registry = (array) $config->get( 'steps', array() );
		$prepared = array();
		foreach ( $wave as $name ) {
			$result->event( 'attempt', $name, sprintf( 'Attempt 1 of %d: %s', $config->attempts( $name ), MSRWA_Engine_Steps::get( $name, $registry )['label'] ) );
			$prepared[ $name ] = self::prepare( $name, $config, $result, $options );
		}

		$requests = array();
		foreach ( $prepared as $name => $plan ) {
			if ( isset( $plan['plan'] ) ) { $requests[ $name ] = $plan['plan']['request']; }
		}
		$answers = MSRWA_Engine_Call::http_many( $requests, (int) $config->get( 'limits.concurrency', 4 ) );

		$outcomes = array();
		foreach ( $prepared as $name => $plan ) {
			$outcomes[ $name ] = isset( $plan['plan'] )
				? call_user_func( $plan['finish'], MSRWA_Engine_Call::read( $plan['plan'], $answers[ $name ] ) )
				: $plan;
		}
		return $outcomes;
	}

	/**
	 * Applies the fact check's corrections to the article, in code.
	 *
	 * The fact check quotes the sentence it objects to verbatim and gives the
	 * sentence that replaces it, so this needs no model and no judgement: either
	 * the quoted sentence is in the article, and it is replaced exactly, or it is
	 * not, and the correction is reported for a person to make. Until this
	 * existed the findings were produced and then applied by hand.
	 *
	 * A review finding carries no quote — it is advice about a section — so none
	 * are applied here. They travel to the editor with the rest.
	 */
	private static function apply_corrections( MSRWA_Result $result ) {
		$article = (array) ( $result->artifacts['article'] ?? array() );
		$html = (string) ( $article['content_html'] ?? '' );
		$applied = array();
		$unapplied = array();

		foreach ( (array) ( ( (array) ( $result->artifacts['fact_check'] ?? array() ) )['corrections'] ?? array() ) as $correction ) {
			if ( ! is_array( $correction ) ) { continue; }
			$before = trim( (string) ( $correction['before'] ?? '' ) );
			if ( '' === $before || trim( (string) ( $correction['after'] ?? '' ) ) === $before ) { continue; }
			// The fact check answers in plain sentences while the article is HTML, so a
			// sentence broken by a tag cannot be substituted. Say so rather than guess.
			if ( false === mb_strpos( $html, $before ) ) { $unapplied[] = $correction; continue; }
			$html = self::substitute( $html, $before, (string) ( $correction['after'] ?? '' ) );
			$applied[] = $correction;
		}

		$article['content_html'] = $html;
		$checks = array(
			'every correction applied' => array( 'pass' => ! $unapplied, 'detail' => $unapplied ? count( $applied ) . ' applied, ' . count( $unapplied ) . ' could not be located in the HTML' : count( $applied ) . ' applied' ),
			'the article survived' => array( 'pass' => '' !== $html, 'detail' => mb_strlen( $html ) . ' characters' ),
		);
		return array(
			'provider' => '', 'model' => '', 'seconds' => 0, 'usage' => array(), 'cost_usd' => 0.0, 'status' => '',
			'passed' => count( array_filter( $checks, static function ( $check ) { return $check['pass']; } ) ), 'total' => count( $checks ),
			'checks' => $checks, 'error' => '', 'retry' => '',
			'artifact' => array_merge( $article, array( 'corrections_applied' => $applied, 'corrections_for_the_editor' => $unapplied ) ),
		);
	}

	/**
	 * One quoted sentence replaced in the article. An empty replacement removes
	 * it: the space before it goes too, and the paragraph if nothing else was in it.
	 */
	private static function substitute( $html, $before, $after ) {
		$before = trim( (string) $before );
		$after = trim( strip_tags( (string) $after ) );
		if ( '' === $after && false !== mb_strpos( $html, ' ' . $before ) ) { $before = ' ' . $before; }
		$html = str_replace( $before, $after, (string) $html );
		return '' === $after ? (string) preg_replace( '#<p>\s*</p>\s*#u', '', $html ) : $html;
	}

	/** A step that returns JSON: research, the recipe, the article and the reviews. */
	private static function write( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options ) {
		$route = $config->model_for( $name );
		if ( '' === $route['model'] ) { return self::failed( 'No model resolves for route "' . $route['route'] . '".' ); }

		$prompt = $config->prompt( $name );
		if ( '' === $prompt['text'] ) { return self::failed( 'No prompt for ' . $name . ' (' . $prompt['source'] . ').' ); }

		$brief = self::working_set( $name, $result );
		if ( 'research' === $name && $brief['images'] ) { $brief['image_observations'] = self::observe_editor_images( $brief['images'], $config, $result ); }
		$input = MSRWA_Engine_Input::build( $name, $prompt['text'], $brief, $options );
		$web_search = 'web_search' === MSRWA_Engine_Steps::capability( $name, (array) $config->get( 'steps', array() ) );
		$ceiling = $config->max_output( $name );

		$attached = self::attached( $brief );
		$result->event( 'input', $name, sprintf(
			'Prompt from %s, %s characters in, ceiling %s tokens, %s attached%s.',
			$prompt['source'], number_format( strlen( $input ) ), number_format( $ceiling ),
			$attached ? implode( ' + ', $attached ) : 'nothing', $web_search ? ', web search on' : ''
		), array( 'prompt_source' => $prompt['source'], 'prompt_chars' => strlen( $prompt['text'] ), 'input_chars' => strlen( $input ), 'attached' => $attached, 'ceiling' => $ceiling, 'web_search' => $web_search, 'route' => $route ) );

		$wire = $config->provider( $route['provider'], $route['model'], $name );
		$plan = MSRWA_Engine_Call::plan_text( $route['provider'], $route['model'], $input, $ceiling, true, $web_search, $wire );
		if ( isset( $plan['error'] ) ) { return self::failed( $plan['error'], 0, $route ); }

		return array( 'plan' => $plan, 'finish' => static function ( $call ) use ( $name, $route, $wire, $config, $result, $brief, $prompt, $input, $ceiling ) {
			if ( isset( $call['error'] ) ) { return self::failed( $call['error'], $call['seconds'] ?? 0, $route ); }
			self::report_call( $result, $name, $route, $wire['text_endpoint'] ?? '', $call, $config );

			// Each provider also says so outright; Gemini stops a few tokens short
			// of the ceiling, so the count alone missed it.
			if ( (int) ( $call['usage']['output_tokens'] ?? 0 ) >= $ceiling || in_array( strtolower( (string) ( $call['status'] ?? '' ) ), array( 'max_tokens', 'incomplete' ), true ) ) {
				$result->event( 'warning', $name, sprintf( 'Stopped on the %d-token ceiling; the answer is cut and was billed in full.', $ceiling ) );
			}

			$answer = MSRWA_Json::decode( $call['text'] );
			$answer = is_array( $answer ) ? $answer : array();

			// An answer that arrived and would not parse is the one failure the run
			// could not explain afterwards: the scorecard said "not parseable" and
			// the text itself was dropped. Keep enough of it to see why.
			$unparsed = '';
			if ( ! $answer && '' !== trim( (string) $call['text'] ) ) {
				$unparsed = mb_substr( (string) $call['text'], 0, 2000 );
				$result->event( 'warning', $name, sprintf( 'The answer arrived (%s characters) and did not parse as JSON. It opens: %s', number_format( mb_strlen( (string) $call['text'] ) ), mb_substr( trim( preg_replace( '/\s+/u', ' ', (string) $call['text'] ) ), 0, 160 ) ), array( 'unparsed' => $unparsed ) );
			}

			if ( 'research' === $name && $answer ) {
				$answer = self::observe( $answer, $config, $result, $call['usage'] );
				$call['text'] = (string) json_encode( $answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}

			$scores = MSRWA_Engine_Score::step( $name, $call['text'], $brief, $config->thresholds() );
			return array(
				'provider' => $route['provider'], 'model' => $route['model'], 'tier' => $route['tier'], 'seconds' => $call['seconds'],
				'usage' => $call['usage'], 'cost_usd' => $config->price( $route['provider'], $route['model'], $call['usage'] ),
				'status' => (string) ( $call['status'] ?? '' ), 'passed' => $scores['passed'], 'total' => $scores['total'],
				'checks' => $scores['checks'], 'error' => '', 'artifact' => $answer,
				'prompt' => $prompt['text'], 'prompt_source' => $prompt['source'], 'input_chars' => strlen( $input ), 'unparsed' => $unparsed,
				'retry' => $scores['pass'] ? '' : sprintf( 'Scored %d/%d; asking again.', $scores['passed'], $scores['total'] ),
			);
		} );
	}

	/**
	 * Replaces the search model's guesses about how the dish looks with what was
	 * read from the bytes of the photographs it cited.
	 *
	 * A search model describes a photograph it has not looked at. Every later
	 * prompt leans on these observations — the recipe, the article, both images
	 * and the final judgement — so they have to come from the real file. The
	 * vision calls are billed to the research step, because that is where they
	 * belong: without them the package is not finished.
	 */
	private static function observe( array $package, MSRWA_Engine_Config $config, MSRWA_Result $result, array &$usage ) {
		$route = $config->model_for( 'vision' );
		if ( '' === $route['model'] ) { return $package; }
		$limit = (int) $config->get( 'limits.images_inspected', 3 );
		$observed = MSRWA_Engine_Call::observe_images( $route['provider'], $route['model'], $package, $limit, $config->provider( $route['provider'], $route['model'], 'vision' ), (int) $config->get( 'limits.max_image_bytes', 10000000 ), (string) $config->get( 'vision_instruction', '' ) );
		$usage['input_tokens'] = (int) ( $usage['input_tokens'] ?? 0 ) + (int) ( $observed['usage']['input_tokens'] ?? 0 );
		$usage['output_tokens'] = (int) ( $usage['output_tokens'] ?? 0 ) + (int) ( $observed['usage']['output_tokens'] ?? 0 );
		$count = count( (array) ( $observed['package']['visual_observations'] ?? array() ) );
		$result->event( 'observe', 'research', sprintf( '%d of %d cited photographs read from their bytes.', $count, min( $limit, count( (array) ( $package['visual_references'] ?? array() ) ) ) ) );
		return $observed['package'];
	}

	/**
	 * What the editor's own images show, read from their bytes.
	 *
	 * An editor who attaches photographs is saying something about the dish that
	 * the title does not. Research is told what is in them rather than that they
	 * exist. Recorded on the run so a retry does not pay for the same look twice.
	 */
	private static function observe_editor_images( array $images, MSRWA_Engine_Config $config, MSRWA_Result $result ) {
		if ( isset( $result->artifacts['editor_observations'] ) ) { return (array) $result->artifacts['editor_observations']; }
		$route = $config->model_for( 'vision' );
		$observed = array();
		foreach ( array_slice( $images, 0, (int) $config->get( 'limits.images_inspected', 3 ) ) as $candidate ) {
			$url = is_array( $candidate ) ? (string) ( $candidate['image_url'] ?? '' ) : (string) $candidate;
			$image = MSRWA_Engine_Call::fetch_image( $url, (int) $config->get( 'limits.max_image_bytes', 10000000 ) );
			if ( isset( $image['error'] ) ) { $observed[] = array( 'image_url' => $url, 'uncertainties' => $image['error'] ); continue; }
			$vision = MSRWA_Engine_Call::vision( $route['provider'], $route['model'], $image, 'Image fournie par l’éditeur', (int) $config->max_output( 'vision' ), $config->provider( $route['provider'], $route['model'], 'vision' ), (string) $config->get( 'vision_instruction', '' ) );
			$decoded = MSRWA_Json::decode( (string) ( $vision['text'] ?? '' ) );
			$observed[] = is_array( $decoded ) ? array_merge( array( 'image_url' => $url ), $decoded ) : array( 'image_url' => $url, 'uncertainties' => 'Analyse visuelle non structurée.' );
		}
		$result->event( 'observe', 'research', count( $observed ) . ' editor-supplied image(s) read before searching.' );
		$result->artifact( 'editor_observations', $observed );
		return $observed;
	}

	/**
	 * A step that returns an image file.
	 *
	 * $findings, when given, are the defects the approval step raised against the
	 * previous attempt: passing them is what makes this a correction rather than
	 * another roll of the dice.
	 */
	private static function draw( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options, array $findings = array() ) {
		$kind = 'facebook_image' === $name ? 'facebook' : 'featured';
		$route = $config->model_for( $config->image_route( $name ) );
		$settings = MSRWA_Engine_Input::settings();
		$brief = self::working_set( $name, $result );

		MSRWA_Engine_Input::use_observation_phrases( (int) $config->get( 'limits.observation_phrases', 8 ) );
		MSRWA_Engine_Input::use_observation_fields( (array) $config->get( 'observation_fields', array( 'colours', 'textures' ) ) );
		$choices = array_merge( $options, array( 'collage_panels' => (int) $config->get( 'images.collage_panels', 6 ) ) );
		$prompt = MSRWA_Engine_Input::image_prompt( $kind, $brief, $choices, $findings );
		$ceiling = (int) $config->get( 'limits.image_prompt_chars', 30000 );
		if ( strlen( $prompt ) > $ceiling ) {
			// The provider refuses anything over 32000 characters, and did once the
			// research package grew. Fail where the cause is visible, not there.
			return self::failed( sprintf( 'The %s image prompt is %d characters, over the %d ceiling; trim what reaches it.', $kind, strlen( $prompt ), $ceiling ) );
		}

		$format = (string) $config->get( 'images.format', 'webp' );
		$size = MSRWA_Images::native_size( $config->get( 'images.' . $kind . '_ratio', 'featured' === $kind ? '1:1' : '2:3' ), (string) $config->get( 'images.' . $kind . '_size', 'featured' === $kind ? '1024x1024' : '1024x1536' ) );
		$quality = (string) $config->get( 'images.' . $kind . '_quality', MSRWA_Images::quality( $settings, $kind ) );
		$destination = self::workspace( $options ) . '/' . $kind . '-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( $prompt ), 0, 6 ) . '.' . $format;

		$result->event( 'input', $name, sprintf( '%s prompt, %s characters, %s at %s quality%s.', ucfirst( $kind ), number_format( strlen( $prompt ) ), $size, $quality, $findings ? ', correcting ' . count( $findings ) . ' finding(s)' : '' ), array( 'prompt_chars' => strlen( $prompt ), 'size' => $size, 'quality' => $quality, 'format' => $format, 'corrections' => count( $findings ), 'route' => $route ) );

		$wire = $config->provider( $route['provider'], $route['model'] );
		$plan = MSRWA_Engine_Call::plan_image( $prompt, $route['model'], $size, $quality, $format, $destination, $wire, $route['provider'] );
		if ( isset( $plan['error'] ) ) { return self::failed( $plan['error'], 0, $route ); }

		return array( 'plan' => $plan, 'finish' => static function ( $call ) use ( $name, $kind, $route, $wire, $config, $result, $prompt, $size, $quality, $format, $findings ) {
			if ( isset( $call['error'] ) ) { return self::failed( $call['error'], $call['seconds'] ?? 0, $route ); }
			self::report_call( $result, $name, $route, $wire['image_endpoint'] ?? '', $call, $config );
			return array(
				'step' => $name, 'provider' => $route['provider'], 'model' => $route['model'], 'tier' => $route['tier'], 'seconds' => $call['seconds'],
				'usage' => $call['usage'], 'cost_usd' => $config->price( $route['provider'], $route['model'], $call['usage'] ),
				'status' => '', 'passed' => null, 'total' => null, 'checks' => array(), 'error' => '', 'retry' => '',
				'artifact' => array(
					'kind' => $kind, 'path' => $call['path'], 'bytes' => $call['bytes'], 'mime' => 'image/' . $format,
					'size' => $size, 'quality' => $quality, 'format' => $format,
					'corrections' => array_values( $findings ), 'prompt' => $prompt,
				),
			);
		} );
	}

	/** The one step that sees the article and both images at once, and decides. */
	private static function decide( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options ) {
		$route = $config->model_for( $name );
		if ( '' === $route['model'] ) { return self::failed( 'No model resolves for route "' . $route['route'] . '".' ); }

		$prompt = $config->prompt( $name );
		if ( '' === $prompt['text'] ) { return self::failed( 'No prompt for ' . $name . ' (' . $prompt['source'] . ').' ); }

		$brief = self::working_set( $name, $result );

		// Judge what was actually produced. A caller that did not ask for a
		// collage has no collage to show, and failing the step for the absence
		// of something nobody ordered made a whole class of run unjudgeable. An
		// image that exists and cannot be read is still a failure: that is a
		// broken run, not a smaller one.
		$images = array();
		$targets = array( 'article' );
		foreach ( array( 'featured' => 'featured_image', 'facebook' => 'facebook_image' ) as $kind => $target ) {
			if ( empty( $result->artifacts[ $kind ] ) ) { continue; }
			$image = self::read_image( (array) $result->artifacts[ $kind ] );
			if ( isset( $image['error'] ) ) { return self::failed( $image['error'], 0, $route ); }
			$images[] = $image;
			$targets[] = $target;
		}
		// Three artifacts can disagree with one another; two cannot.
		if ( 2 === count( $images ) ) { $targets[] = 'consistency'; }

		// The judge is told what it has, in the same order the images are
		// attached, so "the list above is exhaustive" is true rather than a
		// hopeful instruction.
		$manifest = array();
		if ( in_array( 'featured_image', $targets, true ) ) {
			$manifest[] = '- the featured image, ' . (string) $config->get( 'images.featured_size', '1024x1024' ) . ';';
		}
		if ( in_array( 'facebook_image', $targets, true ) ) {
			$manifest[] = '- the Facebook image, ' . (string) $config->get( 'images.facebook_size', '1024x1536' )
				. ', a ' . (int) $config->get( 'images.collage_panels', 6 ) . '-panel preparation collage;';
		}
		$brief['images_received'] = $manifest ? implode( "\n", $manifest ) : '- no image at all: judge the text alone.';

		$input = MSRWA_Engine_Input::build( $name, $prompt['text'], $brief, $options );
		$bytes = 0;
		foreach ( $images as $image ) { $bytes += (int) $image['bytes']; }
		$result->event( 'input', $name, sprintf( 'Prompt from %s, %s characters and %d image(s) totalling %s KB.', $prompt['source'], number_format( strlen( $input ) ), count( $images ), number_format( $bytes / 1024, 1 ) ), array( 'prompt_source' => $prompt['source'], 'input_chars' => strlen( $input ), 'images' => count( $images ), 'image_bytes' => $bytes, 'attached' => self::attached( $brief ), 'route' => $route ) );

		$wire = $config->provider( $route['provider'], $route['model'], $name );
		$plan = MSRWA_Engine_Call::plan_judge( $route['provider'], $route['model'], $input, $images, $config->max_output( $name ), $wire );
		if ( isset( $plan['error'] ) ) { return self::failed( $plan['error'], 0, $route ); }

		return array( 'plan' => $plan, 'finish' => static function ( $call ) use ( $name, $route, $wire, $config, $result, $images, $targets, $prompt, $input ) {
			if ( isset( $call['error'] ) ) { return self::failed( $call['error'], $call['seconds'] ?? 0, $route ); }
			self::report_call( $result, $name, $route, $wire['text_endpoint'] ?? '', $call, $config );

			$verdict = MSRWA_Json::decode( $call['text'] );
			$verdict = is_array( $verdict ) ? $verdict : array();
			$configured = (array) $config->get( 'approval_targets', array() );
			$checks = MSRWA_Engine_Score::approval( $verdict, count( $images ), (int) $config->get( 'images.collage_panels', 6 ), $configured ? $configured : $targets );
			$passed = count( array_filter( $checks, static function ( $check ) { return ! empty( $check['pass'] ); } ) );

			// A verdict that fails its own structural contract is not a refusal, it is
			// a non-answer: a model that closes the root object early leaves the image
			// verdicts outside it, which reads as "refused, no findings". Ask again
			// rather than regenerate images against a decision nobody made.
			$gate = MSRWA_Engine_Score::accepts( $verdict, $checks );
			$sound = $gate['sound'];
			$approved = $gate['approved'];
			$redraw = $sound && ! $approved ? MSRWA_Engine_Score::images_to_retry( $verdict ) : array();
			$repairs = $sound && ! $approved ? MSRWA_Engine_Score::article_repairs( $verdict, (string) ( self::judged( $result )['content_html'] ?? '' ) ) : array();
			$article_blocked = (bool) MSRWA_Engine_Score::findings_for( $verdict, 'article' );
			$retry = '';
			if ( ! $sound ) {
				$retry = sprintf( 'The verdict is malformed (%d/%d contracts); asking again without touching the images.', $passed, count( $checks ) );
			} elseif ( $article_blocked && ! $repairs ) {
				// Redrawing images cannot approve an article that stays refused.
				$result->event( 'decision', $name, 'Refused on the article, which no retry here can change. The findings go to the editor.' );
			} elseif ( $redraw || $repairs ) {
				$work = $redraw ? array( 'regenerating ' . implode( ', ', $redraw ) ) : array();
				if ( $repairs ) { $work[] = sprintf( 'correcting %d sentence(s) of the article', count( $repairs ) ); }
				$retry = 'Refused; ' . implode( ' and ', $work ) . '.';
			} elseif ( ! $approved ) {
				// A refusal the engine cannot act on is a decision, not a failed attempt.
				// Asking the same judge the same question about the same artifacts is a
				// second roll of the dice, and it was costing two calls per run.
				$result->event( 'decision', $name, 'Refused on the article, which no retry here can change. The findings go to the editor.' );
			}

			return array(
				'provider' => $route['provider'], 'model' => $route['model'], 'tier' => $route['tier'], 'seconds' => $call['seconds'],
				'usage' => $call['usage'], 'cost_usd' => $config->price( $route['provider'], $route['model'], $call['usage'] ),
				'status' => (string) ( $call['status'] ?? '' ), 'passed' => $passed, 'total' => count( $checks ),
				'checks' => $checks, 'error' => '', 'artifact' => $verdict, 'approved' => $approved, 'retry' => $retry,
				'prompt' => $prompt['text'], 'prompt_source' => $prompt['source'], 'input_chars' => strlen( $input ),
			);
		} );
	}

	/**
	 * What a later version of the article actually filled in. A field it left
	 * empty keeps the earlier version's: a proofread that answered without its
	 * body had the final approval judge a blank article and left no draft.
	 */
	public static function filled( array $version ) {
		return array_filter( $version, static function ( $value ) { return is_array( $value ) ? (bool) $value : '' !== trim( (string) $value ); } );
	}

	/** The article the final approval judges: the latest version of each field. */
	private static function judged( MSRWA_Result $result ) {
		$article = (array) ( $result->artifacts['article'] ?? array() );
		foreach ( array( 'corrected', 'proofread' ) as $source ) {
			if ( ! empty( $result->artifacts[ $source ] ) ) { $article = array_merge( $article, self::filled( (array) $result->artifacts[ $source ] ) ); }
		}
		return $article;
	}

	/** The artifacts one step reads, under the names its input builder expects. */
	private static function working_set( $name, MSRWA_Result $result ) {
		$brief = (array) ( $result->artifacts['brief'] ?? array() );
		$article = (array) ( $result->artifacts['article'] ?? array() );

		// Each later step reads the latest article there is: the reviews read the
		// draft they are reviewing, proofreading reads the one the facts were fixed
		// in, and the approval judges what a reader would actually get.
		// Oldest first, so the newest version is the one that ends up on top.
		foreach ( array( 'corrected' => array( 'proofread', 'final_approval' ), 'proofread' => array( 'final_approval' ) ) as $source => $steps ) {
			if ( in_array( $name, $steps, true ) && ! empty( $result->artifacts[ $source ] ) ) {
				$article = array_merge( $article, self::filled( (array) $result->artifacts[ $source ] ) );
			}
		}
		// What was corrected is the editor's record, not the article: sent along, it
		// put every sentence already removed back in front of the judge, who then
		// refused an article over a sentence the proofread had already fixed.
		$article = array_diff_key( $article, array_flip( array( 'corrections_applied', 'corrections_for_the_editor', 'approval_repairs', 'changes', 'clean' ) ) );

		// A rewrite carries what the reviews found; a first draft carries nothing.
		$feedback = array();
		foreach ( array( 'review', 'fact_check' ) as $source ) {
			if ( ! empty( $result->artifacts[ $source ] ) ) { $feedback[ $source ] = $result->artifacts[ $source ]; }
		}

		return array_merge( $brief, array(
			'research'  => (array) ( $result->artifacts['research'] ?? array() ),
			'canonical' => (array) ( $result->artifacts['canonical'] ?? array() ),
			'article'   => $article,
			'feedback'  => $feedback,
		) );
	}

	/**
	 * Says what one provider call actually did, the moment it returns.
	 *
	 * A cost and a duration on the step is the summary; this is the detail
	 * underneath it — which endpoint answered, on which model and tier, how many
	 * tokens went each way, how many of them the provider served from its cache,
	 * and whether the answer stopped on the ceiling rather than finishing. A run
	 * that costs more than expected is answered from these, not guessed at.
	 */
	private static function report_call( MSRWA_Result $result, $name, array $route, $endpoint, array $call, MSRWA_Engine_Config $config ) {
		$usage = (array) ( $call['usage'] ?? array() );
		$in = (int) ( $usage['input_tokens'] ?? 0 );
		$out = (int) ( $usage['output_tokens'] ?? 0 );
		$cached = (int) ( $usage['cached_input_tokens'] ?? 0 );
		$cost = $config->price( $route['provider'], $route['model'], $usage );
		$result->event( 'call', $name, sprintf(
			'%s answered in %ss: %s in%s, %s out%s, %s.',
			$route['model'], $call['seconds'],
			number_format( $in ), $cached ? ' (' . number_format( $cached ) . ' cached, ' . round( 100 * $cached / max( 1, $in ) ) . '%)' : '',
			number_format( $out ), empty( $usage['thinking_tokens'] ) ? '' : ' (' . number_format( (int) $usage['thinking_tokens'] ) . ' thinking)',
			null === $cost ? 'no published rate' : sprintf( '$%.4f', $cost )
		), array(
			'provider' => $route['provider'], 'model' => $route['model'], 'tier' => $route['tier'],
			// The endpoint is recorded so a run through a gateway says so. It never
			// carries a key: the key travels in a header, which is not recorded.
			'endpoint' => MSRWA_Engine_Config::redact_url( $endpoint ), 'seconds' => $call['seconds'], 'usage' => $usage,
			'cached_ratio' => $in ? round( $cached / $in, 4 ) : 0.0,
			'cost_usd' => $cost, 'priced' => null !== $cost, 'status' => (string) ( $call['status'] ?? '' ),
			'thinking' => $config->thinking( $name, $route['provider'] ),
		) );
	}

	/** Which artifacts a step was actually given, for the record of what it saw. */
	private static function attached( array $brief ) {
		$attached = array();
		foreach ( array( 'research', 'canonical', 'article', 'feedback', 'image_observations' ) as $key ) {
			if ( ! empty( $brief[ $key ] ) ) { $attached[] = $key; }
		}
		return $attached;
	}

	/** Reads a generated image back; the judge needs the bytes, not a path. */
	private static function read_image( $artifact ) {
		$path = (string) ( $artifact['path'] ?? '' );
		if ( '' === $path || ! is_readable( $path ) ) { return array( 'error' => 'The ' . ( $artifact['kind'] ?? 'generated' ) . ' image is no longer readable at ' . ( '' !== $path ? $path : 'any path' ) . '.' ); }
		$bytes = (string) file_get_contents( $path );
		return array(
			'label' => ( 'facebook' === ( $artifact['kind'] ?? '' ) ? 'facebook collage, ' : 'featured, ' ) . (string) ( $artifact['size'] ?? '' ),
			'mime' => (string) ( $artifact['mime'] ?? 'image/webp' ), 'data' => base64_encode( $bytes ), 'bytes' => strlen( $bytes ), 'path' => $path,
		);
	}

	/** Where generated images are written. The plugin passes its uploads directory. */
	private static function workspace( array $options ) {
		$path = (string) ( $options['workspace'] ?? '' );
		if ( '' === $path ) { $path = rtrim( sys_get_temp_dir(), '/' ) . '/msrwa-engine'; }
		if ( ! is_dir( $path ) ) { @mkdir( $path, 0775, true ); }
		return rtrim( $path, '/' );
	}

	/** A call that never happened, in the shape of one that did. */
	private static function failed( $message, $seconds = 0, $route = array() ) {
		return array(
			'provider' => (string) ( $route['provider'] ?? '' ), 'model' => (string) ( $route['model'] ?? '' ),
			'seconds' => $seconds, 'usage' => array(), 'cost_usd' => 0.0, 'status' => '',
			'passed' => null, 'total' => null, 'checks' => array(), 'error' => $message, 'retry' => $message,
		);
	}

	/** Accepts the several shapes a brief arrives in and returns one. */
	private static function normalise_brief( array $input ) {
		$brief = isset( $input['brief'] ) && is_array( $input['brief'] ) ? $input['brief'] : $input;
		if ( isset( $brief['editor_input'] ) && is_array( $brief['editor_input'] ) ) { $brief = array_merge( $brief, $brief['editor_input'] ); }
		return array(
			'type' => (string) ( $brief['type'] ?? 'article' ),
			'title' => (string) ( $brief['title'] ?? '' ),
			'text' => (string) ( $brief['text'] ?? '' ),
			'images' => array_values( (array) ( $brief['images'] ?? array() ) ),
		);
	}
}
