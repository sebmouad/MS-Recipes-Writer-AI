<?php
/** The rates live in includes/engine so the plugin and the lab price a call the same way. */
function lab_price( $provider, $model, $usage ) { return MSRWA_Engine_Rates::price( $provider, $model, $usage ); }
function lab_model( $provider, $tier ) {
	$model = MSRWA_Engine_Rates::model( $provider, $tier );
	if ( '' === $model ) { fwrite( STDERR, "Unknown provider/tier: {$provider}/{$tier}\n" ); exit( 2 ); }
	return $model;
}
