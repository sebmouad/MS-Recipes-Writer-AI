<?php
// One lot, end to end, on a live site. This is the only test that touches the
// parts no offline suite can reach: the migration, cron, the media library and
// the draft. It spends real money, so it refuses to start without a ceiling
// and uses the cheapest profile there is.
require __DIR__ . '/lib.php';

$budget = msrwa_real_budget();
if ( $budget <= 0 ) { msrwa_real_skip( 'MSRWA_TEST_BUDGET_USD is not set; this test spends real money.' ); }

$health = msrwa_real_request( 'GET', '/msrwa/v1/health' );
if ( empty( $health['body']['providers'] ) ) { msrwa_real_skip( 'No API key is stored on the site; nothing can be generated.' ); }

// Article only: the cheapest way to exercise the whole machine. Images are the
// expensive half and are not what is being proved here.
$estimate = msrwa_real_request( 'GET', '/msrwa/v1/estimate?profile=article&recipes=1&images=0' );
$expected = (float) ( $estimate['body']['cost_usd'] ?? 0 );
msrwa_real_assert( $expected > 0, 'The article profile must be priced before it is run.' );
if ( $expected > $budget ) { msrwa_real_skip( 'One article is estimated at $' . $expected . ', over the $' . $budget . ' ceiling.' ); }
msrwa_real_note( 'estimated $' . number_format( $expected, 4 ) . ', ceiling $' . number_format( $budget, 4 ) );

// --- Submit ---------------------------------------------------------------

$created = msrwa_real_request( 'POST', '/msrwa/v1/batches', array(
	'recipes' => "Tarte aux pommes normande\nPâte brisée, pommes, crème, œufs, calvados. Cuire 45 minutes à 180 °C.",
	'images' => '',
	'budget' => round( $budget, 4 ),
	'profile' => 'article',
	'language' => 'fr',
), 180 );

msrwa_real_assert( 200 === $created['status'], 'A lot must be accepted (got ' . $created['status'] . ': ' . wp_json_encode_compat( $created['body'] ) . ').' );
$batch = (int) ( $created['body']['id'] ?? 0 );
msrwa_real_assert( $batch > 0, 'The lot must come back with a number.' );
msrwa_real_assert( 1 === (int) ( $created['body']['recipes'] ?? 0 ), 'One recipe must have been read out of the text.' );
msrwa_real_note( 'lot #' . $batch );

// --- Dispatch and wait ----------------------------------------------------

$dispatched = msrwa_real_request( 'POST', '/msrwa/v1/batches/' . $batch . '/dispatch' );
msrwa_real_assert( 200 === $dispatched['status'], 'The lot must dispatch (got ' . $dispatched['status'] . ').' );
msrwa_real_assert( 1 === (int) ( $dispatched['body']['started'] ?? 0 ), 'One recipe must have started.' );

// Cron carries it, one wave per tick. On a quiet site nothing visits, so the
// wait is generous and says why if it runs out.
$final = null;
$settled = msrwa_real_wait( function () use ( $batch, &$final ) {
	$runs = msrwa_real_request( 'GET', '/msrwa/v1/batches/' . $batch . '/runs' );
	$run = (array) ( $runs['body']['runs'][0] ?? array() );
	if ( ! $run ) { return false; }
	$final = $run;
	return ! in_array( (string) $run['status'], array( 'queued', 'running' ), true );
}, 900, 15 );

if ( ! $settled ) {
	msrwa_real_fail( 'The recipe never finished in fifteen minutes. Last seen: '
		. wp_json_encode_compat( $final )
		. ' — if it stayed queued, cron is not running: check DISABLE_WP_CRON and the server cron.' );
	msrwa_real_done( 'one lot, end to end' );
}

msrwa_real_spend( (float) ( $final['cost_usd'] ?? 0 ) );
msrwa_real_note( 'finished ' . $final['status'] . ' in ' . $final['seconds'] . 's for $' . number_format( (float) $final['cost_usd'], 4 ) );

msrwa_real_assert( 'done' === (string) $final['status'], 'The recipe must finish, not fail: ' . wp_json_encode_compat( $final ) );
msrwa_real_assert( (int) $final['steps_done'] === (int) $final['steps_total'], 'Every step of the profile must have run.' );

// The estimate exists to be believed. Within half of what was actually billed
// is the loosest band worth asserting.
$billed = (float) $final['cost_usd'];
msrwa_real_assert( $billed > 0, 'A run that called a provider cannot have cost nothing.' );
msrwa_real_assert(
	$billed <= $budget,
	'A run must never exceed the ceiling it was given: billed $' . $billed . ' against $' . $budget . '.'
);
msrwa_real_assert(
	abs( $billed - $expected ) / max( 0.0001, $expected ) < 0.5,
	'The estimate must be in the right region: estimated $' . number_format( $expected, 4 ) . ', billed $' . number_format( $billed, 4 ) . '.'
);

// --- The draft ------------------------------------------------------------

$post = (int) ( $final['draft_post_id'] ?? 0 );
msrwa_real_assert( $post > 0, 'A finished recipe must have become a draft.' );

$draft = msrwa_real_request( 'GET', '/wp/v2/posts/' . $post . '?context=edit' );
msrwa_real_assert( 200 === $draft['status'], 'The draft must be readable (got ' . $draft['status'] . ').' );
msrwa_real_assert( 'draft' === (string) ( $draft['body']['status'] ?? '' ), 'It must be a draft, never published.' );
msrwa_real_assert( '' !== trim( (string) ( $draft['body']['content']['raw'] ?? '' ) ), 'A draft with no article in it is worse than no draft.' );
msrwa_real_note( 'draft #' . $post . ': ' . wp_strip_all_tags_compat( (string) ( $draft['body']['title']['raw'] ?? '' ) ) );

msrwa_real_done( 'one lot, end to end' );
