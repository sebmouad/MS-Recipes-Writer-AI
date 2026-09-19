<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_OpenAI {
	public static function key() {
		if ( defined( 'MSRWA_OPENAI_KEY' ) && MSRWA_OPENAI_KEY ) { return (string) MSRWA_OPENAI_KEY; }
		$environment = getenv( 'MSRWA_OPENAI_KEY' );
		if ( $environment ) { return (string) $environment; }
		$settings = MSRWA_Settings::get();
		return ! empty( $settings['openai_key'] ) ? (string) $settings['openai_key'] : '';
	}

	public static function responses_text( $input, $model = '', $max_output_tokens = 512, $tools = array(), $required_tool = false ) {
		$key = self::key();
		if ( '' === $key ) { return new WP_Error( 'missing_openai_key', 'Aucune clé OpenAI côté serveur.', array( 'status' => 400 ) ); }
		$settings = MSRWA_Settings::get();
		$manual_text = isset( $settings['manual_models']['text'] ) ? $settings['manual_models']['text'] : '';
		$model = $model ? sanitize_text_field( $model ) : ( 0 === strpos( $manual_text, 'openai:' ) ? substr( $manual_text, 7 ) : $settings['openai_model'] );
		$catalog = MSRWA_Catalog::models();
		if ( empty( $catalog['openai'][ $model ]['stable'] ) ) { return new WP_Error( 'unsupported_openai_model', 'Modèle OpenAI non autorisé par le catalogue.', array( 'status' => 400 ) ); }
		$payload = array( 'model' => $model, 'input' => sanitize_textarea_field( $input ), 'store' => false, 'max_output_tokens' => max( 16, absint( $max_output_tokens ) ) );
		if ( $tools ) {
			$payload['tools'] = array_values( $tools );
			if ( $required_tool ) { $payload['tool_choice'] = 'required'; }
			$payload['include'] = array( 'web_search_call.action.sources' );
		}
		$response = wp_remote_post( 'https://api.openai.com/v1/responses', array(
			'timeout' => 45,
			'sslverify' => true,
			'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'openai_network', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : 'Réponse OpenAI invalide.';
			return new WP_Error( 'openai_api_' . $code, $message, array( 'status' => $code >= 400 && $code < 600 ? $code : 502 ) );
		}
		$text = self::extract_text( $body );
		return array( 'id' => isset( $body['id'] ) ? sanitize_text_field( $body['id'] ) : '', 'text' => $text, 'sources' => self::extract_sources( $body ), 'usage' => isset( $body['usage'] ) && is_array( $body['usage'] ) ? $body['usage'] : array(), 'model' => $model );
	}

	public static function extract_text( $body ) {
		if ( isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) { return $body['output_text']; }
		$text = array();
		foreach ( isset( $body['output'] ) && is_array( $body['output'] ) ? $body['output'] : array() as $item ) {
			foreach ( isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array() as $content ) {
				if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $text[] = $content['text']; }
			}
		}
		return implode( "\n", $text );
	}

	public static function connection_test() {
		$result = self::responses_text( 'Return exactly READY.', '', 16 );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( 'READY' !== trim( $result['text'] ) ) { return new WP_Error( 'openai_unexpected_output', 'OpenAI a répondu, mais la sortie de test est inattendue.', array( 'status' => 502 ) ); }
		return array( 'ok' => true, 'model' => $result['model'], 'request_id' => $result['id'], 'usage' => $result['usage'] );
	}

	public static function images_generate( $prompt, $model = 'gpt-image-2.5-flare', $size = '1024x1024', $quality = 'low', $format = 'webp' ) {
		$key = self::key();
		if ( '' === $key ) { return new WP_Error( 'missing_openai_key', 'Aucune clé OpenAI côté serveur.', array( 'status' => 400 ) ); }
		$catalog = MSRWA_Catalog::models();
		if ( empty( $catalog['openai'][ $model ]['stable'] ) || empty( $catalog['openai'][ $model ]['image_generation'] ) ) { return new WP_Error( 'unsupported_openai_image_model', 'Modèle image OpenAI non autorisé.', array( 'status' => 400 ) ); }
		$allowed_sizes = array( '1024x1024', '1536x1024', '1024x1536' );
		$allowed_quality = array( 'low', 'medium', 'high', 'xhigh', 'max', 'auto' );
		$allowed_formats = array( 'png', 'jpeg', 'webp' );
		$payload = array( 'model' => $model, 'prompt' => sanitize_textarea_field( $prompt ), 'size' => in_array( $size, $allowed_sizes, true ) ? $size : '1024x1024', 'quality' => in_array( $quality, $allowed_quality, true ) ? $quality : 'low', 'output_format' => in_array( $format, $allowed_formats, true ) ? $format : 'webp', 'n' => 1 );
		$response = wp_remote_post( 'https://api.openai.com/v1/images/generations', array( 'timeout' => 120, 'sslverify' => true, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'openai_image_network', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'openai_image_api_' . $code, isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : 'Réponse image OpenAI invalide.', array( 'status' => $code >= 400 && $code < 600 ? $code : 502 ) ); }
		$b64 = isset( $body['data'][0]['b64_json'] ) ? (string) $body['data'][0]['b64_json'] : '';
		if ( '' === $b64 ) { return new WP_Error( 'openai_image_empty', 'OpenAI n’a retourné aucune image.', array( 'status' => 502 ) ); }
		return array( 'model' => $model, 'format' => $payload['output_format'], 'base64' => $b64, 'created' => isset( $body['created'] ) ? absint( $body['created'] ) : 0 );
	}

	private static function extract_sources( $body ) {
		$sources = array();
		foreach ( isset( $body['output'] ) && is_array( $body['output'] ) ? $body['output'] : array() as $item ) {
			if ( isset( $item['action']['sources'] ) && is_array( $item['action']['sources'] ) ) {
				foreach ( $item['action']['sources'] as $source ) {
					if ( isset( $source['url'] ) && preg_match( '#^https?://#i', $source['url'] ) ) { $sources[] = array( 'url' => esc_url_raw( $source['url'] ), 'title' => isset( $source['title'] ) ? sanitize_text_field( $source['title'] ) : '' ); }
				}
			}
			foreach ( isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array() as $content ) {
				foreach ( isset( $content['annotations'] ) && is_array( $content['annotations'] ) ? $content['annotations'] : array() as $annotation ) {
					if ( isset( $annotation['url'] ) && preg_match( '#^https?://#i', $annotation['url'] ) ) { $sources[] = array( 'url' => esc_url_raw( $annotation['url'] ), 'title' => isset( $annotation['title'] ) ? sanitize_text_field( $annotation['title'] ) : '' ); }
				}
			}
		}
		$unique = array();
		foreach ( $sources as $source ) { $unique[ $source['url'] ] = $source; }
		return array_values( $unique );
	}
}
