<?php
// The engine was written for a French site and three things in it assumed
// that silently. Each failed an article in another language without saying
// why, and each cost a correction round that could not fix anything.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $class ) { require_once $class; }
require_once MSRWA_DIR . 'includes/engine/load.php';
require __DIR__ . '/bootstrap.php';

// --- The outline is matched in the language it was written in ------------

$outlines = MSRWA_Engine_Score::outlines();
foreach ( array_keys( MSRWA_Profile::languages() ) as $shipped ) {
	msrwa_test_assert( isset( $outlines[ $shipped ] ), 'Every language the plugin ships has an outline: ' . $shipped . ' has none.' );
	msrwa_test_assert( '' !== MSRWA_Profile::page_two_heading( $shipped ) && ( 'fr' === $shipped || MSRWA_Profile::page_two_heading( $shipped ) !== MSRWA_Profile::page_two_heading( 'fr' ) ), 'Page two opens in ' . $shipped . '.' );
}
msrwa_test_assert( isset( MSRWA_Engine_Score::required_sections( array( 'site_language' => 'es' ) )['ingredientes'] ), 'A Spanish article is asked for Spanish sections.' );
msrwa_test_assert( MSRWA_Profile::language_exists( 'es' ) && ! MSRWA_Profile::language_exists( 'de' ), 'Spanish ships; a language nobody reviews in does not.' );
$french = count( $outlines['fr'] );
foreach ( $outlines as $language => $sections ) {
	msrwa_test_assert( count( $sections ) === $french, $language . ' requires the same sections as French: a translation, not a different specification.' );
	foreach ( $sections as $section => $synonyms ) {
		msrwa_test_assert( ! empty( $synonyms ), $language . ':' . $section . ' has at least one wording that satisfies it.' );
	}
}

// A correct English article used to fail this every time — seen live at 9/10,
// "missing: choix, matériel, erreurs, conservation" — because only French
// wordings were ever matched.
$english = MSRWA_Engine_Score::required_sections( array( 'site_language' => 'en' ) );
msrwa_test_assert( isset( $english['ingredients'] ), 'An English site is asked for English sections.' );
msrwa_test_missing( implode( ' ', array_keys( $english ) ), 'matériel', 'And not for French ones.' );
msrwa_test_assert( isset( MSRWA_Engine_Score::required_sections( array( 'site_language' => 'fr' ) )['matériel'] ), 'A French site still is.' );
msrwa_test_assert( isset( MSRWA_Engine_Score::required_sections( array() )['matériel'] ), 'And so is a site that says nothing, as before.' );
msrwa_test_assert( isset( MSRWA_Engine_Score::required_sections( array( 'site_language' => 'xx' ) )['matériel'] ), 'An unknown language falls back rather than requiring nothing.' );

// A caller with its own outline decides it entirely: this is what an editable
// outline needs.
$own = MSRWA_Engine_Score::required_sections( array( 'required_sections' => array( 'wine' => array( 'wine', 'pairing' ) ) ) );
msrwa_test_assert( array( 'wine' ) === array_keys( $own ), 'A supplied outline replaces the shipped one entirely.' );

// --- The typography rule is French orthography, not a universal one -------

// Production MSRWA_Settings is loaded here, so the settings are handed over
// explicitly rather than through the harness double it displaces.
$french_vars = MSRWA_Prompt::variables( array( 'site_language' => 'fr' ) );
$english_vars = MSRWA_Prompt::variables( array( 'site_language' => 'en' ) );
$arabic_vars = MSRWA_Prompt::variables( array( 'site_language' => 'ar' ) );
msrwa_test_assert( true === $french_vars['french'], 'A French article is told to carry French accents.' );
msrwa_test_assert( false === $english_vars['french'], 'An English one is not: it has no é to carry.' );
msrwa_test_assert( false === $arabic_vars['french'], 'Nor is an Arabic one.' );
msrwa_test_assert( 'English' === $english_vars['language'], 'And each is told which language to write.' );
msrwa_test_assert( 'Arabic' === $arabic_vars['language'], 'Including Arabic.' );

$template = (string) file_get_contents( MSRWA_DIR . 'includes/engine/prompts/article.tpl.txt' );
msrwa_test_contains( $template, '{{#if french}}', 'And the prompt asks for it conditionally.' );

// --- The top-level language key reaches the prompts -----------------------

// It was recorded on every run and shown on the Moteur screen, and every
// prompt reads settings.site_language. A caller that set one and not the other
// got an article in the wrong language with no indication why.
$config = MSRWA_Engine_Config::create( array( 'language' => 'en' ) );
msrwa_test_assert( 'en' === $config->settings()['site_language'], 'A caller that sets only `language` still reaches the prompts.' );

// And it never overrides a site that stated its own.
$explicit = MSRWA_Engine_Config::create( array( 'language' => 'en', 'settings' => array( 'site_language' => 'ar' ) ) );
msrwa_test_assert( 'ar' === $explicit->settings()['site_language'], 'A stated site language wins over the top-level key.' );

// --- An article is measured at both ends ---------------------------------

// The words check compared against the minimum only. A live English article
// came back at 5 988 words against a 2 800–3 600 target and passed, and an
// over-long article is paid for twice more: review and proofread each read it
// whole.
MSRWA_Engine_Input::use_settings( array(
	'site_language' => 'en', 'quality_min_words' => 2800, 'quality_max_words' => 3600,
	'quality_min_headings' => 1, 'quality_min_paragraphs' => 1, 'quality_min_score' => 0,
	'quality_min_ingredients' => 1, 'quality_min_steps' => 1, 'internal_links_max' => 3,
) );

/** An article of a given length, with the headings the outline wants. */
function msrwa_article_of( $words ) {
	$body = '';
	foreach ( MSRWA_Engine_Score::required_sections( array( 'site_language' => 'en' ) ) as $section => $synonyms ) {
		$body .= '<h2>' . $synonyms[0] . '</h2><p>' . str_repeat( 'word ', 20 ) . '</p>';
	}
	$body .= '<p>' . str_repeat( 'word ', max( 1, $words ) ) . '</p><!--nextpage-->';
	return $body;
}

/** The words check for one article length. */
function msrwa_words_check( $words ) {
	$json = array(
		'content_html' => msrwa_article_of( $words ),
		'title' => 'x', 'excerpt' => 'x', 'seo_title' => 'x', 'seo_description' => 'x', 'slug' => 'x',
		'tags' => array(), 'categories' => array(), 'recipe_meta' => array(), 'internal_links' => array(),
		'facebook_caption' => 'x', 'faq' => array(), 'visual_final_notes' => 'x',
	);
	$checks = MSRWA_Engine_Score::step( 'article', wp_json_encode( $json ), array() );
	return $checks['checks']['words'] ?? $checks['words'] ?? array();
}

$short = msrwa_words_check( 100 );
$right = msrwa_words_check( 3000 );
$long = msrwa_words_check( 8000 );

msrwa_test_assert( empty( $short['pass'] ), 'An article under the minimum still fails.' );
msrwa_test_assert( ! empty( $right['pass'] ), 'One inside the range passes.' );
msrwa_test_assert( empty( $long['pass'] ), 'And one far over the maximum now fails rather than being billed on.' );
msrwa_test_contains( $long['detail'], 'maximum', 'The detail says which end it failed, so a correction knows what to do.' );

// A little over is not a failure: the target is a target, and asking for the
// whole article again to shed fifty words costs more than the fifty words.
msrwa_test_assert( ! empty( msrwa_words_check( 3700 )['pass'] ), 'A little over the maximum is tolerated.' );

// A live Arabic article was marked as missing its "choosing" section while
// it had one: "كيف تختار الدجاج…" uses the verb, and vowel marks such as
// "يُقدَّم" must not hide a word either.
$ar = MSRWA_Engine_Score::outlines()['ar'];
$found = static function ( $heading, $section ) use ( $ar ) {
	foreach ( $ar[ $section ] as $word ) { if ( false !== strpos( MSRWA_Engine_Score::fold( $heading ), $word ) ) { return true; } }
	return false;
};
msrwa_test_assert( $found( 'كيف تختار الدجاج والليمون والزيتون للطاجين؟', 'الاختيار' ), 'The verb "to choose" names the choosing section.' );
msrwa_test_assert( $found( 'مع ماذا يُقدَّم طاجين الدجاج؟', 'التقديم' ), 'Vowel marks do not hide a word.' );

msrwa_test_done( 'the engine in three languages' );
