<?php
// Load the production settings class before the harness can install its double.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/includes/class-msrwa-settings.php';
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images', 'catalog' );
$GLOBALS['settings_options'] = array();
function get_option( $key, $fallback = false ) { return $GLOBALS['settings_options'][ $key ] ?? $fallback; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['settings_options'][ $key ] = $value; return true; }
function wp_salt( $scheme = 'auth' ) { return 'offline-test-salt-not-a-production-secret'; }

MSRWA_Settings::save( array( 'openai_key' => 'test-openai-original', 'gemini_key' => 'test-gemini-original', 'claude_key' => 'test-claude-original', 'quality_min_words' => 3000 ) );
$stored = get_option( MSRWA_Settings::OPTION );
msrwa_test_assert( 0 === strpos( $stored['openai_key'], 'enc:v1:' ), 'Credentials are encrypted at rest.' );
msrwa_test_assert( 'test-openai-original' === MSRWA_Settings::engine_keys()['openai'], 'Saved key decrypts for the engine.' );
MSRWA_Settings::save( array( 'openai_key' => '', 'gemini_key' => '********', 'claude_key' => '••••••' ) );
msrwa_test_assert( $stored === get_option( MSRWA_Settings::OPTION ), 'Blank and masked form fields preserve stored values exactly.' );
MSRWA_Settings::save( array( 'openai_key' => 'test-openai-replacement' ) );
$keys = MSRWA_Settings::engine_keys();
msrwa_test_assert( 'test-openai-replacement' === $keys['openai'], 'Replacement key is saved.' );
msrwa_test_assert( 'test-gemini-original' === $keys['gemini'] && 'test-claude-original' === $keys['claude'], 'Omitted providers are preserved.' );
msrwa_test_assert( 3000 === MSRWA_Settings::get()['quality_min_words'], 'Credential-only save preserves unrelated settings.' );

// A key handed to the engine under a name it does not configure is a key it
// never reads: a stored Claude key once reached the engine as `anthropic` and
// every Claude step failed with "No API key for claude".
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
$engine_providers = array_keys( (array) MSRWA_Engine_Config::defaults()['providers'] );
foreach ( array_keys( MSRWA_Settings::key_fields() ) as $provider ) {
	msrwa_test_assert( in_array( $provider, $engine_providers, true ), 'The engine has no provider named ' . $provider . '; its key would never be read.' );
}
$config = MSRWA_Engine_Config::create( array(), array( 'settings' => array( 'keys' => MSRWA_Settings::engine_keys() ) ) );
msrwa_test_assert( ! empty( $config->provider( 'claude' )['has_key'] ), 'The stored Claude key must be the one the engine resolves.' );

// The engine treats a non-empty `settings` as complete. Keys alone once meant
// every quality threshold compared against zero and the site's word count,
// page split and language never reached a prompt.
$handed = MSRWA_Settings::engine_settings();
msrwa_test_assert( 3000 === (int) $handed['quality_min_words'], 'The site word count must reach the engine with the keys.' );
foreach ( array( 'quality_min_headings', 'quality_min_ingredients', 'quality_min_steps', 'site_language' ) as $needed ) {
	msrwa_test_assert( array_key_exists( $needed, $handed ), $needed . ' must reach the engine.' );
}
msrwa_test_assert( ! isset( $handed['openai_key'] ) && ! isset( $handed['claude_key'] ) && ! isset( $handed['gemini_key'] ), 'Encrypted key fields travel only once, decrypted, under keys.' );
MSRWA_Engine_Input::use_settings( array() );
$config = MSRWA_Engine_Config::create( array(), array( 'settings' => $handed ) );
msrwa_test_assert( 3000 === (int) $config->settings()['quality_min_words'], 'The engine must read the site threshold, not zero.' );
msrwa_test_done( 'production settings save regression' );
