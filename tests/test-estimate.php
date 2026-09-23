<?php
// What a lot will cost, before anything is spent.
//
// The figure is derived from the configuration that will actually run it, so
// this checks it against runs that really happened. An estimate nobody has
// compared to a bill is a number, not an estimate.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'i18n', 'profile', 'engine-settings' );
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-estimate.php';

$full = MSRWA_Estimate::recipe( MSRWA_Profile::FULL );

// Two real runs of the same brief on the shipped configuration cost $0.1165
// and $0.1128. An estimate outside a quarter of that is not an estimate.
$measured = 0.1147;
msrwa_test_assert(
	abs( $full['cost_usd'] - $measured ) / $measured < 0.25,
	'The full estimate must land near what real runs cost; got ' . $full['cost_usd'] . ' against ' . $measured
);
msrwa_test_assert( array() === $full['unpriced'], 'Every shipped route must be priced; unpriced: ' . implode( ', ', $full['unpriced'] ) );

// Images are the expensive half and route through `routing.image`, not through
// their own step name. Pricing them on the text route once made a collage read
// as a twenty-fifth of its real cost.
msrwa_test_assert( $full['buckets']['featured'] > 0.01, 'The featured image is priced as an image; got ' . $full['buckets']['featured'] );
msrwa_test_assert( $full['buckets']['facebook'] > 0.01, 'The collage is priced as an image; got ' . $full['buckets']['facebook'] );
msrwa_test_assert( 'image' === MSRWA_Estimate::route_for( 'featured_image', 'image_generation' ), 'Image steps resolve through the image route.' );
msrwa_test_assert( 'vision' === MSRWA_Estimate::route_for( 'reference_vision', 'vision' ), 'Vision steps resolve through the vision route.' );
msrwa_test_assert( 'article' === MSRWA_Estimate::route_for( 'article', 'text' ), 'Text steps resolve through their own name.' );

// Research is billed per search on top of its tokens: three searches at the
// provider's per-search fee are part of what the step costs.
$route = MSRWA_Engine_Config::create()->model_for( 'research' );
$tokens_only = MSRWA_Engine_Config::create()->price( $route['provider'], $route['model'], array( 'input_tokens' => 65000, 'output_tokens' => min( 6200, MSRWA_Engine_Config::create()->max_output( 'research' ) ) ) );
msrwa_test_assert( $full['steps']['research']['cost_usd'] > $tokens_only, 'The research estimate includes its searches.' );

// Asking for less must cost less, in the bucket it was removed from.
$featured = MSRWA_Estimate::recipe( MSRWA_Profile::FEATURED );
$article = MSRWA_Estimate::recipe( MSRWA_Profile::ARTICLE );
msrwa_test_assert( $featured['cost_usd'] < $full['cost_usd'], 'Dropping the collage costs less.' );
msrwa_test_assert( 0.0 === $featured['buckets']['facebook'], 'And nothing is charged to a collage nobody drew.' );
msrwa_test_assert( $article['cost_usd'] < $featured['cost_usd'], 'Dropping both images costs less again.' );
msrwa_test_assert( 0.0 === $article['buckets']['featured'], 'Nothing is charged to an image nobody drew.' );

// A lot costs its recipes plus one look at each photograph, which is spent
// before any recipe starts.
$lot = MSRWA_Estimate::lot( MSRWA_Profile::FULL, 4, 6 );
msrwa_test_assert( $lot['matching_usd'] > 0, 'Pairing six photographs is not free.' );
msrwa_test_assert(
	abs( $lot['cost_usd'] - ( $full['cost_usd'] * 4 + $lot['matching_usd'] ) ) < 0.000001,
	'A lot is its recipes plus its pairing; got ' . $lot['cost_usd']
);

// A model with no published rate is unknown, never free — the distinction the
// whole ledger is built on.
$unpriced = MSRWA_Estimate::recipe( MSRWA_Profile::ARTICLE, array( 'routing' => array( 'article' => 'openai:pas-un-modele' ) ) );
msrwa_test_assert( in_array( 'article', $unpriced['unpriced'], true ), 'An unpriced route is reported as unknown.' );
msrwa_test_assert( ! isset( $unpriced['steps']['article'] ), 'And it is not silently counted as costing nothing.' );

msrwa_test_done( 'estimates track what runs really cost' );
