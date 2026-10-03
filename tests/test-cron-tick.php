<?php
// The five-minute tick runs under one lock, records what each part took, and
// says when WP-Cron has stopped; the list search ignores what is too short to
// filter anything.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'i18n', 'db', 'sources', 'retention', 'run', 'schedule', 'plugin', 'ledger' );
if ( ! defined( 'DB_NAME' ) ) { define( 'DB_NAME', 'site' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
$GLOBALS['msrwa_test_options'] = array();

// Another tick holds the lock: this one does nothing at all.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'GET_LOCK', '0' );
msrwa_test_assert( false === MSRWA_Plugin::cleanup(), 'A tick that finds the lock taken returns at once.' );
msrwa_test_assert( 1 === count( $GLOBALS['wpdb']->queries ), 'It asks for the lock and nothing else.' );
msrwa_test_assert( ! isset( $GLOBALS['msrwa_test_options']['msrwa_cleanup_last'] ), 'And writes nothing down.' );

// With the lock: the three parts, in order, then the lock is given back.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'GET_LOCK', '1' );
$took = MSRWA_Plugin::cleanup();
msrwa_test_assert( array( 'recover', 'due', 'prune' ) === array_keys( (array) $took ), 'Stuck recipes first, then the lots due, then retention.' );
$log = $GLOBALS['wpdb']->log();
msrwa_test_contains( $log, "SELECT GET_LOCK('msrwa_cleanup_" . md5( DB_NAME . '|wp_' ), 'The lock is named after this site: the name is server-wide.' );
msrwa_test_contains( $log, 'RELEASE_LOCK', 'The lock is released.' );
msrwa_test_assert( strrpos( $log, 'RELEASE_LOCK' ) > strrpos( $log, 'msrwa_batches' ), 'After the work, not before.' );
$last = $GLOBALS['msrwa_test_options']['msrwa_cleanup_last'] ?? array();
msrwa_test_assert( isset( $last['seconds'], $last['parts']['prune'] ), 'How long the tick and each part took is written down.' );

// WP-Cron that stopped: late by more than two ticks, and only then.
$GLOBALS['msrwa_test_next_scheduled'] = time() - 30 * 60;
msrwa_test_assert( 30 === MSRWA_Plugin::cron_stalled_minutes(), 'A tick due half an hour ago means WP-Cron has stopped.' );
$GLOBALS['msrwa_test_next_scheduled'] = time() - 5 * 60;
msrwa_test_assert( 0 === MSRWA_Plugin::cron_stalled_minutes(), 'Five minutes late is a quiet site, not a stopped cron.' );
$GLOBALS['msrwa_test_next_scheduled'] = time() + 60;
msrwa_test_assert( 0 === MSRWA_Plugin::cron_stalled_minutes(), 'A tick still to come is not late.' );
$GLOBALS['msrwa_test_next_scheduled'] = 1789003600;

// The search filters from three letters; shorter matches nearly everything.
msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Ledger::runs( array( 'search' => 'ta' ) );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'post_title LIKE', 'Two letters filter nothing.' );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Ledger::runs( array( 'search' => ' tar ' ) );
msrwa_test_contains( $GLOBALS['wpdb']->log(), "post_title LIKE '%tar%'", 'Three do, trimmed.' );

msrwa_test_done( 'the cleanup tick is locked, measured and watched' );
