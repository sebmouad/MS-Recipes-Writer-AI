<?php
// What a batch asks for, and what that means to the engine. A profile is only
// a caller configuration: if it ever needed the engine changed, it would be the
// wrong design.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-profile.php';

$all = MSRWA_Profile::all();
msrwa_test_assert( 3 === count( $all ), 'Three shapes of output are offered; got ' . count( $all ) );

// The full profile runs everything the engine ships, and changes nothing.
msrwa_test_assert( MSRWA_Engine_Steps::names() === MSRWA_Profile::steps( MSRWA_Profile::FULL ), 'The full profile is the engine’s own pipeline.' );
msrwa_test_assert( ! isset( MSRWA_Profile::config( MSRWA_Profile::FULL, "fr" )["steps"] ), "The full profile overrides no step." );

// Dropping the collage drops the judge with it: the engine asks that step for
// both images by name, so with one image there is nothing for it to compare.
$featured = MSRWA_Profile::steps( MSRWA_Profile::FEATURED );
msrwa_test_assert( ! in_array( 'facebook_image', $featured, true ), 'The featured profile draws no collage.' );
msrwa_test_assert( in_array( 'featured_image', $featured, true ), 'The featured profile still draws its featured image.' );
// The judge sees whatever was produced, so one image is still worth judging.
msrwa_test_assert( in_array( 'final_approval', $featured, true ), 'One image is still judged; only the comparison between two is lost.' );
foreach ( array( 'review', 'corrections', 'proofread' ) as $check ) {
	msrwa_test_assert( in_array( $check, $featured, true ), 'The text checks run in every profile; ' . $check . ' is missing.' );
}

$article = MSRWA_Profile::steps( MSRWA_Profile::ARTICLE );
msrwa_test_assert( ! array_intersect( array( 'featured_image', 'facebook_image' ), $article ), 'The article profile generates no image at all.' );
// With nothing drawn, the judge would add nothing the text checks have not
// already done, so the call is saved rather than spent.
msrwa_test_assert( ! in_array( 'final_approval', $article, true ), 'With no image there is nothing for the judge to look at.' );
msrwa_test_assert( in_array( 'proofread', $article, true ), 'An article-only run is still proofread.' );

// The point of the whole design: every remaining step must be runnable. A step
// waiting on an artifact nobody asked for would hang the run forever.
foreach ( array( MSRWA_Profile::FULL, MSRWA_Profile::FEATURED, MSRWA_Profile::ARTICLE ) as $profile ) {
	$config = MSRWA_Profile::config( $profile, 'fr' );
	$registry = (array) ( $config['steps'] ?? array() );
	$remaining = MSRWA_Profile::steps( $profile, $registry );
	$done = array( 'brief' => array( 'x' ) );
	$guard = 0;

	while ( $remaining && $guard++ < 20 ) {
		$wave = MSRWA_Engine_Steps::ready( $done, $remaining, $registry );
		msrwa_test_assert( (bool) $wave, 'Profile ' . $profile . ' stalls with ' . implode( ', ', $remaining ) . ' unrunnable.' );
		if ( ! $wave ) { break; }
		foreach ( $wave as $name ) {
			$produces = MSRWA_Engine_Steps::get( $name, $registry )['produces'];
			if ( $produces ) { $done[ $produces ] = array( 'x' ); }
		}
		$remaining = array_values( array_diff( $remaining, $wave ) );
	}
	msrwa_test_assert( ! $remaining, 'Profile ' . $profile . ' must run to the end; left: ' . implode( ', ', $remaining ) );
}

// The language is the engine's own key, not a translation of it.
msrwa_test_assert( 'en' === MSRWA_Profile::config( MSRWA_Profile::FULL, 'en' )['language'], 'The chosen language reaches the engine.' );
msrwa_test_assert( ! isset( MSRWA_Profile::config( MSRWA_Profile::FULL, 'klingon' )['language'] ), 'An unknown language is ignored, not passed on.' );
msrwa_test_assert( array( 'fr', 'en', 'ar', 'es' ) === array_keys( MSRWA_Profile::languages() ), 'Four languages are offered: French, English, Arabic and Spanish.' );
msrwa_test_contains( file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-settings.php' ), "array( '" . implode( "', '", array_keys( MSRWA_Profile::languages() ) ) . "' )", 'The settings accept exactly the languages offered.' );

// The prompts read the language from settings.site_language; the engine's own
// `language` key is read by nothing. A lot asked for in English was written in
// French until the language travelled where the prompts look.
foreach ( array( 'en', 'ar', 'fr' ) as $code ) {
	$config = MSRWA_Profile::config( MSRWA_Profile::ARTICLE, $code );
	msrwa_test_assert( $code === ( $config['settings']['site_language'] ?? '' ), 'The ' . $code . ' lot must reach the prompts as site_language.' );
}
msrwa_test_assert( 0 === MSRWA_Profile::config( MSRWA_Profile::FULL, 'en' )['thresholds']['article_accents_per_1000'], 'An English article is not failed for lacking French accents.' );
msrwa_test_assert( ! isset( MSRWA_Profile::config( MSRWA_Profile::FULL, 'fr' )['thresholds'] ), 'French keeps the measured accent threshold.' );
msrwa_test_assert( 'Step-by-step preparation' === MSRWA_Profile::config( MSRWA_Profile::ARTICLE, 'en' )['settings']['article_page2_heading'], 'An English lot on a French site turns its page in English.' );
msrwa_test_assert( ! isset( MSRWA_Profile::config( MSRWA_Profile::ARTICLE, 'fr' )['settings']['article_page2_heading'] ), 'A lot in the site language keeps the heading the site chose.' );
msrwa_test_assert( false !== strpos( MSRWA_Prompt::compile( 'Write in {{language}}.', array( 'site_language' => 'ar' ) ), 'Arabic' ), 'Arabic is a language the prompts can name.' );

// An unknown profile is the complete one, never an empty pipeline.
msrwa_test_assert( MSRWA_Engine_Steps::names() === MSRWA_Profile::steps( 'inventé' ), 'An unknown profile falls back to the full pipeline.' );

msrwa_test_done( 'output profiles OK' );
