<?php
// A writer who sends photographs of the dish has given the research its visual
// evidence: the research is then written from them and the writer's text, with
// no web search and no downloads of other cooks' photographs. The searches and
// the pages they open were most of what a recipe's research cost.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
msrwa_test_load( 'estimate', 'profile', 'engine-settings' );

$package = array(
	'dish_identity' => array( 'name' => 'Tarte aux pommes', 'confidence' => 'haute', 'evidence' => 'titre et photographie' ),
	'recipe_outline' => array( 'servings' => 8, 'prep_minutes' => 30, 'cook_minutes' => 45, 'total_minutes' => 75, 'category' => 'Dessert', 'cuisine' => 'française', 'difficulty' => 'facile', 'source_url' => 'brief' ),
	'ingredients' => array(
		array( 'name' => 'pommes', 'quantity' => 6, 'unit' => '', 'role' => 'garniture', 'essential' => true, 'source_url' => 'brief' ),
		array( 'name' => 'pâte brisée', 'quantity' => 1, 'unit' => '', 'role' => 'fond', 'essential' => true, 'source_url' => 'brief' ),
		array( 'name' => 'crème', 'quantity' => 20, 'unit' => 'cl', 'role' => 'appareil', 'essential' => true, 'source_url' => 'culinary_practice' ),
		array( 'name' => 'œufs', 'quantity' => 2, 'unit' => '', 'role' => 'liaison', 'essential' => true, 'source_url' => 'culinary_practice' ),
	),
	'preparation' => array(
		array( 'step' => 1, 'action' => 'Foncer le moule.', 'cue' => 'pâte bien plaquée', 'minutes' => 5, 'temperature_c' => null, 'source_url' => 'culinary_practice' ),
		array( 'step' => 2, 'action' => 'Disposer les pommes.', 'cue' => 'rosace serrée', 'minutes' => 10, 'temperature_c' => null, 'source_url' => 'photograph' ),
		array( 'step' => 3, 'action' => 'Verser l’appareil.', 'cue' => 'pommes à demi couvertes', 'minutes' => 2, 'temperature_c' => null, 'source_url' => 'culinary_practice' ),
		array( 'step' => 4, 'action' => 'Cuire.', 'cue' => 'surface dorée', 'minutes' => 45, 'temperature_c' => 180, 'source_url' => 'brief' ),
	),
	'substitutions' => array(), 'accompaniments' => array(), 'common_failures' => array(), 'storage' => array(), 'food_safety' => array(),
	'references' => array(), 'visual_references' => array(), 'visual_observations' => array(),
	'uncertainties' => array(), 'originality_notes' => array(),
);
$seen = array( 'observable_details' => 'Tarte ronde, pommes en rosace, bords dorés.', 'colours' => 'doré', 'textures' => 'croustillant' );

$sent = array();
$transport = static function ( $url, $payload ) use ( &$sent, $package, $seen ) {
	$sent[] = $payload;
	$looks = false !== strpos( json_encode( $payload ), 'input_image' );
	return array( 'status' => 200, 'raw' => json_encode( array(
		'status' => 'completed',
		'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => json_encode( $looks ? $seen : $package ) ) ) ) ),
		'usage' => $looks ? array( 'input_tokens' => 1100, 'output_tokens' => 200 ) : array( 'input_tokens' => 4000, 'output_tokens' => 5000 ),
	) ) );
};
MSRWA_Engine_Call::$transport = $transport;

$reader_asked = array();
$reader = static function ( $image, $max ) use ( &$reader_asked ) { $reader_asked[] = $image; return array( 'mime' => 'image/jpeg', 'data' => base64_encode( 'bytes' ) ); };
$brief = array( 'title' => 'Tarte aux pommes', 'text' => 'Tarte aux pommes normande', 'images' => array( array( 'id' => 12, 'url' => 'http://127.0.0.1:8080/wp-content/uploads/tarte.jpg', 'title' => 'tarte' ) ) );
$options = array( 'config' => array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ) ), 'read_image' => $reader );

$result = MSRWA_Engine::run_step( 'research', $brief, $options );
$research = (array) ( $result->artifacts['research'] ?? array() );
$asks = array_values( array_filter( $sent, static function ( $payload ) { return false === strpos( json_encode( $payload ), 'input_image' ); } ) );

// The writer's photograph is read from the site's disk, not fetched from a
// local http address the engine may not open — it never was, before.
msrwa_test_assert( 1 === count( $reader_asked ) && 12 === $reader_asked[0]['id'], 'The editor’s photograph is read through the caller’s reader, by its id.' );
msrwa_test_assert( 1 === count( $asks ), 'One research call; got ' . count( $asks ) );
msrwa_test_assert( empty( $asks[0]['tools'] ), 'No web search tool is offered when the editor sent photographs.' );
msrwa_test_contains( json_encode( $asks[0], JSON_UNESCAPED_UNICODE ), 'no web search is run', 'The research is asked with the photographs prompt.' );
msrwa_test_contains( json_encode( $asks[0], JSON_UNESCAPED_UNICODE ), 'Tarte ronde, pommes en rosace', 'The research is told what the photograph shows.' );

msrwa_test_assert( 1 === count( (array) ( $research['visual_observations'] ?? array() ) ), 'The editor’s photograph is the package’s observation.' );
msrwa_test_assert( 'editor' === ( $research['visual_references'][0]['source_url'] ?? '' ) && 1 === (int) ( $research['visual_references'][0]['tier'] ?? 0 ), 'It is cited as the editor’s, tier 1.' );
msrwa_test_contains( (string) ( $research['visual_observations'][0]['observable_details'] ?? '' ), 'rosace', 'What it shows reaches every later step.' );
$step = end( $result->steps );
msrwa_test_assert( (int) $step['passed'] === (int) $step['total'], 'A package written from photographs passes its own contract: ' . json_encode( $step['checks'] ) );
msrwa_test_assert( 5100 === (int) ( $step['usage']['input_tokens'] ?? 0 ), 'Reading the photograph is billed to the research: ' . json_encode( $step['usage'] ) );
msrwa_test_assert( 0 === (int) ( $step['usage']['web_searches'] ?? 0 ), 'No search is billed.' );

// Set to search always, the research searches as it did.
$sent = array();
$reader_asked = array();
$always = $options;
$always['config']['research'] = array( 'web_search' => 'always' );
MSRWA_Engine::run_step( 'research', $brief, $always );
$asks = array_values( array_filter( $sent, static function ( $payload ) { return false === strpos( json_encode( $payload ), 'input_image' ); } ) );
msrwa_test_assert( ! empty( $asks[0]['tools'] ), '`research.web_search` set to `always` searches even with photographs.' );

// Without a photograph, the research searches.
$sent = array();
MSRWA_Engine::run_step( 'research', array( 'title' => 'Tarte aux pommes', 'text' => 'Tarte' ), $options );
msrwa_test_assert( ! empty( $sent[0]['tools'] ), 'A brief without a photograph is researched on the web.' );

// A photograph that cannot be read is no evidence: the research searches after all.
$sent = array();
$blind = $options;
$blind['read_image'] = static function () { return array( 'error' => 'fichier introuvable' ); };
$fallback = MSRWA_Engine::run_step( 'research', $brief, $blind );
$asks = array_values( array_filter( $sent, static function ( $payload ) { return false === strpos( json_encode( $payload ), 'input_image' ); } ) );
msrwa_test_assert( ! empty( $asks[0]['tools'] ), 'With no readable photograph, the research searches the web.' );
$warned = array_filter( $fallback->events, static function ( $event ) { return false !== strpos( (string) ( $event['message'] ?? '' ), 'searching the web instead' ); } );
msrwa_test_assert( (bool) $warned, 'And says why.' );
MSRWA_Engine_Call::$transport = null;

// The setting is one of two words; anything else is the default.
$config = MSRWA_Engine_Config::create( array( 'research' => array( 'web_search' => 'sometimes' ) ) );
msrwa_test_assert( 'without_images' === $config->get( 'research.web_search' ), 'An unknown value reads as `without_images`.' );
msrwa_test_assert( 'always' === MSRWA_Engine_Config::create( array( 'research' => array( 'web_search' => 'always' ) ) )->get( 'research.web_search' ), '`always` is kept.' );

// The estimate follows: a recipe with a photograph pays no search.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$web = MSRWA_Estimate::recipe( MSRWA_Profile::FULL );
$pictured = MSRWA_Estimate::recipe( MSRWA_Profile::FULL, array(), true );
msrwa_test_assert( 0 === (int) $pictured['steps']['research']['searches'], 'A pictured recipe is estimated without a search.' );
msrwa_test_assert( $pictured['steps']['research']['cost_usd'] < $web['steps']['research']['cost_usd'] / 3, sprintf( 'Its research is estimated well under the searched one: %.4f against %.4f.', $pictured['steps']['research']['cost_usd'], $web['steps']['research']['cost_usd'] ) );
$lot = MSRWA_Estimate::lot( MSRWA_Profile::FULL, 3, 1 );
msrwa_test_assert( abs( $lot['cost_usd'] - ( $pictured['cost_usd'] + 2 * $web['cost_usd'] + (float) $lot['matching_usd'] ) ) < 0.00001, 'A lot of three recipes and one photograph prices one pictured recipe and two searched.' );

msrwa_test_done( 'a recipe sent with photographs is researched from them, without a web search' );
