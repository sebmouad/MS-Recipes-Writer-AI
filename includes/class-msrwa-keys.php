<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Whether each stored key actually opens its provider.
 *
 * "Key stored" says a string was saved, not that it works. The first time an
 * operator learned a key was wrong used to be a failed lot. Listing a
 * provider's models is free on all three, needs the key and nothing else, and
 * answers most of the question: is this key real, and can this server reach
 * the provider at all?
 *
 * It does not answer the rest of it. A key belonging to an account with no
 * credit lists models perfectly happily, so the probe alone reports a green
 * "key accepted" for an account that cannot pay for a single word — and the
 * operator finds out when a lot dies at its first step. The provider says so
 * plainly when a real call is made, and every real call this site has made is
 * already written down, so the last refusal is read back and reported beside
 * the probe.
 */
final class MSRWA_Keys {

	/** Where each provider lists its models, and how it wants the key. */
	public static function probes() {
		return array(
			'openai' => array( 'label' => 'OpenAI', 'url' => 'https://api.openai.com/v1/models', 'headers' => static function ( $key ) { return array( 'Authorization' => 'Bearer ' . $key ); } ),
			'gemini' => array( 'label' => 'Gemini', 'url' => 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200', 'headers' => static function ( $key ) { return array( 'x-goog-api-key' => $key ); } ),
			'claude' => array( 'label' => 'Claude', 'url' => 'https://api.anthropic.com/v1/models?limit=1000', 'headers' => static function ( $key ) { return array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ); } ),
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
			$response = wp_remote_get( $probe['url'], array( 'timeout' => 20, 'headers' => call_user_func( $probe['headers'], $keys[ $provider ] ) ) );
			$verdict = self::temper( self::verdict( $response ), self::last_refusal( $provider ) );

			// The list was always downloaded and always thrown away: only the
			// status code was read. It is the one thing the provider knows and
			// this plugin cannot — which model identifiers still answer — so it
			// is kept, and a route to a name the provider no longer serves can
			// be caught before a lot pays its way to that step.
			$listed = self::models( $provider, wp_remote_retrieve_body( $response ) );
			if ( $listed && class_exists( 'MSRWA_Catalog' ) ) {
				MSRWA_Catalog::remember_listing( $provider, $listed );
				$verdict['models'] = count( $listed );
			}

			$out[ $provider ] = array( 'label' => $probe['label'] ) + $verdict;
		}
		return $out;
	}

	/**
	 * The probe's verdict, corrected by what the provider did on a real call.
	 *
	 * Only a green verdict is corrected: a key the provider refuses outright is
	 * already the more urgent news, and a server that cannot reach the provider
	 * has nothing to say about its billing.
	 */
	public static function temper( array $verdict, $refusal ) {
		if ( 'ok' !== ( $verdict['state'] ?? '' ) || '' === (string) $refusal ) { return $verdict; }
		return 'credit' === $refusal
			? array( 'state' => 'blocked', 'message' => __( 'clé valide, mais le compte du fournisseur n’a plus de crédit : le dernier appel réel a été refusé. Recharger le compte chez le fournisseur.', 'ms-recipes-writer-ai' ) )
			: array( 'state' => 'blocked', 'message' => __( 'clé valide, mais le quota du compte est épuisé : le dernier appel réel a été refusé. Attendre qu’il se réinitialise ou relever la limite chez le fournisseur.', 'ms-recipes-writer-ai' ) );
	}

	/**
	 * Why this provider last refused a real call, if it did: credit or quota.
	 *
	 * Bounded to the recent past, because an account topped up last month must
	 * not be reported as empty for ever.
	 */
	public static function last_refusal( $provider, $days = 7 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		$errors = $wpdb->get_col( $wpdb->prepare(
			'SELECT error_message FROM ' . $t['steps'] . ' WHERE provider = %s AND error_message IS NOT NULL AND error_message <> %s AND created_at >= %s ORDER BY id DESC LIMIT 1',
			sanitize_key( (string) $provider ),
			'',
			$since
		) );
		return $errors ? self::refusal( (string) $errors[0] ) : '';
	}

	/**
	 * What a provider's error says about the account behind the key.
	 *
	 * Shared with the sentence an editor reads on a failed recipe, so the two
	 * never disagree about whether an account is out of credit or merely
	 * throttled — the difference decides whether waiting helps.
	 */
	public static function refusal( $error ) {
		$error = (string) $error;
		// Only the unmistakable wordings count for credit. Google's rate limit
		// carries the words "billing" and "quota" while the account is fully
		// funded, and matching either sent an administrator to top up a wallet
		// that was already full.
		if ( preg_match( '/credit balance|insufficient_quota|billing_not_active|account is not active/i', $error ) ) { return 'credit'; }
		if ( preg_match( '/HTTP 429|RESOURCE_EXHAUSTED|rate.?limit|quota/i', $error ) ) { return 'quota'; }
		return '';
	}

	/** Just the identifiers, for anything that does not care about the rest. */
	public static function model_ids( $provider, $body ) {
		return array_values( array_unique( array_column( self::models( $provider, $body ), 'id' ) ) );
	}

	/**
	 * Every model in one provider's listing, with whatever it says about them.
	 *
	 * Three providers, three shapes, and what each volunteers differs wildly:
	 * Anthropic states its capabilities outright, Gemini gives token limits and
	 * the methods a model supports, OpenAI gives an identifier and little else.
	 * None of them gives a price — checked against the live responses of all
	 * three — which is why a rate is never fetched here.
	 *
	 * Google prefixes every name with `models/`, which is not the identifier
	 * the engine routes to. Getting that wrong marks every Gemini model
	 * unserved.
	 *
	 * Pure, so each shape is held by a test rather than by a live call nobody
	 * can make from a machine with no key.
	 */
	public static function models( $provider, $body ) {
		$data = json_decode( (string) $body, true );
		if ( ! is_array( $data ) ) { return array(); }
		$rows = 'gemini' === $provider ? (array) ( $data['models'] ?? array() ) : (array) ( $data['data'] ?? array() );

		$out = array();
		$seen = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$id = trim( (string) ( $row['id'] ?? $row['name'] ?? '' ) );
			if ( 'gemini' === $provider && 0 === strpos( $id, 'models/' ) ) { $id = substr( $id, 7 ); }
			if ( '' === $id || isset( $seen[ $id ] ) ) { continue; }
			$seen[ $id ] = true;

			$model = array( 'id' => $id, 'label' => trim( (string) ( $row['display_name'] ?? $row['displayName'] ?? '' ) ) );

			// Only what the provider actually stated. A capability left out
			// here is left alone in the catalogue rather than set to false:
			// silence is not a denial, and overwriting a correct flag with a
			// guess would take a working model out of service.
			$capabilities = array();
			$limits = array();
			if ( 'gemini' === $provider ) {
				$methods = (array) ( $row['supportedGenerationMethods'] ?? array() );
				if ( $methods ) { $capabilities['text'] = in_array( 'generateContent', $methods, true ); }
				if ( isset( $row['inputTokenLimit'] ) ) { $limits['input_tokens'] = (int) $row['inputTokenLimit']; }
				if ( isset( $row['outputTokenLimit'] ) ) { $limits['output_tokens'] = (int) $row['outputTokenLimit']; }
			} elseif ( 'claude' === $provider ) {
				$stated = (array) ( $row['capabilities'] ?? array() );
				if ( isset( $stated['image_input']['supported'] ) ) { $capabilities['vision'] = (bool) $stated['image_input']['supported']; }
				if ( $stated ) { $capabilities['text'] = true; }
				if ( isset( $row['max_input_tokens'] ) ) { $limits['input_tokens'] = (int) $row['max_input_tokens']; }
				if ( isset( $row['max_tokens'] ) ) { $limits['output_tokens'] = (int) $row['max_tokens']; }
			}
			if ( $capabilities ) { $model['capabilities'] = $capabilities; }
			if ( $limits ) { $model['limits'] = $limits; }
			$out[] = $model;
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
