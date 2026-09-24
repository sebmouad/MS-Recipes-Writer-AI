<?php
// The draft, in the shapes the MS stack parses. Each assertion is a field the
// theme's card, MS SEO Plus, MS FB Posts or MS Image Optimizer reads, and a
// value in the wrong shape shows up on the page: the card once printed the
// JSON of the ingredient list as the ingredient list.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
msrwa_test_load( 'stack', 'draft' );

$canonical = array(
	'title' => 'Daube de bœuf provençale', 'servings' => 4, 'prep_minutes' => 30, 'cook_minutes' => 180, 'total_minutes' => 210,
	'cuisine' => 'provençale française', 'recipe_category' => 'plat principal', 'difficulty' => 'intermédiaire', 'calories_estimate' => 650,
	'ingredients' => array(
		array( 'name' => 'paleron de bœuf', 'quantity' => 900, 'unit' => 'g' ),
		array( 'name' => 'cuillère à café de sel', 'quantity' => 0.5, 'unit' => '' ),
		array( 'name' => 'œufs', 'quantity' => 2, 'unit' => '' ),
		array( 'name' => 'sucre glace', 'quantity' => 'au goût', 'unit' => '' ),
	),
	'steps' => array( array( 'text' => 'Couper la viande.' ), array( 'text' => 'Mijoter <b>3 heures</b>.' ) ),
	'equipment' => array( 'cocotte', 'couteau' ), 'notes' => array( 'Meilleure le lendemain.' ),
	'faq' => array( array( 'question' => 'Se congèle-t-elle ?', 'answer' => 'Oui, trois mois.' ) ),
	'keywords' => array( 'daube', 'bœuf mijoté' ),
);
$article = array( 'seo_title' => 'Daube de bœuf provençale au vin rouge, fondante et parfumée, recette facile', 'seo_description' => 'Courte.', 'title' => 'Daube' );
$meta = MSRWA_Stack::recipe_meta( $canonical, $article, 'fr' );

msrwa_test_assert( '30' === $meta['_recipe_prep_time'] && '180' === $meta['_recipe_cook_time'] && '4' === $meta['_recipe_servings'] && '650' === $meta['_recipe_calories'], 'Times, servings and calories are whole numbers, as the card reads them.' );
msrwa_test_assert( "900 g paleron de bœuf\n0,5 cuillère à café de sel\n2 œufs\nsucre glace (au goût)" === $meta['_recipe_ingredients'], 'Ingredients are one per line, quantity first, in the article\'s decimal style; got ' . $meta['_recipe_ingredients'] );
msrwa_test_assert( "Couper la viande.\nMijoter 3 heures." === $meta['_recipe_instructions'], 'Steps are one per line, without markup.' );
msrwa_test_assert( "cocotte\ncouteau" === $meta['_recipe_equipment'], 'Equipment is one per line.' );
msrwa_test_assert( 'daube, bœuf mijoté' === $meta['_recipe_keywords'], 'Keywords are a comma list, not JSON.' );
msrwa_test_assert( 'medium' === $meta['_recipe_difficulty'], 'Difficulty uses the card\'s own vocabulary.' );
msrwa_test_assert( 'Provençale française' === $meta['_recipe_cuisine'], 'The cuisine reads as a label.' );
msrwa_test_assert( 'Se congèle-t-elle ?' === json_decode( $meta['_recipe_faq'], true )[0]['question'], 'The FAQ is stored as MS SEO Plus reads it.' );
msrwa_test_assert( mb_strlen( $meta['_seo_title'] ) <= 60 && ' ' !== mb_substr( $meta['_seo_title'], -1 ) && false === strpos( $meta['_seo_title'], 'recette fac' ), 'The SEO title is cut at a word, within 60 characters; got ' . $meta['_seo_title'] );
msrwa_test_assert( 'Courte.' === $meta['_seo_description'], 'A description that fits is kept whole.' );

// Nothing is invented: a field the recipe does not have stays out.
$bare = MSRWA_Stack::recipe_meta( array( 'title' => 'Tarte', 'difficulty' => 'pas facile à dire' ), array() );
foreach ( array( '_recipe_prep_time', '_recipe_calories', '_recipe_cuisine', '_recipe_difficulty', '_recipe_ingredients' ) as $key ) {
	msrwa_test_assert( ! isset( $bare[ $key ] ), 'No value is made up for ' . $key . '.' );
}
msrwa_test_assert( 'Tarte' === $bare['_seo_title'], 'The SEO title falls back to the recipe title.' );

// MS FB Posts refuses anything but a JSON string of {id, text}.
msrwa_test_assert( '[{"id":55,"text":"Une daube fondante."}]' === MSRWA_Stack::facebook_data( 55, 'Une daube fondante.' ), 'The collage and its caption reach MS FB Posts in its format.' );
msrwa_test_assert( '' === MSRWA_Stack::facebook_data( 0, 'x' ), 'No collage, no Facebook entry.' );

// A suggestion is filed under the category the site already has.
msrwa_test_assert( MSRWA_Stack::term_key( 'plat principal' ) === MSRWA_Stack::term_key( 'Plats principaux' ), 'Singular and plural name one category.' );
msrwa_test_assert( MSRWA_Stack::term_key( 'Dessert' ) === MSRWA_Stack::term_key( 'desserts' ), 'Case and a plural s do not split a category.' );
msrwa_test_assert( MSRWA_Stack::term_key( 'Entrées' ) === MSRWA_Stack::term_key( 'entree' ), 'Accents do not split a category.' );
msrwa_test_assert( MSRWA_Stack::term_key( 'Soupes' ) !== MSRWA_Stack::term_key( 'Salades' ), 'Different categories stay different.' );

msrwa_test_assert( 'hard' === MSRWA_Stack::difficulty( 'Difficile' ) && 'easy' === MSRWA_Stack::difficulty( 'facile' ), 'Each level is recognised.' );

// Nobody in the stack prints the head on a bare site; the theme does once it
// is active with no SEO plugin, and the plugin's own tags step aside.
msrwa_test_assert( ! MSRWA_Stack::owns_head(), 'Without the MS stack, the plugin prints its own head tags.' );
// Declared here, not at the top level, where PHP would hoist them.
if ( true ) {
	function ms_recipes_seo_plugin_active() { return false; }
	function ms_recipes_seo_singular_schema() {}
}
msrwa_test_assert( MSRWA_Stack::owns_head(), 'With the MS Recipes theme owning SEO, the plugin only feeds it.' );

// --- The generated images, described as MS Image Optimizer describes them --
// Alternative text and title from the SEO title, caption and description from
// the SEO description: its own mapping for every role, so it has nothing to
// rewrite, and all four fields are filled on a site without it.
if ( true ) {
	if ( ! function_exists( 'get_post_type' ) ) { function get_post_type( $id = 0 ) { return (string) ( $GLOBALS['msrwa_test_posts'][ (int) $id ]->post_type ?? '' ); } }
	if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $data ) { foreach ( (array) $data as $key => $value ) { if ( 'ID' !== $key ) { $GLOBALS['msrwa_test_posts'][ (int) $data['ID'] ]->$key = $value; } } return (int) $data['ID']; } }
}
$GLOBALS['msrwa_test_posts'][50] = (object) array( 'ID' => 50, 'post_type' => 'post', 'post_title' => 'Daube', 'post_excerpt' => 'Extrait.' );
$GLOBALS['msrwa_test_posts'][51] = (object) array( 'ID' => 51, 'post_type' => 'attachment', 'post_title' => 'Daube — Facebook image', 'post_excerpt' => '', 'post_content' => '' );
$GLOBALS['msrwa_test_meta'][50] = array( '_seo_title' => 'Daube de bœuf provençale au vin rouge', '_seo_description' => 'La daube de bœuf mijotée trois heures au vin rouge, comme en Provence.' );
msrwa_test_assert( MSRWA_Stack::describe_image( 50, 51 ), 'The collage is described.' );
$collage = $GLOBALS['msrwa_test_posts'][51];
msrwa_test_assert( 'Daube de bœuf provençale au vin rouge' === $collage->post_title && 'Daube de bœuf provençale au vin rouge' === get_post_meta( 51, '_wp_attachment_image_alt', true ), 'Title and alternative text are the SEO title.' );
msrwa_test_assert( 'La daube de bœuf mijotée trois heures au vin rouge, comme en Provence.' === $collage->post_excerpt && $collage->post_excerpt === $collage->post_content, 'Caption and description are the SEO description.' );
msrwa_test_assert( ! MSRWA_Stack::describe_image( 50, 50 ), 'Only an attachment is described.' );

// The generated images are named on keys MS Image Optimizer does not read as
// content: any meta key with "image" in it made the collage look used in the
// article, and the optimizer left it untouched.
foreach ( array( 'featured', 'facebook' ) as $kind ) {
	msrwa_test_assert( ! preg_match( '/(?:attachment|image|thumbnail|gallery|media|logo|icon|photo|picture)/i', MSRWA_Draft::generated_key( $kind ) ), 'The ' . $kind . ' key is not one the optimizer scans: ' . MSRWA_Draft::generated_key( $kind ) );
}
$source = '';
foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) { $source .= file_get_contents( $file ); }
msrwa_test_missing( preg_replace( '/\*.*_image_id.*\n/', '', $source ), "_image_id', true )", 'Nothing reads the old keys any more.' );

// Named from the post's slug, as the optimizer's profiles and MS Cook Writer name them.
$GLOBALS['msrwa_test_posts'][60] = (object) array( 'ID' => 60, 'post_type' => 'post', 'post_name' => 'daube-provencale' );
msrwa_test_assert( 'daube-provencale' === MSRWA_Draft::file_base( 60, 'Daube', 'featured' ), 'The featured image is named {post-slug}.' );
msrwa_test_assert( 'daube-provencale-fb-1' === MSRWA_Draft::file_base( 60, 'Daube', 'facebook' ), 'The collage is named {post-slug}-fb-1.' );
$GLOBALS['msrwa_test_posts'][61] = (object) array( 'ID' => 61, 'post_type' => 'post', 'post_name' => '' );
msrwa_test_assert( 'poulet-yassa' === MSRWA_Draft::file_base( 61, 'Poulet yassa', 'featured' ), 'A draft with no slug yet is named from its title.' );

// The image model answers in lossless WebP, which WordPress keeps lossless on
// every later encoding, so the optimizer's quality was ignored: stored lossy
// once, the image is compressed like any photograph.
if ( true ) {
	if ( ! function_exists( 'wp_get_webp_info' ) ) { function wp_get_webp_info( $file ) { $chunk = (string) file_get_contents( $file, false, null, 12, 4 ); return array( 'type' => 'VP8L' === $chunk ? 'lossless' : ( 'VP8 ' === $chunk ? 'lossy' : '' ) ); } }
}
if ( function_exists( 'imagewebp' ) ) {
	$canvas = imagecreatetruecolor( 512, 512 );
	// Noise, like a photograph's grain: what lossless keeps and lossy does not.
	mt_srand( 7 );
	for ( $x = 0; $x < 512; $x += 2 ) { for ( $y = 0; $y < 512; $y += 2 ) { imagefilledrectangle( $canvas, $x, $y, $x + 1, $y + 1, imagecolorallocate( $canvas, 120 + mt_rand( 0, 90 ), 80 + mt_rand( 0, 60 ), 40 + mt_rand( 0, 50 ) ) ); } }
	$lossless = sys_get_temp_dir() . '/msrwa-lossless-' . getmypid() . '.webp';
	imagewebp( $canvas, $lossless, 101 );
	msrwa_test_assert( 'lossless' === wp_get_webp_info( $lossless )['type'], 'The fixture is a lossless WebP, as the image model sends.' );
	$stored = MSRWA_Draft::lossy( $lossless, 'image/webp' );
	msrwa_test_assert( 'VP8 ' === substr( $stored, 12, 4 ) && strlen( $stored ) < filesize( $lossless ), sprintf( 'A lossless WebP is stored lossy and lighter: %d bytes from %d.', strlen( $stored ), filesize( $lossless ) ) );
	file_put_contents( $lossless, $stored );
	msrwa_test_assert( $stored === MSRWA_Draft::lossy( $lossless, 'image/webp' ), 'A lossy WebP is stored as it came, never encoded twice.' );
	msrwa_test_assert( 'x' !== MSRWA_Draft::lossy( $lossless, 'image/png' ) && file_get_contents( $lossless ) === MSRWA_Draft::lossy( $lossless, 'image/png' ), 'Another format is left alone.' );
	@unlink( $lossless );
}

// Recipe figures, this plugin's records and the image-size settings hold
// numbers the optimizer took for attachment ids: a collage that was
// attachment 150 was never compressed because a recipe cooks 150 minutes.
$GLOBALS['wpdb'] = (object) array( 'postmeta' => 'wp_postmeta', 'options' => 'wp_options', 'termmeta' => 'wp_termmeta' );
$narrowed = MSRWA_Stack::not_references( array(
	array( 'table' => 'wp_postmeta', 'where' => "meta_key NOT IN ('_thumbnail_id')" ),
	array( 'table' => 'wp_options', 'where' => '' ),
	array( 'table' => 'wp_termmeta', 'where' => '' ),
) );
msrwa_test_contains( $narrowed[0]['where'], "meta_key NOT IN ('_thumbnail_id') AND meta_key NOT LIKE '\\_recipe\\_%'", 'Recipe figures are not searched for attachment ids, and the optimizer’s own exclusions stay.' );
msrwa_test_contains( $narrowed[0]['where'], '_msrwa', 'Nor are this plugin’s records.' );
msrwa_test_contains( $narrowed[1]['where'], "'thumbnail_size_w'", 'Nor the image-size settings.' );
msrwa_test_assert( '' === $narrowed[2]['where'], 'Anything else is searched as before.' );

// MS Image Optimizer's workers are given the time they need, and only they.
// A 30-second host limit made every featured image fail "execution_limit_too_low".
msrwa_test_contains( file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-stack.php' ), "'msimg_image_queue_cron', 'msimg_post_discovery_cron'", 'The optimizer’s workers are the ones given more time.' );
set_time_limit( 30 );
MSRWA_Stack::room_for_images();
msrwa_test_assert( 180 === (int) ini_get( 'max_execution_time' ), 'A 30-second limit is raised to 180 for the optimizer; got ' . ini_get( 'max_execution_time' ) );
set_time_limit( 0 );
MSRWA_Stack::room_for_images();
msrwa_test_assert( 0 === (int) ini_get( 'max_execution_time' ), 'No limit is never turned into one.' );

msrwa_test_done( 'the MS stack reads what the draft wrote' );
