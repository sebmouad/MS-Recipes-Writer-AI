<?php
// The deterministic quality checks the engine scores an article with. They are
// the engine's, not the plugin's: MSRWA_Engine_Score calls them.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'quality' );

// The minimum word count is a blocker, not a matter of score: an article one
// word short of it fails however well it does elsewhere. The heading counts.
$limits = array( 'quality_min_words' => 2000, 'quality_max_words' => 2400, 'quality_min_score' => 1 );
foreach ( array( 1999 => false, 2000 => true ) as $words => $long_enough ) {
	$article = array( 'content_html' => '<h2>Titre</h2><p>' . implode( ' ', array_fill( 0, $words - 1, 'recette' ) ) . '</p>' );
	$verdict = MSRWA_Quality::evaluate( $article, array(), $limits );
	msrwa_test_assert( $long_enough === ! in_array( 'content_html', $verdict['blockers'], true ), 'Length boundary: ' . $words . ' words ' . ( $long_enough ? 'pass' : 'block' ) . ' — ' . json_encode( $verdict['blockers'] ) );
}

msrwa_test_done( 'MSRWA quality contracts' );
