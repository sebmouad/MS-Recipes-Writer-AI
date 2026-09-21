<?php
// Everything the engine reports is kept, in rows a question can be asked of.
// These are the questions a single run cannot answer and that decide what to
// change next: which step carries the cost, which check keeps failing, whether
// the judge ever approves anything.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-lab.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-lab-stats.php';

msrwa_test_as_admin();

// --- A step read back is the step that was written ----------------------

$GLOBALS['wpdb']->on( 'SELECT * FROM wp_msrwa_lab_steps', array(
	array( 'step' => 'research', 'provider' => 'openai', 'model' => 'm', 'seconds' => '74.3', 'attempts' => '1',
		'input_tokens' => '63121', 'output_tokens' => '6256', 'cost_usd' => '0.022600', 'bucket' => 'other', 'status' => '',
		'passed' => '14', 'total' => '14', 'checks_json' => '{"images inspected":{"pass":true}}', 'error_message' => '' ),
	// A model with no published rate: the column is null, and null must survive
	// the round trip. Casting it to zero once made a paid run report as free.
	array( 'step' => 'article', 'provider' => 'openai', 'model' => 'inconnu', 'seconds' => '49.3', 'attempts' => '1',
		'input_tokens' => '6654', 'output_tokens' => '6557', 'cost_usd' => null, 'bucket' => 'article', 'status' => '',
		'passed' => null, 'total' => null, 'checks_json' => null, 'error_message' => '' ),
) );

$steps = MSRWA_Lab::steps( 1 );
msrwa_test_assert( 2 === count( $steps ), 'Every stored step is read back.' );
msrwa_test_assert( 0.0226 === $steps[0]['cost_usd'], 'A priced step keeps its price; got ' . var_export( $steps[0]['cost_usd'], true ) );
msrwa_test_assert( null === $steps[1]['cost_usd'], 'An unpriced step stays unknown, never free.' );
msrwa_test_assert( null === $steps[1]['passed'], 'A step with no scorecard has no score, not a zero.' );
msrwa_test_assert( 63121 === $steps[0]['usage']['input_tokens'], 'Token counts survive the round trip.' );

// And the totals built from them carry the warning, not a false figure.
$totals = ( new ReflectionMethod( MSRWA_Lab::class, 'totals' ) );
$totals->setAccessible( true );
$sums = $totals->invoke( null, $steps );
msrwa_test_assert( 1 === $sums['unpriced_steps'], 'A run with an unpriced call says so; got ' . $sums['unpriced_steps'] );
msrwa_test_assert( 0.0226 === round( $sums['cost_usd'], 4 ), 'The known cost is still reported; got ' . $sums['cost_usd'] );

// --- Which check keeps failing ------------------------------------------

$GLOBALS['wpdb']->on( 'SELECT s.step, s.checks_json', array(
	array( 'step' => 'review', 'checks_json' => '{"valid JSON":{"pass":true},"findings are structured":{"pass":false}}' ),
	array( 'step' => 'review', 'checks_json' => '{"valid JSON":{"pass":true},"findings are structured":{"pass":false}}' ),
	array( 'step' => 'review', 'checks_json' => '{"valid JSON":{"pass":false},"findings are structured":{"pass":true}}' ),
) );

$checks = MSRWA_Lab_Stats::checks();
msrwa_test_assert( 2 === count( $checks ), 'Only the checks that failed are reported; got ' . count( $checks ) );
msrwa_test_assert( 'findings are structured' === $checks[0]['check'], 'The check that fails most often comes first; got ' . $checks[0]['check'] );
msrwa_test_assert( 2 === $checks[0]['failed'] && 3 === $checks[0]['seen'], 'A failure rate counts every time the check ran, not only its failures.' );

// --- What the judge decided, across runs --------------------------------

$GLOBALS['wpdb']->on( 'artifact_key', array(
	wp_json_encode( array( 'approved' => false, 'article' => array( 'verdict' => 'bad' ), 'featured_image' => array( 'verdict' => 'good' ),
		'findings' => array( array( 'severity' => 'blocking' ), array( 'severity' => 'minor' ) ) ) ),
	wp_json_encode( array( 'approved' => true, 'article' => array( 'verdict' => 'good' ), 'featured_image' => array( 'verdict' => 'good' ), 'findings' => array() ) ),
	'pas du json',
) );

$verdicts = MSRWA_Lab_Stats::verdicts();
msrwa_test_assert( 2 === $verdicts['judged'], 'An unreadable verdict is not a verdict; got ' . $verdicts['judged'] );
msrwa_test_assert( 1 === $verdicts['approved'], 'Approvals are counted; got ' . $verdicts['approved'] );
msrwa_test_assert( 2 === $verdicts['findings'] && 1 === $verdicts['blocking'], 'Findings are counted by severity.' );
msrwa_test_assert( 2 === ( $verdicts['artifacts']['featured_image']['good'] ?? 0 ), 'Each artifact’s verdicts are tallied separately.' );
msrwa_test_assert( 1 === ( $verdicts['artifacts']['article']['bad'] ?? 0 ), 'A refused article is tallied as refused.' );

// --- Nothing stored may carry a secret ----------------------------------

// Everything on its way into these tables goes through the same sanitiser the
// editorial tables use; the invariant is the plugin's, not the lab's.
$clean = MSRWA_DB::sanitize_persisted_data( array( 'api_key' => 'sk-secret', 'nested' => array( 'password' => 'hunter2', 'model' => 'm' ) ) );
msrwa_test_assert( '[redacted]' === $clean['api_key'], 'A key must never reach a stored row.' );
msrwa_test_assert( '[redacted]' === $clean['nested']['password'], 'Nor a password, however deeply it sits.' );
msrwa_test_assert( 'm' === $clean['nested']['model'], 'Everything else is kept as it was.' );

msrwa_test_done( 'lab data OK' );
