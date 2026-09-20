<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Providers {
	public static function image( $provider, $model, $prompt, $size = '1024x1024' ) {
		if ( 'gemini' !== $provider ) { return new WP_Error( 'image_provider_pending', 'La génération image de ce fournisseur n’est pas disponible.', array( 'status' => 409 ) ); }
		$settings = MSRWA_Settings::get();
		$key = defined( 'MSRWA_GEMINI_KEY' ) && MSRWA_GEMINI_KEY ? MSRWA_GEMINI_KEY : ( getenv( 'MSRWA_GEMINI_KEY' ) ?: $settings['gemini_key'] );
		if ( ! $key ) { return new WP_Error( 'missing_gemini_key', 'Aucune clé Gemini côté serveur.', array( 'status' => 400 ) ); }
		$payload = array( 'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => sanitize_textarea_field( $prompt ) ) ) ) ), 'generationConfig' => array( 'responseModalities' => array( 'IMAGE' ) ) );
		$url = self::gemini_endpoint( $model, $key );
		if ( is_wp_error( $url ) ) { return $url; }
		$response = wp_remote_post( $url, array( 'timeout' => MSRWA_Catalog::timeout( 'gemini', 'timeout_image', 120 ), 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
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

	public static function image_edit( $provider, $model, $reference_path, $prompt, $size = '1024x1536' ) {
		if ( 'gemini' !== $provider ) { return new WP_Error( 'image_edit_provider_pending', 'L’édition image n’est pas disponible pour ce fournisseur.', array( 'status' => 409 ) ); }
		if ( ! is_readable( $reference_path ) || filesize( $reference_path ) > 10 * 1024 * 1024 ) { return new WP_Error( 'image_reference_invalid', 'L’image de référence est absente ou dépasse la limite.', array( 'status' => 400 ) ); }
		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $reference_path ) : 'image/jpeg';
		if ( 0 !== strpos( (string) $mime, 'image/' ) ) { return new WP_Error( 'image_reference_mime_invalid', 'Le fichier de référence n’est pas une image.', array( 'status' => 400 ) ); }
		$binary = file_get_contents( $reference_path );
		if ( false === $binary ) { return new WP_Error( 'image_reference_read_failed', 'Impossible de lire l’image de référence.', array( 'status' => 400 ) ); }
		$settings = MSRWA_Settings::get();
		$key = defined( 'MSRWA_GEMINI_KEY' ) && MSRWA_GEMINI_KEY ? MSRWA_GEMINI_KEY : ( getenv( 'MSRWA_GEMINI_KEY' ) ?: $settings['gemini_key'] );
		if ( ! $key ) { return new WP_Error( 'missing_gemini_key', 'Aucune clé Gemini côté serveur.', array( 'status' => 400 ) ); }
		$payload = array( 'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'inlineData' => array( 'mimeType' => $mime, 'data' => base64_encode( $binary ) ) ), array( 'text' => sanitize_textarea_field( $prompt ) ) ) ) ), 'generationConfig' => array( 'responseModalities' => array( 'IMAGE' ) ) );
		$url = self::gemini_endpoint( $model, $key );
		if ( is_wp_error( $url ) ) { return $url; }
		$response = wp_remote_post( $url, array( 'timeout' => MSRWA_Catalog::timeout( 'gemini', 'timeout_image', 120 ), 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'gemini_image_edit_network', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'gemini_image_edit_api_' . $code, isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : 'Réponse édition image Gemini invalide.', array( 'status' => $code ) ); }
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( ! empty( $part['inlineData']['data'] ) ) {
				$format = isset( $part['inlineData']['mimeType'] ) && 'image/png' === $part['inlineData']['mimeType'] ? 'png' : 'webp';
				return array( 'model' => $model, 'format' => $format, 'base64' => (string) $part['inlineData']['data'], 'created' => time() );
			}
		}
		return new WP_Error( 'gemini_image_edit_empty', 'Gemini n’a retourné aucune variante image.', array( 'status' => 502 ) );
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

	public static function research_fallback( $query, $max_results = 5 ) {
		$settings = MSRWA_Settings::get();
		if ( 'custom_json' !== $settings['research_fallback_provider'] ) { return new WP_Error( 'research_fallback_disabled', 'La recherche de secours est désactivée.' ); }
		$url = esc_url_raw( $settings['research_fallback_url'] );
		if ( ! self::safe_external_endpoint( $url ) ) { return new WP_Error( 'research_fallback_url_invalid', 'L’URL du service de recherche de secours doit être HTTPS et publique.', array( 'status' => 400 ) ); }
		$headers = array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' );
		if ( ! empty( $settings['research_fallback_key'] ) ) { $headers['Authorization'] = 'Bearer ' . $settings['research_fallback_key']; }
		$response = wp_remote_post( $url, array( 'timeout' => 12, 'redirection' => 0, 'sslverify' => true, 'headers' => $headers, 'body' => wp_json_encode( array( 'query' => sanitize_text_field( $query ), 'max_results' => min( 10, max( 1, absint( $max_results ) ) ) ) ) ) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'research_fallback_network', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) { return new WP_Error( 'research_fallback_response', 'Le service de recherche de secours a retourné une réponse invalide.', array( 'status' => $code ?: 502 ) ); }
		$rows = isset( $body['results'] ) && is_array( $body['results'] ) ? $body['results'] : array();
		$sources = array(); $facts = array();
		foreach ( array_slice( $rows, 0, 10 ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$row_url = isset( $row['url'] ) ? esc_url_raw( $row['url'] ) : '';
			if ( ! $row_url || ! preg_match( '#^https://#i', $row_url ) ) { continue; }
			$title = sanitize_text_field( isset( $row['title'] ) ? $row['title'] : '' );
			$content = sanitize_textarea_field( isset( $row['content'] ) ? $row['content'] : ( isset( $row['snippet'] ) ? $row['snippet'] : '' ) );
			$sources[] = array( 'url' => $row_url, 'title' => $title );
			if ( $content ) { $facts[] = array( 'source' => $row_url, 'text' => $content ); }
		}
		return array( 'id' => '', 'text' => wp_json_encode( array( 'recipe_facts' => $facts, 'references' => $sources, 'uncertainties' => array( 'Recherche fournie par un service externe configurable ; vérifiez les sources.' ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'sources' => $sources, 'usage' => array(), 'model' => 'custom_json' );
	}

	private static function safe_external_endpoint( $url ) {
		if ( ! $url || ! preg_match( '#^https://#i', $url ) ) { return false; }
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || preg_match( '/(?:^|\.)localhost$|\.local$/i', $host ) ) { return false; }
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : ( function_exists( 'gethostbynamel' ) ? (array) gethostbynamel( $host ) : array() );
		if ( empty( $ips ) ) { return false; }
		foreach ( $ips as $ip ) { if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return false; } }
		return true;
	}

	public static function text( $provider, $model, $input, $max_tokens = 1200, $search = false, $json_output = false ) {
		if ( 'openai' === $provider ) {
			$tools = $search ? array( array( 'type' => 'web_search' ) ) : array();
			return MSRWA_OpenAI::responses_text( $input, $model, $max_tokens, $tools, $search, $json_output );
		}
		if ( 'gemini' === $provider ) { return self::gemini( $model, $input, $max_tokens, $search, $json_output ); }
		if ( 'claude' === $provider ) {
			if ( $search ) { return new WP_Error( 'claude_search_pending', 'La recherche native Claude doit être activée et vérifiée dans le compte.', array( 'status' => 409 ) ); }
			return self::claude( $model, $input, $max_tokens );
		}
		return new WP_Error( 'provider_unknown', 'Fournisseur non pris en charge.', array( 'status' => 400 ) );
	}

	public static function vision_text( $provider, $model, $prompt, $image_path, $max_tokens = 1000 ) {
		if ( ! is_readable( $image_path ) || filesize( $image_path ) > 10 * 1024 * 1024 ) { return new WP_Error( 'vision_input_invalid', 'L’image de vision est absente ou dépasse la limite.', array( 'status' => 400 ) ); }
		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $image_path ) : 'image/jpeg';
		if ( 0 !== strpos( (string) $mime, 'image/' ) ) { return new WP_Error( 'vision_mime_invalid', 'Le fichier de vision n’est pas une image.', array( 'status' => 400 ) ); }
		$binary = file_get_contents( $image_path );
		if ( false === $binary ) { return new WP_Error( 'vision_read_failed', 'Impossible de lire l’image de vision.', array( 'status' => 400 ) ); }
		$encoded = base64_encode( $binary );
		if ( 'gemini' === $provider ) { return self::gemini_vision( $model, $prompt, $mime, $encoded, $max_tokens ); }
		if ( 'claude' === $provider ) { return self::claude_vision( $model, $prompt, $mime, $encoded, $max_tokens ); }
		return MSRWA_OpenAI::vision_text( $prompt, $image_path, $model, $max_tokens );
	}

	private static function gemini( $model, $input, $max_tokens, $search, $json_output ) {
		$settings = MSRWA_Settings::get();
		$key = defined( 'MSRWA_GEMINI_KEY' ) && MSRWA_GEMINI_KEY ? MSRWA_GEMINI_KEY : ( getenv( 'MSRWA_GEMINI_KEY' ) ?: $settings['gemini_key'] );
		if ( ! $key ) { return new WP_Error( 'missing_gemini_key', 'Aucune clé Gemini côté serveur.', array( 'status' => 400 ) ); }
		$payload = array(
			'contents' => array(
				array( 'role' => 'user', 'parts' => array( array( 'text' => sanitize_textarea_field( $input ) ) ) ),
			),
			'generationConfig' => array( 'maxOutputTokens' => max( 16, absint( $max_tokens ) ) ),
		);
		if ( $json_output ) { $payload['generationConfig']['responseMimeType'] = 'application/json'; }
		if ( $search ) { $payload['tools'] = array( array( 'google_search' => new stdClass() ) ); }
		$url = self::gemini_endpoint( $model, $key );
		if ( is_wp_error( $url ) ) { return $url; }
		$response = wp_remote_post( $url, array( 'timeout' => MSRWA_Catalog::timeout( 'gemini', 'timeout_text', 60 ), 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'gemini_network', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'gemini_api_' . $code, isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : 'Réponse Gemini invalide.', array( 'status' => $code ) ); }
		$text = '';
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) { if ( isset( $part['text'] ) ) { $text .= (string) $part['text']; } }
		if ( '' === trim( $text ) ) { return new WP_Error( 'gemini_empty', 'Gemini n’a retourné aucun texte.', array( 'status' => 502 ) ); }
		return array( 'id' => '', 'text' => $text, 'sources' => array(), 'usage' => isset( $body['usageMetadata'] ) ? array( 'input_tokens' => absint( $body['usageMetadata']['promptTokenCount'] ?? 0 ), 'output_tokens' => absint( $body['usageMetadata']['candidatesTokenCount'] ?? 0 ) ) : array(), 'model' => $model );
	}

	private static function gemini_vision( $model, $prompt, $mime, $encoded, $max_tokens ) {
		$settings = MSRWA_Settings::get();
		$key = defined( 'MSRWA_GEMINI_KEY' ) && MSRWA_GEMINI_KEY ? MSRWA_GEMINI_KEY : ( getenv( 'MSRWA_GEMINI_KEY' ) ?: $settings['gemini_key'] );
		if ( ! $key ) { return new WP_Error( 'missing_gemini_key', 'Aucune clé Gemini côté serveur.', array( 'status' => 400 ) ); }
		$payload = array( 'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => sanitize_textarea_field( $prompt ) ), array( 'inlineData' => array( 'mimeType' => $mime, 'data' => $encoded ) ) ) ) ), 'generationConfig' => array( 'maxOutputTokens' => max( 16, absint( $max_tokens ) ), 'responseMimeType' => 'application/json' ) );
		$url = self::gemini_endpoint( $model, $key );
		if ( is_wp_error( $url ) ) { return $url; }
		$response = wp_remote_post( $url, array( 'timeout' => MSRWA_Catalog::timeout( 'gemini', 'timeout_text', 60 ), 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'gemini_vision_network', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'gemini_vision_api_' . $code, isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : 'Réponse Gemini vision invalide.', array( 'status' => $code ) ); }
		$text = '';
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) { if ( isset( $part['text'] ) ) { $text .= (string) $part['text']; } }
		if ( '' === trim( $text ) ) { return new WP_Error( 'gemini_vision_empty', 'Gemini vision n’a retourné aucun texte.', array( 'status' => 502 ) ); }
		return array( 'id' => '', 'text' => $text, 'sources' => array(), 'usage' => isset( $body['usageMetadata'] ) ? array( 'input_tokens' => absint( $body['usageMetadata']['promptTokenCount'] ?? 0 ), 'output_tokens' => absint( $body['usageMetadata']['candidatesTokenCount'] ?? 0 ) ) : array(), 'model' => $model );
	}

	private static function claude( $model, $input, $max_tokens ) {
		$settings = MSRWA_Settings::get();
		$key = defined( 'MSRWA_CLAUDE_KEY' ) && MSRWA_CLAUDE_KEY ? MSRWA_CLAUDE_KEY : ( getenv( 'MSRWA_CLAUDE_KEY' ) ?: $settings['claude_key'] );
		if ( ! $key ) { return new WP_Error( 'missing_claude_key', 'Aucune clé Claude côté serveur.', array( 'status' => 400 ) ); }
		$url = MSRWA_Catalog::endpoint( 'claude', 'messages_path' );
		if ( ! $url ) { return new WP_Error( 'claude_endpoint_missing', 'Endpoint Claude Messages non configuré.', array( 'status' => 500 ) ); }
		$api = MSRWA_Catalog::provider_api( 'claude' );
		$response = wp_remote_post( $url, array(
			'timeout' => MSRWA_Catalog::timeout( 'claude', 'timeout_text', 60 ),
			'sslverify' => true,
			'headers' => array( 'Content-Type' => 'application/json', 'x-api-key' => $key, 'anthropic-version' => sanitize_text_field( $api['api_version'] ?? '2023-06-01' ) ),
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

	private static function claude_vision( $model, $prompt, $mime, $encoded, $max_tokens ) {
		$settings = MSRWA_Settings::get();
		$key = defined( 'MSRWA_CLAUDE_KEY' ) && MSRWA_CLAUDE_KEY ? MSRWA_CLAUDE_KEY : ( getenv( 'MSRWA_CLAUDE_KEY' ) ?: $settings['claude_key'] );
		if ( ! $key ) { return new WP_Error( 'missing_claude_key', 'Aucune clé Claude côté serveur.', array( 'status' => 400 ) ); }
		$payload = array( 'model' => $model, 'max_tokens' => max( 16, absint( $max_tokens ) ), 'messages' => array( array( 'role' => 'user', 'content' => array( array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $mime, 'data' => $encoded ) ), array( 'type' => 'text', 'text' => sanitize_textarea_field( $prompt ) ) ) ) ) );
		$url = MSRWA_Catalog::endpoint( 'claude', 'messages_path' );
		if ( ! $url ) { return new WP_Error( 'claude_endpoint_missing', 'Endpoint Claude Messages non configuré.', array( 'status' => 500 ) ); }
		$api = MSRWA_Catalog::provider_api( 'claude' );
		$response = wp_remote_post( $url, array( 'timeout' => MSRWA_Catalog::timeout( 'claude', 'timeout_text', 60 ), 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json', 'x-api-key' => $key, 'anthropic-version' => sanitize_text_field( $api['api_version'] ?? '2023-06-01' ) ), 'body' => wp_json_encode( $payload ) ) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'claude_vision_network', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'claude_vision_api_' . $code, isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : 'Réponse Claude vision invalide.', array( 'status' => $code ) ); }
		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $part ) { if ( isset( $part['text'] ) ) { $text .= (string) $part['text']; } }
		if ( '' === trim( $text ) ) { return new WP_Error( 'claude_vision_empty', 'Claude vision n’a retourné aucun texte.', array( 'status' => 502 ) ); }
		return array( 'id' => isset( $body['id'] ) ? sanitize_text_field( $body['id'] ) : '', 'text' => $text, 'sources' => array(), 'usage' => isset( $body['usage'] ) ? array( 'input_tokens' => absint( $body['usage']['input_tokens'] ?? 0 ), 'output_tokens' => absint( $body['usage']['output_tokens'] ?? 0 ) ) : array(), 'model' => $model );
	}

	private static function gemini_endpoint( $model, $key ) {
		$url = MSRWA_Catalog::endpoint( 'gemini', 'generate_path', array( 'model' => $model ) );
		if ( ! $url ) { return new WP_Error( 'gemini_endpoint_missing', 'Endpoint Gemini Generate non configuré.', array( 'status' => 500 ) ); }
		return add_query_arg( 'key', $key, $url );
	}
}
