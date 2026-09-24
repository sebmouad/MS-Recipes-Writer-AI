<?php
// A French research once read "润ir les pommes": a Chinese character in the
// middle of a French word, in an answer that scored 13/13. Letters from an
// alphabet the article is not written in are now a failed check, and when they
// are all that failed, the step is asked once more.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

$stray = MSRWA_Engine_Score::stray_script( array( 'ingredients' => array( array( 'role' => '润ir les pommes et favoriser le brunissement' ) ) ), 'fr' );
msrwa_test_assert( 1 === count( $stray ) && false !== strpos( $stray[0], '润ir' ), 'A Chinese character in French is found, with its context: ' . json_encode( $stray, JSON_UNESCAPED_UNICODE ) );
msrwa_test_assert( array() === MSRWA_Engine_Score::stray_script( array( 'text' => 'Crème brûlée, 180 °C, œufs — ½ litre, 5 µg, « façon » ÿ' ), 'fr' ), 'French with its accents, ligatures and symbols is clean.' );
msrwa_test_assert( array() === MSRWA_Engine_Score::stray_script( array( 'text' => 'طاجين بالدجاج، 180 °C، Harissa' ), 'ar' ), 'Arabic with Latin names and units is clean.' );
msrwa_test_assert( 1 === count( MSRWA_Engine_Score::stray_script( array( 'text' => 'Poulet yassa إضافة' ), 'fr' ) ), 'Arabic in a French article is stray.' );
msrwa_test_assert( 1 === count( MSRWA_Engine_Score::stray_script( array( 'text' => 'Суп à la tomate' ), 'es' ) ), 'Cyrillic in Spanish is stray.' );
msrwa_test_assert( array() === MSRWA_Engine_Score::stray_script( array( 'source_url' => 'https://例え.jp/レシピ' ), 'fr' ), 'An address is not text.' );
msrwa_test_assert( array() === MSRWA_Engine_Score::stray_script( array( 'text' => '5 μg de vitamine, Δ de 10 °C' ), 'fr' ), 'Greek letters are units and symbols, not stray.' );

$fixed = json_encode( array( 'changes' => array( array( 'quote' => 'x', 'replace' => '润ir les pommes.' ) ) ), JSON_UNESCAPED_UNICODE );
$scored = MSRWA_Engine_Score::step( 'fact_check', $fixed, array(), array() );
msrwa_test_assert( isset( $scored['checks']['one alphabet'] ) && ! $scored['checks']['one alphabet']['pass'], 'A correction that would put one into the article fails its scorecard.' );

// Through the engine: one attempt configured, the stray answer is asked once
// more, and the clean second answer is the one kept.
$package = array(
	'dish_identity' => array( 'name' => 'Tarte aux pommes', 'confidence' => 'haute', 'evidence' => 'titre' ),
	'recipe_outline' => array( 'servings' => 8, 'prep_minutes' => 30, 'cook_minutes' => 45, 'total_minutes' => 75, 'category' => 'Dessert', 'cuisine' => 'française', 'difficulty' => 'facile', 'source_url' => 'brief' ),
	'ingredients' => array_fill( 0, 4, array( 'name' => 'pommes', 'quantity' => 6, 'unit' => '', 'role' => '润ir la tarte', 'essential' => true, 'source_url' => 'brief' ) ),
	'preparation' => array_map( static function ( $n ) { return array( 'step' => $n, 'action' => 'Cuire.', 'cue' => 'doré', 'minutes' => 5, 'temperature_c' => null, 'source_url' => 'brief' ); }, range( 1, 4 ) ),
	'substitutions' => array(), 'accompaniments' => array(), 'common_failures' => array(), 'storage' => array(), 'food_safety' => array(),
	'references' => array(), 'visual_references' => array(), 'visual_observations' => array(), 'uncertainties' => array(), 'originality_notes' => array(),
);
$calls = 0;
$seen = array( 'observable_details' => 'Tarte ronde, pommes en rosace.', 'colours' => 'doré', 'textures' => 'croustillant' );
MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( &$calls, $package, $seen ) {
	if ( false !== strpos( json_encode( $payload ), 'input_image' ) ) {
		return array( 'status' => 200, 'raw' => json_encode( array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => json_encode( $seen ) ) ) ) ), 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 10 ) ) ) );
	}
	$calls++;
	if ( $calls > 1 ) { foreach ( $package['ingredients'] as &$one ) { $one['role'] = 'garniture'; } unset( $one ); }
	return array( 'status' => 200, 'raw' => json_encode( array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => json_encode( $package, JSON_UNESCAPED_UNICODE ) ) ) ) ), 'usage' => array( 'input_tokens' => 100, 'output_tokens' => 100 ) ) ) );
};
$brief = array( 'title' => 'Tarte aux pommes', 'text' => 'Tarte', 'images' => array( array( 'id' => 12, 'url' => 'http://127.0.0.1/tarte.jpg', 'title' => 'tarte' ) ) );
$reader = static function () { return array( 'mime' => 'image/jpeg', 'data' => base64_encode( 'bytes' ) ); };
$result = MSRWA_Engine::run_step( 'research', $brief, array( 'read_image' => $reader, 'config' => array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ), 'attempts' => array( 'research' => 1 ) ) ) );
MSRWA_Engine_Call::$transport = null;
msrwa_test_assert( 2 === $calls, 'A stray-only failure is asked once more, even on its last attempt: ' . $calls . ' call(s).' );
msrwa_test_assert( 'garniture' === ( $result->artifacts['research']['ingredients'][0]['role'] ?? '' ), 'The clean answer is the one kept.' );
$events = implode( "\n", array_column( $result->events, 'message' ) );
msrwa_test_contains( $events, 'another alphabet', 'And the timeline says why.' );

// The collage is six panels, whatever an older setting said.
$config = MSRWA_Engine_Config::create( array( 'images' => array( 'collage_panels' => 4 ) ) );
msrwa_test_assert( 6 === $config->facebook_template()['panels'], 'The collage template fixes six panels.' );
msrwa_test_assert( 6 === ( MSRWA_Prompt::variables( array( 'facebook_collage_steps' => 3 ) )['facebook_steps'] ?? 0 ), 'The prompt is compiled for six, whatever an old setting holds.' );

msrwa_test_done( 'letters from another alphabet are caught and asked again; the collage is six panels' );
