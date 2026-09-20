<?php
// What research observed about the finished dish drives the recipe, the article
// and both images. A step that stops receiving it degrades silently — the model
// simply invents an appearance — so the chain is asserted rather than trusted.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images', 'prompt', 'json' );

$facets = array( 'colour', 'surface', 'texture', 'plating', 'garnish', 'doneness_cues' );

// 1. The shipped research prompt asks for every facet by name. MSRWA_Settings is
//     a double offline, so the default is read from the file that seeds the table.
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-settings.php' );
msrwa_test_assert( 1 === preg_match( "/'prompt_research'\s*=>\s*'(.*?)',\n/s", $source, $match ), 'The shipped research prompt must be readable.' );
$research = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $match[1] );
foreach ( $facets as $facet ) {
	msrwa_test_contains( $research, '"' . $facet . '"', 'The research prompt must ask for the ' . $facet . ' facet.' );
}
msrwa_test_contains( $research, 'visual_reference', 'The research prompt must name the object the later steps read.' );
msrwa_test_missing( $research, '{{', 'The shipped prompt must be compiled, with no placeholder left.' );
msrwa_test_contains( $research, 'Never propose an image URL', 'Research must describe photographs, never hand one on to be reused.' );

// 2. Every downstream template tells the model the reference outranks its own idea.
$templates = array(
	'article.tpl.txt'          => 'visual reference',
	'canonical_recipe.tpl.txt' => 'VISUAL REFERENCE',
	'featured_image.tpl.txt'   => 'VISUAL REFERENCE',
	'facebook_image.tpl.txt'   => 'VISUAL REFERENCE',
);
foreach ( $templates as $file => $needle ) {
	$path = dirname( __DIR__ ) . '/tools/prompts/' . $file;
	msrwa_test_assert( file_exists( $path ), $file . ' must exist.' );
	msrwa_test_contains( strtolower( file_get_contents( $path ) ), strtolower( $needle ), $file . ' must consume the visual reference.' );
}

// 3. Both briefs carry a reference with every facet filled, so the lab measures
//    the chain rather than an empty object.
foreach ( array( 'tarte-pommes', 'poulet-yassa' ) as $brief ) {
	$data = MSRWA_Json::decode( file_get_contents( dirname( __DIR__ ) . '/tools/fixtures/' . $brief . '.json' ) );
	$reference = $data['research']['visual_reference'] ?? array();
	msrwa_test_assert( is_array( $reference ), $brief . ' must carry a visual reference.' );
	foreach ( $facets as $facet ) {
		msrwa_test_assert( '' !== trim( (string) ( $reference[ $facet ] ?? '' ) ), $brief . ' must record its ' . $facet . '.' );
	}
}

// 4. The facets stay configurable in the same place as the rest of the prompt.
msrwa_test_settings( array( 'research_facts_max' => 8, 'research_references_max' => 4 ) );
$compiled = MSRWA_Prompt::compile( 'At most {{research_facts_max}} facts and {{research_references_max}} references.', MSRWA_Settings::get() );
msrwa_test_contains( $compiled, 'At most 8 facts and 4 references.', 'The research limits must come from the settings.' );

msrwa_test_done( 'visual reference' );
