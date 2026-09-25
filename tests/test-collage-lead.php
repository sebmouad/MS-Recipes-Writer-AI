<?php
// The Facebook collage leads the recipe (ENGINE.md §7, 50): drawn first on a
// complete lot, or the writer's own collage in its place. The recipe, the
// article and the featured image follow what it shows; the research still
// sets how much.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
msrwa_test_load( 'profile' );

// Without a lead, nothing moves.
msrwa_test_assert( array() === MSRWA_Engine_Steps::for_lead( array(), '' ), 'A recipe without a lead keeps the registry as it is.' );

// Drawn first: research, then the collage, its reading, then the recipe.
$drawn = MSRWA_Engine_Steps::for_lead( array(), 'drawn' );
$order = MSRWA_Engine_Steps::names( $drawn );
msrwa_test_assert( array_search( 'collage_reading', $order, true ) === array_search( 'canonical_recipe', $order, true ) - 1, 'The reading is listed right before the recipe: ' . implode( ', ', $order ) );
msrwa_test_assert( array( 'research' ) === MSRWA_Engine_Steps::get( 'facebook_image', $drawn )['needs'], 'The collage waits only for the research.' );
foreach ( array( 'canonical_recipe', 'article', 'featured_image' ) as $step ) {
	msrwa_test_assert( in_array( 'collage', MSRWA_Engine_Steps::get( $step, $drawn )['needs'], true ), $step . ' waits for the collage’s reading.' );
}
$done = array( 'research' => array( 'x' => 1 ) );
msrwa_test_assert( array( 'facebook_image' ) === MSRWA_Engine_Steps::ready( $done, array( 'canonical_recipe', 'facebook_image', 'collage_reading' ), $drawn ), 'After the research, only the collage can run.' );
$done['facebook'] = array( 'path' => '/x.webp' );
msrwa_test_assert( array( 'collage_reading' ) === MSRWA_Engine_Steps::ready( $done, array( 'canonical_recipe', 'collage_reading' ), $drawn ), 'Then its reading.' );
$done['collage'] = array( 'finished_dish' => 'crêpes' );
msrwa_test_assert( array( 'canonical_recipe' ) === MSRWA_Engine_Steps::ready( $done, array( 'canonical_recipe', 'article' ), $drawn ), 'Then the recipe.' );

// The writer's own collage is never drawn again.
msrwa_test_assert( array( 'facebook_image' ) === MSRWA_Engine_Steps::skipped( 'provided' ) && array() === MSRWA_Engine_Steps::skipped( 'drawn' ), 'Only a provided collage skips the drawing.' );
$steps = MSRWA_Profile::run_steps( MSRWA_Profile::FULL, array(), 'provided' );
msrwa_test_assert( ! in_array( 'facebook_image', $steps, true ) && in_array( 'collage_reading', $steps, true ), 'A recipe with the writer’s collage reads it and draws only the featured image: ' . implode( ', ', $steps ) );
msrwa_test_assert( in_array( 'facebook_image', MSRWA_Profile::run_steps( MSRWA_Profile::FULL, array(), 'drawn' ), true ), 'A complete lot without one draws it.' );

// Which lead a recipe gets.
msrwa_test_assert( 'provided' === MSRWA_Profile::lead( MSRWA_Profile::FULL, true ), 'A ticked collage leads a complete lot.' );
msrwa_test_assert( 'drawn' === MSRWA_Profile::lead( MSRWA_Profile::FULL, false ), 'A complete lot without one draws it first.' );
msrwa_test_assert( '' === MSRWA_Profile::lead( MSRWA_Profile::FEATURED, false ) && '' === MSRWA_Profile::lead( MSRWA_Profile::ARTICLE, true ), 'Other lots keep today’s order; an article alone has no image to lead it.' );

// What the steps are told.
$reading = array( 'ingredients_seen' => array( 'truffe noire', 'persil plat' ), 'garnish_and_sides' => array( 'persil plat' ), 'finished_dish' => 'Des crêpes pliées en carré.', 'serving' => 'Sur une assiette blanche.', 'panels' => array() );
$brief = array( 'collage_lead' => 'drawn', 'collage' => $reading );
$recipe = MSRWA_Engine_Input::collage_lead( $brief, 'canonical_recipe' );
msrwa_test_contains( $recipe, 'truffe noire', 'The recipe is given every ingredient the collage shows.' );
msrwa_test_contains( $recipe, 'quantities, times, temperatures', 'And told the research, not the picture, sets how much.' );
msrwa_test_contains( MSRWA_Engine_Input::collage_lead( $brief, 'article' ), 'Des crêpes pliées en carré.', 'The article describes the collage’s finished dish.' );
msrwa_test_contains( MSRWA_Engine_Input::collage_lead( $brief, 'final_approval' ), 'never against the ingredient list', 'The judge does not hold a drawn collage to the list it was written before.' );
msrwa_test_contains( MSRWA_Engine_Input::collage_lead( array( 'collage_lead' => 'provided', 'collage' => $reading ), 'final_approval' ), 'Do not judge it', 'The writer’s own collage is not judged.' );
msrwa_test_assert( '' === MSRWA_Engine_Input::collage_lead( array( 'collage' => $reading ), 'canonical_recipe' ), 'Without a lead nothing is said.' );

// The free collage has no recipe to obey, and keeps the research as knowledge.
$free = MSRWA_Engine_Input::collage_brief_free( array( 'title' => 'Crêpes jambon fromage', 'text' => '', 'research' => array( 'ingredients' => array( array( 'name' => 'farine' ), array( 'name' => 'jambon' ) ), 'preparation' => array( array( 'action' => 'Préparer la pâte.' ) ) ) ), 'facebook_brief.tpl.txt' );
msrwa_test_contains( $free, 'Crêpes jambon fromage', 'It names the dish.' );
msrwa_test_contains( $free, 'add freely to it', 'The research is knowledge of the dish, not a limit.' );
msrwa_test_missing( $free, 'add nothing it does not use', 'No recipe restricts it.' );
$instruction = (string) file_get_contents( MSRWA_Engine_Input::prompt_path( 'facebook_compose_free.tpl.txt' ) );
msrwa_test_missing( $instruction, 'Add no ingredient, garnish or step the recipe does not have', 'The free instruction drops the restriction.' );
msrwa_test_missing( $instruction, 'no cloth', 'And lets a home kitchen look lived in.' );

// The judge's garnish rule is dropped where the collage leads.
msrwa_test_missing( MSRWA_Engine_Input::visual_brief( array( 'ingredients' => array() ), array(), false, false ), 'NO GARNISH', 'Led by the collage, garnish is not a defect.' );
msrwa_test_contains( MSRWA_Engine_Input::visual_brief( array( 'ingredients' => array() ), array() ), 'NO GARNISH', 'Otherwise the rule stands.' );

msrwa_test_done( 'the collage leads the recipe: drawn first, or the writer’s own' );
