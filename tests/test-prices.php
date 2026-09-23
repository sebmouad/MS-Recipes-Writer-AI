<?php
// A price looked up by a model is an indication, never an invoice. What is
// held here is the refusing: a model asked for a price it cannot find will
// sometimes answer with a confident number anyway, and a wrong rate that
// looks authoritative is worse than an empty cell — an empty cell stops the
// estimate, a wrong one quietly bills against it.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'catalog', 'json', 'prices' );

/** What remember_price() was asked to store, without a database. */
$GLOBALS['msrwa_stored_prices'] = array();

$wanted = array(
	'claude:claude-sonnet-5' => array( 'provider' => 'claude', 'model_id' => 'claude-sonnet-5' ),
	'openai:gpt-5.6-luna' => array( 'provider' => 'openai', 'model_id' => 'gpt-5.6-luna' ),
);

// --- A well-formed answer -------------------------------------------------

$good = array( 'prices' => array(
	array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'output' => 15, 'source' => 'https://platform.claude.com/docs/en/about-claude/pricing' ),
) );
$out = MSRWA_Prices::apply( $wanted, $good );
msrwa_test_assert( 2 === $out['asked'], 'It reports how many it asked about.' );
msrwa_test_assert( 1 === $out['found'], 'And how many came back usable.' );
msrwa_test_assert( 'found' === $out['results']['claude:claude-sonnet-5']['state'], 'A rate with a page behind it is kept.' );
// A model omitted from the answer is the correct answer when the page does
// not carry a price. It must read as missing, never as zero.
msrwa_test_assert( 'missing' === $out['results']['openai:gpt-5.6-luna']['state'], 'A model the answer left out is missing, not free.' );

// --- Answers that must be refused -----------------------------------------

$refused = array(
	'no source at all' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'output' => 15 ),
	'an empty source' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'output' => 15, 'source' => '' ),
	'a price of zero' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 0, 'output' => 15, 'source' => 'https://example.test/p' ),
	'a negative price' => array( 'key' => 'claude:claude-sonnet-5', 'input' => -3, 'output' => 15, 'source' => 'https://example.test/p' ),
	'an absurd price' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 5000, 'output' => 15, 'source' => 'https://example.test/p' ),
	'words instead of a number' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 'about three dollars', 'output' => 15, 'source' => 'https://example.test/p' ),
	'a null price' => array( 'key' => 'claude:claude-sonnet-5', 'input' => null, 'output' => 15, 'source' => 'https://example.test/p' ),
	'only half a rate' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'source' => 'https://example.test/p' ),
);
foreach ( $refused as $why => $entry ) {
	$out = MSRWA_Prices::apply( $wanted, array( 'prices' => array( $entry ) ) );
	msrwa_test_assert( 0 === $out['found'], 'Refused: ' . $why . '.' );
	msrwa_test_assert( 'found' !== $out['results']['claude:claude-sonnet-5']['state'], 'And says so rather than storing it: ' . $why . '.' );
}

// A rate for something nobody asked about is not quietly written somewhere.
$stray = MSRWA_Prices::apply( $wanted, array( 'prices' => array(
	array( 'key' => 'claude:a-model-nobody-asked-about', 'input' => 1, 'output' => 2, 'source' => 'https://example.test/p' ),
) ) );
msrwa_test_assert( 0 === $stray['found'], 'A rate for a model that was not asked about is ignored.' );
msrwa_test_assert( 2 === count( $stray['results'] ), 'Only what was asked about is reported on.' );

// --- Answers that are not answers -----------------------------------------

foreach ( array( null, '', 'not json', array(), array( 'prices' => 'nonsense' ), array( 'prices' => array( 'flat', 3 ) ) ) as $rubbish ) {
	$out = MSRWA_Prices::apply( $wanted, $rubbish );
	msrwa_test_assert( 0 === $out['found'], 'Nothing usable yields nothing stored.' );
	msrwa_test_assert( 2 === count( $out['results'] ), 'And every model asked about is still accounted for.' );
}

// --- The prompt -----------------------------------------------------------

$prompt = MSRWA_Prices::prompt( $wanted );
foreach ( array_keys( $wanted ) as $key ) {
	msrwa_test_contains( $prompt, $key, 'The prompt names ' . $key . '.' );
}
// The three things that decide whether the answer is usable at all.
msrwa_test_contains( $prompt, 'MILLION', 'It fixes the unit, because providers quote per thousand and per million.' );
msrwa_test_contains( $prompt, 'source', 'It demands the page the number was read from.' );
msrwa_test_contains( $prompt, 'A missing entry is correct', 'And makes omission the right answer, so a gap is not filled with a guess.' );
msrwa_test_contains( $prompt, 'provider\'s own', 'Only the provider\'s own page counts as a source.' );

msrwa_test_done( 'looked-up prices' );
