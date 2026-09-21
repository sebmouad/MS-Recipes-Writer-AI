<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Published rates, in USD per million tokens, read from each provider's own
 * pricing page on 2026-09-20. They live beside the engine rather than in the
 * plugin catalogue so a model can be priced before the plugin ships it.
 *
 * Sources:
 *  - https://developers.openai.com/api/docs/pricing
 *  - https://ai.google.dev/gemini-api/docs/pricing
 *  - Anthropic model table, claude-api skill (cached 2026-06-24)
 */
final class MSRWA_Engine_Rates {

	public static function all() {
		return array(
			'openai' => array(
				'gpt-5-nano' => array( 0.05, 0.40 ),
				'gpt-5.6-luna' => array( 0.20, 1.20 ),
				'gpt-5.6-sol' => array( 4.00, 20.00 ),
				'gpt-5.6-terra' => array( 2.00, 12.00 ),
				'gpt-5.4-mini' => array( 0.75, 4.50 ),
				'gpt-image-1-mini' => array( 2.00, 8.00 ),
				'gpt-image-2' => array( 5.00, 30.00 ),
				'gpt-image-2.5-flare' => array( 5.00, 30.00 ),
			),
			'gemini' => array(
				'gemini-3.1-flash-lite' => array( 0.25, 1.50 ),
				'gemini-2.5-flash-lite' => array( 0.10, 0.40 ),
				'gemini-2.5-flash' => array( 0.30, 2.50 ),
				'gemini-3.1-pro-preview' => array( 2.00, 12.00 ),
				'gemini-3.5-flash' => array( 1.50, 9.00 ),
				'gemini-2.5-flash-image' => array( 0.30, 2.50 ),
				'gemini-3.1-flash-image' => array( 0.50, 3.00 ),
				'gemini-3-pro-image' => array( 2.00, 12.00 ),
			),
			'claude' => array(
				'claude-haiku-4-5' => array( 1.00, 5.00 ),
				'claude-sonnet-5' => array( 2.00, 10.00 ),
				'claude-opus-5' => array( 5.00, 25.00 ),
			),
		);
	}

	/** The three tiers under test, per provider. */
	public static function tiers() {
		return array(
			'low'    => array( 'openai' => 'gpt-5-nano', 'gemini' => 'gemini-3.1-flash-lite', 'claude' => 'claude-haiku-4-5' ),
			'medium' => array( 'openai' => 'gpt-5.6-luna', 'gemini' => 'gemini-3.5-flash', 'claude' => 'claude-sonnet-5' ),
			'high'   => array( 'openai' => 'gpt-5.6-sol', 'gemini' => 'gemini-3.1-pro-preview', 'claude' => 'claude-opus-5' ),
		);
	}

	/**
	 * The model a provider and tier resolve to, or an empty string. The engine
	 * never kills its caller over a bad routing value: the step that asked for
	 * the model reports the failure as data instead.
	 */
	public static function model( $provider, $tier ) {
		$tiers = self::tiers();
		return isset( $tiers[ $tier ][ $provider ] ) ? $tiers[ $tier ][ $provider ] : '';
	}

	/** Price of one call in USD, or null when the model carries no published rate. */
	public static function price( $provider, $model, $usage ) {
		$rates = self::all();
		if ( ! isset( $rates[ $provider ][ $model ] ) ) { return null; }
		list( $in, $out ) = $rates[ $provider ][ $model ];
		return ( (int) ( $usage['input_tokens'] ?? 0 ) * $in + (int) ( $usage['output_tokens'] ?? 0 ) * $out ) / 1000000;
	}
}
