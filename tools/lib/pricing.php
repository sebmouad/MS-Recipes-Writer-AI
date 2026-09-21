<?php
/**
 * Delegates to the engine. The rates moved into includes/engine so the plugin
 * and the lab price a call the same way; these wrappers keep the lab's older
 * entry points working while their callers are migrated.
 */
function lab_rates() { return MSRWA_Engine_Rates::all(); }
function lab_tiers() { return MSRWA_Engine_Rates::tiers(); }
function lab_price( $provider, $model, $usage ) { return MSRWA_Engine_Rates::price( $provider, $model, $usage ); }
function lab_model( $provider, $tier ) {
	$model = MSRWA_Engine_Rates::model( $provider, $tier );
	if ( '' === $model ) { fwrite( STDERR, "Unknown provider/tier: {$provider}/{$tier}\n" ); exit( 2 ); }
	return $model;
}
