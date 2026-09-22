<?php
// The share preview and the meta description a published article carries. Held
// here because all of it is written once, months before anybody looks at the
// page source, and a wrong tag is invisible until a link is shared.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $class ) { require_once $class; }
require_once MSRWA_DIR . 'includes/engine/load.php';
require __DIR__ . '/bootstrap.php';

/** One tag's content, by name. */
function msrwa_tag( array $tags, $name ) {
	foreach ( $tags as $tag ) {
		if ( $name === $tag['name'] ) { return $tag['content']; }
	}
	return null;
}

msrwa_test_assert( array() === MSRWA_Head::tags( 0 ), 'A page that is not one of this plugin’s articles gets no tags at all.' );

// --- An article with everything the engine writes ------------------------

$GLOBALS['msrwa_test_posts'] = array( 11 => (object) array( 'ID' => 11, 'post_title' => 'Tarte aux pommes normande', 'post_excerpt' => 'Un repli si rien d’autre.' ) );
$GLOBALS['msrwa_test_meta'] = array(
	11 => array(
		'_msrwa_run_id' => 4,
		'_msrwa_seo_title' => 'Tarte aux pommes normande : la recette au calvados',
		'_msrwa_seo_description' => 'La tarte normande à la crème et au calvados, cuite 45 minutes à 180 °C.',
		'_msrwa_facebook_caption' => 'Le dimanche, une tarte normande et rien d’autre.',
		'_msrwa_facebook_image_id' => 15,
		'_msrwa_language' => 'fr',
	),
	15 => array( '_wp_attachment_image_alt' => 'Tarte aux pommes normande' ),
);
$GLOBALS['msrwa_test_thumbnails'] = array( 11 => 14 );
$GLOBALS['msrwa_test_attachments'] = array(
	14 => array( 'width' => 1024, 'height' => 1024 ),
	15 => array( 'width' => 1200, 'height' => 630 ),
);

$tags = MSRWA_Head::tags( 11 );

msrwa_test_assert( 'La tarte normande à la crème et au calvados, cuite 45 minutes à 180 °C.' === msrwa_tag( $tags, 'description' ), 'The description is the one the article wrote.' );
msrwa_test_assert( 'Tarte aux pommes normande : la recette au calvados' === msrwa_tag( $tags, 'og:title' ), 'The share title is the SEO title, not the post title.' );
msrwa_test_assert( msrwa_tag( $tags, 'description' ) === msrwa_tag( $tags, 'og:description' ), 'The share description says the same thing.' );
msrwa_test_assert( 'article' === msrwa_tag( $tags, 'og:type' ), 'The page is declared an article.' );
msrwa_test_assert( 'fr_FR' === msrwa_tag( $tags, 'og:locale' ), 'The locale is the lot’s language, which the site’s own may not be.' );
msrwa_test_assert( 'Le Site' === msrwa_tag( $tags, 'og:site_name' ), 'The site names itself.' );
msrwa_test_contains( msrwa_tag( $tags, 'og:url' ), '11', 'The share URL is the post’s own.' );

// The engine draws the Facebook image at exactly the size the preview wants,
// so it wins over the featured image, which is square and would be cropped.
msrwa_test_contains( msrwa_tag( $tags, 'og:image' ), '15', 'The sharing image is the one drawn for sharing.' );
msrwa_test_assert( '1200' === msrwa_tag( $tags, 'og:image:width' ), 'Its width is declared.' );
msrwa_test_assert( '630' === msrwa_tag( $tags, 'og:image:height' ), 'And its height.' );
msrwa_test_assert( 'Tarte aux pommes normande' === msrwa_tag( $tags, 'og:image:alt' ), 'And what it is a photograph of.' );
msrwa_test_assert( 'summary_large_image' === msrwa_tag( $tags, 'twitter:card' ), 'A page with an image asks for the large card.' );
msrwa_test_contains( msrwa_tag( $tags, 'twitter:image' ), '15', 'Which uses the same image.' );

// The caption is what an editor posts alongside the link; it describes the
// post they are writing, not the page, so it must not leak into a tag.
foreach ( $tags as $tag ) {
	msrwa_test_missing( $tag['content'], 'Le dimanche', 'The Facebook caption stays out of ' . $tag['name'] . '.' );
}

// --- An article that wrote no SEO fields ---------------------------------

$GLOBALS['msrwa_test_meta'] = array( 11 => array( '_msrwa_run_id' => 4 ) );
$GLOBALS['msrwa_test_thumbnails'] = array();
$bare = MSRWA_Head::tags( 11 );
msrwa_test_assert( 'Tarte aux pommes normande' === msrwa_tag( $bare, 'og:title' ), 'Without an SEO title the post’s own title is used.' );
msrwa_test_assert( 'Un repli si rien d’autre.' === msrwa_tag( $bare, 'description' ), 'And the excerpt stands in for the description.' );
msrwa_test_assert( null === msrwa_tag( $bare, 'og:image' ), 'A post with no image claims none.' );
msrwa_test_assert( 'summary' === msrwa_tag( $bare, 'twitter:card' ), 'And asks for the small card rather than an empty large one.' );

// Falling back to the featured image when no sharing image was drawn.
$GLOBALS['msrwa_test_thumbnails'] = array( 11 => 14 );
msrwa_test_contains( msrwa_tag( MSRWA_Head::tags( 11 ), 'og:image' ), '14', 'With no sharing image, the featured image is shared.' );

// --- Somebody else already does this -------------------------------------

msrwa_test_assert( ! MSRWA_Head::another_plugin_prints_it(), 'On a bare site nothing else prints these tags.' );
define( 'WPSEO_VERSION', '99.0' );
msrwa_test_assert( MSRWA_Head::another_plugin_prints_it(), 'With an SEO plugin active this plugin stands aside.' );

msrwa_test_done( 'head' );
