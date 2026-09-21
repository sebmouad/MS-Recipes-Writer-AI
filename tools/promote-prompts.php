<?php
/**
 * Compiles every prompt template against the shipped settings and writes the
 * result into the defaults that seed the prompts table.
 *
 * The lab measures templates; the plugin runs what is in the database. Without
 * this step the two drift, and the plugin quietly runs a prompt nobody proved.
 * tests/test-prompt-templates.php fails when they differ, so run this after
 * editing any template.
 *
 *   php tools/promote-prompts.php
 */
require __DIR__ . '/lib/steps.php';
lab_boot();
$dir = dirname( __DIR__ ) . '/';
$map = array(
	'research.tpl.txt'         => 'prompt_research',
	'canonical_recipe.tpl.txt' => 'prompt_recipe',
	'article.tpl.txt'          => 'prompt_article',
	'review.tpl.txt'           => 'prompt_review',
	'proofread.tpl.txt'        => 'prompt_correction',
	'featured_image.tpl.txt'   => 'prompt_image',
	'facebook_image.tpl.txt'   => 'prompt_facebook_image',
	'final_approval.tpl.txt'   => 'prompt_final_approval',
);
$file = $dir . 'includes/class-msrwa-settings.php';
$source = file_get_contents( $file );
foreach ( $map as $template => $key ) {
	$compiled = MSRWA_Prompt::compile( trim( file_get_contents( MSRWA_Engine_Input::prompt_path( $template ) ) ), lab_settings() );
	if ( false !== strpos( $compiled, '{{' ) ) { fwrite( STDERR, "Unresolved placeholder in {$template}\n" ); exit( 1 ); }
	$pattern = "/('" . $key . "'\s*=>\s*)'.*?',\n/s";
	if ( ! preg_match( $pattern, $source ) ) { fwrite( STDERR, "No setting {$key}\n" ); continue; }
	$source = preg_replace( $pattern, '$1' . str_replace( '$', '\\$', var_export( $compiled, true ) ) . ",\n", $source, 1 );
	printf( "%-26s -> %-24s %d chars\n", $template, $key, strlen( $compiled ) );
}
file_put_contents( $file, $source );
