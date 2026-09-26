<?php
// What is kept, for how long, and who decides. The ages are settings so an
// operator can change them; a filter still wins, because a site that pinned one
// in code meant it.
require __DIR__ . '/bootstrap.php';
require_once MSRWA_DIR . 'includes/class-msrwa-retention.php';
require_once MSRWA_DIR . 'includes/class-msrwa-sources.php';
require_once MSRWA_DIR . 'includes/class-msrwa-db.php';
require_once MSRWA_DIR . 'includes/class-msrwa-run.php';

msrwa_test_settings( array( 'retention_events_days' => 45, 'retention_artifacts_days' => 200, 'retention_runs_days' => 0 ) );
$GLOBALS['msrwa_test_options'] = array();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();

$policy = MSRWA_Retention::policy();
msrwa_test_assert( 45 === $policy['events'], 'The timeline age is the one the site set.' );
msrwa_test_assert( 200 === $policy['artifacts'], 'The heavy-output age is the one the site set.' );
msrwa_test_assert( 0 === $policy['runs'], 'Whole runs are kept unless a site asks otherwise.' );

$GLOBALS['msrwa_test_filters']['msrwa_retention_events_days'] = 7;
msrwa_test_assert( 7 === MSRWA_Retention::policy()['events'], 'A filter beats the field, so the screen can warn that it does.' );
unset( $GLOBALS['msrwa_test_filters']['msrwa_retention_events_days'] );

// Nothing is removed at zero, and nothing is asked of the database either: a
// site that keeps everything should not pay for a query that finds nothing.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
msrwa_test_assert( 0 === MSRWA_Retention::events( 0 ), 'A timeline age of zero removes nothing.' );
msrwa_test_assert( 0 === MSRWA_Retention::artifacts( 0 ), 'A heavy-output age of zero removes nothing.' );
msrwa_test_assert( 0 === MSRWA_Retention::runs( 0 ), 'A run age of zero removes nothing.' );
msrwa_test_assert( ! $GLOBALS['wpdb']->queries, 'Keeping everything costs no query at all.' );

// Rows are chosen, then removed by their own ids: a multi-table DELETE cannot
// carry a LIMIT on MySQL and does not exist at all on SQLite. The one-statement
// version shipped in 0.4.1 and never deleted a row.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT e.id', array( 4, 5, 6 ) );
msrwa_test_assert( 1 === MSRWA_Retention::events( 30 ), 'Old timeline rows are removed.' );
$log = $GLOBALS['wpdb']->log();
msrwa_test_contains( $log, 'DELETE FROM wp_msrwa_events WHERE id IN (4,5,6)', 'Rows are removed by their own ids.' );
msrwa_test_missing( $log, 'DELETE FROM wp_msrwa_events e INNER JOIN', 'No multi-table DELETE: it is invalid on MySQL and absent from SQLite.' );
msrwa_test_contains( $log, "r.status NOT IN ('queued','running')", 'A run still moving keeps its timeline.' );

// The heavy artifacts go; the verdict, the review and the recipe stay, because
// they are what somebody asks an old article about.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT a.id', array( 9 ) );
MSRWA_Retention::artifacts( 30 );
foreach ( array( 'brief', 'review', 'approval', 'canonical' ) as $kept ) {
	msrwa_test_contains( $GLOBALS['wpdb']->log(), "'" . $kept . "'", 'The ' . $kept . ' artifact is never swept.' );
}

// A run that produced a draft is never removed, whatever its age: the draft
// points back at it.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Retention::runs( 30 );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'draft_post_id = 0', 'A run whose draft still stands is kept.' );

// Every pass is written down, including one that took nothing: "ran an hour ago
// and found nothing" and "has not run since March" look identical otherwise.
$GLOBALS['msrwa_test_options'] = array();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
msrwa_test_settings( array( 'retention_events_days' => 0, 'retention_artifacts_days' => 0, 'retention_runs_days' => 0 ) );
MSRWA_Retention::sweep();
msrwa_test_assert( '' !== MSRWA_Retention::last()['at'], 'A pass that removed nothing still says it ran.' );

// Files nothing will open again: a finished recipe's drawings once its draft
// holds them, and a folder whose recipe is gone. A drawing written within the
// hour, a recipe still moving and one without a draft keep theirs.

$root = wp_upload_dir()['basedir'] . '/msrwa';
foreach ( array( 901, 902, 903, 904 ) as $run ) { MSRWA_Sources::forget_run( $run ); @mkdir( $root . '/' . $run . '/sources', 0777, true ); }
$old = time() - 2 * HOUR_IN_SECONDS;
foreach ( array( '901/featured-a.webp', '901/sources/photo.jpg', '902/facebook-b.webp', '903/featured-c.webp' ) as $file ) { file_put_contents( $root . '/' . $file, 'x' ); touch( $root . '/' . $file, $old ); }
file_put_contents( $root . '/901/featured-new.webp', 'x' );
touch( $root . '/904', $old );
$GLOBALS['msrwa_test_deleted'] = array();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT id, status, draft_post_id', array(
	array( 'id' => 901, 'status' => 'done', 'draft_post_id' => 40 ),
	array( 'id' => 902, 'status' => 'failed', 'draft_post_id' => 0 ),
	array( 'id' => 903, 'status' => 'running', 'draft_post_id' => 0 ),
) );
MSRWA_Retention::leftovers();
$deleted = implode( "\n", (array) $GLOBALS['msrwa_test_deleted'] );
msrwa_test_contains( $deleted, '901/featured-a.webp', 'A finished recipe’s old drawing goes once its draft holds the image.' );
msrwa_test_missing( $deleted, 'featured-new.webp', 'A drawing written within the hour stays: it may be on its way to the library.' );
msrwa_test_missing( $deleted, 'photo.jpg', 'The photographs a recipe was written from are not drawings.' );
msrwa_test_missing( $deleted, '902/', 'A failed recipe keeps its drawings: resuming it reuses them.' );
msrwa_test_missing( $deleted, '903/', 'A recipe still moving keeps everything.' );
msrwa_test_assert( ! is_dir( $root . '/904' ) && is_dir( $root . '/901' ), 'A folder whose recipe is gone is removed, and only that one.' );
foreach ( array( 901, 902, 903 ) as $run ) { MSRWA_Sources::forget_run( $run ); }

msrwa_test_done( 'what is kept and for how long' );
