<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Catalog {
	public static function defaults() {
		return array(
			'openai' => array(
				'gpt-5.6-luna' => array( 'label' => 'GPT-5.6 Luna', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 0.20, 'output' => 1.20, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-luna' ),
				'gpt-5.6-terra' => array( 'label' => 'GPT-5.6 Terra', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 2.00, 'output' => 12.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-terra' ),
				'gpt-5.6-sol' => array( 'label' => 'GPT-5.6 Sol', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 4.00, 'output' => 20.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-sol' ),
				'gpt-image-2.5-flare' => array( 'label' => 'GPT Image 2.5 Flare', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'input' => 5.00, 'image_input' => 8.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-flare' ),
				'gpt-image-2.5-sunburst' => array( 'label' => 'GPT Image 2.5 Sunburst', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'input' => 5.00, 'image_input' => 8.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-sunburst' ),
			),
			'gemini' => array(
				'gemini-3.1-flash-image' => array( 'label' => 'Gemini 3.1 Flash Image', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => true, 'input' => 0.50, 'output' => 3.00, 'source' => 'https://ai.google.dev/gemini-api/docs/image-generation' ),
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
				$pricing = array( 'input' => (float) ( $model['input'] ?? 0 ), 'output' => (float) ( $model['output'] ?? 0 ), 'image_input' => (float) ( $model['image_input'] ?? 0 ), 'currency' => 'USD', 'unit' => 'million_tokens' );
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
		if ( ! MSRWA_DB::table_exists( $t['models'] ) ) { return self::defaults(); }
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

	public static function save_admin( $raw ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$raw = is_array( $raw ) ? $raw : array();
		$known_providers = wp_list_pluck( self::providers(), 'provider_key' );
		foreach ( $known_providers as $provider ) {
			$data = isset( $raw['providers'][ $provider ] ) && is_array( $raw['providers'][ $provider ] ) ? $raw['providers'][ $provider ] : array();
			$api_config = isset( $data['api_config'] ) && is_array( $data['api_config'] ) ? $data['api_config'] : array();
			$clean_api = array();
			foreach ( $api_config as $key => $value ) { $clean_api[ sanitize_key( $key ) ] = sanitize_text_field( $value ); }
			$wpdb->update( $t['providers'], array( 'enabled' => empty( $data['enabled'] ) ? 0 : 1, 'api_config_json' => wp_json_encode( $clean_api ), 'updated_at' => current_time( 'mysql', true ) ), array( 'provider_key' => $provider ), array( '%d', '%s', '%s' ), array( '%s' ) );
		}
		$models = self::models( true );
		foreach ( $models as $provider => $provider_models ) {
			foreach ( $provider_models as $model_id => $model ) {
				$data = isset( $raw['models'][ $provider ][ $model_id ] ) && is_array( $raw['models'][ $provider ][ $model_id ] ) ? $raw['models'][ $provider ][ $model_id ] : array();
				$pricing = array( 'input' => max( 0, (float) ( $data['input'] ?? $model['input'] ?? 0 ) ), 'output' => max( 0, (float) ( $data['output'] ?? $model['output'] ?? 0 ) ), 'image_input' => max( 0, (float) ( $data['image_input'] ?? $model['image_input'] ?? 0 ) ), 'currency' => 'USD', 'unit' => 'million_tokens' );
				$capabilities = array();
				foreach ( array( 'text', 'vision', 'web_search', 'image_generation' ) as $capability ) { $capabilities[ $capability ] = ! empty( $data[ $capability ] ); }
				$api_specifics = isset( $data['api_specifics'] ) && is_array( $data['api_specifics'] ) ? $data['api_specifics'] : ( $model['api_specifics'] ?? array() );
				$clean_specifics = array();
				foreach ( $api_specifics as $key => $value ) { if ( is_scalar( $value ) ) { $clean_specifics[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value ); } }
				$wpdb->update( $t['models'], array( 'label' => sanitize_text_field( $data['label'] ?? $model['label'] ), 'stable' => empty( $data['stable'] ) ? 0 : 1, 'enabled' => empty( $data['enabled'] ) ? 0 : 1, 'capabilities_json' => wp_json_encode( $capabilities ), 'pricing_json' => wp_json_encode( $pricing ), 'api_specifics_json' => wp_json_encode( $clean_specifics ), 'source_url' => esc_url_raw( $data['source_url'] ?? $model['source'] ), 'verified_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( 'provider_key' => $provider, 'model_id' => $model_id ), array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%s', '%s' ) );
			}
		}
		MSRWA_DB::event( 'catalog_configuration_saved', 0, 0, array( 'providers' => count( $known_providers ), 'models' => array_sum( array_map( 'count', $models ) ) ) );
	}

	private static function save_status( $provider, $state ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		return false !== $wpdb->update( $t['providers'], array( 'status_json' => wp_json_encode( is_array( $state ) ? $state : array() ), 'updated_at' => current_time( 'mysql', true ) ), array( 'provider_key' => sanitize_key( $provider ) ), array( '%s', '%s' ), array( '%s' ) );
	}

	public static function sync( $provider ) {
		$provider = sanitize_key( $provider );
		if ( ! in_array( $provider, array( 'openai', 'gemini' ), true ) ) { return new WP_Error( 'catalog_sync_unsupported', 'La synchronisation automatique de ce fournisseur n’est pas disponible.' ); }
		if ( 'openai' === $provider ) {
			$key = MSRWA_OpenAI::key();
			if ( ! $key ) { return new WP_Error( 'missing_openai_key', 'Aucune clé OpenAI côté serveur.' ); }
			$url = self::endpoint( 'openai', 'models_path' );
			if ( ! $url ) { return new WP_Error( 'openai_models_endpoint_missing', 'Endpoint OpenAI Models non configuré.' ); }
			$response = wp_remote_get( $url, array( 'timeout' => self::timeout( 'openai', 'timeout_text', 20 ), 'sslverify' => true, 'headers' => array( 'Authorization' => 'Bearer ' . $key ) ) );
		} else {
			$s = MSRWA_Settings::get();
			$key = defined( 'MSRWA_GEMINI_KEY' ) && MSRWA_GEMINI_KEY ? MSRWA_GEMINI_KEY : ( getenv( 'MSRWA_GEMINI_KEY' ) ?: $s['gemini_key'] );
			if ( ! $key ) { return new WP_Error( 'missing_gemini_key', 'Aucune clé Gemini côté serveur.' ); }
			$url = self::endpoint( 'gemini', 'models_path' );
			if ( ! $url ) { return new WP_Error( 'gemini_models_endpoint_missing', 'Endpoint Gemini Models non configuré.' ); }
			$response = wp_remote_get( add_query_arg( 'key', $key, $url ), array( 'timeout' => self::timeout( 'gemini', 'timeout_text', 20 ), 'sslverify' => true ) );
		}
		if ( is_wp_error( $response ) ) { return new WP_Error( 'catalog_sync_network', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) { return new WP_Error( 'catalog_sync_response', 'La réponse du catalogue fournisseur est invalide.', array( 'status' => $code ?: 502 ) ); }
		$rows = 'openai' === $provider ? ( isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array() ) : ( isset( $body['models'] ) && is_array( $body['models'] ) ? $body['models'] : array() );
		$ids = array();
		foreach ( $rows as $row ) {
			$id = 'openai' === $provider ? ( isset( $row['id'] ) ? $row['id'] : '' ) : ( isset( $row['name'] ) ? preg_replace( '#^models/#', '', $row['name'] ) : '' );
			if ( $id ) { $ids[] = sanitize_text_field( $id ); }
		}
		$provider_state = array( 'available_ids' => array_values( array_unique( $ids ) ), 'checked_at' => current_time( 'mysql', true ), 'status' => 'ok' );
		self::save_status( $provider, $provider_state );
		return array( 'provider' => $provider, 'count' => count( $ids ), 'checked_at' => $provider_state['checked_at'] );
	}
}
