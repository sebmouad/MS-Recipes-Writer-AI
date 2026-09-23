<?php
// The catalogue against the providers themselves: what they list, trimmed to
// what a recipe can use, and the price lookup reading their own pages. The
// listing is free; the lookup is a few cents of one cheap model reading a page.
require __DIR__ . '/lib.php';

$listing = msrwa_real_request( 'POST', '/msrwa/v1/catalog/models', null, 120 );
msrwa_real_assert( 200 === $listing['status'], 'Fetching the models must answer an administrator (got ' . $listing['status'] . ').' );
$listed = 0;
foreach ( (array) $listing['body'] as $provider => $verdict ) {
	$kept = (int) ( $verdict['models'] ?? 0 );
	msrwa_real_note( $provider . ': ' . ( $verdict['state'] ?? '?' ) . ', ' . $kept . ' model(s) kept' );
	if ( ! $kept ) { continue; }
	$listed++;
	// Gemini lists some sixty names, of which about a dozen are chat models
	// the engine can call; the rest are speech, music, video and embeddings.
	msrwa_real_assert( $kept <= 20, $provider . ' keeps only the models a recipe can use, not its whole listing (kept ' . $kept . ').' );
}
if ( ! $listed ) { msrwa_real_skip( 'no provider key lists models on this site' ); }

msrwa_real_budget();
// Asked about models whose rate shipped, so the answer can be compared with
// a figure read off the same page by hand. One per provider with a key.
$ask = array( 'gemini:gemini-3.5-flash', 'gemini:gemini-3.7-flash', 'claude:claude-sonnet-5', 'openai:gpt-5.6-luna' );
$lookup = msrwa_real_request( 'POST', '/msrwa/v1/catalog/prices', array( 'models' => $ask ), 900 );
msrwa_real_assert( 200 === $lookup['status'], 'The price lookup must answer an administrator (got ' . $lookup['status'] . ').' );
$body = (array) $lookup['body'];
msrwa_real_note( 'asked ' . (int) ( $body['asked'] ?? 0 ) . ', found ' . (int) ( $body['found'] ?? 0 ) . ( empty( $body['error'] ) ? '' : ' — ' . $body['error'] ) );
// A cheap model reading one page per provider; a generous ceiling all the same.
msrwa_real_spend( 0.05 );

msrwa_real_assert( count( $ask ) === (int) ( $body['asked'] ?? 0 ), 'Every model named is asked about, priced or not.' );
msrwa_real_assert( empty( $body['error'] ), 'At least one route must answer: ' . ( $body['error'] ?? '' ) );
msrwa_real_assert( (int) ( $body['found'] ?? 0 ) > 0, 'At least one rate must come back from a provider’s own page.' );
$shipped = array( 'gemini:gemini-3.5-flash' => array( 1.50, 9.00 ), 'gemini:gemini-3.7-flash' => array( 0.75, 3.75 ), 'claude:claude-sonnet-5' => array( 2.00, 10.00 ), 'openai:gpt-5.6-luna' => array( 0.20, 1.20 ) );
$pages = array( 'openai' => 'openai.com', 'gemini' => 'google.dev', 'claude' => 'claude.com' );
foreach ( (array) ( $body['results'] ?? array() ) as $key => $result ) {
	if ( 'found' !== ( $result['state'] ?? '' ) ) { msrwa_real_note( $key . ': ' . ( $result['why'] ?? '' ) ); continue; }
	$provider = strtok( $key, ':' );
	msrwa_real_note( $key . ': $' . $result['input'] . ' / $' . $result['output'] . ' — ' . $result['source'] );
	msrwa_real_assert( false !== strpos( (string) wp_parse_url_compat( $result['source'] ), $pages[ $provider ] ?? '#' ), $key . ' must cite its provider’s own page, not ' . $result['source'] . '.' );
	msrwa_real_assert( $result['input'] > 0 && $result['output'] >= $result['input'], $key . ' must read as a rate: output costs at least what input does.' );
	if ( isset( $shipped[ $key ] ) && array( (float) $result['input'], (float) $result['output'] ) !== $shipped[ $key ] ) {
		msrwa_real_note( $key . ' differs from the shipped $' . $shipped[ $key ][0] . ' / $' . $shipped[ $key ][1] . ' — one of the two is out of date' );
	}
}

// What the lookup stored is what the estimate now prices with.
$estimate = msrwa_real_request( 'GET', '/msrwa/v1/estimate?profile=full&recipes=1&images=0' );
msrwa_real_assert( 200 === $estimate['status'], 'The estimate must still answer after the catalogue changed.' );
msrwa_real_assert( empty( $estimate['body']['unpriced'] ), 'Every configured route must still be priced; unpriced: ' . implode( ', ', (array) ( $estimate['body']['unpriced'] ?? array() ) ) );

// The Moteur simulation resolves what is on screen over the catalogue, and
// prices it. A model that ships priced but switched off, named by a route,
// must still read as priced: an unpriced route stops the run.
$simulation = msrwa_real_request( 'POST', '/msrwa/v1/diagnostics/config', array( 'config' => array( 'routing' => wp_json_encode_compat( array( 'article' => 'gemini:gemini-3.7-flash', 'research' => 'gemini:medium' ) ) ) ) );
msrwa_real_assert( 200 === $simulation['status'], 'The simulation must answer (got ' . $simulation['status'] . ').' );
$routes = (array) ( $simulation['body']['routes'] ?? array() );
msrwa_real_assert( 'gemini-3.7-flash' === ( $routes['article']['route']['model'] ?? '' ), 'The simulation resolves the route typed on screen, not the saved one.' );
msrwa_real_assert( ! empty( $routes['article']['price_known'] ) && (float) ( $routes['article']['cost_usd'] ?? 0 ) > 0, 'A shipped rate reaches the simulation even for a model not switched on.' );
msrwa_real_assert( 0 === strpos( (string) ( $routes['featured_image']['route']['model'] ?? '' ), 'gpt-image' ), 'An image step is simulated on the image route.' );
msrwa_real_assert( (float) ( $simulation['body']['cost_usd'] ?? 0 ) > 0 && empty( $simulation['body']['unpriced'] ), 'The simulation totals a full recipe with nothing unpriced.' );
msrwa_real_note( 'simulated full recipe: $' . number_format( (float) ( $simulation['body']['cost_usd'] ?? 0 ), 4 ) );
msrwa_real_assert( 'low' === ( $routes['article']['thinking'] ?? '' ), 'A Gemini route thinks at the level its provider ships (got "' . ( $routes['article']['thinking'] ?? '' ) . '").' );

// A thinking level typed on screen reaches every step and the money.
$harder = msrwa_real_request( 'POST', '/msrwa/v1/diagnostics/config', array( 'config' => array(
	'routing' => wp_json_encode_compat( array( 'article' => 'gemini:gemini-3.7-flash', 'research' => 'gemini:medium' ) ),
	'thinking' => wp_json_encode_compat( array( 'default' => 'high' ) ),
) ) );
msrwa_real_assert( 'high' === ( $harder['body']['routes']['article']['thinking'] ?? '' ), 'The level on screen is the level simulated.' );
msrwa_real_assert( (float) ( $harder['body']['cost_usd'] ?? 0 ) > (float) ( $simulation['body']['cost_usd'] ?? 0 ), 'Thinking harder is simulated as costing more.' );
msrwa_real_note( 'the same recipe at high thinking: $' . number_format( (float) ( $harder['body']['cost_usd'] ?? 0 ), 4 ) );

// Each image has its own model and quality, and the simulation prices each.
$images = msrwa_real_request( 'POST', '/msrwa/v1/diagnostics/config', array( 'config' => array(
	'routing' => wp_json_encode_compat( array( 'featured_image' => 'openai:gpt-image-2.5-flare', 'facebook_image' => 'openai:gpt-image-2' ) ),
	'images' => wp_json_encode_compat( array( 'featured_quality' => 'high', 'facebook_quality' => 'low' ) ),
) ) );
$by_step = (array) ( $images['body']['routes'] ?? array() );
msrwa_real_assert( 'gpt-image-2.5-flare' === ( $by_step['featured_image']['route']['model'] ?? '' ) && 'gpt-image-2' === ( $by_step['facebook_image']['route']['model'] ?? '' ), 'Each image is simulated on its own model.' );
msrwa_real_assert( (float) ( $by_step['featured_image']['cost_usd'] ?? 0 ) > (float) ( $by_step['facebook_image']['cost_usd'] ?? 0 ), 'A high-quality featured image is priced above a low-quality collage.' );
msrwa_real_note( 'featured at high $' . number_format( (float) ( $by_step['featured_image']['cost_usd'] ?? 0 ), 4 ) . ', collage at low $' . number_format( (float) ( $by_step['facebook_image']['cost_usd'] ?? 0 ), 4 ) );

msrwa_real_done( 'the catalogue keeps what it can use and prices it from the providers’ pages' );

function wp_parse_url_compat( $url ) { return parse_url( (string) $url, PHP_URL_HOST ); }
