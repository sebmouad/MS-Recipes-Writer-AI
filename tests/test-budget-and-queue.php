<?php
// The two operator controls: a spending ceiling that refuses, and a queue that
// can be held. Both decide whether money is spent, so both are pinned here.
require __DIR__ . '/bootstrap.php';
require_once MSRWA_DIR . 'includes/class-msrwa-i18n.php';
require_once MSRWA_DIR . 'includes/class-msrwa-rights.php';
require_once MSRWA_DIR . 'includes/class-msrwa-budget.php';
require_once MSRWA_DIR . 'includes/class-msrwa-queue.php';
require_once MSRWA_DIR . 'includes/class-msrwa-run.php';

// --- Ceilings ----------------------------------------------------------

msrwa_test_settings( array( 'daily_budget_usd' => 0, 'monthly_budget_usd' => 0 ) );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'FROM wp_msrwa_runs', array( array( 'day' => 4.0, 'month' => 40.0 ) ) );
msrwa_test_assert( '' === MSRWA_Budget::refusal( 999.0 ), 'A ceiling of zero is no ceiling, whatever the lot costs.' );

// A budget belongs to the site, so the spend behind it is never scoped to the
// reader: a writer refused a lot has to be able to see why.
msrwa_test_as_editor( 7 );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'FROM wp_msrwa_runs', array( array( 'day' => 4.0, 'month' => 40.0 ) ) );
MSRWA_Budget::spent();
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'owner_id', 'Spend against a ceiling counts the whole site, not one writer.' );

msrwa_test_settings( array( 'daily_budget_usd' => 5, 'monthly_budget_usd' => 100 ) );
$state = MSRWA_Budget::state();
msrwa_test_assert( 80 === $state['daily']['share'], 'Daily ceiling reports how full it is.' );
msrwa_test_assert( 1.0 === $state['daily']['left'], 'Daily ceiling reports what is left.' );
msrwa_test_assert( ! $state['daily']['exceeded'], 'A ceiling not yet reached is not exceeded.' );
msrwa_test_assert( null === $state['monthly']['left'] || 60.0 === $state['monthly']['left'], 'Monthly ceiling reports what is left.' );

msrwa_test_assert( '' === MSRWA_Budget::refusal( 0.5 ), 'A lot that fits under every ceiling is not refused.' );
$refusal = MSRWA_Budget::refusal( 2.0 );
msrwa_test_contains( $refusal, 'ne reste que', 'A lot that would cross a ceiling is refused before it starts.' );
msrwa_test_assert( '' === MSRWA_Budget::refusal(), 'Nothing is refused while the ceilings hold.' );

msrwa_test_settings( array( 'daily_budget_usd' => 4, 'monthly_budget_usd' => 100 ) );
msrwa_test_contains( MSRWA_Budget::refusal(), 'plafond du jour', 'A ceiling already reached refuses with no lot at all.' );
msrwa_test_assert( '' !== MSRWA_Budget::refusal(), 'Once a ceiling is reached, nothing more is paid for.' );

msrwa_test_settings( array( 'daily_budget_usd' => 0, 'monthly_budget_usd' => 40 ) );
msrwa_test_contains( MSRWA_Budget::refusal(), 'trente derniers jours', 'The monthly ceiling refuses on its own.' );

// --- The queue ---------------------------------------------------------

$GLOBALS['msrwa_test_options'] = array();
$GLOBALS['msrwa_test_next_scheduled'] = false;
$GLOBALS['msrwa_test_scheduled'] = array();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( "WHERE status = 'queued'", array( 3, 1, 2 ) );

msrwa_test_assert( ! MSRWA_Queue::held(), 'The queue runs until somebody holds it.' );
MSRWA_Queue::hold();
msrwa_test_assert( MSRWA_Queue::held(), 'A hold is remembered.' );

$rearmed = MSRWA_Queue::release();
msrwa_test_assert( ! MSRWA_Queue::held(), 'Releasing lifts the hold.' );
msrwa_test_assert( 3 === $rearmed, 'Releasing re-arms every waiting recipe; cron fires one event per run.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), "ORDER BY priority DESC, id ASC", 'Waiting recipes are re-armed in priority order.' );
msrwa_test_assert( 3 === count( $GLOBALS['msrwa_test_scheduled'] ), 'Every re-armed recipe gets its own cron event.' );

// Stalled is a statement of fact, not a guess: work waiting, nothing working,
// and waiting for longer than several cron ticks.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'FROM wp_msrwa_runs', array( array( 'waiting' => 2, 'working' => 0, 'oldest' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'expired' => 0 ) ) );
msrwa_test_assert( ! MSRWA_Queue::stalled(), 'A queue that has been waiting a minute is not stalled.' );

$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'FROM wp_msrwa_runs', array( array( 'waiting' => 2, 'working' => 0, 'oldest' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'expired' => 0 ) ) );
msrwa_test_assert( MSRWA_Queue::stalled(), 'An hour of waiting with nothing running is cron, and says so.' );

MSRWA_Queue::hold();
msrwa_test_assert( ! MSRWA_Queue::stalled(), 'A held queue is not a stalled queue.' );

msrwa_test_assert( 10 === MSRWA_Queue::prioritise( 4, 999 ), 'Priority is clamped, so no recipe can starve the rest.' );
msrwa_test_assert( -10 === MSRWA_Queue::prioritise( 4, -999 ), 'Priority is clamped at the bottom too.' );

// --- What a hold and a ceiling do to a recipe in flight -----------------

// Either reason parks the recipe: back to waiting, lease released, every step
// it finished still there. Nothing is marked failed, because nothing failed.
$GLOBALS['msrwa_test_options'] = array();
msrwa_test_settings( array( 'daily_budget_usd' => 0, 'monthly_budget_usd' => 0 ) );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Queue::hold();
MSRWA_Run::tick( 12 );
msrwa_test_contains( $GLOBALS['wpdb']->log(), '"status":"queued"', 'A held queue sends a running recipe back to waiting.' );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'lock_token = ', 'A held queue never claims a lease.' );
msrwa_test_missing( $GLOBALS['wpdb']->log(), '"status":"failed"', 'A hold is not a failure.' );

$GLOBALS['msrwa_test_options'] = array();
msrwa_test_settings( array( 'daily_budget_usd' => 4, 'monthly_budget_usd' => 0 ) );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'FROM wp_msrwa_runs', array( array( 'day' => 4.0, 'month' => 40.0 ) ) );
MSRWA_Run::tick( 12 );
msrwa_test_contains( $GLOBALS['wpdb']->log(), '"status":"queued"', 'A ceiling reached mid-lot parks the recipe instead of paying for another wave.' );
msrwa_test_missing( $GLOBALS['wpdb']->log(), '"status":"failed"', 'A recipe stopped by a ceiling has not failed; it is waiting for room.' );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'updated_at', 'Parking does not make a recipe look newer than those waiting behind it.' );

msrwa_test_done( 'budget ceilings and queue control' );
