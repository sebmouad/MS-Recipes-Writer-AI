<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every call the engine makes to a provider, normalised.
 *
 * One shape in and one shape out across OpenAI, Gemini and Anthropic, so a step
 * describes what it wants rather than how each provider spells it. Nothing here
 * throws or exits: a missing key, a refused image, an HTTP error all come back
 * as an 'error' entry the caller records.
 */
final class MSRWA_Engine_Call {

	/**
	 * Reads .env.local for API keys when the environment does not already carry
	 * them. Only the three key names are accepted and an existing value always
	 * wins, so a stray file cannot override how the caller is configured. The
	 * file is gitignored; keys never enter the repository.
	 */
	private static function load_local_env() {
	static $loaded = false;
	if ( $loaded ) { return; }
	$loaded = true;
	$file = dirname( __DIR__, 2 ) . '/.env.local';
	if ( ! is_readable( $file ) ) { return; }
	$allowed = array( 'OPENAI_API_KEY', 'GEMINI_API_KEY', 'ANTHROPIC_API_KEY' );
	foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		if ( ! preg_match( '/^([A-Z][A-Z0-9_]*)=(.*)$/', trim( $line ), $match ) || ! in_array( $match[1], $allowed, true ) || false !== getenv( $match[1] ) ) { continue; }
		$value = trim( $match[2] );
		if ( strlen( $value ) >= 2 && ( ( '"' === $value[0] && '"' === substr( $value, -1 ) ) || ( "'" === $value[0] && "'" === substr( $value, -1 ) ) ) ) { $value = substr( $value, 1, -1 ); }
		if ( '' === $value ) { continue; }
		putenv( $match[1] . '=' . $value );
		$_ENV[ $match[1] ] = $value;
	}
	}

	/** Reports a provider that cannot be called at all, so a step fails with a reason. */
	public static function unusable( $provider ) {
		return '' === self::key( $provider ) ? 'No API key for ' . $provider . '.' : '';
	}

	public static function key( $provider ) {
		self::load_local_env();
		$names = array( 'openai' => array( 'OPENAI_API_KEY', 'MSRWA_OPENAI_KEY' ), 'gemini' => array( 'GEMINI_API_KEY', 'MSRWA_GEMINI_KEY' ), 'claude' => array( 'ANTHROPIC_API_KEY', 'MSRWA_CLAUDE_KEY' ) );
		foreach ( $names[ $provider ] ?? array() as $name ) {
			$value = (string) getenv( $name );
			if ( '' !== $value ) { return $value; }
		}
		return '';
	}

	public static function http( $url, $headers, $payload, $timeout = 600 ) {
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
		return array( 'status' => $status, 'raw' => (string) $raw, 'error' => $error, 'seconds' => round( microtime( true ) - $started, 1 ) );
	}

	/** One text call on any provider. $tools is a normalised list, currently only web search. */
	public static function text( $provider, $model, $input, $max_tokens, $json_output = true, $tools = array() ) {
		$unusable = self::unusable( $provider );
		if ( '' !== $unusable ) { return array( 'error' => $unusable, 'seconds' => 0, 'usage' => array() ); }
		if ( 'openai' === $provider ) { return self::text_openai( $model, $input, $max_tokens, $json_output, $tools ); }
		if ( 'gemini' === $provider ) { return self::text_gemini( $model, $input, $max_tokens, $json_output, $tools ); }
		if ( 'claude' === $provider ) { return self::text_claude( $model, $input, $max_tokens, $json_output, $tools ); }
		return array( 'error' => 'unknown provider ' . $provider, 'seconds' => 0 );
	}

	private static function text_openai( $model, $input, $max_tokens, $json_output, $tools ) {
		$payload = array( 'model' => $model, 'input' => (string) $input, 'store' => false, 'max_output_tokens' => max( 16, (int) $max_tokens ) );
		if ( $tools ) { $payload['tools'] = array( array( 'type' => 'web_search' ) ); }
		if ( $json_output && ! $tools ) { $payload['text'] = array( 'format' => array( 'type' => 'json_object' ) ); }
		$result = self::http( 'https://api.openai.com/v1/responses', array( 'Content-Type: application/json', 'Authorization: Bearer ' . self::key( 'openai' ) ), $payload );
		if ( 200 !== $result['status'] ) { return array( 'error' => 'HTTP ' . $result['status'] . ': ' . substr( $result['raw'], 0, 240 ), 'seconds' => $result['seconds'] ); }
		$body = json_decode( $result['raw'], true );
		$text = '';
		if ( isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) { $text = $body['output_text']; }
		else {
			foreach ( (array) ( $body['output'] ?? array() ) as $item ) {
				foreach ( (array) ( $item['content'] ?? array() ) as $content ) { if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $text .= $content['text']; } }
			}
		}
		// cached_tokens is what the provider reused from an identical prompt prefix; it
		// is billed at a discount, so it is the number that says whether caching works.
		return array( 'text' => self::utf8( $text ), 'usage' => array( 'input_tokens' => (int) ( $body['usage']['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ), 'cached_input_tokens' => (int) ( $body['usage']['input_tokens_details']['cached_tokens'] ?? 0 ) ), 'model' => $body['model'] ?? $model, 'status' => $body['status'] ?? '', 'seconds' => $result['seconds'] );
	}

	private static function text_gemini( $model, $input, $max_tokens, $json_output, $tools ) {
		$payload = array(
			'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => (string) $input ) ) ) ),
			'generationConfig' => array( 'maxOutputTokens' => max( 16, (int) $max_tokens ) ),
		);
		if ( $json_output && ! $tools ) { $payload['generationConfig']['responseMimeType'] = 'application/json'; }
		if ( $tools ) { $payload['tools'] = array( array( 'google_search' => new stdClass() ) ); }
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
		$result = self::http( $url, array( 'Content-Type: application/json', 'x-goog-api-key: ' . self::key( 'gemini' ) ), $payload );
		if ( 200 !== $result['status'] ) { return array( 'error' => 'HTTP ' . $result['status'] . ': ' . substr( $result['raw'], 0, 240 ), 'seconds' => $result['seconds'] ); }
		$body = json_decode( $result['raw'], true );
		$text = '';
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) { if ( isset( $part['text'] ) ) { $text .= $part['text']; } }
		$usage = $body['usageMetadata'] ?? array();
		return array( 'text' => self::utf8( $text ), 'usage' => array( 'input_tokens' => (int) ( $usage['promptTokenCount'] ?? 0 ), 'output_tokens' => (int) ( $usage['candidatesTokenCount'] ?? 0 ) ), 'model' => $model, 'status' => $body['candidates'][0]['finishReason'] ?? '', 'seconds' => $result['seconds'] );
	}

	private static function text_claude( $model, $input, $max_tokens, $json_output, $tools ) {
		$instruction = $json_output ? "\n\nReturn only a valid JSON object, with no Markdown fence and no commentary." : '';
		$payload = array(
			'model' => $model, 'max_tokens' => max( 16, (int) $max_tokens ),
			'messages' => array( array( 'role' => 'user', 'content' => (string) $input . $instruction ) ),
		);
		if ( $tools ) { $payload['tools'] = array( array( 'type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3 ) ); }
		$result = self::http( 'https://api.anthropic.com/v1/messages', array( 'Content-Type: application/json', 'x-api-key: ' . self::key( 'claude' ), 'anthropic-version: 2023-06-01' ), $payload );
		if ( 200 !== $result['status'] ) { return array( 'error' => 'HTTP ' . $result['status'] . ': ' . substr( $result['raw'], 0, 240 ), 'seconds' => $result['seconds'] ); }
		$body = json_decode( $result['raw'], true );
		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $block ) { if ( 'text' === ( $block['type'] ?? '' ) ) { $text .= $block['text']; } }
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', trim( $text ) );
		return array( 'text' => self::utf8( $text ), 'usage' => array( 'input_tokens' => (int) ( $body['usage']['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ) ), 'model' => $body['model'] ?? $model, 'status' => $body['stop_reason'] ?? '', 'seconds' => $result['seconds'] );
	}

	/** Generates one OpenAI image and writes it to the lab runs directory. */
	public static function image( $prompt, $model, $size, $quality, $output_format, $destination ) {
		$unusable = self::unusable( 'openai' );
		if ( '' !== $unusable ) { return array( 'error' => $unusable, 'seconds' => 0, 'usage' => array() ); }
		$payload = array( 'model' => $model, 'prompt' => (string) $prompt, 'size' => $size, 'quality' => $quality, 'output_format' => $output_format, 'n' => 1 );
		$result = self::http( 'https://api.openai.com/v1/images/generations', array( 'Content-Type: application/json', 'Authorization: Bearer ' . self::key( 'openai' ) ), $payload );
		if ( 200 !== $result['status'] ) { return array( 'error' => 'HTTP ' . $result['status'] . ': ' . substr( $result['raw'], 0, 240 ), 'seconds' => $result['seconds'] ); }
		$body = json_decode( $result['raw'], true );
		$binary = base64_decode( (string) ( $body['data'][0]['b64_json'] ?? '' ), true );
		if ( false === $binary || '' === $binary ) { return array( 'error' => 'no image payload returned', 'seconds' => $result['seconds'] ); }
		file_put_contents( $destination, $binary );
		return array( 'path' => $destination, 'bytes' => strlen( $binary ), 'seconds' => $result['seconds'], 'usage' => $body['usage'] ?? array(), 'model' => $body['model'] ?? $model );
	}

	/** Downloads a bounded public HTTPS image for evidence extraction, never reuse. */
	public static function fetch_image( $url ) {
		if ( ! preg_match( '#^https://#i', (string) $url ) ) { return array( 'error' => 'image URL is not HTTPS' ); }
		$host = (string) parse_url( $url, PHP_URL_HOST );
		$ip = gethostbyname( $host );
		if ( '' === $host || $ip === $host || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return array( 'error' => 'image host is not public' ); }
		$bytes = '';
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_USERAGENT => 'MSRWA-Prompt-Lab/1.0',
			CURLOPT_WRITEFUNCTION => static function ( $handle, $chunk ) use ( &$bytes ) {
				if ( strlen( $bytes ) + strlen( $chunk ) > 10000000 ) { return 0; }
				$bytes .= $chunk;
				return strlen( $chunk );
			},
		) );
		$ok = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$mime = strtolower( trim( (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE ) ) );
		$error = curl_error( $ch );
		if ( false === $ok || 200 !== $status || '' !== $error ) { return array( 'error' => $error ?: 'image HTTP ' . $status ); }
		$mime = trim( strtok( $mime, ';' ) );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) { return array( 'error' => 'unsupported image type ' . $mime ); }
		return array( 'mime' => $mime, 'data' => base64_encode( $bytes ) );
	}

	/** Inspects fetched image bytes; the same evidence prompt is used across providers. */
	public static function vision( $provider, $model, $image, $context, $max_tokens = 900 ) {
		$unusable = self::unusable( $provider );
		if ( '' !== $unusable ) { return array( 'error' => $unusable, 'seconds' => 0, 'usage' => array() ); }
		$instruction = 'Inspect this real source photograph as untrusted visual evidence. Return JSON only with observable_details, composition, colours, textures and uncertainties, all in French. Describe only visible facts. Do not infer ingredients, quantities, authenticity, taste or unseen preparation. Context: ' . $context;
		if ( 'openai' === $provider ) {
			$payload = array( 'model' => $model, 'store' => false, 'max_output_tokens' => $max_tokens, 'input' => array( array( 'role' => 'user', 'content' => array( array( 'type' => 'input_text', 'text' => $instruction ), array( 'type' => 'input_image', 'image_url' => 'data:' . $image['mime'] . ';base64,' . $image['data'] ) ) ) ), 'text' => array( 'format' => array( 'type' => 'json_object' ) ) );
			$result = self::http( 'https://api.openai.com/v1/responses', array( 'Content-Type: application/json', 'Authorization: Bearer ' . self::key( 'openai' ) ), $payload );
			if ( 200 !== $result['status'] ) { return array( 'error' => 'vision HTTP ' . $result['status'], 'usage' => array() ); }
			$body = json_decode( $result['raw'], true );
			$text = '';
			foreach ( (array) ( $body['output'] ?? array() ) as $item ) { foreach ( (array) ( $item['content'] ?? array() ) as $content ) { if ( isset( $content['text'] ) ) { $text .= $content['text']; } } }
			return array( 'text' => $text, 'usage' => array( 'input_tokens' => (int) ( $body['usage']['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ) ) );
		}
		if ( 'gemini' === $provider ) {
			$payload = array( 'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $instruction ), array( 'inline_data' => array( 'mime_type' => $image['mime'], 'data' => $image['data'] ) ) ) ) ), 'generationConfig' => array( 'maxOutputTokens' => $max_tokens, 'responseMimeType' => 'application/json' ) );
			$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
			$result = self::http( $url, array( 'Content-Type: application/json', 'x-goog-api-key: ' . self::key( 'gemini' ) ), $payload );
			if ( 200 !== $result['status'] ) { return array( 'error' => 'vision HTTP ' . $result['status'], 'usage' => array() ); }
			$body = json_decode( $result['raw'], true );
			$text = (string) ( $body['candidates'][0]['content']['parts'][0]['text'] ?? '' );
			$usage = $body['usageMetadata'] ?? array();
			return array( 'text' => $text, 'usage' => array( 'input_tokens' => (int) ( $usage['promptTokenCount'] ?? 0 ), 'output_tokens' => (int) ( $usage['candidatesTokenCount'] ?? 0 ) ) );
		}
		$payload = array( 'model' => $model, 'max_tokens' => $max_tokens, 'messages' => array( array( 'role' => 'user', 'content' => array( array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data'] ) ), array( 'type' => 'text', 'text' => $instruction ) ) ) ) );
		$result = self::http( 'https://api.anthropic.com/v1/messages', array( 'Content-Type: application/json', 'x-api-key: ' . self::key( 'claude' ), 'anthropic-version: 2023-06-01' ), $payload );
		if ( 200 !== $result['status'] ) { return array( 'error' => 'vision HTTP ' . $result['status'], 'usage' => array() ); }
		$body = json_decode( $result['raw'], true );
		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $block ) { if ( 'text' === ( $block['type'] ?? '' ) ) { $text .= $block['text']; } }
		return array( 'text' => $text, 'usage' => array( 'input_tokens' => (int) ( $body['usage']['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ) ) );
	}

	/**
	 * Judges several images against one instruction in a single call. Separate from
	 * lab_call_vision because the jobs differ: that one observes one source photo as
	 * untrusted evidence, this one compares the finished artifacts to each other, and
	 * seeing them together is the whole point — the article, the featured image and
	 * the collage can only be checked for agreement in one call.
	 *
	 * $images is a list of array( 'label' => string, 'mime' => string, 'data' => base64 ).
	 */
	public static function judge( $provider, $model, $instruction, $images, $max_tokens = 2500 ) {
		$unusable = self::unusable( $provider );
		if ( '' !== $unusable ) { return array( 'error' => $unusable, 'seconds' => 0, 'usage' => array() ); }
		if ( 'openai' === $provider ) {
			$content = array( array( 'type' => 'input_text', 'text' => $instruction ) );
			foreach ( $images as $image ) {
				$content[] = array( 'type' => 'input_text', 'text' => 'IMAGE — ' . $image['label'] );
				$content[] = array( 'type' => 'input_image', 'image_url' => 'data:' . $image['mime'] . ';base64,' . $image['data'] );
			}
			$payload = array( 'model' => $model, 'store' => false, 'max_output_tokens' => $max_tokens, 'input' => array( array( 'role' => 'user', 'content' => $content ) ), 'text' => array( 'format' => array( 'type' => 'json_object' ) ) );
			$result = self::http( 'https://api.openai.com/v1/responses', array( 'Content-Type: application/json', 'Authorization: Bearer ' . self::key( 'openai' ) ), $payload );
			if ( 200 !== $result['status'] ) { return array( 'error' => 'judge HTTP ' . $result['status'] . ': ' . substr( $result['raw'], 0, 200 ), 'seconds' => $result['seconds'], 'usage' => array() ); }
			$body = json_decode( $result['raw'], true );
			$text = '';
			foreach ( (array) ( $body['output'] ?? array() ) as $item ) { foreach ( (array) ( $item['content'] ?? array() ) as $part ) { if ( isset( $part['text'] ) ) { $text .= $part['text']; } } }
			return array( 'text' => self::utf8( $text ), 'status' => $body['status'] ?? '', 'seconds' => $result['seconds'], 'usage' => array( 'input_tokens' => (int) ( $body['usage']['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ) ) );
		}
		if ( 'gemini' === $provider ) {
			$parts = array( array( 'text' => $instruction ) );
			foreach ( $images as $image ) {
				$parts[] = array( 'text' => 'IMAGE — ' . $image['label'] );
				$parts[] = array( 'inline_data' => array( 'mime_type' => $image['mime'], 'data' => $image['data'] ) );
			}
			$payload = array( 'contents' => array( array( 'role' => 'user', 'parts' => $parts ) ), 'generationConfig' => array( 'maxOutputTokens' => $max_tokens, 'responseMimeType' => 'application/json' ) );
			$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
			$result = self::http( $url, array( 'Content-Type: application/json', 'x-goog-api-key: ' . self::key( 'gemini' ) ), $payload );
			if ( 200 !== $result['status'] ) { return array( 'error' => 'judge HTTP ' . $result['status'] . ': ' . substr( $result['raw'], 0, 200 ), 'seconds' => $result['seconds'], 'usage' => array() ); }
			$body = json_decode( $result['raw'], true );
			$text = '';
			foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) { if ( isset( $part['text'] ) ) { $text .= $part['text']; } }
			$usage = $body['usageMetadata'] ?? array();
			return array( 'text' => self::utf8( $text ), 'status' => $body['candidates'][0]['finishReason'] ?? '', 'seconds' => $result['seconds'], 'usage' => array( 'input_tokens' => (int) ( $usage['promptTokenCount'] ?? 0 ), 'output_tokens' => (int) ( $usage['candidatesTokenCount'] ?? 0 ) ) );
		}
		$content = array();
		foreach ( $images as $image ) {
			$content[] = array( 'type' => 'text', 'text' => 'IMAGE — ' . $image['label'] );
			$content[] = array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data'] ) );
		}
		$content[] = array( 'type' => 'text', 'text' => $instruction . "\n\nReturn only a valid JSON object, with no Markdown fence and no commentary." );
		$payload = array( 'model' => $model, 'max_tokens' => $max_tokens, 'messages' => array( array( 'role' => 'user', 'content' => $content ) ) );
		$result = self::http( 'https://api.anthropic.com/v1/messages', array( 'Content-Type: application/json', 'x-api-key: ' . self::key( 'claude' ), 'anthropic-version: 2023-06-01' ), $payload );
		if ( 200 !== $result['status'] ) { return array( 'error' => 'judge HTTP ' . $result['status'] . ': ' . substr( $result['raw'], 0, 200 ), 'seconds' => $result['seconds'], 'usage' => array() ); }
		$body = json_decode( $result['raw'], true );
		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $block ) { if ( 'text' === ( $block['type'] ?? '' ) ) { $text .= $block['text']; } }
		return array( 'text' => self::utf8( $text ), 'status' => $body['stop_reason'] ?? '', 'seconds' => $result['seconds'], 'usage' => array( 'input_tokens' => (int) ( $body['usage']['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ) ) );
	}

	/** Replaces search-model guesses with observations made from the cited bytes. */
	public static function observe_images( $provider, $model, $package, $limit = 3 ) {
		$package['visual_observations'] = array();
		$usage = array( 'input_tokens' => 0, 'output_tokens' => 0 );
		foreach ( array_slice( (array) ( $package['visual_references'] ?? array() ), 0, $limit ) as $reference ) {
			$image = self::fetch_image( $reference['image_url'] ?? '' );
			if ( isset( $image['error'] ) ) { $package['uncertainties'][] = 'Image non analysée : ' . $image['error']; continue; }
			$vision = self::vision( $provider, $model, $image, (string) ( $reference['title'] ?? '' ) );
			$usage['input_tokens'] += (int) ( $vision['usage']['input_tokens'] ?? 0 );
			$usage['output_tokens'] += (int) ( $vision['usage']['output_tokens'] ?? 0 );
			$observed = MSRWA_Json::decode( (string) ( $vision['text'] ?? '' ) );
			if ( ! is_array( $observed ) ) { $package['uncertainties'][] = 'Analyse visuelle non structurée pour ' . (string) ( $reference['source_url'] ?? '' ); continue; }
			$package['visual_observations'][] = array_merge( array( 'image_url' => $reference['image_url'] ?? '', 'source_url' => $reference['source_url'] ?? '' ), $observed );
		}
		return array( 'package' => $package, 'usage' => $usage );
	}
}
