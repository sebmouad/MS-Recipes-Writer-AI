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

msrwa_test_done( 'key check' );
