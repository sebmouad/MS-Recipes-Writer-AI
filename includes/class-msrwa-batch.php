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

	public static function may_submit() { return current_user_can( 'msrwa_create' ) || current_user_can( 'manage_options' ); }

	/**
	 * Records a submission and has its photographs matched.
	 *
	 * Matching costs money — one vision call per photograph — so it happens
	 * once, here, and its result is stored. Correcting a pairing afterwards is
	 * free because nothing is described twice.
	 */
	public static function create( array $recipes, array $images, $budget_per_recipe, $profile = MSRWA_Profile::FULL, $language = 'fr', array $config_overrides = array() ) {
		global $wpdb;
		if ( ! $recipes ) { return new WP_Error( 'msrwa_no_recipes', 'Aucune recette dans ce qui a été fourni.' ); }
		$budget = round( (float) $budget_per_recipe, 4 );
		if ( $budget <= 0 ) { return new WP_Error( 'msrwa_no_budget', 'Fixez un plafond de dépense par recette.' ); }

		$now = current_time( 'mysql', true );
		$wpdb->insert( self::table(), array(
			'owner_id' => get_current_user_id(),
			'label' => mb_substr( (string) $recipes[0]['title'], 0, 190 ) . ( count( $recipes ) > 1 ? sprintf( ' et %d autres', count( $recipes ) - 1 ) : '' ),
			'status' => 'matching', 'recipes' => count( $recipes ), 'images' => count( $images ),
			'budget_usd' => $budget,
			'profile' => MSRWA_Profile::exists( $profile ) ? $profile : MSRWA_Profile::FULL,
			'language' => MSRWA_Profile::language_exists( $language ) ? $language : 'fr',
			'config_json' => wp_json_encode( $config_overrides ),
			'matching_json' => wp_json_encode( array( 'recipes' => $recipes, 'images' => $images, 'pairs' => array() ) ),
			'created_at' => $now, 'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) { return new WP_Error( 'msrwa_not_created', 'Le lot n’a pas pu être enregistré.' ); }

		$match = MSRWA_Match::run( $recipes, $images, self::engine_config( self::config_overrides( $id ) ) );
		$wpdb->update( self::table(), array(
			'status' => 'ready',
			'matching_json' => wp_json_encode( MSRWA_DB::sanitize( array(
				'recipes' => $recipes, 'images' => $match['images'], 'pairs' => $match['pairs'],
				'reasoning' => $match['reasoning'], 'cost_usd' => $match['cost_usd'], 'seconds' => $match['seconds'],
			) ) ),
			'error_message' => $match['errors'] ? implode( ' | ', array_slice( $match['errors'], 0, 5 ) ) : '',
			'updated_at' => current_time( 'mysql', true ),
		), array( 'id' => $id ) );
		return $id;
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ), ARRAY_A );
		return $row ? $row : null;
	}

	public static function recent( $limit = 30 ) {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		if ( current_user_can( 'manage_options' ) ) {
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE owner_id = %d ORDER BY id DESC LIMIT %d', get_current_user_id(), $limit ), ARRAY_A );
	}

	public static function may_see( array $batch ) {
		return (int) $batch['owner_id'] === get_current_user_id() || current_user_can( 'manage_options' );
	}

	public static function matching( $id ) {
		$batch = self::get( $id );
		$matching = $batch ? json_decode( (string) $batch['matching_json'], true ) : null;
		return is_array( $matching ) ? $matching : array( 'recipes' => array(), 'images' => array(), 'pairs' => array() );
	}

	/** Replaces the pairing with the writer's own, which is always the last word. */
	public static function repair( $id, array $pairs ) {
		global $wpdb;
		$matching = self::matching( $id );
		$clean = array();
		$taken = array();
		foreach ( $pairs as $pair ) {
			$image = isset( $pair['image'] ) ? (int) $pair['image'] : -1;
			if ( $image < 0 || $image >= count( (array) $matching['images'] ) || isset( $taken[ $image ] ) ) { continue; }
			$recipe = isset( $pair['recipe'] ) && '' !== $pair['recipe'] && null !== $pair['recipe'] ? (int) $pair['recipe'] : null;
			if ( null !== $recipe && ( $recipe < 0 || $recipe >= count( (array) $matching['recipes'] ) ) ) { $recipe = null; }
			$taken[ $image ] = true;
			$clean[] = array( 'image' => $image, 'recipe' => $recipe, 'confidence' => 'haute', 'why' => 'Confirmé par le rédacteur.' );
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
		if ( ! $batch ) { return new WP_Error( 'msrwa_no_batch', 'Lot introuvable.' ); }
		if ( 'ready' !== $batch['status'] ) { return new WP_Error( 'msrwa_not_ready', 'Ce lot a déjà été lancé.' ); }

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
		$config = self::merge( MSRWA_Engine_Settings::stored(), $overrides );
		if ( $batch ) {
			// What this batch asked to produce, and in which language, expressed
			// as the engine's own configuration rather than as a special case.
			$config = self::merge( $config, MSRWA_Profile::config( $batch['profile'], $batch['language'], (array) ( $config['steps'] ?? array() ) ) );
			$config['limits']['budget_usd'] = (float) $batch['budget_usd'];
		}
		$keys = MSRWA_Settings::engine_keys();
		if ( $keys ) { $config['settings'] = array_merge( (array) ( $config['settings'] ?? array() ), array( 'keys' => $keys ) ); }
		return $config;
	}

	public static function config_for( $id ) { return self::config_overrides( $id ); }

	private static function engine_config( array $overrides ) { return MSRWA_Engine_Config::create( $overrides ); }

	private static function merge( array $base, array $over ) {
		foreach ( $over as $key => $value ) {
			$base[ $key ] = is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ? self::merge( $base[ $key ], $value ) : $value;
		}
		return $base;
	}

	/** Deletes a batch, its runs and everything the engine reported about them. */
	public static function delete( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		foreach ( MSRWA_Run::for_batch( $id ) as $run ) {
			foreach ( array( 'steps', 'calls', 'events', 'artifacts' ) as $table ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t[ $table ] . ' WHERE run_id = %d', (int) $run['id'] ) );
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t['runs'] . ' WHERE batch_id = %d', absint( $id ) ) );
		return (bool) $wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );
	}
}
