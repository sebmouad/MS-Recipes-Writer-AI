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

msrwa_test_done( 'visual reference' );
