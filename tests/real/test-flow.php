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

// Sent the way the compose screen sends it: a form, with a photograph from
// disk when PHP can draw one, so the upload, the pairing and the draft taking
// the photograph are all on the path this test walks.
$fields = array(
	'recipes' => "Tarte aux pommes normande\nPâte brisée, pommes, crème, œufs, calvados. Cuire 45 minutes à 180 °C.",
	'budget' => (string) round( $budget, 4 ),
	'profile' => $profile,
	'language' => 'fr',
);
// A real photograph of the dish when one is given; otherwise a drawn one,
// which the pairing rightly sets aside: no dish can be recognised on it.
$photo = '';
$real_photo = (string) getenv( 'MSRWA_TEST_PHOTO' );
if ( '' !== $real_photo && is_readable( $real_photo ) ) {
	$photo = sys_get_temp_dir() . '/msrwa-flow-tarte-' . getmypid() . '.' . pathinfo( $real_photo, PATHINFO_EXTENSION );
	copy( $real_photo, $photo );
} elseif ( function_exists( 'imagecreatetruecolor' ) ) {
	$photo = sys_get_temp_dir() . '/msrwa-flow-tarte-' . getmypid() . '.jpg';
	$image = imagecreatetruecolor( 640, 480 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 222, 196, 150 ) );
	imagefilledellipse( $image, 320, 240, 440, 440, imagecolorallocate( $image, 200, 140, 60 ) );
	imagejpeg( $image, $photo, 85 );
}
$created = msrwa_real_upload( '/msrwa/v1/batches', $fields, $photo ? array( $photo ) : array() );
if ( $photo ) { @unlink( $photo ); }

msrwa_real_assert( 200 === $created['status'], 'A lot must be accepted (got ' . $created['status'] . ': ' . wp_json_encode_compat( $created['body'] ) . ').' );
$batch = (int) ( $created['body']['id'] ?? 0 );
msrwa_real_assert( $batch > 0, 'The lot must come back with a number.' );
msrwa_real_assert( 1 === (int) ( $created['body']['recipes'] ?? 0 ), 'One recipe must have been read out of the text.' );
msrwa_real_assert( ( $photo ? 1 : 0 ) === (int) ( $created['body']['images'] ?? -1 ), 'The photograph sent is the lot\'s photograph.' );
msrwa_real_note( 'lot #' . $batch );

// --- Dispatch and wait ----------------------------------------------------

$dispatched = msrwa_real_request( 'POST', '/msrwa/v1/batches/' . $batch . '/dispatch' );
// A photograph the pairing could not recognise waits for the writer (0.25.0)
// and holds the lot back: the drawn one is set aside, as the writer would on
// the pairing screen, and the lot is sent again.
if ( 409 === $dispatched['status'] && $photo ) {
	$aside = msrwa_real_request( 'POST', '/msrwa/v1/batches/' . $batch . '/pairs', array( 'pairs' => array( array( 'image' => 0, 'recipe' => 'aside' ) ) ) );
	msrwa_real_assert( 200 === $aside['status'], 'An undecided photograph can be set aside (got ' . $aside['status'] . ').' );
	msrwa_real_note( 'the unrecognised photograph was set aside' );
	$dispatched = msrwa_real_request( 'POST', '/msrwa/v1/batches/' . $batch . '/dispatch' );
}
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

// The photograph the writer sent is attached to the draft that came of it,
// where the editor will look for it — unless the pairing set it aside.
if ( $photo ) {
	$media = msrwa_real_request( 'GET', '/wp/v2/media?parent=' . $post . '&per_page=20&context=edit' );
	$sent = array_filter( (array) $media['body'], static function ( $item ) { return false !== strpos( (string) ( $item['source_url'] ?? '' ), 'msrwa-flow-tarte' ); } );
	msrwa_real_note( count( (array) $media['body'] ) . ' attachment(s) on the draft, ' . count( $sent ) . ' of them the photograph sent' );
	if ( '' !== $real_photo ) {
		msrwa_real_assert( 1 === count( $sent ), 'The writer\'s photograph is attached to the draft.' );
	} else {
		msrwa_real_assert( count( $sent ) <= 1, 'A drawn photograph is attached at most once.' );
		msrwa_real_note( 'no real photograph given (MSRWA_TEST_PHOTO): the drawn one ' . ( $sent ? 'was paired' : 'was set aside by the pairing, as it should be' ) );
	}
}

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

// --- A refused image, redrawn at the editor's request ---------------------

// The engine no longer redraws on its own (0.28.0). An image the judge refused
// is offered to the editor; one it accepted is not, and asking costs nothing.
if ( 'article' !== $profile ) {
	$refused = array();
	foreach ( array( 'featured', 'facebook' ) as $kind ) {
		$again = msrwa_real_request( 'POST', '/msrwa/v1/runs/' . (int) $final['id'] . '/redraw', array( 'kind' => $kind ), 180 );
		if ( 200 === $again['status'] ) {
			$refused[] = $kind;
			$after = msrwa_real_request( 'GET', '/msrwa/v1/batches/' . $batch . '/runs' );
			$cost = (float) ( $after['body']['runs'][0]['cost_usd'] ?? 0 );
			msrwa_real_assert( $cost > $billed, 'A redraw is recorded on the recipe’s bill.' );
			msrwa_real_spend( $cost - $billed );
			msrwa_real_note( 'the refused ' . $kind . ' image was redrawn for $' . number_format( $cost - $billed, 4 ) );
			$billed = $cost;
			if ( 'featured' === $kind ) {
				$redrawn = msrwa_real_request( 'GET', '/wp/v2/posts/' . $post . '?context=edit' );
				msrwa_real_assert( (int) ( $redrawn['body']['featured_media'] ?? 0 ) !== $featured, 'The redrawn image replaces the draft’s featured image.' );
			}
		} else {
			msrwa_real_assert( 409 === $again['status'], 'An image the judge accepted is not redrawn (got ' . $again['status'] . ').' );
		}
	}
	if ( ! $refused ) { msrwa_real_note( 'the judge accepted both images: nothing to redraw, and asking spent nothing' ); }
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
