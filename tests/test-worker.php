<?php
// Recipes used to wait on WordPress cron: a visit before each tick, and every
// recipe of a lot one after another in a single wp-cron.php. The site now calls
// itself for each queued recipe, three at a time, with a signed request.
$GLOBALS['msrwa_test_posts_sent'] = array();
function wp_remote_post( $url, $args = array() ) { $GLOBALS['msrwa_test_posts_sent'][] = array( 'url' => $url, 'args' => $args ); return array(); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt'; }
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'i18n', 'profile', 'db' );
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
foreach ( array( 'batch', 'queue', 'run', 'worker' ) as $class ) { require_once dirname( __DIR__ ) . '/includes/class-msrwa-' . $class . '.php'; }

$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->default_var( 0 );
$GLOBALS['msrwa_test_next_scheduled'] = false;

// Queueing a recipe starts it: one call to the site's own admin-ajax, not waited on.
MSRWA_Run::queue( 41, 5 );
$sent = $GLOBALS['msrwa_test_posts_sent'];
msrwa_test_assert( 1 === count( $sent ), 'A queued recipe is started at once.' );
msrwa_test_contains( $sent[0]['url'], 'admin-ajax.php', 'Through the site’s own admin-ajax.' );
msrwa_test_assert( false === $sent[0]['args']['blocking'], 'Without waiting for the tick to finish.' );
msrwa_test_assert( 'msrwa_worker' === $sent[0]['args']['body']['action'] && 41 === $sent[0]['args']['body']['run'], 'For that recipe.' );

// Signed: nobody is logged in on a loopback, so the signature is the permission.
$body = $sent[0]['args']['body'];
msrwa_test_assert( MSRWA_Worker::valid( 41, $body['expires'], $body['sig'] ), 'The site’s own call is accepted.' );
msrwa_test_assert( ! MSRWA_Worker::valid( 42, $body['expires'], $body['sig'] ), 'Not for another recipe.' );
msrwa_test_assert( ! MSRWA_Worker::valid( 41, $body['expires'], 'forged' ), 'Not with a forged signature.' );
msrwa_test_assert( ! MSRWA_Worker::valid( 41, time() - 1, $body['sig'] ), 'Not once it has expired.' );

// Three at a time: a watchdog re-arming a hundred recipes starts three.
foreach ( range( 42, 60 ) as $id ) { MSRWA_Run::queue( $id, 5 ); }
msrwa_test_assert( MSRWA_Worker::PARALLEL === count( $GLOBALS['msrwa_test_posts_sent'] ), 'No more than three recipes are started by one request; started ' . count( $GLOBALS['msrwa_test_posts_sent'] ) . '.' );

// Cron stays behind it: the event is scheduled whatever the worker does.
msrwa_test_assert( in_array( 'msrwa_run_step', array_column( $GLOBALS['msrwa_test_scheduled'], 'hook' ), true ), 'The cron event is still scheduled, as the safety net.' );

// Where the site cannot call itself either, the lot page carries the lot on:
// a waiting recipe whose tick is gone or late is overdue, and only that one.
$GLOBALS['msrwa_test_next_scheduled'] = false;
msrwa_test_assert( MSRWA_Run::overdue( array( 'id' => 41, 'status' => 'queued' ) ), 'A waiting recipe with no tick scheduled is overdue.' );
$GLOBALS['msrwa_test_next_scheduled'] = time() - 120;
msrwa_test_assert( MSRWA_Run::overdue( array( 'id' => 41, 'status' => 'queued' ) ), 'So is one whose tick is two minutes late.' );
$GLOBALS['msrwa_test_next_scheduled'] = time() + 5;
msrwa_test_assert( ! MSRWA_Run::overdue( array( 'id' => 41, 'status' => 'queued' ) ), 'One about to run is not.' );
msrwa_test_assert( ! MSRWA_Run::overdue( array( 'id' => 41, 'status' => 'running' ) ), 'Nor one already running.' );
update_option( MSRWA_Queue::HELD, 1 );
msrwa_test_assert( 0 === MSRWA_Run::nudge( 3 ), 'A paused queue is not carried on by the page either.' );
update_option( MSRWA_Queue::HELD, 0 );

msrwa_test_done( 'a queued recipe is started at once, three at a time' );
