<?php
// What makes an article findable and worth advertising beside: the phrase it
// is written to be found by, placed where search engines weigh it; the FAQ as
// the page shows it, for Bing and the AI assistants; nutrition only as a
// source stated it; and research that gathers the appliance and diet versions
// advertisers bid on, from sources only.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'schema', 'stack', 'images', 'prompt' );
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-draft.php';

// --- The phrase, and where it goes -----------------------------------------
$article = array(
	'focus_keyword' => 'tarte aux pommes normande',
	'secondary_keywords' => array( 'tarte normande au calvados', 'combien de temps cuire une tarte aux pommes' ),
	'seo_title' => 'Tarte aux pommes normande : la recette crémeuse',
	'slug' => 'tarte-aux-pommes-normande',
	'seo_description' => 'La tarte aux pommes normande, crème et calvados : 1 h 05 pour 6 parts, au four à 180 °C.',
	'content_html' => '<h2>Comment réussir une tarte aux pommes normande</h2><p>La tarte aux pommes normande cuit 45 minutes à 180 °C.</p>',
);
msrwa_test_assert( array() === MSRWA_Engine_Score::search_placement( $article ), 'A phrase in the title, slug, description, opening and a heading is placed; missing: ' . implode( ', ', MSRWA_Engine_Score::search_placement( $article ) ) );
$moved = array_merge( $article, array( 'content_html' => '<h2>Les étapes</h2><p>Une tarte crémeuse.</p>', 'slug' => 'tarte-normande' ) );
msrwa_test_assert( array( 'slug', 'opening paragraph', 'an h2' ) === MSRWA_Engine_Score::search_placement( $moved ), 'Each place it is missing from is named.' );
msrwa_test_assert( array( 'every place' ) === MSRWA_Engine_Score::search_placement( array() ), 'An article that chose no phrase is told so.' );

msrwa_test_assert( array( 'tarte aux pommes normande', 'tarte normande au calvados', 'combien de temps cuire une tarte aux pommes' ) === MSRWA_Stack::search_phrases( $article ), 'The focus keyword leads the secondary ones.' );
$meta = MSRWA_Stack::recipe_meta( array( 'keywords' => array( 'dessert', 'Tarte aux pommes normande' ) ), $article );
msrwa_test_assert( 0 === strpos( $meta['_recipe_keywords'], 'tarte aux pommes normande, tarte normande au calvados' ), 'The theme\'s keywords open on the article\'s phrases; got ' . $meta['_recipe_keywords'] );
msrwa_test_assert( 1 === substr_count( $meta['_recipe_keywords'], 'tarte aux pommes normande' ), 'Without repeating one.' );

// --- The FAQ the page shows -------------------------------------------------
$faq = array( array( 'question' => 'Peut-on congeler la tarte ?', 'answer' => 'Oui, trois mois.' ) );
$meta = MSRWA_Stack::recipe_meta( array( 'faq' => array( array( 'question' => 'Autre ?', 'answer' => 'Non.' ) ) ), array( 'faq' => $faq ) );
msrwa_test_contains( $meta['_recipe_faq'], 'Peut-on congeler', 'The FAQ stored is the one the article shows.' );
$page = '<h2>FAQ</h2><h3>Peut-on congeler la tarte ?</h3><p>Oui, trois mois.</p>';
$schema = MSRWA_Schema::faq( array_merge( $faq, array( array( 'question' => 'Jamais montrée ?', 'answer' => 'Non.' ) ) ), $page );
msrwa_test_assert( 'FAQPage' === ( $schema['@type'] ?? '' ) && 1 === count( $schema['mainEntity'] ), 'FAQPage carries only the questions the page really shows.' );
msrwa_test_assert( 'Oui, trois mois.' === $schema['mainEntity'][0]['acceptedAnswer']['text'], 'Each with its answer.' );
msrwa_test_assert( null === MSRWA_Schema::faq( $faq, '<p>Rien.</p>' ), 'A page that shows none gets no FAQ markup.' );
$split = '<p>Page un.</p><!--nextpage--><h3>Peut-on congeler la tarte ?</h3>';
msrwa_test_assert( null === MSRWA_Schema::faq( $faq, MSRWA_Schema::page_of( $split, 0 ) ), 'Page one of a split article does not claim the FAQ page two shows.' );
msrwa_test_assert( null !== MSRWA_Schema::faq( $faq, MSRWA_Schema::page_of( $split, 2 ) ), 'Page two does.' );

// --- Nutrition, only as a source stated it ----------------------------------
msrwa_test_assert( array() === MSRWA_Draft::nutrition( array( 'calories' => 320 ) ), 'Figures with no source are not kept.' );
$stated = MSRWA_Draft::nutrition( array( 'calories' => 320, 'protein_g' => 5.2, 'fat_g' => 'n/a', 'source_url' => 'https://example.org' ) );
msrwa_test_assert( array( 'calories' => 320.0, 'protein_g' => 5.2 ) === $stated, 'A sourced figure is kept, anything that is not a figure dropped.' );
msrwa_test_assert( array( 'calories' => '320 kcal', 'proteinContent' => '5.2 g' ) === MSRWA_Schema::nutrition( $stated ), 'As schema.org spells it.' );
$built = MSRWA_Schema::build( array( 'ingredients' => array( 'x' ), 'calories_estimate' => 300 ), array( 'name' => 'Tarte', 'nutrition' => $stated, 'modified' => '2026-09-26T10:00:00+00:00' ) );
msrwa_test_assert( '320 kcal' === $built['nutrition']['calories'] && '5.2 g' === $built['nutrition']['proteinContent'], 'A stated figure wins over the recipe\'s estimate.' );
msrwa_test_assert( '2026-09-26T10:00:00+00:00' === ( $built['dateModified'] ?? '' ), 'The Recipe says when it last changed.' );

// --- What the prompts ask ----------------------------------------------------
$prompts = dirname( __DIR__ ) . '/includes/engine/prompts/';
$compiled = MSRWA_Prompt::compile( file_get_contents( $prompts . 'article.tpl.txt' ), array( 'site_language' => 'fr' ) );
foreach ( array( '"focus_keyword"', '"secondary_keywords"', 'answers the search on its own', 'Advertisers bid on these exact names', 'never a brand', 'What the research does not document is not offered' ) as $rule ) {
	msrwa_test_contains( $compiled, $rule, 'The article is asked: ' . $rule );
}
msrwa_test_contains( $compiled, '35 to 60 characters', 'The SEO title fits what Google shows.' );
msrwa_test_contains( $compiled, '120 to 155 characters', 'So does the description.' );
foreach ( array( 'research.tpl.txt', 'research_photographs.tpl.txt' ) as $file ) {
	$research = file_get_contents( $prompts . $file );
	foreach ( array( '"appliance_versions"', '"diet_adaptations"', '"nutrition"' ) as $key ) { msrwa_test_contains( $research, $key, $file . ' gathers ' . $key . '.' ); }
}


// --- A locked post keeps its recipe to itself --------------------------------
if ( ! function_exists( 'is_singular' ) ) { function is_singular( $type = '' ) { return true; } }
if ( ! function_exists( 'get_queried_object_id' ) ) { function get_queried_object_id() { return 298; } }
if ( ! function_exists( 'post_password_required' ) ) { function post_password_required( $post = null ) { return ! empty( $GLOBALS['msrwa_test_locked'] ); } }
if ( ! function_exists( 'get_query_var' ) ) { function get_query_var( $name ) { return 0; } }
msrwa_test_load( 'head' );
msrwa_test_settings( array( 'recipe_schema' => 1, 'seo_meta' => 1 ) );
$GLOBALS['msrwa_test_posts'][298] = (object) array( 'ID' => 298, 'post_status' => 'publish', 'post_content' => $page, 'post_author' => 1 );
$GLOBALS['msrwa_test_meta'][298] = array( '_msrwa_run_id' => 8, '_recipe_faq' => wp_json_encode( $faq ) );
$GLOBALS['msrwa_test_locked'] = false;
ob_start();
MSRWA_Schema::print_head();
msrwa_test_contains( ob_get_clean(), 'FAQPage', 'An open post prints its FAQ markup.' );
$GLOBALS['msrwa_test_locked'] = true;
ob_start();
MSRWA_Schema::print_head();
msrwa_test_assert( '' === ob_get_clean(), 'A password-protected post prints no Recipe or FAQ markup: its ingredients and answers are what the password protects.' );
$subject = new ReflectionMethod( 'MSRWA_Head', 'subject' );
$subject->setAccessible( true );
msrwa_test_assert( 0 === $subject->invoke( null ), 'Nor its description in the meta tags.' );
$GLOBALS['msrwa_test_locked'] = false;

msrwa_test_done( 'articles written to be found and advertised beside' );
