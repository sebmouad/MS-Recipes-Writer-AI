<?php
// Who sees what, and what a query is allowed to fetch on their behalf.
//
// The rules held here are deliberate and easy to lose in a redesign: a writer
// sees their own work and nobody else's, a leftover `msrwa_view_all` grant does
// not widen that, and money is an operator's concern — a screen that may not
// show a figure must not query it either.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'i18n', 'profile', 'ledger', 'ui', 'db' );
require_once dirname( __DIR__ ) . '/includes/class-msrwa-screen-articles.php';

msrwa_test_as_editor( 7 );
$_GET = array( 'page' => 'msrwa-articles', 'state' => 'failed', 's' => '<b>tarte</b>', 'paged' => 9999 );
ob_start(); MSRWA_Screen_Articles::render(); $html = ob_get_clean();
$log = $GLOBALS['wpdb']->log();

msrwa_test_contains( $log, 'owner_id = 7', 'A writer’s list is scoped before it is read.' );
msrwa_test_contains( $log, "status = 'failed'", 'The state filter reaches the query.' );
msrwa_test_contains( $log, 'LIMIT 25 OFFSET', 'The query is bounded however high the page number goes.' );
// A filtered list that finds nothing and a site with no work yet are two
// different situations, and telling someone to "start a lot" when they have
// simply over-filtered is unhelpful.
msrwa_test_contains( $html, 'Rien ne correspond', 'A filtered list that finds nothing says so, rather than claiming there is no work.' );
msrwa_test_contains( $html, 'filtre', 'And it says what to do about it.' );
msrwa_test_missing( $html, '$', 'A writer is not shown what a run cost.' );
msrwa_test_missing( $log, 'cost_usd', 'A figure that cannot be shown is not fetched either.' );
msrwa_test_missing( $html, '<b>tarte</b>', 'What was searched for is escaped on the way out.' );
msrwa_test_missing( $html, 'page=msrwa-settings', 'A writer is not offered the administrator’s screens.' );

// A capability carried over from an earlier version of this plugin must not
// quietly widen a writer's view.
$GLOBALS['msrwa_test_caps'][] = 'msrwa_view_all';
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
ob_start(); MSRWA_Screen_Articles::render(); ob_end_clean();
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'owner_id = 7', 'A leftover view-all grant does not widen a writer’s scope.' );
msrwa_test_assert( ! MSRWA_Rights::may_see_money(), 'Nor does it show them the money.' );

msrwa_test_load( 'run', 'batch' );
msrwa_test_assert( ! MSRWA_Run::may_see( array( 'owner_id' => 8 ) ), 'A writer cannot open another writer’s run.' );
msrwa_test_assert( ! MSRWA_Batch::may_see( array( 'owner_id' => 8 ) ), 'Nor their batch.' );

// An administrator sees the whole ledger, money included.
msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$_GET = array( 'page' => 'msrwa-articles' );
ob_start(); MSRWA_Screen_Articles::render(); $html = ob_get_clean();
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'owner_id =', 'An administrator’s list is not scoped to themselves.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'cost_usd', 'An administrator’s list fetches what it may show.' );
msrwa_test_assert( MSRWA_Rights::may_see_money(), 'An administrator is shown the money.' );

// A finished run is work waiting for an editor, never work that was approved.
msrwa_test_assert( 'en cours' === MSRWA_UI::state_of( array( 'status' => 'running' ) )['label'], 'A run in flight says so.' );
msrwa_test_assert( 'à relire' === MSRWA_UI::state_of( array( 'status' => 'done', 'approved' => 1 ) )['label'], 'A finished run waits for a reader; it is never called approved.' );
msrwa_test_contains( MSRWA_UI::state_of( array( 'status' => 'done', 'approved' => 0 ) )['label'], 'réserves', 'A judge’s objection is reported as the judge’s, not as a failure.' );
msrwa_test_assert( 'échec' === MSRWA_UI::state_of( array( 'status' => 'failed' ) )['label'], 'A failure says so plainly.' );

msrwa_test_done( 'screens and permissions' );
