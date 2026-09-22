<?php
// The recipe Google reads. A rich result is only shown when the times are
// ISO 8601 durations, the ingredients are lines of text and the steps are
// HowToStep objects; anything else is silently ignored by the crawler.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'schema' );

msrwa_test_assert( 'PT20M' === MSRWA_Schema::duration( 20 ), 'Minutes under an hour stay minutes.' );
msrwa_test_assert( 'PT1H5M' === MSRWA_Schema::duration( 65 ), 'An hour and five minutes is PT1H5M, not PT65M.' );
msrwa_test_assert( 'PT4H' === MSRWA_Schema::duration( 240 ), 'Whole hours carry no minute part.' );
msrwa_test_assert( 'PT0M' === MSRWA_Schema::duration( -3 ), 'A negative duration is clamped, never printed.' );

$recipe = array(
	'title' => 'Tarte aux pommes normande',
	'description' => 'Une tarte fondante aux pommes et à la crème.',
	'servings' => 6, 'prep_minutes' => 20, 'cook_minutes' => 45, 'total_minutes' => 65,
	'cuisine' => 'Française', 'recipe_category' => 'dessert', 'calories_estimate' => 380,
	'keywords' => array( 'tarte', 'pommes' ),
	'ingredients' => array(
		array( 'name' => 'Pâte brisée', 'quantity' => 250, 'unit' => 'g' ),
		array( 'name' => 'Pommes', 'quantity' => 4, 'unit' => '' ),
		'Une pincée de sel',
	),
	'steps' => array( array( 'text' => 'Foncer le moule.' ), array( 'text' => '<b>Cuire</b> 45 minutes.' ), array( 'text' => '' ) ),
);
$data = MSRWA_Schema::build( $recipe, array( 'name' => 'Tarte normande', 'url' => 'https://example.com/tarte', 'image' => 'https://example.com/t.webp', 'author' => 'Awa', 'language' => 'fr' ) );

msrwa_test_assert( 'Recipe' === $data['@type'] && 'https://schema.org' === $data['@context'], 'It is a schema.org Recipe.' );
msrwa_test_assert( 'Tarte normande' === $data['name'], 'The post title names the recipe, since an editor may have changed it.' );
msrwa_test_assert( 'PT1H5M' === $data['totalTime'] && 'PT20M' === $data['prepTime'] && 'PT45M' === $data['cookTime'], 'Times are durations.' );
msrwa_test_assert( '6' === $data['recipeYield'], 'The yield is the number of servings.' );
msrwa_test_assert( array( '250 g Pâte brisée', '4 Pommes', 'Une pincée de sel' ) === $data['recipeIngredient'], 'Ingredients are plain lines, quantity first.' );
msrwa_test_assert( 2 === count( $data['recipeInstructions'] ) && 'Cuire 45 minutes.' === $data['recipeInstructions'][1]['text'], 'Steps are HowToStep text, without markup, and empty ones are dropped.' );
msrwa_test_assert( 'HowToStep' === $data['recipeInstructions'][0]['@type'], 'Each step is a HowToStep.' );
msrwa_test_assert( '380 kcal' === $data['nutrition']['calories'], 'Calories carry their unit.' );
msrwa_test_assert( 'tarte, pommes' === $data['keywords'], 'Keywords are one comma-separated string.' );
msrwa_test_assert( 'Awa' === $data['author']['name'] && 'fr' === $data['inLanguage'], 'The author and the language travel with it.' );

// A no-cook dish: zero cooking is an answer, and must still be printed.
$raw = $recipe;
$raw['cook_minutes'] = 0;
msrwa_test_assert( 'PT0M' === MSRWA_Schema::build( $raw, array() )['cookTime'], 'Zero cooking is printed as PT0M.' );
msrwa_test_assert( 'Tarte aux pommes normande' === MSRWA_Schema::build( $raw, array() )['name'], 'Without a post, the recipe names itself.' );

// It must survive being printed inside a <script>: a "</script>" in a recipe
// field would otherwise end the tag and inject whatever follows.
$hostile = $recipe;
$hostile['description'] = '</script><script>alert(1)</script>';
$printed = wp_json_encode( MSRWA_Schema::build( $hostile, array() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );
msrwa_test_missing( $printed, '</script>', 'A recipe field cannot close the script tag it is printed in.' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-schema.php' );
msrwa_test_contains( $source, 'JSON_HEX_TAG', 'The printed JSON-LD escapes angle brackets.' );
msrwa_test_contains( $source, "'publish' !== \$post->post_status", 'Nothing is printed for a post that is not published.' );

msrwa_test_done( 'recipe JSON-LD' );
