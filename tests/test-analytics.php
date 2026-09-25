<?php
// The figures that only make sense across many runs: where the money goes, and
// whether it is going there more than it used to.
// Production classes first, so a harness double cannot answer for a screen.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $class ) { require_once $class; }
require_once MSRWA_DIR . 'includes/engine/load.php';
require __DIR__ . '/bootstrap.php';

// --- Scope is not optional, on the new queries either --------------------

msrwa_test_as_editor( 7 );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Ledger::by_bucket( 30 );
MSRWA_Ledger::previously( 30 );
msrwa_test_assert( 2 === count( $GLOBALS['wpdb']->matching( 'owner_id = 7' ) ), 'A writer sees their own buckets and their own history, and nobody else’s.' );

msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Ledger::previously( 30 );
$log = $GLOBALS['wpdb']->log();
msrwa_test_contains( $log, 'INTERVAL 60 DAY', 'The comparison window starts two windows back.' );
msrwa_test_contains( $log, 'INTERVAL 30 DAY', 'The comparison window ends where the current one starts.' );
msrwa_test_missing( $log, 'owner_id =', 'An administrator’s comparison covers the whole site.' );

// A window of "everything" has nothing before it, and asks nothing of the
// database rather than inventing a period.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$empty = MSRWA_Ledger::previously( 0 );
msrwa_test_assert( 0 === $empty['runs'] && ! $GLOBALS['wpdb']->queries, 'Comparing "everything" with what came before it asks nothing.' );

// --- What the screen does with it ---------------------------------------

function msrwa_analysis_html( array $now, array $before, array $buckets ) {
	$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
	$GLOBALS['wpdb']->on( 'GROUP BY s.bucket', $buckets );
	$GLOBALS['wpdb']->on( 'INTERVAL 60 DAY', array( $before ) );
	$GLOBALS['wpdb']->on( 'FROM wp_msrwa_spend', array( array( 'spend' => $now['spend'], 'matching' => 0.0 ) ) );
	$GLOBALS['wpdb']->on( 'COUNT(*) runs, AVG(CASE', array( $now ) );
	ob_start();
	MSRWA_Screen_Analysis::render();
	return ob_get_clean();
}

$buckets = array(
	array( 'bucket' => 'article', 'steps' => 20, 'spend' => 0.60, 'seconds' => 300, 'unpriced' => 0 ),
	array( 'bucket' => 'featured', 'steps' => 5, 'spend' => 0.40, 'seconds' => 50, 'unpriced' => 0 ),
);

// Enough runs on both sides: the comparison is drawn, and it is drawn the
// right way round.
$html = msrwa_analysis_html(
	array( 'spend' => 1.0, 'runs' => 10, 'average' => 0.10, 'seconds' => 200 ),
	array( 'runs' => 10, 'average' => 0.08, 'seconds' => 200 ),
	$buckets
);
msrwa_test_contains( $html, '+25 %', 'A cost that rose a quarter is reported as having risen a quarter.' );
msrwa_test_contains( $html, 'Où part l’argent', 'The buckets are drawn when there is more than one of them.' );
msrwa_test_contains( $html, '60 %', 'Each bucket carries its share of the spend.' );

// Two runs against two is not a trend, and saying nothing is the honest
// answer. The buckets still draw: a share is a share however few runs made it.
$html = msrwa_analysis_html(
	array( 'spend' => 0.2, 'runs' => 2, 'average' => 0.10, 'seconds' => 200 ),
	array( 'runs' => 2, 'average' => 0.08, 'seconds' => 200 ),
	$buckets
);
msrwa_test_missing( $html, '+25 %', 'A handful of runs on either side produces no comparison at all.' );
msrwa_test_contains( $html, 'Où part l’argent', 'The buckets do not need a trend to be worth drawing.' );

// One bucket is not a distribution, so there is nothing to compare it against.
$html = msrwa_analysis_html(
	array( 'spend' => 1.0, 'runs' => 10, 'average' => 0.10, 'seconds' => 200 ),
	array( 'runs' => 10, 'average' => 0.10, 'seconds' => 200 ),
	array( $buckets[0] )
);
msrwa_test_missing( $html, 'Où part l’argent', 'A single bucket is the total, and the total is already on screen.' );

// --- What the judge decided, after the drafts took the verdicts ----------

// A draft keeps the verdict in its post meta and the run's own copy is
// released; counting artifacts alone reported one judged run out of twenty-six.
msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( '_msrwa_judge_report', array(
	array( 'approved' => '1', 'verdict' => json_encode( array( 'approved' => true, 'article' => array( 'verdict' => 'good' ), 'findings' => array( array( 'severity' => 'minor' ) ) ) ) ),
	array( 'approved' => '0', 'verdict' => json_encode( array( 'approved' => false, 'article' => array( 'verdict' => 'bad' ), 'findings' => array( array( 'severity' => 'blocking' ) ) ) ) ),
	array( 'approved' => '1', 'verdict' => null ),
) );
$verdicts = MSRWA_Ledger::verdicts( 30 );
msrwa_test_assert( 3 === $verdicts['judged'] && 2 === $verdicts['approved'], 'Every judged run counts, from the run row, whether or not its verdict is still at hand; got ' . $verdicts['judged'] . ' judged, ' . $verdicts['approved'] . ' approved.' );
msrwa_test_assert( 2 === $verdicts['findings'] && 1 === $verdicts['blocking'], 'Findings are read from the draft\'s copy of the verdict.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'wp_postmeta', 'The draft\'s post meta is read.' );

msrwa_test_done( 'analytics across many runs' );
