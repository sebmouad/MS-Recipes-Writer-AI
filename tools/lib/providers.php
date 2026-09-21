<?php
/**
 * Delegates to the engine. Every provider call moved into
 * includes/engine/class-msrwa-engine-call.php so the plugin and the lab reach a
 * model through the same code; these wrappers keep the lab's older entry points
 * working while their callers are migrated.
 */
function lab_provider_key( $provider ) {
	$key = MSRWA_Engine_Call::key( $provider );
	if ( '' === $key ) { fwrite( STDERR, "No API key for {$provider}. Set it in the environment.\n" ); exit( 2 ); }
	return $key;
}
function lab_http( $url, $headers, $payload, $timeout = 600 ) { return MSRWA_Engine_Call::http( $url, $headers, $payload, $timeout ); }
function lab_call( $provider, $model, $input, $max_tokens, $json_output = true, $tools = array() ) { return MSRWA_Engine_Call::text( $provider, $model, $input, $max_tokens, $json_output, $tools ); }
function lab_call_vision( $provider, $model, $image, $context, $max_tokens = 900 ) { return MSRWA_Engine_Call::vision( $provider, $model, $image, $context, $max_tokens ); }
function lab_call_judge( $provider, $model, $instruction, $images, $max_tokens = 2500 ) { return MSRWA_Engine_Call::judge( $provider, $model, $instruction, $images, $max_tokens ); }
function lab_image( $prompt, $model, $size, $quality, $format, $destination ) { return MSRWA_Engine_Call::image( $prompt, $model, $size, $quality, $format, $destination ); }
function lab_fetch_image( $url ) { return MSRWA_Engine_Call::fetch_image( $url ); }
function lab_enrich_research_images( $provider, $model, $package, $limit = 3 ) { return MSRWA_Engine_Call::observe_images( $provider, $model, $package, $limit ); }
function lab_utf8( $text ) { return MSRWA_Engine_Call::utf8( $text ); }
