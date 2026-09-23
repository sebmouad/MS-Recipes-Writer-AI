<?php
// A lot whose recipes are estimated above the per-recipe ceiling is refused
// at dispatch, before anything is spent: a recipe that would cross its ceiling
// is stopped part way, having paid for everything before the stop. The real
// test used to prove this by sending its own tiny ceiling; lots no longer carry
// one, so it is held here, where nothing can be dispatched by accident.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'i18n', 'profile', 'engine-settings', 'budget', 'batch' );
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-estimate.php';

msrwa_test_as_admin();
msrwa_test_settings( array( 'daily_budget_usd' => 0, 'monthly_budget_usd' => 0 ) );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'WHERE id = 7', array( array( 'id' => 7, 'status' => 'ready', 'profile' => 'article', 'recipes' => 1, 'budget_usd' => 0.001, 'language' => 'fr', 'owner_id' => 1, 'config_json' => '' ) ) );
$refused = MSRWA_Batch::dispatch( 7 );
msrwa_test_assert( is_wp_error( $refused ) && 'msrwa_over_ceiling' === $refused->get_error_code(), 'A lot estimated above its per-recipe ceiling is refused at dispatch; got ' . ( is_wp_error( $refused ) ? $refused->get_error_code() : 'a dispatch' ) );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'INSERT wp_msrwa_runs', 'Nothing is started for a refused lot.' );

msrwa_test_done( 'the per-recipe ceiling refuses a lot at dispatch' );
