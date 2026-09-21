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
$prompt = file_get_contents( dirname( __DIR__ ) . '/tools/prompts/canonical_recipe.tpl.txt' );
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

msrwa_test_done( 'recipe structured data' );
