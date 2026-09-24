<?php
// The two images are priced and judged independently, so the administrator sets
// each one's quality. Measured on gpt-image-2.5-flare at 1024x1024, 2026-09-20:
// low $0.0104, medium $0.0177, high $0.0572 — the spread the setting controls.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images' );

msrwa_test_assert( 'medium' === MSRWA_Images::quality( array(), 'featured' ), 'The featured image defaults to medium.' );
msrwa_test_assert( 'medium' === MSRWA_Images::quality( array(), 'facebook' ), 'The Facebook image defaults to medium.' );

$split = array( 'featured_image_quality' => 'high', 'facebook_image_quality' => 'low' );
msrwa_test_assert( 'high' === MSRWA_Images::quality( $split, 'featured' ), 'The featured quality must be settable on its own.' );
msrwa_test_assert( 'low' === MSRWA_Images::quality( $split, 'facebook' ), 'The Facebook quality must be settable on its own.' );

// A site configured before the split keeps the value it chose.
$legacy = array( 'image_quality' => 'high' );
msrwa_test_assert( 'high' === MSRWA_Images::quality( $legacy, 'featured' ), 'An existing single setting must still apply to the featured image.' );
msrwa_test_assert( 'high' === MSRWA_Images::quality( $legacy, 'facebook' ), 'An existing single setting must still apply to the Facebook image.' );

// The specific setting wins over the legacy one.
$both = array( 'image_quality' => 'high', 'facebook_image_quality' => 'low' );
msrwa_test_assert( 'low' === MSRWA_Images::quality( $both, 'facebook' ), 'The per-image setting must override the legacy one.' );
msrwa_test_assert( 'high' === MSRWA_Images::quality( $both, 'featured' ), 'The legacy setting still covers what is not set.' );

// A value the image API would reject must never reach it.
foreach ( array( 'ultra', '', 'HIGH', 'auto ' ) as $bad ) {
	msrwa_test_assert( in_array( MSRWA_Images::quality( array( 'featured_image_quality' => $bad ), 'featured' ), MSRWA_Images::qualities(), true ), 'An invalid quality must fall back to a value the API accepts.' );
}

msrwa_test_done( 'image quality' );
