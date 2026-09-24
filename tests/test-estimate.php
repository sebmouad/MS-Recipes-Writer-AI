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

/** The shipped default, read from the real settings file without loading the class the harness doubles. */
final class MSRWA_Settings_Defaults_For_Test {
	public static function ceiling() {
		preg_match( "/'per_recipe_budget_usd'\s*=>\s*([0-9.]+)/", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-settings.php' ), $m );
		return (float) ( $m[1] ?? 0 );
	}
}

$full = MSRWA_Estimate::recipe( MSRWA_Profile::FULL );

// Real runs on the shipped configuration, 2026-09-23, OpenAI, after the search
// and thinking economies: one pass of a full recipe cost $0.1055, $0.109 and
// $0.115 on three dishes, the article profile about $0.050. On 0.19.0, with
// the proofread returning its changes only and shorter image prompts, a
// recipe approved at the first pass billed $0.0896 and $0.0912. On 0.26.6, with
// the collage drawn at high, first-pass recipes billed $0.1203 and $0.1259. The
// estimate must never read below what was billed — that is how a lot passes a
// ceiling it then breaks — and not so far above it that it stops meaning anything.
$measured = 0.126;
msrwa_test_assert(
	$full['cost_usd'] >= $measured && $full['cost_usd'] <= $measured * 1.4,
	'The full estimate must sit at or just above what one real pass cost; got ' . $full['cost_usd'] . ' against ' . $measured
);
$article_only = MSRWA_Estimate::recipe( MSRWA_Profile::ARTICLE );
msrwa_test_assert( $article_only['cost_usd'] >= 0.050 && $article_only['cost_usd'] <= 0.050 * 1.5, 'The article profile must sit at or just above its real runs; got ' . $article_only['cost_usd'] );
// The maximum is what the engine can spend when every approval refuses, and
// the run that was refused twice must fall inside it.
msrwa_test_assert( $full['max_usd'] >= 0.1839, 'The maximum covers the real run refused twice ($0.1839); got ' . $full['max_usd'] );
// The shipped per-recipe ceiling lets a full recipe through with room for a
// redrawn collage; the engine stops retrying before crossing it. At high
// quality one redraw billed $0.192, so $0.20 left no room for a second.
$ceiling = (float) MSRWA_Settings_Defaults_For_Test::ceiling();
msrwa_test_assert( 0.25 === $ceiling, 'The shipped per-recipe ceiling is $0.25.' );
msrwa_test_assert( $ceiling >= 0.192 + 0.057, 'The ceiling leaves room for a second collage after one redraw.' );
msrwa_test_assert( MSRWA_Estimate::fits( $full['cost_usd'], $ceiling ), 'A full recipe fits under the shipped ceiling; estimated ' . $full['cost_usd'] );
msrwa_test_assert( $full['cost_usd'] + 0.035 < $ceiling, 'With room for one redrawn collage and approval.' );
msrwa_test_assert( $full['max_usd'] > $full['cost_usd'], 'A recipe that can be refused has a maximum above its expected cost.' );
// Without a final approval nothing is redrawn; what remains between the two
// figures is research spending every tool call it is allowed on paid searches.
$config = MSRWA_Engine_Config::create();
$route = $config->model_for( 'research' );
$extra = ( $config->web_tool_calls( $route['provider'] ) - $article_only['steps']['research']['searches'] ) * (float) $config->get( 'providers.' . $route['provider'] . '.web_search_usd' );
msrwa_test_assert( abs( $article_only['max_usd'] - $article_only['cost_usd'] - $extra ) < 1e-6, 'Without a final approval, the maximum is research at its tool-call cap; got ' . $article_only['max_usd'] );
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

// Thinking harder costs more, and is capped by the ceiling it is spent from.
$high = MSRWA_Estimate::recipe( MSRWA_Profile::FULL, array( 'thinking' => array( 'default' => 'high' ) ) );
msrwa_test_assert( $high['cost_usd'] > $full['cost_usd'], 'A recipe allowed to think harder is estimated higher.' );
msrwa_test_assert( 'high' === $high['steps']['article']['thinking'], 'Each step says the level it was estimated at.' );
msrwa_test_assert( '' === $high['steps']['featured_image']['thinking'], 'An image model does not think.' );
// 256 is the smallest ceiling the engine accepts.
$capped = MSRWA_Estimate::recipe( MSRWA_Profile::ARTICLE, array( 'thinking' => array( 'default' => 'high' ), 'max_output' => array( 'article' => 256 ) ) );
$route = MSRWA_Engine_Config::create()->model_for( 'article' );
msrwa_test_assert( abs( $capped['steps']['article']['cost_usd'] - round( MSRWA_Engine_Config::create()->price( $route['provider'], $route['model'], array( 'input_tokens' => 6900, 'output_tokens' => 256 ) ), 6 ) ) < 1e-9, 'Never past the ceiling, whatever the level.' );

// Each image has its own model and quality, and the estimate follows both.
$split = MSRWA_Estimate::recipe( MSRWA_Profile::FULL, array( 'images' => array( 'featured_quality' => 'high', 'facebook_quality' => 'low' ) ) );
msrwa_test_assert( 'high' === $split['steps']['featured_image']['quality'] && 'low' === $split['steps']['facebook_image']['quality'], 'Each image is estimated at its own quality.' );
msrwa_test_assert( $split['steps']['featured_image']['cost_usd'] > $full['steps']['featured_image']['cost_usd'] * 2, 'High quality is priced well above medium (measured: $0.061 against $0.021).' );
msrwa_test_assert( $split['steps']['facebook_image']['cost_usd'] < $full['steps']['facebook_image']['cost_usd'], 'Low is priced below medium.' );
$routed = MSRWA_Estimate::recipe( MSRWA_Profile::FULL, array( 'routing' => array( 'facebook_image' => 'openai:gpt-image-2' ) ) );
msrwa_test_assert( 'openai:gpt-image-2' === ( $routed['steps']['facebook_image']['model'] ?? '' ), 'The collage can be drawn by its own model.' );
msrwa_test_assert( 'openai:gpt-image-2.5-flare' === $routed['steps']['featured_image']['model'], 'While the featured image keeps the shared one.' );
msrwa_test_assert( 'facebook_image' === MSRWA_Engine_Config::create( array( 'routing' => array( 'facebook_image' => 'openai:gpt-image-2' ) ) )->image_route( 'facebook_image' ), 'A step with its own route uses it.' );
msrwa_test_assert( 'image' === MSRWA_Engine_Config::create()->image_route( 'featured_image' ), 'Otherwise the shared image route.' );

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
	abs( $lot['cost_usd'] - ( $lot['per_recipe_pictured_usd'] * 4 + $lot['matching_usd'] ) ) < 0.000001,
	'A lot is its recipes — each with a photograph, so researched from it — plus its pairing; got ' . $lot['cost_usd']
);

// A model with no published rate is unknown, never free — the distinction the
// whole ledger is built on.
$unpriced = MSRWA_Estimate::recipe( MSRWA_Profile::ARTICLE, array( 'routing' => array( 'article' => 'openai:pas-un-modele' ) ) );
msrwa_test_assert( in_array( 'article', $unpriced['unpriced'], true ), 'An unpriced route is reported as unknown.' );
msrwa_test_assert( ! isset( $unpriced['steps']['article'] ), 'And it is not silently counted as costing nothing.' );

msrwa_test_done( 'estimates track what runs really cost' );
