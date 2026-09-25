<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One submission: several recipes, several photographs, one dispatch.
 *
 * The writer hands everything over at once. The batch has the photographs
 * described and paired with the recipes, shows him the pairing to confirm or
 * correct, then builds a brief per recipe and sends every one of them to the
 * engine. From that point the runs advance together — each carried by its own
 * cron tick — and the batch is only their sum.
 */
final class MSRWA_Batch {

	private static function table() {
		$t = MSRWA_DB::tables();
		return $t['batches'];
	}

	/**
	 * Records a submission and has its photographs matched.
	 *
	 * Matching costs money — one vision call per photograph — so it happens
	 * once, here, and its result is stored. Correcting a pairing afterwards is
	 * free because nothing is described twice.
	 */
	public static function create( array $recipes, array $files, $budget_per_recipe, $profile = MSRWA_Profile::FULL, $language = 'fr', array $config_overrides = array() ) {
		global $wpdb;
		// A text, photographs, or both: photographs alone name their recipes once described.
		if ( ! $recipes && ! $files ) { return new WP_Error( 'msrwa_no_recipes', __( 'Collez au moins une recette ou ajoutez au moins une photographie.', 'ms-recipes-writer-ai' ) ); }
		$budget = round( (float) $budget_per_recipe, 4 );
		if ( $budget <= 0 ) { return new WP_Error( 'msrwa_no_budget', __( 'Fixez un plafond de dépense par recette.', 'ms-recipes-writer-ai' ) ); }

		$now = current_time( 'mysql', true );
		$wpdb->insert( self::table(), array(
			'owner_id' => get_current_user_id(),
			'label' => $recipes ? self::label( $recipes ) : __( 'Photographies à reconnaître', 'ms-recipes-writer-ai' ),
			'status' => 'matching', 'recipes' => count( $recipes ), 'images' => count( $files ),
			'budget_usd' => $budget,
			'profile' => MSRWA_Profile::exists( $profile ) ? $profile : MSRWA_Profile::FULL,
			'language' => MSRWA_Profile::language_exists( $language ) ? $language : 'fr',
			'config_json' => wp_json_encode( $config_overrides ),
			'matching_json' => wp_json_encode( array( 'recipes' => $recipes, 'images' => array(), 'pairs' => array() ) ),
			'created_at' => $now, 'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) { return new WP_Error( 'msrwa_not_created', __( 'Le lot n’a pas pu être enregistré.', 'ms-recipes-writer-ai' ) ); }

		// Kept with the lot, named after their bytes: the same photograph sent
		// twice is one file, never a name that already exists.
		$images = MSRWA_Sources::receive( $id, $files );
		MSRWA_History::lot( $id, 'provided', array(
			'owner' => get_current_user_id(), 'language' => $language, 'profile' => $profile,
			'facebook_template' => (string) ( $config_overrides['images']['facebook_template'] ?? '' ),
			'recipes' => $recipes,
			'photos' => array_map( static function ( $image ) { return array( 'file' => $image['id'], 'ref' => $image['ref'], 'origin' => $image['origin'], 'source' => $image['source'], 'name' => $image['file'], 'mime' => $image['mime'] ); }, $images ),
		) );
		$match = MSRWA_Match::run( $recipes, $images, self::engine_config( self::config_overrides( $id ) ), MSRWA_Sources::lot_dir( $id ) );
		$recipes = $match['recipes'];
		// Counted before anything else can happen to the lot: a lot in which no
		// dish is recognised is deleted, and what reading it cost is not.
		MSRWA_Spend::matching( get_current_user_id(), $id, (float) $match['cost_usd'] );
		MSRWA_History::lot( $id, 'matching', array(
			'readings' => array_map( static function ( $image ) { return array( 'file' => $image['id'], 'ref' => MSRWA_Sources::ref( $image['id'] ), 'dish' => $image['dish'] ?? '', 'description' => $image['describes'] ?? '', 'observation' => $image['observation'] ?? array() ); }, $match['images'] ),
			'recipes' => $recipes, 'pairs' => $match['pairs'], 'reasoning' => $match['reasoning'],
			'cost_usd' => $match['cost_usd'], 'seconds' => $match['seconds'], 'errors' => $match['errors'],
		) );
		if ( ! $recipes ) {
			// Photographs in which no dish could be named leave nothing to write.
			MSRWA_History::forget_lot( $id );
			MSRWA_Sources::forget_lot( $id );
			$wpdb->delete( self::table(), array( 'id' => $id ) );
			return new WP_Error( 'msrwa_no_dish', __( 'Aucun plat n’a été reconnu sur ces photographies. Ajoutez le nom de chaque recette dans le texte, avec ou sans les photographies.', 'ms-recipes-writer-ai' ) );
		}
		$wpdb->update( self::table(), array(
			'status' => 'ready',
			'label' => self::label( $recipes ),
			'recipes' => count( $recipes ),
			'images' => count( $images ),
			'matching_json' => wp_json_encode( MSRWA_DB::sanitize( array(
				'recipes' => $recipes, 'images' => $match['images'], 'pairs' => $match['pairs'],
				'reasoning' => $match['reasoning'], 'cost_usd' => $match['cost_usd'], 'seconds' => $match['seconds'],
			) ) ),
			'error_message' => $match['errors'] ? implode( ' | ', array_slice( $match['errors'], 0, 5 ) ) : '',
			'updated_at' => current_time( 'mysql', true ),
		), array( 'id' => $id ) );
		return $id;
	}

	/** A lot is known by its first recipe, and how many follow. */
	/**
	 * What is stored: the first recipe's title alone. The "and N others" is
	 * said at display, in the reader's language — stored, it came out in
	 * whichever language saved the lot last, English on an Arabic screen.
	 */
	private static function label( array $recipes ) {
		return mb_substr( (string) ( $recipes[0]['title'] ?? '' ), 0, 190 );
	}

	/** A lot's name as a reader sees it: its first recipe, and how many follow. */
	public static function title( array $batch ) {
		// Lots named before 0.25.3 carry the count in their stored label.
		$first = (string) preg_replace( '/\\s+(?:et|and)\\s+\\d+\\s+(?:autres?|others?)$|\\s+و\\S*(?:\\s+\\S+)?\\s+أخر\\S*$/u', '', (string) ( $batch['label'] ?? '' ) );
		$more = max( 0, (int) ( $batch['recipes'] ?? 1 ) - 1 );
		if ( '' === $first ) { return ''; }
		return $first . ( $more ? sprintf( /* translators: %d is how many further recipes the lot carries. */ _n( ' et %d autre', ' et %d autres', $more, 'ms-recipes-writer-ai' ), $more ) : '' );
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * The lots that have not started: scheduled, or still waiting to be sent.
	 *
	 * Until now a lot left the screen the moment you did. One scheduled for
	 * nine tomorrow, or paired and never dispatched, existed only in the
	 * database — which is the same as not existing to the person who made it.
	 *
	 * Soonest departure first, then the most recently made, because a lot with
	 * an hour on it is the one with a deadline.
	 */
	public static function waiting( $limit = 10 ) {
		global $wpdb;
		$limit = max( 1, min( 50, (int) $limit ) );
		$columns = 'id, owner_id, label, recipes, images, profile, language, dispatch_at, created_at';
		$order = 'ORDER BY (dispatch_at IS NULL), dispatch_at ASC, id DESC LIMIT %d';

		if ( MSRWA_Rights::may_see_everything() ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM " . self::table() . " WHERE status = 'ready' {$order}", $limit ), ARRAY_A );
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM " . self::table() . " WHERE status = 'ready' AND owner_id = %d {$order}", get_current_user_id(), $limit ), ARRAY_A );
	}

	public static function may_see( array $batch ) {
		return MSRWA_Rights::may_see( (int) $batch['owner_id'] );
	}

	public static function matching( $id ) {
		$batch = self::get( $id );
		$matching = $batch ? json_decode( (string) $batch['matching_json'], true ) : null;
		return is_array( $matching ) ? $matching : array( 'recipes' => array(), 'images' => array(), 'pairs' => array() );
	}

	/**
	 * Replaces the pairing with the writer's own, which is always the last word.
	 *
	 * A photograph the writer left where the model put it keeps the model's
	 * confidence and reason; one they moved is marked as theirs. The pairing
	 * is saved on every change now, and marking every row as confirmed erased
	 * what the model said about the ones nobody had looked at yet.
	 */
	public static function repair( $id, array $pairs ) {
		global $wpdb;
		$matching = self::matching( $id );
		$before = array();
		foreach ( (array) ( $matching['pairs'] ?? array() ) as $pair ) {
			if ( isset( $pair['image'] ) ) { $before[ (int) $pair['image'] ] = $pair; }
		}
		$clean = array();
		$taken = array();
		foreach ( $pairs as $pair ) {
			$image = isset( $pair['image'] ) ? (int) $pair['image'] : -1;
			if ( $image < 0 || $image >= count( (array) $matching['images'] ) || isset( $taken[ $image ] ) ) { continue; }
			$choice = (string) ( $pair['recipe'] ?? '' );
			$taken[ $image ] = true;
			// Unticked, a collage is an ordinary photograph of the dish, and the
			// recipe's collage is drawn again.
			if ( array_key_exists( 'collage', $pair ) && ! empty( $matching['images'][ $image ]['collage'] ) ) {
				$matching['images'][ $image ]['collage_off'] = ! $pair['collage'];
			}
			$was = $before[ $image ] ?? null;
			// Three choices beside a recipe: set the photograph aside, make it a
			// recipe of its own under a name the writer gives, or leave it
			// undecided — which holds the lot back.
			if ( 'aside' === $choice ) {
				$clean[] = array( 'image' => $image, 'recipe' => null, 'confidence' => 'haute', 'why' => '', 'by_writer' => true, 'set_aside' => true );
				continue;
			}
			if ( 'new' === $choice ) {
				$recipe = MSRWA_Match::named( (string) ( $pair['title'] ?? '' ), (array) $matching['images'][ $image ] );
				if ( $recipe ) {
					$matching['recipes'][] = $recipe;
					$clean[] = array( 'image' => $image, 'recipe' => count( $matching['recipes'] ) - 1, 'confidence' => 'haute', 'why' => '', 'by_writer' => true, 'new_recipe' => true );
					continue;
				}
				$choice = '';
			}
			$recipe = '' !== $choice && ctype_digit( $choice ) ? (int) $choice : null;
			if ( null !== $recipe && $recipe >= count( (array) $matching['recipes'] ) ) { $recipe = null; }
			if ( null === $recipe ) {
				$clean[] = array( 'image' => $image, 'recipe' => null, 'confidence' => 'basse', 'why' => (string) ( $was['why'] ?? '' ), 'pending' => true );
				continue;
			}
			$kept = $was && null !== ( $was['recipe'] ?? null ) && (int) $was['recipe'] === $recipe;
			$clean[] = $kept ? array_merge( $was, array( 'image' => $image, 'recipe' => $recipe ) ) : array( 'image' => $image, 'recipe' => $recipe, 'confidence' => 'haute', 'why' => '', 'by_writer' => true );
		}
		// A photograph the request did not mention keeps where it was: a save
		// that names one photograph must not drop the others from the pairing.
		foreach ( $before as $image => $was ) {
			if ( ! isset( $taken[ $image ] ) && $image < count( (array) $matching['images'] ) ) { $clean[] = $was; }
		}
		usort( $clean, static function ( $a, $b ) { return (int) $a['image'] - (int) $b['image']; } );
		$matching['pairs'] = $clean;
		$wpdb->update( self::table(), array(
			'matching_json' => wp_json_encode( $matching ),
			'recipes' => count( (array) $matching['recipes'] ),
			'label' => self::label( (array) $matching['recipes'] ),
			'updated_at' => current_time( 'mysql', true ),
		), array( 'id' => absint( $id ) ) );
		MSRWA_History::lot( $id, 'pairing', array( 'by' => get_current_user_id(), 'pairs' => $clean ) );
		return true;
	}

	/** How many of a lot's photographs still wait for the writer's decision. */
	public static function undecided( $id ) {
		$matching = self::matching( $id );
		$decided = array();
		$waiting = 0;
		foreach ( (array) ( $matching['pairs'] ?? array() ) as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['image'] ) ) { continue; }
			if ( ! empty( $pair['pending'] ) ) { $waiting++; continue; }
			$decided[ (int) $pair['image'] ] = true;
		}
		// A photograph the pairing does not mention at all waits too.
		foreach ( array_keys( (array) ( $matching['images'] ?? array() ) ) as $image ) {
			if ( ! isset( $decided[ (int) $image ] ) && ! self::mentioned( $matching, (int) $image ) ) { $waiting++; }
		}
		return $waiting;
	}

	private static function mentioned( array $matching, $image ) {
		foreach ( (array) ( $matching['pairs'] ?? array() ) as $pair ) { if ( is_array( $pair ) && (int) ( $pair['image'] ?? -1 ) === $image ) { return true; } }
		return false;
	}

	/**
	 * Builds a brief per recipe and sends every one of them off at once.
	 *
	 * Each run then advances on its own cron tick, so the recipes progress
	 * together rather than one after another: the engine's own waves are
	 * concurrent inside a run, and the runs are concurrent inside the batch.
	 */
	public static function dispatch( $id ) {
		global $wpdb;
		$batch = self::get( $id );
		if ( ! $batch ) { return new WP_Error( 'msrwa_no_batch', __( 'Lot introuvable.', 'ms-recipes-writer-ai' ) ); }
		if ( 'ready' !== $batch['status'] ) { return new WP_Error( 'msrwa_not_ready', __( 'Ce lot a déjà été lancé.', 'ms-recipes-writer-ai' ) ); }
		$undecided = self::undecided( $id );
		if ( $undecided ) {
			/* translators: %d is a number of photographs. */
			return new WP_Error( 'msrwa_undecided', sprintf( _n( 'Une photographie attend votre décision : associez-la à une recette, faites-en une recette ou écartez-la.', '%d photographies attendent votre décision : associez-les à une recette, faites-en des recettes ou écartez-les.', $undecided, 'ms-recipes-writer-ai' ), $undecided ) );
		}

		// Refusing to start is free; stopping halfway is not. The estimate is
		// what the site is about to commit, so the ceiling is checked against
		// it rather than only against what has already been spent.
		// A route the catalogue says cannot serve its step would fail on the
		// first recipe, after paying for everything before it: refused here.
		$problems = class_exists( 'MSRWA_Compat' ) ? MSRWA_Compat::problems( self::config_overrides( $id ) ) : array();
		if ( $problems ) {
			return new WP_Error( 'msrwa_incompatible_route', MSRWA_Rights::may_manage()
				/* translators: %s lists each refused step, its route and the reason. */
				? sprintf( __( 'Ce lot ne peut pas partir : une étape est confiée à un modèle qui ne peut pas la faire. %s Changez-le sur l’écran Moteur.', 'ms-recipes-writer-ai' ), MSRWA_Compat::describe( $problems ) )
				: __( 'Ce lot ne peut pas partir : le réglage des modèles du site doit être corrigé par un administrateur.', 'ms-recipes-writer-ai' ) );
		}
		$estimate = MSRWA_Estimate::lot( $batch['profile'], (int) $batch['recipes'], 0, self::config_overrides( $id ) );
		$refusal = MSRWA_Budget::refusal( (float) $estimate['cost_usd'] );
		if ( '' !== $refusal ) { return new WP_Error( 'msrwa_over_budget', $refusal ); }
		// The per-recipe ceiling stops a run before the step that would cross it.
		// A recipe expected to cost more than its ceiling would be stopped part
		// way through, having paid for everything before the stop and delivered
		// nothing — so it is refused here, where refusing costs nothing.
		if ( ! MSRWA_Estimate::fits( (float) $estimate['per_recipe_usd'], (float) $batch['budget_usd'] ) ) {
			return new WP_Error( 'msrwa_over_ceiling', MSRWA_Rights::may_see_money()
				/* translators: 1: estimated cost per recipe, 2: the per-recipe ceiling. */
				? sprintf( __( 'Une recette de ce lot est estimée à %1$s, au-dessus de son plafond de %2$s : elle s’arrêterait en route après avoir dépensé. Relevez le plafond ou choisissez une sortie plus légère.', 'ms-recipes-writer-ai' ), MSRWA_I18N::money( (float) $estimate['per_recipe_usd'], 4 ), MSRWA_I18N::money( (float) $batch['budget_usd'], 2 ) )
				: __( 'Ce lot dépasse le plafond par recette fixé pour le site : il s’arrêterait en route. Choisissez une sortie plus légère, ou demandez à un administrateur de relever le plafond.', 'ms-recipes-writer-ai' )
			);
		}

		$matching = self::matching( $id );
		$config = self::config_overrides( $id );
		$started = 0;

		foreach ( (array) $matching['recipes'] as $index => $recipe ) {
			$images = array();
			foreach ( (array) $matching['pairs'] as $pair ) {
				if ( (int) $index === (int) ( $pair['recipe'] ?? -1 ) && isset( $matching['images'][ $pair['image'] ] ) ) {
					$images[] = $matching['images'][ $pair['image'] ];
				}
			}
			// A recipe named after photographs the writer then set aside has
			// nothing left to be written from.
			if ( ! empty( $recipe['from_photographs'] ) && ! $images ) { continue; }
			$brief = MSRWA_Match::brief( $recipe, $images );
			// What the article may file itself under: this site's categories, not a model's guess.
			$brief['site_categories'] = MSRWA_Stack::site_categories();
			// The writer's own Facebook collage, when one of its photographs is
			// one and they kept it ticked, leads the recipe and is not drawn
			// again; a complete lot without one has its collage drawn first
			// (ENGINE.md §7, 50).
			$collage = null;
			foreach ( $images as $image ) {
				if ( ! empty( $image['collage'] ) && empty( $image['collage_off'] ) ) { $collage = $image; break; }
			}
			$brief['collage_lead'] = MSRWA_Profile::lead( $batch['profile'], null !== $collage );
			$run = MSRWA_Run::create( (int) $id, (int) $batch['owner_id'], $brief, $config, MSRWA_Profile::run_steps( $batch['profile'], (array) ( $config['steps'] ?? array() ), $brief['collage_lead'] ) );
			if ( $run ) {
				MSRWA_History::inherit( (int) $id, $run );
				MSRWA_Sources::hand_over( (int) $id, $run, $brief );
				if ( 'provided' === $brief['collage_lead'] ) { self::seed_collage( $run, $collage ); }
				$started++;
			}
		}
		// Every recipe has its photographs now; a photograph no recipe took goes.
		MSRWA_Sources::forget_lot( (int) $id );

		$wpdb->update( self::table(), array( 'status' => $started ? 'running' : 'failed', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $id ) ) );
		return $started;
	}

	/**
	 * The writer's collage as the run's Facebook image: the file handed over to
	 * the run, marked as provided, read by every later step and attached to the
	 * draft exactly as a drawn one would be.
	 */
	private static function seed_collage( $run, array $collage ) {
		$source = MSRWA_Sources::path( MSRWA_Sources::run_dir( $run ), (string) ( $collage['id'] ?? '' ) );
		if ( '' === $source ) { return; }
		// Attaching an image to the draft moves its file into the media library:
		// the copy goes, and the writer's photograph stays where the lot and the
		// recipe's record show it.
		$path = MSRWA_Run::workspace( $run ) . '/facebook-provided-' . basename( $source );
		if ( ! @copy( $source, $path ) ) { return; } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		MSRWA_Run::seed( $run, 'facebook', array(
			'kind' => 'facebook', 'path' => $path, 'bytes' => (int) filesize( $path ), 'mime' => (string) ( $size['mime'] ?? 'image/jpeg' ),
			'size' => $size ? (int) $size[0] . 'x' . (int) $size[1] : '', 'provided' => true, 'file' => (string) ( $collage['id'] ?? '' ),
		) );
	}

	/** A batch with work in it again is not a finished batch. */
	public static function reopen( $id ) {
		global $wpdb;
		if ( ! $id ) { return; }
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'running', updated_at = %s WHERE id = %d AND status IN ('done','failed')", current_time( 'mysql', true ), absint( $id ) ) );
	}

	/** Closes a batch once none of its runs is still moving. */
	public static function settle( $id ) {
		global $wpdb;
		if ( ! $id ) { return; }
		$runs = MSRWA_Run::for_batch( $id );
		foreach ( $runs as $run ) {
			if ( in_array( $run['status'], array( 'queued', 'running' ), true ) ) { return; }
		}
		$failed = 0;
		foreach ( $runs as $run ) { if ( 'done' !== $run['status'] ) { $failed++; } }
		$wpdb->update( self::table(), array( 'status' => $failed === count( $runs ) ? 'failed' : 'done', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $id ) ) );
	}

	/** What the batch asked of the engine, over what the site asked of it. */
	public static function config_overrides( $id ) {
		$batch = self::get( $id );
		$overrides = $batch ? (array) json_decode( (string) $batch['config_json'], true ) : array();
		$config = MSRWA_Engine_Settings::merge( MSRWA_Engine_Settings::stored(), $overrides );
		if ( $batch ) {
			// What this batch asked to produce, and in which language, expressed
			// as the engine's own configuration rather than as a special case.
			$config = MSRWA_Engine_Settings::merge( $config, MSRWA_Profile::config( $batch['profile'], $batch['language'], (array) ( $config['steps'] ?? array() ) ) );
			$config['limits']['budget_usd'] = (float) $batch['budget_usd'];
		}
		$config['settings'] = array_merge( MSRWA_Settings::engine_settings(), (array) ( $config['settings'] ?? array() ) );
		// The owner's style references, or the shipped one, when the writer sent no photograph of the dish.
		if ( class_exists( 'MSRWA_Sources' ) ) { $config['images']['style_references'] = MSRWA_Sources::style_in_use(); }
		return $config;
	}

	public static function config_for( $id ) { return self::config_overrides( $id ); }

	private static function engine_config( array $overrides ) { return MSRWA_Engine_Config::create( $overrides ); }

	/** Deletes a batch, its runs and everything the engine reported about them. */
	public static function delete( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		// The photographs this lot uploaded and no draft took would otherwise
		// stay in the media library with nothing pointing at them.
		MSRWA_Intake::forget( array_filter( array_column( (array) ( self::matching( $id )['images'] ?? array() ), 'id' ), 'is_int' ) );
		MSRWA_Sources::forget_lot( $id );
		MSRWA_History::forget_lot( $id );
		foreach ( MSRWA_Run::for_batch( $id ) as $run ) {
			MSRWA_Sources::forget_run( (int) $run['id'] );
			MSRWA_History::forget_run( (int) $run['id'] );
			foreach ( array( 'steps', 'calls', 'events', 'artifacts' ) as $table ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t[ $table ] . ' WHERE run_id = %d', (int) $run['id'] ) );
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t['runs'] . ' WHERE batch_id = %d', absint( $id ) ) );
		return (bool) $wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );
	}
}
