<?php
// The Articles screen's live list: /runs/live answers an operator with the
// running rows and the totals, drawn server-side, and refuses anyone else.
// Costs nothing: it reads the ledger and calls no provider.
require __DIR__ . '/lib.php';

$index = msrwa_real_request( 'GET', '/?cb=' . mt_rand() );
msrwa_real_assert( in_array( '/msrwa/v1/runs/live', array_keys( (array) ( $index['body']['routes'] ?? array() ) ), true ), 'Route /msrwa/v1/runs/live must be registered.' );

$anonymous = msrwa_real_anonymous( 'GET', '/msrwa/v1/runs/live' );
msrwa_real_assert( in_array( (int) $anonymous['status'], array( 401, 403 ), true ), 'A visitor must be refused (got ' . $anonymous['status'] . ').' );

$live = msrwa_real_request( 'GET', '/msrwa/v1/runs/live?shown=' );
msrwa_real_assert( 200 === $live['status'], 'The live list must answer an administrator (got ' . $live['status'] . ').' );
$body = (array) $live['body'];
foreach ( array( 'moving', 'settled', 'totals', 'post_counts' ) as $key ) {
	msrwa_real_assert( array_key_exists( $key, $body ), 'The answer must carry ' . $key . '.' );
}
foreach ( (array) ( $body['moving'] ?? array() ) as $row ) {
	msrwa_real_assert( false !== strpos( (string) ( $row['html'] ?? '' ), 'data-run="' . (int) $row['id'] . '"' ), 'Each running row must come drawn with its id.' );
	msrwa_real_assert( md5( (string) $row['html'] ) === (string) ( $row['sig'] ?? '' ), 'Each row must carry its signature.' );
}
msrwa_real_note( (int) ( $body['totals']['moving'] ?? 0 ) . ' running, ' . (int) ( $body['totals']['settled'] ?? 0 ) . ' finished' );

// Ids outside the list are asked after and come back only once settled.
$settled = msrwa_real_request( 'GET', '/msrwa/v1/runs/live?shown=999999999' );
msrwa_real_assert( 200 === $settled['status'] && array() === (array) ( $settled['body']['settled'] ?? array() ), 'A run that does not exist comes back as nothing, not as an error.' );

msrwa_real_done( 'the live articles list answers operators and nobody else' );
