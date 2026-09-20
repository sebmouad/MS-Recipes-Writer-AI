<?php
// Cost contracts: every amount is tokens times a catalogue rate, each bucket
// carries a minimum and a maximum, and an unpriced model is reported as
// unknown rather than as free.
require __DIR__ . '/bootstrap.php';

class MSRWA_Catalog {
	public static $models = array();
	public static function models( $all = false ) { return self::$models; }
}
class MSRWA_Router {
	public static $route = array( 'provider' => 'openai', 'model' => 'text-1' );
	public static function plan( $capability = 'text', $stage = '' ) {
		if ( 'image_generation' === $capability ) { return array( 'provider' => 'openai', 'model' => 'image-1' ); }
		return self::$route;
	}
}
class MSRWA_Images {
	public static function native_size( $ratio, $fallback ) {
		$sizes = array( '1:1' => '1024x1024', '3:2' => '1536x1024', '2:3' => '1024x1536', '4:5' => '1024x1536' );
		return $sizes[ $ratio ] ?? $fallback;
	}
}
MSRWA_Catalog::$models = array( 'openai' => array(
	'text-1'  => array( 'text' => true, 'input' => 1.0, 'output' => 10.0 ),
	'image-1' => array( 'image_generation' => true, 'input' => 5.0, 'output' => 30.0, 'image_tokens' => array( '1024x1024' => array( 'medium' => 1000 ), '1024x1536' => array( 'medium' => 1500 ) ) ),
) );
msrwa_test_load( 'cost' );

msrwa_test_settings( array(
	'max_corrections' => 2, 'quality_min_words' => 2800, 'quality_max_words' => 3200,
	'max_reference_images' => 3, 'image_quality' => 'medium', 'featured_ratio' => '1:1', 'facebook_ratio' => '4:5',
	'prompt_article' => str_repeat( 'a', 4000 ), 'web_search_tool_cost_usd' => 0.01, 'web_search_max_tool_calls' => 1,
) );
$settings = MSRWA_Settings::get();
$estimate = MSRWA_Cost::estimate( $settings );

// Four buckets, each with a minimum no greater than its maximum.
msrwa_test_assert( array( 'article', 'featured', 'facebook', 'other' ) === array_keys( $estimate['buckets'] ), 'The estimate must report the four agreed buckets.' );
foreach ( $estimate['buckets'] as $bucket => $amounts ) {
	msrwa_test_assert( $amounts['min'] > 0, 'Bucket ' . $bucket . ' must carry a price.' );
	msrwa_test_assert( $amounts['max'] >= $amounts['min'], 'Bucket ' . $bucket . ' maximum must not be below its minimum.' );
}
msrwa_test_assert( abs( $estimate['total']['min'] - array_sum( array_column( $estimate['buckets'], 'min' ) ) ) < 0.000001, 'The total must be the sum of the buckets.' );
msrwa_test_assert( array() === $estimate['unknown'], 'No step may be unpriced with a full catalogue.' );

// A longer article costs more, and only in its own bucket.
msrwa_test_settings( array( 'max_corrections' => 2, 'quality_min_words' => 4000, 'quality_max_words' => 4400, 'max_reference_images' => 3, 'image_quality' => 'medium', 'featured_ratio' => '1:1', 'facebook_ratio' => '4:5', 'prompt_article' => str_repeat( 'a', 4000 ), 'web_search_tool_cost_usd' => 0.01, 'web_search_max_tool_calls' => 1 ) );
$longer = MSRWA_Cost::estimate( MSRWA_Settings::get() );
msrwa_test_assert( $longer['buckets']['article']['min'] > $estimate['buckets']['article']['min'], 'More words must cost more in the article bucket.' );
msrwa_test_assert( $longer['buckets']['featured'] === $estimate['buckets']['featured'], 'Word count must not move an image bucket.' );

// Corrections raise only the maximum.
msrwa_test_settings( array( 'max_corrections' => 0, 'quality_min_words' => 2800, 'quality_max_words' => 3200, 'max_reference_images' => 3, 'image_quality' => 'medium', 'featured_ratio' => '1:1', 'facebook_ratio' => '4:5', 'prompt_article' => str_repeat( 'a', 4000 ), 'web_search_tool_cost_usd' => 0.01, 'web_search_max_tool_calls' => 1 ) );
$no_corrections = MSRWA_Cost::estimate( MSRWA_Settings::get() );
msrwa_test_assert( abs( $no_corrections['buckets']['article']['min'] - $estimate['buckets']['article']['min'] ) < 0.000001, 'Corrections must not change the minimum.' );
msrwa_test_assert( $no_corrections['buckets']['article']['max'] < $estimate['buckets']['article']['max'], 'Corrections must raise the maximum.' );

// Image price follows the size the ratio produces.
$image_model = MSRWA_Catalog::models()['openai']['image-1'];
$square = MSRWA_Cost::image_price( $image_model, '1024x1024', 'medium', 0 );
$portrait = MSRWA_Cost::image_price( $image_model, '1024x1536', 'medium', 0 );
msrwa_test_assert( abs( $square - 1000 * 30.0 / 1000000 ) < 0.000001, 'A square image must be priced from its token count.' );
msrwa_test_assert( $portrait > $square, 'A taller image must cost more than a square one.' );
msrwa_test_assert( null === MSRWA_Cost::image_tokens( $image_model, '2048x2048', 'medium' ), 'An unknown size must be unknown, never zero.' );
msrwa_test_assert( null === MSRWA_Cost::image_price( array( 'output' => 30.0 ), '1024x1024', 'medium' ), 'A model without a token table cannot be priced.' );

// Text price is exactly tokens times rate.
msrwa_test_assert( abs( MSRWA_Cost::text_price( array( 'input' => 2.0, 'output' => 8.0 ), 1000, 500 ) - ( 1000 * 2.0 + 500 * 8.0 ) / 1000000 ) < 0.000000001, 'Text price must be tokens times the catalogue rate.' );
msrwa_test_assert( null === MSRWA_Cost::text_price( array(), 1000, 500 ), 'A model with no rate must not be priced.' );

// A step the pipeline does not perform yet must not be charged for.
$steps = MSRWA_Cost::steps( MSRWA_Settings::get() );
msrwa_test_assert( empty( $steps['proofreading']['enabled'] ), 'Proofreading is not built yet and must stay out of the estimate.' );
msrwa_test_assert( ! isset( $estimate['steps']['proofreading'] ), 'A disabled step must not appear in the estimate.' );

// An empty catalogue reports every step as unknown instead of inventing zero.
MSRWA_Catalog::$models = array();
$blind = MSRWA_Cost::estimate( MSRWA_Settings::get() );
msrwa_test_assert( ! empty( $blind['unknown'] ), 'Without prices every step must be reported as unknown.' );
msrwa_test_assert( 0.0 === $blind['total']['min'] && 0.0 === $blind['total']['max'], 'Unknown steps must not be counted as priced work.' );

msrwa_test_done( 'MSRWA cost contracts' );
