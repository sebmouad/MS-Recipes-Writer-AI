<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What a lot is likely to cost, before a penny is spent.
 *
 * Built from the configuration that will actually run it — the routes, the
 * models, the output ceilings and the published rates the engine itself
 * resolves — rather than from a figure typed into this file. Change a route on
 * the Moteur screen and this follows.
 *
 * It is an estimate and says so everywhere it appears. Two numbers are worth
 * more than one: what a lot is likely to cost, and the ceiling beyond which it
 * stops. The first can be wrong; the second cannot be exceeded.
 */
final class MSRWA_Estimate {

	/** Rough output tokens per step, from what real runs actually produced. */
	private static function shape() {
		return array(
			// Search is billed per query on top of the tokens; three is the
			// ceiling the research step is given.
			'research' => array( 'input' => 65000, 'output' => 6200, 'searches' => 3 ),
			'canonical_recipe' => array( 'input' => 4200, 'output' => 3100 ),
			'article' => array( 'input' => 6900, 'output' => 6600 ),
			'review' => array( 'input' => 11200, 'output' => 2200 ),
			'fact_check' => array( 'input' => 10400, 'output' => 2900 ),
			'proofread' => array( 'input' => 11800, 'output' => 6800 ),
			'final_approval' => array( 'input' => 18000, 'output' => 2100 ),
			'featured_image' => array( 'input' => 1800, 'output' => 440 ),
			'facebook_image' => array( 'input' => 3600, 'output' => 345 ),
			'corrections' => array( 'input' => 0, 'output' => 0 ),
		);
	}

	/**
	 * One recipe, step by step, under a given profile.
	 *
	 * A step whose model carries no published rate is counted as unknown rather
	 * than as free — the same distinction the ledger keeps — so an estimate that
	 * cannot be completed says so instead of reading low.
	 */
	public static function recipe( $profile, array $overrides = array() ) {
		return self::recipe_on( MSRWA_Engine_Config::create( MSRWA_Engine_Settings::merge( MSRWA_Engine_Settings::stored(), $overrides ) ), $profile );
	}

	/** The same, on a configuration already resolved — the Moteur preview's, say. */
	public static function recipe_on( MSRWA_Engine_Config $config, $profile ) {
		$registry = (array) $config->get( 'steps', array() );
		$shape = self::shape();

		$total = 0.0;
		$unknown = array();
		$steps = array();

		foreach ( MSRWA_Profile::steps( $profile, $registry ) as $name ) {
			$capability = MSRWA_Engine_Steps::capability( $name, $registry );
			if ( 'none' === $capability ) { continue; }
			// Image steps do not route by their own name: the engine sends every
			// one of them to `routing.image`. Asking for the step's route gave
			// the text model and priced a collage at a twenty-fifth of what it
			// really costs.
			$route = $config->model_for( self::route_for( $name, $capability ) );
			$usage = $shape[ $name ] ?? array( 'input' => 5000, 'output' => 2000 );
			// The ceiling is what the caller will be billed for if the model
			// runs long, so an estimate uses the smaller of the two rather than
			// promising an output nobody guaranteed.
			$usage['output'] = min( (int) $usage['output'], $config->max_output( $name ) );

			$cost = $config->price( $route['provider'], $route['model'], array( 'input_tokens' => $usage['input'], 'output_tokens' => $usage['output'], 'web_searches' => (int) ( $usage['searches'] ?? 0 ) ) );
			if ( null === $cost ) { $unknown[] = $name; continue; }

			$total += (float) $cost;
			$steps[ $name ] = array(
				'model' => $route['provider'] . ':' . $route['model'],
				'bucket' => MSRWA_Engine_Steps::bucket( $name, $registry ),
				'cost_usd' => round( (float) $cost, 6 ),
			);
		}

		$buckets = array( 'article' => 0.0, 'featured' => 0.0, 'facebook' => 0.0, 'other' => 0.0 );
		foreach ( $steps as $step ) { $buckets[ $step['bucket'] ] += $step['cost_usd']; }

		return array(
			'cost_usd' => round( $total, 6 ),
			'steps' => $steps,
			'buckets' => array_map( static function ( $value ) { return round( $value, 6 ); }, $buckets ),
			'unpriced' => $unknown,
		);
	}

	/**
	 * A whole lot: the recipes, plus one vision call per photograph for the
	 * pairing, which is spent before any recipe starts.
	 */
	public static function lot( $profile, $recipes, $images, array $overrides = array() ) {
		$recipe = self::recipe( $profile, $overrides );
		$config = MSRWA_Engine_Config::create( MSRWA_Engine_Settings::merge( MSRWA_Engine_Settings::stored(), $overrides ) );
		// The pairing reads image bytes, so it is priced on the vision route the
		// matcher actually uses.
		$route = $config->model_for( 'vision' );
		$per_image = $config->price( $route['provider'], $route['model'], array( 'input_tokens' => 1100, 'output_tokens' => 180 ) );

		$recipes = max( 0, (int) $recipes );
		$images = max( 0, (int) $images );
		$matching = null === $per_image ? null : round( (float) $per_image * $images, 6 );

		return array(
			'recipes' => $recipes,
			'per_recipe_usd' => $recipe['cost_usd'],
			'matching_usd' => $matching,
			'cost_usd' => round( $recipe['cost_usd'] * $recipes + (float) $matching, 6 ),
			'buckets' => $recipe['buckets'],
			'unpriced' => $recipe['unpriced'],
			'matching_unpriced' => null === $matching,
		);
	}

	/**
	 * Whether a recipe expected to cost $per_recipe runs under $ceiling. Zero
	 * or less is no ceiling; an unpriced estimate cannot be said not to fit.
	 */
	public static function fits( $per_recipe, $ceiling ) {
		$ceiling = (float) $ceiling;
		return $ceiling <= 0 || (float) $per_recipe <= $ceiling;
	}

	/** Which routing key the engine will actually use for a step. */
	public static function route_for( $name, $capability ) {
		if ( 'image_generation' === $capability ) { return 'image'; }
		if ( 'vision' === $capability ) { return 'vision'; }
		return $name;
	}
}
