<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every call the engine makes to a provider, normalised.
 *
 * One shape in and one shape out across OpenAI, Gemini and Anthropic, so a step
 * describes what it wants rather than how each provider spells it. Nothing here
 * throws or exits: a missing key, a refused image, an HTTP error all come back
 * as an 'error' entry the caller records.
 *
 * Endpoints, headers, key names and each provider's spelling of "search the
 * web" are not written here. They arrive as `$wire`, resolved by
 * MSRWA_Engine_Config::provider(), so a caller can move an endpoint, add a
 * header or point at a gateway without touching this file. What remains here is
 * the one thing that genuinely differs between providers: the shape of the
 * request body and where the answer sits in the response.
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
	$allowed = self::known_key_names();
	foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		if ( ! preg_match( '/^([A-Z][A-Z0-9_]*)=(.*)$/', trim( $line ), $match ) || ! in_array( $match[1], $allowed, true ) || false !== getenv( $match[1] ) ) { continue; }
		$value = trim( $match[2] );
		if ( strlen( $value ) >= 2 && ( ( '"' === $value[0] && '"' === substr( $value, -1 ) ) || ( "'" === $value[0] && "'" === substr( $value, -1 ) ) ) ) { $value = substr( $value, 1, -1 ); }
		if ( '' === $value ) { continue; }
		putenv( $match[1] . '=' . $value );
		$_ENV[ $match[1] ] = $value;
	}
	}

	/**
	 * Drops bytes that are not valid UTF-8.
	 *
	 * An answer stopped at max_tokens ends mid-character, and that one broken
	 * sequence makes json_encode return false for the whole string — a 14 500
	 * token answer was once billed and stored as an empty one.
	 */
	public static function utf8( $text ) {
		return MSRWA_Json::valid_utf8( $text );
	}

	/** Reports a provider that cannot be called at all, so a step fails with a reason. */
	public static function unusable( $provider, $wire = array() ) {
		if ( $wire && empty( $wire['has_key'] ) ) { return 'No API key for ' . $provider . '.'; }
		if ( ! $wire && '' === self::key( $provider ) ) { return 'No API key for ' . $provider . '.'; }
		if ( $wire && '' === (string) ( $wire['text_endpoint'] ?? '' ) && '' === (string) ( $wire['image_endpoint'] ?? '' ) ) { return 'No endpoint configured for ' . $provider . '.'; }
		return '';
	}

	/**
	 * The key for a provider, from the environment names the configuration gives.
	 * The names default to the shipped ones so a caller that configures nothing
	 * still works; a caller that keeps its keys elsewhere passes its own.
	 */
	public static function key( $provider, $names = array() ) {
		self::load_local_env();
		if ( ! $names ) { $names = (array) ( MSRWA_Engine_Config::defaults()['providers'][ $provider ]['key_env'] ?? array() ); }
		foreach ( (array) $names as $name ) {
			$value = (string) getenv( $name );
			if ( '' !== $value ) { return $value; }
		}
		return '';
	}

	/**
	 * Every environment variable the shipped configuration names as holding a
	 * key. It is the allowlist for .env.local: a stray file may set one of
	 * these and nothing else, so it cannot reach into the rest of the process.
	 */
	private static function known_key_names() {
		$names = array();
		foreach ( (array) MSRWA_Engine_Config::defaults()['providers'] as $provider ) {
			foreach ( (array) ( $provider['key_env'] ?? array() ) as $name ) { $names[] = $name; }
		}
		return $names;
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

	/**
	 * Several HTTP calls at once, returned under the keys they were given.
	 *
	 * The engine knows which steps do not wait on each other — that is what
	 * `needs` declares — but knowing it bought nothing while the calls were made
	 * one after another. The article and both images take 138 seconds in a row
	 * and 71 together; the two reviews take 65 and 33. PHP waits on the network
	 * either way, so what is saved here is only the waiting.
	 *
	 * A request that fails comes back as a failed request, never as an exception,
	 * and one failure does not disturb the others.
	 */
	public static function http_many( array $requests, $limit = 4 ) {
		if ( ! $requests ) { return array(); }
		if ( 1 === count( $requests ) || $limit < 2 ) {
			$out = array();
			foreach ( $requests as $key => $request ) {
				$out[ $key ] = self::http( $request['url'], $request['headers'], $request['payload'], (int) ( $request['timeout'] ?? 600 ) );
			}
			return $out;
		}

		$results = array();
		foreach ( array_chunk( $requests, max( 2, (int) $limit ), true ) as $chunk ) {
			$multi = curl_multi_init();
			$handles = array();
			$started = microtime( true );
			foreach ( $chunk as $key => $request ) {
				$handle = curl_init( $request['url'] );
				curl_setopt_array( $handle, array(
					CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
					CURLOPT_HTTPHEADER => $request['headers'],
					CURLOPT_POSTFIELDS => json_encode( $request['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					CURLOPT_TIMEOUT => (int) ( $request['timeout'] ?? 600 ),
				) );
				curl_multi_add_handle( $multi, $handle );
				$handles[ $key ] = $handle;
			}

			do {
				$status = curl_multi_exec( $multi, $running );
				if ( $running ) { curl_multi_select( $multi, 1.0 ); }
			} while ( $running && CURLM_OK === $status );

			foreach ( $handles as $key => $handle ) {
				// Each call's own time on the wire, not the wave's, so a step still
				// reports what it took and the totals stay honest.
				$seconds = round( (float) curl_getinfo( $handle, CURLINFO_TOTAL_TIME ), 1 );
				$results[ $key ] = array(
					'status' => (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ),
					'raw' => (string) curl_multi_getcontent( $handle ),
					'error' => (string) curl_error( $handle ),
					'seconds' => $seconds > 0 ? $seconds : round( microtime( true ) - $started, 1 ),
				);
				curl_multi_remove_handle( $multi, $handle );
				curl_close( $handle );
			}
			curl_multi_close( $multi );
		}
		return $results;
	}

	/**
	 * One text call on any provider.
	 *
	 * $web_search asks for the provider's search tool; what that tool is called
	 * comes from the configuration, not from here.
	 */
	public static function text( $provider, $model, $input, $max_tokens, $json_output = true, $web_search = false, $wire = array() ) {
		$plan = self::plan_text( $provider, $model, $input, $max_tokens, $json_output, $web_search, $wire );
		if ( isset( $plan['error'] ) ) { return array( 'error' => $plan['error'], 'seconds' => 0, 'usage' => array() ); }
		$request = $plan['request'];
		return self::read( $plan, self::http( $request['url'], $request['headers'], $request['payload'], (int) $request['timeout'] ) );
	}

	/**
	 * Everything a text call needs, without making it.
	 *
	 * Splitting the request from the reading of it is what lets a wave of
	 * independent steps go out together: the engine builds each plan, hands them
	 * all to http_many(), and reads each answer back with read().
	 */
	public static function plan_text( $provider, $model, $input, $max_tokens, $json_output = true, $web_search = false, $wire = array() ) {
		$wire = $wire ? $wire : self::shipped_wire( $provider, $model );
		$unusable = self::unusable( $provider, $wire );
		if ( '' !== $unusable ) { return array( 'error' => $unusable ); }
		$tools = $web_search ? (array) ( $wire['web_search_tool'] ?? array() ) : array();

		if ( 'openai' === $provider ) {
			$payload = array( 'model' => $model, 'input' => (string) $input, 'store' => false, 'max_output_tokens' => max( 16, (int) $max_tokens ) );
			if ( $tools ) { $payload['tools'] = array( $tools ); }
			if ( $json_output && ! $tools ) { $payload['text'] = array( 'format' => array( 'type' => 'json_object' ) ); }
		} elseif ( 'gemini' === $provider ) {
			$payload = array(
				'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => (string) $input ) ) ) ),
				'generationConfig' => self::gemini_generation( $wire, max( 16, (int) $max_tokens ) ),
			);
			if ( $json_output && ! $tools ) { $payload['generationConfig']['responseMimeType'] = 'application/json'; }
			// An empty tool object must reach the wire as {}, not as [].
			if ( $tools ) { $payload['tools'] = array( array_map( static function ( $value ) { return array() === $value ? new stdClass() : $value; }, $tools ) ); }
		} elseif ( 'claude' === $provider ) {
			$instruction = $json_output ? "\n\nReturn only a valid JSON object, with no Markdown fence and no commentary." : '';
			$payload = array(
				'model' => $model, 'max_tokens' => max( 16, (int) $max_tokens ),
				'messages' => array( array( 'role' => 'user', 'content' => (string) $input . $instruction ) ),
			);
			if ( $tools ) { $payload['tools'] = array( $tools ); }
		} else {
			return array( 'error' => 'unknown provider ' . $provider );
		}

		$payload = self::think( $provider, $model, $payload, $wire );
		return array(
			'kind' => 'text', 'provider' => $provider, 'model' => $model,
			'request' => array( 'url' => $wire['text_endpoint'], 'headers' => $wire['headers'], 'payload' => $payload, 'timeout' => (int) ( $wire['timeout'] ?? 600 ) ),
		);
	}

	private static function gemini_generation( array $wire, $max_tokens ) { return array( 'maxOutputTokens' => (int) $max_tokens ); }

	/**
	 * How hard the model may think, in each provider's own words.
	 *
	 * Thinking is billed as output everywhere, and on Gemini and Claude it is
	 * spent out of the output ceiling. Left to itself, Gemini thinks hard: a
	 * live canonical recipe on gemini-3.5-flash spent its whole 4 500-token
	 * ceiling, stopped on MAX_TOKENS with its JSON cut in half and was billed
	 * $0.046; at `low` the same step passed for $0.0227. The level comes from
	 * the wire (`thinking_level`, set per step by the configuration). A model
	 * that takes no such setting is sent none, because the provider refuses the
	 * whole request rather than ignore it.
	 */
	public static function think( $provider, $model, array $payload, array $wire ) {
		$level = (string) ( $wire['thinking_level'] ?? '' );
		if ( 'gemini' === $provider ) {
			if ( in_array( $level, MSRWA_Engine_Config::thinking_levels(), true ) ) { $payload['generationConfig']['thinkingConfig'] = array( 'thinkingLevel' => $level ); }
			// A caller's own thinkingConfig, as 0.15.0 shipped it, still wins.
			elseif ( ! empty( $wire['thinking'] ) && is_array( $wire['thinking'] ) ) { $payload['generationConfig']['thinkingConfig'] = $wire['thinking']; }
			return $payload;
		}
		if ( '' === $level || ! self::thinks( $provider, $model ) ) { return $payload; }
		if ( 'openai' === $provider ) { $payload['reasoning'] = array( 'effort' => $level ); }
		// Anthropic's effort has no `minimal`; `low` is the least it takes.
		if ( 'claude' === $provider ) { $payload['output_config'] = array( 'effort' => 'minimal' === $level ? 'low' : $level ); }
		return $payload;
	}

	/**
	 * Whether a model accepts a thinking level. OpenAI's GPT-5 family and o-series
	 * take `reasoning.effort`; Claude takes `effort` from Opus 4.5 and the 4.6
	 * generation on, and refuses it on Haiku 4.5 and Sonnet 4.5. Gemini 3 takes
	 * `thinkingLevel` and is handled apart.
	 */
	public static function thinks( $provider, $model ) {
		$model = strtolower( (string) $model );
		if ( 'openai' === $provider ) { return (bool) preg_match( '/^(gpt-(\d+)(\.\d+)?(-[a-z]+)?|o\d(-mini)?)$/', $model, $m ) && ( ! isset( $m[2] ) || '' === $m[2] || (int) $m[2] >= 5 ); }
		if ( 'claude' === $provider ) {
			if ( ! preg_match( '/^claude-(opus|sonnet|haiku|fable|mythos)-(\d+)(?:-(\d{1,2}))?(?:-\d{8})?$/', $model, $m ) ) { return false; }
			$version = (int) $m[2] * 10 + ( isset( $m[3] ) ? (int) $m[3] : 0 );
			if ( 'haiku' === $m[1] ) { return $version >= 50; }
			return 'opus' === $m[1] ? $version >= 45 : $version >= 46;
		}
		return 'gemini' === $provider;
	}

	/** Reads one answer back, whatever provider and kind of call produced it. */
	public static function read( array $plan, array $result ) {
		$provider = $plan['provider'];
		$model = $plan['model'];
		if ( 200 !== $result['status'] ) {
			$label = in_array( $plan['kind'], array( 'judge', 'vision' ), true ) ? $plan['kind'] . ' ' : '';
			return array( 'error' => $label . 'HTTP ' . $result['status'] . ': ' . substr( $result['raw'], 0, 240 ), 'seconds' => $result['seconds'], 'usage' => array() );
		}
		$body = json_decode( $result['raw'], true );

		if ( 'image' === $plan['kind'] ) {
			$binary = base64_decode( (string) ( $body['data'][0]['b64_json'] ?? '' ), true );
			if ( false === $binary || '' === $binary ) { return array( 'error' => 'no image payload returned', 'seconds' => $result['seconds'] ); }
			file_put_contents( $plan['destination'], $binary );
			return array( 'path' => $plan['destination'], 'bytes' => strlen( $binary ), 'seconds' => $result['seconds'], 'usage' => $body['usage'] ?? array(), 'model' => $body['model'] ?? $model );
		}

		$text = '';
		if ( 'openai' === $provider ) {
			if ( isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) { $text = $body['output_text']; }
			else {
				foreach ( (array) ( $body['output'] ?? array() ) as $item ) {
					foreach ( (array) ( $item['content'] ?? array() ) as $content ) { if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $text .= $content['text']; } }
				}
			}
			// cached_tokens is what the provider reused from an identical prompt; it is
			// billed at a discount, so it is the number that says whether caching works.
			$usage = array( 'input_tokens' => (int) ( $body['usage']['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ), 'cached_input_tokens' => (int) ( $body['usage']['input_tokens_details']['cached_tokens'] ?? 0 ) );
			$reasoning = (int) ( $body['usage']['output_tokens_details']['reasoning_tokens'] ?? 0 );
			if ( $reasoning ) { $usage['thinking_tokens'] = $reasoning; }
			$searches = 0;
			foreach ( (array) ( $body['output'] ?? array() ) as $item ) { if ( 'web_search_call' === ( $item['type'] ?? '' ) ) { $searches++; } }
			if ( $searches ) { $usage['web_searches'] = $searches; }
			$status = $body['status'] ?? '';
			$model = $body['model'] ?? $model;
		} elseif ( 'gemini' === $provider ) {
			foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) { if ( isset( $part['text'] ) ) { $text .= $part['text']; } }
			$meta = $body['usageMetadata'] ?? array();
			// Google bills thinking as output and whatever a tool fetched as input,
			// and reports neither inside the two headline counts: gemini-3.6-flash
			// answered five visible tokens after 2 717 of thinking, and a url_context
			// call read 8 973 tokens of page. Leaving them out priced both at almost
			// nothing.
			$usage = array(
				'input_tokens' => (int) ( $meta['promptTokenCount'] ?? 0 ) + (int) ( $meta['toolUsePromptTokenCount'] ?? 0 ),
				'output_tokens' => (int) ( $meta['candidatesTokenCount'] ?? 0 ) + (int) ( $meta['thoughtsTokenCount'] ?? 0 ),
			);
			if ( ! empty( $meta['cachedContentTokenCount'] ) ) { $usage['cached_input_tokens'] = (int) $meta['cachedContentTokenCount']; }
			if ( ! empty( $meta['thoughtsTokenCount'] ) ) { $usage['thinking_tokens'] = (int) $meta['thoughtsTokenCount']; }
			$searches = count( (array) ( $body['candidates'][0]['groundingMetadata']['webSearchQueries'] ?? array() ) );
			if ( $searches ) { $usage['web_searches'] = $searches; }
			$status = $body['candidates'][0]['finishReason'] ?? '';
		} else {
			foreach ( (array) ( $body['content'] ?? array() ) as $block ) { if ( 'text' === ( $block['type'] ?? '' ) ) { $text .= $block['text']; } }
			if ( 'text' === $plan['kind'] ) { $text = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', trim( $text ) ); }
			// Cache writes and reads are input too; Anthropic reports them apart.
			$cached = (int) ( $body['usage']['cache_read_input_tokens'] ?? 0 );
			$usage = array(
				'input_tokens' => (int) ( $body['usage']['input_tokens'] ?? 0 ) + (int) ( $body['usage']['cache_creation_input_tokens'] ?? 0 ) + $cached,
				'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ),
			);
			if ( $cached ) { $usage['cached_input_tokens'] = $cached; }
			$searches = (int) ( $body['usage']['server_tool_use']['web_search_requests'] ?? 0 );
			if ( $searches ) { $usage['web_searches'] = $searches; }
			$status = $body['stop_reason'] ?? '';
			$model = $body['model'] ?? $model;
		}
		return array( 'text' => self::utf8( $text ), 'usage' => $usage, 'model' => $model, 'status' => $status, 'seconds' => $result['seconds'] );
	}

	/**
	 * The wire settings a caller that passed none would have got. It keeps every
	 * entry point callable on its own — a test, an experiment — without making
	 * the engine's own calls depend on anything written here.
	 */
	private static function shipped_wire( $provider, $model = '' ) {
		return MSRWA_Engine_Config::create()->provider( $provider, $model );
	}




	/** Generates one OpenAI image and writes it to the lab runs directory. */
	public static function image( $prompt, $model, $size, $quality, $output_format, $destination, $wire = array(), $provider = 'openai' ) {
		$plan = self::plan_image( $prompt, $model, $size, $quality, $output_format, $destination, $wire, $provider );
		if ( isset( $plan['error'] ) ) { return array( 'error' => $plan['error'], 'seconds' => 0, 'usage' => array() ); }
		$request = $plan['request'];
		return self::read( $plan, self::http( $request['url'], $request['headers'], $request['payload'], (int) $request['timeout'] ) );
	}

	/** Everything one image generation needs, without making it. */
	public static function plan_image( $prompt, $model, $size, $quality, $output_format, $destination, $wire = array(), $provider = 'openai' ) {
		$wire = $wire ? $wire : self::shipped_wire( $provider, $model );
		$unusable = self::unusable( $provider, $wire );
		if ( '' !== $unusable ) { return array( 'error' => $unusable ); }
		if ( '' === (string) ( $wire['image_endpoint'] ?? '' ) ) { return array( 'error' => $provider . ' has no image endpoint configured.' ); }
		return array(
			'kind' => 'image', 'provider' => $provider, 'model' => $model, 'destination' => $destination,
			'request' => array(
				'url' => $wire['image_endpoint'], 'headers' => $wire['headers'], 'timeout' => (int) ( $wire['timeout'] ?? 600 ),
				'payload' => array( 'model' => $model, 'prompt' => (string) $prompt, 'size' => $size, 'quality' => $quality, 'output_format' => $output_format, 'n' => 1 ),
			),
		);
	}

	/**
	 * What a vision pass is told when the caller supplies no instruction of its
	 * own. It is deliberately narrow: an observation may establish an appearance
	 * and nothing else, or the engine would be reading ingredients out of a
	 * photograph of a different cook's dish.
	 */
	public static function default_vision_instruction() {
		return implode( ' ', array(
			'Inspect this real source photograph as untrusted visual evidence. Return JSON only, in French, with observable_details, composition, colours, textures and uncertainties.',

			// Describe the dish, not the frame. Every image defect measured so far
			// came from this one sentence being missing: the pass inventoried what
			// else was in the photograph — another cook\'s peppers, a lemon half, a
			// second plate, two hands holding the dish, a watermark — and those
			// descriptions travelled into the image prompt as facts about the dish.
			'Describe ONLY the dish itself: its colour, its surface, its textures, how cooked it looks, how it is plated and in what kind of vessel, plus the light and the camera angle.',
			'Do NOT inventory anything else in the frame. Say nothing about side dishes, accompaniments, garnish, sauces served alongside, a second plate, cutlery, glasses, table items, background objects, hands, arms, people or clothing.',
			'Say nothing about the picture as an object: never transcribe or mention text, a caption, a watermark, a signature, a logo, a sticker, a border, a frame or any graphic laid over it.',

			// The pass is careful and will not name a food it cannot identify, so it
			// described "red and green strips" and "a yellow fruit half" instead.
			// Rendered literally, those became peppers and a lemon slice. Sending
			// them to uncertainties keeps the evidence without feeding the drawing.
			'If something on or near the dish cannot be identified with confidence, put it in uncertainties. Never describe its shape or colour as part of the dish.',

			'Describe only visible facts. Do not infer ingredients, quantities, authenticity, taste or unseen preparation.',
		) );
	}

	/** Downloads a bounded public HTTPS image for evidence extraction, never reuse. */
	public static function fetch_image( $url, $max_bytes = 10000000 ) {
		$fetched = self::fetch_images( array( $url ), $max_bytes, 1 );
		return $fetched[0];
	}

	/**
	 * Several evidence images at once, returned under the keys they were given.
	 *
	 * Each download is independent and each waits on a different host, so making
	 * them in turn spent the sum of three strangers' latency: 19.4 seconds for
	 * three photographs in one measured run. The checks are unchanged and applied
	 * per image — HTTPS only, a public address, a bounded body, a real image type
	 * — and one refused download never disturbs the others.
	 */
	public static function fetch_images( array $urls, $max_bytes = 10000000, $limit = 4 ) {
		$results = array();
		$bytes = array();
		$multi = curl_multi_init();
		$handles = array();

		foreach ( $urls as $key => $url ) {
			$refused = self::unfetchable( $url );
			if ( '' !== $refused ) { $results[ $key ] = array( 'error' => $refused ); continue; }
			if ( count( $handles ) >= max( 1, (int) $limit ) ) { $results[ $key ] = array( 'error' => 'too many images requested at once' ); continue; }
			$bytes[ $key ] = '';
			$handle = curl_init( $url );
			curl_setopt_array( $handle, array(
				CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
				CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_USERAGENT => 'MSRWA-Prompt-Lab/1.0',
				CURLOPT_WRITEFUNCTION => static function ( $ignored, $chunk ) use ( &$bytes, $key, $max_bytes ) {
					if ( strlen( $bytes[ $key ] ) + strlen( $chunk ) > $max_bytes ) { return 0; }
					$bytes[ $key ] .= $chunk;
					return strlen( $chunk );
				},
			) );
			curl_multi_add_handle( $multi, $handle );
			$handles[ $key ] = $handle;
		}

		if ( $handles ) {
			do {
				$status = curl_multi_exec( $multi, $running );
				if ( $running ) { curl_multi_select( $multi, 1.0 ); }
			} while ( $running && CURLM_OK === $status );
		}

		foreach ( $handles as $key => $handle ) {
			$results[ $key ] = self::downloaded( $handle, $bytes[ $key ] );
			curl_multi_remove_handle( $multi, $handle );
			curl_close( $handle );
		}
		curl_multi_close( $multi );
		return $results;
	}

	/** Why an image URL is not worth opening a connection for, or '' when it is. */
	private static function unfetchable( $url ) {
		if ( ! preg_match( '#^https://#i', (string) $url ) ) { return 'image URL is not HTTPS'; }
		$host = (string) parse_url( $url, PHP_URL_HOST );
		$ip = gethostbyname( $host );
		if ( '' === $host || $ip === $host || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return 'image host is not public'; }
		return '';
	}

	/** What one finished download turned out to be: usable bytes, or the reason not. */
	private static function downloaded( $handle, $bytes ) {
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$error = (string) curl_error( $handle );
		if ( 200 !== $status || '' !== $error ) { return array( 'error' => $error ? $error : 'image HTTP ' . $status ); }
		$mime = trim( strtok( strtolower( trim( (string) curl_getinfo( $handle, CURLINFO_CONTENT_TYPE ) ) ), ';' ) );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) { return array( 'error' => 'unsupported image type ' . $mime ); }
		if ( '' === $bytes ) { return array( 'error' => 'image body was empty or over the size limit' ); }
		return array( 'mime' => $mime, 'data' => base64_encode( $bytes ) );
	}

	/** Inspects fetched image bytes; the same evidence prompt is used across providers. */
	public static function vision( $provider, $model, $image, $context, $max_tokens = 900, $wire = array(), $instruction = '' ) {
		$plan = self::plan_vision( $provider, $model, $image, $context, $max_tokens, $wire, $instruction );
		if ( isset( $plan['error'] ) ) { return array( 'error' => $plan['error'], 'seconds' => 0, 'usage' => array() ); }
		$request = $plan['request'];
		return self::read( $plan, self::http( $request['url'], $request['headers'], $request['payload'], (int) $request['timeout'] ) );
	}

	/** Everything one image observation needs, without asking yet, so a set of them can go out together. */
	public static function plan_vision( $provider, $model, $image, $context, $max_tokens = 900, $wire = array(), $instruction = '' ) {
		$wire = $wire ? $wire : self::shipped_wire( $provider, $model );
		$unusable = self::unusable( $provider, $wire );
		if ( '' !== $unusable ) { return array( 'error' => $unusable ); }
		$instruction = ( '' !== trim( (string) $instruction ) ? $instruction : self::default_vision_instruction() ) . ' Context: ' . $context;

		if ( 'openai' === $provider ) {
			$payload = array( 'model' => $model, 'store' => false, 'max_output_tokens' => $max_tokens, 'input' => array( array( 'role' => 'user', 'content' => array( array( 'type' => 'input_text', 'text' => $instruction ), array( 'type' => 'input_image', 'image_url' => 'data:' . $image['mime'] . ';base64,' . $image['data'] ) ) ) ), 'text' => array( 'format' => array( 'type' => 'json_object' ) ) );
		} elseif ( 'gemini' === $provider ) {
			$payload = array( 'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $instruction ), array( 'inline_data' => array( 'mime_type' => $image['mime'], 'data' => $image['data'] ) ) ) ) ), 'generationConfig' => self::gemini_generation( $wire, $max_tokens ) + array( 'responseMimeType' => 'application/json' ) );
		} else {
			$payload = array( 'model' => $model, 'max_tokens' => $max_tokens, 'messages' => array( array( 'role' => 'user', 'content' => array( array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data'] ) ), array( 'type' => 'text', 'text' => $instruction ) ) ) ) );
		}

		$payload = self::think( $provider, $model, $payload, $wire );
		return array(
			'kind' => 'vision', 'provider' => $provider, 'model' => $model,
			'request' => array( 'url' => $wire['text_endpoint'], 'headers' => $wire['headers'], 'payload' => $payload, 'timeout' => (int) ( $wire['timeout'] ?? 600 ) ),
		);
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
	public static function judge( $provider, $model, $instruction, $images, $max_tokens = 2500, $wire = array() ) {
		$plan = self::plan_judge( $provider, $model, $instruction, $images, $max_tokens, $wire );
		if ( isset( $plan['error'] ) ) { return array( 'error' => $plan['error'], 'seconds' => 0, 'usage' => array() ); }
		$request = $plan['request'];
		return self::read( $plan, self::http( $request['url'], $request['headers'], $request['payload'], (int) $request['timeout'] ) );
	}

	/** Everything the judge needs to see the article and both images at once, without asking yet. */
	public static function plan_judge( $provider, $model, $instruction, $images, $max_tokens = 2500, $wire = array() ) {
		$wire = $wire ? $wire : self::shipped_wire( $provider, $model );
		$unusable = self::unusable( $provider, $wire );
		if ( '' !== $unusable ) { return array( 'error' => $unusable ); }

		if ( 'openai' === $provider ) {
			$content = array( array( 'type' => 'input_text', 'text' => $instruction ) );
			foreach ( $images as $image ) {
				$content[] = array( 'type' => 'input_text', 'text' => 'IMAGE — ' . $image['label'] );
				$content[] = array( 'type' => 'input_image', 'image_url' => 'data:' . $image['mime'] . ';base64,' . $image['data'] );
			}
			$payload = array( 'model' => $model, 'store' => false, 'max_output_tokens' => $max_tokens, 'input' => array( array( 'role' => 'user', 'content' => $content ) ), 'text' => array( 'format' => array( 'type' => 'json_object' ) ) );
		} elseif ( 'gemini' === $provider ) {
			$parts = array( array( 'text' => $instruction ) );
			foreach ( $images as $image ) {
				$parts[] = array( 'text' => 'IMAGE — ' . $image['label'] );
				$parts[] = array( 'inline_data' => array( 'mime_type' => $image['mime'], 'data' => $image['data'] ) );
			}
			$payload = array( 'contents' => array( array( 'role' => 'user', 'parts' => $parts ) ), 'generationConfig' => self::gemini_generation( $wire, $max_tokens ) + array( 'responseMimeType' => 'application/json' ) );
		} else {
			$content = array();
			foreach ( $images as $image ) {
				$content[] = array( 'type' => 'text', 'text' => 'IMAGE — ' . $image['label'] );
				$content[] = array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data'] ) );
			}
			$content[] = array( 'type' => 'text', 'text' => $instruction . "\n\nReturn only a valid JSON object, with no Markdown fence and no commentary." );
			$payload = array( 'model' => $model, 'max_tokens' => $max_tokens, 'messages' => array( array( 'role' => 'user', 'content' => $content ) ) );
		}

		$payload = self::think( $provider, $model, $payload, $wire );
		return array(
			'kind' => 'judge', 'provider' => $provider, 'model' => $model,
			'request' => array( 'url' => $wire['text_endpoint'], 'headers' => $wire['headers'], 'payload' => $payload, 'timeout' => (int) ( $wire['timeout'] ?? 600 ) ),
		);
	}

	/**
	 * Replaces search-model guesses with observations made from the cited bytes.
	 *
	 * The photographs have nothing to do with one another, so they are downloaded
	 * together and then described together: two waves of concurrent calls instead
	 * of six in a row. The evidence and its cost are identical; only the waiting
	 * is gone.
	 */
	public static function observe_images( $provider, $model, $package, $limit = 3, $wire = array(), $max_bytes = 10000000, $instruction = '' ) {
		$package['visual_observations'] = array();
		$usage = array( 'input_tokens' => 0, 'output_tokens' => 0 );
		$references = array_slice( (array) ( $package['visual_references'] ?? array() ), 0, $limit );
		if ( ! $references ) { return array( 'package' => $package, 'usage' => $usage ); }

		$images = self::fetch_images( array_map( static function ( $reference ) { return (string) ( $reference['image_url'] ?? '' ); }, $references ), $max_bytes, max( 1, (int) $limit ) );

		$plans = array();
		$requests = array();
		foreach ( $references as $key => $reference ) {
			$image = (array) ( $images[ $key ] ?? array( 'error' => 'image was not fetched' ) );
			if ( isset( $image['error'] ) ) { $package['uncertainties'][] = 'Image non analysée : ' . $image['error']; continue; }
			$plan = self::plan_vision( $provider, $model, $image, (string) ( $reference['title'] ?? '' ), 900, $wire, $instruction );
			if ( isset( $plan['error'] ) ) { $package['uncertainties'][] = 'Image non analysée : ' . $plan['error']; continue; }
			$plans[ $key ] = $plan;
			$requests[ $key ] = $plan['request'];
		}

		$answers = self::http_many( $requests, max( 1, (int) $limit ) );
		foreach ( $plans as $key => $plan ) {
			$reference = $references[ $key ];
			$vision = self::read( $plan, $answers[ $key ] );
			$usage['input_tokens'] += (int) ( $vision['usage']['input_tokens'] ?? 0 );
			$usage['output_tokens'] += (int) ( $vision['usage']['output_tokens'] ?? 0 );
			$observed = MSRWA_Json::decode( (string) ( $vision['text'] ?? '' ) );
			if ( ! is_array( $observed ) ) { $package['uncertainties'][] = 'Analyse visuelle non structurée pour ' . (string) ( $reference['source_url'] ?? '' ); continue; }
			$package['visual_observations'][] = array_merge( array( 'image_url' => $reference['image_url'] ?? '', 'source_url' => $reference['source_url'] ?? '' ), $observed );
		}
		return array( 'package' => $package, 'usage' => $usage );
	}
}
