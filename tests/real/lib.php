<?php
/**
 * Helpers for the real test layer.
 *
 * Real tests drive a live WordPress over its REST API with an application
 * password, so they need no shell access on the site. Credentials come from
 * the environment; nothing is ever read from the repository.
 */

$GLOBALS['msrwa_real_failures'] = array();
$GLOBALS['msrwa_real_spent'] = 0.0;

function msrwa_real_env( $name, $required = true ) {
	$value = (string) getenv( $name );
	if ( '' === $value && $required ) { msrwa_real_skip( $name . ' is not set' ); }
	return $value;
}

/** Ends the test as skipped: a missing prerequisite is not a failure. */
function msrwa_real_skip( $reason ) {
	echo 'SKIP ' . $reason . "\n";
	exit( 0 );
}

function msrwa_real_site() { return rtrim( msrwa_real_env( 'MSRWA_TEST_SITE_URL' ), '/' ); }

/** One authenticated REST call. Returns array( status, body, headers ). */
function msrwa_real_request( $method, $path, $body = null, $timeout = 90 ) {
	$url = 0 === strpos( $path, 'http' ) ? $path : msrwa_real_site() . '/wp-json' . $path;
	$auth = msrwa_real_env( 'MSRWA_WP_USER' ) . ':' . msrwa_real_env( 'MSRWA_WP_APP_PASSWORD' );
	$command = 'curl -sS --max-time ' . (int) $timeout . ' -o /dev/stdout -w "\n%{http_code}" -X ' . escapeshellarg( strtoupper( $method ) );
	$command .= ' -u ' . escapeshellarg( $auth ) . ' -H ' . escapeshellarg( 'Accept: application/json' );
	if ( null !== $body ) { $command .= ' -H ' . escapeshellarg( 'Content-Type: application/json' ) . ' -d ' . escapeshellarg( wp_json_encode_compat( $body ) ); }
	$command .= ' ' . escapeshellarg( $url ) . ' 2>/dev/null';
	$raw = (string) shell_exec( $command );
	$split = strrpos( $raw, "\n" );
	$status = (int) substr( $raw, $split + 1 );
	$payload = json_decode( substr( $raw, 0, max( 0, $split ) ), true );
	return array( 'status' => $status, 'body' => $payload, 'raw' => substr( $raw, 0, max( 0, $split ) ) );
}

/** An unauthenticated call, to prove a route is not public. */
function msrwa_real_anonymous( $method, $path, $timeout = 60 ) {
	$url = msrwa_real_site() . '/wp-json' . $path;
	$command = 'curl -sS --max-time ' . (int) $timeout . ' -o /dev/null -w "%{http_code}" -X ' . escapeshellarg( strtoupper( $method ) ) . ' ' . escapeshellarg( $url ) . ' 2>/dev/null';
	return (int) shell_exec( $command );
}

function wp_strip_all_tags_compat( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }

function wp_json_encode_compat( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }

/** Refuses to spend past the cap the owner set for one run. */
function msrwa_real_budget() {
	$cap = (float) msrwa_real_env( 'MSRWA_TEST_BUDGET_USD' );
	if ( $cap <= 0 ) { msrwa_real_skip( 'MSRWA_TEST_BUDGET_USD must be a positive amount' ); }
	return $cap;
}

function msrwa_real_spend( $amount ) {
	$GLOBALS['msrwa_real_spent'] += max( 0, (float) $amount );
	$cap = (float) getenv( 'MSRWA_TEST_BUDGET_USD' );
	if ( $cap > 0 && $GLOBALS['msrwa_real_spent'] > $cap ) {
		msrwa_real_fail( sprintf( 'Run exceeded its budget: %.4f spent, cap %.4f.', $GLOBALS['msrwa_real_spent'], $cap ) );
		msrwa_real_done( 'budget exceeded' );
	}
}

function msrwa_real_assert( $condition, $message ) {
	if ( ! $condition ) { $GLOBALS['msrwa_real_failures'][] = $message; }
	return (bool) $condition;
}

function msrwa_real_fail( $message ) { $GLOBALS['msrwa_real_failures'][] = $message; }

function msrwa_real_note( $message ) { echo '      · ' . $message . "\n"; }

/** Polls until the callback returns true or the deadline passes. */
function msrwa_real_wait( callable $ready, $seconds = 600, $interval = 10 ) {
	$deadline = time() + max( 1, (int) $seconds );
	while ( time() < $deadline ) {
		if ( $ready() ) { return true; }
		sleep( max( 1, (int) $interval ) );
	}
	return false;
}

function msrwa_real_done( $label ) {
	if ( $GLOBALS['msrwa_real_spent'] > 0 ) { echo sprintf( "      · spent %.4f USD\n", $GLOBALS['msrwa_real_spent'] ); }
	if ( empty( $GLOBALS['msrwa_real_failures'] ) ) { echo $label . " OK\n"; exit( 0 ); }
	foreach ( $GLOBALS['msrwa_real_failures'] as $failure ) { fwrite( STDERR, 'FAIL: ' . $failure . "\n" ); }
	exit( 1 );
}
