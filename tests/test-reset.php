<?php
// Starting over, in two sizes. The settings go back to what ships and the
// keys stay unless asked; the data goes for everyone, but never while a
// recipe is being written, and never the drafts.
// The production settings class, before the harness can install its double.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/includes/class-msrwa-settings.php';
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images', 'catalog', 'db' );
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $fallback = false ) { return $GLOBALS['msrwa_test_options'][ $key ] ?? $fallback; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $key, $value, $autoload = false ) { $GLOBALS['msrwa_test_options'][ $key ] = $value; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $key ) { unset( $GLOBALS['msrwa_test_options'][ $key ] ); return true; } }
if ( ! function_exists( 'delete_post_meta_by_key' ) ) { function delete_post_meta_by_key( $key ) { $GLOBALS['msrwa_test_meta_dropped'][] = $key; return true; } }
foreach ( array( 'rights', 'engine-settings', 'sources', 'reset' ) as $class ) { require_once MSRWA_DIR . 'includes/class-msrwa-' . $class . '.php'; }

$GLOBALS['wpdb'] = ( new MSRWA_Fake_Wpdb() )->on( 'SHOW TABLES', 'present' );
$GLOBALS['msrwa_test_options'] = array(
	'msrwa_settings' => array( 'openai_key' => 'enc:openai', 'claude_key' => 'enc:claude', 'per_recipe_budget_usd' => 0.5, 'site_language' => 'en' ),
	'msrwa_engine_config' => array( 'routing' => array( 'article' => 'openai:high' ) ),
);
MSRWA_Reset::settings();
msrwa_test_assert( array( 'openai_key' => 'enc:openai', 'claude_key' => 'enc:claude' ) === $GLOBALS['msrwa_test_options']['msrwa_settings'], 'The settings go back to what ships and the stored keys stay: ' . json_encode( $GLOBALS['msrwa_test_options']['msrwa_settings'] ) );
msrwa_test_assert( ! isset( $GLOBALS['msrwa_test_options']['msrwa_engine_config'] ), 'The engine’s routing and levels go back to what ships.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'DELETE FROM wp_msrwa_catalog', 'The catalogue is refilled from what ships.' );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'wp_msrwa_runs', 'Resetting the settings leaves the work alone.' );

$GLOBALS['msrwa_test_options']['msrwa_settings']['per_recipe_budget_usd'] = 0.5;
MSRWA_Reset::settings( true );
msrwa_test_assert( ! isset( $GLOBALS['msrwa_test_options']['msrwa_settings'] ), 'Asked to, the keys go too.' );

// A recipe being written blocks the data reset: its worker would write into rows just deleted.
$GLOBALS['wpdb'] = ( new MSRWA_Fake_Wpdb() )->on( 'SHOW TABLES', 'present' )->on( "status IN ('queued','running')", '2' );
$refused = MSRWA_Reset::everything();
msrwa_test_assert( is_wp_error( $refused ), 'Nothing is erased while recipes are being written.' );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'DELETE FROM', 'Not a row.' );

$GLOBALS['wpdb'] = ( new MSRWA_Fake_Wpdb() )->on( 'SHOW TABLES', 'present' )->on( "status IN ('queued','running')", '0' );
$GLOBALS['msrwa_test_options']['msrwa_settings'] = array( 'gemini_key' => 'enc:gemini', 'site_language' => 'ar' );
$GLOBALS['msrwa_test_meta_dropped'] = array();
msrwa_test_assert( true === MSRWA_Reset::everything(), 'With nothing running, the data reset goes through.' );
$log = $GLOBALS['wpdb']->log();
foreach ( MSRWA_Reset::DATA as $table ) { msrwa_test_contains( $log, 'DELETE FROM wp_msrwa_' . $table, 'The ' . $table . ' table is emptied.' ); }
msrwa_test_missing( $log, 'TRUNCATE', 'Rows are deleted, not truncated: the numbering carries on, so no new run takes an old draft’s number.' );
msrwa_test_missing( $log, 'wp_posts', 'The drafts themselves are never touched.' );
msrwa_test_assert( array( '_msrwa_run_id' ) === $GLOBALS['msrwa_test_meta_dropped'], 'Only the drafts’ link to their deleted run goes.' );
msrwa_test_assert( array( 'gemini_key' => 'enc:gemini' ) === $GLOBALS['msrwa_test_options']['msrwa_settings'], 'The settings are reset with the data, keys kept.' );

// Only an administrator who sees everyone's work may erase it.
msrwa_test_contains( (string) file_get_contents( MSRWA_DIR . 'includes/class-msrwa-admin.php' ), "current_user_can( MSRWA_Rights::VIEW_ALL )", 'The data reset is refused to anyone who cannot see every lot.' );

echo "reset in two sizes, keys kept unless asked, never while a recipe runs OK\n";
