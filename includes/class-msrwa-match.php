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
 * Then one text call reads the writer's text and the descriptions together: it
 * finds the recipes in the text, however the writer laid them out, and says
 * which photograph goes with which, which is cheap. Describing an image twice
 * for two recipes would pay twice for the same photograph.
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
	public static function run( array $recipes, array $images, MSRWA_Engine_Config $config, $dir = '', $text = '' ) {
		$seen = self::describe( $images, $config, $dir );
		// The writer's text is a brief; callers that hand recipes already cut
		// are read the same way, their texts one after the other.
		$text = '' !== trim( (string) $text ) ? trim( (string) $text ) : trim( implode( "\n\n", array_map( static function ( $recipe ) { return (string) ( $recipe['text'] ?? '' ); }, $recipes ) ) );
		$none = array( 'pairs' => array(), 'reasoning' => '', 'cost_usd' => 0.0, 'seconds' => 0.0, 'errors' => array() );
		if ( '' === $text && ! $seen['images'] ) {
			$decision = $none;
			$recipes = array();
		} elseif ( '' === $text ) {
			// No brief: the recipes are the dishes the photographs show.
			$decision = self::propose( $seen['images'], $config );
			$recipes = $decision['recipes'];
		} elseif ( 1 === count( $seen['images'] ) && false === strpos( $text, "\n" ) && self::all_of( array( 'title' => $text ), $seen['images'] ) ) {
			// A brief of one line naming the dish its one photograph shows:
			// there is nothing for a paid call to decide. Two photographs are
			// always read: two versions of one dish are two recipes. A photograph
			// with no dish on it still waits for the writer, as normalise() promises.
			$recipes = array( array( 'title' => MSRWA_Intake::title_of( $text ), 'text' => $text ) );
			$decision = $none;
			foreach ( array_keys( $seen['images'] ) as $index ) { $decision['pairs'][] = array( 'image' => $index, 'recipe' => 0, 'confidence' => 'haute', 'why' => 'Seule recette du lot.', 'reason' => 'only_recipe' ); }
		} else {
			$decision = self::read( $text, $seen['images'], $config, $recipes ? $recipes : MSRWA_Intake::recipes( $text ) );
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

	/** Characters of a brief the reading is handed: a prompt has a size. */
	const MAX_BRIEF = 30000;

	/**
	 * One text call that reads the writer's brief and the photographs
	 * together, and decides the lot's recipes.
	 *
	 * The text is never cut by lines or separators: it is a brief, read as a
	 * whole. Every dish it asks for is a recipe, with the writer's own words
	 * about it copied out, and what it asks of every recipe travels with each.
	 * The brief comes first in the pairing too: a photograph goes with the dish
	 * the brief asks for that it shows; a dish photographed that the brief
	 * does not name is a recipe of its own, unless the brief rules it out; and
	 * a brief that names no dish is applied to the dishes the photographs show.
	 */
	private static function read( $text, array $images, MSRWA_Engine_Config $config, array $fallback ) {
		$out = array( 'recipes' => $fallback, 'pairs' => array(), 'reasoning' => '', 'cost_usd' => 0.0, 'seconds' => 0.0, 'errors' => array() );
		$route = $config->model_for( 'canonical_recipe' );
		$wire = $config->provider( $route['provider'], $route['model'], 'canonical_recipe' );
		$started = microtime( true );
		$brief = mb_substr( (string) $text, 0, self::MAX_BRIEF );
		// The answer copies the brief out, recipe by recipe: room for all of it.
		$room = (int) min( 16000, 2000 + mb_strlen( $brief ) );
		$answer = MSRWA_Engine_Call::text( $route['provider'], $route['model'], self::read_prompt( $brief, $images, self::language( $config ) ), $room, true, false, $wire );
		$out['cost_usd'] = (float) $config->price( $route['provider'], $route['model'], (array) ( $answer['usage'] ?? array() ) );
		$out['seconds'] = round( microtime( true ) - $started, 1 );
		$decoded = MSRWA_Json::decode( (string) ( $answer['text'] ?? '' ) );
		$read = is_array( $decoded ) ? self::understood( $decoded, $images ) : null;
		if ( ! $read ) {
			// Unreadable, or no dish in it: the plain first cut, and every
			// photograph waits for the writer rather than go where nobody decided.
			$out['errors'][] = 'Lecture du lot illisible : ' . mb_substr( (string) ( $answer['error'] ?? $answer['text'] ?? '' ), 0, 200 );
			return $out;
		}
		return array_merge( $out, $read );
	}

	/**
	 * The reading's answer, checked: titles and texts are plain text, a recipe
	 * the answer named from photographs that no photograph went to is dropped,
	 * one named from photographs that is plainly a dish of the brief is that
	 * recipe, and what the brief asks of every recipe is added to each. Null
	 * when no recipe is left.
	 */
	private static function understood( array $decoded, array $images ) {
		// Model output is data, never markup.
		$plain = static function ( $value, $max ) { return mb_substr( trim( wp_strip_all_tags( (string) $value ) ), 0, $max ); };
		$general = $plain( $decoded['general'] ?? '', 4000 );
		$asked = array();
		foreach ( array_values( (array) ( $decoded['recipes'] ?? array() ) ) as $key => $recipe ) {
			if ( ! is_array( $recipe ) ) { continue; }
			$title = MSRWA_Intake::plain_title( $plain( $recipe['title'] ?? '', 180 ) );
			if ( '' === $title ) { continue; }
			$asked[ $key ] = array( 'title' => $title, 'photos' => 'photos' === ( $recipe['from'] ?? '' ), 'brief' => $plain( $recipe['brief'] ?? '', 20000 ) );
		}
		$text_recipes = array_filter( $asked, static function ( $recipe ) { return ! $recipe['photos']; } );
		$pairs = array();
		$taken = array();
		foreach ( (array) ( $decoded['pairs'] ?? array() ) as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['image'] ) || ! isset( $images[ (int) $pair['image'] ] ) ) { continue; }
			$recipe = isset( $pair['recipe'] ) && is_numeric( $pair['recipe'] ) && isset( $asked[ (int) $pair['recipe'] ] ) ? (int) $pair['recipe'] : null;
			$one = array( 'image' => (int) $pair['image'], 'recipe' => $recipe, 'confidence' => (string) ( $pair['confidence'] ?? 'basse' ), 'why' => $plain( $pair['why'] ?? '', 300 ) );
			// A dish said to be new that is plainly one the brief asks for is that one.
			if ( null !== $recipe && $asked[ $recipe ]['photos'] ) {
				$known = self::closest( $asked[ $recipe ]['title'], $text_recipes );
				if ( null !== $known ) {
					$one['recipe'] = $known;
					$one['confidence'] = 'moyenne';
				} else {
					$one = array_merge( $one, array( 'confidence' => 'moyenne', 'why' => 'Plat absent du texte : une recette de plus, d’après la photographie.', 'reason' => 'new_recipe', 'new_recipe' => true ) );
				}
			}
			// Ruled out by the brief itself: set aside, which the writer asked for
			// and can still undo on the pairing screen.
			if ( null === $recipe && ! empty( $pair['excluded'] ) ) { $one['reason'] = 'excluded'; }
			if ( null !== $one['recipe'] ) { $taken[ $one['recipe'] ] = true; }
			$pairs[] = $one;
		}
		$recipes = array();
		$shift = array();
		foreach ( $asked as $key => $recipe ) {
			// A dish from photographs no photograph went to was never shown.
			if ( $recipe['photos'] && ! isset( $taken[ $key ] ) ) { continue; }
			$shift[ $key ] = count( $recipes );
			if ( $recipe['photos'] ) {
				$local = array();
				foreach ( $pairs as $pair ) { if ( $key === $pair['recipe'] ) { $local[] = array( 'image' => $pair['image'], 'recipe' => 0 ); } }
				$one = self::from_photographs( $recipe['title'], $images, $local, 0, true );
			} else {
				$one = array( 'title' => $recipe['title'], 'text' => '' !== $recipe['brief'] ? $recipe['brief'] : $recipe['title'] );
			}
			if ( '' !== $general ) { $one['text'] .= "\n\n" . 'Consignes du rédacteur pour tout le lot : ' . $general; }
			$recipes[] = $one;
		}
		if ( ! $recipes ) { return null; }
		foreach ( $pairs as $key => $pair ) {
			if ( null !== $pair['recipe'] ) { $pairs[ $key ]['recipe'] = $shift[ $pair['recipe'] ] ?? null; }
		}
		return array( 'recipes' => $recipes, 'pairs' => $pairs, 'reasoning' => $plain( $decoded['reasoning'] ?? '', 400 ), 'general' => $general );
	}

	/**
	 * The brief's recipe a dish name plainly is, or null: the same words of
	 * four letters or more, in any order, accents and case aside, and only one
	 * recipe answers. "Yassa au poulet" is the "Poulet yassa"; a "Tarte aux
	 * pommes et amandes" is another version of the "Tarte aux pommes", which
	 * the owner wants as a recipe of its own (2026-09-28).
	 */
	private static function closest( $dish, array $recipes ) {
		$words = static function ( $text ) {
			return array_values( array_filter( preg_split( '/[^\p{L}\p{N}]+/u', MSRWA_Engine_Score::fold( (string) $text ) ), static function ( $word ) { return mb_strlen( $word ) >= 4; } ) );
		};
		$named = $words( $dish );
		if ( ! $named ) { return null; }
		$found = array();
		foreach ( $recipes as $index => $recipe ) {
			$title = $words( (string) ( $recipe['title'] ?? '' ) );
			if ( $title && ! array_diff( $named, $title ) && ! array_diff( $title, $named ) ) { $found[] = $index; }
		}
		return 1 === count( $found ) ? $found[0] : null;
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
				// A collage the writer made (steps, or ingredients and the finished
				// dish) is offered as the recipe's
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
			$titles[] = mb_substr( MSRWA_Intake::plain_title( wp_strip_all_tags( (string) ( is_array( $recipe ) ? ( $recipe['title'] ?? '' ) : $recipe ) ) ), 0, 180 );
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
		$title = mb_substr( MSRWA_Intake::plain_title( wp_strip_all_tags( (string) $title ) ), 0, 180 );
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
			$lines[] = sprintf( '%d. fichier « %s »%s — plat reconnu : %s — %s', $index, self::file_label( $image ), empty( $image['collage'] ) ? '' : ' (collage Facebook)', '' !== $image['dish'] ? $image['dish'] : 'non identifié', $image['describes'] ) . self::details( $image );
		}
		$lines[] = '';
		$lines[] = 'RÈGLES :';
		$lines[] = '- Plusieurs photographies vont à la même recette seulement si elles montrent exactement la même préparation : mêmes ingrédients visibles, même garniture, même cuisson, même présentation du plat. Deux versions d’un même plat (avec ou sans amandes, au poulet ou au chèvre, gratinée ou non) sont deux recettes, même si leur nom est le même ; donne-leur alors des titres qui les distinguent.';
		$lines[] = '- Un collage Facebook (étapes en photographies, ou ingrédients et plat fini) va à la recette du plat qu’il montre, comme une photographie de plus.';
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
			. '"collage", true when the image is a recipe post made of several pictures — a grid of photographs of the recipe being made step by step, or a recipe card that sets pictures of the ingredients, often with their quantities, beside the finished dish, usually under the recipe\'s title — and false for a single photograph of a dish, even with a caption or a watermark. '
			. 'Describe only what is visible; never invent a hidden ingredient, a quantity or an origin.';
	}

	/**
	 * What the reading is asked: the writer's brief as they wrote it, the
	 * photographs as they were described, and how to decide the recipes.
	 */
	private static function read_prompt( $brief, array $images, $language = 'français' ) {
		$out = array( 'Un rédacteur a écrit la consigne ci-dessous, librement : des noms de plats, des recettes complètes ou en partie, des consignes pour tout le lot, dans n’importe quelle mise en forme. Lis-la comme un brief, en entier.' );
		if ( $images ) { $out[] = 'Il a aussi fourni ' . count( $images ) . ' photographie(s), sans dire lesquelles vont avec quoi.'; }
		$out[] = '';
		$out[] = 'CONSIGNE DU RÉDACTEUR :';
		$out[] = '<<<';
		$out[] = $brief;
		$out[] = '>>>';
		$out[] = '';
		if ( $images ) {
			$out[] = 'PHOTOGRAPHIES, décrites depuis leurs propres pixels :';
			foreach ( $images as $index => $image ) {
				// A collage is one more view of its dish, never a dish of its own.
				$out[] = sprintf( '%d. fichier « %s »%s — plat reconnu : %s — %s', $index, self::file_label( $image ), empty( $image['collage'] ) ? '' : ' (collage Facebook)', '' !== (string) ( $image['dish'] ?? '' ) ? $image['dish'] : 'non identifié', (string) ( $image['describes'] ?? '' ) ) . self::details( $image );
			}
			$out[] = '';
		}
		$out[] = 'DÉCIDE LES RECETTES DU LOT — LA CONSIGNE PRIME :';
		$out[] = '- Chaque plat que la consigne demande est une recette ("from": "text"), avec ou sans photographie. N’en oublie aucun, n’en fusionne pas deux, n’en invente aucun. Une liste de plats est autant de recettes ; un plat suivi de ses ingrédients, étapes ou remarques est une seule recette.';
		$out[] = '- "brief" : recopie mot pour mot tout ce que la consigne dit de ce plat — son nom, ses ingrédients, ses étapes, ses remarques —, sans résumer ni reformuler.';
		$out[] = '- "general" : recopie mot pour mot ce que la consigne demande pour toutes les recettes (régime, public, ton, contraintes), ou "". Une simple phrase d’introduction n’est pas une consigne.';
		$out[] = '- Le titre est le nom usuel du plat en ' . $language . ', sans numéro ni décoration.';
		if ( $images ) {
			$out[] = '- Associe chaque photographie à la recette de la consigne dont elle montre le plat, en comparant ce qu’elle montre au nom ET au contenu de la recette : une « tarte normande » faite de pommes est la photographie d’une tarte aux pommes. Partager l’ingrédient principal ne suffit pas : ce doit être la même préparation ; une tarte aux pommes n’est pas des pommes au four.';
			$out[] = '- Il peut y avoir moins de photographies que de recettes : des recettes restent sans photographie, c’est normal. Deux plats différents ne vont pas à la même recette.';
			$out[] = '- Plusieurs photographies vont à la même recette seulement si elles montrent exactement la même préparation : mêmes ingrédients visibles, même garniture, même cuisson. Deux versions d’un même plat (avec ou sans amandes, au poulet ou au chèvre, gratinée ou non) ne vont pas ensemble, même sous le même nom : la version qui correspond à la recette de la consigne va à elle, l’autre devient une recette de plus ("from": "photos") dont le titre dit ce qui la distingue.';
			$out[] = '- Un plat photographié que la consigne ne demande pas devient une recette de plus ("from": "photos", "brief": ""), nommée d’après le plat ; toutes ses photographies vont à elle. Si la consigne ne nomme aucun plat (par exemple « des recettes légères pour ces photos »), les recettes sont les plats des photographies et la consigne va dans "general". Si la consigne exclut un plat photographié, n’en fais pas une recette : "recipe": null et "excluded": true.';
			$out[] = '- Le nom du fichier est un indice faible ; ce que montre la photographie prime.';
			$out[] = '- Un collage Facebook — les étapes en photographies, ou les ingrédients et le plat fini — montre un seul plat : il va à la recette de ce plat, comme une photographie de plus, et ne fait jamais une recette à lui seul ; son titre imprimé est le nom de ce plat.';
			$out[] = '- Si tu hésites entre deux recettes, choisis la plus probable avec "confidence": "basse". "recipe": null est réservé à une photographie où aucun plat n’est visible, ou exclue par la consigne.';
		}
		$out[] = '';
		$out[] = 'RÉPONSE — un objet JSON valide, sans Markdown, avec exactement ces clés :';
		$out[] = '- "recipes" : tableau de {"title": le nom du plat, "from": "text" ou "photos", "brief": les passages recopiés}';
		$out[] = '- "general" : les consignes pour toutes les recettes, recopiées, ou ""';
		if ( $images ) { $out[] = '- "pairs" : tableau de {"image": numéro de la photographie, "recipe": indice dans "recipes" (à partir de 0) ou null, "excluded": true si la consigne l’exclut, "confidence": "haute"|"moyenne"|"basse", "why": une phrase en ' . $language . '}'; }
		$out[] = '- "reasoning" : une phrase sur la façon dont le lot se répartit';
		return implode( "\n", $out );
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
			$reason = in_array( (string) ( $pair['reason'] ?? '' ), array( 'only_recipe', 'new_recipe', 'no_dish', 'not_mentioned', 'excluded' ), true ) ? (string) $pair['reason'] : '';
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
			// Unless their brief itself ruled it out: set aside, and theirs to undo.
			if ( null === $recipe && 'excluded' === $reason ) { $one['set_aside'] = true; } elseif ( null === $recipe ) { $one['pending'] = true; }
			$out[] = $one;
		}
		// A photograph the answer never mentioned is unassigned, not missing.
		foreach ( array_keys( $images ) as $image ) {
			if ( ! isset( $taken[ $image ] ) ) { $out[] = array( 'image' => (int) $image, 'recipe' => null, 'confidence' => 'basse', 'why' => 'Non mentionnée par l’appariement.', 'reason' => 'not_mentioned', 'pending' => true ); }
		}
		return $out;
	}

	/**
	 * What a photograph shows in detail — its ingredients, garnish, cooking —
	 * from its reading: two versions of one dish are told apart on these, not
	 * on a name.
	 */
	private static function details( array $image ) {
		$seen = array();
		foreach ( array( 'observable_details', 'composition' ) as $key ) {
			$value = $image['observation'][ $key ] ?? '';
			$value = is_array( $value ) ? implode( ' ; ', array_map( static function ( $one ) { return is_scalar( $one ) ? (string) $one : wp_json_encode( $one ); }, $value ) ) : (string) $value;
			if ( '' !== trim( $value ) ) { $seen[] = trim( $value ); }
		}
		return $seen ? ' — détails : ' . mb_substr( implode( ' ; ', $seen ), 0, 600 ) : '';
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
