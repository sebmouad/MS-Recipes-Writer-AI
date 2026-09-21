<?php
// What research saw in real photographs drives the recipe, the article and both
// images. A step that stops receiving it does not fail — the model simply
// invents an appearance — so the chain is asserted rather than trusted.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images', 'prompt', 'json' );

// MSRWA_Settings is a double offline, so the shipped default is read from the
// file that seeds the prompts table.
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-settings.php' );
msrwa_test_assert( 1 === preg_match( "/'prompt_research'\s*=>\s*'(.*?)',\n/s", $source, $match ), 'The shipped research prompt must be readable.' );
$research = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $match[1] );

// 1. Research must ask for provenance, and for observations of the real bytes.
foreach ( array( 'visual_references', 'visual_observations', 'image_url', 'source_url' ) as $key ) {
	msrwa_test_contains( $research, $key, 'The research prompt must carry ' . $key . '.' );
}
msrwa_test_contains( $research, 'not AI-generated', 'Only real source photographs may be cited.' );
msrwa_test_contains( $research, 'never guess visual details', 'Observations must come from the bytes, not from search snippets.' );
msrwa_test_missing( $research, '{{', 'The shipped prompt must be compiled, with no placeholder left.' );

// 2. The prompt must never invite a photograph to be republished. The engine
//    downloads a cited image to look at it; it does not hand it to the reader.
msrwa_test_contains( $research, 'Do not copy a source', 'Research must not reuse a source\'s work.' );

// 3. The lab must hand the package to every step that describes the dish.
$steps = file_get_contents( dirname( __DIR__ ) . '/tools/lib/steps.php' );
foreach ( array( 'canonical_recipe', 'article', 'review' ) as $step ) {
	msrwa_test_assert(
		1 === preg_match( "/'" . $step . "' === \\\$step \\)\s*\{(.*?)\n\t\}/s", $steps, $block ) && false !== strpos( $block[1], '$research' ),
		'The ' . $step . ' step must receive the research package.'
	);
}

// 4. Every brief carries a package, so the lab measures the chain rather than an
//    empty object.
foreach ( glob( dirname( __DIR__ ) . '/tools/fixtures/*.json' ) as $file ) {
	$brief = MSRWA_Json::decode( file_get_contents( $file ) );
	$name = basename( $file, '.json' );
	msrwa_test_assert( is_array( $brief['research'] ?? null ), $name . ' must carry a research package.' );
}

// The owner's directive, 2026-09-21: what research read and what it saw in real
// photographs must reach every later step, not only the images. Raw JSON is not
// enough — the distilled brief is what an image model and a judge can obey.
require_once dirname( __DIR__ ) . '/tools/lib/steps.php';
$brief = lab_brief( 'tarte-pommes' );
$recipe_input = lab_build_input( 'canonical_recipe', 'PROMPT', $brief, array() );
msrwa_test_contains( $recipe_input, 'OBSERVED APPEARANCE', 'The recipe must receive what the photographs showed.' );
msrwa_test_contains( $recipe_input, 'never an ingredient, a quantity or a step', 'An observation may establish appearance only.' );

$article_input = lab_build_input( 'article', 'PROMPT', $brief, array() );
msrwa_test_contains( $article_input, 'VISUAL BRIEF', 'The article must receive the derived visual brief.' );

foreach ( array( 'featured', 'facebook' ) as $kind ) {
	$image_prompt = lab_image_prompt( $kind, $brief, array() );
	msrwa_test_contains( $image_prompt, 'VISUAL BRIEF', 'The ' . $kind . ' prompt must receive the derived visual brief.' );
	msrwa_test_contains( $image_prompt, 'ONE SERVING PRESENTATION', 'Both images must share one serving decision.' );
	msrwa_test_contains( $image_prompt, 'Moule à tarte de 28 cm', 'The canonical equipment must bound what may appear.' );
	msrwa_test_contains( $image_prompt, 'exactly 6 pièces', 'A countable ingredient must reach the prompt as a number.' );
	msrwa_test_contains( $image_prompt, 'exactly 1 rouleau', 'One pastry roll must be stated as one.' );
}

// A measured ingredient carries no count to respect, so it must not be stated as one.
$featured = lab_image_prompt( 'featured', $brief, array() );
msrwa_test_contains( $featured, '80 g Sucre', 'A measured ingredient is listed with its amount.' );
msrwa_test_missing( $featured, 'Sucre — exactly', 'Grams are not a count and must not be given as one.' );

msrwa_test_done( 'visual reference' );
