<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Catalog {
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

	public static function install() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$now = current_time( 'mysql', true );
		$providers = array(
			'openai' => array( 'label' => 'OpenAI', 'adapter' => 'MSRWA_OpenAI', 'capabilities' => array( 'text', 'vision', 'web_search', 'image_generation' ), 'api' => array( 'base_url' => 'https://api.openai.com/v1', 'responses_path' => '/responses', 'models_path' => '/models', 'image_generation_path' => '/images/generations', 'image_edit_path' => '/images/edits', 'timeout_text' => 180, 'timeout_image' => 120, 'auth' => 'bearer' ) ),
			'gemini' => array( 'label' => 'Google Gemini', 'adapter' => 'MSRWA_Providers', 'capabilities' => array( 'text', 'vision', 'web_search', 'image_generation' ), 'api' => array( 'base_url' => 'https://generativelanguage.googleapis.com', 'generate_path' => '/v1beta/models/{model}:generateContent', 'models_path' => '/v1beta/models', 'timeout_text' => 60, 'timeout_image' => 120, 'auth' => 'query_key' ) ),
			'claude' => array( 'label' => 'Anthropic Claude', 'adapter' => 'MSRWA_Providers', 'capabilities' => array( 'text', 'vision', 'web_search' ), 'api' => array( 'base_url' => 'https://api.anthropic.com', 'messages_path' => '/v1/messages', 'api_version' => '2023-06-01', 'timeout_text' => 60, 'auth' => 'x-api-key' ) ),
		);
		foreach ( $providers as $key => $provider ) {
			$sql = "INSERT INTO {$t['providers']} (provider_key,label,enabled,adapter_class,capabilities_json,api_config_json,status_json,created_at,updated_at) VALUES (%s,%s,1,%s,%s,%s,'{}',%s,%s) ON DUPLICATE KEY UPDATE provider_key=VALUES(provider_key)";
			$wpdb->query( $wpdb->prepare( $sql, $key, $provider['label'], $provider['adapter'], wp_json_encode( $provider['capabilities'] ), wp_json_encode( $provider['api'] ), $now, $now ) );
			$current_json = $wpdb->get_var( $wpdb->prepare( "SELECT api_config_json FROM {$t['providers']} WHERE provider_key = %s", $key ) );
			$current = json_decode( (string) $current_json, true );
			$current = is_array( $current ) ? $current : array();
			$merged = array_merge( $provider['api'], $current );
			if ( $merged !== $current ) {
				$wpdb->update( $t['providers'], array( 'api_config_json' => wp_json_encode( $merged ), 'updated_at' => $now ), array( 'provider_key' => $key ), array( '%s', '%s' ), array( '%s' ) );
			}
		}
		foreach ( self::defaults() as $provider => $models ) {
			foreach ( $models as $model_id => $model ) {
				$capabilities = array();
				foreach ( array( 'text', 'vision', 'web_search', 'image_generation' ) as $capability ) { $capabilities[ $capability ] = ! empty( $model[ $capability ] ); }
				$pricing = array( 'input' => (float) ( $model['input'] ?? 0 ), 'output' => (float) ( $model['output'] ?? 0 ), 'image_input' => (float) ( $model['image_input'] ?? 0 ), 'image_tokens' => isset( $model['image_tokens'] ) ? $model['image_tokens'] : array(), 'currency' => 'USD', 'unit' => 'million_tokens' );
				$api = array( 'identifier' => $model_id );
				$sql = "INSERT INTO {$t['models']} (provider_key,model_id,label,stable,enabled,capabilities_json,pricing_json,api_specifics_json,source_url,verified_at,created_at,updated_at) VALUES (%s,%s,%s,%d,1,%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE model_id=VALUES(model_id)";
				$wpdb->query( $wpdb->prepare( $sql, $provider, $model_id, $model['label'], empty( $model['stable'] ) ? 0 : 1, wp_json_encode( $capabilities ), wp_json_encode( $pricing ), wp_json_encode( $api ), $model['source'], $now, $now, $now ) );
				$current_json = $wpdb->get_var( $wpdb->prepare( "SELECT api_specifics_json FROM {$t['models']} WHERE provider_key = %s AND model_id = %s", $provider, $model_id ) );
				$current = json_decode( (string) $current_json, true );
				$current = is_array( $current ) ? $current : array();
				$merged = array_merge( $api, $current );
				if ( $merged !== $current ) {
					$wpdb->update( $t['models'], array( 'api_specifics_json' => wp_json_encode( $merged ), 'updated_at' => $now ), array( 'provider_key' => $provider, 'model_id' => $model_id ), array( '%s', '%s' ), array( '%s', '%s' ) );
				}
			}
		}
	}

	public static function models( $include_disabled = false ) {
		if ( ! class_exists( 'MSRWA_DB' ) ) { return self::defaults(); }
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( empty( $t['models'] ) || ! MSRWA_DB::table_exists( $t['models'] ) ) { return self::defaults(); }
		$where = $include_disabled ? '' : ' WHERE enabled = 1';
		$rows = $wpdb->get_results( "SELECT * FROM {$t['models']}{$where} ORDER BY provider_key ASC,id ASC", ARRAY_A );
		if ( ! $rows ) { return self::defaults(); }
		$out = array();
		foreach ( $rows as $row ) {
			$capabilities = json_decode( (string) $row['capabilities_json'], true );
			$pricing = json_decode( (string) $row['pricing_json'], true );
			$api = json_decode( (string) $row['api_specifics_json'], true );
			$model = array_merge( is_array( $capabilities ) ? $capabilities : array(), is_array( $pricing ) ? $pricing : array(), is_array( $api ) ? $api : array() );
			$model['label'] = $row['label'];
			$model['stable'] = ! empty( $row['stable'] );
			$model['enabled'] = ! empty( $row['enabled'] );
			$model['source'] = $row['source_url'];
			$model['verified_at'] = $row['verified_at'];
			$model['api_specifics'] = is_array( $api ) ? $api : array();
			if ( ! empty( $model['image_generation'] ) && empty( $model['image_tokens'] ) ) {
				$seeded = self::defaults();
				$model['image_tokens'] = $seeded[ $row['provider_key'] ][ $row['model_id'] ]['image_tokens'] ?? array();
			}
			$out[ $row['provider_key'] ][ $row['model_id'] ] = $model;
		}
		return $out;
	}

	public static function eligible( $capability = 'text' ) {
		$out = array();
		$state = self::status();
		$enabled_providers = array();
		foreach ( self::providers() as $provider_row ) { if ( ! empty( $provider_row['enabled'] ) ) { $enabled_providers[ $provider_row['provider_key'] ] = true; } }
		foreach ( self::models() as $provider => $models ) {
			// The Claude transport currently implements text and vision, not native search.
			if ( 'claude' === $provider && 'web_search' === $capability ) { continue; }
			if ( $enabled_providers && empty( $enabled_providers[ $provider ] ) ) { continue; }
			$known_ids = ! empty( $state[ $provider ]['available_ids'] ) && is_array( $state[ $provider ]['available_ids'] ) ? array_flip( $state[ $provider ]['available_ids'] ) : array();
			foreach ( $models as $id => $model ) {
				if ( 'vision' === $capability && empty( $model['text'] ) ) { continue; }
				if ( ! empty( $model['stable'] ) && ! empty( $model[ $capability ] ) && ( empty( $known_ids ) || isset( $known_ids[ $id ] ) ) ) { $out[ $provider . ':' . $id ] = $model + array( 'provider' => $provider, 'id' => $id ); }
			}
		}
		return $out;
	}

	public static function status() {
		if ( ! class_exists( 'MSRWA_DB' ) ) { return array(); }
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( ! MSRWA_DB::table_exists( $t['providers'] ) ) { return array(); }
		$rows = $wpdb->get_results( "SELECT provider_key,status_json FROM {$t['providers']} ORDER BY provider_key ASC", ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) { $state = json_decode( (string) $row['status_json'], true ); $out[ $row['provider_key'] ] = is_array( $state ) ? $state : array(); }
		return $out;
	}

	public static function providers() {
		if ( ! class_exists( 'MSRWA_DB' ) ) { return array(); }
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( ! MSRWA_DB::table_exists( $t['providers'] ) ) { return array(); }
		$rows = $wpdb->get_results( "SELECT * FROM {$t['providers']} ORDER BY provider_key ASC", ARRAY_A );
		foreach ( $rows as &$row ) {
			foreach ( array( 'capabilities_json' => 'capabilities', 'api_config_json' => 'api_config', 'status_json' => 'status' ) as $column => $key ) {
				$value = json_decode( (string) $row[ $column ], true );
				$row[ $key ] = is_array( $value ) ? $value : array();
			}
		}
		unset( $row );
		return $rows;
	}

	public static function provider_api( $provider ) {
		$provider = sanitize_key( $provider );
		foreach ( self::providers() as $row ) { if ( $provider === $row['provider_key'] ) { return $row['api_config']; } }
		return array();
	}

	public static function endpoint( $provider, $path_key, $replacements = array() ) {
		$config = self::provider_api( $provider );
		$base = isset( $config['base_url'] ) ? untrailingslashit( esc_url_raw( $config['base_url'] ) ) : '';
		$path = isset( $config[ $path_key ] ) ? '/' . ltrim( (string) $config[ $path_key ], '/' ) : '';
		foreach ( $replacements as $key => $value ) { $path = str_replace( '{' . sanitize_key( $key ) . '}', rawurlencode( (string) $value ), $path ); }
		return $base && $path ? $base . $path : '';
	}

	public static function timeout( $provider, $key, $fallback ) {
		$config = self::provider_api( $provider );
		return min( 300, max( 5, absint( $config[ $key ] ?? $fallback ) ) );
	}

	/** Generated-image token counts, per size and quality, as the administrator corrected them. */
	private static function sanitize_image_tokens( $raw ) {
		$out = array();
		foreach ( (array) $raw as $size => $qualities ) {
			if ( ! preg_match( '/^\d{3,5}x\d{3,5}$/', (string) $size ) || ! is_array( $qualities ) ) { continue; }
			foreach ( array( 'low', 'medium', 'high' ) as $quality ) {
				if ( isset( $qualities[ $quality ] ) && '' !== $qualities[ $quality ] ) { $out[ $size ][ $quality ] = max( 0, (int) $qualities[ $quality ] ); }
			}
		}
		return $out;
	}

	private static function save_status( $provider, $state ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		return false !== $wpdb->update( $t['providers'], array( 'status_json' => wp_json_encode( is_array( $state ) ? $state : array() ), 'updated_at' => current_time( 'mysql', true ) ), array( 'provider_key' => sanitize_key( $provider ) ), array( '%s', '%s' ), array( '%s' ) );
	}

}
