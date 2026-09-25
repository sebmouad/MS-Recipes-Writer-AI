<?php
// Every amount the site spends, on the day it was spent. The ceilings used to
// sum the runs: a lot's photograph reading was never counted, a redraw counted
// on its recipe's creation day, and deleting a lot handed its money back.
require __DIR__ . '/bootstrap.php';
require_once MSRWA_DIR . 'includes/class-msrwa-rights.php';

// A step with a known price is a line; an unpriced or free one is not.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Spend::steps( array( 'id' => 9, 'owner_id' => 7, 'batch_id' => 3 ), array(
	array( 'step' => 'research', 'cost_usd' => 0.021 ),
	array( 'step' => 'corrections', 'cost_usd' => 0.0 ),
	array( 'step' => 'article', 'cost_usd' => null ),
), '2026-09-25 10:00:00' );
$log = $GLOBALS['wpdb']->log();
msrwa_test_contains( $log, 'wp_msrwa_spend', 'A wave writes its spending lines.' );
msrwa_test_contains( $log, "'research'", 'The priced step is a line.' );
msrwa_test_missing( $log, "'corrections'", 'A step that cost nothing is not.' );
msrwa_test_missing( $log, "'article'", 'Nor one whose price is unknown: it is counted as unpriced elsewhere, never as zero.' );

// A lot's pairing is a line of its own.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Spend::matching( 7, 3, 0.0042 );
msrwa_test_contains( $GLOBALS['wpdb']->log(), "'matching'", 'Reading a lot’s photographs is counted.' );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Spend::matching( 7, 3, 0.0 );
msrwa_test_assert( ! $GLOBALS['wpdb']->queries, 'A lot of text alone writes nothing.' );

// A window reads the lines by the day they were spent, scoped when asked.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'FROM wp_msrwa_spend', array( array( 'spend' => 1.25, 'matching' => 0.05 ) ) );
$window = MSRWA_Spend::window( 30, 'x.owner_id = 7' );
msrwa_test_assert( 1.25 === $window['spend_usd'] && 0.05 === $window['matching_usd'], 'A window returns the total and the pairing’s share.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'x.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)', 'By the day the money was spent.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'x.owner_id = 7', 'And only what the reader may see, when scoped.' );

// Filled once from what the site recorded, never twice.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SHOW TABLES', array( 'x' ) )->on( 'COUNT(*) FROM wp_msrwa_spend', 5 );
MSRWA_Spend::backfill();
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'INSERT INTO wp_msrwa_spend', 'A table already filled is not filled again.' );

msrwa_test_done( 'every amount spent is counted on its day, and outlives its lot' );
