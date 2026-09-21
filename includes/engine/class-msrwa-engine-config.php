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
 * its side before calling. That direction is deliberate — the engine is the
 * thing being reused, so it cannot carry a mapping per caller.
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
				// How many of the photographs research cites are downloaded and read.
				'images_inspected'   => 3,
			),

			'language' => 'fr',

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
		foreach ( array( $caller, $run ) as $layer ) {
			foreach ( $layer as $key => $value ) {
				$values[ $key ] = is_array( $value ) && isset( $values[ $key ] ) && is_array( $values[ $key ] )
					? array_merge( $values[ $key ], $value )
					: $value;
			}
		}
		return new self( self::clamp( $values ) );
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

	/**
	 * The prompt and quality settings the shared classes read: MSRWA_Prompt
	 * compiles templates against them and MSRWA_Quality scores against them.
	 * Empty when the engine runs alone, in which case the shipped defaults serve.
	 */
	public function settings() { return (array) $this->get( 'settings', array() ); }

	/** Everything, for the record kept on the run. */
	public function to_array() {
		$values = $this->values;
		// Settings can hold API keys; the record never does.
		unset( $values['settings'] );
		return $values;
	}
}
