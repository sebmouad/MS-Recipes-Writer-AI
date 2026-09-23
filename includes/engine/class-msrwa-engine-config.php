<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every knob the engine turns, and who gets to turn it.
 *
 * Three layers, each overriding the one before:
 *
 *   1. defaults()      — what the engine does when told nothing. Proven in the lab.
 *   2. the caller      — the plugin's stored configuration, or the lab's flags.
 *   3. the single run  — what this recipe asks for, for a retry at a higher tier.
 *
 * Layers two and three speak the engine's vocabulary, not the caller's: these
 * key names are the contract, and a caller with its own spelling translates on
 * its side. That direction is deliberate — the engine is the thing being
 * reused, so it cannot carry a mapping per caller.
 *
 * **Nothing the engine does is hardcoded.** Provider endpoints, model prices,
 * tiers, the step registry, the prompts, every scoring threshold and every
 * ceiling is a key below, and every one can be replaced by the caller. What is
 * written here is a default, not a law.
 *
 * The resolved values are recorded on the run, and so is where each came from,
 * so a report can answer "what was this run with, and who decided" months later
 * without guessing.
 */
final class MSRWA_Engine_Config {

	/** @var array The effective values, after all three layers. */
	private $values;

	/** @var array Per top-level key, which layer last wrote it. */
	private $provenance;

	private function __construct( array $values, array $provenance ) {
		$this->values = $values;
		$this->provenance = $provenance;
	}

	/**
	 * Engine defaults. These are the measured ones: the routing, ceilings and
	 * retry limits the lab proved, not aspirations.
	 */
	public static function defaults() {
		return array(
			// Which model does which job. A step asks for a capability; this decides who serves it.
			'routing' => array(
				'research'         => 'openai:medium',
				'canonical_recipe' => 'openai:medium',
				'article'          => 'openai:medium',
				'review'           => 'openai:medium',
				'fact_check'       => 'openai:medium',
				'proofread'        => 'openai:medium',
				'final_approval'   => 'openai:medium',
				'vision'           => 'openai:medium',
				'image'            => 'openai:gpt-image-2.5-flare',
			),

			/*
			 * How hard a model may think before it answers, per step: minimal, low,
			 * medium or high; '' leaves it to the provider (`providers.<name>.
			 * thinking_level`, else the provider's own default). Thinking is billed
			 * as output and, on Gemini and Claude, spent out of the output ceiling.
			 */
			'thinking' => array( 'default' => '' ),

			// Output ceilings. Every one of these has been too low at least once,
			// and a truncated answer is billed in full and scores nothing.
			'max_output' => array(
				'research'         => 12000,
				'canonical_recipe' => 4500,
				'article'          => 14500,
				// 3000 was too low: a review with a dozen findings stops exactly on it,
				// is cut mid-object, parses as nothing and is billed in full. That is
				// the sixth ceiling in this list to have been found that way.
				'review'           => 6000,
				'fact_check'       => 4000,
				'proofread'        => 14500,
				'final_approval'   => 14000,
				'vision'           => 1200,
			),

			// How many times a step may be asked again before the run gives up.
			'attempts' => array(
				'final_approval' => 3,
				'default'        => 1,
			),

			'images' => array(
				'featured_quality' => 'medium',
				'facebook_quality' => 'medium',
				'format'           => 'webp',
				'featured_ratio'   => '1:1',
				'facebook_ratio'   => '2:3',
				'featured_size'    => '1024x1024',
				'facebook_size'    => '1024x1536',
				'collage_panels'   => 6,
			),

			// A run stops rather than overspending. Zero means no ceiling.
			'limits' => array(
				'budget_usd'         => 0.0,
				'seconds'            => 0,
				'image_prompt_chars' => 30000,
				'images_inspected'   => 3,
				'http_timeout'       => 600,
				// How many provider calls a wave may have in flight. One means the
				// engine waits for each in turn, which is what it used to do.
				'concurrency'        => 4,
				'max_image_bytes'    => 10000000,
				'observation_phrases'=> 8,
				// Web searches one call may run, each billed on top of the tokens.
				// Uncapped, a live OpenAI research call ran 13 and cost $0.1525
				// against an estimate of $0.0504 that had assumed three. The
				// estimate prices exactly this many, so it is a ceiling and not a
				// guess. Gemini offers no cap; its grounding is priced the same way.
				'web_searches'       => 10,
			),

			'language' => 'fr',

			/*
			 * Which observation fields reach an image prompt. Colour and texture
			 * describe the food; observable_details and composition inventory the
			 * frame, which is how another site's watermark, a pair of hands and two
			 * different cooks' garnish were drawn into generated photographs.
			 */
			'observation_fields' => array( 'colours', 'textures' ),

			/*
			 * How each provider is reached. A new provider is a row here, not a
			 * branch in the transport: endpoint, how the key is presented, which
			 * environment variables carry it, and how it spells "search the web".
			 * {{model}} and {{key}} are substituted at call time.
			 */
			'providers' => array(
				'openai' => array(
					'text_endpoint'   => 'https://api.openai.com/v1/responses',
					'image_endpoint'  => 'https://api.openai.com/v1/images/generations',
					'headers'         => array( 'Content-Type: application/json', 'Authorization: Bearer {{key}}' ),
					'key_env'         => array( 'OPENAI_API_KEY', 'MSRWA_OPENAI_KEY' ),
					'web_search_tool' => array( 'type' => 'web_search' ),
					'web_search_usd'  => 0.01,
					// Cached input is billed at a tenth of the input rate on every
					// current model on OpenAI's pricing page.
					'cached_input_ratio' => 0.1,
				),
				'gemini' => array(
					'text_endpoint'   => 'https://generativelanguage.googleapis.com/v1beta/models/{{model}}:generateContent',
					'image_endpoint'  => '',
					'headers'         => array( 'Content-Type: application/json', 'x-goog-api-key: {{key}}' ),
					'key_env'         => array( 'GEMINI_API_KEY', 'MSRWA_GEMINI_KEY' ),
					'web_search_tool' => array( 'google_search' => array() ),
					'web_search_usd'  => 0.014,
					'thinking_level'  => 'low',
				),
				'claude' => array(
					'text_endpoint'   => 'https://api.anthropic.com/v1/messages',
					'image_endpoint'  => '',
					'headers'         => array( 'Content-Type: application/json', 'x-api-key: {{key}}', 'anthropic-version: 2023-06-01' ),
					'key_env'         => array( 'ANTHROPIC_API_KEY', 'MSRWA_CLAUDE_KEY' ),
					'web_search_tool' => array( 'type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3 ),
					'web_search_usd'  => 0.01,
				),
			),

			/*
			 * Published rates in USD per million tokens, as [input, output], read
			 * from each provider's own pricing page on 2026-09-20. A model with no
			 * row here is priced as unknown — never as free.
			 */
			'models' => array(
				'openai' => array(
					'gpt-5-nano' => array( 0.05, 0.40 ),
					'gpt-5.6-luna' => array( 0.20, 1.20 ),
					'gpt-5.6-sol' => array( 4.00, 20.00 ),
					'gpt-5.6-terra' => array( 2.00, 12.00 ),
					'gpt-5.4-mini' => array( 0.75, 4.50 ),
					'gpt-image-1-mini' => array( 2.00, 8.00 ),
					'gpt-image-2' => array( 5.00, 30.00 ),
					'gpt-image-2.5-flare' => array( 5.00, 30.00 ),
				),
				'gemini' => array(
					'gemini-3.1-flash-lite' => array( 0.25, 1.50 ),
					'gemini-2.5-flash-lite' => array( 0.10, 0.40 ),
					'gemini-2.5-flash' => array( 0.30, 2.50 ),
					'gemini-3.1-pro-preview' => array( 2.00, 12.00 ),
					'gemini-3.5-flash' => array( 1.50, 9.00 ),
					'gemini-2.5-flash-image' => array( 0.30, 2.50 ),
					'gemini-3.1-flash-image' => array( 0.50, 3.00 ),
					'gemini-3-pro-image' => array( 2.00, 12.00 ),
				),
				'claude' => array(
					'claude-haiku-4-5-20251001' => array( 1.00, 5.00 ),
					'claude-sonnet-5' => array( 2.00, 10.00 ),
					'claude-opus-5' => array( 5.00, 25.00 ),
				),
			),

			/** What `provider:low|medium|high` resolves to. */
			'tiers' => array(
				'low'    => array( 'openai' => 'gpt-5-nano', 'gemini' => 'gemini-3.1-flash-lite', 'claude' => 'claude-haiku-4-5-20251001' ),
				'medium' => array( 'openai' => 'gpt-5.6-luna', 'gemini' => 'gemini-3.5-flash', 'claude' => 'claude-sonnet-5' ),
				'high'   => array( 'openai' => 'gpt-5.6-sol', 'gemini' => 'gemini-3.1-pro-preview', 'claude' => 'claude-opus-5' ),
			),

			/*
			 * Overrides for the step registry in MSRWA_Engine_Steps. A caller can
			 * retune a step — its model ceiling lives in max_output, but its label,
			 * bucket, dependencies or prompt file are changed here — or add one.
			 */
			'steps' => array(),

			/*
			 * Prompt text, overriding the shipped templates. An empty string means
			 * "use includes/engine/prompts/<file>". The plugin stores the prompts an
			 * administrator has edited and passes them here, so what runs is what
			 * was approved, not what happens to be on disk.
			 */
			'prompts' => array(),

			/*
			 * What counts as passed. Every number here was put there by an answer
			 * that failed, and every one is the caller's to move.
			 */
			'thresholds' => array(
				'research_minimums'       => array( 'ingredients' => 4, 'preparation' => 4, 'references' => 2, 'visual_references' => 1, 'visual_observations' => 1, 'uncertainties' => 0 ),
				'research_outline_figures'=> 3,
				'article_accents_per_1000'=> 12,
				'article_closing_words'   => 60,
				'proofread_min_ratio'     => 0.7,
				'proofread_accent_slack'  => 1,
				'fact_check_max_fixes'    => 12,
			),

			// The prompt and quality settings the shared classes need. A caller that
			// has its own — the plugin — passes them here; empty means "what ships".
			'settings' => array(),
		);
	}

	/**
	 * Builds the effective configuration.
	 *
	 * `$caller` is what the plugin or the lab stores; `$run` is what this one
	 * recipe asks for. Both use the keys above. An unknown key is kept rather
	 * than rejected, so a newer caller and an older engine still work together.
	 */
	public static function create( array $caller = array(), array $run = array() ) {
		$values = self::defaults();
		$provenance = array();
		foreach ( array_keys( $values ) as $key ) { $provenance[ $key ] = 'engine'; }

		foreach ( array( 'caller' => $caller, 'run' => $run ) as $layer => $overrides ) {
			foreach ( $overrides as $key => $value ) {
				$values[ $key ] = is_array( $value ) && isset( $values[ $key ] ) && is_array( $values[ $key ] )
					? self::merge( $values[ $key ], $value )
					: $value;
				$provenance[ $key ] = isset( $provenance[ $key ] ) && 'engine' !== $provenance[ $key ] ? $provenance[ $key ] . '+' . $layer : $layer;
			}
		}

		// The top-level `language` was recorded on every run, shown on the
		// Moteur screen, and read by nothing: every prompt reads
		// `settings.site_language`. A caller that set one and not the other got
		// an article in the wrong language with no indication why.
		if ( ! empty( $values['language'] ) && empty( $values['settings']['site_language'] ) ) {
			$values['settings'] = (array) ( $values['settings'] ?? array() );
			$values['settings']['site_language'] = (string) $values['language'];
		}

		return new self( self::clamp( $values ), $provenance );
	}

	/**
	 * Merges one layer over another, one level deeper than array_merge.
	 *
	 * A caller changing one provider's endpoint, or one step's dependencies,
	 * must not have to restate the others. A list — a header set, a price pair —
	 * is replaced whole, because half a header set is not a header set.
	 */
	private static function merge( array $base, array $over ) {
		foreach ( $over as $key => $value ) {
			$base[ $key ] = is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! self::is_list( $value )
				? array_merge( $base[ $key ], $value )
				: $value;
		}
		return $base;
	}

	private static function is_list( array $value ) { return array_keys( $value ) === range( 0, count( $value ) - 1 ); }

	/** Keeps a hand-edited value inside what the providers actually accept. */
	private static function clamp( array $values ) {
		foreach ( (array) ( $values['max_output'] ?? array() ) as $step => $tokens ) {
			$values['max_output'][ $step ] = max( 256, min( 32000, (int) $tokens ) );
		}
		foreach ( (array) ( $values['attempts'] ?? array() ) as $step => $times ) {
			$values['attempts'][ $step ] = max( 1, min( 6, (int) $times ) );
		}
		$values['limits']['budget_usd'] = max( 0.0, (float) ( $values['limits']['budget_usd'] ?? 0 ) );
		$values['limits']['images_inspected'] = max( 0, min( 20, (int) ( $values['limits']['images_inspected'] ?? 3 ) ) );
		$values['limits']['http_timeout'] = max( 5, min( 3600, (int) ( $values['limits']['http_timeout'] ?? 600 ) ) );
		$values['limits']['concurrency'] = max( 1, min( 12, (int) ( $values['limits']['concurrency'] ?? 4 ) ) );
		return $values;
	}

	/** Reads a value by dotted path: `images.format`, `routing.article`. */
	public function get( $path, $fallback = null ) {
		$node = $this->values;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) { return $fallback; }
			$node = $node[ $key ];
		}
		return $node;
	}

	/** The provider and model a step runs on, resolved from `provider:tier` or `provider:model`. */
	public function model_for( $step ) {
		$route = (string) $this->get( 'routing.' . $step, $this->get( 'routing.article', 'openai:medium' ) );
		$parts = explode( ':', $route, 2 );
		$provider = $parts[0];
		$named = isset( $parts[1] ) ? $parts[1] : 'medium';
		$tiers = (array) $this->get( 'tiers', array() );
		$model = isset( $tiers[ $named ][ $provider ] ) ? (string) $tiers[ $named ][ $provider ] : ( isset( $tiers[ $named ] ) ? '' : $named );
		return array( 'provider' => $provider, 'model' => $model, 'route' => $route, 'tier' => isset( $tiers[ $named ] ) ? $named : '' );
	}

	/**
	 * How many web searches one call may run on a provider: `limits.web_searches`,
	 * or the provider's own tool cap when that is lower — Claude's tool ships
	 * with `max_uses` 3, and the lower of two ceilings is the one that binds.
	 */
	public function web_searches( $provider = '' ) {
		$limit = max( 1, (int) $this->get( 'limits.web_searches', 10 ) );
		$own = (int) ( ( (array) $this->get( 'providers.' . $provider . '.web_search_tool', array() ) )['max_uses'] ?? 0 );
		return $own > 0 ? min( $limit, $own ) : $limit;
	}

	/** The thinking levels the engine knows how to ask for. */
	public static function thinking_levels() { return array( 'minimal', 'low', 'medium', 'high' ); }

	/**
	 * How hard a step may think on a provider: the step's own level, else the
	 * `default`, else the provider's. '' means the provider decides.
	 */
	public function thinking( $step, $provider ) {
		$levels = (array) $this->get( 'thinking', array() );
		$level = (string) ( $levels[ $step ] ?? '' );
		if ( '' === $level ) { $level = (string) ( $levels['default'] ?? '' ); }
		if ( '' === $level ) { $level = (string) $this->get( 'providers.' . $provider . '.thinking_level', '' ); }
		return in_array( $level, self::thinking_levels(), true ) ? $level : '';
	}

	/**
	 * Output tokens a level adds to a call, over what the step's measured shape
	 * already holds. The shapes were measured at the providers' default
	 * reasoning, which is `medium`, so only a higher level adds anything; a
	 * lower one is not subtracted, because an estimate that reads low lets a lot
	 * past a ceiling it will break.
	 */
	public static function thinking_allowance( $level ) {
		$tokens = array( 'minimal' => 0, 'low' => 1000, 'medium' => 3000, 'high' => 6000 );
		return max( 0, ( $tokens[ $level ] ?? $tokens['medium'] ) - $tokens['medium'] );
	}

	/** How one provider is reached, with {{model}} and {{key}} resolved, and how hard `$step` may think on it. */
	public function provider( $name, $model = '', $step = '' ) {
		$provider = (array) $this->get( 'providers.' . $name, array() );
		if ( ! $provider ) { return array(); }
		$key = $this->key_for( $name, $provider );
		$replace = static function ( $text ) use ( $model, $key ) { return str_replace( array( '{{model}}', '{{key}}' ), array( rawurlencode( (string) $model ), $key ), (string) $text ); };
		$provider['text_endpoint'] = $replace( $provider['text_endpoint'] ?? '' );
		$provider['image_endpoint'] = $replace( $provider['image_endpoint'] ?? '' );
		$provider['headers'] = array_map( $replace, (array) ( $provider['headers'] ?? array() ) );
		$provider['timeout'] = (int) $this->get( 'limits.http_timeout', 600 );
		$provider['has_key'] = '' !== $key;
		$provider['thinking_level'] = $this->thinking( '' === (string) $step ? 'default' : (string) $step, $name );
		$provider['web_searches'] = $this->web_searches( $name );
		return $provider;
	}

	/**
	 * The API key for a provider: the caller's own, or the environment's.
	 *
	 * The lab keeps its keys in the environment; WordPress keeps them encrypted
	 * in its settings table and has no environment to read. So a caller may hand
	 * the key over directly under `settings.keys.<provider>`, and it wins — an
	 * administrator who typed a key into the plugin means that key, not whatever
	 * the server happens to have exported.
	 *
	 * `settings` is the one branch of the configuration that never reaches a
	 * stored record, which is why the key belongs there and nowhere else.
	 */
	private function key_for( $name, array $provider ) {
		$supplied = (string) $this->get( 'settings.keys.' . $name, '' );
		if ( '' !== trim( $supplied ) ) { return trim( $supplied ); }
		return MSRWA_Engine_Call::key( $name, (array) ( $provider['key_env'] ?? array() ) );
	}

	/**
	 * Price of one call in USD, or null when the model carries no published rate.
	 *
	 * Indexed directly rather than through get(): a dotted path splits on the
	 * dot, and model names contain them — `models.openai.gpt-5.6-luna` resolved
	 * to nothing, and a whole run reported as free.
	 */
	public function price( $provider, $model, $usage ) {
		$models = (array) $this->get( 'models', array() );
		$rate = $models[ $provider ][ $model ] ?? null;
		if ( ! is_array( $rate ) || 2 > count( $rate ) ) { return null; }
		// Every provider charges each web search on top of the tokens, and the
		// research step makes several.
		$searches = (int) ( $usage['web_searches'] ?? 0 ) * (float) ( $this->get( 'providers.' . $provider . '.web_search_usd', 0 ) );
		// Input read from the provider's cache is billed at a fraction of the
		// rate. Without a published ratio it is billed in full, which overstates
		// and never understates.
		$input = (int) ( $usage['input_tokens'] ?? 0 );
		$cached = min( $input, (int) ( $usage['cached_input_tokens'] ?? 0 ) );
		$ratio = min( 1.0, max( 0.0, (float) $this->get( 'providers.' . $provider . '.cached_input_ratio', 1.0 ) ) );
		return ( ( $input - $cached + $cached * $ratio ) * (float) $rate[0] + (int) ( $usage['output_tokens'] ?? 0 ) * (float) $rate[1] ) / 1000000 + $searches;
	}

	public function max_output( $step ) { return (int) $this->get( 'max_output.' . $step, 4000 ); }

	public function attempts( $step ) { return (int) $this->get( 'attempts.' . $step, $this->get( 'attempts.default', 1 ) ); }

	public function thresholds() { return (array) $this->get( 'thresholds', array() ); }

	/** The step registry, with whatever the caller changed applied over it. */
	public function steps() { return MSRWA_Engine_Steps::all( (array) $this->get( 'steps', array() ) ); }

	/**
	 * The prompt a step runs, from the caller's own text when it supplied any,
	 * otherwise from the template shipped beside the engine. Either way it is
	 * compiled against the settings before the step sees it.
	 */
	public function prompt( $step ) {
		$stored = trim( (string) $this->get( 'prompts.' . $step, '' ) );
		if ( '' === $stored ) {
			$registry = $this->steps();
			$file = MSRWA_Engine_Input::prompt_path( (string) ( $registry[ $step ]['prompt'] ?? ( $step . '.tpl.txt' ) ) );
			if ( ! is_readable( $file ) ) { return array( 'text' => '', 'source' => 'missing: ' . basename( $file ) ); }
			$stored = trim( (string) file_get_contents( $file ) );
			$source = 'template ' . basename( $file );
		} else {
			$source = 'caller';
		}
		return array( 'text' => MSRWA_Prompt::compile( $stored, $this->settings() ), 'source' => $source );
	}

	/**
	 * The prompt and quality settings the shared classes read: MSRWA_Prompt
	 * compiles templates against them and MSRWA_Quality scores against them.
	 * Empty when the engine runs alone, in which case the shipped defaults serve.
	 */
	public function settings() { return (array) $this->get( 'settings', array() ); }

	/** Which layer decided each top-level key: engine, caller or run. */
	public function provenance() { return $this->provenance; }

	/** Everything, for the record kept on the run. */
	public function to_array() {
		$values = $this->values;
		// Settings can hold API keys; the record never does. Neither do the
		// environment variable names, which say where a key is kept.
		unset( $values['settings'] );
		foreach ( array_keys( (array) ( $values['providers'] ?? array() ) ) as $name ) {
			unset( $values['providers'][ $name ]['key_env'] );
			$values['providers'][ $name ] = self::redact( $values['providers'][ $name ] );
		}
		$values['_provenance'] = $this->provenance;
		return $values;
	}

	/**
	 * Removes credentials a caller wrote into its own configuration.
	 *
	 * The shipped rows carry {{key}}, which is a placeholder and safe to record.
	 * A caller pointing at its own gateway may put a real token in a header or in
	 * an endpoint's query string or userinfo, and this object is written into
	 * saved runs and HTML reports that get shared.
	 */
	public static function redact( array $provider ) {
		foreach ( array( 'headers' ) as $key ) {
			foreach ( (array) ( $provider[ $key ] ?? array() ) as $index => $header ) {
				$provider[ $key ][ $index ] = preg_replace(
					'/^(\s*(?:authorization|x-api-key|api-key|x-goog-api-key|proxy-authorization)\s*:\s*)(?!\{\{key\}\}$)(?:bearer\s+)?\S.*$/i',
					'$1[redacted]', (string) $header
				);
			}
		}
		foreach ( array( 'text_endpoint', 'image_endpoint' ) as $key ) {
			$provider[ $key ] = self::redact_url( (string) ( $provider[ $key ] ?? '' ) );
		}
		return $provider;
	}

	/** An endpoint with any credential in its userinfo or query string removed. */
	public static function redact_url( $url ) {
		$url = (string) $url;
		if ( '' === $url ) { return ''; }
		$url = preg_replace( '#(://)[^/@\s]*:[^/@\s]*@#', '$1[redacted]@', $url );
		return preg_replace( '/([?&](?:key|api_key|apikey|access_token|token|secret)=)(?!\{\{key\}\})[^&\s]+/i', '$1[redacted]', $url );
	}
}
