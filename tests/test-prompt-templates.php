<?php
// Every prompt is a template compiled from the settings, and the settings ship
// the compiled result. A prompt that hardcodes what a setting controls silently
// ignores the administrator (owner directive, 2026-09-20).
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images', 'prompt' );
require_once dirname( __DIR__ ) . '/tools/lib/steps.php';

$templates = glob( dirname( __DIR__ ) . '/tools/prompts/*.txt' );
msrwa_test_assert( 9 === count( $templates ), 'Nine prompts: six text stages, two images and the final approval.' );

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

// What ships must be what the lab measured, compiled.
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-settings.php' );
$shipped = array(
	'research.tpl.txt' => 'prompt_research', 'canonical_recipe.tpl.txt' => 'prompt_recipe',
	'article.tpl.txt' => 'prompt_article', 'review.tpl.txt' => 'prompt_review',
	'proofread.tpl.txt' => 'prompt_correction', 'featured_image.tpl.txt' => 'prompt_image',
	'facebook_image.tpl.txt' => 'prompt_facebook_image', 'final_approval.tpl.txt' => 'prompt_final_approval',
);
foreach ( $shipped as $template => $key ) {
	msrwa_test_assert( 1 === preg_match( "/'" . $key . "'\s*=>\s*'(.*?)',\n/s", $source, $match ), $key . ' must ship a default.' );
	$default = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $match[1] );
	$compiled = MSRWA_Prompt::compile( file_get_contents( dirname( __DIR__ ) . '/tools/prompts/' . $template ), lab_settings() );
	msrwa_test_assert( $default === $compiled, $key . ' must ship exactly what ' . $template . ' compiles to; run tools/promote.' );
}

msrwa_test_done( 'prompt templates' );
