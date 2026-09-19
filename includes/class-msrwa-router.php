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

	private static function result( $model, $mode, $reason ) {
		return array( 'mode' => $mode, 'provider' => $model['provider'], 'model' => $model['id'], 'reason' => $reason, 'pricing' => array( 'input_per_million' => (float) $model['input'], 'output_per_million' => (float) $model['output'], 'source' => $model['source'] ) );
	}
}
