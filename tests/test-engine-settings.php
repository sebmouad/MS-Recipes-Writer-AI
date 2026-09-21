<?php
// The engine is not modified to suit the plugin: what an administrator changes
// is handed to it as its own caller layer. These hold that arrangement in
// place — every group reachable, and only the difference stored.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-engine-settings.php';

$GLOBALS['saved_engine_config'] = array( 'language' => 'fr' );
function update_option( $name, $value, $autoload = false ) { $GLOBALS['saved_engine_config'] = $value; }
$invalid = MSRWA_Engine_Settings::save( array( 'routing' => '{broken', 'language' => 'en' ) );
msrwa_test_assert( array( 'routing' ) === $invalid, 'Malformed JSON identifies its group.' );
msrwa_test_assert( array( 'language' => 'fr' ) === $GLOBALS['saved_engine_config'], 'Invalid submission preserves all saved settings atomically.' );
$parsed = MSRWA_Engine_Settings::parse( array( 'attempts' => '{"default":3}' ) );
msrwa_test_assert( 3 === $parsed['config']['attempts']['default'], 'Preview parses unsaved values.' );
msrwa_test_assert( array( 'language' => 'fr' ) === $GLOBALS['saved_engine_config'], 'Preview never changes saved values.' );
msrwa_test_assert( array( 'routing' ) === MSRWA_Engine_Settings::parse( array( 'routing' => array() ) )['invalid'], 'Non-string JSON input is rejected safely.' );
msrwa_test_assert( array( 'price' => array( 1, 3 ) ) === MSRWA_Engine_Settings::difference( array( 'price' => array( 1, 3 ) ), array( 'price' => array( 1, 2 ) ) ), 'Price lists are retained whole, not sparse patches.' );

$defaults = array( 'budget_usd' => 0.0, 'concurrency' => 4, 'nested' => array( 'a' => 1, 'b' => 2 ) );
$diff = function ( $value, $default = null ) use ( $defaults ) { return MSRWA_Engine_Settings::difference( $value, null === $default ? $defaults : $default ); };

msrwa_test_assert( array() === $diff( $defaults ), 'Saving the defaults unchanged stores nothing.' );
msrwa_test_assert( array( 'concurrency' => 8 ) === $diff( array_merge( $defaults, array( 'concurrency' => 8 ) ) ), 'Only the value that moved is stored.' );
msrwa_test_assert( array( 'nested' => array( 'b' => 99 ) ) === $diff( array( 'nested' => array( 'a' => 1, 'b' => 99 ) ) ), 'A nested change keeps its path and drops its siblings.' );
msrwa_test_assert( isset( $diff( array( 'ma_propre_etape' => array() ) )['ma_propre_etape'] ), 'A key the engine does not ship is still stored.' );
msrwa_test_assert( array( 'concurrency' => '4' ) === $diff( array( 'concurrency' => '4' ) ), 'A value that changed type has changed.' );

// Every configuration group the engine carries must have a field. Without this
// the promise that nothing is hardcoded quietly stops being true.
$engine = array_keys( MSRWA_Engine_Config::create()->to_array() );
$offered = array_keys( array_merge( MSRWA_Engine_Settings::simple(), MSRWA_Engine_Settings::structural() ) );
foreach ( $offered as $group ) {
	msrwa_test_assert( in_array( $group, $engine, true ), 'The screen offers "' . $group . '", which the engine does not have.' );
}
// `language` has its own field; `settings` carries the API keys and is edited
// on the settings screen; `_provenance` records who decided what and is not a
// setting. Everything else must be reachable.
foreach ( array_diff( $engine, $offered, array( 'language', 'settings', '_provenance' ) ) as $group ) {
	msrwa_test_assert( false, 'The engine carries "' . $group . '", which no field can reach.' );
}

// The keys reach the engine without the engine being changed to fetch them.
putenv( 'OPENAI_API_KEY=depuis-le-serveur' );
$supplied = MSRWA_Engine_Config::create( array( 'settings' => array( 'keys' => array( 'openai' => 'depuis-wordpress' ) ) ) );
$wire = $supplied->provider( 'openai', 'gpt-5.6-luna' );
msrwa_test_contains( json_encode( $wire['headers'] ), 'depuis-wordpress', 'A key stored in WordPress is the one sent.' );
msrwa_test_missing( json_encode( $supplied->to_array() ), 'depuis-wordpress', 'A key must never reach a stored record.' );
putenv( 'OPENAI_API_KEY' );

msrwa_test_done( 'engine settings OK' );
