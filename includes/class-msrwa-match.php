<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Which photograph belongs to which recipe, and the brief that follows.
 *
 * This is the plugin's own step, not the engine's. It runs before any engine
 * run starts and it uses the engine only the way any caller may: through the
 * public call layer, with a prompt of its own. Nothing in `includes/engine/`
 * knows this exists.
 *
 * It works in two passes because they cost differently. Every photograph is
 * described once, concurrently — that is the expensive half, billed per image.
 * Then one text call reads the descriptions beside the recipe titles and says
 * which goes with which, which is cheap. Describing an image twice for two
 * recipes would pay twice for the same photograph.
 */
final class MSRWA_Match {

	/**
	 * Describes every photograph, then pairs them with the recipes.
	 *
	 * Returns the pairing, the descriptions it was decided from, and what it
	 * cost — the writer confirms a pairing rather than discovering it in a
	 * finished article, and a decision nobody can see the reason for is a
	 * decision nobody can correct.
	 */
	public static function run( array $recipes, array $images, MSRWA_Engine_Config $config ) {
		$seen = self::describe( $images, $config );
		$decision = self::pair( $recipes, $seen['images'], $config );

		return array(
			'images' => $seen['images'],
			'pairs' => self::normalise( $decision['pairs'], count( $recipes ), $images ),
			'reasoning' => $decision['reasoning'],
			'cost_usd' => round( (float) $seen['cost_usd'] + (float) $decision['cost_usd'], 6 ),
			'seconds' => round( (float) $seen['seconds'] + (float) $decision['seconds'], 1 ),
			'errors' => array_merge( $seen['errors'], $decision['errors'] ),
		);
	}

	/** What each photograph actually shows, read from its own bytes, all at once. */
	private static function describe( array $images, MSRWA_Engine_Config $config ) {
		// Reading image bytes is the vision route's job. Asking for the research
		// route happened to resolve to the same model today and would have
		// quietly stopped doing so the moment somebody changed one of them.
		$route = $config->model_for( 'vision' );
		$wire = $config->provider( $route['provider'], $route['model'] );
		$started = microtime( true );
		$out = array( 'images' => array(), 'cost_usd' => 0.0, 'seconds' => 0.0, 'errors' => array() );

		$plans = array();
		$requests = array();
		foreach ( $images as $index => $image ) {
			$bytes = MSRWA_Intake::read( $image['id'], (int) $config->get( 'limits.max_image_bytes', 10000000 ) );
			if ( isset( $bytes['error'] ) ) {
				$out['errors'][] = $image['file'] . ' : ' . $bytes['error'];
				$out['images'][ $index ] = array_merge( $image, array( 'describes' => '', 'dish' => '' ) );
				continue;
			}
			$plan = MSRWA_Engine_Call::plan_vision( $route['provider'], $route['model'], $bytes, $image['title'], 400, $wire, self::vision_instruction() );
			if ( isset( $plan['error'] ) ) {
				$out['errors'][] = $image['file'] . ' : ' . $plan['error'];
				$out['images'][ $index ] = array_merge( $image, array( 'describes' => '', 'dish' => '' ) );
				continue;
			}
			$plans[ $index ] = $plan;
			$requests[ $index ] = $plan['request'];
		}

		$answers = MSRWA_Engine_Call::http_many( $requests, (int) $config->get( 'limits.concurrency', 4 ) );
		foreach ( $plans as $index => $plan ) {
			$answer = MSRWA_Engine_Call::read( $plan, $answers[ $index ] );
			$cost = $config->price( $route['provider'], $route['model'], (array) ( $answer['usage'] ?? array() ) );
			$out['cost_usd'] += (float) $cost;
			$seen = MSRWA_Json::decode( (string) ( $answer['text'] ?? '' ) );
			$out['images'][ $index ] = array_merge( $images[ $index ], array(
				'dish' => is_array( $seen ) ? (string) ( $seen['dish'] ?? '' ) : '',
				'describes' => is_array( $seen ) ? (string) ( $seen['description'] ?? '' ) : '',
			) );
			if ( ! is_array( $seen ) ) { $out['errors'][] = $images[ $index ]['file'] . ' : description illisible'; }
		}

		ksort( $out['images'] );
		$out['images'] = array_values( $out['images'] );
		$out['seconds'] = round( microtime( true ) - $started, 1 );
		return $out;
	}

	/** One cheap text call that reads the descriptions against the recipe titles. */
	private static function pair( array $recipes, array $images, MSRWA_Engine_Config $config ) {
		$route = $config->model_for( 'canonical_recipe' );
		$wire = $config->provider( $route['provider'], $route['model'] );
		$started = microtime( true );

		$answer = MSRWA_Engine_Call::text( $route['provider'], $route['model'], self::prompt( $recipes, $images ), 1500, true, false, $wire );
		$cost = $config->price( $route['provider'], $route['model'], (array) ( $answer['usage'] ?? array() ) );
		$decoded = MSRWA_Json::decode( (string) ( $answer['text'] ?? '' ) );

		return array(
			'pairs' => is_array( $decoded ) ? (array) ( $decoded['pairs'] ?? array() ) : array(),
			'reasoning' => is_array( $decoded ) ? (string) ( $decoded['reasoning'] ?? '' ) : '',
			'cost_usd' => (float) $cost,
			'seconds' => round( microtime( true ) - $started, 1 ),
			'errors' => is_array( $decoded ) ? array() : array( 'Appariement illisible : ' . mb_substr( (string) ( $answer['error'] ?? $answer['text'] ?? '' ), 0, 200 ) ),
		);
	}

	private static function vision_instruction() {
		return 'Tu regardes une photographie destinée à illustrer une recette. Réponds en JSON avec exactement deux clés : '
			. '"dish", le nom du plat tel qu’un cuisinier le reconnaîtrait, en français, ou "" si tu ne peux pas le nommer ; '
			. '"description", une phrase décrivant ce qui est visible — ingrédients principaux, couleur, cuisson, présentation. '
			. 'Ne décris que ce qui est visible. N’invente ni ingrédient caché, ni quantité, ni origine.';
	}

	/** What the pairing call is asked, with the recipes and the photographs numbered. */
	private static function prompt( array $recipes, array $images ) {
		$lines = array(
			'Un rédacteur a fourni plusieurs recettes et plusieurs photographies, sans dire lesquelles vont ensemble.',
			'Associe chaque photographie à la recette qu’elle illustre.',
			'',
			'RECETTES :',
		);
		foreach ( $recipes as $index => $recipe ) {
			$lines[] = sprintf( '%d. %s', $index, $recipe['title'] );
		}
		$lines[] = '';
		$lines[] = 'PHOTOGRAPHIES, décrites depuis leurs propres pixels :';
		foreach ( $images as $index => $image ) {
			$lines[] = sprintf( '%d. fichier « %s » — plat reconnu : %s — %s', $index, $image['file'], '' !== $image['dish'] ? $image['dish'] : 'non identifié', $image['describes'] );
		}
		$lines[] = '';
		$lines[] = 'RÈGLES :';
		$lines[] = '- Une photographie appartient à une seule recette, ou à aucune.';
		$lines[] = '- Une recette peut recevoir plusieurs photographies, ou aucune.';
		$lines[] = '- Le nom du fichier est un indice faible ; ce que montre la photographie prime sur lui.';
		$lines[] = '- Dans le doute, n’associe pas. Une photographie laissée de côté coûte moins qu’une photographie attribuée au mauvais plat, qui illustrera un article entier.';
		$lines[] = '';
		$lines[] = 'RÉPONSE — un objet JSON valide, sans Markdown, avec exactement ces clés :';
		$lines[] = '- "pairs" : tableau de {"image": entier, "recipe": entier ou null, "confidence": "haute"|"moyenne"|"basse", "why": une phrase en français}';
		$lines[] = '- "reasoning" : une phrase sur la façon dont l’ensemble se répartit';
		return implode( "\n", $lines );
	}

	/**
	 * Keeps only pairings that point at something real.
	 *
	 * The answer decides what every recipe is illustrated with, so an index out
	 * of range or a photograph claimed twice is dropped here rather than
	 * carried into a brief. A pairing the model was unsure of is kept and
	 * marked: the writer is the one who settles it.
	 */
	private static function normalise( array $pairs, $recipe_count, array $images ) {
		$out = array();
		$taken = array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) ) { continue; }
			$image = isset( $pair['image'] ) ? (int) $pair['image'] : -1;
			if ( $image < 0 || $image >= count( $images ) || isset( $taken[ $image ] ) ) { continue; }
			$recipe = isset( $pair['recipe'] ) && null !== $pair['recipe'] ? (int) $pair['recipe'] : null;
			if ( null !== $recipe && ( $recipe < 0 || $recipe >= (int) $recipe_count ) ) { $recipe = null; }
			$taken[ $image ] = true;
			$out[] = array(
				'image' => $image, 'recipe' => $recipe,
				'confidence' => in_array( (string) ( $pair['confidence'] ?? '' ), array( 'haute', 'moyenne', 'basse' ), true ) ? (string) $pair['confidence'] : 'basse',
				'why' => mb_substr( (string) ( $pair['why'] ?? '' ), 0, 300 ),
			);
		}
		// A photograph the answer never mentioned is unassigned, not missing.
		foreach ( array_keys( $images ) as $image ) {
			if ( ! isset( $taken[ $image ] ) ) { $out[] = array( 'image' => (int) $image, 'recipe' => null, 'confidence' => 'basse', 'why' => 'Non mentionnée par l’appariement.' ); }
		}
		return $out;
	}

	/**
	 * The brief for one recipe, in the shape the engine's `run()` takes.
	 *
	 * The engine defines this shape; the plugin fills it. The writer's own text
	 * is the instruction, and the photographs he paired with it are the images
	 * the engine will observe.
	 */
	public static function brief( array $recipe, array $images ) {
		$attached = array();
		foreach ( $images as $image ) {
			$attached[] = array( 'id' => (int) $image['id'], 'url' => (string) $image['url'], 'title' => (string) $image['title'] );
		}
		return array(
			'type' => 'recipe',
			'title' => (string) $recipe['title'],
			'text' => (string) $recipe['text'],
			'images' => $attached,
		);
	}
}
