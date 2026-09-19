<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Providers {
	public static function image( $provider, $model, $prompt, $size = '1024x1024' ) {
		if ( 'gemini' !== $provider ) { return new WP_Error( 'image_provider_pending', 'La génération image de ce fournisseur n’est pas disponible.', array( 'status' => 409 ) ); }
		$settings = MSRWA_Settings::get();
		$key = defined( 'MSRWA_GEMINI_KEY' ) && MSRWA_GEMINI_KEY ? MSRWA_GEMINI_KEY : ( getenv( 'MSRWA_GEMINI_KEY' ) ?: $settings['gemini_key'] );
		if ( ! $key ) { return new WP_Error( 'missing_gemini_key', 'Aucune clé Gemini côté serveur.', array( 'status' => 400 ) ); }
		$payload = array( 'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => sanitize_textarea_field( $prompt ) ) ) ) ), 'generationConfig' => array( 'responseModalities' => array( 'IMAGE' ) ) );
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );
		$response = wp_remote_post( $url, array( 'timeout' => 120, 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'gemini_image_network', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'gemini_image_api_' . $code, isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : 'Réponse image Gemini invalide.', array( 'status' => $code ) ); }
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( ! empty( $part['inlineData']['data'] ) ) {
				$mime = isset( $part['inlineData']['mimeType'] ) ? $part['inlineData']['mimeType'] : 'image/png';
				return array( 'model' => $model, 'format' => 'image/png' === $mime ? 'png' : 'webp', 'base64' => (string) $part['inlineData']['data'], 'created' => time() );
			}
		}
		return new WP_Error( 'gemini_image_empty', 'Gemini n’a retourné aucune image.', array( 'status' => 502 ) );
	}

	public static function connection_test( $provider ) {
		if ( 'openai' === $provider ) { return MSRWA_OpenAI::connection_test(); }
		$settings = MSRWA_Settings::get();
		$model = 'gemini' === $provider ? $settings['gemini_model'] : $settings['claude_model'];
		$result = self::text( $provider, $model, 'Return exactly READY.', 16, false );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( 'READY' !== trim( $result['text'] ) ) { return new WP_Error( $provider . '_unexpected_output', $provider . ' a répondu, mais la sortie de test est inattendue.', array( 'status' => 502 ) ); }
		return array( 'ok' => true, 'provider' => $provider, 'model' => $result['model'], 'usage' => $result['usage'] );
	}

	public static function text( $provider, $model, $input, $max_tokens = 1200, $search = false ) {
		if ( 'openai' === $provider ) {
			$tools = $search ? array( array( 'type' => 'web_search' ) ) : array();
			return MSRWA_OpenAI::responses_text( $input, $model, $max_tokens, $tools, $search );
		}
		if ( 'gemini' === $provider ) { return self::gemini( $model, $input, $max_tokens, $search ); }
		if ( 'claude' === $provider ) {
			if ( $search ) { return new WP_Error( 'claude_search_pending', 'La recherche native Claude doit être activée et vérifiée dans le compte.', array( 'status' => 409 ) ); }
			return self::claude( $model, $input, $max_tokens );
		}
		return new WP_Error( 'provider_unknown', 'Fournisseur non pris en charge.', array( 'status' => 400 ) );
	}

	private static function gemini( $model, $input, $max_tokens, $search ) {
		$settings = MSRWA_Settings::get();
		$key = defined( 'MSRWA_GEMINI_KEY' ) && MSRWA_GEMINI_KEY ? MSRWA_GEMINI_KEY : ( getenv( 'MSRWA_GEMINI_KEY' ) ?: $settings['gemini_key'] );
		if ( ! $key ) { return new WP_Error( 'missing_gemini_key', 'Aucune clé Gemini côté serveur.', array( 'status' => 400 ) ); }
		$payload = array(
			'contents' => array(
				array( 'role' => 'user', 'parts' => array( array( 'text' => sanitize_textarea_field( $input ) ) ) ),
			),
			'generationConfig' => array( 'maxOutputTokens' => max( 16, absint( $max_tokens ) ), 'responseMimeType' => 'application/json' ),
		);
		if ( $search ) { $payload['tools'] = array( array( 'google_search' => new stdClass() ) ); }
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );
		$response = wp_remote_post( $url, array( 'timeout' => 60, 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'gemini_network', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'gemini_api_' . $code, isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : 'Réponse Gemini invalide.', array( 'status' => $code ) ); }
		$text = '';
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) { if ( isset( $part['text'] ) ) { $text .= (string) $part['text']; } }
		if ( '' === trim( $text ) ) { return new WP_Error( 'gemini_empty', 'Gemini n’a retourné aucun texte.', array( 'status' => 502 ) ); }
		return array( 'id' => '', 'text' => $text, 'sources' => array(), 'usage' => isset( $body['usageMetadata'] ) ? array( 'input_tokens' => absint( $body['usageMetadata']['promptTokenCount'] ?? 0 ), 'output_tokens' => absint( $body['usageMetadata']['candidatesTokenCount'] ?? 0 ) ) : array(), 'model' => $model );
	}

	private static function claude( $model, $input, $max_tokens ) {
		$settings = MSRWA_Settings::get();
		$key = defined( 'MSRWA_CLAUDE_KEY' ) && MSRWA_CLAUDE_KEY ? MSRWA_CLAUDE_KEY : ( getenv( 'MSRWA_CLAUDE_KEY' ) ?: $settings['claude_key'] );
		if ( ! $key ) { return new WP_Error( 'missing_claude_key', 'Aucune clé Claude côté serveur.', array( 'status' => 400 ) ); }
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 60,
			'sslverify' => true,
			'headers' => array( 'Content-Type' => 'application/json', 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ),
			'body' => wp_json_encode( array( 'model' => $model, 'max_tokens' => max( 16, absint( $max_tokens ) ), 'messages' => array( array( 'role' => 'user', 'content' => sanitize_textarea_field( $input ) ) ) ) ),
		) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'claude_network', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'claude_api_' . $code, isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : 'Réponse Claude invalide.', array( 'status' => $code ) ); }
		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $part ) { if ( isset( $part['text'] ) ) { $text .= (string) $part['text']; } }
		if ( '' === trim( $text ) ) { return new WP_Error( 'claude_empty', 'Claude n’a retourné aucun texte.', array( 'status' => 502 ) ); }
		return array( 'id' => isset( $body['id'] ) ? sanitize_text_field( $body['id'] ) : '', 'text' => $text, 'sources' => array(), 'usage' => isset( $body['usage'] ) ? array( 'input_tokens' => absint( $body['usage']['input_tokens'] ?? 0 ), 'output_tokens' => absint( $body['usage']['output_tokens'] ?? 0 ) ) : array(), 'model' => $model );
	}
}
