<?php
/** Prices and tiers live in the engine's configuration, so the lab and the plugin agree. */
function lab_price( $provider, $model, $usage ) { return MSRWA_Engine_Config::create()->price( $provider, $model, $usage ); }
function lab_model( $provider, $tier ) {
	$model = MSRWA_Engine_Config::create()->model_for( '__none__' );
	$tiers = MSRWA_Engine_Config::defaults()['tiers'];
	if ( ! isset( $tiers[ $tier ][ $provider ] ) ) { fwrite( STDERR, "Unknown provider/tier: {$provider}/{$tier}\n" ); exit( 2 ); }
	return $tiers[ $tier ][ $provider ];
}
