<?php
/**
 * Every provider call lives in includes/engine/class-msrwa-engine-call.php, so
 * the plugin and the lab reach a model through the same code. What is left here
 * is what the format experiment still calls directly.
 */
function lab_call( $provider, $model, $input, $max_tokens, $json_output = true, $tools = array() ) { return MSRWA_Engine_Call::text( $provider, $model, $input, $max_tokens, $json_output, $tools ); }
function lab_image( $prompt, $model, $size, $quality, $format, $destination ) { return MSRWA_Engine_Call::image( $prompt, $model, $size, $quality, $format, $destination ); }
