<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every model this site may use, and everything variable about it.
 *
 * The engine holds the editorial process and nothing else. Which models exist,
 * what they cost and which step each may serve are not process — they change
 * when a provider renames a model or moves a price, and neither should require
 * touching the chain that writes an article. So they live here, in a table the
 * owner edits, and are handed to the engine as its caller layer.
 *
 * Three kinds of knowledge, kept apart because they are learned differently:
 *
 * - **Which identifiers exist.** Only the provider knows. Fetched.
 * - **What they cost.** No provider API says. Typed by a person, or looked up
 *   by a model and marked as such — `price_method` records which, because a
 *   rate nobody can trace is a rate nobody should bill on.
 * - **Which step a model may serve.** The owner's editorial judgement. Only
 *   ever set by hand.
 */
final class MSRWA_Catalog {

	/** A price nobody typed and nobody fetched: what shipped in the box. */
	const SHIPPED = 'shipped';
	/** A price a person entered on the Modèles screen. */
	const MANUAL = 'manual';
	/** A price a model looked up, with the page it read. Never silently trusted. */
	const LOOKED_UP = 'ai';

	public static function defaults() {
		return array(
			'openai' => array(
				'gpt-5.6-luna' => array( 'label' => 'GPT-5.6 Luna', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 0.20, 'output' => 1.20, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-luna' ),
				'gpt-5.6-terra' => array( 'label' => 'GPT-5.6 Terra', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 2.00, 'output' => 12.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-terra' ),
				'gpt-5.6-sol' => array( 'label' => 'GPT-5.6 Sol', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 4.00, 'output' => 20.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-sol' ),
				'gpt-image-2.5-flare' => array( 'label' => 'GPT Image 2.5 Flare', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'image_tokens' => array( '1024x1024' => array( 'low' => 272, 'medium' => 1056, 'high' => 4160 ), '1024x1536' => array( 'low' => 408, 'medium' => 1584, 'high' => 6240 ), '1536x1024' => array( 'low' => 400, 'medium' => 1568, 'high' => 6208 ) ), 'input' => 5.00, 'image_input' => 8.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-flare' ),
				'gpt-image-2.5-sunburst' => array( 'label' => 'GPT Image 2.5 Sunburst', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'image_tokens' => array( '1024x1024' => array( 'low' => 272, 'medium' => 1056, 'high' => 4160 ), '1024x1536' => array( 'low' => 408, 'medium' => 1584, 'high' => 6240 ), '1536x1024' => array( 'low' => 400, 'medium' => 1568, 'high' => 6208 ) ), 'input' => 5.00, 'image_input' => 8.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-sunburst' ),
			),
			'gemini' => array(
				'gemini-3.1-flash-image' => array( 'label' => 'Gemini 3.1 Flash Image', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => true, 'image_tokens' => array( '1024x1024' => array( 'low' => 272, 'medium' => 1056, 'high' => 4160 ), '1024x1536' => array( 'low' => 408, 'medium' => 1584, 'high' => 6240 ), '1536x1024' => array( 'low' => 400, 'medium' => 1568, 'high' => 6208 ) ), 'input' => 0.50, 'output' => 3.00, 'source' => 'https://ai.google.dev/gemini-api/docs/image-generation' ),
				'gemini-3.5-flash' => array( 'label' => 'Gemini 3.5 Flash', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 1.50, 'output' => 9.00, 'source' => 'https://ai.google.dev/gemini-api/docs/pricing' ),
			),
			'claude' => array(
				'claude-sonnet-5' => array( 'label' => 'Claude Sonnet 5', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 2.00, 'output' => 10.00, 'source' => 'https://platform.claude.com/docs/en/models/overview' ),
				'claude-haiku-4-5-20251001' => array( 'label' => 'Claude Haiku 4.5', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 1.00, 'output' => 5.00, 'source' => 'https://platform.claude.com/docs/en/models/overview' ),
			),
		);
	}

	// --- Reading ---------------------------------------------------------

	/** Every row, newest provider order, as the screens and the engine read it. */
	public static function rows( $only_enabled = false ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( empty( $t['catalog'] ) || ! MSRWA_DB::table_exists( $t['catalog'] ) ) { return array(); }
		$sql = 'SELECT * FROM ' . $t['catalog'] . ( $only_enabled ? ' WHERE enabled = 1' : '' ) . ' ORDER BY provider ASC, model_id ASC';
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/** One row, or null. */
	public static function row( $provider, $model_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( empty( $t['catalog'] ) || ! MSRWA_DB::table_exists( $t['catalog'] ) ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . $t['catalog'] . ' WHERE provider = %s AND model_id = %s',
			sanitize_key( (string) $provider ),
			(string) $model_id
		), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/** The JSON columns decoded, so no caller has to remember which are which. */
	private static function hydrate( array $row ) {
		foreach ( array( 'capabilities_json' => 'capabilities', 'limits_json' => 'limits', 'steps_json' => 'steps' ) as $column => $key ) {
			$value = json_decode( (string) $row[ $column ], true );
			$row[ $key ] = is_array( $value ) ? $value : array();
			unset( $row[ $column ] );
		}
		$row['enabled'] = ! empty( $row['enabled'] );
		$row['input_usd'] = null === $row['input_usd'] ? null : (float) $row['input_usd'];
		$row['output_usd'] = null === $row['output_usd'] ? null : (float) $row['output_usd'];
		// Three-valued on purpose: a provider never asked says nothing about
		// its models, and reporting silence as "missing" sends somebody
		// chasing a model that is perfectly fine.
		$row['served'] = null === $row['served'] ? null : ! empty( $row['served'] );
		return $row;
	}

	/** Whether a model is known to be served: yes, no, or unknown. */
	public static function served( $provider, $model_id ) {
		$row = self::row( $provider, $model_id );
		if ( ! $row || null === $row['served'] ) { return 'unknown'; }
		return $row['served'] ? 'yes' : 'no';
	}

	/** Model identifiers this site last saw a provider offer. */
	public static function available( $provider ) {
		$out = array();
		foreach ( self::rows() as $row ) {
			if ( $row['provider'] === sanitize_key( (string) $provider ) && $row['served'] ) { $out[] = $row['model_id']; }
		}
		return $out;
	}

	/** When that provider was last asked, or '' if it never was. */
	public static function listed_at( $provider ) {
		$latest = '';
		foreach ( self::rows() as $row ) {
			if ( $row['provider'] !== sanitize_key( (string) $provider ) ) { continue; }
			if ( $row['listed_at'] && $row['listed_at'] > $latest ) { $latest = (string) $row['listed_at']; }
		}
		return $latest;
	}

	/** What the plugin prices against, in the shape the old callers expect. */
	public static function models( $include_disabled = false ) {
		$out = array();
		foreach ( self::rows( ! $include_disabled ) as $row ) {
			$out[ $row['provider'] ][ $row['model_id'] ] = array_merge( $row['capabilities'], array(
				'label' => $row['label'],
				'input' => $row['input_usd'],
				'output' => $row['output_usd'],
				'source' => $row['price_source'],
			) );
		}
		return $out ? $out : self::defaults();
	}

	// --- Writing ---------------------------------------------------------

	/**
	 * Fills the table once, from what shipped, without ever overwriting.
	 *
	 * Runs on activation and on every version change, so it must be safe to
	 * run on a site whose owner has already corrected a rate by hand. It only
	 * ever inserts rows that are not there.
	 */
	public static function seed() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( empty( $t['catalog'] ) || ! MSRWA_DB::table_exists( $t['catalog'] ) ) { return 0; }

		$documented = self::defaults();
		// The engine's own list is the one it bills against and the longer of
		// the two, so it decides which models exist; the documented list adds
		// the label, the capabilities and the page each rate came from.
		$priced = class_exists( 'MSRWA_Engine_Config' ) ? (array) MSRWA_Engine_Config::create()->get( 'models', array() ) : array();

		$now = current_time( 'mysql', true );
		$added = 0;
		foreach ( $priced as $provider => $models ) {
			foreach ( (array) $models as $model_id => $rate ) {
				$known = $documented[ $provider ][ $model_id ] ?? array();
				$capabilities = array();
				foreach ( array( 'text', 'vision', 'web_search', 'image_generation' ) as $capability ) {
					if ( isset( $known[ $capability ] ) ) { $capabilities[ $capability ] = (bool) $known[ $capability ]; }
				}
				$added += (int) self::insert_missing( $provider, (string) $model_id, array(
					'label' => (string) ( $known['label'] ?? $model_id ),
					'input_usd' => is_array( $rate ) && isset( $rate[0] ) ? (float) $rate[0] : null,
					'output_usd' => is_array( $rate ) && isset( $rate[1] ) ? (float) $rate[1] : null,
					'price_method' => self::SHIPPED,
					'price_source' => (string) ( $known['source'] ?? '' ),
					'price_checked_at' => $now,
					'capabilities_json' => wp_json_encode( $capabilities ),
				), $now );
			}
		}
		// Models the plugin documents that the engine never priced: they carry
		// a rate and a source of their own, so they are worth keeping.
		foreach ( $documented as $provider => $models ) {
			foreach ( $models as $model_id => $model ) {
				$capabilities = array();
				foreach ( array( 'text', 'vision', 'web_search', 'image_generation' ) as $capability ) { $capabilities[ $capability ] = ! empty( $model[ $capability ] ); }
				$added += (int) self::insert_missing( $provider, (string) $model_id, array(
					'label' => (string) $model['label'],
					'input_usd' => (float) ( $model['input'] ?? 0 ),
					'output_usd' => (float) ( $model['output'] ?? 0 ),
					'price_method' => self::SHIPPED,
					'price_source' => (string) ( $model['source'] ?? '' ),
					'price_checked_at' => $now,
					'capabilities_json' => wp_json_encode( $capabilities ),
				), $now );
			}
		}
		return $added;
	}

	private static function insert_missing( $provider, $model_id, array $values, $now ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$provider = sanitize_key( (string) $provider );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $t['catalog'] . ' WHERE provider = %s AND model_id = %s', $provider, $model_id ) );
		if ( $exists ) { return false; }
		return false !== $wpdb->insert( $t['catalog'], array_merge( $values, array(
			'provider' => $provider, 'model_id' => $model_id, 'enabled' => 1,
			'created_at' => $now, 'updated_at' => $now,
		) ) );
	}

	/**
	 * What one provider answered when asked for its models.
	 *
	 * Identifiers it no longer names are marked unserved rather than deleted:
	 * a retired model still has a rate, still appears in old runs, and the
	 * owner may want to see why a route stopped working rather than find the
	 * row gone.
	 */
	public static function remember_listing( $provider, array $models ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( ! $models || empty( $t['catalog'] ) || ! MSRWA_DB::table_exists( $t['catalog'] ) ) { return 0; }
		$provider = sanitize_key( (string) $provider );
		$now = current_time( 'mysql', true );
		$seen = array();

		foreach ( $models as $model ) {
			$id = trim( (string) ( $model['id'] ?? '' ) );
			if ( '' === $id ) { continue; }
			$seen[] = $id;
			$known = self::row( $provider, $id );

			$update = array( 'served' => 1, 'listed_at' => $now, 'updated_at' => $now );
			if ( ! empty( $model['label'] ) ) { $update['label'] = (string) $model['label']; }
			// Merged, never replaced. A provider states only some of what a
			// model can do, and a fetch that answered nothing about vision
			// must not turn a correct vision flag off and take the model out
			// of service.
			if ( isset( $model['capabilities'] ) ) {
				$update['capabilities_json'] = wp_json_encode( array_merge( $known ? $known['capabilities'] : array(), (array) $model['capabilities'] ) );
			}
			if ( isset( $model['limits'] ) ) {
				$update['limits_json'] = wp_json_encode( array_merge( $known ? $known['limits'] : array(), (array) $model['limits'] ) );
			}

			if ( $known ) {
				$wpdb->update( $t['catalog'], $update, array( 'provider' => $provider, 'model_id' => $id ) );
				continue;
			}
			// New to this site, and unpriced: a model the provider serves but
			// nobody has costed yet is exactly what the price lookup is for.
			$wpdb->insert( $t['catalog'], array_merge( $update, array(
				'provider' => $provider, 'model_id' => $id, 'enabled' => 0,
				'created_at' => $now,
			) ) );
		}

		// Every identifier was blank: that is a shape this code does not
		// understand, not a provider that serves nothing, so nothing is marked.
		if ( ! $seen ) { return 0; }

		$placeholders = implode( ',', array_fill( 0, count( $seen ), '%s' ) );
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . $t['catalog'] . ' SET served = 0, listed_at = %s, updated_at = %s WHERE provider = %s AND model_id NOT IN (' . $placeholders . ')',
			array_merge( array( $now, $now, $provider ), $seen )
		) );
		return count( $seen );
	}

	/** A rate, with where it came from. Never loses the provenance. */
	public static function remember_price( $provider, $model_id, $input, $output, $method, $source = '' ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( empty( $t['catalog'] ) || ! MSRWA_DB::table_exists( $t['catalog'] ) ) { return false; }
		$method = in_array( $method, array( self::SHIPPED, self::MANUAL, self::LOOKED_UP ), true ) ? $method : self::MANUAL;
		$now = current_time( 'mysql', true );
		return false !== $wpdb->update( $t['catalog'], array(
			'input_usd' => null === $input ? null : max( 0, (float) $input ),
			'output_usd' => null === $output ? null : max( 0, (float) $output ),
			'price_method' => $method,
			'price_source' => esc_url_raw( (string) $source ),
			'price_checked_at' => $now,
			'updated_at' => $now,
		), array( 'provider' => sanitize_key( (string) $provider ), 'model_id' => (string) $model_id ) );
	}

	// --- What the engine is handed --------------------------------------

	/**
	 * The `models` and `tiers` groups, generated from this table.
	 *
	 * This is what keeps the engine holding nothing but the editorial process.
	 * Both groups are part of the caller layer the engine already accepts, so
	 * no engine change is needed: a renamed model or a moved rate is an edit
	 * in this table, and the chain that writes an article never moves.
	 *
	 * Only enabled, priced models are handed over. A model with no rate makes
	 * the run unverifiable against its ceiling, and the plugin's promise is an
	 * estimate before it spends.
	 */
	public static function for_engine( array $rows = null ) {
		$models = array();
		$priced = array();
		foreach ( null === $rows ? self::rows( true ) : $rows as $row ) {
			if ( null === $row['input_usd'] || null === $row['output_usd'] ) { continue; }
			$models[ $row['provider'] ][ $row['model_id'] ] = array( (float) $row['input_usd'], (float) $row['output_usd'] );
			$priced[ $row['provider'] ][ $row['model_id'] ] = $row;
		}
		return array( 'models' => $models, 'tiers' => self::tiers( $priced ) );
	}

	/**
	 * What `low`, `medium` and `high` resolve to, per provider.
	 *
	 * Cheapest, middle and dearest of what this site actually has, by input
	 * rate — so a tier can never name a model the provider does not serve or
	 * nobody has priced, which is exactly how `claude:low` came to point at an
	 * identifier Anthropic retired.
	 *
	 * A provider the catalogue cannot fill is left out entirely rather than
	 * given a wrong answer; the engine's own default then stands, and the
	 * Diagnostic screen says so.
	 */
	private static function tiers( array $priced ) {
		$tiers = array();
		foreach ( $priced as $provider => $rows ) {
			$usable = array_filter( $rows, static function ( $row ) {
				// A model the provider is known not to serve is never a tier;
				// one nothing has asked about still is, because silence is not
				// a denial.
				if ( false === $row['served'] ) { return false; }
				// These are the text routes — every step but image generation,
				// which names its model outright. Sorting the whole catalogue
				// by price without this put an image model in front of the
				// article step, which is the cheapest way to ruin a lot.
				// Known-to-write only: routing prose at a model nobody has
				// established can write it is a gamble with the owner's money.
				return true === ( $row['capabilities']['text'] ?? null );
			} );
			if ( ! $usable ) { continue; }
			uasort( $usable, static function ( $a, $b ) { return (float) $a['input_usd'] <=> (float) $b['input_usd']; } );
			$ids = array_keys( $usable );
			$tiers['low'][ $provider ] = $ids[0];
			$tiers['high'][ $provider ] = $ids[ count( $ids ) - 1 ];
			$tiers['medium'][ $provider ] = $ids[ intdiv( count( $ids ) - 1, 2 ) ];
		}
		// The engine reads tiers as tier => provider => model, and a half-built
		// tier is worse than none: it would route one provider and not another.
		foreach ( array( 'low', 'medium', 'high' ) as $tier ) {
			if ( empty( $tiers[ $tier ] ) ) { unset( $tiers[ $tier ] ); }
		}
		return $tiers;
	}

	/**
	 * Whether a model may serve a step, as the owner decided.
	 *
	 * An empty grid means nothing has been decided yet, not that everything is
	 * forbidden — a fresh install must still be able to run.
	 */
	public static function may_serve( $provider, $model_id, $step ) {
		$row = self::row( $provider, $model_id );
		return $row ? self::allows( $row, $step ) : true;
	}

	/** The same decision on a row already in hand, and the pure half of it. */
	public static function allows( array $row, $step ) {
		$steps = (array) ( $row['steps'] ?? array() );
		if ( ! $steps ) { return true; }
		return in_array( sanitize_key( (string) $step ), $steps, true );
	}

	/** Which steps a model may serve, and whether it is offered at all. */
	public static function remember_compatibility( $provider, $model_id, array $steps, $enabled ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( empty( $t['catalog'] ) || ! MSRWA_DB::table_exists( $t['catalog'] ) ) { return false; }
		$steps = array_values( array_unique( array_map( 'sanitize_key', $steps ) ) );
		$now = current_time( 'mysql', true );
		return false !== $wpdb->update( $t['catalog'], array(
			'steps_json' => wp_json_encode( $steps ),
			'enabled' => $enabled ? 1 : 0,
			'updated_at' => $now,
		), array( 'provider' => sanitize_key( (string) $provider ), 'model_id' => (string) $model_id ) );
	}
}
