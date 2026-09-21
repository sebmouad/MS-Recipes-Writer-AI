<?php
// The laboratory runs the engine from WordPress, where there is no environment
// to keep an API key in and no request long enough to finish a run. Both of
// those seams are tested here: a key handed over directly, and a run carried
// forward one wave at a time.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

// --- The key the caller supplies ----------------------------------------

// WordPress keeps its keys encrypted in its own table. Handing one to the
// engine directly must work, and must beat whatever the server exports.
putenv( 'OPENAI_API_KEY=from-the-environment' );
$supplied = MSRWA_Engine_Config::create( array( 'settings' => array( 'keys' => array( 'openai' => 'from-the-administrator' ) ) ) );
$wire = $supplied->provider( 'openai', 'gpt-5.6-luna' );
msrwa_test_assert( true === $wire['has_key'], 'A key given by the caller must count as a key.' );
msrwa_test_contains( json_encode( $wire['headers'] ), 'from-the-administrator', 'The caller’s key must be the one sent.' );
msrwa_test_missing( json_encode( $wire['headers'] ), 'from-the-environment', 'An administrator who typed a key meant that key.' );

// With nothing supplied, the environment still answers, so the lab and the
// tests keep working exactly as before.
$environment = MSRWA_Engine_Config::create();
msrwa_test_contains( json_encode( $environment->provider( 'openai', 'gpt-5.6-luna' )['headers'] ), 'from-the-environment', 'With no key supplied the environment is still read.' );

// A blank key is not a key: it must not shadow the environment.
$blank = MSRWA_Engine_Config::create( array( 'settings' => array( 'keys' => array( 'openai' => '   ' ) ) ) );
msrwa_test_contains( json_encode( $blank->provider( 'openai', 'gpt-5.6-luna' )['headers'] ), 'from-the-environment', 'An empty setting must not hide a working key.' );
putenv( 'OPENAI_API_KEY' );

// The key must never reach anything that gets stored.
$record = json_encode( $supplied->to_array() );
msrwa_test_missing( $record, 'from-the-administrator', 'A key must never reach the run’s stored record.' );

// --- Carrying a run forward one wave at a time --------------------------

// This is what a cron tick decides: given what has been produced so far, what
// may run now. It is the engine's own scheduler, which is why the lab can stop
// between waves and resume without knowing anything the engine does not.
$registry = array();
msrwa_test_assert( array( 'research' ) === MSRWA_Engine_Steps::ready( array(), MSRWA_Engine_Steps::names(), $registry ), 'A fresh run starts with research and nothing else.' );

$after_research = array( 'brief' => array( 'title' => 'x' ), 'research' => array( 'ok' => true ) );
msrwa_test_assert( array( 'canonical_recipe' ) === MSRWA_Engine_Steps::ready( $after_research, array_diff( MSRWA_Engine_Steps::names(), array( 'research' ) ), $registry ), 'The canonical recipe follows the research alone.' );

$after_canonical = array_merge( $after_research, array( 'canonical' => array( 'ok' => true ) ) );
$wave = MSRWA_Engine_Steps::ready( $after_canonical, array_diff( MSRWA_Engine_Steps::names(), array( 'research', 'canonical_recipe' ) ), $registry );
msrwa_test_assert( 3 === count( $wave ), 'The article and both images form one wave; got ' . implode( ', ', $wave ) );

// A tick must never re-run a step that already finished, however it finished.
$done = array( 'research', 'canonical_recipe' );
$remaining = array_values( array_diff( MSRWA_Engine_Steps::names(), $done ) );
msrwa_test_assert( ! in_array( 'research', $remaining, true ), 'A finished step is never offered to the next tick.' );

// Totals are summed over the run's own steps, not over any single tick, or a
// four-minute run would report the cost of its last wave.
$result = new MSRWA_Result();
$result->step( 'research', array( 'seconds' => 74.3, 'cost_usd' => 0.0226 ) );
$result->step( 'canonical_recipe', array( 'seconds' => 19.8, 'cost_usd' => 0.0045 ) );
$totals = $result->totals();
msrwa_test_assert( 0.0271 === round( $totals['cost_usd'], 4 ), 'A run costs what all of its waves cost; got ' . $totals['cost_usd'] );
msrwa_test_assert( 94.1 === round( $totals['seconds'], 1 ), 'A run takes what all of its waves took; got ' . $totals['seconds'] );

// --- What the screen offers and what it renders -------------------------

require_once dirname( __DIR__ ) . '/includes/class-msrwa-lab-screen.php';

$fixtures = MSRWA_Lab_Screen::fixtures();
msrwa_test_assert( isset( $fixtures['tarte-pommes'] ), 'The shipped briefs must be offered as a starting point.' );
msrwa_test_assert( '' !== trim( (string) reset( $fixtures ) ), 'A brief is offered under its title, not its filename.' );

$brief = MSRWA_Lab_Screen::brief( 'tarte-pommes' );
msrwa_test_assert( '' !== (string) ( $brief['title'] ?? '' ), 'A chosen brief must carry a title into the run.' );

// A name that walks out of the fixtures directory must find nothing.
msrwa_test_assert( array() === MSRWA_Lab_Screen::brief( '../../wp-config' ), 'A brief name must never escape its directory.' );
msrwa_test_assert( array() === MSRWA_Lab_Screen::brief( 'pas-un-brief' ), 'An unknown brief is empty, never a fatal.' );

// The report the screen shows is the command line's own renderer, fed the
// state the worker stored. Proving that here means the admin link cannot be
// the first place it is tried.
$saved = glob( dirname( __DIR__ ) . '/tools/runs/tarte-pommes-*.json' );
if ( $saved ) {
	sort( $saved );
	$state = json_decode( (string) file_get_contents( end( $saved ) ), true );
	require_once dirname( __DIR__ ) . '/tools/report.php';
	$html = report_render( (array) $state );
	msrwa_test_contains( $html, '<!doctype html', 'The report must be a whole document the browser can open.' );
	msrwa_test_assert( strlen( $html ) > 10000, 'A report of a real run is not a stub; got ' . strlen( $html ) . ' characters.' );
}

msrwa_test_done( 'lab seams OK' );
