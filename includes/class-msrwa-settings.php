<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Settings {

	/** Everything an administrator changed, and nothing they did not. */
	const OPTION = 'msrwa_settings';

	const FORM_FIELD = 'msrwa_settings';
	const SCOPE = 'global';

	public static function defaults() {
		$defaults = array(
			'openai_key'          => '',
			'gemini_key'          => '',
			'claude_key'          => '',
			'per_recipe_budget_usd' => 0.20,
			'daily_budget_usd'    => 0,
			'monthly_budget_usd'  => 0,
			'retention_events_days' => 90,
			'retention_artifacts_days' => 365,
			'retention_runs_days' => 0,
			'featured_ratio'      => '1:1',
			'facebook_ratio'      => '2:3',
			'image_quality'       => 'medium',
			'image_format'        => 'webp',
			'internal_links_max'     => 3,
			'quality_min_score'        => 90,
			'quality_min_words'   => 2400,
			'site_language'       => 'fr',
			'recipe_schema'       => 1,
			'seo_meta'            => 1,
			'article_page2_heading' => 'Préparation de la recette étape par étape',
			'required_sections'   => array(),

			'quality_max_words'        => 3200,
			'quality_min_headings'     => 10,
			'quality_min_paragraphs'   => 24,
			'quality_min_ingredients'  => 6,
			'quality_min_steps'        => 6,
			'research_facts_max'      => 12,
			'research_references_max' => 6,
			'article_pagination_enabled' => 1,
			'integration_mapping' => array( 'prep_minutes' => '_recipe_prep_time', 'cook_minutes' => '_recipe_cook_time', 'total_minutes' => '_recipe_total_time', 'recipe_category' => '_recipe_category', 'description' => '_recipe_description', 'servings' => '_recipe_servings', 'calories_estimate' => '_recipe_calories', 'cuisine' => '_recipe_cuisine', 'difficulty' => '_recipe_difficulty', 'equipment' => '_recipe_equipment', 'notes' => '_recipe_notes', 'faq' => '_recipe_faq', 'keywords' => '_recipe_keywords', 'ingredients' => '_recipe_ingredients', 'instructions' => '_recipe_instructions', 'seo_title' => '_seo_title', 'seo_description' => '_seo_description', 'facebook_meta' => 'fb_images_data' ),
		);
		return $defaults;
	}

	/**
	 * The effective settings: the defaults above with whatever is stored over
	 * them, secrets decrypted.
	 *
	 * These are what MSRWA_Prompt, MSRWA_Quality and MSRWA_Images read, which is
	 * why `defaults()` above is left exactly as the engine expects it. What
	 * changed here is only where the stored half lives: one WordPress option
	 * instead of two tables and a history, because this plugin has one job and
	 * a settings audit trail was not part of it.
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		$defaults = self::defaults();
		// A setting a later version retired stays in the option until the next
		// save; it is not handed to anything in the meantime.
		$values = array_merge( $defaults, array_intersect_key( is_array( $stored ) ? $stored : array(), $defaults ) );
		foreach ( self::secrets() as $key ) { $values[ $key ] = self::decrypt_secret( (string) ( $values[ $key ] ?? '' ) ); }
		return $values;
	}

	/** Stores the difference from the defaults, with every key encrypted on the way in. */
	public static function save( $raw, $source = 'admin' ) {
		// The credentials form only submits three fields; preserve all others.
		$clean = self::sanitize( array_merge( self::get(), (array) $raw ) );
		$defaults = self::defaults();
		$stored = (array) get_option( self::OPTION, array() );
		$out = array();

		foreach ( $clean as $key => $value ) {
			if ( self::is_secret( $key ) ) {
				// An untouched key field posts back empty or masked; that means
				// "leave it alone", never "delete the key".
				if ( '' === trim( (string) $value ) ) {
					if ( isset( $stored[ $key ] ) ) { $out[ $key ] = $stored[ $key ]; }
					continue;
				}
				$out[ $key ] = self::encrypt_secret( (string) $value );
				continue;
			}
			if ( ! array_key_exists( $key, $defaults ) || $value !== $defaults[ $key ] ) { $out[ $key ] = $value; }
		}

		update_option( self::OPTION, $out, false );
		return true;
	}

	/** Nothing to install: the defaults are code and the overrides are one option. */
	public static function install() { return true; }

	private static function secrets() { return array( 'openai_key', 'gemini_key', 'claude_key' ); }

	private static function is_secret( $key ) { return in_array( $key, self::secrets(), true ); }

	/** Empty, omitted or masked fields mean keep the stored credential. */
	private static function secret_for_save( $key, $value ) {
		if ( ! is_string( $value ) ) { return ''; }
		$value = trim( $value );
		if ( '' === $value || preg_match( '/[\x{2022}\x{2026}*]|\.{3}/u', $value ) ) { return ''; }
		return $value;
	}

	/**
	 * Which setting holds each provider's key, under the provider's name as the
	 * engine spells it. The engine looks a key up as `settings.keys.claude`; a
	 * key handed over under any other name is a key it never sees.
	 */
	public static function key_fields() { return array( 'openai' => 'openai_key', 'gemini' => 'gemini_key', 'claude' => 'claude_key' ); }

	/** Which providers have a key, for a screen that must not print one. */
	public static function configured_providers() {
		$values = self::get();
		$out = array();
		foreach ( self::key_fields() as $provider => $field ) {
			if ( '' !== trim( (string) ( $values[ $field ] ?? '' ) ) ) { $out[] = $provider; }
		}
		return $out;
	}

	/**
	 * The keys, in the shape the engine takes them: `settings.keys.<provider>`.
	 *
	 * WordPress keeps them encrypted and has no environment to export them into,
	 * and `settings` is the one branch of the engine's configuration that never
	 * reaches a stored record.
	 */
	public static function engine_keys() {
		$values = self::get();
		$keys = array();
		foreach ( self::key_fields() as $provider => $field ) {
			$value = trim( (string) ( $values[ $field ] ?? '' ) );
			if ( '' !== $value ) { $keys[ $provider ] = $value; }
		}
		return $keys;
	}

	/**
	 * What the engine receives as its `settings` branch: the site's prompt and
	 * quality settings, and the keys.
	 *
	 * The engine treats a non-empty `settings` as the caller's complete set and
	 * stops reading the shipped defaults. Handing it the keys alone therefore
	 * handed it no word count, no minimum, no page split and no language: every
	 * threshold compared against zero and the site's own choices never reached a
	 * prompt.
	 */
	public static function engine_settings() {
		$values = self::get();
		foreach ( self::key_fields() as $field ) { unset( $values[ $field ] ); }
		$keys = self::engine_keys();
		if ( $keys ) { $values['keys'] = $keys; }
		return $values;
	}

	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$defaults = self::defaults();
		$out = $defaults;
		foreach ( array( 'openai_key', 'gemini_key', 'claude_key' ) as $key ) {
			$out[ $key ] = self::secret_for_save( $key, $raw[ $key ] ?? null );
		}
		$out['featured_ratio'] = isset( $raw['featured_ratio'] ) && in_array( $raw['featured_ratio'], array( '1:1', '4:5', '3:2', '2:3' ), true ) ? $raw['featured_ratio'] : $defaults['featured_ratio'];
		$out['facebook_ratio'] = isset( $raw['facebook_ratio'] ) && in_array( $raw['facebook_ratio'], array( '4:5', '1:1', '2:3', '3:2' ), true ) ? $raw['facebook_ratio'] : $defaults['facebook_ratio'];
		foreach ( array( 'image_quality' ) as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) && in_array( $raw[ $key ], MSRWA_Images::qualities(), true ) ? $raw[ $key ] : $defaults[ $key ];
		}
		$out['image_format'] = isset( $raw['image_format'] ) && in_array( $raw['image_format'], array( 'webp', 'jpeg', 'png' ), true ) ? $raw['image_format'] : $defaults['image_format'];
		// Zero means "never remove anything", so these cannot share the loop
		// above: its floor of one would quietly turn a site that asked to keep
		// everything into one that keeps a day.
		foreach ( array( 'retention_events_days', 'retention_artifacts_days', 'retention_runs_days' ) as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) ? min( 3650, max( 0, absint( $raw[ $key ] ) ) ) : $defaults[ $key ];
		}
		foreach ( array( 'per_recipe_budget_usd', 'daily_budget_usd', 'monthly_budget_usd' ) as $key ) { $out[ $key ] = isset( $raw[ $key ] ) ? min( 100000, max( 0, (float) $raw[ $key ] ) ) : $defaults[ $key ]; }
		$out['article_pagination_enabled'] = empty( $raw['article_pagination_enabled'] ) ? 0 : 1;
		$out['recipe_schema'] = empty( $raw['recipe_schema'] ) ? 0 : 1;
		$out['seo_meta'] = empty( $raw['seo_meta'] ) ? 0 : 1;
		$out['internal_links_max'] = isset( $raw['internal_links_max'] ) ? min( 10, max( 0, absint( $raw['internal_links_max'] ) ) ) : $defaults['internal_links_max'];
		$out['quality_min_score'] = isset( $raw['quality_min_score'] ) ? min( 100, max( 1, absint( $raw['quality_min_score'] ) ) ) : $defaults['quality_min_score'];
		foreach ( array( 'quality_min_words' => array( 300, 8000 ), 'quality_max_words' => array( 500, 10000 ), 'quality_min_headings' => array( 3, 80 ), 'quality_min_paragraphs' => array( 5, 150 ), 'quality_min_ingredients' => array( 1, 50 ), 'quality_min_steps' => array( 1, 40 ), 'research_facts_max' => array( 3, 30 ), 'research_references_max' => array( 1, 20 ), ) as $key => $limits ) {
			$value = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : $defaults[ $key ];
			$out[ $key ] = min( $limits[1], max( $limits[0], $value ) );
		}
		if ( $out['quality_max_words'] < $out['quality_min_words'] ) { $out['quality_max_words'] = $out['quality_min_words']; }
		// These were never read back from a submission, so every save reset them
		// to what ships: the site language could not be chosen at all.
		$out['site_language'] = isset( $raw['site_language'] ) && in_array( $raw['site_language'], array( 'fr', 'en', 'ar', 'es' ), true ) ? $raw['site_language'] : $defaults['site_language'];
		$heading = isset( $raw['article_page2_heading'] ) ? trim( sanitize_text_field( (string) $raw['article_page2_heading'] ) ) : '';
		$out['article_page2_heading'] = '' !== $heading ? mb_substr( $heading, 0, 120 ) : $defaults['article_page2_heading'];
		if ( isset( $raw['required_sections'] ) && is_array( $raw['required_sections'] ) ) {
			$out['required_sections'] = array_values( array_filter( array_map( static function ( $line ) { return mb_substr( trim( sanitize_text_field( (string) $line ) ), 0, 160 ); }, $raw['required_sections'] ) ) );
		}
		if ( isset( $raw['integration_mapping_json'] ) ) {
			$mapping = json_decode( (string) $raw['integration_mapping_json'], true );
			if ( is_array( $mapping ) ) { foreach ( $defaults['integration_mapping'] as $key => $fallback ) { if ( isset( $mapping[ $key ] ) ) { $out['integration_mapping'][ $key ] = sanitize_key( $mapping[ $key ] ); } } }
		} elseif ( isset( $raw['integration_mapping'] ) && is_array( $raw['integration_mapping'] ) ) {
			foreach ( $defaults['integration_mapping'] as $key => $fallback ) { if ( isset( $raw['integration_mapping'][ $key ] ) ) { $out['integration_mapping'][ $key ] = sanitize_key( $raw['integration_mapping'][ $key ] ); } }
		}
		return $out;
	}

	private static function crypto_available() { return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && function_exists( 'wp_salt' ); }

	private static function crypto_key() { return hash( 'sha256', wp_salt( 'auth' ) . '|' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ), true ); }

	private static function encrypt_secret( $value ) {
		$value = (string) $value;
		if ( '' === $value || 0 === strpos( $value, 'enc:v1:' ) || ! self::crypto_available() ) { return $value; }
		$iv = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $value, 'AES-256-CBC', self::crypto_key(), OPENSSL_RAW_DATA, $iv );
		return false === $cipher ? $value : 'enc:v1:' . base64_encode( $iv . $cipher );
	}

	private static function decrypt_secret( $value ) {
		$value = (string) $value;
		if ( 0 !== strpos( $value, 'enc:v1:' ) || ! self::crypto_available() ) { return $value; }
		$decoded = base64_decode( substr( $value, 7 ), true );
		if ( false === $decoded || strlen( $decoded ) <= 16 ) { return ''; }
		$plain = openssl_decrypt( substr( $decoded, 16 ), 'AES-256-CBC', self::crypto_key(), OPENSSL_RAW_DATA, substr( $decoded, 0, 16 ) );
		return false === $plain ? '' : $plain;
	}
}
