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

msrwa_test_done( 'key check' );
