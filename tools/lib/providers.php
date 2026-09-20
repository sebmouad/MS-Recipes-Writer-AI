<?php
/**
 * Provider adapters for the lab: one normalised call across OpenAI, Gemini and
 * Anthropic, so the same prompt can be measured on each.
 *
 * Every adapter returns: text, usage{input_tokens,output_tokens}, model, seconds.
 */

function lab_provider_key( $provider ) {
	$names = array( 'openai' => array( 'OPENAI_API_KEY', 'MSRWA_OPENAI_KEY' ), 'gemini' => array( 'GEMINI_API_KEY', 'MSRWA_GEMINI_KEY' ), 'claude' => array( 'ANTHROPIC_API_KEY', 'MSRWA_CLAUDE_KEY' ) );
	foreach ( $names[ $provider ] ?? array() as $name ) {
		$value = (string) getenv( $name );
		if ( '' !== $value ) { return $value; }
	}
	fwrite( STDERR, "No API key for {$provider}. Set it in the environment.\n" );
	exit( 2 );
}

function lab_http( $url, $headers, $payload, $timeout = 600 ) {
	$started = microtime( true );
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_POSTFIELDS => json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		CURLOPT_TIMEOUT => $timeout,
	) );
	$raw = curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$error = curl_error( $ch );
	curl_close( $ch );
	return array( 'status' => $status, 'raw' => (string) $raw, 'error' => $error, 'seconds' => round( microtime( true ) - $started, 1 ) );
}

/** One text call on any provider. $tools is a normalised list, currently only web search. */
function lab_call( $provider, $model, $input, $max_tokens, $json_output = true, $tools = array() ) {
	if ( 'openai' === $provider ) { return lab_call_openai( $model, $input, $max_tokens, $json_output, $tools ); }
	if ( 'gemini' === $provider ) { return lab_call_gemini( $model, $input, $max_tokens, $json_output, $tools ); }
	if ( 'claude' === $provider ) { return lab_call_claude( $model, $input, $max_tokens, $json_output, $tools ); }
	return array( 'error' => 'unknown provider ' . $provider, 'seconds' => 0 );
}

function lab_call_openai( $model, $input, $max_tokens, $json_output, $tools ) {
	$payload = array( 'model' => $model, 'input' => (string) $input, 'store' => false, 'max_output_tokens' => max( 16, (int) $max_tokens ) );
	if ( $tools ) { $payload['tools'] = array( array( 'type' => 'web_search' ) ); }
	if ( $json_output && ! $tools ) { $payload['text'] = array( 'format' => array( 'type' => 'json_object' ) ); }
	$result = lab_http( 'https://api.openai.com/v1/responses', array( 'Content-Type: application/json', 'Authorization: Bearer ' . lab_provider_key( 'openai' ) ), $payload );
	if ( 200 !== $result['status'] ) { return array( 'error' => 'HTTP ' . $result['status'] . ': ' . substr( $result['raw'], 0, 240 ), 'seconds' => $result['seconds'] ); }
	$body = json_decode( $result['raw'], true );
	$text = '';
	if ( isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) { $text = $body['output_text']; }
	else {
		foreach ( (array) ( $body['output'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['content'] ?? array() ) as $content ) { if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $text .= $content['text']; } }
		}
	}
	return array( 'text' => $text, 'usage' => array( 'input_tokens' => (int) ( $body['usage']['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ) ), 'model' => $body['model'] ?? $model, 'status' => $body['status'] ?? '', 'seconds' => $result['seconds'] );
}

function lab_call_gemini( $model, $input, $max_tokens, $json_output, $tools ) {
	$payload = array(
		'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => (string) $input ) ) ) ),
		'generationConfig' => array( 'maxOutputTokens' => max( 16, (int) $max_tokens ) ),
	);
	if ( $json_output && ! $tools ) { $payload['generationConfig']['responseMimeType'] = 'application/json'; }
	if ( $tools ) { $payload['tools'] = array( array( 'google_search' => new stdClass() ) ); }
	$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
	$result = lab_http( $url, array( 'Content-Type: application/json', 'x-goog-api-key: ' . lab_provider_key( 'gemini' ) ), $payload );
	if ( 200 !== $result['status'] ) { return array( 'error' => 'HTTP ' . $result['status'] . ': ' . substr( $result['raw'], 0, 240 ), 'seconds' => $result['seconds'] ); }
	$body = json_decode( $result['raw'], true );
	$text = '';
	foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) { if ( isset( $part['text'] ) ) { $text .= $part['text']; } }
	$usage = $body['usageMetadata'] ?? array();
	return array( 'text' => $text, 'usage' => array( 'input_tokens' => (int) ( $usage['promptTokenCount'] ?? 0 ), 'output_tokens' => (int) ( $usage['candidatesTokenCount'] ?? 0 ) ), 'model' => $model, 'status' => $body['candidates'][0]['finishReason'] ?? '', 'seconds' => $result['seconds'] );
}

function lab_call_claude( $model, $input, $max_tokens, $json_output, $tools ) {
	$instruction = $json_output ? "\n\nReturn only a valid JSON object, with no Markdown fence and no commentary." : '';
	$payload = array(
		'model' => $model, 'max_tokens' => max( 16, (int) $max_tokens ),
		'messages' => array( array( 'role' => 'user', 'content' => (string) $input . $instruction ) ),
	);
	if ( $tools ) { $payload['tools'] = array( array( 'type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3 ) ); }
	$result = lab_http( 'https://api.anthropic.com/v1/messages', array( 'Content-Type: application/json', 'x-api-key: ' . lab_provider_key( 'claude' ), 'anthropic-version: 2023-06-01' ), $payload );
	if ( 200 !== $result['status'] ) { return array( 'error' => 'HTTP ' . $result['status'] . ': ' . substr( $result['raw'], 0, 240 ), 'seconds' => $result['seconds'] ); }
	$body = json_decode( $result['raw'], true );
	$text = '';
	foreach ( (array) ( $body['content'] ?? array() ) as $block ) { if ( 'text' === ( $block['type'] ?? '' ) ) { $text .= $block['text']; } }
	$text = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', trim( $text ) );
	return array( 'text' => $text, 'usage' => array( 'input_tokens' => (int) ( $body['usage']['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ) ), 'model' => $body['model'] ?? $model, 'status' => $body['stop_reason'] ?? '', 'seconds' => $result['seconds'] );
}
