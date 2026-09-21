<?php
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'admin' );
msrwa_test_as_editor( 7 );
$_GET = array( 'page' => 'msrwa-jobs', 'status' => 'failed', 's' => '<b>tarte</b>', 'paged' => 9999 );
ob_start(); MSRWA_Admin::jobs(); $html = ob_get_clean();
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'owner_id = 7', 'Editor queries are scoped before reading.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), "status = 'failed'", 'Status filter reaches the query.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'LIMIT 25 OFFSET 0', 'Page and query size are bounded for empty results.' );
msrwa_test_contains( $html, 'Aucun job', 'Jobs show a useful empty state.' );
msrwa_test_missing( $html, 'page=msrwa-settings', 'Editors are not offered administrator settings.' );
msrwa_test_missing( $html, '<b>tarte</b>', 'Search output is sanitized.' );
msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$_GET = array( 'page' => 'msrwa-jobs' );
ob_start(); MSRWA_Admin::jobs(); $html = ob_get_clean();
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'owner_id =', 'Administrator can inspect all jobs.' );
foreach ( array( 'Rédaction', 'Jobs', 'Statistiques', 'Planificateur', 'Moteur', 'Réglages' ) as $label ) { msrwa_test_contains( $html, $label, 'Navigation includes ' . $label ); }
msrwa_test_contains( $html, 'aria-current="page"', 'Navigation marks the current page accessibly.' );
msrwa_test_done( 'admin pages and permissions' );
