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

		$only = array_values( array_filter( (array) ( $options['only'] ?? array() ) ) );
		$remaining = $only ? array_values( array_intersect( MSRWA_Engine_Steps::names(), $only ) ) : MSRWA_Engine_Steps::names();
		foreach ( (array) ( $input['artifacts'] ?? array() ) as $key => $value ) { $result->artifact( $key, $value ); }
		$budget = (float) $config->get( 'limits.budget_usd', 0 );

		while ( $remaining ) {
			$wave = MSRWA_Engine_Steps::ready( $result->artifacts, $remaining );
			if ( ! $wave ) {
				foreach ( $remaining as $name ) {
					$result->fail( $name, 'Waiting on ' . implode( ', ', MSRWA_Engine_Steps::missing( $name, $result->artifacts ) ) . ', which never arrived.' );
				}
				break;
			}

			$result->event( 'wave', 'run', count( $wave ) > 1 ? 'These may run together: ' . implode( ', ', $wave ) : 'Next: ' . $wave[0], array( 'steps' => $wave ) );

			foreach ( $wave as $name ) {
				if ( $budget > 0 && $result->totals()['cost_usd'] >= $budget ) {
					$result->fail( $name, sprintf( 'Stopped at the $%.4f budget, before running %s.', $budget, $name ) );
					break 2;
				}
				self::perform( $name, $config, $result, $options );
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
	private static function perform( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options ) {
		$step = MSRWA_Engine_Steps::get( $name );
		if ( ! $step ) { return $result->fail( $name, 'Unknown step.' ); }

		$missing = MSRWA_Engine_Steps::missing( $name, $result->artifacts );
		if ( $missing ) { return $result->fail( $name, 'Cannot run without ' . implode( ', ', $missing ) . '.' ); }

		$attempts = $config->attempts( $name );
		$spent = 0.0;
		$seconds = 0.0;
		$outcome = array();

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$result->event( 'attempt', $name, sprintf( 'Attempt %d of %d: %s', $attempt, $attempts, $step['label'] ) );
			$outcome = self::call( $name, $config, $result, $options );
			$spent += (float) $outcome['cost_usd'];
			$seconds += (float) $outcome['seconds'];
			$outcome['cost_usd'] = round( $spent, 6 );
			$outcome['seconds'] = round( $seconds, 1 );
			$outcome['attempts'] = $attempt;

			if ( '' === $outcome['retry'] || $attempt >= $attempts ) { break; }
			$result->event( 'retry', $name, $outcome['retry'] );
			$spent += self::before_retry( $name, $outcome, $config, $result, $options );
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
	private static function before_retry( $name, array $outcome, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options ) {
		if ( 'final_approval' !== $name || ! is_array( $outcome['artifact'] ?? null ) ) { return 0.0; }
		$verdict = $outcome['artifact'];
		$spent = 0.0;
		foreach ( MSRWA_Engine_Score::images_to_retry( $verdict ) as $kind ) {
			$findings = MSRWA_Engine_Score::findings_for( $verdict, $kind . '_image' );
			if ( 'facebook' === $kind ) { $findings = array_merge( $findings, MSRWA_Engine_Score::findings_for( $verdict, 'consistency' ) ); }
			$redrawn = self::draw( $kind . '_image', $config, $result, $options, $findings );
			$spent += (float) $redrawn['cost_usd'];
			if ( '' !== $redrawn['error'] ) {
				$result->fail( $kind . '_image', 'Could not redraw for the approval: ' . $redrawn['error'] );
				continue;
			}
			$result->artifact( $kind, $redrawn['artifact'] );
			$result->step( $kind . '_image', array_diff_key( $redrawn, array( 'artifact' => 1, 'retry' => 1 ) ) );
		}
		return $spent;
	}

	/** One step, routed by what it asks a model for — or by asking none. */
	private static function call( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options ) {
		$capability = MSRWA_Engine_Steps::capability( $name );
		if ( 'none' === $capability ) { return self::apply_corrections( $result ); }
		if ( 'image_generation' === $capability ) { return self::draw( $name, $config, $result, $options, array() ); }
		if ( 'vision' === $capability ) { return self::decide( $name, $config, $result, $options ); }
		return self::write( $name, $config, $result, $options );
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
			$after = trim( (string) ( $correction['after'] ?? '' ) );
			if ( '' === $before || $before === $after ) { continue; }
			// The fact check answers in plain sentences while the article is HTML, so a
			// sentence broken by a tag cannot be substituted. Say so rather than guess.
			if ( false === mb_strpos( $html, $before ) ) { $unapplied[] = $correction; continue; }
			$html = str_replace( $before, $after, $html );
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

	/** A step that returns JSON: research, the recipe, the article and the three reviews. */
	private static function write( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options ) {
		$route = $config->model_for( $name );
		if ( '' === $route['model'] ) { return self::failed( 'No model resolves for route "' . $route['route'] . '".' ); }

		$prompt = self::prompt( $name, $config );
		if ( '' === $prompt ) { return self::failed( 'No prompt template for ' . $name . '.' ); }

		$brief = self::working_set( $name, $result );
		if ( 'research' === $name && $brief['images'] ) { $brief['image_observations'] = self::observe_editor_images( $brief['images'], $config, $result ); }
		$input = MSRWA_Engine_Input::build( $name, $prompt, $brief, $options );
		$tools = 'web_search' === MSRWA_Engine_Steps::capability( $name ) ? array( array( 'type' => 'web_search' ) ) : array();
		$ceiling = $config->max_output( $name );

		$call = MSRWA_Engine_Call::text( $route['provider'], $route['model'], $input, $ceiling, true, $tools );
		if ( isset( $call['error'] ) ) { return self::failed( $call['error'], $call['seconds'] ?? 0, $route ); }

		if ( (int) ( $call['usage']['output_tokens'] ?? 0 ) >= $ceiling ) {
			$result->event( 'warning', $name, sprintf( 'Stopped on the %d-token ceiling; the answer is cut and was billed in full.', $ceiling ) );
		}

		$answer = MSRWA_Json::decode( $call['text'] );
		$answer = is_array( $answer ) ? $answer : array();

		if ( 'research' === $name && $answer ) {
			$answer = self::observe( $answer, $config, $result, $call['usage'] );
			$call['text'] = (string) json_encode( $answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$scores = MSRWA_Engine_Score::step( $name, $call['text'], $brief );
		return array(
			'provider' => $route['provider'], 'model' => $route['model'], 'seconds' => $call['seconds'],
			'usage' => $call['usage'], 'cost_usd' => (float) MSRWA_Engine_Rates::price( $route['provider'], $route['model'], $call['usage'] ),
			'status' => (string) ( $call['status'] ?? '' ), 'passed' => $scores['passed'], 'total' => $scores['total'],
			'checks' => $scores['checks'], 'error' => '', 'artifact' => $answer,
			'retry' => $scores['pass'] ? '' : sprintf( 'Scored %d/%d; asking again.', $scores['passed'], $scores['total'] ),
		);
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
		$observed = MSRWA_Engine_Call::observe_images( $route['provider'], $route['model'], $package, $limit );
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
			$image = MSRWA_Engine_Call::fetch_image( $url );
			if ( isset( $image['error'] ) ) { $observed[] = array( 'image_url' => $url, 'uncertainties' => $image['error'] ); continue; }
			$vision = MSRWA_Engine_Call::vision( $route['provider'], $route['model'], $image, 'Image fournie par l’éditeur' );
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
		$route = $config->model_for( 'image' );
		$settings = MSRWA_Engine_Input::settings();
		$brief = self::working_set( $name, $result );

		$choices = array_merge( $options, array( 'collage_panels' => (int) $config->get( 'images.collage_panels', 6 ) ) );
		$prompt = MSRWA_Engine_Input::image_prompt( $kind, $brief, $choices, $findings );
		$ceiling = (int) $config->get( 'limits.image_prompt_chars', 30000 );
		if ( strlen( $prompt ) > $ceiling ) {
			// The provider refuses anything over 32000 characters, and did once the
			// research package grew. Fail where the cause is visible, not there.
			return self::failed( sprintf( 'The %s image prompt is %d characters, over the %d ceiling; trim what research_for_image() forwards.', $kind, strlen( $prompt ), $ceiling ) );
		}

		$format = (string) $config->get( 'images.format', 'webp' );
		$size = MSRWA_Images::native_size( $config->get( 'images.' . $kind . '_ratio', 'featured' === $kind ? '1:1' : '2:3' ), 'featured' === $kind ? '1024x1024' : '1024x1536' );
		$quality = (string) $config->get( 'images.' . $kind . '_quality', MSRWA_Images::quality( $settings, $kind ) );
		$destination = self::workspace( $options ) . '/' . $kind . '-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( $prompt ), 0, 6 ) . '.' . $format;

		$call = MSRWA_Engine_Call::image( $prompt, $route['model'], $size, $quality, $format, $destination );
		if ( isset( $call['error'] ) ) { return self::failed( $call['error'], $call['seconds'] ?? 0, $route ); }

		return array(
			'step' => $name, 'provider' => $route['provider'], 'model' => $route['model'], 'seconds' => $call['seconds'],
			'usage' => $call['usage'], 'cost_usd' => (float) MSRWA_Engine_Rates::price( $route['provider'], $route['model'], $call['usage'] ),
			'status' => '', 'passed' => null, 'total' => null, 'checks' => array(), 'error' => '', 'retry' => '',
			'artifact' => array(
				'kind' => $kind, 'path' => $call['path'], 'bytes' => $call['bytes'], 'mime' => 'image/' . $format,
				'size' => $size, 'quality' => $quality, 'format' => $format,
				'corrections' => array_values( $findings ), 'prompt' => $prompt,
			),
		);
	}

	/** The one step that sees the article and both images at once, and decides. */
	private static function decide( $name, MSRWA_Engine_Config $config, MSRWA_Result $result, array $options ) {
		$route = $config->model_for( $name );
		if ( '' === $route['model'] ) { return self::failed( 'No model resolves for route "' . $route['route'] . '".' ); }

		$prompt = self::prompt( $name, $config );
		if ( '' === $prompt ) { return self::failed( 'No prompt template for ' . $name . '.' ); }

		$brief = self::working_set( $name, $result );
		$images = array();
		foreach ( array( 'featured', 'facebook' ) as $kind ) {
			$image = self::read_image( $result->artifacts[ $kind ] ?? array() );
			if ( isset( $image['error'] ) ) { return self::failed( $image['error'], 0, $route ); }
			$images[] = $image;
		}

		$call = MSRWA_Engine_Call::judge( $route['provider'], $route['model'], MSRWA_Engine_Input::build( $name, $prompt, $brief, $options ), $images, $config->max_output( $name ) );
		if ( isset( $call['error'] ) ) { return self::failed( $call['error'], $call['seconds'] ?? 0, $route ); }

		$verdict = MSRWA_Json::decode( $call['text'] );
		$verdict = is_array( $verdict ) ? $verdict : array();
		$checks = MSRWA_Engine_Score::approval( $verdict, count( $images ), (int) $config->get( 'images.collage_panels', 6 ) );
		$passed = count( array_filter( $checks, static function ( $check ) { return ! empty( $check['pass'] ); } ) );

		// A verdict that fails its own structural contract is not a refusal, it is a
		// non-answer: a model that closes the root object early leaves the image
		// verdicts outside it, which reads as "refused, no findings". Ask again
		// rather than regenerate images against a decision nobody made.
		$sound = ! empty( $checks['valid JSON']['pass'] ) && ! empty( $checks['a verdict per artifact']['pass'] ) && ! empty( $checks['a refusal is justified']['pass'] );
		$approved = $sound && ! empty( $verdict['approved'] );
		$redraw = $sound && ! $approved ? MSRWA_Engine_Score::images_to_retry( $verdict ) : array();
		$retry = '';
		if ( ! $sound ) {
			$retry = sprintf( 'The verdict is malformed (%d/%d contracts); asking again without touching the images.', $passed, count( $checks ) );
		} elseif ( $redraw ) {
			$retry = 'Refused; regenerating ' . implode( ', ', $redraw ) . '.';
		} elseif ( ! $approved ) {
			// A refusal the engine cannot act on is a decision, not a failed attempt.
			// Asking the same judge the same question about the same artifacts is a
			// second roll of the dice, and it was costing two calls per run.
			$result->event( 'decision', $name, 'Refused on the article, which no retry here can change. The findings go to the editor.' );
		}

		return array(
			'provider' => $route['provider'], 'model' => $route['model'], 'seconds' => $call['seconds'],
			'usage' => $call['usage'], 'cost_usd' => (float) MSRWA_Engine_Rates::price( $route['provider'], $route['model'], $call['usage'] ),
			'status' => (string) ( $call['status'] ?? '' ), 'passed' => $passed, 'total' => count( $checks ),
			'checks' => $checks, 'error' => '', 'artifact' => $verdict, 'approved' => $approved, 'retry' => $retry,
		);
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
				$article = array_merge( $article, (array) $result->artifacts[ $source ] );
			}
		}

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

	/** A step's prompt template, compiled against the settings in force. */
	private static function prompt( $name, MSRWA_Engine_Config $config ) {
		$file = MSRWA_Engine_Input::prompt_path( MSRWA_Engine_Steps::get( $name )['prompt'] ?? ( $name . '.tpl.txt' ) );
		if ( ! is_readable( $file ) ) { return ''; }
		return MSRWA_Prompt::compile( trim( (string) file_get_contents( $file ) ), MSRWA_Engine_Input::settings() );
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
