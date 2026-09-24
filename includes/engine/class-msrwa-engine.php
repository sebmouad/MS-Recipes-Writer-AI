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
	 * are drawn, and the review reads the article once they are done. A wave's steps
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
	 * attempts. A refused approval is not a failed attempt: the images it blocked
	 * go to the editor with its findings, and are redrawn only if the editor asks.
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
		$extra = false;

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

			// Letters from another alphabet, or an answer that is not JSON at all,
			// are a slip, not a judgement: the step is asked once more even on its
			// last attempt. An unreadable research answer otherwise ended the
			// whole recipe on its only try.
			if ( '' !== $outcome['retry'] && $attempt >= $attempts && ! $extra && ( self::only_stray( $outcome ) || self::unreadable( $outcome ) ) ) {
				$extra = true;
				$attempts++;
				$result->event( 'retry', $name, self::unreadable( $outcome ) ? 'The answer was not readable JSON; asking once more.' : 'Characters from another alphabet in the answer; asking once more.' );
			}
			// A connection that dropped before any answer billed nothing and said
			// nothing about the prompt; one review lost that way ended a live
			// recipe with its article unchecked. It is asked once more.
			if ( self::dropped( $outcome ) && ! $extra ) {
				$extra = true;
				$attempts = max( $attempts, $attempt + 1 );
				$outcome['retry'] = 'The connection dropped before an answer; asking once more.';
				$result->event( 'retry', $name, $outcome['retry'] . ' (' . $outcome['error'] . ')' );
				continue;
			}
			if ( '' === $outcome['retry'] || $attempt >= $attempts ) { break; }
			$budget = (float) $config->get( 'limits.budget_usd', 0 );
			if ( $budget > 0 && $result->totals()['cost_usd'] + $spent + (float) $outcome['cost_usd'] / max( 1, $attempt ) > $budget ) {
				$result->event( 'decision', $name, sprintf( 'Not retried: another attempt would cross the $%.4f budget.', $budget ) );
				break;
			}
			$result->event( 'retry', $name, $outcome['retry'] );
		}

		if ( '' === $outcome['error'] && isset( $outcome['artifact'] ) ) { $result->artifact( $step['produces'], $outcome['artifact'] ); }
		unset( $outcome['artifact'], $outcome['retry'] );
		return $result->step( $name, $outcome );
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
		if ( 'none' === $capability ) { return 'proofread' === $name ? self::apply_language( $config, $result ) : self::apply_corrections( $result ); }
		// A redraw the editor asked for carries the judge's findings in the options.
		if ( 'image_generation' === $capability ) { return self::draw( $name, $config, $result, $options, $findings ? $findings : array_values( (array) ( $options['findings'] ?? array() ) ) ); }
		if ( 'vision' === $capability ) { return self::decide( $name, $config, $result, $options ); }
		return self::write( $name, $config, $result, $options );
	}

	/** Makes one prepared call and reads it back. */
	private static function settle( array $prepared ) {
		if ( ! isset( $prepared['plan'] ) ) { return $prepared; }
		$request = $prepared['plan']['request'];
		$raw = MSRWA_Engine_Call::http( $request['url'], $request['headers'], $request['payload'], (int) $request['timeout'], ! empty( $request['multipart'] ) );
		return call_user_func( $prepared['finish'], MSRWA_Engine_Call::read( $prepared['plan'], $raw ) );
	}

	/** One step, asked and answered. */
	private static function call( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options, array $findings = array() ) {
		return self::settle( self::prepare( $name, $config, $result, $options, $findings ) );
	}

	/** Whether the call never reached an answer: no HTTP status, nothing billed. */
	private static function dropped( array $outcome ) {
		return 0 === strpos( (string) ( $outcome['error'] ?? '' ), 'HTTP 0' ) || false !== strpos( (string) ( $outcome['error'] ?? '' ), ' HTTP 0:' );
	}

	/** Whether the answer could not be read as JSON at all. */
	private static function unreadable( array $outcome ) {
		$check = $outcome['checks']['valid JSON'] ?? null;
		return is_array( $check ) && empty( $check['pass'] );
	}

	/** Whether the only check an answer failed is the one for foreign letters. */
	private static function only_stray( array $outcome ) {
		$failed = array();
		foreach ( (array) ( $outcome['checks'] ?? array() ) as $name => $check ) {
			if ( is_array( $check ) && empty( $check['pass'] ) ) { $failed[] = (string) $name; }
		}
		return array( 'one alphabet' ) === $failed;
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
	 * Applies the review's factual corrections to the article, in code.
	 *
	 * The review quotes the sentence it objects to verbatim and gives the
	 * sentence that replaces it, so this needs no model and no judgement: either
	 * the quoted sentence is in the article, and it is replaced exactly, or it is
	 * not, and the correction is reported for a person to make. Until this
	 * existed the findings were produced and then applied by hand.
	 *
	 * A review finding carries no quote — it is advice about a section — so none
	 * are applied here. They travel to the editor with the rest, and the language
	 * changes wait for the proofread step, which applies them to this text.
	 */
	private static function apply_corrections( MSRWA_Result $result ) {
		$article = (array) ( $result->artifacts['article'] ?? array() );
		$html = (string) ( $article['content_html'] ?? '' );
		$applied = array();
		$unapplied = array();

		foreach ( (array) ( ( (array) ( $result->artifacts['review'] ?? array() ) )['corrections'] ?? array() ) as $correction ) {
			if ( ! is_array( $correction ) ) { continue; }
			$before = trim( (string) ( $correction['before'] ?? '' ) );
			if ( '' === $before || trim( (string) ( $correction['after'] ?? '' ) ) === $before ) { continue; }
			// The review answers in plain sentences while the article is HTML, so a
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
	 * The proofread article, built in code from the sentences the review changed.
	 *
	 * Rewriting a whole article to correct a few sentences was the single most
	 * expensive answer after the research, and twice it came back with the body
	 * missing. The review returns only what it changed, quoted verbatim, and each
	 * change is substituted here, on the text the facts were already fixed in. A
	 * change that touches a figure is refused, since the proofread may never alter
	 * one, and a quote that cannot be found is left for the editor.
	 */
	private static function apply_language( MSRWA_Engine_Config $config, MSRWA_Result $result ) {
		$brief = self::working_set( 'proofread', $result );
		$answer = self::proofread( (array) ( ( (array) ( $result->artifacts['review'] ?? array() ) )['changes'] ?? array() ), $brief, $result );
		$scores = MSRWA_Engine_Score::step( 'proofread', (string) json_encode( $answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $brief, $config->thresholds() );
		return array(
			'provider' => '', 'model' => '', 'seconds' => 0, 'usage' => array(), 'cost_usd' => 0.0, 'status' => '',
			'passed' => $scores['passed'], 'total' => $scores['total'], 'checks' => $scores['checks'], 'error' => '', 'retry' => '',
			'artifact' => $answer,
		);
	}

	private static function proofread( array $changes, array $brief, MSRWA_Result $result ) {
		$html = (string) ( MSRWA_Engine_Input::article( $brief )['content_html'] ?? '' );
		$applied = array();
		$skipped = array();
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) { continue; }
			$before = trim( (string) ( $change['before'] ?? '' ) );
			$after = trim( (string) ( $change['after'] ?? '' ) );
			if ( '' === $before || '' === $after || $before === $after ) { continue; }
			preg_match_all( '/\d+(?:[.,]\d+)?/', $before, $was );
			preg_match_all( '/\d+(?:[.,]\d+)?/', $after, $is );
			// Proofreaders list overlapping passages: once the first is applied the
			// second's wording is gone, and its correction is already in the text.
			if ( false === mb_strpos( $html, $before ) && false !== mb_strpos( $html, $after ) ) { continue; }
			if ( $was[0] !== $is[0] || false === mb_strpos( $html, $before ) ) { $skipped[] = $change; continue; }
			$html = self::substitute( $html, $before, $after );
			$applied[] = $change;
		}
		if ( $skipped ) {
			$result->event( 'warning', 'proofread', sprintf( '%d change(s) not applied: quoted text not found verbatim, or a figure would have changed.', count( $skipped ) ), array( 'skipped' => $skipped ) );
		}
		return array( 'content_html' => $html, 'changes' => $applied, 'clean' => ! $applied, 'changes_not_applied' => $skipped );
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

		$brief = self::working_set( $name, $result );
		// The editor's own photographs are the visual evidence when there are
		// any: the research is then written from them and the editor's text,
		// without a web search or the downloads of other people's photographs —
		// the three searches and their pages were most of a recipe's research
		// cost. `research.web_search` set to `always` searches regardless.
		$from_photographs = 'research' === $name && $brief['images'] && 'always' !== (string) $config->get( 'research.web_search', 'without_images' );
		$editor_usage = array( 'input_tokens' => 0, 'output_tokens' => 0 );
		if ( 'research' === $name && $brief['images'] ) { $brief['image_observations'] = self::observe_editor_images( $brief['images'], $config, $result, $editor_usage, $options ); }
		// Photographs that could not be read are no evidence: search after all.
		// One readable photograph is enough — it is the dish itself.
		if ( $from_photographs && ! self::readable( (array) ( $brief['image_observations'] ?? array() ) ) ) {
			$from_photographs = false;
			$result->event( 'warning', 'research', 'No editor photograph could be read; searching the web instead.' );
		}
		if ( $from_photographs ) { $brief['research_mode'] = 'photographs'; }

		$prompt = $config->prompt( $from_photographs ? 'research_photographs' : $name );
		if ( '' === $prompt['text'] ) { return self::failed( 'No prompt for ' . $name . ' (' . $prompt['source'] . ').' ); }
		$input = MSRWA_Engine_Input::build( $name, $prompt['text'], $brief, $options );
		$web_search = ! $from_photographs && 'web_search' === MSRWA_Engine_Steps::capability( $name, (array) $config->get( 'steps', array() ) );
		$ceiling = $config->max_output( $name );

		$attached = self::attached( $brief );
		$result->event( 'input', $name, sprintf(
			'Prompt from %s, %s characters in, ceiling %s tokens, %s attached%s.',
			$prompt['source'], number_format( strlen( $input ) ), number_format( $ceiling ),
			$attached ? implode( ' + ', $attached ) : 'nothing', $web_search ? ', web search on' : ''
		), array( 'prompt_source' => $prompt['source'], 'prompt_chars' => strlen( $prompt['text'] ), 'input_chars' => strlen( $input ), 'attached' => $attached, 'ceiling' => $ceiling, 'web_search' => $web_search, 'route' => $route ) );

		$wire = $config->provider( $route['provider'], $route['model'], $name );
		// What a provider can cache: the step's own prompt, the same on every
		// recipe, and the opening the recipe, the article and the review share —
		// one cache key for them, and a breakpoint after the research and after
		// the recipe for the provider that needs them marked.
		$wire['instructions'] = (string) $prompt['text'];
		if ( in_array( $name, array( 'canonical_recipe', 'article', 'review' ), true ) ) {
			$research = MSRWA_Engine_Input::shared_context( $brief );
			$recipe = MSRWA_Engine_Input::shared_context( $brief, true );
			$wire['cache_key'] = 'msrwa-' . substr( md5( $research ), 0, 24 );
			$wire['cache_breaks'] = array_values( array_filter( array( strlen( rtrim( $research ) ), 'canonical_recipe' === $name ? 0 : strlen( rtrim( $recipe ) ) ), static function ( $at ) use ( $input, $research ) { return $at > 0 && 0 === strpos( $input, rtrim( $research ) ); } ) );
		}
		$plan = MSRWA_Engine_Call::plan_text( $route['provider'], $route['model'], $input, $ceiling, true, $web_search, $wire );
		if ( isset( $plan['error'] ) ) { return self::failed( $plan['error'], 0, $route ); }

		return array( 'plan' => $plan, 'finish' => static function ( $call ) use ( $name, $route, $wire, $config, $result, $brief, $prompt, $input, $ceiling, $from_photographs, $editor_usage, $options ) {
			if ( isset( $call['error'] ) ) { return self::failed( $call['error'], $call['seconds'] ?? 0, $route ); }
			// Reading the editor's photographs is part of the research and billed to it.
			$call['usage']['input_tokens'] = (int) ( $call['usage']['input_tokens'] ?? 0 ) + $editor_usage['input_tokens'];
			$call['usage']['output_tokens'] = (int) ( $call['usage']['output_tokens'] ?? 0 ) + $editor_usage['output_tokens'];
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
				$answer = $from_photographs ? self::from_editor_photographs( $answer, $brief, $result ) : self::with_editor_photographs( self::observe( $answer, $config, $result, $call['usage'], $options ), $brief );
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
	private static function observe( array $package, MSRWA_Engine_Config $config, MSRWA_Result $result, array &$usage, array $options = array() ) {
		$route = $config->model_for( 'vision' );
		if ( '' === $route['model'] ) { return $package; }
		$limit = (int) $config->get( 'limits.web_images_inspected', 1 );
		$observed = MSRWA_Engine_Call::observe_images( $route['provider'], $route['model'], $package, $limit, $config->provider( $route['provider'], $route['model'], 'vision' ), (int) $config->get( 'limits.max_image_bytes', 10000000 ), (string) $config->get( 'vision_instruction', '' ), is_callable( $options['keep_image'] ?? null ) ? $options['keep_image'] : null );
		$usage['input_tokens'] = (int) ( $usage['input_tokens'] ?? 0 ) + (int) ( $observed['usage']['input_tokens'] ?? 0 );
		$usage['output_tokens'] = (int) ( $usage['output_tokens'] ?? 0 ) + (int) ( $observed['usage']['output_tokens'] ?? 0 );
		$count = count( (array) ( $observed['package']['visual_observations'] ?? array() ) );
		$result->event( 'observe', 'research', sprintf( '%d of %d cited photographs read from their bytes.', $count, min( $limit, count( (array) ( $package['visual_references'] ?? array() ) ) ) ) );
		return $observed['package'];
	}

	/**
	 * The editor's photographs as the package's visual evidence: they are the
	 * references and their readings the observations, in the shape the web
	 * package gives, so every later step reads them the same way.
	 */
	private static function from_editor_photographs( array $package, array $brief, MSRWA_Result $result ) {
		$package['references'] = array();
		$package['visual_references'] = array();
		$package['visual_observations'] = array();
		foreach ( (array) ( $brief['image_observations'] ?? array() ) as $observation ) {
			$url = (string) ( $observation['image_url'] ?? '' );
			// A photograph that could not be read is not evidence of anything.
			if ( '' === $url || array( 'image_url', 'uncertainties' ) === array_keys( $observation ) ) { continue; }
			$package['visual_references'][] = array( 'image_url' => $url, 'source_url' => 'editor', 'title' => 'Photographie fournie par l’éditeur', 'tier' => 1 );
			$package['visual_observations'][] = array_merge( $observation, array( 'image_url' => $url, 'source_url' => 'editor', 'tier' => 1 ) );
		}
		$result->event( 'observe', 'research', sprintf( 'Written from %d editor photograph(s) without a web search.', count( $package['visual_observations'] ) ) );
		return $package;
	}

	/**
	 * The editor's photographs ahead of the web's, when there were too few to
	 * write from alone: they are the dish itself, the web's are other cooks'.
	 */
	private static function with_editor_photographs( array $package, array $brief ) {
		$observations = self::readable( (array) ( $brief['image_observations'] ?? array() ) );
		if ( ! $observations ) { return $package; }
		$references = array();
		$observed = array();
		foreach ( $observations as $observation ) {
			$url = (string) $observation['image_url'];
			$references[] = array( 'image_url' => $url, 'source_url' => 'editor', 'title' => 'Photographie fournie par l’éditeur', 'tier' => 1 );
			$observed[] = array_merge( $observation, array( 'source_url' => 'editor', 'tier' => 1 ) );
		}
		$package['visual_references'] = array_merge( $references, (array) ( $package['visual_references'] ?? array() ) );
		$package['visual_observations'] = array_merge( $observed, (array) ( $package['visual_observations'] ?? array() ) );
		return $package;
	}

	/** Observations that are readings, not the note that a photograph could not be read. */
	private static function readable( array $observations ) {
		return array_values( array_filter( $observations, static function ( $observation ) {
			return is_array( $observation ) && '' !== (string) ( $observation['image_url'] ?? '' ) && array( 'image_url', 'uncertainties' ) !== array_keys( $observation );
		} ) );
	}

	/**
	 * What the editor's own images show, read from their bytes.
	 *
	 * An editor who attaches photographs is saying something about the dish that
	 * the title does not. Research is told what is in them rather than that they
	 * exist. Recorded on the run so a retry does not pay for the same look twice.
	 */
	private static function observe_editor_images( array $images, MSRWA_Engine_Config $config, MSRWA_Result $result, array &$usage = array(), array $options = array() ) {
		if ( isset( $result->artifacts['editor_observations'] ) ) { return (array) $result->artifacts['editor_observations']; }
		$route = $config->model_for( 'vision' );
		$observed = array();
		$max = (int) $config->get( 'limits.max_image_bytes', 10000000 );
		foreach ( array_slice( $images, 0, (int) $config->get( 'limits.images_inspected', 3 ) ) as $candidate ) {
			// The caller's own files are read the caller's way: an editor's upload
			// sits on the caller's disk, and its address need not be public or
			// HTTPS. Only an image the caller cannot read is fetched over HTTPS.
			// The plugin names it `url`; the engine's own shape is `image_url`.
			$url = is_array( $candidate ) ? (string) ( $candidate['image_url'] ?? $candidate['url'] ?? '' ) : (string) $candidate;
			// Read already by the caller, in this engine's own words: looking
			// again would bill the same photograph twice.
			if ( is_array( $candidate ) && ! empty( $candidate['observation'] ) && is_array( $candidate['observation'] ) ) {
				$observed[] = array_merge( array( 'image_url' => $url ), $candidate['observation'] );
				$given = ( $given ?? 0 ) + 1;
				continue;
			}
			$image = is_array( $candidate ) && is_callable( $options['read_image'] ?? null ) ? (array) call_user_func( $options['read_image'], $candidate, $max ) : array();
			if ( ! $image || isset( $image['error'] ) && '' !== $url && preg_match( '#^https://#i', $url ) ) { $image = MSRWA_Engine_Call::fetch_image( $url, $max ); }
			if ( isset( $image['error'] ) ) { $observed[] = array( 'image_url' => $url, 'uncertainties' => $image['error'] ); continue; }
			$vision = MSRWA_Engine_Call::vision( $route['provider'], $route['model'], $image, 'Image fournie par l’éditeur', (int) $config->max_output( 'vision' ), $config->provider( $route['provider'], $route['model'], 'vision' ), (string) $config->get( 'vision_instruction', '' ) );
			$usage['input_tokens'] = (int) ( $usage['input_tokens'] ?? 0 ) + (int) ( $vision['usage']['input_tokens'] ?? 0 );
			$usage['output_tokens'] = (int) ( $usage['output_tokens'] ?? 0 ) + (int) ( $vision['usage']['output_tokens'] ?? 0 );
			$decoded = MSRWA_Json::decode( (string) ( $vision['text'] ?? '' ) );
			$observed[] = is_array( $decoded ) ? array_merge( array( 'image_url' => $url ), $decoded ) : array( 'image_url' => $url, 'uncertainties' => 'Analyse visuelle non structurée.' );
		}
		$read = count( array_filter( $observed, static function ( $one ) { return array( 'image_url', 'uncertainties' ) !== array_keys( $one ); } ) );
		$given = (int) ( $given ?? 0 );
		$result->event( $read ? 'observe' : 'warning', 'research', $given
			? sprintf( '%d of %d editor-supplied image(s) read from their bytes, %d of them already read by the caller.', $read, count( $observed ), $given )
			: sprintf( '%d of %d editor-supplied image(s) read from their bytes.', $read, count( $observed ) ) );
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
		$template = $config->facebook_template();
		$choices = array_merge( $options, array( 'collage_panels' => $template['panels'], 'collage_columns' => $template['columns'], 'collage_rows' => $template['rows'], 'facebook_prompt' => $template['prompt'] ) );
		$prompt = MSRWA_Engine_Input::image_prompt( $kind, $brief, $choices, $findings );
		$references = array();
		$composed = array( 'cost_usd' => 0.0, 'seconds' => 0.0 );
		if ( 'facebook' === $kind && '' !== $template['compose'] && '' !== $template['brief'] ) {
			$composed = self::compose_collage( $name, $config, $result, $options, $brief, $template, $findings );
			if ( isset( $composed['error'] ) ) {
				// The written prompt is the better drawing, not the only one: the
				// template's own prompt still draws a sound collage.
				$result->event( 'warning', $name, 'Could not write the collage prompt (' . $composed['error'] . '); drawing from the template instead.' );
				$composed = array( 'cost_usd' => (float) ( $composed['cost_usd'] ?? 0 ), 'seconds' => (float) ( $composed['seconds'] ?? 0 ) );
			} else {
				$prompt = $composed['prompt'];
				$references = $composed['references'];
			}
		}
		$ceiling = (int) $config->get( 'limits.image_prompt_chars', 30000 );
		if ( strlen( $prompt ) > $ceiling ) {
			// The provider refuses anything over 32000 characters, and did once the
			// research package grew. Fail where the cause is visible, not there.
			return self::failed( sprintf( 'The %s image prompt is %d characters, over the %d ceiling; trim what reaches it.', $kind, strlen( $prompt ), $ceiling ) );
		}

		$format = (string) $config->get( 'images.format', 'webp' );
		$size = 'facebook' === $kind
			? MSRWA_Images::native_size( $template['ratio'], $template['size'] )
			: MSRWA_Images::native_size( $config->get( 'images.featured_ratio', '1:1' ), (string) $config->get( 'images.featured_size', '1024x1024' ) );
		$quality = (string) $config->get( 'images.' . $kind . '_quality', MSRWA_Images::quality( $settings, $kind ) );
		$destination = self::workspace( $options ) . '/' . $kind . '-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( $prompt ), 0, 6 ) . '.' . $format;

		$result->event( 'input', $name, sprintf( '%s prompt, %s characters, %s at %s quality%s.', ucfirst( $kind ), number_format( strlen( $prompt ) ), $size, $quality, $findings ? ', correcting ' . count( $findings ) . ' finding(s)' : '' ), array( 'prompt_chars' => strlen( $prompt ), 'size' => $size, 'quality' => $quality, 'format' => $format, 'corrections' => count( $findings ), 'route' => $route ) );

		$wire = $config->provider( $route['provider'], $route['model'] );
		$plan = MSRWA_Engine_Call::plan_image( $prompt, $route['model'], $size, $quality, $format, $destination, $wire, $route['provider'], $references );
		if ( isset( $plan['error'] ) ) { return self::failed( $plan['error'], 0, $route ); }

		return array( 'plan' => $plan, 'finish' => static function ( $call ) use ( $name, $kind, $route, $wire, $config, $result, $prompt, $size, $quality, $format, $findings, $composed, $references ) {
			if ( isset( $call['error'] ) ) { return self::failed( $call['error'], $call['seconds'] ?? 0, $route ); }
			self::report_call( $result, $name, $route, $references ? ( $wire['image_edit_endpoint'] ?? '' ) : ( $wire['image_endpoint'] ?? '' ), $call, $config );
			$cost = $config->price( $route['provider'], $route['model'], $call['usage'] );
			return array(
				'step' => $name, 'provider' => $route['provider'], 'model' => $route['model'], 'tier' => $route['tier'], 'seconds' => round( $call['seconds'] + (float) $composed['seconds'], 1 ),
				// Writing the prompt is part of drawing this image, and billed with it.
				'usage' => $call['usage'], 'cost_usd' => null === $cost ? null : $cost + (float) $composed['cost_usd'],
				'status' => '', 'passed' => null, 'total' => null, 'checks' => array(), 'error' => '', 'retry' => '',
				'artifact' => array(
					'kind' => $kind, 'path' => $call['path'], 'bytes' => $call['bytes'], 'mime' => 'image/' . $format,
					'size' => $size, 'quality' => $quality, 'format' => $format,
					'corrections' => array_values( $findings ), 'prompt' => $prompt,
				),
			);
		} );
	}

	/**
	 * Writes the collage's image prompt the way the owner's ChatGPT does: a text
	 * model reads his brief, the recipe and the reference image, and plans the
	 * panels. Written once per run; a redraw reuses it with the refusal's
	 * findings added, so a correction changes only what was refused.
	 *
	 * The reference is the editor's own photograph of the dish when there is
	 * one, otherwise the first readable style reference the caller configured.
	 */
	private static function compose_collage( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options, array $brief, array $template, array $findings ) {
		$reference = self::collage_reference( $config, $options, $brief );
		$written = (array) ( $result->artifacts['facebook_composed'] ?? array() );
		$spent = array( 'cost_usd' => 0.0, 'seconds' => 0.0 );
		if ( empty( $written['prompt'] ) ) {
			$route = $config->model_for( 'image_compose' );
			$wire = $config->provider( $route['provider'], $route['model'], 'image_compose' );
			$instruction = MSRWA_Prompt::compile( trim( (string) file_get_contents( MSRWA_Engine_Input::prompt_path( $template['compose'] ) ) ), MSRWA_Engine_Input::settings() );
			$message = MSRWA_Engine_Input::collage_brief( $brief, $template['brief'], $reference['source'] );
			$call = MSRWA_Engine_Call::compose( $route['provider'], $route['model'], $instruction, $message, $reference['shown'], $config->max_output( 'image_compose' ), $wire );
			if ( isset( $call['error'] ) ) { return array( 'error' => (string) $call['error'], 'seconds' => (float) ( $call['seconds'] ?? 0 ) ); }
			self::report_call( $result, $name, $route, $wire['text_endpoint'] ?? '', $call, $config );
			$spent = array( 'cost_usd' => (float) $config->price( $route['provider'], $route['model'], (array) ( $call['usage'] ?? array() ) ), 'seconds' => (float) ( $call['seconds'] ?? 0 ) );
			$text = trim( (string) ( $call['text'] ?? '' ) );
			if ( strlen( $text ) < 200 ) { return array( 'error' => 'the answer was too short to be a prompt', 'cost_usd' => $spent['cost_usd'], 'seconds' => $spent['seconds'] ); }
			$written = array( 'prompt' => $text, 'reference' => $reference['source'], 'reference_label' => $reference['label'], 'reference_file' => (string) ( $reference['file'] ?? '' ), 'model' => $route['model'] );
			$result->artifact( 'facebook_composed', $written );
			$result->event( 'input', $name, sprintf( 'Collage prompt written by %s from the recipe%s: %s characters.', $route['model'], '' !== $reference['source'] ? ' and ' . $reference['label'] : '', number_format( strlen( $text ) ) ) );
		}
		$prompt = (string) $written['prompt'];
		$findings = array_values( array_filter( $findings, 'is_array' ) );
		if ( $findings ) {
			$prompt .= "\n\nTHIS IMAGE WAS REFUSED. An independent editor inspected the previous attempt and listed what is wrong with it. Produce the same image with each of these corrected, and change nothing else:\n";
			foreach ( $findings as $index => $finding ) {
				$prompt .= ( $index + 1 ) . '. ' . trim( (string) ( $finding['reason'] ?? '' ) ) . ' — ' . trim( (string) ( $finding['fix'] ?? '' ) ) . "\n";
			}
		}
		return array( 'prompt' => $prompt, 'references' => $reference['image'] ? array( $reference['image'] ) : array() ) + $spent;
	}

	/**
	 * A reference image made small before it is sent: the model is billed for
	 * every tile it reads, and a style or a dish reads as well at a fraction of
	 * a photograph's size. Returned as it was when GD cannot shrink it.
	 */
	private static function shrink_reference( array $image, $pixels ) {
		$pixels = (int) $pixels;
		if ( $pixels <= 0 || ! function_exists( 'imagecreatefromstring' ) ) { return $image; }
		$source = @imagecreatefromstring( (string) base64_decode( (string) $image['data'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $source ) { return $image; }
		$width = imagesx( $source );
		$height = imagesy( $source );
		if ( max( $width, $height ) <= $pixels ) { return $image; }
		$scale = $pixels / max( $width, $height );
		$small = imagescale( $source, max( 1, (int) round( $width * $scale ) ), max( 1, (int) round( $height * $scale ) ) );
		ob_start();
		imagejpeg( $small, null, 88 );
		$bytes = (string) ob_get_clean();
		return '' === $bytes ? $image : array( 'mime' => 'image/jpeg', 'data' => base64_encode( $bytes ) );
	}

	/** The image a composed collage is drawn from: the editor's photograph, else a style reference. */
	/**
	 * What the collage is composed from: the owner's approved collage for the
	 * look, and the editor's photograph for the dish. An editor's photograph used
	 * to replace the approved collage outright, and a dim, flash-lit amateur
	 * photograph of a rôti Orloff gave a collage that looked nothing like the
	 * owner's. The photograph now shows the writer of the prompt what the dish
	 * is; the image is drawn from the approved collage alone. With no approved
	 * collage, the photograph is the reference, as before.
	 */
	private static function collage_reference( MSRWA_Engine_Config $config, array $options, array $brief ) {
		$pixels = (int) $config->get( 'limits.reference_pixels', 768 );
		$style = self::style_reference( $config );
		$editor = self::editor_reference( $config, $options, $brief );
		if ( $style['image'] ) { $style['image'] = self::shrink_reference( $style['image'], $pixels ); }
		if ( $editor ) { $editor = self::shrink_reference( $editor, $pixels ); }
		if ( $style['image'] && $editor ) {
			return array( 'source' => 'both', 'label' => 'a style reference and the editor\'s photograph', 'file' => $style['file'], 'image' => $style['image'], 'shown' => array( $style['image'], $editor ) );
		}
		if ( $style['image'] ) { return $style + array( 'shown' => array( $style['image'] ) ); }
		if ( $editor ) { return array( 'source' => 'editor', 'label' => 'the editor\'s photograph', 'file' => '', 'image' => $editor, 'shown' => array( $editor ) ); }
		return array( 'source' => '', 'label' => '', 'file' => '', 'image' => null, 'shown' => array() );
	}

	/** The editor's first readable photograph of the dish, or null. */
	private static function editor_reference( MSRWA_Engine_Config $config, array $options, array $brief ) {
		$max = (int) $config->get( 'limits.max_image_bytes', 10000000 );
		foreach ( array_slice( array_values( (array) ( $brief['images'] ?? array() ) ), 0, 1 ) as $candidate ) {
			$image = is_array( $candidate ) && is_callable( $options['read_image'] ?? null ) ? (array) call_user_func( $options['read_image'], $candidate, $max ) : array();
			$url = is_array( $candidate ) ? (string) ( $candidate['image_url'] ?? $candidate['url'] ?? '' ) : (string) $candidate;
			if ( ( ! $image || isset( $image['error'] ) ) && preg_match( '#^https://#i', $url ) ) { $image = MSRWA_Engine_Call::fetch_image( $url, $max ); }
			if ( $image && ! isset( $image['error'] ) && ! empty( $image['data'] ) ) { return array( 'mime' => (string) $image['mime'], 'data' => (string) $image['data'] ); }
		}
		return null;
	}

	/** The owner's first readable style reference. */
	private static function style_reference( MSRWA_Engine_Config $config ) {
		$max = (int) $config->get( 'limits.max_image_bytes', 10000000 );
		foreach ( (array) $config->get( 'images.style_references', array() ) as $path ) {
			$path = (string) $path;
			if ( '' === $path || ! is_readable( $path ) || filesize( $path ) > $max ) { continue; }
			$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$mime = (string) ( $size['mime'] ?? '' );
			if ( ! isset( MSRWA_Engine_Call::TYPES[ $mime ] ) ) { continue; }
			return array( 'source' => 'style', 'label' => 'a style reference', 'file' => basename( $path ), 'image' => array( 'mime' => $mime, 'data' => base64_encode( (string) file_get_contents( $path ) ) ) );
		}
		return array( 'source' => '', 'label' => '', 'file' => '', 'image' => null );
	}

	/** The one step that sees both images at once, and decides on them. */
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
		$targets = array();
		foreach ( array( 'featured' => 'featured_image', 'facebook' => 'facebook_image' ) as $kind => $target ) {
			if ( empty( $result->artifacts[ $kind ] ) ) { continue; }
			$image = self::read_image( (array) $result->artifacts[ $kind ] );
			if ( isset( $image['error'] ) ) { return self::failed( $image['error'], 0, $route ); }
			$images[] = $image;
			$targets[] = $target;
		}
		// Two images can disagree with one another; one cannot.
		if ( 2 === count( $images ) ) { $targets[] = 'consistency'; }
		// The text is the review's: with no image there is nothing left to judge.
		if ( ! $images ) { return self::failed( 'No image to judge: the final approval judges the images, and none was produced.', 0, $route ); }

		// The judge is told what it has, in the same order the images are
		// attached, so "the list above is exhaustive" is true rather than a
		// hopeful instruction.
		$manifest = array();
		if ( in_array( 'featured_image', $targets, true ) ) {
			$manifest[] = '- the featured image, ' . (string) $config->get( 'images.featured_size', '1024x1024' ) . ';';
		}
		if ( in_array( 'facebook_image', $targets, true ) ) {
			$template = $config->facebook_template();
			$manifest[] = '- the Facebook image, ' . $template['size']
				. ', a ' . $template['panels'] . '-panel preparation collage;';
		}
		$brief['images_received'] = implode( "\n", $manifest );

		$input = MSRWA_Engine_Input::build( $name, $prompt['text'], $brief, $options );
		$bytes = 0;
		foreach ( $images as $image ) { $bytes += (int) $image['bytes']; }
		$result->event( 'input', $name, sprintf( 'Prompt from %s, %s characters and %d image(s) totalling %s KB.', $prompt['source'], number_format( strlen( $input ) ), count( $images ), number_format( $bytes / 1024, 1 ) ), array( 'prompt_source' => $prompt['source'], 'input_chars' => strlen( $input ), 'images' => count( $images ), 'image_bytes' => $bytes, 'attached' => self::attached( $brief ), 'route' => $route ) );

		$wire = $config->provider( $route['provider'], $route['model'], $name );
		// The judge's prompt is the same on every recipe: a provider may cache it.
		$wire['instructions'] = (string) $prompt['text'];
		$plan = MSRWA_Engine_Call::plan_judge( $route['provider'], $route['model'], $input, $images, $config->max_output( $name ), $wire );
		if ( isset( $plan['error'] ) ) { return self::failed( $plan['error'], 0, $route ); }

		return array( 'plan' => $plan, 'finish' => static function ( $call ) use ( $name, $route, $wire, $config, $result, $images, $targets, $prompt, $input ) {
			if ( isset( $call['error'] ) ) { return self::failed( $call['error'], $call['seconds'] ?? 0, $route ); }
			self::report_call( $result, $name, $route, $wire['text_endpoint'] ?? '', $call, $config );

			$verdict = MSRWA_Json::decode( $call['text'] );
			$verdict = MSRWA_Engine_Score::enforce( is_array( $verdict ) ? $verdict : array(), (string) ( $config->get( 'settings', array() )['site_language'] ?? 'fr' ) );
			$configured = (array) $config->get( 'approval_targets', array() );
			$checks = MSRWA_Engine_Score::approval( $verdict, count( $images ), $config->facebook_template()['panels'], $configured ? $configured : $targets );
			$passed = count( array_filter( $checks, static function ( $check ) { return ! empty( $check['pass'] ); } ) );

			// A verdict that fails its own structural contract is not a refusal, it is
			// a non-answer: a model that closes the root object early leaves the image
			// verdicts outside it, which reads as "refused, no findings". Ask again
			// rather than regenerate images against a decision nobody made.
			$gate = MSRWA_Engine_Score::accepts( $verdict, $checks );
			$sound = $gate['sound'];
			$approved = $gate['approved'];
			$retry = '';
			if ( ! $sound ) {
				$retry = sprintf( 'The verdict is malformed (%d/%d contracts); asking again.', $passed, count( $checks ) );
			} elseif ( ! $approved ) {
				// Every test recipe redrew one image here and was judged again: a
				// quarter of its cost, spent before anybody decided the finding
				// mattered. The findings go to the editor, who can have the image
				// redrawn from them.
				$redraw = MSRWA_Engine_Score::images_to_retry( $verdict );
				$result->event( 'decision', $name, 'Refused' . ( $redraw ? ' on ' . implode( ', ', $redraw ) : '' ) . '. The findings go to the editor, who decides whether to redraw.', array( 'redraw' => $redraw ) );
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

	/** The artifacts one step reads, under the names its input builder expects. */
	private static function working_set( $name, MSRWA_Result $result ) {
		$brief = (array) ( $result->artifacts['brief'] ?? array() );
		$article = (array) ( $result->artifacts['article'] ?? array() );

		// Each later step reads the latest article there is: the review reads the
		// draft it is reviewing, proofreading reads the one the facts were fixed
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
		$article = array_diff_key( $article, array_flip( array( 'corrections_applied', 'corrections_for_the_editor', 'approval_repairs', 'changes', 'clean', 'changes_not_applied' ) ) );

		// A rewrite carries what the reviews found; a first draft carries nothing.
		$feedback = array();
		foreach ( array( 'review' ) as $source ) {
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
