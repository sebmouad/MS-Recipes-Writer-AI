<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Settings {
	const OPTION = 'msrwa_settings';

	public static function defaults() {
		return array(
			'mode'                => 'automatic',
			'openai_key'          => '',
			'gemini_key'          => '',
			'claude_key'          => '',
			'openai_model'        => 'gpt-5.6-luna',
			'gemini_model'        => 'gemini-3.1-flash-image',
			'claude_model'        => 'claude-sonnet-5',
			'manual_models'       => array(
				'text'   => 'openai:gpt-5.6-luna',
				'review' => 'claude:claude-sonnet-5',
				'image'  => 'gemini:gemini-3.1-flash-image',
				'search' => 'openai:gpt-5.6-luna',
			),
			'research_provider'   => 'native',
			'max_batch'           => 50,
			'max_concurrency'     => 4,
			'max_corrections'     => 2,
			'log_days'            => 30,
			'temp_days'           => 7,
			'aggregate_months'    => 12,
			'featured_ratio'      => '1:1',
			'facebook_ratio'      => '4:5',
			'facebook_text'       => 0,
			'prompt_recipe'       => 'Rédige une recette fiable en français à partir de la recette canonique suivante. Retourne uniquement le JSON demandé.',
			'prompt_review'       => 'Contrôle la cohérence culinaire, la langue, les métadonnées et l’image. Retourne des findings structurés.',
			'prompt_image'        => 'Photographie culinaire réaliste, sans texte dans l’image, plat cohérent avec la recette canonique.',
		);
	}

	public static function get() {
		$value = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$defaults = self::defaults();
		$out = $defaults;
		$out['mode'] = in_array( isset( $raw['mode'] ) ? $raw['mode'] : '', array( 'automatic', 'manual' ), true ) ? $raw['mode'] : $defaults['mode'];
		foreach ( array( 'openai_key', 'gemini_key', 'claude_key' ) as $key ) {
			if ( isset( $raw[ $key ] ) && '' !== trim( $raw[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( $raw[ $key ] );
			} else {
				$out[ $key ] = self::get()[ $key ];
			}
		}
		foreach ( array( 'openai_model', 'gemini_model', 'claude_model', 'research_provider', 'featured_ratio', 'facebook_ratio' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) { $out[ $key ] = sanitize_text_field( $raw[ $key ] ); }
		}
		$catalog = MSRWA_Catalog::models();
		if ( isset( $raw['manual_models'] ) && is_array( $raw['manual_models'] ) ) {
			foreach ( array( 'text', 'review', 'image', 'search' ) as $stage ) {
				$value = isset( $raw['manual_models'][ $stage ] ) ? sanitize_text_field( $raw['manual_models'][ $stage ] ) : $defaults['manual_models'][ $stage ];
				list( $provider, $model ) = array_pad( explode( ':', $value, 2 ), 2, '' );
				if ( isset( $catalog[ $provider ][ $model ] ) && ! empty( $catalog[ $provider ][ $model ]['stable'] ) ) { $out['manual_models'][ $stage ] = $provider . ':' . $model; }
			}
		}
		foreach ( array( 'max_batch' => array( 1, 50 ), 'max_concurrency' => array( 1, 4 ), 'max_corrections' => array( 0, 2 ), 'log_days' => array( 1, 365 ), 'temp_days' => array( 1, 90 ), 'aggregate_months' => array( 1, 60 ) ) as $key => $limits ) {
			$value = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : $defaults[ $key ];
			$out[ $key ] = min( $limits[1], max( $limits[0], $value ) );
		}
		$out['facebook_text'] = empty( $raw['facebook_text'] ) ? 0 : 1;
		foreach ( array( 'prompt_recipe', 'prompt_review', 'prompt_image' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) { $out[ $key ] = sanitize_textarea_field( $raw[ $key ] ); }
		}
		return $out;
	}
}
