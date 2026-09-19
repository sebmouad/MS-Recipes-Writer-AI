<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Catalog {
	const STATE_OPTION = 'msrwa_catalog_state';
	public static function models() {
		return array(
			'openai' => array(
				'gpt-5.6-luna' => array( 'label' => 'GPT-5.6 Luna', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 0.20, 'output' => 1.20, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-luna' ),
				'gpt-5.6-terra' => array( 'label' => 'GPT-5.6 Terra', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 2.00, 'output' => 12.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-terra' ),
				'gpt-5.6-sol' => array( 'label' => 'GPT-5.6 Sol', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 4.00, 'output' => 20.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-sol' ),
				'gpt-image-2.5-flare' => array( 'label' => 'GPT Image 2.5 Flare', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'input' => 5.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-flare' ),
				'gpt-image-2.5-sunburst' => array( 'label' => 'GPT Image 2.5 Sunburst', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'input' => 5.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-sunburst' ),
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

	public static function eligible( $capability = 'text' ) {
		$out = array();
		$state = function_exists( 'get_option' ) ? get_option( self::STATE_OPTION, array() ) : array();
		foreach ( self::models() as $provider => $models ) {
			$known_ids = ! empty( $state[ $provider ]['available_ids'] ) && is_array( $state[ $provider ]['available_ids'] ) ? array_flip( $state[ $provider ]['available_ids'] ) : array();
			foreach ( $models as $id => $model ) {
				if ( ! empty( $model['stable'] ) && ! empty( $model[ $capability ] ) && ( empty( $known_ids ) || isset( $known_ids[ $id ] ) ) ) { $out[ $provider . ':' . $id ] = $model + array( 'provider' => $provider, 'id' => $id ); }
			}
		}
		return $out;
	}

	public static function status() { return function_exists( 'get_option' ) ? get_option( self::STATE_OPTION, array() ) : array(); }

	public static function sync( $provider ) {
		$provider = sanitize_key( $provider );
		if ( ! in_array( $provider, array( 'openai', 'gemini' ), true ) ) { return new WP_Error( 'catalog_sync_unsupported', 'La synchronisation automatique de ce fournisseur n’est pas disponible.' ); }
		if ( 'openai' === $provider ) {
			$key = MSRWA_OpenAI::key();
			if ( ! $key ) { return new WP_Error( 'missing_openai_key', 'Aucune clé OpenAI côté serveur.' ); }
			$response = wp_remote_get( 'https://api.openai.com/v1/models', array( 'timeout' => 20, 'sslverify' => true, 'headers' => array( 'Authorization' => 'Bearer ' . $key ) ) );
		} else {
			$s = MSRWA_Settings::get();
			$key = defined( 'MSRWA_GEMINI_KEY' ) && MSRWA_GEMINI_KEY ? MSRWA_GEMINI_KEY : ( getenv( 'MSRWA_GEMINI_KEY' ) ?: $s['gemini_key'] );
			if ( ! $key ) { return new WP_Error( 'missing_gemini_key', 'Aucune clé Gemini côté serveur.' ); }
			$response = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $key ), array( 'timeout' => 20, 'sslverify' => true ) );
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
		$state = self::status();
		$state[ $provider ] = array( 'available_ids' => array_values( array_unique( $ids ) ), 'checked_at' => current_time( 'mysql', true ), 'status' => 'ok' );
		update_option( self::STATE_OPTION, $state, false );
		return array( 'provider' => $provider, 'count' => count( $ids ), 'checked_at' => $state[ $provider ]['checked_at'] );
	}
}
