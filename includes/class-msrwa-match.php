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
	public static function run( array $recipes, array $images, MSRWA_Engine_Config $config, $dir = '' ) {
		$seen = self::describe( $images, $config, $dir );
		// Text alone has nothing to pair; photographs alone name their own
		// recipes, one per dish they show.
		if ( ! $seen['images'] ) {
			$decision = array( 'pairs' => array(), 'reasoning' => '', 'cost_usd' => 0.0, 'seconds' => 0.0, 'errors' => array() );
		} elseif ( 1 === count( $recipes ) && self::all_of( $recipes[0], $seen['images'] ) ) {
			// One recipe, and every photograph shows a dish that shares a word
			// with its title: the writer sent them for it, and there is nothing
			// for a paid call to decide. A photograph with no dish on it still
			// waits for the writer, as normalise() promises. A photograph of
			// something else goes through the pairing below, and becomes a
			// recipe of its own rather than illustrate the wrong one.
			$decision = array( 'pairs' => array(), 'reasoning' => '', 'cost_usd' => 0.0, 'seconds' => 0.0, 'errors' => array() );
			foreach ( array_keys( $seen['images'] ) as $index ) { $decision['pairs'][] = array( 'image' => $index, 'recipe' => 0, 'confidence' => 'haute', 'why' => 'Seule recette du lot.', 'reason' => 'only_recipe' ); }
		} elseif ( ! $recipes ) {
			$decision = self::propose( $seen['images'], $config );
			$recipes = $decision['recipes'];
		} else {
			$decision = self::strays( $recipes, $seen['images'], self::pair( $recipes, $seen['images'], $config ), $config );
			$recipes = $decision['recipes'];
		}

		return array(
			'recipes' => $recipes,
			'images' => $seen['images'],
			// The described photographs: the raw ones carry no dish, and the rule
			// against pairing an unrecognised photograph read that as none, everywhere.
			'pairs' => self::normalise( $decision['pairs'], count( $recipes ), $seen['images'] ),
			'reasoning' => $decision['reasoning'],
			'cost_usd' => round( (float) $seen['cost_usd'] + (float) $decision['cost_usd'], 6 ),
			'seconds' => round( (float) $seen['seconds'] + (float) $decision['seconds'], 1 ),
			'errors' => array_merge( $seen['errors'], $decision['errors'] ),
		);
	}

	/**
	 * What each photograph actually shows, read from its own bytes, all at once.
	 *
	 * Read once for everything: the same answer names the dish for the pairing
	 * and carries the observation the research is written from, in the
	 * engine's own words, so the engine does not pay to look again.
	 */
	private static function describe( array $images, MSRWA_Engine_Config $config, $dir = '' ) {
		// Reading image bytes is the vision route's job. Asking for the research
		// route happened to resolve to the same model today and would have
		// quietly stopped doing so the moment somebody changed one of them.
		$route = $config->model_for( 'vision' );
		$wire = $config->provider( $route['provider'], $route['model'], 'vision' );
		$started = microtime( true );
		$out = array( 'images' => array(), 'cost_usd' => 0.0, 'seconds' => 0.0, 'errors' => array() );

		$plans = array();
		$requests = array();
		foreach ( $images as $index => $image ) {
			$max = (int) $config->get( 'limits.max_image_bytes', 10000000 );
			$bytes = is_int( $image['id'] ) ? MSRWA_Intake::read( $image['id'], $max ) : MSRWA_Sources::read( $dir, $image['id'], $max );
			if ( isset( $bytes['error'] ) ) {
				$out['errors'][] = $image['file'] . ' : ' . $bytes['error'];
				$out['images'][ $index ] = array_merge( $image, array( 'describes' => '', 'dish' => '' ) );
				continue;
			}
			$plan = MSRWA_Engine_Call::plan_vision( $route['provider'], $route['model'], $bytes, $image['title'], (int) $config->max_output( 'vision' ), $wire, self::vision_instruction( self::language( $config ), (string) $config->get( 'vision_instruction', '' ) ) );
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
			$observation = is_array( $seen ) ? array_intersect_key( $seen, array_flip( array( 'observable_details', 'composition', 'colours', 'textures', 'uncertainties' ) ) ) : array();
			$out['images'][ $index ] = array_merge( $images[ $index ], array(
				'dish' => is_array( $seen ) ? (string) ( $seen['dish'] ?? '' ) : '',
				'describes' => is_array( $seen ) ? (string) ( $seen['description'] ?? '' ) : '',
				// A step-by-step collage the writer made is offered as the recipe's
				// Facebook image and its reference (ENGINE.md §7, 50).
				'collage' => is_array( $seen ) && true === ( $seen['collage'] ?? null ),
				'observation' => $observation,
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
		$wire = $config->provider( $route['provider'], $route['model'], 'canonical_recipe' );
		$started = microtime( true );

		$answer = MSRWA_Engine_Call::text( $route['provider'], $route['model'], self::prompt( $recipes, $images, self::language( $config ) ), 1500, true, false, $wire );
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

	/**
	 * Whether every recognised photograph plainly shows this recipe: each word
	 * of four letters or more in its dish name is in the title, accents and
	 * case aside. "Yassa au poulet" is a "Poulet yassa"; a "Poulet yassa" is
	 * not a "Tajine de poulet", however much chicken they share. Anything
	 * less certain goes to the pairing call, which costs a fraction of a cent.
	 */
	private static function all_of( array $recipe, array $images ) {
		$words = static function ( $text ) {
			return array_filter( preg_split( '/[^\p{L}\p{N}]+/u', MSRWA_Engine_Score::fold( (string) $text ) ), static function ( $word ) { return mb_strlen( $word ) >= 4; } );
		};
		$title = $words( ( $recipe['title'] ?? '' ) . ' ' . strtok( (string) ( $recipe['text'] ?? '' ), "\n" ) );
		foreach ( $images as $image ) {
			$dish = (string) ( $image['dish'] ?? '' );
			if ( '' === trim( $dish ) ) { continue; }
			$named = $words( $dish );
			if ( ! $named || array_diff( $named, $title ) ) { return false; }
		}
		return true;
	}

	/**
	 * A photograph of a dish the text does not name is not thrown away: it
	 * becomes a recipe of its own, as it would in a lot with no text, and the
	 * writer can still set it aside. The owner's rule, 2026-09-24: a live lot
	 * sent a tart with a yassa and a clafoutis, and the tart was left out.
	 * Photographs of one dish become one recipe; a photograph with no dish on
	 * it is not a recipe anyone can name, and waits for the writer.
	 */
	private static function strays( array $recipes, array $images, array $decision, MSRWA_Engine_Config $config ) {
		$pairs = self::normalise( $decision['pairs'], count( $recipes ), $images );
		$loose = array();
		foreach ( $pairs as $pair ) {
			if ( null === $pair['recipe'] && '' !== trim( (string) ( $images[ $pair['image'] ]['dish'] ?? '' ) ) ) { $loose[] = (int) $pair['image']; }
		}
		$decision['recipes'] = $recipes;
		if ( ! $loose ) { return $decision; }

		$subset = array();
		foreach ( $loose as $index ) { $subset[] = $images[ $index ]; }
		$grouped = self::propose( $subset, $config );
		// No grouping came back: one recipe per dish name, so none is lost.
		if ( ! $grouped['recipes'] ) {
			$grouped['pairs'] = array();
			$titles = array();
			foreach ( $subset as $key => $image ) {
				$title = mb_substr( trim( wp_strip_all_tags( (string) $image['dish'] ) ), 0, 180 );
				$fold = MSRWA_Engine_Score::fold( $title );
				if ( ! isset( $titles[ $fold ] ) ) { $titles[ $fold ] = count( $grouped['recipes'] ); $grouped['recipes'][] = null; }
				$grouped['pairs'][] = array( 'image' => $key, 'recipe' => $titles[ $fold ], 'confidence' => 'moyenne', 'why' => '' );
				$grouped['recipes'][ $titles[ $fold ] ] = $title;
			}
			foreach ( $grouped['recipes'] as $index => $title ) { $grouped['recipes'][ $index ] = self::from_photographs( $title, $subset, $grouped['pairs'], $index, true ); }
		}

		$offset = count( $recipes );
		$moved = array();
		foreach ( (array) $grouped['pairs'] as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['image'], $pair['recipe'], $loose[ (int) $pair['image'] ] ) || null === $pair['recipe'] ) { continue; }
			$moved[ $loose[ (int) $pair['image'] ] ] = array(
				'image' => $loose[ (int) $pair['image'] ], 'recipe' => $offset + (int) $pair['recipe'],
				'confidence' => 'moyenne', 'why' => 'Plat absent du texte : une recette de plus, d’après la photographie.', 'reason' => 'new_recipe', 'new_recipe' => true,
			);
		}
		foreach ( $pairs as $key => $pair ) {
			if ( isset( $moved[ $pair['image'] ] ) ) { $pairs[ $key ] = $moved[ $pair['image'] ]; }
		}
		$decision['recipes'] = array_merge( $recipes, array_values( (array) $grouped['recipes'] ) );
		$decision['pairs'] = $pairs;
		$decision['cost_usd'] = (float) $decision['cost_usd'] + (float) $grouped['cost_usd'];
		$decision['seconds'] = (float) $decision['seconds'] + (float) $grouped['seconds'];
		$decision['errors'] = array_merge( $decision['errors'], $grouped['errors'] );
		return $decision;
	}

	/**
	 * Recipes out of photographs alone: one per distinct dish, with the
	 * photographs of it. Two shots of one tart are one recipe, which a
	 * grouping on the recognised names could not tell — "tarte aux pommes"
	 * and "tarte normande" — so one cheap text call decides it.
	 */
	private static function propose( array $images, MSRWA_Engine_Config $config ) {
		$route = $config->model_for( 'canonical_recipe' );
		$wire = $config->provider( $route['provider'], $route['model'], 'canonical_recipe' );
		$started = microtime( true );
		$named = array_filter( $images, static function ( $image ) { return '' !== trim( (string) ( $image['dish'] ?? '' ) ); } );
		$out = array( 'recipes' => array(), 'pairs' => array(), 'reasoning' => '', 'cost_usd' => 0.0, 'seconds' => 0.0, 'errors' => array() );
		if ( ! $named ) { return $out; }

		$answer = MSRWA_Engine_Call::text( $route['provider'], $route['model'], self::proposal_prompt( $images, self::language( $config ) ), 1500, true, false, $wire );
		$out['cost_usd'] = (float) $config->price( $route['provider'], $route['model'], (array) ( $answer['usage'] ?? array() ) );
		$out['seconds'] = round( microtime( true ) - $started, 1 );
		$decoded = MSRWA_Json::decode( (string) ( $answer['text'] ?? '' ) );
		if ( ! is_array( $decoded ) ) {
			$out['errors'][] = 'Regroupement illisible : ' . mb_substr( (string) ( $answer['error'] ?? $answer['text'] ?? '' ), 0, 200 );
			return $out;
		}
		return array_merge( $out, self::proposal( $decoded, $images ) );
	}

	/**
	 * The grouping answer, checked: titles are plain text, a recipe no
	 * photograph was given is dropped, and the pairs follow the recipes kept.
	 */
	private static function proposal( array $decoded, array $images ) {
		$titles = array();
		// Model output is data: a title is plain text, and a dish nobody photographed is not a recipe.
		foreach ( (array) ( $decoded['recipes'] ?? array() ) as $recipe ) {
			$titles[] = mb_substr( trim( wp_strip_all_tags( (string) ( is_array( $recipe ) ? ( $recipe['title'] ?? '' ) : $recipe ) ) ), 0, 180 );
		}
		$pairs = array_values( array_filter( (array) ( $decoded['pairs'] ?? array() ), 'is_array' ) );
		$used = array();
		foreach ( $pairs as $pair ) {
			if ( isset( $pair['recipe'], $pair['image'] ) && isset( $images[ (int) $pair['image'] ] ) ) { $used[ (int) $pair['recipe'] ] = true; }
		}
		$recipes = array();
		$shift = array();
		foreach ( $titles as $index => $title ) {
			$recipe = '' !== $title ? self::from_photographs( $title, $images, $pairs, $index, isset( $used[ $index ] ) ) : null;
			$shift[ $index ] = $recipe ? count( $recipes ) : null;
			if ( $recipe ) { $recipes[] = $recipe; }
		}
		// Dropping a recipe shifts the ones after it: the pairs follow.
		foreach ( $pairs as $key => $pair ) {
			if ( isset( $pair['recipe'] ) && null !== $pair['recipe'] ) { $pairs[ $key ]['recipe'] = $shift[ (int) $pair['recipe'] ] ?? null; }
		}
		return array( 'recipes' => $recipes, 'pairs' => $pairs, 'reasoning' => (string) ( $decoded['reasoning'] ?? '' ) );
	}

	/** A recipe the writer named for a photograph, on the pairing screen. */
	public static function named( $title, array $image ) {
		$title = mb_substr( trim( wp_strip_all_tags( (string) $title ) ), 0, 180 );
		return '' === $title ? null : self::from_photographs( $title, array( $image ), array( array( 'image' => 0, 'recipe' => 0 ) ), 0, true );
	}

	/** A recipe the writer did not type: the dish's name, and what its photographs show. */
	private static function from_photographs( $title, array $images, array $pairs, $index, $photographed ) {
		if ( ! $photographed ) { return null; }
		$seen = array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['recipe'], $pair['image'] ) || (int) $pair['recipe'] !== (int) $index ) { continue; }
			$described = trim( (string) ( $images[ (int) $pair['image'] ]['describes'] ?? '' ) );
			if ( '' !== $described ) { $seen[] = '- ' . $described; }
		}
		$text = $title . "\n\n" . 'Aucun texte fourni : la recette est à établir d’après les sources, pour le plat que montrent les photographies.';
		if ( $seen ) { $text .= "\n\n" . 'Ce que montrent les photographies :' . "\n" . implode( "\n", array_unique( $seen ) ); }
		return array( 'title' => $title, 'text' => $text, 'from_photographs' => true );
	}

	/**
	 * The lot's language, as the prompts name it. A dish name the pairing
	 * reads becomes a recipe's title when there is no text, and its reasons
	 * are shown to the writer: both are written in the language of the site.
	 */
	private static function language( MSRWA_Engine_Config $config ) {
		$names = array( 'fr' => 'français', 'en' => 'anglais', 'ar' => 'arabe', 'es' => 'espagnol' );
		$code = (string) ( $config->settings()['site_language'] ?? $config->get( 'language', 'fr' ) );
		return $names[ $code ] ?? 'français';
	}

	private static function proposal_prompt( array $images, $language = 'français' ) {
		$lines = array(
			'Un rédacteur a fourni des photographies de plats, sans aucun texte.',
			'Chaque plat distinct deviendra une recette, illustrée par ses photographies.',
			'',
			'PHOTOGRAPHIES, décrites depuis leurs propres pixels :',
		);
		foreach ( $images as $index => $image ) {
			$lines[] = sprintf( '%d. fichier « %s » — plat reconnu : %s — %s', $index, self::file_label( $image ), '' !== $image['dish'] ? $image['dish'] : 'non identifié', $image['describes'] );
		}
		$lines[] = '';
		$lines[] = 'RÈGLES :';
		$lines[] = '- Deux photographies du même plat vont à la même recette, même si le nom reconnu diffère un peu.';
		$lines[] = '- Le titre est le nom usuel du plat, en ' . $language . ', sans adjectif publicitaire.';
		$lines[] = '- Une photographie où aucun plat n’est reconnu n’appartient à aucune recette.';
		$lines[] = '- N’invente aucun plat qu’aucune photographie ne montre.';
		$lines[] = '';
		$lines[] = 'RÉPONSE — un objet JSON valide, sans Markdown, avec exactement ces clés :';
		$lines[] = '- "recipes" : tableau de {"title": le nom du plat}';
		$lines[] = '- "pairs" : tableau de {"image": entier, "recipe": indice dans "recipes" ou null, "confidence": "haute"|"moyenne"|"basse", "why": une phrase en ' . $language . '}';
		$lines[] = '- "reasoning" : une phrase sur la façon dont l’ensemble se répartit';
		return implode( "\n", $lines );
	}

	/** The engine's own observation instruction, and the two keys the pairing needs. */
	private static function vision_instruction( $language = 'français', $engine = '' ) {
		return ( '' !== trim( $engine ) ? $engine : MSRWA_Engine_Call::default_vision_instruction() )
			. ' Add two keys to the same JSON object: "dish", the name of the dish as a cook would recognise it, in ' . $language . ', or "" if you cannot name it; '
			. '"description", one sentence in ' . $language . ' saying what is visible — main ingredients, colour, doneness, presentation; '
			. '"collage", true when the image is a grid of several photographs showing a recipe being made step by step, false for a single photograph. '
			. 'Describe only what is visible; never invent a hidden ingredient, a quantity or an origin.';
	}

	/** What the pairing call is asked, with the recipes and the photographs numbered. */
	private static function prompt( array $recipes, array $images, $language = 'français' ) {
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
			$lines[] = sprintf( '%d. fichier « %s » — plat reconnu : %s — %s', $index, self::file_label( $image ), '' !== $image['dish'] ? $image['dish'] : 'non identifié', $image['describes'] );
		}
		$lines[] = '';
		$lines[] = 'RÈGLES :';
		$lines[] = '- Une photographie appartient à une seule recette, ou à aucune.';
		$lines[] = '- Une recette peut recevoir plusieurs photographies, ou aucune.';
		$lines[] = '- Le nom du fichier est un indice faible ; ce que montre la photographie prime sur lui.';
		$lines[] = '- Dans le doute, n’associe pas. Une photographie laissée de côté coûte moins qu’une photographie attribuée au mauvais plat, qui illustrera un article entier.';
		$lines[] = '';
		$lines[] = 'RÉPONSE — un objet JSON valide, sans Markdown, avec exactement ces clés :';
		$lines[] = '- "pairs" : tableau de {"image": entier, "recipe": entier ou null, "confidence": "haute"|"moyenne"|"basse", "why": une phrase en ' . $language . '}';
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
			$confidence = in_array( (string) ( $pair['confidence'] ?? '' ), array( 'haute', 'moyenne', 'basse' ), true ) ? (string) $pair['confidence'] : 'basse';
			$why = mb_substr( (string) ( $pair['why'] ?? '' ), 0, 300 );
			// A reason the plugin gives itself travels as a code, so the screen
			// can say it in the reader's language; the model's own reasons are
			// written in the lot's.
			$reason = in_array( (string) ( $pair['reason'] ?? '' ), array( 'only_recipe', 'new_recipe', 'no_dish', 'not_mentioned' ), true ) ? (string) $pair['reason'] : '';
			// "In doubt, do not pair" is a promise on screen, so it is kept here
			// and not left to the model: a live pairing gave a plain brown square
			// to a tart "with confidence" while saying no dish was visible. A
			// photograph nobody could name waits for the writer.
			if ( null !== $recipe && array_key_exists( 'dish', (array) $images[ $image ] ) && '' === trim( (string) $images[ $image ]['dish'] ) ) {
				$recipe = null;
				$confidence = 'basse';
				$why = 'Aucun plat reconnu sur la photographie : associez-la, faites-en une recette ou écartez-la.';
				$reason = 'no_dish';
			}
			$taken[ $image ] = true;
			$one = array( 'image' => $image, 'recipe' => $recipe, 'confidence' => $confidence, 'why' => $why );
			if ( null === $recipe && '' === trim( (string) ( $images[ $image ]['dish'] ?? 'x' ) ) ) { $reason = 'no_dish'; }
			if ( '' !== $reason ) { $one['reason'] = $reason; }
			if ( ! empty( $pair['new_recipe'] ) ) { $one['new_recipe'] = true; }
			// A photograph no recipe took is the writer's to decide — none is
			// dropped without them — and the lot does not leave until they have.
			if ( null === $recipe ) { $one['pending'] = true; }
			$out[] = $one;
		}
		// A photograph the answer never mentioned is unassigned, not missing.
		foreach ( array_keys( $images ) as $image ) {
			if ( ! isset( $taken[ $image ] ) ) { $out[] = array( 'image' => (int) $image, 'recipe' => null, 'confidence' => 'basse', 'why' => 'Non mentionnée par l’appariement.', 'reason' => 'not_mentioned', 'pending' => true ); }
		}
		return $out;
	}

	/**
	 * How the pairing model is told which photograph it reads: its identifier,
	 * and the writer's file name when there is one — a name like "tarte.jpg"
	 * is a hint, a pasted image's is not.
	 */
	private static function file_label( array $image ) {
		$ref = (string) ( $image['ref'] ?? '' );
		$file = 'pasted' === ( $image['origin'] ?? '' ) ? 'image collée' : (string) ( $image['file'] ?? '' );
		return '' === $ref ? $file : $ref . ' · ' . $file;
	}

	/**
	 * The brief for one recipe, in the shape the engine's `run()` takes.
	 *
	 * The engine defines this shape; the plugin fills it. The writer's own text
	 * is the instruction, and the photographs they paired with it are the images
	 * the engine will observe.
	 */
	public static function brief( array $recipe, array $images ) {
		$attached = array();
		foreach ( $images as $image ) {
			$one = array( 'id' => is_int( $image['id'] ) ? (int) $image['id'] : (string) $image['id'], 'url' => (string) $image['url'], 'title' => (string) $image['title'] );
			// Read at intake already: the engine uses it rather than looking again.
			if ( ! empty( $image['observation'] ) ) { $one['observation'] = (array) $image['observation']; }
			$attached[] = $one;
		}
		return array(
			'type' => 'recipe',
			'title' => (string) $recipe['title'],
			'text' => (string) $recipe['text'],
			'images' => $attached,
		);
	}
}
