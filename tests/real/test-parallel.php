<?php
// A lot's recipes run side by side, and none waits on a visit to the site.
// Through WordPress cron alone they ran one after another in a single
// wp-cron.php, each waiting for the next visitor between two ticks. Three
// article-only recipes, the cheapest profile, on the site's own worker.
require __DIR__ . '/lib.php';

$budget = msrwa_real_budget();
if ( $budget <= 0 ) { msrwa_real_skip( 'MSRWA_TEST_BUDGET_USD is not set; this test spends real money.' ); }
$estimate = msrwa_real_request( 'GET', '/msrwa/v1/estimate?profile=article&recipes=1&images=0' );
if ( (float) ( $estimate['body']['cost_usd'] ?? 0 ) > $budget ) { msrwa_real_skip( 'One article is estimated above the ceiling.' ); }

$recipes = implode( "\n---\n", array(
	"Tarte aux pommes normande\nPâte brisée, pommes, crème, œufs, calvados. Cuire 45 minutes à 180 °C.",
	"Quiche lorraine\nPâte brisée, lardons, œufs, crème, muscade. Cuire 35 minutes à 190 °C.",
	"Gratin dauphinois\nPommes de terre, crème, lait, ail, muscade. Cuire 1 heure à 160 °C.",
) );
$created = msrwa_real_upload( '/msrwa/v1/batches', array( 'recipes' => $recipes, 'budget' => (string) $budget, 'profile' => 'article', 'language' => 'fr' ), array() );
msrwa_real_assert( 200 === $created['status'] && 3 === (int) ( $created['body']['recipes'] ?? 0 ), 'A lot of three recipes is accepted.' );
$batch = (int) ( $created['body']['id'] ?? 0 );
$started = microtime( true );
$dispatched = msrwa_real_request( 'POST', '/msrwa/v1/batches/' . $batch . '/dispatch' );
msrwa_real_assert( 200 === $dispatched['status'], 'The lot dispatches (got ' . $dispatched['status'] . ').' );
msrwa_real_note( 'lot #' . $batch );

// Cron, run by wp-cron.php, takes due recipes one after another: two running
// at once can only be the worker.
$most_at_once = 0;
$final = array();
msrwa_real_wait( function () use ( $batch, &$most_at_once, &$final ) {
	$runs = (array) ( msrwa_real_request( 'GET', '/msrwa/v1/batches/' . $batch . '/runs' )['body']['runs'] ?? array() );
	$final = $runs;
	$running = count( array_filter( $runs, static function ( $run ) { return 'running' === $run['status']; } ) );
	$most_at_once = max( $most_at_once, $running );
	return $runs && ! array_filter( $runs, static function ( $run ) { return in_array( $run['status'], array( 'queued', 'running' ), true ); } );
}, 900, 5 );
$seconds = round( microtime( true ) - $started );
$spent = array_sum( array_map( static function ( $run ) { return (float) ( $run['cost_usd'] ?? 0 ); }, $final ) );
msrwa_real_spend( $spent );

msrwa_real_assert( 3 === count( array_filter( $final, static function ( $run ) { return 'done' === $run['status']; } ) ), 'All three recipes finish: ' . wp_json_encode_compat( array_column( $final, 'status' ) ) );
msrwa_real_assert( $most_at_once >= 2, 'Recipes run side by side; at most ' . $most_at_once . ' ran at once.' );
$longest = max( array_map( static function ( $run ) { return (float) ( $run['seconds'] ?? 0 ); }, $final ) );
msrwa_real_note( sprintf( 'three recipes in %ds, the longest %ds on its own, up to %d at once, $%.4f', $seconds, $longest, $most_at_once, $spent ) );
msrwa_real_assert( $seconds < 2 * $longest + 60, 'The lot takes about as long as its longest recipe, not the three end to end.' );

// The page's fallback, for a site that cannot call itself: with nothing left
// waiting, it moves nothing and costs nothing.
$nudged = msrwa_real_request( 'POST', '/msrwa/v1/batches/' . $batch . '/nudge' );
msrwa_real_assert( 200 === $nudged['status'] && 0 === (int) ( $nudged['body']['moved'] ?? -1 ), 'A finished lot has nothing for the page to carry on.' );

msrwa_real_done( 'a lot runs side by side, without waiting for a visit' );
