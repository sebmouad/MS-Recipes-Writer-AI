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

	public static function responses_text( $input, $model = '', $max_output_tokens = 512 ) {
		$key = self::key();
		if ( '' === $key ) { return new WP_Error( 'missing_openai_key', 'Aucune clé OpenAI côté serveur.', array( 'status' => 400 ) ); }
		$settings = MSRWA_Settings::get();
		$model = $model ? sanitize_text_field( $model ) : $settings['openai_model'];
		$catalog = MSRWA_Catalog::models();
		if ( empty( $catalog['openai'][ $model ]['stable'] ) ) { return new WP_Error( 'unsupported_openai_model', 'Modèle OpenAI non autorisé par le catalogue.', array( 'status' => 400 ) ); }
		$payload = array( 'model' => $model, 'input' => sanitize_textarea_field( $input ), 'store' => false, 'max_output_tokens' => max( 16, absint( $max_output_tokens ) ) );
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
		return array( 'id' => isset( $body['id'] ) ? sanitize_text_field( $body['id'] ) : '', 'text' => $text, 'usage' => isset( $body['usage'] ) && is_array( $body['usage'] ) ? $body['usage'] : array(), 'model' => $model );
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
}
