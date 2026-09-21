<?php
// The deterministic quality checks the engine scores an article with. They are
// the engine's, not the plugin's: MSRWA_Engine_Score calls them.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'quality' );

$missing = MSRWA_Quality::normalize_review( array( 'pass' => true ) );
msrwa_test_assert( ! $missing['pass'] && 'unknown' === $missing['verdict'], 'A bare pass boolean cannot invent an AI verdict.' );

$image = MSRWA_Quality::normalize_review( array( 'pass' => true, 'verdict' => 'good', 'realism' => 'bad' ), true );
msrwa_test_assert( ! $image['pass'] && 'bad' === $image['realism'], 'Unrealistic images must not pass.' );

$limits = array( 'quality_min_words' => 2000, 'quality_max_words' => 2400 );
foreach ( array( 1999 => false, 2000 => true, 2400 => true, 2401 => false ) as $words => $valid ) {
	$article = array( 'content_html' => '<p>' . implode( ' ', array_fill( 0, $words, 'recette' ) ) . '</p>' );
	msrwa_test_assert( empty( MSRWA_Quality::length_findings( $article, $limits ) ) === $valid, 'Length boundary: ' . $words );
}

msrwa_test_done( 'MSRWA quality contracts' );
