<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Whether each stored key actually opens its provider.
 *
 * "Key stored" says a string was saved, not that it works. The first time an
 * operator learned a key was wrong used to be a failed lot. Listing a
 * provider's models is free on all three, needs the key and nothing else, and
 * answers the one question: would a real call get through?
 */
final class MSRWA_Keys {

	/** Where each provider lists its models, and how it wants the key. */
	public static function probes() {
		return array(
			'openai' => array( 'label' => 'OpenAI', 'url' => 'https://api.openai.com/v1/models', 'headers' => static function ( $key ) { return array( 'Authorization' => 'Bearer ' . $key ); } ),
			'gemini' => array( 'label' => 'Gemini', 'url' => 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1', 'headers' => static function ( $key ) { return array( 'x-goog-api-key' => $key ); } ),
			'claude' => array( 'label' => 'Claude', 'url' => 'https://api.anthropic.com/v1/models?limit=1', 'headers' => static function ( $key ) { return array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ); } ),
		);
	}

	/** One verdict per provider: missing, ok, refused or unreachable. Never the key. */
	public static function check() {
		$keys = MSRWA_Settings::engine_keys();
		$out = array();
		foreach ( self::probes() as $provider => $probe ) {
			if ( empty( $keys[ $provider ] ) ) {
				$out[ $provider ] = array( 'label' => $probe['label'], 'state' => 'missing', 'message' => __( 'aucune clé enregistrée', 'ms-recipes-writer-ai' ) );
				continue;
			}
			$response = wp_remote_get( $probe['url'], array( 'timeout' => 15, 'headers' => call_user_func( $probe['headers'], $keys[ $provider ] ) ) );
			$out[ $provider ] = array( 'label' => $probe['label'] ) + self::verdict( $response );
		}
		return $out;
	}

	/** Pure, so the reading of a response can be tested without a network. */
	public static function verdict( $response ) {
		if ( is_wp_error( $response ) ) {
			return array( 'state' => 'unreachable', 'message' => __( 'injoignable depuis ce serveur : le pare-feu de l’hébergeur bloque peut-être la sortie', 'ms-recipes-writer-ai' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) { return array( 'state' => 'ok', 'message' => __( 'clé acceptée', 'ms-recipes-writer-ai' ) ); }
		if ( in_array( $status, array( 400, 401, 403 ), true ) ) {
			return array( 'state' => 'refused', 'message' => __( 'clé refusée par le fournisseur : vérifiez-la ou remplacez-la', 'ms-recipes-writer-ai' ) );
		}
		if ( 429 === $status ) { return array( 'state' => 'ok', 'message' => __( 'clé acceptée, mais le quota est atteint pour l’instant', 'ms-recipes-writer-ai' ) ); }
		/* translators: %d is an HTTP status code. */
		return array( 'state' => 'unreachable', 'message' => sprintf( __( 'le fournisseur répond %d : réessayez plus tard', 'ms-recipes-writer-ai' ), $status ) );
	}
}
