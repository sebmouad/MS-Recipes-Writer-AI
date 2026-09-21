<?php
// The ledger as a file.
//
// Two properties matter more than the columns: a writer never exports another
// writer's spend, and an unknown cost is written as nothing rather than as a
// zero — a spreadsheet that sums a column of zeroes reports a total nobody ever
// paid.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $class ) { require_once $class; }
require_once MSRWA_DIR . 'includes/engine/load.php';
require __DIR__ . '/bootstrap.php';

$rows = new ReflectionMethod( MSRWA_Export::class, 'rows' );
$rows->setAccessible( true );

msrwa_test_assert( 3 === count( MSRWA_Export::shapes() ), 'Three shapes are offered.' );

// A writer's export is scoped, like every other query.
msrwa_test_as_editor( 7 );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
iterator_to_array( $rows->invoke( null, 'runs', 30 ) );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'owner_id = 7', 'A writer exports their own work only.' );

// An administrator's is not.
msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
iterator_to_array( $rows->invoke( null, 'calls', 0 ) );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'owner_id =', 'An administrator exports the whole ledger.' );

// Every shape starts with its header, and asks for its own table.
foreach ( array( 'runs' => 'msrwa_runs', 'steps' => 'msrwa_steps', 'calls' => 'msrwa_calls' ) as $shape => $table ) {
	$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
	$out = iterator_to_array( $rows->invoke( null, $shape, 7 ) );
	msrwa_test_assert( ! empty( $out[0] ), $shape . ' starts with a header row.' );
	msrwa_test_contains( $GLOBALS['wpdb']->log(), $table, $shape . ' reads from ' . $table . '.' );
	msrwa_test_contains( $GLOBALS['wpdb']->log(), 'LIMIT 500 OFFSET 0', $shape . ' is read in pages, not all at once.' );
}

// An unknown cost is empty, never zero.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'FROM wp_msrwa_steps', array( array( 3, 'article', 'article', 'openai', 'm', 44.0, 1, 100, 200, null, 9, 10, 0, '' ) ) );
$out = iterator_to_array( $rows->invoke( null, 'steps', 0 ) );
msrwa_test_assert( isset( $out[1] ), 'The row is exported.' );
msrwa_test_assert( '' === $out[1][9], 'An unpriced step exports as empty, never as 0; got ' . var_export( $out[1][9] ?? null, true ) );

msrwa_test_done( 'the ledger exports honestly' );
