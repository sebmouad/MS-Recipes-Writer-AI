<?php
/**
 * Published rates, in USD per million tokens, read from each provider's own
 * pricing page on 2026-09-20. Kept here rather than in the plugin catalogue so
 * the lab can price models the plugin does not ship.
 *
 * Sources:
 *  - https://developers.openai.com/api/docs/pricing
 *  - https://ai.google.dev/gemini-api/docs/pricing
 *  - Anthropic model table, claude-api skill (cached 2026-06-24)
 */
function lab_rates() {
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
function lab_tiers() {
	return array(
		'low'    => array( 'openai' => 'gpt-5-nano', 'gemini' => 'gemini-3.1-flash-lite', 'claude' => 'claude-haiku-4-5' ),
		'medium' => array( 'openai' => 'gpt-5.6-luna', 'gemini' => 'gemini-3.5-flash', 'claude' => 'claude-sonnet-5' ),
		'high'   => array( 'openai' => 'gpt-5.6-sol', 'gemini' => 'gemini-3.1-pro-preview', 'claude' => 'claude-opus-5' ),
	);
}

/** The model a provider and tier resolve to. */
function lab_model( $provider, $tier ) {
	$tiers = lab_tiers();
	if ( ! isset( $tiers[ $tier ][ $provider ] ) ) { fwrite( STDERR, "Unknown provider/tier: {$provider}/{$tier}\n" ); exit( 2 ); }
	return $tiers[ $tier ][ $provider ];
}

function lab_price( $provider, $model, $usage ) {
	$rates = lab_rates();
	if ( ! isset( $rates[ $provider ][ $model ] ) ) { return null; }
	list( $in, $out ) = $rates[ $provider ][ $model ];
	return ( (int) ( $usage['input_tokens'] ?? 0 ) * $in + (int) ( $usage['output_tokens'] ?? 0 ) * $out ) / 1000000;
}
