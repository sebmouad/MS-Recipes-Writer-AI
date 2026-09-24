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
			// Measured on seven live research calls with the search-economy rule and
			// low search context: 23 000–64 000 tokens in, 3 800–6 300 out, and
			// 1, 4, 1, 1, 1, 1, 1 paid searches. Two searches is the average
			// rounded up; the maximum prices every tool call as one.
			'research' => array( 'input' => 45000, 'output' => 5500, 'searches' => 2 ),
			// Written from the editor's photographs: no search, no page read, only
			// the brief and what the photographs show. Measured on 0.21.1, one live
			// tart: 2 841 in (the photograph's reading included), 4 014 out, $0.0054
			// against $0.022–0.028 for the ten searched runs before it.
			'research_photographs' => array( 'input' => 3000, 'output' => 4500 ),
			'canonical_recipe' => array( 'input' => 4200, 'output' => 3100 ),
			'article' => array( 'input' => 6900, 'output' => 6600 ),
			'review' => array( 'input' => 11200, 'output' => 2200 ),
			// Three live fact checks on 0.18.3 answered 3 990 tokens and twice more
			// than the old 4 000 ceiling, reasoning included.
			'fact_check' => array( 'input' => 10400, 'output' => 4500 ),
			// Measured on 0.19.0, three live recipes: the proofread returns only the
			// sentences it changes (3 050–3 320 out, reasoning included, against
			// 6 000 when it returned the article), and the image prompts lost their
			// repetitions (1 450 and 2 530 tokens in, against 1 800 and 3 750).
			'proofread' => array( 'input' => 11000, 'output' => 3300 ),
			'final_approval' => array( 'input' => 18000, 'output' => 2100 ),
			'featured_image' => array( 'input' => 1450, 'output' => 440 ),
			'facebook_image' => array( 'input' => 2550, 'output' => 345 ),
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
	public static function recipe( $profile, array $overrides = array(), $with_photographs = false ) {
		return self::recipe_on( MSRWA_Engine_Config::create( MSRWA_Engine_Settings::merge( MSRWA_Engine_Settings::stored(), $overrides ) ), $profile, $with_photographs );
	}

	/**
	 * The same, on a configuration already resolved — the Moteur preview's, say.
	 * A recipe the writer sent photographs with is researched from them, with
	 * no web search, unless `research.web_search` is `always`.
	 */
	public static function recipe_on( MSRWA_Engine_Config $config, $profile, $with_photographs = false ) {
		$from_photographs = $with_photographs && 'always' !== (string) $config->get( 'research.web_search', 'without_images' );
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
			$route = $config->model_for( self::route_for( $name, $capability, $config ) );
			$usage = $shape[ 'research' === $name && $from_photographs ? 'research_photographs' : $name ] ?? array( 'input' => 5000, 'output' => 2000 );
			// The ceiling is what the caller will be billed for if the model
			// runs long, so an estimate uses the smaller of the two rather than
			// promising an output nobody guaranteed.
			// Thinking above the level the shapes were measured at is extra output;
			// the ceiling still caps the lot, because nothing is billed past it.
			$thinking = 'image_generation' === $capability ? '' : $config->thinking( $name, $route['provider'] );
			// An image is billed by the tokens it is drawn with, and its quality
			// decides how many: the shapes are the `medium` drawing.
			if ( 'image_generation' === $capability ) {
				$usage['output'] = (int) round( $usage['output'] * self::quality_factor( (string) $config->get( 'images.' . ( 'facebook_image' === $name ? 'facebook' : 'featured' ) . '_quality', 'medium' ) ) );
			}
			$usage['output'] = min( max( (int) ( $usage['output'] / 2 ), (int) $usage['output'] + MSRWA_Engine_Config::thinking_allowance( $thinking ) ), $config->max_output( $name ) );

			$searches = 'web_search' === $capability && ! $from_photographs ? min( (int) ( $usage['searches'] ?? 2 ), $config->web_searches( $route['provider'] ) ) : 0;
			$cost = $config->price( $route['provider'], $route['model'], array( 'input_tokens' => $usage['input'], 'output_tokens' => $usage['output'], 'web_searches' => $searches ) );
			if ( null === $cost ) { $unknown[] = $name; continue; }

			// Research also reads up to `limits.images_inspected` of the photographs
			// it cites, one vision call each: $0.0021 on a real run, and missing.
			// The editor's photographs were read at intake, by the pairing, and
			// are not read again: that look is priced with the lot.
			if ( 'web_search' === $capability && ! $from_photographs ) {
				$vision = $config->model_for( 'vision' );
				$look = $config->price( $vision['provider'], $vision['model'], self::vision_usage( $config, $vision['provider'] ) );
				$looks = max( 0, (int) $config->get( 'limits.images_inspected', 3 ) );
				$cost += null === $look ? 0.0 : (float) $look * $looks;
			}

			$total += (float) $cost;
			$steps[ $name ] = array(
				'model' => $route['provider'] . ':' . $route['model'],
				'thinking' => $thinking,
				'quality' => 'image_generation' === $capability ? (string) $config->get( 'images.' . ( 'facebook_image' === $name ? 'facebook' : 'featured' ) . '_quality', 'medium' ) : '',
				'searches' => $searches,
				'bucket' => MSRWA_Engine_Steps::bucket( $name, $registry ),
				'cost_usd' => round( (float) $cost, 6 ),
			);
		}

		$buckets = array( 'article' => 0.0, 'featured' => 0.0, 'facebook' => 0.0, 'other' => 0.0 );
		foreach ( $steps as $step ) { $buckets[ $step['bucket'] ] += $step['cost_usd']; }

		// The final approval may refuse. Each refusal redraws the images it
		// blocked and asks again, up to `attempts.final_approval`. A real full
		// recipe was refused twice: the collage was drawn three times and the
		// approval ran three times, $0.2804 against a one-pass $0.2119. The
		// expected figure stays one pass; the maximum is every attempt used,
		// with both images redrawn each time — the most the engine can spend.
		// Research may search more than it is asked to: at most every tool call
		// it is allowed, each priced as a paid search.
		$retry = 0.0;
		foreach ( $steps as $name => $step ) {
			if ( empty( $step['searches'] ) ) { continue; }
			list( $provider ) = explode( ':', $step['model'], 2 );
			$retry += ( $config->web_tool_calls( $provider ) - $step['searches'] ) * (float) $config->get( 'providers.' . $provider . '.web_search_usd', 0 );
		}
		if ( isset( $steps['final_approval'] ) ) {
			$approval = 0.0;
			foreach ( array( 'featured_image', 'facebook_image', 'final_approval' ) as $again ) { $approval += (float) ( $steps[ $again ]['cost_usd'] ?? 0 ); }
			$retry += $approval * max( 0, $config->attempts( 'final_approval' ) - 1 );
		}

		return array(
			'cost_usd' => round( $total, 6 ),
			'max_usd' => round( $total + $retry, 6 ),
			'steps' => $steps,
			'buckets' => array_map( static function ( $value ) { return round( $value, 6 ); }, $buckets ),
			'unpriced' => $unknown,
		);
	}

	/**
	 * Output tokens an image quality draws, against `medium`. Measured on
	 * gpt-image-2.5-flare, 2026-09-23: a 1024×1024 featured image drew 196,
	 * 439, 1 756, 3 122 and 7 024 tokens from low to max ($0.014 to $0.219);
	 * the 1024×1536 collage followed the same ratios (158, 343, 1 372).
	 */
	public static function quality_factor( $quality ) {
		$factors = array( 'low' => 0.45, 'medium' => 1.0, 'high' => 4.0, 'xhigh' => 7.2, 'max' => 16.0 );
		return $factors[ $quality ] ?? 1.0;
	}

	/** One look at one photograph: the shape the matcher and research both pay. */
	private static function vision_usage( MSRWA_Engine_Config $config, $provider ) {
		return array( 'input_tokens' => 1100, 'output_tokens' => max( 180, 180 + MSRWA_Engine_Config::thinking_allowance( $config->thinking( 'vision', $provider ) ) ) );
	}

	/**
	 * A whole lot: the recipes, plus one vision call per photograph for the
	 * pairing, which is spent before any recipe starts.
	 */
	public static function lot( $profile, $recipes, $images, array $overrides = array() ) {
		$recipe = self::recipe( $profile, $overrides );
		// A recipe with enough photographs — `research.min_photographs`, two by
		// default — is researched from them: as many recipes as the photographs
		// cover, at most, are priced that way; the rest search.
		$pictured = self::recipe( $profile, $overrides, true );
		$config = MSRWA_Engine_Config::create( MSRWA_Engine_Settings::merge( MSRWA_Engine_Settings::stored(), $overrides ) );
		$with = min( max( 0, (int) $recipes ), intdiv( max( 0, (int) $images ), max( 1, (int) $config->get( 'research.min_photographs', 2 ) ) ) );
		// The pairing reads image bytes, so it is priced on the vision route the
		// matcher actually uses.
		$route = $config->model_for( 'vision' );
		$per_image = $config->price( $route['provider'], $route['model'], self::vision_usage( $config, $route['provider'] ) );

		$recipes = max( 0, (int) $recipes );
		$images = max( 0, (int) $images );
		$matching = null === $per_image ? null : round( (float) $per_image * $images, 6 );

		return array(
			'recipes' => $recipes,
			'per_recipe_usd' => $recipe['cost_usd'],
			'per_recipe_max_usd' => $recipe['max_usd'],
			'matching_usd' => $matching,
			'per_recipe_pictured_usd' => $pictured['cost_usd'],
			'cost_usd' => round( $pictured['cost_usd'] * $with + $recipe['cost_usd'] * ( $recipes - $with ) + (float) $matching, 6 ),
			'max_usd' => round( $pictured['max_usd'] * $with + $recipe['max_usd'] * ( $recipes - $with ) + (float) $matching, 6 ),
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
	public static function route_for( $name, $capability, $config = null ) {
		if ( 'image_generation' === $capability ) { return $config instanceof MSRWA_Engine_Config ? $config->image_route( $name ) : 'image'; }
		if ( 'vision' === $capability ) { return 'vision'; }
		return $name;
	}
}
