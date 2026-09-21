<?php
// The engine is the plugin's core, so its two promises are tested here: it
// reports failure as data rather than killing its caller, and it knows which
// steps may run at the same time.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

// A run records what happened, including what failed, and still returns the rest.
$seen = array();
$result = new MSRWA_Result( function ( $event ) use ( &$seen ) { $seen[] = $event['kind']; } );
$result->step( 'research', array( 'seconds' => 40, 'cost_usd' => 0.02, 'passed' => 14, 'total' => 14 ) );
$result->step( 'featured_image', array( 'seconds' => 11, 'cost_usd' => 0.027 ) );
$result->step( 'facebook_image', array( 'seconds' => 13, 'cost_usd' => 0.033, 'error' => 'provider refused' ) );
$result->artifact( 'research', array( 'ok' => true ) );

msrwa_test_assert( false === $result->ok, 'A failed step must mark the run not ok.' );
msrwa_test_assert( 1 === count( $result->errors ), 'The failure must be recorded once.' );
msrwa_test_assert( 3 === count( $result->steps ), 'Every attempted step is recorded, failures included.' );
msrwa_test_assert( isset( $result->artifacts['research'] ), 'A partial run must still return what succeeded.' );
msrwa_test_assert( in_array( 'error', $seen, true ), 'The observer must see the failure as it happens.' );

$totals = $result->totals();
msrwa_test_assert( 64.0 === round( $totals['seconds'], 1 ), 'Seconds add up across steps; got ' . $totals['seconds'] );
msrwa_test_assert( 0.08 === round( $totals['cost_usd'], 2 ), 'Cost adds up across steps; got ' . $totals['cost_usd'] );
msrwa_test_assert( 0.027 === round( $totals['buckets']['featured'], 3 ), 'Each cost lands in its own budget bucket.' );
msrwa_test_assert( 0.02 === round( $totals['buckets']['other'], 3 ), 'Research is charged to other, not to the article.' );
msrwa_test_assert( isset( $result->to_array()['events'] ), 'The whole run must serialise for storage and for the report.' );

// A missing key is reported, never fatal: the plugin must survive a bad setting.
$before = getenv( 'OPENAI_API_KEY' );
putenv( 'OPENAI_API_KEY' );
putenv( 'MSRWA_OPENAI_KEY' );
$call = MSRWA_Engine_Call::text( 'openai', 'gpt-5.6-luna', 'hello', 10 );
msrwa_test_assert( ! empty( $call['error'] ), 'A missing API key must come back as an error, not an exit.' );
if ( false !== $before ) { putenv( 'OPENAI_API_KEY=' . $before ); }

// An unroutable model is a value too.
msrwa_test_assert( '' === MSRWA_Engine_Rates::model( 'nowhere', 'medium' ), 'An unknown provider resolves to no model rather than exiting.' );
msrwa_test_assert( null === MSRWA_Engine_Rates::price( 'nowhere', 'nothing', array() ), 'An unpriced model is unknown, never free.' );

// The order steps may run in. Three run beside each other in two of the waves,
// which is where the wall clock is won.
$done = array();
$remaining = MSRWA_Engine_Steps::names();
$waves = array();
while ( $remaining ) {
	$ready = MSRWA_Engine_Steps::ready( $done, $remaining );
	msrwa_test_assert( ! empty( $ready ), 'The pipeline must never deadlock; stuck on ' . implode( ', ', $remaining ) );
	$waves[] = $ready;
	foreach ( $ready as $name ) { $done[ MSRWA_Engine_Steps::get( $name )['produces'] ] = true; }
	$remaining = array_values( array_diff( $remaining, $ready ) );
}
msrwa_test_assert( array( 'research' ) === $waves[0], 'Research opens the pipeline.' );
msrwa_test_assert( array( 'canonical_recipe' ) === $waves[1], 'The recipe follows research alone.' );
msrwa_test_assert( 3 === count( $waves[2] ), 'The article and both images do not wait on each other.' );
msrwa_test_assert( in_array( 'featured_image', $waves[2], true ) && in_array( 'article', $waves[2], true ), 'Images need the recipe, not the article.' );
msrwa_test_assert( 3 === count( $waves[3] ), 'The three text reviews are independent of one another.' );
msrwa_test_assert( array( 'final_approval' ) === $waves[4], 'Approval is last: it judges the article a reader would get.' );

$missing = MSRWA_Engine_Steps::missing( 'article', array( 'research' => true ) );
msrwa_test_assert( array( 'canonical' ) === $missing, 'A step must say what it is still waiting for.' );

msrwa_test_done( 'engine' );
