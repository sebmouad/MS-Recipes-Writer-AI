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
	/** A price this plugin read off the provider's own page, with no model in between. */
	const READ = 'page';

	public static function defaults() {
		return array(
			'openai' => array(
				'gpt-5.6-luna' => array( 'label' => 'GPT-5.6 Luna', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 0.20, 'output' => 1.20, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-luna' ),
				'gpt-5.6-terra' => array( 'label' => 'GPT-5.6 Terra', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 2.00, 'output' => 12.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-terra' ),
				'gpt-5.6-sol' => array( 'label' => 'GPT-5.6 Sol', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 4.00, 'output' => 20.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-sol' ),
				'gpt-6-luna' => array( 'label' => 'GPT-6 Luna', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 0.10, 'output' => 0.50, 'source' => 'https://developers.openai.com/api/docs/pricing', 'enabled' => false ),
				'gpt-6-sol' => array( 'label' => 'GPT-6 Sol', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 2.00, 'output' => 10.00, 'source' => 'https://developers.openai.com/api/docs/pricing', 'enabled' => false ),
				'gpt-6-astra' => array( 'label' => 'GPT-6 Astra', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 10.00, 'output' => 50.00, 'source' => 'https://developers.openai.com/api/docs/pricing', 'enabled' => false ),
				'gpt-image-2.5-flare' => array( 'label' => 'GPT Image 2.5 Flare', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'image_tokens' => array( '1024x1024' => array( 'low' => 272, 'medium' => 1056, 'high' => 4160 ), '1024x1536' => array( 'low' => 408, 'medium' => 1584, 'high' => 6240 ), '1536x1024' => array( 'low' => 400, 'medium' => 1568, 'high' => 6208 ) ), 'input' => 5.00, 'image_input' => 8.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-flare' ),
				'gpt-image-2.5-sunburst' => array( 'label' => 'GPT Image 2.5 Sunburst', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'image_tokens' => array( '1024x1024' => array( 'low' => 272, 'medium' => 1056, 'high' => 4160 ), '1024x1536' => array( 'low' => 408, 'medium' => 1584, 'high' => 6240 ), '1536x1024' => array( 'low' => 400, 'medium' => 1568, 'high' => 6208 ) ), 'input' => 5.00, 'image_input' => 8.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-sunburst' ),
			),
			// Read from each provider's own pricing page on 2026-09-23. A model marked
			// `enabled => false` is priced so a fetch finds it costed, but is not
			// offered until the owner ticks it: the tiers are chosen by price among
			// enabled models, and shipping a dearer one enabled would move them.
			'gemini' => array(
				'gemini-3.5-flash' => array( 'label' => 'Gemini 3.5 Flash', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 1.50, 'output' => 9.00, 'source' => 'https://ai.google.dev/gemini-api/docs/pricing' ),
				'gemini-3.1-flash-lite' => array( 'label' => 'Gemini 3.1 Flash-Lite', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 0.25, 'output' => 1.50, 'source' => 'https://ai.google.dev/gemini-api/docs/pricing' ),
				'gemini-3.7-flash' => array( 'label' => 'Gemini 3.7 Flash', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 0.75, 'output' => 3.75, 'source' => 'https://ai.google.dev/gemini-api/docs/pricing', 'enabled' => false ),
				'gemini-3.8-flash' => array( 'label' => 'Gemini 3.8 Flash', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 0.75, 'output' => 3.75, 'source' => 'https://ai.google.dev/gemini-api/docs/pricing', 'enabled' => false ),
			),
			'claude' => array(
				'claude-sonnet-5' => array( 'label' => 'Claude Sonnet 5', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 2.00, 'output' => 10.00, 'source' => 'https://platform.claude.com/docs/en/about-claude/pricing' ),
				'claude-haiku-4-5-20251001' => array( 'label' => 'Claude Haiku 4.5', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 1.00, 'output' => 5.00, 'source' => 'https://platform.claude.com/docs/en/about-claude/pricing' ),
				'claude-opus-5' => array( 'label' => 'Claude Opus 5', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 5.00, 'output' => 25.00, 'source' => 'https://platform.claude.com/docs/en/about-claude/pricing' ),
				'claude-opus-5-5' => array( 'label' => 'Claude Opus 5.5', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 4.00, 'output' => 20.00, 'source' => 'https://platform.claude.com/docs/en/about-claude/pricing', 'enabled' => false ),
				'claude-fable-5-1' => array( 'label' => 'Claude Fable 5.1', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 10.00, 'output' => 50.00, 'source' => 'https://platform.claude.com/docs/en/about-claude/pricing', 'enabled' => false ),
			),
		);
	}

	/**
	 * What this plugin can use a model identifier for: `text`, `image`, or ''.
	 *
	 * A provider lists everything it serves — Gemini answered 59 names, among
	 * them speech, music, video, embeddings, robotics and live audio, every one
	 * marked as able to generate content. Kept, they buried the dozen models a
	 * recipe can use, and the image and speech ones were flagged as writers, so
	 * sorting the catalogue by price could hand an article to a model that
	 * draws. Only the chat families the engine calls are kept: dated snapshots,
	 * `-latest` aliases and generations the provider has stopped offering to new
	 * accounts are left out. Gemini's image models are left out too: the engine
	 * draws through OpenAI's images endpoint and has none for Gemini.
	 */
	public static function role( $provider, $model_id ) {
		$id = strtolower( trim( (string) $model_id ) );
		if ( preg_match( '/\d{4}-\d{2}-\d{2}$|(^|-)(latest|audio|realtime|search|transcribe|tts|live|embedding|codex|chat|oss|instruct|customtools|computer|robotics|research)(-|$)/', $id ) ) { return ''; }
		switch ( sanitize_key( (string) $provider ) ) {
			case 'openai':
				if ( preg_match( '/^gpt-image-\d+(\.\d+)?(-[a-z]+)?$/', $id ) ) { return 'image'; }
				// GPT-5 onwards; `-pro` bills several times the flagship for
				// reasoning a recipe does not need.
				return preg_match( '/^gpt-(\d+)(\.\d+)?(-[a-z]+)?$/', $id, $m ) && (int) $m[1] >= 5 && '-pro' !== ( $m[3] ?? '' ) ? 'text' : '';
			case 'gemini':
				// Google refuses 2.5 to new accounts and names 3.x as the way on.
				return preg_match( '/^gemini-(\d+)(\.\d+)?-(pro|flash|flash-lite)(-preview)?$/', $id, $m ) && (int) $m[1] >= 3 ? 'text' : '';
			case 'claude':
				return preg_match( '/^claude-(opus|sonnet|haiku|fable)-(\d+)(-\d+)?(-\d{8})?$/', $id, $m ) && (int) $m[2] >= 4 ? 'text' : '';
		}
		return '';
	}

	/**
	 * The identifiers worth a row, out of one provider's answer.
	 *
	 * The two newest generations of each role, and nothing older unless this
	 * site has a reason to keep it: a rate that ships, a model the engine's own
	 * list names, or a route somebody typed. OpenAI listed 132 identifiers on
	 * 2026-09-23 and even the chat families alone came to 21 — every point
	 * release from GPT-5 to GPT-6. A preview is dropped when the same model is
	 * served without the suffix: it is the same model twice, and the preview is
	 * the one that gets retired.
	 */
	public static function keep( $provider, array $ids ) {
		$ids = array_values( array_unique( array_map( 'strval', $ids ) ) );
		$roles = array();
		foreach ( $ids as $id ) {
			$role = self::role( $provider, $id );
			if ( '' === $role ) { continue; }
			if ( preg_match( '/^(.+)-preview$/', $id, $m ) && in_array( $m[1], $ids, true ) ) { continue; }
			$roles[ $role ][ $id ] = self::generation( $provider, $id );
		}
		$pinned = self::pinned( $provider );
		$out = array();
		foreach ( $roles as $generations ) {
			$newest = array_values( array_unique( $generations ) );
			rsort( $newest );
			$newest = array_slice( $newest, 0, 2 );
			foreach ( $generations as $id => $generation ) {
				if ( in_array( $generation, $newest, true ) || in_array( (string) $id, $pinned, true ) ) { $out[] = (string) $id; }
			}
		}
		return array_values( array_intersect( $ids, $out ) );
	}

	/** A model's generation as a comparable number: gpt-5.6 → 5.6, claude-opus-4-5 → 4.5. */
	public static function generation( $provider, $model_id ) {
		$id = strtolower( (string) $model_id );
		if ( preg_match( '/^claude-[a-z]+-(\d+)(?:-(\d{1,2}))?(?:-\d{8})?$/', $id, $m ) ) { return (float) ( $m[1] . '.' . ( $m[2] ?? '0' ) ); }
		if ( preg_match( '/^(?:gpt-image|gpt|gemini)-(\d+(?:\.\d+)?)/', $id, $m ) ) { return (float) $m[1]; }
		return 0.0;
	}

	/** Models kept whatever their generation: shipped, used by the engine, or routed to by name. */
	private static function pinned( $provider ) {
		$provider = sanitize_key( (string) $provider );
		$pinned = array_keys( self::defaults()[ $provider ] ?? array() );
		if ( class_exists( 'MSRWA_Engine_Config' ) ) {
			$engine = MSRWA_Engine_Config::defaults();
			$pinned = array_merge( $pinned, array_keys( (array) ( $engine['models'][ $provider ] ?? array() ) ) );
			foreach ( (array) ( $engine['tiers'] ?? array() ) as $models ) { $pinned[] = (string) ( $models[ $provider ] ?? '' ); }
		}
		if ( class_exists( 'MSRWA_Engine_Settings' ) && function_exists( 'get_option' ) ) {
			foreach ( (array) ( MSRWA_Engine_Settings::typed()['routing'] ?? array() ) as $route ) {
				$parts = explode( ':', (string) $route, 2 );
				if ( $provider === $parts[0] && isset( $parts[1] ) ) { $pinned[] = $parts[1]; }
			}
		}
		return array_values( array_filter( array_unique( $pinned ) ) );
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
				if ( '' === self::role( $provider, $model_id ) ) { continue; }
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
					'enabled' => isset( $model['enabled'] ) && ! $model['enabled'] ? 0 : 1,
				), $now );
			}
		}
		self::reprice();
		self::classify();
		foreach ( array_keys( $documented ) as $provider ) { self::prune( $provider ); }
		return $added;
	}

	/**
	 * Says what a row is for when nothing ever did. Rows seeded from the
	 * engine's price list carried no capabilities, so gpt-5-nano — the
	 * engine's own `openai:low` — could never be a tier. Only a row that states
	 * nothing about writing is touched; a flag set by hand or by a provider stands.
	 */
	public static function classify() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$changed = 0;
		foreach ( self::rows() as $row ) {
			if ( array_key_exists( 'text', $row['capabilities'] ) ) { continue; }
			$role = self::role( $row['provider'], $row['model_id'] );
			if ( '' === $role ) { continue; }
			$capabilities = array_merge( $row['capabilities'], array( 'text' => 'text' === $role, 'image_generation' => 'image' === $role ) );
			$changed += (int) $wpdb->update( $t['catalog'], array( 'capabilities_json' => wp_json_encode( $capabilities ) ), array( 'provider' => $row['provider'], 'model_id' => $row['model_id'] ) );
		}
		return $changed;
	}

	/**
	 * Brings a rate that shipped in an earlier version up to the one shipping
	 * now. Seeding never overwrote, which was right for a rate a person typed
	 * and wrong for one that only ever came in the box: a provider that moved
	 * a price left every site billing on the old one for ever. Only `shipped`
	 * rows move; a typed or looked-up rate is somebody's decision. A row a
	 * fetch added with no rate at all is filled too, once one ships.
	 */
	public static function reprice() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$now = current_time( 'mysql', true );
		$moved = 0;
		foreach ( self::defaults() as $provider => $models ) {
			foreach ( $models as $model_id => $model ) {
				$moved += (int) $wpdb->query( $wpdb->prepare(
					'UPDATE ' . $t['catalog'] . ' SET input_usd = %f, output_usd = %f, price_method = \'' . self::SHIPPED . '\', price_source = %s, price_checked_at = %s, updated_at = %s WHERE provider = %s AND model_id = %s AND ( ( price_method = %s AND ( input_usd IS NULL OR output_usd IS NULL OR input_usd <> %f OR output_usd <> %f ) ) OR ( price_method = %s AND input_usd IS NULL AND output_usd IS NULL ) )',
					(float) $model['input'], (float) $model['output'], (string) ( $model['source'] ?? '' ), $now, $now,
					$provider, (string) $model_id, self::SHIPPED, (float) $model['input'], (float) $model['output'], ''
				) );
			}
		}
		return $moved;
	}

	/**
	 * Removes the rows `keep()` would not have created, from before it existed.
	 *
	 * Never a rate a person typed, and never a model the owner has assigned to
	 * a step: both are decisions, and a decision is not noise.
	 */
	public static function prune( $provider ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( empty( $t['catalog'] ) || ! MSRWA_DB::table_exists( $t['catalog'] ) ) { return 0; }
		$provider = sanitize_key( (string) $provider );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT model_id, price_method, steps_json FROM ' . $t['catalog'] . ' WHERE provider = %s', $provider ), ARRAY_A );
		$kept = self::keep( $provider, array_column( $rows, 'model_id' ) );
		$removed = 0;
		foreach ( $rows as $row ) {
			if ( in_array( $row['model_id'], $kept, true ) || self::MANUAL === $row['price_method'] ) { continue; }
			if ( json_decode( (string) $row['steps_json'], true ) ) { continue; }
			$removed += (int) $wpdb->delete( $t['catalog'], array( 'provider' => $provider, 'model_id' => $row['model_id'] ) );
		}
		return $removed;
	}

	private static function insert_missing( $provider, $model_id, array $values, $now ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$provider = sanitize_key( (string) $provider );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $t['catalog'] . ' WHERE provider = %s AND model_id = %s', $provider, $model_id ) );
		if ( $exists ) { return false; }
		return false !== $wpdb->insert( $t['catalog'], array_merge( array( 'enabled' => 1 ), $values, array(
			'provider' => $provider, 'model_id' => $model_id,
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
		$kept = self::keep( $provider, array_map( static function ( $model ) { return trim( (string) ( $model['id'] ?? '' ) ); }, $models ) );
		$documented = self::defaults()[ $provider ] ?? array();

		foreach ( $models as $model ) {
			$id = trim( (string) ( $model['id'] ?? '' ) );
			if ( '' === $id ) { continue; }
			// Every name counts as served, so nothing the provider still offers
			// is marked retired; only the ones this plugin can use get a row.
			$seen[] = $id;
			if ( ! in_array( $id, $kept, true ) ) { continue; }
			$known = self::row( $provider, $id );
			// What the model is for is decided here, not by the listing: Gemini
			// marks every model it serves, speech and image included, as able
			// to generate content.
			$model['capabilities'] = array_merge( (array) ( $model['capabilities'] ?? array() ), 'image' === self::role( $provider, $id )
				? array( 'text' => false, 'image_generation' => true )
				: array( 'text' => true, 'image_generation' => false ) );

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
			// New to this site. Priced from what shipped when the rate is known,
			// otherwise left for the price lookup; offered only once the owner
			// ticks it either way.
			$priced = isset( $documented[ $id ] ) ? array(
				'input_usd' => (float) $documented[ $id ]['input'], 'output_usd' => (float) $documented[ $id ]['output'],
				'price_method' => self::SHIPPED, 'price_source' => (string) ( $documented[ $id ]['source'] ?? '' ), 'price_checked_at' => $now,
			) : array();
			$wpdb->insert( $t['catalog'], array_merge( $update, $priced, array(
				'provider' => $provider, 'model_id' => $id, 'enabled' => 0,
				'created_at' => $now,
			) ) );
		}
		self::prune( $provider );

		// Every identifier was blank: that is a shape this code does not
		// understand, not a provider that serves nothing, so nothing is marked.
		if ( ! $seen ) { return 0; }

		$placeholders = implode( ',', array_fill( 0, count( $seen ), '%s' ) );
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . $t['catalog'] . ' SET served = 0, listed_at = %s, updated_at = %s WHERE provider = %s AND model_id NOT IN (' . $placeholders . ')',
			array_merge( array( $now, $now, $provider ), $seen )
		) );
		return count( $kept );
	}

	/** A rate, with where it came from. Never loses the provenance. */
	public static function remember_price( $provider, $model_id, $input, $output, $method, $source = '' ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( empty( $t['catalog'] ) || ! MSRWA_DB::table_exists( $t['catalog'] ) ) { return false; }
		$method = in_array( $method, array( self::SHIPPED, self::MANUAL, self::LOOKED_UP, self::READ ), true ) ? $method : self::MANUAL;
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
	 * Every priced model's rate is handed over; only enabled ones can become a
	 * tier. A model with no rate makes the run unverifiable against its ceiling,
	 * and the plugin's promise is an estimate before it spends. Withholding the
	 * rate of a model merely not enabled did the same to a route that names it
	 * outright: gemini-3.6-flash ships priced, and routed by name it read as
	 * unpriced and stopped the run.
	 */
	public static function for_engine( array $rows = null ) {
		$models = array();
		$priced = array();
		foreach ( null === $rows ? self::rows() : $rows as $row ) {
			if ( null === $row['input_usd'] || null === $row['output_usd'] ) { continue; }
			$models[ $row['provider'] ][ $row['model_id'] ] = array( (float) $row['input_usd'], (float) $row['output_usd'] );
			if ( ! isset( $row['enabled'] ) || $row['enabled'] ) { $priced[ $row['provider'] ][ $row['model_id'] ] = $row; }
		}
		return array( 'models' => $models, 'tiers' => self::tiers( $priced ) );
	}

	/**
	 * What `low`, `medium` and `high` resolve to, per provider.
	 *
	 * The engine's own pick when this site has it enabled, priced, served and
	 * known to write; otherwise the cheapest, middle and dearest of what the
	 * site actually has, by input rate — so a tier can never name a model the
	 * provider does not serve or nobody has priced, which is exactly how
	 * `claude:low` came to point at an identifier Anthropic retired.
	 *
	 * A provider the catalogue cannot fill is left out entirely rather than
	 * given a wrong answer; the engine's own default then stands, and the
	 * Diagnostic screen says so.
	 */
	private static function tiers( array $priced ) {
		$tiers = array();
		$engine = class_exists( 'MSRWA_Engine_Config' ) ? (array) ( MSRWA_Engine_Config::defaults()['tiers'] ?? array() ) : array();
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
			$sorted = array( 'low' => $ids[0], 'medium' => $ids[ intdiv( count( $ids ) - 1, 2 ) ], 'high' => $ids[ count( $ids ) - 1 ] );
			foreach ( $sorted as $tier => $fallback ) {
				// The engine's own choice stands whenever this site can use it.
				// Position in a price list is not a choice: with nano and mini
				// left unflagged, the middle of OpenAI's list was Terra, and every
				// step of a fresh site ran at ten times the rate of the Luna the
				// engine names — $0.69 a recipe against $0.11.
				$chosen = (string) ( $engine[ $tier ][ $provider ] ?? '' );
				$tiers[ $tier ][ $provider ] = isset( $usable[ $chosen ] ) ? $chosen : $fallback;
			}
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
