<?php
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'quality', 'publisher', 'presentation' );
$missing = MSRWA_Quality::normalize_review( array( 'pass' => true ) );
msrwa_test_assert( ! $missing['pass'] && 'unknown' === $missing['verdict'], 'A bare pass boolean cannot invent an AI verdict.' );
$image = MSRWA_Quality::normalize_review( array( 'pass' => true, 'verdict' => 'good', 'realism' => 'bad' ), true );
msrwa_test_assert( ! $image['pass'] && 'bad' === $image['realism'], 'Unrealistic images must not pass.' );
$artifacts = array(
	'article' => array( 'content_html' => '<p>Recette utilisable.</p>' ),
	'review' => array( 'pass' => true, 'verdict' => 'good' ),
	'structure_checks' => array( 'pass' => false, 'score' => 5 ),
	'length_findings' => array( array( 'field' => 'article_length', 'reason' => '1200 mots pour 2000 minimum.' ) ),
	'output_options' => array( 'generate_featured_image' => 0, 'generate_facebook_image' => 0 ),
);
$report = MSRWA_Publisher::editorial_report( $artifacts );
msrwa_test_assert( 'good' === $report['article_quality'] && 'needs_review' === $report['status'], 'Length requirements flag delivery while preserving the independent AI verdict.' );
msrwa_test_assert( ! empty( $report['findings'] ) && 'not_generated' === $report['featured_quality'], 'Length warnings and disabled outputs remain visible.' );
ob_start(); MSRWA_Presentation::render_verdict( $report['article_quality'] ); $html = ob_get_clean();
msrwa_test_contains( $html, 'Bon', 'Verdict labels are visible.' );
msrwa_test_missing( $html, '%', 'Quality does not expose a percentage.' );
$limits = array( 'quality_min_words' => 2000, 'quality_max_words' => 2400 );
foreach ( array( 1999 => false, 2000 => true, 2400 => true, 2401 => false ) as $words => $valid ) {
	$article = array( 'content_html' => '<p>' . implode( ' ', array_fill( 0, $words, 'recette' ) ) . '</p>' );
	msrwa_test_assert( empty( MSRWA_Quality::length_findings( $article, $limits ) ) === $valid, 'Length boundary: ' . $words );
}
msrwa_test_done( 'MSRWA independent AI quality' );
