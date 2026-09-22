<?php
// "Key stored" says a string was saved; this says whether it opens the door.
// The reading of a provider's answer is what can go wrong offline, so it is
// what is tested: a refused key must never read as a network problem, and a
// firewall must never read as a bad key.
require __DIR__ . '/bootstrap.php';
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0; }
}
msrwa_test_load( 'keys' );

$answer = static function ( $code ) { return array( 'response' => array( 'code' => $code ) ); };
msrwa_test_assert( 'ok' === MSRWA_Keys::verdict( $answer( 200 ) )['state'], 'A listed model means the key works.' );
foreach ( array( 400, 401, 403 ) as $code ) {
	msrwa_test_assert( 'refused' === MSRWA_Keys::verdict( $answer( $code ) )['state'], $code . ' is the provider refusing the key.' );
}
msrwa_test_assert( 'ok' === MSRWA_Keys::verdict( $answer( 429 ) )['state'], 'A quota answer still proves the key is accepted.' );
msrwa_test_assert( 'unreachable' === MSRWA_Keys::verdict( $answer( 503 ) )['state'], 'A provider outage is not a bad key.' );
msrwa_test_assert( 'unreachable' === MSRWA_Keys::verdict( new WP_Error( 'http_request_failed', 'x' ) )['state'], 'No connection is not a bad key either.' );

$probes = MSRWA_Keys::probes();
msrwa_test_assert( array( 'openai', 'gemini', 'claude' ) === array_keys( $probes ), 'One probe per provider, named the engine’s way.' );
foreach ( $probes as $provider => $probe ) {
	msrwa_test_assert( 0 === strpos( $probe['url'], 'https://' ), $provider . ' is probed over HTTPS only.' );
	$headers = call_user_func( $probe['headers'], 'secret-value' );
	msrwa_test_assert( in_array( true, array_map( static function ( $value ) { return false !== strpos( $value, 'secret-value' ); }, $headers ), true ), $provider . ' carries its key in a header.' );
	msrwa_test_missing( $probe['url'], 'secret', $provider . ' never carries its key in the URL, where logs would keep it.' );
}

// --- What the provider said on a real call -------------------------------

// The probe cannot see any of this: an account with no credit lists models
// perfectly happily, so a green verdict was reported for an account that could
// not pay for a single word, twice, against two different providers.
msrwa_test_assert( 'credit' === MSRWA_Keys::refusal( 'HTTP 400: {"error":{"message":"Your credit balance is too low to access the Anthropic API."}}' ), 'An empty Anthropic account reads as a credit problem.' );
msrwa_test_assert( 'credit' === MSRWA_Keys::refusal( 'HTTP 429: {"error":{"code":"insufficient_quota"}}' ), 'And so does an OpenAI account with no balance.' );
msrwa_test_assert( 'credit' === MSRWA_Keys::refusal( 'billing_not_active' ), 'And an account that was never activated.' );

// Google says "check your plan and billing details" while the account is fully
// funded. Reading that as an empty wallet sent an administrator to top up one
// that was already full, so it must stay a quota answer.
msrwa_test_assert( 'quota' === MSRWA_Keys::refusal( 'HTTP 429: {"error":{"code":429,"message":"You exceeded your current quota, please check your plan and billing details."}}' ), 'Google’s exhausted quota is a quota problem, not an empty wallet.' );
msrwa_test_assert( 'quota' === MSRWA_Keys::refusal( 'RESOURCE_EXHAUSTED' ), 'And so is a bare RESOURCE_EXHAUSTED.' );
msrwa_test_assert( '' === MSRWA_Keys::refusal( 'HTTP 500: upstream timed out' ), 'A provider outage says nothing about the account.' );
msrwa_test_assert( '' === MSRWA_Keys::refusal( '' ), 'And neither does silence.' );

// Only a green verdict is corrected: a refused key is the more urgent news,
// and an unreachable provider has told us nothing about its billing.
$ok = array( 'state' => 'ok', 'message' => 'clé acceptée' );
msrwa_test_assert( 'blocked' === MSRWA_Keys::temper( $ok, 'credit' )['state'], 'A key that works on an account that cannot pay is not reported green.' );
msrwa_test_assert( 'blocked' === MSRWA_Keys::temper( $ok, 'quota' )['state'], 'Nor is one whose quota is spent.' );
msrwa_test_assert( $ok === MSRWA_Keys::temper( $ok, '' ), 'With nothing recorded against it, the probe’s verdict stands.' );
foreach ( array( 'refused', 'unreachable', 'missing' ) as $state ) {
	$verdict = array( 'state' => $state, 'message' => 'x' );
	msrwa_test_assert( $verdict === MSRWA_Keys::temper( $verdict, 'credit' ), 'A ' . $state . ' verdict is left as it is.' );
}
msrwa_test_assert(
	false === strpos( MSRWA_Keys::temper( $ok, 'credit' )['message'], 'clé acceptée' ),
	'And it stops claiming the key was accepted.'
);

// --- The list the probe downloads, which used to be thrown away ----------

// Three providers, three shapes. These are the real ones, trimmed: Gemini
// prefixes every name with `models/`, which is not the identifier the engine
// routes to, and getting that wrong would mark every Gemini model unserved.
$openai = '{"object":"list","data":[{"id":"gpt-5.6-luna","object":"model"},{"id":"gpt-image-2.5-flare","object":"model"}]}';
msrwa_test_assert( array( 'gpt-5.6-luna', 'gpt-image-2.5-flare' ) === MSRWA_Keys::model_ids( 'openai', $openai ), 'OpenAI lists its models under data[].id.' );

$claude = '{"data":[{"type":"model","id":"claude-sonnet-5"},{"type":"model","id":"claude-opus-5"}],"has_more":false}';
msrwa_test_assert( array( 'claude-sonnet-5', 'claude-opus-5' ) === MSRWA_Keys::model_ids( 'claude', $claude ), 'Claude uses the same shape.' );

$gemini = '{"models":[{"name":"models/gemini-3.5-flash","displayName":"Gemini","inputTokenLimit":1048576,"outputTokenLimit":65536,"supportedGenerationMethods":["generateContent","countTokens"]},{"name":"models/gemini-2.5-pro"}]}';
msrwa_test_assert( array( 'gemini-3.5-flash', 'gemini-2.5-pro' ) === MSRWA_Keys::model_ids( 'gemini', $gemini ), 'Gemini lists under models[].name, and the models/ prefix is not part of the identifier.' );

// --- What each provider volunteers besides the name ----------------------

// None of the three gives a price — checked against all three live responses
// — which is why a rate is never fetched. What they do give is worth keeping.
$rows = MSRWA_Keys::models( 'gemini', $gemini );
msrwa_test_assert( 'Gemini' === $rows[0]['label'], 'Gemini names its models.' );
msrwa_test_assert( true === $rows[0]['capabilities']['text'], 'A model that supports generateContent can write.' );
msrwa_test_assert( 1048576 === $rows[0]['limits']['input_tokens'], 'And states how much it will read.' );
msrwa_test_assert( ! isset( $rows[1]['capabilities'] ), 'A model that states nothing carries nothing: silence must not be recorded as a denial.' );

$rich = '{"data":[{"type":"model","id":"claude-opus-5-5","display_name":"Claude Opus 5.5","max_input_tokens":1000000,"max_tokens":128000,"capabilities":{"image_input":{"supported":true},"thinking":{"supported":true}}}]}';
$rows = MSRWA_Keys::models( 'claude', $rich );
msrwa_test_assert( 'Claude Opus 5.5' === $rows[0]['label'], 'Anthropic names its models.' );
msrwa_test_assert( true === $rows[0]['capabilities']['vision'], 'And states outright whether one reads images.' );
msrwa_test_assert( 128000 === $rows[0]['limits']['output_tokens'], 'And how much it will write.' );

// OpenAI gives an identifier and little else, which must not be mistaken for
// a model that can do nothing.
$rows = MSRWA_Keys::models( 'openai', $openai );
msrwa_test_assert( ! isset( $rows[0]['capabilities'] ), 'A provider that states no capabilities leaves the catalogue’s own untouched.' );
msrwa_test_assert( 'gpt-5.6-luna' === $rows[0]['id'], 'But the identifier still comes through.' );

// Nothing usable must produce nothing, never a half-list: an empty answer is
// what stops the catalogue from forgetting everything it knew.
foreach ( array( '', 'not json at all', '[]', '{}', '{"data":[]}', '{"data":[{"object":"model"}]}', 'null' ) as $body ) {
	msrwa_test_assert( array() === MSRWA_Keys::model_ids( 'openai', $body ), 'An answer with no identifiers in it yields none.' );
}
msrwa_test_assert( array( 'gpt-5' ) === MSRWA_Keys::model_ids( 'openai', '{"data":[{"id":"gpt-5"},{"id":"gpt-5"},{"id":"  "}]}' ), 'Repeats and blanks are dropped.' );

// The probe must fetch the whole list, not the one row it needed when only the
// status code was read.
foreach ( MSRWA_Keys::probes() as $provider => $probe ) {
	msrwa_test_assert( 0 === preg_match( '/(limit|pageSize)=1\b/', $probe['url'] ), $provider . ' asks for the full list, not a single row.' );
}

msrwa_test_done( 'key check' );
