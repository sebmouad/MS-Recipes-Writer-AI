<?php
// The draft, in the shapes the MS stack parses. Each assertion is a field the
// theme's card, MS SEO Plus, MS FB Posts or MS Image Optimizer reads, and a
// value in the wrong shape shows up on the page: the card once printed the
// JSON of the ingredient list as the ingredient list.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
msrwa_test_load( 'stack' );

$canonical = array(
	'title' => 'Daube de bœuf provençale', 'servings' => 4, 'prep_minutes' => 30, 'cook_minutes' => 180, 'total_minutes' => 210,
	'cuisine' => 'provençale française', 'recipe_category' => 'plat principal', 'difficulty' => 'intermédiaire', 'calories_estimate' => 650,
	'ingredients' => array(
		array( 'name' => 'paleron de bœuf', 'quantity' => 900, 'unit' => 'g' ),
		array( 'name' => 'cuillère à café de sel', 'quantity' => 0.5, 'unit' => '' ),
		array( 'name' => 'œufs', 'quantity' => 2, 'unit' => '' ),
		array( 'name' => 'sucre glace', 'quantity' => 'au goût', 'unit' => '' ),
	),
	'steps' => array( array( 'text' => 'Couper la viande.' ), array( 'text' => 'Mijoter <b>3 heures</b>.' ) ),
	'equipment' => array( 'cocotte', 'couteau' ), 'notes' => array( 'Meilleure le lendemain.' ),
	'faq' => array( array( 'question' => 'Se congèle-t-elle ?', 'answer' => 'Oui, trois mois.' ) ),
	'keywords' => array( 'daube', 'bœuf mijoté' ),
);
$article = array( 'seo_title' => 'Daube de bœuf provençale au vin rouge, fondante et parfumée, recette facile', 'seo_description' => 'Courte.', 'title' => 'Daube' );
$meta = MSRWA_Stack::recipe_meta( $canonical, $article, 'fr' );

msrwa_test_assert( '30' === $meta['_recipe_prep_time'] && '180' === $meta['_recipe_cook_time'] && '4' === $meta['_recipe_servings'] && '650' === $meta['_recipe_calories'], 'Times, servings and calories are whole numbers, as the card reads them.' );
msrwa_test_assert( "900 g paleron de bœuf\n0,5 cuillère à café de sel\n2 œufs\nsucre glace (au goût)" === $meta['_recipe_ingredients'], 'Ingredients are one per line, quantity first, in the article\'s decimal style; got ' . $meta['_recipe_ingredients'] );
msrwa_test_assert( "Couper la viande.\nMijoter 3 heures." === $meta['_recipe_instructions'], 'Steps are one per line, without markup.' );
msrwa_test_assert( "cocotte\ncouteau" === $meta['_recipe_equipment'], 'Equipment is one per line.' );
msrwa_test_assert( 'daube, bœuf mijoté' === $meta['_recipe_keywords'], 'Keywords are a comma list, not JSON.' );
msrwa_test_assert( 'medium' === $meta['_recipe_difficulty'], 'Difficulty uses the card\'s own vocabulary.' );
msrwa_test_assert( 'Provençale française' === $meta['_recipe_cuisine'], 'The cuisine reads as a label.' );
msrwa_test_assert( 'Se congèle-t-elle ?' === json_decode( $meta['_recipe_faq'], true )[0]['question'], 'The FAQ is stored as MS SEO Plus reads it.' );
msrwa_test_assert( mb_strlen( $meta['_seo_title'] ) <= 60 && ' ' !== mb_substr( $meta['_seo_title'], -1 ) && false === strpos( $meta['_seo_title'], 'recette fac' ), 'The SEO title is cut at a word, within 60 characters; got ' . $meta['_seo_title'] );
msrwa_test_assert( 'Courte.' === $meta['_seo_description'], 'A description that fits is kept whole.' );

// Nothing is invented: a field the recipe does not have stays out.
$bare = MSRWA_Stack::recipe_meta( array( 'title' => 'Tarte', 'difficulty' => 'pas facile à dire' ), array() );
foreach ( array( '_recipe_prep_time', '_recipe_calories', '_recipe_cuisine', '_recipe_difficulty', '_recipe_ingredients' ) as $key ) {
	msrwa_test_assert( ! isset( $bare[ $key ] ), 'No value is made up for ' . $key . '.' );
}
msrwa_test_assert( 'Tarte' === $bare['_seo_title'], 'The SEO title falls back to the recipe title.' );

// MS FB Posts refuses anything but a JSON string of {id, text}.
msrwa_test_assert( '[{"id":55,"text":"Une daube fondante."}]' === MSRWA_Stack::facebook_data( 55, 'Une daube fondante.' ), 'The collage and its caption reach MS FB Posts in its format.' );
msrwa_test_assert( '' === MSRWA_Stack::facebook_data( 0, 'x' ), 'No collage, no Facebook entry.' );

// A suggestion is filed under the category the site already has.
msrwa_test_assert( MSRWA_Stack::term_key( 'plat principal' ) === MSRWA_Stack::term_key( 'Plats principaux' ), 'Singular and plural name one category.' );
msrwa_test_assert( MSRWA_Stack::term_key( 'Dessert' ) === MSRWA_Stack::term_key( 'desserts' ), 'Case and a plural s do not split a category.' );
msrwa_test_assert( MSRWA_Stack::term_key( 'Entrées' ) === MSRWA_Stack::term_key( 'entree' ), 'Accents do not split a category.' );
msrwa_test_assert( MSRWA_Stack::term_key( 'Soupes' ) !== MSRWA_Stack::term_key( 'Salades' ), 'Different categories stay different.' );

msrwa_test_assert( 'hard' === MSRWA_Stack::difficulty( 'Difficile' ) && 'easy' === MSRWA_Stack::difficulty( 'facile' ), 'Each level is recognised.' );

// Nobody in the stack prints the head on a bare site; the theme does once it
// is active with no SEO plugin, and the plugin's own tags step aside.
msrwa_test_assert( ! MSRWA_Stack::owns_head(), 'Without the MS stack, the plugin prints its own head tags.' );
// Declared here, not at the top level, where PHP would hoist them.
if ( true ) {
	function ms_recipes_seo_plugin_active() { return false; }
	function ms_recipes_seo_singular_schema() {}
}
msrwa_test_assert( MSRWA_Stack::owns_head(), 'With the MS Recipes theme owning SEO, the plugin only feeds it.' );

msrwa_test_done( 'the MS stack reads what the draft wrote' );
