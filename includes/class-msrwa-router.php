<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Router {
	public static function connected( $provider ) {
		if ( 'openai' === $provider ) { return class_exists( 'MSRWA_OpenAI' ) ? '' !== trim( MSRWA_OpenAI::key() ) : ( ( defined( 'MSRWA_OPENAI_KEY' ) && MSRWA_OPENAI_KEY ) || (bool) getenv( 'MSRWA_OPENAI_KEY' ) ); }
		$s = MSRWA_Settings::get();
		$constant = 'MSRWA_' . strtoupper( $provider ) . '_KEY';
		return ( defined( $constant ) && constant( $constant ) ) || ( getenv( $constant ) ?: ! empty( $s[ $provider . '_key' ] ) );
	}

	public static function plan( $capability = 'text', $stage = '' ) {
		$s = MSRWA_Settings::get();
		if ( '' === $stage ) {
			$stage = 'image_generation' === $capability ? 'image' : ( 'web_search' === $capability ? 'search' : ( 'vision' === $capability ? 'review' : 'text' ) );
		}
		$eligible = MSRWA_Catalog::eligible( $capability );
		$connected = array_filter( $eligible, function ( $model ) { return self::connected( $model['provider'] ); } );
		if ( empty( $connected ) ) { return new WP_Error( 'no_provider_available', 'Aucun fournisseur connecté possède un modèle compatible.', array( 'status' => 400 ) ); }
		if ( 'manual' === $s['mode'] ) {
			$manual_id = isset( $s['manual_models'][ $stage ] ) ? $s['manual_models'][ $stage ] : '';
			foreach ( $connected as $candidate ) {
				if ( $candidate['provider'] . ':' . $candidate['id'] === $manual_id ) { return self::result( $candidate, 'manual', 'Modèle imposé par les réglages administrateur pour l’étape ' . $stage . '.' ); }
			}
			return new WP_Error( 'manual_model_unavailable', 'Le modèle manuel est absent, non connecté ou incompatible.', array( 'status' => 400 ) );
		}
		// A deterministic pre-filter keeps the future agent inside the admin-approved capabilities.
		usort( $connected, function ( $a, $b ) {
			$a_cost = (float) $a['input'] + (float) $a['output'];
			$b_cost = (float) $b['input'] + (float) $b['output'];
			if ( $a_cost === $b_cost ) { return strcmp( $a['id'], $b['id'] ); }
			return $a_cost <=> $b_cost;
		} );
		$selected = reset( $connected );
		return self::result( $selected, 'automatic', 'Préfiltre qualité/coût : candidat stable compatible et connecté ; sélection agentique ultérieure bornée à ce catalogue.' );
	}

	public static function agent_plan( $job ) {
		$settings = MSRWA_Settings::get();
		$selected = json_decode( (string) $job->selected_models_json, true );
		$selected = is_array( $selected ) ? $selected : array();
		if ( 'manual' === $settings['mode'] ) { return $selected; }
		$candidates = array();
		foreach ( array( 'text' => 'text', 'review' => 'text', 'search' => 'web_search', 'vision' => 'vision', 'image' => 'image_generation' ) as $stage => $capability ) {
			$candidates[ $stage ] = array();
			foreach ( MSRWA_Catalog::eligible( $capability ) as $key => $model ) {
				if ( self::connected( $model['provider'] ) ) { $candidates[ $stage ][ $key ] = array( 'label' => $model['label'], 'input_per_million' => (float) $model['input'], 'output_per_million' => (float) $model['output'], 'source' => $model['source'] ?? '', 'verified_at' => $model['verified_at'] ?? '' ); }
			}
		}
		$router = isset( $selected['text'] ) && is_array( $selected['text'] ) ? $selected['text'] : self::plan( 'text', 'text' );
		if ( is_wp_error( $router ) || empty( $router['provider'] ) || empty( $router['model'] ) ) { return new WP_Error( 'router_model_unavailable', 'Aucun modèle texte connecté ne peut effectuer le routage.', array( 'status' => 409 ) ); }
		$catalog = MSRWA_Catalog::models();
		$max_tokens = absint( $settings['router_max_output_tokens'] );
		$prompt = $settings['prompt_router'] . "\nRéponds uniquement avec un JSON compact contenant les clés text, review, search, vision, image et reason. Chaque valeur doit être exactement une clé fournisseur:modèle présente dans les candidats correspondants. reason doit être une phrase courte sans raisonnement privé. Budget recette complète, deux images comprises (USD) : " . (float) $settings['per_recipe_budget_usd'] . '. CANDIDATS: ' . wp_json_encode( $candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$prompt .= '\nCONTEXTE : ' . wp_json_encode( array( 'recipe' => $job->title, 'article_words' => array( $settings['quality_min_words'], $settings['quality_max_words'] ), 'article_max_output_tokens' => $settings['article_max_output_tokens'], 'review_max_output_tokens' => $settings['review_max_output_tokens'], 'image_estimates_usd' => array( $settings['featured_image_estimate_usd'], $settings['facebook_image_estimate_usd'] ), 'search_tool_cost_usd' => $settings['web_search_tool_cost_usd'] ), JSON_UNESCAPED_UNICODE );
		$estimate = isset( $catalog[ $router['provider'] ][ $router['model'] ] ) ? ( strlen( $prompt ) * (float) $catalog[ $router['provider'] ][ $router['model'] ]['input'] + $max_tokens * (float) $catalog[ $router['provider'] ][ $router['model'] ]['output'] ) / 1000000 + (float) $settings['text_reserve_margin_usd'] : 0.01;
		$reservation = MSRWA_DB::reserve( $job, $estimate, 'model_routing' );
		if ( is_wp_error( $reservation ) ) { return $reservation; }
		$started = current_time( 'mysql', true );
		$result = MSRWA_Providers::text( $router['provider'], $router['model'], $prompt, $max_tokens, false, true );
		$input_tokens = is_array( $result ) && ! empty( $result['usage']['input_tokens'] ) ? absint( $result['usage']['input_tokens'] ) : 0;
		$output_tokens = is_array( $result ) && ! empty( $result['usage']['output_tokens'] ) ? absint( $result['usage']['output_tokens'] ) : 0;
		$actual = isset( $catalog[ $router['provider'] ][ $router['model'] ] ) ? ( $input_tokens * (float) $catalog[ $router['provider'] ][ $router['model'] ]['input'] + $output_tokens * (float) $catalog[ $router['provider'] ][ $router['model'] ]['output'] ) / 1000000 : 0;
		MSRWA_DB::call( array( 'batch_id' => absint( $job->batch_id ), 'job_id' => absint( $job->id ), 'provider' => $router['provider'], 'model' => $router['model'], 'operation' => 'model_routing', 'status' => is_wp_error( $result ) ? 'failed' : 'completed', 'request_id' => is_array( $result ) && ! empty( $result['id'] ) ? $result['id'] : '', 'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens, 'cost_estimate' => $actual, 'uncertain' => 0, 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : '', 'request_json' => array( 'prompt' => $prompt, 'max_output_tokens' => $max_tokens, 'candidates' => $candidates ), 'response_json' => MSRWA_DB::diagnostic_payload( $result ), 'payload_hash' => hash( 'sha256', $prompt ), 'started_at' => $started, 'finished_at' => current_time( 'mysql', true ) ) );
		if ( is_wp_error( $result ) ) { MSRWA_DB::release( $reservation ); return $result; }
		MSRWA_DB::settle( $reservation, $actual );
		$decoded = json_decode( trim( (string) $result['text'] ), true );
		if ( ! is_array( $decoded ) ) { $start = strpos( $result['text'], '{' ); $end = strrpos( $result['text'], '}' ); if ( false !== $start && false !== $end ) { $decoded = json_decode( substr( $result['text'], $start, $end - $start + 1 ), true ); } }
		if ( ! is_array( $decoded ) ) { return new WP_Error( 'router_invalid_json', 'Le routeur automatique n’a pas retourné un JSON valide.', array( 'status' => 502 ) ); }
		$plan = array();
		$eligible_by_stage = array();
		foreach ( array( 'text' => 'text', 'review' => 'text', 'search' => 'web_search', 'vision' => 'vision', 'image' => 'image_generation' ) as $stage => $capability ) {
			$eligible_by_stage[ $stage ] = MSRWA_Catalog::eligible( $capability );
			$key = isset( $decoded[ $stage ] ) ? sanitize_text_field( $decoded[ $stage ] ) : '';
			if ( $key && isset( $candidates[ $stage ][ $key ] ) ) {
				list( $provider, $model ) = array_pad( explode( ':', $key, 2 ), 2, '' );
				$plan[ $stage ] = self::result( $eligible_by_stage[ $stage ][ $key ], 'automatic_agent', 'Choisi par le routeur automatique parmi les candidats vérifiés.' );
			}
		}
		foreach ( $candidates as $stage => $available ) {
			if ( $available && empty( $plan[ $stage ] ) ) { return new WP_Error( 'router_incomplete_plan', 'Le routeur n’a pas sélectionné de modèle autorisé pour : ' . $stage . '.', array( 'status' => 502 ) ); }
		}
		$plan['reason'] = isset( $decoded['reason'] ) ? sanitize_text_field( $decoded['reason'] ) : 'Sélection automatique vérifiée par le catalogue.';
		return $plan;
	}

	private static function result( $model, $mode, $reason ) {
		return array( 'mode' => $mode, 'provider' => $model['provider'], 'model' => $model['id'], 'reason' => $reason, 'pricing' => array( 'input_per_million' => (float) $model['input'], 'output_per_million' => (float) $model['output'], 'source' => $model['source'] ) );
	}
}
