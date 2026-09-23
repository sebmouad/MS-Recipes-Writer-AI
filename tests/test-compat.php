<?php
// Only a model able to serve a step may be given it. gpt-5-nano searches the
// web on paper, yet on the research step it reasoned its whole answer away
// and was cut at the ceiling: routed through `openai:low`, it was saved
// without a word and failed on the first recipe.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'i18n', 'profile', 'engine-settings', 'budget', 'batch', 'ui', 'catalog', 'compat' );
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-estimate.php';
msrwa_test_as_admin();

// What each step needs, against the models the plugin ships.
msrwa_test_assert( MSRWA_Compat::allowed( 'openai', 'gpt-5.6-luna', 'research' ), 'A text model that searches the web may do the research.' );
msrwa_test_assert( ! MSRWA_Compat::allowed( 'openai', 'gpt-image-2.5-flare', 'research' ), 'An image model may not do the research.' );
msrwa_test_assert( MSRWA_Compat::allowed( 'openai', 'gpt-image-2.5-flare', 'featured_image' ), 'An image model may draw the featured image.' );
msrwa_test_assert( ! MSRWA_Compat::allowed( 'openai', 'gpt-5.6-luna', 'facebook_image' ), 'A text model may not draw the collage.' );
msrwa_test_assert( MSRWA_Compat::allowed( 'openai', 'gpt-5.6-luna', 'vision' ), 'A text model that reads images may read the photographs.' );
msrwa_test_assert( ! MSRWA_Compat::allowed( 'openai', 'gpt-image-2.5-flare', 'vision' ), 'An image model takes images in but cannot describe them in words.' );
msrwa_test_assert( MSRWA_Compat::allowed( 'claude', 'claude-sonnet-5', 'final_approval' ), 'The final approval needs a model that reads images and writes.' );

// A fetched row states only what the provider's list says; it does not erase
// what the plugin ships about the model.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SHOW TABLES', array( 'wp_msrwa_catalog' ) );
$GLOBALS['wpdb']->on( "model_id = 'gpt-5.6-luna'", array( array( 'provider' => 'openai', 'model_id' => 'gpt-5.6-luna', 'enabled' => 1, 'capabilities_json' => '{"text":true,"image_generation":false}', 'input_usd' => 0.2, 'output_usd' => 1.2, 'served' => null, 'limits_json' => '{}', 'steps_json' => '[]' ) ) );
msrwa_test_assert( MSRWA_Compat::allowed( 'openai', 'gpt-5.6-luna', 'research' ), 'A fetched row that is silent on web search keeps what the plugin ships.' );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SHOW TABLES', array( 'wp_msrwa_catalog' ) );
$GLOBALS['wpdb']->on( "model_id = 'gpt-5.6-luna'", array( array( 'provider' => 'openai', 'model_id' => 'gpt-5.6-luna', 'enabled' => 1, 'capabilities_json' => '{"text":true,"web_search":false}', 'input_usd' => 0.2, 'output_usd' => 1.2, 'served' => null, 'limits_json' => '{}', 'steps_json' => '[]' ) ) );
msrwa_test_assert( ! MSRWA_Compat::allowed( 'openai', 'gpt-5.6-luna', 'research' ), 'What the row states wins over what the plugin ships.' );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SHOW TABLES', array( 'wp_msrwa_catalog' ) );
$GLOBALS['wpdb']->on( "model_id = 'gpt-5.6-luna'", array( array( 'provider' => 'openai', 'model_id' => 'gpt-5.6-luna', 'enabled' => 1, 'capabilities_json' => '{}', 'input_usd' => 0.2, 'output_usd' => 1.2, 'served' => null, 'limits_json' => '{}', 'steps_json' => '["article"]' ) ) );
msrwa_test_contains( MSRWA_Compat::refusal( 'openai', 'gpt-5.6-luna', 'research' ), 'Modèles', 'A step the owner took the model off is refused.' );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SHOW TABLES', array( 'wp_msrwa_catalog' ) );
$GLOBALS['wpdb']->on( "model_id = 'gpt-5.6-luna'", array( array( 'provider' => 'openai', 'model_id' => 'gpt-5.6-luna', 'enabled' => 0, 'input_usd' => 0.2, 'output_usd' => 1.2, 'served' => null, 'capabilities_json' => '{}', 'limits_json' => '{}', 'steps_json' => '[]' ) ) );
msrwa_test_contains( MSRWA_Compat::refusal( 'openai', 'gpt-5.6-luna', 'article' ), 'désactivé', 'A model switched off on the Modèles screen serves no step.' );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();

// Measured unfit, whatever the capability says.
msrwa_test_contains( MSRWA_Compat::refusal( 'openai', 'gpt-5-nano', 'research' ), 'Mesuré', 'gpt-5-nano is refused the research, with what was measured.' );
msrwa_test_contains( MSRWA_Compat::refusal( 'openai', 'gpt-5.4-mini', 'research' ), 'Mesuré', 'gpt-5.4-mini is refused the research, with what was measured.' );

// A capability nobody established is not assumed.
msrwa_test_contains( MSRWA_Compat::refusal( 'openai', 'gpt-inconnu', 'research' ), 'rien n’établit', 'An unknown model is not handed the research on faith, and the reason says it is unknown rather than unable.' );
msrwa_test_contains( MSRWA_Compat::refusal( 'openai', 'gpt-image-2.5-flare', 'article' ), 'ne sait pas', 'A known lack reads as a lack.' );

// The whole routing, resolved as the engine resolves it.
$problems = MSRWA_Compat::problems( array( 'routing' => array( 'research' => 'openai:gpt-5-nano' ), 'models' => array( 'openai' => array( 'gpt-5-nano' => array( 0.05, 0.4 ) ) ) ) );
msrwa_test_assert( isset( $problems['research'] ) && 'openai:gpt-5-nano' === $problems['research']['route'], 'A routing that gives the research to nano is reported for that step.' );
msrwa_test_assert( 1 === count( $problems ), 'Only the step that cannot be served is reported; got ' . implode( ', ', array_keys( $problems ) ) );
msrwa_test_assert( ! MSRWA_Compat::problems( array() ), 'The shipped routing serves every step.' );

// Saving refuses it and stores nothing.
$GLOBALS['msrwa_test_options'][ MSRWA_Engine_Settings::OPTION ] = array( 'untouched' => true );
MSRWA_Engine_Settings::save( array( 'routing' => wp_json_encode( array( 'research' => 'openai:gpt-5-nano' ) ) ) );
msrwa_test_assert( isset( MSRWA_Engine_Settings::$refused['research'] ), 'Saving a route nano cannot serve is refused.' );
msrwa_test_assert( array( 'untouched' => true ) === $GLOBALS['msrwa_test_options'][ MSRWA_Engine_Settings::OPTION ], 'A refused save stores nothing.' );
MSRWA_Engine_Settings::save( array( 'routing' => wp_json_encode( array( 'research' => 'openai:gpt-5.6-luna' ) ) ) );
msrwa_test_assert( ! MSRWA_Engine_Settings::$refused, 'A route the model can serve is saved.' );

// A lot routed to it is refused at dispatch, before a cent is spent.
unset( $GLOBALS['msrwa_test_options'][ MSRWA_Engine_Settings::OPTION ] );
msrwa_test_settings( array( 'daily_budget_usd' => 0, 'monthly_budget_usd' => 0 ) );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'WHERE id = 9', array( array( 'id' => 9, 'status' => 'ready', 'profile' => 'article', 'recipes' => 1, 'budget_usd' => 5, 'language' => 'fr', 'owner_id' => 1, 'config_json' => wp_json_encode( array( 'routing' => array( 'research' => 'openai:gpt-5-nano' ) ) ) ) ) );
$refused = MSRWA_Batch::dispatch( 9 );
msrwa_test_assert( is_wp_error( $refused ) && 'msrwa_incompatible_route' === $refused->get_error_code(), 'A lot whose research would run on nano is refused at dispatch; got ' . ( is_wp_error( $refused ) ? $refused->get_error_code() : 'a dispatch' ) );
msrwa_test_contains( $refused->get_error_message(), 'Recherche', 'The refusal names the step.' );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'INSERT wp_msrwa_runs', 'Nothing is started.' );

// Both model screens read one list of steps and one family rule.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$steps = MSRWA_Compat::steps();
msrwa_test_assert( isset( $steps['vision'], $steps['featured_image'], $steps['facebook_image'], $steps['research'] ), 'The shared list names every routed step, the photograph reading included.' );
msrwa_test_assert( ! isset( $steps['image'], $steps['corrections'] ), 'Neither the shared image route nor a step that calls no model is listed.' );
msrwa_test_assert( $steps['featured_image']['image'] && ! $steps['vision']['image'], 'An image step is served by a model that draws, the photograph reading by one that writes.' );
$offer = MSRWA_Compat::choices( 'research', MSRWA_Engine_Config::create( array() ) );
$values = array();
foreach ( $offer as $list ) { foreach ( $list as $choice ) { $values[ $choice['value'] ] = $choice['blocked']; } }
msrwa_test_assert( isset( $values['openai:medium'] ) && '' === $values['openai:medium'], 'The research is offered the standard level, usable.' );
msrwa_test_assert( ! isset( $values['openai:gpt-image-2.5-flare'] ), 'The research is never offered an image model.' );
foreach ( $values as $value => $why ) { msrwa_test_assert( '' === $why || '' !== trim( $why ), 'A refused choice always says why: ' . $value ); }

msrwa_test_done( 'only a model able to serve a step is given it' );
