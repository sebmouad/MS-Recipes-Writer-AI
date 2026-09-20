<?php
/**
 * Minimal provider client for the prompt lab. Mirrors the request the plugin
 * sends, so a prompt proven here behaves the same in production.
 */

function lab_key() {
	foreach ( array( 'OPENAI_API_KEY', 'MSRWA_OPENAI_KEY' ) as $name ) {
		$value = (string) getenv( $name );
		if ( '' !== $value ) { return $value; }
	}
	fwrite( STDERR, "No API key. Set OPENAI_API_KEY in the environment (never in the repository).\n" );
	exit( 2 );
}

/** One Responses call. Returns text, usage, seconds and the raw body. */
function lab_responses( $input, $model, $max_output_tokens, $tools = array(), $json_output = true ) {
	$payload = array( 'model' => $model, 'input' => (string) $input, 'store' => false, 'max_output_tokens' => max( 16, (int) $max_output_tokens ) );
	if ( $tools ) { $payload['tools'] = $tools; }
	if ( $json_output ) { $payload['text'] = array( 'format' => array( 'type' => 'json_object' ) ); }
	$started = microtime( true );
	$ch = curl_init( 'https://api.openai.com/v1/responses' );
	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => array( 'Content-Type: application/json', 'Authorization: Bearer ' . lab_key() ),
		CURLOPT_POSTFIELDS => json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		CURLOPT_TIMEOUT => 300,
	) );
	$raw = curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$error = curl_error( $ch );
	curl_close( $ch );
	$seconds = round( microtime( true ) - $started, 1 );
	if ( '' !== $error ) { return array( 'error' => $error, 'seconds' => $seconds ); }
	$body = json_decode( (string) $raw, true );
	if ( 200 !== $status ) { return array( 'error' => 'HTTP ' . $status . ': ' . substr( (string) $raw, 0, 300 ), 'seconds' => $seconds ); }
	return array(
		'text' => lab_extract_text( $body ),
		'usage' => isset( $body['usage'] ) ? $body['usage'] : array(),
		'model' => isset( $body['model'] ) ? $body['model'] : $model,
		'status' => isset( $body['status'] ) ? $body['status'] : '',
		'seconds' => $seconds,
	);
}

function lab_extract_text( $body ) {
	if ( isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) { return $body['output_text']; }
	$text = '';
	foreach ( (array) ( $body['output'] ?? array() ) as $item ) {
		foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
			if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $text .= $content['text']; }
		}
	}
	return $text;
}

/** Cost from the catalogue rates the plugin ships. */
function lab_cost( $model, $usage, $catalog ) {
	foreach ( $catalog as $models ) {
		foreach ( $models as $id => $row ) {
			if ( $id !== $model && ( $row['api_specifics']['identifier'] ?? '' ) !== $model ) { continue; }
			$in = (int) ( $usage['input_tokens'] ?? 0 );
			$out = (int) ( $usage['output_tokens'] ?? 0 );
			return ( $in * (float) $row['input'] + $out * (float) $row['output'] ) / 1000000;
		}
	}
	return null;
}

/** One image generation. Writes the file and returns its path, size and usage. */
function lab_image( $prompt, $model, $size, $quality, $output_format, $destination ) {
	$payload = array(
		'model' => $model, 'prompt' => (string) $prompt, 'size' => $size,
		'quality' => $quality, 'output_format' => $output_format, 'n' => 1,
	);
	$started = microtime( true );
	$ch = curl_init( 'https://api.openai.com/v1/images/generations' );
	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => array( 'Content-Type: application/json', 'Authorization: Bearer ' . lab_key() ),
		CURLOPT_POSTFIELDS => json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		CURLOPT_TIMEOUT => 600,
	) );
	$raw = curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	$seconds = round( microtime( true ) - $started, 1 );
	if ( 200 !== $status ) { return array( 'error' => 'HTTP ' . $status . ': ' . substr( (string) $raw, 0, 300 ), 'seconds' => $seconds ); }
	$body = json_decode( (string) $raw, true );
	$b64 = $body['data'][0]['b64_json'] ?? '';
	if ( '' === $b64 ) { return array( 'error' => 'no image payload returned', 'seconds' => $seconds ); }
	$binary = base64_decode( $b64 );
	file_put_contents( $destination, $binary );
	return array( 'path' => $destination, 'bytes' => strlen( $binary ), 'seconds' => $seconds, 'usage' => $body['usage'] ?? array(), 'model' => $body['model'] ?? $model );
}
