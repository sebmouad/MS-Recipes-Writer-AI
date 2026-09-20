<?php
// Proves the plugin is installed, reachable and closed to the public on the
// live site. Costs nothing: it calls no provider.
require __DIR__ . '/lib.php';

$index = msrwa_real_request( 'GET', '/?cb=' . mt_rand() );
msrwa_real_assert( 200 === $index['status'], 'The site REST index must answer (got ' . $index['status'] . ').' );
$namespaces = (array) ( $index['body']['namespaces'] ?? array() );
msrwa_real_assert( in_array( 'msrwa/v1', $namespaces, true ), 'The plugin must be active: msrwa/v1 missing from the REST index.' );

$routes = array_keys( (array) ( $index['body']['routes'] ?? array() ) );
$expected = array( '/msrwa/v1/batches', '/msrwa/v1/stats', '/msrwa/v1/events', '/msrwa/v1/export', '/msrwa/v1/catalog' );
foreach ( $expected as $route ) {
	msrwa_real_assert( in_array( $route, $routes, true ), 'Route ' . $route . ' must be registered.' );
}

// The catalogue must carry priced models, or no estimate can ever be built.
$catalog = msrwa_real_request( 'GET', '/msrwa/v1/catalog' );
msrwa_real_assert( 200 === $catalog['status'], 'The catalogue must be readable by an administrator.' );
$models = (array) ( $catalog['body']['models'] ?? array() );
$count = 0; $priced = 0;
foreach ( $models as $provider_models ) {
	foreach ( (array) $provider_models as $model ) {
		$count++;
		if ( ! empty( $model['input'] ) && ! empty( $model['output'] ) ) { $priced++; }
	}
}
msrwa_real_assert( $count > 0, 'The catalogue must contain models.' );
msrwa_real_assert( $priced === $count, 'Every catalogue model must carry input and output prices (' . $priced . '/' . $count . ').' );
msrwa_real_note( $count . ' models, all priced' );

// Statistics answer with the documented shape.
$stats = msrwa_real_request( 'GET', '/msrwa/v1/stats?days=7' );
msrwa_real_assert( 200 === $stats['status'], 'Statistics must answer for an administrator.' );
foreach ( array( 'days', 'jobs', 'total_jobs', 'calls', 'estimated_cost_usd' ) as $key ) {
	msrwa_real_assert( array_key_exists( $key, (array) $stats['body'] ), 'Statistics must expose ' . $key . '.' );
}
msrwa_real_note( 'jobs in the last 7 days: ' . (int) ( $stats['body']['total_jobs'] ?? 0 ) );

// Nothing in this namespace may answer an anonymous caller.
foreach ( array( '/msrwa/v1/stats', '/msrwa/v1/events', '/msrwa/v1/catalog' ) as $route ) {
	$status = msrwa_real_anonymous( 'GET', $route );
	msrwa_real_assert( in_array( $status, array( 401, 403 ), true ), 'Route ' . $route . ' must refuse an anonymous caller (got ' . $status . ').' );
}
$status = msrwa_real_anonymous( 'POST', '/msrwa/v1/batches' );
msrwa_real_assert( in_array( $status, array( 401, 403 ), true ), 'Batch creation must refuse an anonymous caller (got ' . $status . ').' );

// Provider readiness is reported, not assumed.
$openai = msrwa_real_request( 'POST', '/msrwa/v1/test/openai' );
if ( 200 === $openai['status'] ) {
	msrwa_real_note( 'OpenAI reachable, model ' . (string) ( $openai['body']['model'] ?? '?' ) );
} else {
	msrwa_real_note( 'OpenAI not usable yet: ' . (string) ( $openai['body']['code'] ?? $openai['status'] ) . ' — generation tests will skip' );
}

msrwa_real_done( 'MSRWA live site contracts' );
