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
	public static function create( array $recipes, array $images, $budget_per_recipe, $profile = MSRWA_Profile::FULL, $language = 'fr', array $config_overrides = array() ) {
		global $wpdb;
		// A text, photographs, or both: photographs alone name their recipes once described.
		if ( ! $recipes && ! $images ) { return new WP_Error( 'msrwa_no_recipes', __( 'Collez au moins une recette ou ajoutez au moins une photographie.', 'ms-recipes-writer-ai' ) ); }
		$budget = round( (float) $budget_per_recipe, 4 );
		if ( $budget <= 0 ) { return new WP_Error( 'msrwa_no_budget', __( 'Fixez un plafond de dépense par recette.', 'ms-recipes-writer-ai' ) ); }

		$now = current_time( 'mysql', true );
		$wpdb->insert( self::table(), array(
			'owner_id' => get_current_user_id(),
			'label' => $recipes ? self::label( $recipes ) : __( 'Photographies à reconnaître', 'ms-recipes-writer-ai' ),
			'status' => 'matching', 'recipes' => count( $recipes ), 'images' => count( $images ),
			'budget_usd' => $budget,
			'profile' => MSRWA_Profile::exists( $profile ) ? $profile : MSRWA_Profile::FULL,
			'language' => MSRWA_Profile::language_exists( $language ) ? $language : 'fr',
			'config_json' => wp_json_encode( $config_overrides ),
			'matching_json' => wp_json_encode( array( 'recipes' => $recipes, 'images' => $images, 'pairs' => array() ) ),
			'created_at' => $now, 'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) { return new WP_Error( 'msrwa_not_created', __( 'Le lot n’a pas pu être enregistré.', 'ms-recipes-writer-ai' ) ); }

		$match = MSRWA_Match::run( $recipes, $images, self::engine_config( self::config_overrides( $id ) ) );
		$recipes = $match['recipes'];
		if ( ! $recipes ) {
			// Photographs in which no dish could be named leave nothing to write.
			$wpdb->delete( self::table(), array( 'id' => $id ) );
			return new WP_Error( 'msrwa_no_dish', __( 'Aucun plat n’a été reconnu sur ces photographies. Ajoutez le nom de chaque recette dans le texte, avec ou sans les photographies.', 'ms-recipes-writer-ai' ) );
		}
		$wpdb->update( self::table(), array(
			'status' => 'ready',
			'label' => self::label( $recipes ),
			'recipes' => count( $recipes ),
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
	private static function label( array $recipes ) {
		return mb_substr( (string) $recipes[0]['title'], 0, 190 ) . ( count( $recipes ) > 1 ? sprintf( /* translators: %d is how many further recipes the lot carries. */ __( ' et %d autres', 'ms-recipes-writer-ai' ), count( $recipes ) - 1 ) : '' );
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
			$recipe = isset( $pair['recipe'] ) && '' !== $pair['recipe'] && null !== $pair['recipe'] ? (int) $pair['recipe'] : null;
			if ( null !== $recipe && ( $recipe < 0 || $recipe >= count( (array) $matching['recipes'] ) ) ) { $recipe = null; }
			$taken[ $image ] = true;
			$was = $before[ $image ] ?? null;
			$kept = $was && ( null === $recipe ? null === ( $was['recipe'] ?? null ) : null !== ( $was['recipe'] ?? null ) && (int) $was['recipe'] === $recipe );
			$clean[] = $kept ? array_merge( $was, array( 'image' => $image, 'recipe' => $recipe ) ) : array( 'image' => $image, 'recipe' => $recipe, 'confidence' => 'haute', 'why' => '', 'by_writer' => true );
		}
		$matching['pairs'] = $clean;
		$wpdb->update( self::table(), array( 'matching_json' => wp_json_encode( $matching ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $id ) ) );
		return true;
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
			if ( MSRWA_Run::create( (int) $id, (int) $batch['owner_id'], MSRWA_Match::brief( $recipe, $images ), $config, MSRWA_Profile::steps( $batch['profile'], (array) ( $config['steps'] ?? array() ) ) ) ) { $started++; }
		}

		$wpdb->update( self::table(), array( 'status' => $started ? 'running' : 'failed', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $id ) ) );
		return $started;
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
		MSRWA_Intake::forget( array_column( (array) ( self::matching( $id )['images'] ?? array() ), 'id' ) );
		foreach ( MSRWA_Run::for_batch( $id ) as $run ) {
			foreach ( array( 'steps', 'calls', 'events', 'artifacts' ) as $table ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t[ $table ] . ' WHERE run_id = %d', (int) $run['id'] ) );
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t['runs'] . ' WHERE batch_id = %d', absint( $id ) ) );
		return (bool) $wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );
	}
}
