<?php
// One lot, end to end, on a live site. This is the only test that touches the
// parts no offline suite can reach: the migration, cron, the media library and
// the draft. It spends real money, so it refuses to start without a ceiling
// and uses the cheapest profile there is.
require __DIR__ . '/lib.php';

$budget = msrwa_real_budget();
if ( $budget <= 0 ) { msrwa_real_skip( 'MSRWA_TEST_BUDGET_USD is not set; this test spends real money.' ); }

$health = msrwa_real_request( 'GET', '/msrwa/v1/health' );
if ( empty( $health['body']['providers'] ) ) { msrwa_real_skip( 'No API key is stored on the site; nothing can be generated.' ); }

// Article only by default: the cheapest way to exercise the whole machine.
// MSRWA_TEST_PROFILE=full runs the images and the final approval too, which is
// what the estimate of a complete recipe is measured against.
$profile = in_array( getenv( 'MSRWA_TEST_PROFILE' ), array( 'article', 'featured', 'full' ), true ) ? getenv( 'MSRWA_TEST_PROFILE' ) : 'article';
$estimate = msrwa_real_request( 'GET', '/msrwa/v1/estimate?profile=' . $profile . '&recipes=1&images=0' );
$expected = (float) ( $estimate['body']['cost_usd'] ?? 0 );
$most = (float) ( $estimate['body']['max_usd'] ?? $expected );
msrwa_real_assert( $expected > 0, 'The article profile must be priced before it is run.' );
if ( $expected > $budget ) { msrwa_real_skip( 'One article is estimated at $' . $expected . ', over the $' . $budget . ' ceiling.' ); }
msrwa_real_note( 'estimated $' . number_format( $expected, 4 ) . ', at most $' . number_format( $most, 4 ) . ', ceiling $' . number_format( $budget, 4 ) );

// --- Submit ---------------------------------------------------------------

$created = msrwa_real_request( 'POST', '/msrwa/v1/batches', array(
	'recipes' => "Tarte aux pommes normande\nPâte brisée, pommes, crème, œufs, calvados. Cuire 45 minutes à 180 °C.",
	'images' => '',
	'budget' => round( $budget, 4 ),
	'profile' => $profile,
	'language' => 'fr',
), 180 );

msrwa_real_assert( 200 === $created['status'], 'A lot must be accepted (got ' . $created['status'] . ': ' . wp_json_encode_compat( $created['body'] ) . ').' );
$batch = (int) ( $created['body']['id'] ?? 0 );
msrwa_real_assert( $batch > 0, 'The lot must come back with a number.' );
msrwa_real_assert( 1 === (int) ( $created['body']['recipes'] ?? 0 ), 'One recipe must have been read out of the text.' );
msrwa_real_note( 'lot #' . $batch );

// --- Dispatch and wait ----------------------------------------------------

$dispatched = msrwa_real_request( 'POST', '/msrwa/v1/batches/' . $batch . '/dispatch' );
msrwa_real_assert( 200 === $dispatched['status'], 'The lot must dispatch (got ' . $dispatched['status'] . ').' );
msrwa_real_assert( 1 === (int) ( $dispatched['body']['started'] ?? 0 ), 'One recipe must have started.' );

// Cron carries it, one wave per tick. On a quiet site nothing visits, so the
// wait is generous and says why if it runs out.
$final = null;
$settled = msrwa_real_wait( function () use ( $batch, &$final ) {
	$runs = msrwa_real_request( 'GET', '/msrwa/v1/batches/' . $batch . '/runs' );
	$run = (array) ( $runs['body']['runs'][0] ?? array() );
	if ( ! $run ) { return false; }
	$final = $run;
	return ! in_array( (string) $run['status'], array( 'queued', 'running' ), true );
}, 900, 15 );

if ( ! $settled ) {
	msrwa_real_fail( 'The recipe never finished in fifteen minutes. Last seen: '
		. wp_json_encode_compat( $final )
		. ' — if it stayed queued, cron is not running: check DISABLE_WP_CRON and the server cron.' );
	msrwa_real_done( 'one lot, end to end' );
}

msrwa_real_spend( (float) ( $final['cost_usd'] ?? 0 ) );
msrwa_real_note( 'finished ' . $final['status'] . ' in ' . $final['seconds'] . 's for $' . number_format( (float) $final['cost_usd'], 4 ) );

msrwa_real_assert( 'done' === (string) $final['status'], 'The recipe must finish, not fail: ' . wp_json_encode_compat( $final ) );
msrwa_real_assert( (int) $final['steps_done'] === (int) $final['steps_total'], 'Every step of the profile must have run.' );

// The estimate exists to be believed. Within half of what was actually billed
// is the loosest band worth asserting.
$billed = (float) $final['cost_usd'];
msrwa_real_assert( $billed > 0, 'A run that called a provider cannot have cost nothing.' );
msrwa_real_assert(
	$billed <= $budget,
	'A run must never exceed the ceiling it was given: billed $' . $billed . ' against $' . $budget . '.'
);
// Two numbers, both checked. What is billed may run above the expected cost
// when the final approval refuses, but never above the maximum — with a tenth
// of slack, because OpenAI can run one search past the cap it was given.
msrwa_real_assert(
	$billed >= $expected * 0.5,
	'The estimate must not be far above the bill: estimated $' . number_format( $expected, 4 ) . ', billed $' . number_format( $billed, 4 ) . '.'
);
msrwa_real_assert(
	$billed <= $most * 1.1,
	'The bill must stay within the estimated maximum: at most $' . number_format( $most, 4 ) . ', billed $' . number_format( $billed, 4 ) . '.'
);
msrwa_real_note( 'billed ' . round( 100 * $billed / max( 0.0001, $expected ) ) . '% of the expected cost' );

// --- The draft ------------------------------------------------------------

$post = (int) ( $final['draft_post_id'] ?? 0 );
msrwa_real_assert( $post > 0, 'A finished recipe must have become a draft.' );

$draft = msrwa_real_request( 'GET', '/wp/v2/posts/' . $post . '?context=edit' );
msrwa_real_assert( 200 === $draft['status'], 'The draft must be readable (got ' . $draft['status'] . ').' );
msrwa_real_assert( 'draft' === (string) ( $draft['body']['status'] ?? '' ), 'It must be a draft, never published.' );
msrwa_real_assert( '' !== trim( (string) ( $draft['body']['content']['raw'] ?? '' ) ), 'A draft with no article in it is worse than no draft.' );
msrwa_real_note( 'draft #' . $post . ': ' . wp_strip_all_tags_compat( (string) ( $draft['body']['title']['raw'] ?? '' ) ) );

// What the article wrote about itself must reach the post: proofreading returns
// the body alone, and taking its artifact whole once left every draft without
// an excerpt or a slug.
msrwa_real_assert( '' !== trim( (string) ( $draft['body']['excerpt']['raw'] ?? '' ) ), 'The draft must carry the article’s excerpt.' );
msrwa_real_assert( '' !== trim( (string) ( $draft['body']['slug'] ?? '' ) ) || '' !== trim( (string) ( $draft['body']['generated_slug'] ?? '' ) ), 'The draft must carry a slug.' );
msrwa_real_assert( ! empty( $draft['body']['tags'] ), 'The article’s tags must be on the draft.' );

msrwa_real_note( 'excerpt ' . mb_strlen( (string) ( $draft['body']['excerpt']['raw'] ?? '' ) ) . ' chars, ' . count( (array) ( $draft['body']['tags'] ?? array() ) ) . ' tag(s)' );

// --- The article as the editor will open it -------------------------------

// Blocks, not one Classic block holding the whole article. Only a real site
// can answer this: the conversion runs against the engine's own HTML, and the
// version that dropped every element but the page break passed offline.
$raw = (string) ( $draft['body']['content']['raw'] ?? '' );
msrwa_real_assert( false !== strpos( $raw, '<!-- wp:' ), 'The article must be stored as blocks.' );
msrwa_real_assert( false === strpos( $raw, '<!-- wp:freeform' ), 'It must not arrive as one Classic block.' );
msrwa_real_assert( substr_count( $raw, '<!-- wp:paragraph' ) > 1, 'Its paragraphs must be paragraph blocks.' );
msrwa_real_assert( false !== strpos( $raw, '<!-- wp:heading' ), 'Its headings must be heading blocks.' );
// Delimiters that do not pair are what the editor reports as invalid content.
foreach ( array( 'paragraph', 'heading', 'list', 'list-item' ) as $block ) {
	$opened = preg_match_all( '~<!-- wp:' . preg_quote( $block, '~' ) . '( \{[^\n]*\})? -->~', $raw );
	msrwa_real_assert( $opened === substr_count( $raw, '<!-- /wp:' . $block . ' -->' ), 'Every ' . $block . ' block must be closed.' );
}
msrwa_real_note( substr_count( $raw, '<!-- wp:' ) . ' block(s) in the article' );

// The images the engine drew must be in the media library and on the post,
// with something a screen reader can read.
$featured = (int) ( $draft['body']['featured_media'] ?? 0 );
if ( $featured ) {
	$media = msrwa_real_request( 'GET', '/wp/v2/media/' . $featured );
	msrwa_real_assert( 200 === $media['status'], 'The featured image must be in the media library.' );
	msrwa_real_assert( (int) ( $media['body']['post'] ?? 0 ) === $post, 'It must be attached to the draft, not left loose.' );
	msrwa_real_assert( '' !== trim( (string) ( $media['body']['alt_text'] ?? '' ) ), 'It must carry alternative text.' );
	msrwa_real_assert( ! empty( $media['body']['media_details']['sizes'] ), 'WordPress must have generated its sizes.' );
	msrwa_real_note( 'featured image #' . $featured . ', ' . count( (array) $media['body']['media_details']['sizes'] ) . ' size(s)' );
} else {
	msrwa_real_note( 'no featured image: this lot was run on the article-only profile' );
}

// --- What a published article tells search engines ------------------------

// The head tags and the Recipe markup print on published posts only, so the
// draft is published for the length of this check and put straight back. It is
// never left published: an unreviewed article on a live site is exactly what
// this plugin exists to prevent.
$published = msrwa_real_request( 'POST', '/wp/v2/posts/' . $post, array( 'status' => 'publish' ) );
if ( 200 === $published['status'] ) {
	$html = msrwa_real_page( (string) ( $published['body']['link'] ?? '' ) );
	msrwa_real_request( 'POST', '/wp/v2/posts/' . $post, array( 'status' => 'draft' ) );

	msrwa_real_assert( '' !== $html, 'The published article must be reachable.' );
	foreach ( array( 'og:title', 'og:description', 'og:url', 'twitter:card' ) as $tag ) {
		msrwa_real_assert( false !== strpos( $html, '"' . $tag . '"' ), 'The page must carry its ' . $tag . '.' );
	}
	msrwa_real_assert( false !== strpos( $html, 'name="description"' ), 'The page must carry a meta description.' );
	if ( preg_match( '~<script type="application/ld\+json">(.*?)</script>~s', $html, $found ) ) {
		$data = json_decode( $found[1], true );
		msrwa_real_assert( 'Recipe' === ( $data['@type'] ?? '' ), 'The structured data must describe a Recipe.' );
		msrwa_real_assert( ! empty( $data['recipeIngredient'] ), 'It must list the ingredients.' );
		msrwa_real_assert( ! empty( $data['recipeInstructions'] ), 'And the steps.' );
		msrwa_real_note( 'Recipe JSON-LD with ' . count( (array) $data['recipeIngredient'] ) . ' ingredient(s)' );
	} else {
		msrwa_real_fail( 'The published article carried no Recipe JSON-LD.' );
	}

	$back = msrwa_real_request( 'GET', '/wp/v2/posts/' . $post . '?context=edit' );
	msrwa_real_assert( 'draft' === (string) ( $back['body']['status'] ?? '' ), 'The article must be back to a draft.' );
} else {
	msrwa_real_note( 'could not publish the draft to check the page head (got ' . $published['status'] . ')' );
}

msrwa_real_done( 'one lot, end to end' );
