<?php
// Every prompt is a template compiled from the settings. A prompt that
// hardcodes what a setting controls silently ignores the administrator (owner
// directive, 2026-09-20).
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images', 'prompt' );
require_once dirname( __DIR__ ) . '/tools/lib/steps.php';

$templates = glob( dirname( __DIR__ ) . '/includes/engine/prompts/*.txt' );
msrwa_test_assert( 12 === count( $templates ), 'Twelve prompts: four text stages, the research written from the editor’s photographs, two images, the collage’s brief and its two composing instructions (following a recipe, or drawn first and free), the collage’s reading, and the final approval.' );

foreach ( $templates as $path ) {
	$name = basename( $path );
	msrwa_test_assert( '.tpl.txt' === substr( $name, -8 ), $name . ' must be a template.' );
	$raw = file_get_contents( $path );

	// The output language is a setting, so no prompt may name one.
	foreach ( array( 'French', 'français', 'en français' ) as $hardcoded ) {
		msrwa_test_missing( $raw, $hardcoded, $name . ' must not hardcode the language; use {{language}}.' );
	}

	$compiled = MSRWA_Prompt::compile( $raw, lab_settings() );
	msrwa_test_missing( $compiled, '{{', $name . ' must compile with no placeholder left.' );
	msrwa_test_assert( strlen( $compiled ) > 200, $name . ' must compile to a real prompt.' );
}

// Changing the language changes every prompt that mentions it.
$spanish = array_merge( lab_settings(), array( 'site_language' => 'es' ) );
$touched = 0;
foreach ( $templates as $path ) {
	$raw = file_get_contents( $path );
	if ( false === strpos( $raw, '{{language}}' ) ) { continue; }
	$touched++;
	msrwa_test_contains( MSRWA_Prompt::compile( $raw, $spanish ), 'Spanish', basename( $path ) . ' must follow the configured language.' );
}
msrwa_test_assert( $touched >= 6, 'Most prompts must take their language from the settings; only ' . $touched . ' do.' );

// French typography is asked of French articles only: an English or Arabic
// article was proofread for missing é and œ.
$review = file_get_contents( dirname( __DIR__ ) . '/includes/engine/prompts/review.tpl.txt' );
msrwa_test_contains( MSRWA_Prompt::compile( $review, array( 'site_language' => 'fr' ) ), 'missing accents: é, è', 'A French review checks the French accents.' );
msrwa_test_missing( MSRWA_Prompt::compile( $review, array( 'site_language' => 'en' ) ), 'missing accents: é, è', 'An English review does not.' );
msrwa_test_contains( MSRWA_Prompt::compile( $review, array( 'site_language' => 'en' ) ), 'the accents and punctuation', 'It checks its own language’s instead.' );

// The course is named in the article's language, like every other value.
$canonical = file_get_contents( dirname( __DIR__ ) . '/includes/engine/prompts/canonical_recipe.tpl.txt' );
msrwa_test_contains( MSRWA_Prompt::compile( $canonical, array( 'site_language' => 'fr' ) ), 'plat principal', 'A French recipe files itself under a French course.' );
msrwa_test_missing( MSRWA_Prompt::compile( $canonical, array( 'site_language' => 'en' ) ), 'plat principal', 'An English one never does.' );

msrwa_test_done( 'prompt templates' );
