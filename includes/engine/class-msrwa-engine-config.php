<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every knob the engine turns, and who gets to turn it.
 *
 * Three layers, each overriding the one before:
 *
 *   1. defaults()      — what the engine does when told nothing. Proven in the lab.
 *   2. the plugin      — the administrator's stored settings, passed in whole.
 *   3. the single run  — what this recipe asks for, for a retry at a higher tier.
 *
 * The result of resolving them is recorded on the run, so a report can always
 * answer "what was this actually run with", months later, without guessing.
 */
final class MSRWA_Engine_Config {

	/** @var array The effective values, after all three layers. */
	private $values;

	private function __construct( array $values ) { $this->values = $values; }

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

			// Output ceilings. Every one of these has been too low at least once,
			// and a truncated answer is billed in full and scores nothing.
			'max_output' => array(
				'research'         => 12000,
				'canonical_recipe' => 4500,
				'article'          => 14500,
				'review'           => 3000,
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
				'collage_panels'   => 6,
			),

			// A run stops rather than overspending. Zero means no ceiling.
			'limits' => array(
				'budget_usd'      => 0.0,
				'seconds'         => 0,
				'image_prompt_chars' => 30000,
			),

			'language' => 'fr',
		);
	}

	/**
	 * Builds the effective configuration.
	 *
	 * `$settings` is the plugin's stored settings, in its own vocabulary; `$run`
	 * is what this one recipe asks for. Unknown keys are ignored rather than
	 * rejected, so an older plugin and a newer engine still work together.
	 */
	public static function create( array $settings = array(), array $run = array() ) {
		$values = self::defaults();
		foreach ( array( self::from_settings( $settings ), $run ) as $layer ) {
			foreach ( $layer as $key => $value ) {
				$values[ $key ] = is_array( $value ) && isset( $values[ $key ] ) && is_array( $values[ $key ] )
					? array_merge( $values[ $key ], $value )
					: $value;
			}
		}
		return new self( self::clamp( $values ) );
	}

	/**
	 * Translates the plugin's settings into engine vocabulary.
	 *
	 * The plugin names things for administrators; the engine names them for
	 * steps. Keeping the mapping here means neither side has to learn the
	 * other's spelling, and a renamed setting breaks in one readable place.
	 */
	private static function from_settings( array $s ) {
		if ( ! $s ) { return array(); }
		$out = array();
		$max_output = array();
		foreach ( array(
			'research' => 'research_max_output_tokens',
			'canonical_recipe' => 'canonical_max_output_tokens',
			'article' => 'article_max_output_tokens',
			'review' => 'review_max_output_tokens',
			'proofread' => 'article_max_output_tokens',
			'final_approval' => 'approval_max_output_tokens',
			'vision' => 'vision_max_output_tokens',
		) as $step => $setting ) {
			if ( isset( $s[ $setting ] ) ) { $max_output[ $step ] = (int) $s[ $setting ]; }
		}
		if ( $max_output ) { $out['max_output'] = $max_output; }

		$images = array();
		foreach ( array(
			'featured_quality' => 'featured_image_quality',
			'facebook_quality' => 'facebook_image_quality',
			'format' => 'image_format',
			'featured_ratio' => 'featured_ratio',
			'facebook_ratio' => 'facebook_ratio',
			'collage_panels' => 'facebook_collage_steps',
		) as $key => $setting ) {
			if ( isset( $s[ $setting ] ) ) { $images[ $key ] = $s[ $setting ]; }
		}
		if ( $images ) { $out['images'] = $images; }

		$limits = array();
		if ( isset( $s['per_recipe_budget_usd'] ) ) { $limits['budget_usd'] = (float) $s['per_recipe_budget_usd']; }
		if ( $limits ) { $out['limits'] = $limits; }

		if ( isset( $s['site_language'] ) ) { $out['language'] = (string) $s['site_language']; }
		if ( isset( $s['max_corrections'] ) ) { $out['attempts'] = array( 'final_approval' => max( 1, (int) $s['max_corrections'] + 1 ) ); }

		// The settings themselves travel too: the prompts live there.
		$out['settings'] = $s;
		return $out;
	}

	/** Keeps a hand-edited value inside what the providers actually accept. */
	private static function clamp( array $values ) {
		foreach ( (array) ( $values['max_output'] ?? array() ) as $step => $tokens ) {
			$values['max_output'][ $step ] = max( 256, min( 32000, (int) $tokens ) );
		}
		foreach ( (array) ( $values['attempts'] ?? array() ) as $step => $times ) {
			$values['attempts'][ $step ] = max( 1, min( 6, (int) $times ) );
		}
		$values['limits']['budget_usd'] = max( 0.0, (float) ( $values['limits']['budget_usd'] ?? 0 ) );
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
		$model = in_array( $named, array( 'low', 'medium', 'high' ), true ) ? MSRWA_Engine_Rates::model( $provider, $named ) : $named;
		return array( 'provider' => $provider, 'model' => $model, 'route' => $route );
	}

	public function max_output( $step ) { return (int) $this->get( 'max_output.' . $step, 4000 ); }

	public function attempts( $step ) { return (int) $this->get( 'attempts.' . $step, $this->get( 'attempts.default', 1 ) ); }

	/** The plugin's settings, which carry the prompts. Empty when the engine runs alone. */
	public function settings() { return (array) $this->get( 'settings', array() ); }

	/** Everything, for the record kept on the run. */
	public function to_array() {
		$values = $this->values;
		// Settings can hold API keys; the record never does.
		unset( $values['settings'] );
		return $values;
	}
}
