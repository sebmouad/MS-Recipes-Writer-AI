<?php
// Proves the plugin is installed, in the state it expects to be in, and closed
// to the public. Costs nothing: it calls no provider.
require __DIR__ . '/lib.php';

$index = msrwa_real_request( 'GET', '/?cb=' . mt_rand() );
msrwa_real_assert( 200 === $index['status'], 'The site REST index must answer (got ' . $index['status'] . ').' );
$namespaces = (array) ( $index['body']['namespaces'] ?? array() );
msrwa_real_assert( in_array( 'msrwa/v1', $namespaces, true ), 'The plugin must be active: msrwa/v1 missing from the REST index.' );

$routes = array_keys( (array) ( $index['body']['routes'] ?? array() ) );
foreach ( array( '/msrwa/v1/health', '/msrwa/v1/estimate', '/msrwa/v1/batches', '/msrwa/v1/runs/bulk' ) as $route ) {
	msrwa_real_assert( in_array( $route, $routes, true ), 'Route ' . $route . ' must be registered.' );
}

// --- The installation is in the state the code expects ------------------

$health = msrwa_real_request( 'GET', '/msrwa/v1/health' );
msrwa_real_assert( 200 === $health['status'], 'Health must answer for an administrator (got ' . $health['status'] . ').' );
$state = (array) $health['body'];

msrwa_real_assert(
	(int) ( $state['schema'] ?? 0 ) === (int) ( $state['expected_schema'] ?? -1 ),
	'The schema must be up to date: stored ' . ( $state['schema'] ?? '?' ) . ', expected ' . ( $state['expected_schema'] ?? '?' ) . '. Deactivate and reactivate the plugin.'
);
msrwa_real_note( 'version ' . ( $state['version'] ?? '?' ) . ', schema ' . ( $state['schema'] ?? '?' ) );

// The migration is the part no offline test can reach, so every table and
// every added column is named rather than assumed.
foreach ( (array) ( $state['tables'] ?? array() ) as $name => $exists ) {
	msrwa_real_assert( $exists, 'Table ' . $name . ' must exist.' );
}
foreach ( (array) ( $state['columns'] ?? array() ) as $column => $exists ) {
	msrwa_real_assert( $exists, 'Column ' . $column . ' must exist; the guarded ALTER did not run.' );
}

// Capabilities are granted on activation, and a role missing one silently
// locks somebody out of a screen.
foreach ( array( 'administrator' => 3, 'editor' => 1, 'author' => 1 ) as $role => $expected ) {
	$granted = (array) ( $state['roles'][ $role ] ?? array() );
	msrwa_real_assert( count( $granted ) === $expected, $role . ' must hold ' . $expected . ' capability(ies); has ' . implode( ', ', $granted ) . '.' );
}

if ( empty( $state['uploads_writable'] ) ) { msrwa_real_fail( 'The uploads directory is not writable: no generated image can be saved.' ); }
if ( empty( $state['providers'] ) ) { msrwa_real_note( 'no API key stored — nothing can be generated yet' ); }
else { msrwa_real_note( 'keys for: ' . implode( ', ', (array) $state['providers'] ) ); }

if ( ! empty( $state['cron']['wp_cron_disabled'] ) ) {
	msrwa_real_note( 'DISABLE_WP_CRON is set — a server cron must call wp-cron.php or nothing advances' );
}
msrwa_real_assert( ! empty( $state['cron']['next'] ), 'Maintenance must be scheduled, or expired leases are never recovered.' );
if ( ! empty( $state['dormant_tables'] ) ) {
	msrwa_real_note( count( (array) $state['dormant_tables'] ) . ' dormant table(s) from the previous plugin, left untouched' );
}

// --- An estimate can be built without spending anything ------------------

$estimate = msrwa_real_request( 'GET', '/msrwa/v1/estimate?profile=full&recipes=1&images=0' );
msrwa_real_assert( 200 === $estimate['status'], 'An estimate must be available before anything is spent.' );
msrwa_real_assert( (float) ( $estimate['body']['cost_usd'] ?? 0 ) > 0, 'An estimate of zero means no route is priced.' );
msrwa_real_assert( empty( $estimate['body']['unpriced'] ), 'Every configured route must be priced; unpriced: ' . implode( ', ', (array) ( $estimate['body']['unpriced'] ?? array() ) ) );
msrwa_real_note( 'one full recipe is estimated at $' . number_format( (float) $estimate['body']['cost_usd'], 4 ) );

// --- Whether the stored keys open their providers (free: lists models) --------

$keys = msrwa_real_request( 'POST', '/msrwa/v1/keys/check' );
msrwa_real_assert( 200 === $keys['status'], 'The key check must answer an administrator (got ' . $keys['status'] . ').' );
foreach ( (array) $keys['body'] as $provider => $verdict ) {
	msrwa_real_assert( false === strpos( wp_json_encode_compat( $verdict ), 'sk-' ), 'The key check must never echo a key.' );
	msrwa_real_note( $provider . ': ' . ( $verdict['state'] ?? '?' ) );
	if ( 'refused' === ( $verdict['state'] ?? '' ) ) { msrwa_real_fail( $provider . ' refuses the stored key.' ); }
}

// --- A lot that cannot finish under its ceiling is refused, free -------------

// No photographs, so creating the lot calls no provider; dispatch is refused
// before any run exists. Nothing here spends.
$tight = msrwa_real_request( 'POST', '/msrwa/v1/batches', array( 'recipes' => "Test de plafond\nNe doit jamais partir.", 'images' => '', 'budget' => 0.001, 'profile' => 'article', 'language' => 'fr' ) );
if ( 200 === $tight['status'] && ! empty( $tight['body']['id'] ) ) {
	$refused = msrwa_real_request( 'POST', '/msrwa/v1/batches/' . (int) $tight['body']['id'] . '/dispatch' );
	msrwa_real_assert( $refused['status'] >= 400, 'A lot estimated above its per-recipe ceiling must be refused at dispatch (got ' . $refused['status'] . ').' );
	msrwa_real_assert( 'msrwa_over_ceiling' === (string) ( $refused['body']['code'] ?? '' ), 'The refusal must be the per-recipe ceiling, not something else (got ' . (string) ( $refused['body']['code'] ?? '' ) . ').' );
	msrwa_real_request( 'DELETE', '/msrwa/v1/batches/' . (int) $tight['body']['id'] );
} else {
	msrwa_real_fail( 'A lot without photographs must be accepted (got ' . $tight['status'] . ').' );
}

// --- Nothing here answers the public -------------------------------------

foreach ( array( '/msrwa/v1/health', '/msrwa/v1/estimate', '/msrwa/v1/batches', '/msrwa/v1/keys/check' ) as $route ) {
	$anonymous = msrwa_real_anonymous( 'GET', $route );
	msrwa_real_assert(
		in_array( $anonymous['status'], array( 401, 403, 404 ), true ),
		$route . ' must refuse an anonymous caller (got ' . $anonymous['status'] . ').'
	);
}

msrwa_real_done( 'the site is installed and closed' );
