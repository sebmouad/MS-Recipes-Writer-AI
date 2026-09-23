<?php
// Google reads the recipe through the Recipe Card plugin's post meta, so a field
// the recipe produces but nothing maps never reaches the rich result. total_minutes
// was in that position; recipe_category and description were not produced at all.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'recipe' );

$complete = array(
	'title' => 'Daube de bœuf', 'description' => 'Un ragoût provençal fondant, mijoté au vin rouge, servi en plat du dimanche.',
	'servings' => 4, 'prep_minutes' => 30, 'cook_minutes' => 240, 'total_minutes' => 270,
	'cuisine' => 'française', 'recipe_category' => 'plat principal', 'difficulty' => 'moyenne',
	'calories_estimate' => 520, 'ingredients' => array( array( 'name' => 'bœuf', 'quantity' => '1', 'unit' => 'kg' ) ),
	'steps' => array( array( 'text' => 'Faire mariner la viande douze heures.' ) ),
	'equipment' => array( 'cocotte' ), 'notes' => array( 'Meilleure réchauffée.' ),
	'faq' => array( array( 'question' => 'Quel vin ?', 'answer' => 'Un rouge corsé.' ) ),
	'keywords' => array( 'daube' ), 'food_safety' => array( 'Refroidir en moins de deux heures.' ),
);
msrwa_test_assert( array() === MSRWA_Recipe::validate( $complete ), 'A complete recipe must validate.' );

foreach ( array( 'recipe_category', 'description', 'total_minutes' ) as $field ) {
	$missing = $complete;
	unset( $missing[ $field ] );
	$errors = MSRWA_Recipe::validate( $missing );
	msrwa_test_assert( isset( $errors[ $field ] ), 'A recipe without ' . $field . ' must not pass: Google reads it.' );
}

// total_minutes must be a real duration, not the zero a missing value decays to.
$zero = $complete;
$zero['total_minutes'] = 0;
msrwa_test_assert( isset( MSRWA_Recipe::validate( $zero )['total_minutes'] ), 'A total time of zero must be rejected.' );

// A recipe with no cooking is still valid: cook_minutes 0 is meaningful, total is not.
$raw = $complete;
$raw['cook_minutes'] = 0;
$raw['total_minutes'] = 30;
msrwa_test_assert( array() === MSRWA_Recipe::validate( $raw ), 'A no-cook recipe must still validate.' );

// Each new field must have somewhere to land, or it is produced and dropped.
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-settings.php' );
msrwa_test_assert( 1 === preg_match( "/'integration_mapping'\s*=>\s*array\((.*?)\),\n/s", $source, $match ), 'The mapping must be readable.' );
foreach ( array( 'total_minutes', 'recipe_category', 'description' ) as $field ) {
	msrwa_test_contains( $match[1], "'" . $field . "' =>", 'The mapping must carry ' . $field . '.' );
}

// And the prompt must actually ask for them.
$prompt = file_get_contents( dirname( __DIR__ ) . '/includes/engine/prompts/canonical_recipe.tpl.txt' );
foreach ( array( 'recipe_category', 'description', 'total_minutes' ) as $field ) {
	msrwa_test_contains( $prompt, $field, 'The recipe prompt must ask for ' . $field . '.' );
}

// gpt-5.6-luna returned keywords as one comma-separated string on 2026-09-20.
// The publisher reads either shape, so the gate must not reject the recipe over it.
$string_keywords = $complete;
$string_keywords['keywords'] = 'daube, bœuf, provençale';
msrwa_test_assert( array() === MSRWA_Recipe::validate( $string_keywords ), 'Keywords as a string must be accepted, since the publisher normalises them.' );
$blank_keywords = $complete;
$blank_keywords['keywords'] = '   ';
msrwa_test_assert( isset( MSRWA_Recipe::validate( $blank_keywords )['keywords'] ), 'Blank keywords must still be rejected.' );
msrwa_test_contains( $prompt, 'never one comma-separated string', 'The prompt must say which shape it wants.' );

// The recipe-card mapping hands third-party plugins whatever the model wrote,
// under keys naming meta fields those plugins print unescaped. Every other
// meta this file writes strips markup first; this mapping must too, or a
// dish description that talks a model into writing a script tag becomes
// stored XSS the moment a card plugin echoes it.
msrwa_test_load( 'draft', 'stack' );
msrwa_test_settings( array( 'integration_mapping' => array(
	'description' => '_recipe_description', 'notes' => '_recipe_notes',
	'instructions' => '_recipe_instructions', 'seo_title' => '_seo_title',
) ) );
$GLOBALS['msrwa_test_meta'] = array();
$map_recipe = new ReflectionMethod( MSRWA_Draft::class, 'map_recipe' );
$map_recipe->setAccessible( true );
$map_recipe->invoke(
	null,
	4104,
	array(
		'description' => 'Une tarte <script>alert(1)</script> aux pommes.',
		'notes' => array( 'Se garde <img src=x onerror=alert(1)> trois jours.' ),
		'steps' => array( array( 'text' => 'Cuire <b>doucement</b>.' ) ),
	),
	'<script>alert(2)</script> titre SEO',
	'description SEO'
);
foreach ( array( '_recipe_description', '_recipe_notes', '_recipe_instructions', '_seo_title' ) as $meta_key ) {
	$stored = (string) ( $GLOBALS['msrwa_test_meta'][4104][ $meta_key ] ?? '' );
	msrwa_test_missing( $stored, '<script', 'The recipe-card mapping strips markup from ' . $meta_key . '.' );
	msrwa_test_missing( $stored, 'onerror=', 'The recipe-card mapping strips markup from ' . $meta_key . '.' );
}
msrwa_test_contains( (string) $GLOBALS['msrwa_test_meta'][4104]['_recipe_description'], 'Une tarte', 'Stripping markup keeps the text around it.' );

// The MS stack keys are written by MSRWA_Stack, and strip markup the same way.
$stack = MSRWA_Stack::recipe_meta(
	array( 'notes' => array( 'Se garde <img src=x onerror=alert(1)> trois jours.' ), 'steps' => array( array( 'text' => 'Cuire <b>doucement</b>.' ) ) ),
	array( 'seo_title' => '<script>alert(2)</script> titre SEO' )
);
foreach ( array( '_recipe_notes', '_recipe_instructions', '_seo_title' ) as $meta_key ) {
	msrwa_test_assert( isset( $stack[ $meta_key ] ), 'The stack writes ' . $meta_key . '.' );
	msrwa_test_missing( (string) ( $stack[ $meta_key ] ?? '' ), '<', 'The stack strips markup from ' . $meta_key . '.' );
}

msrwa_test_done( 'recipe structured data' );
