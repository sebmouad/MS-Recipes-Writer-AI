<?php
// The last gate before a reader. Its danger is not a crash but a judge that waves
// everything through, or one that blocks everything — both make the gate useless.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images', 'prompt', 'json' );
require_once dirname( __DIR__ ) . '/tools/lib/steps.php';

$sound = array(
	'approved' => false,
	'article' => array( 'verdict' => 'reservations', 'summary' => 'Les figures concordent.' ),
	'featured_image' => array( 'verdict' => 'good', 'realism' => 'good', 'summary' => 'Photographie crédible.' ),
	'facebook_image' => array( 'verdict' => 'bad', 'realism' => 'good', 'panels_counted' => 6, 'summary' => 'Garniture absente.' ),
	'consistency' => array( 'verdict' => 'bad', 'summary' => 'Le dernier panneau diffère.' ),
	'findings' => array(
		array( 'target' => 'facebook_image', 'severity' => 'blocking', 'quote' => '', 'reason' => 'Persil absent de la recette.', 'fix' => 'Retirer la garniture verte.' ),
		array( 'target' => 'article', 'severity' => 'minor', 'quote' => 'Les pommes de septembre sont les plus sucrées.', 'reason' => 'Non étayé.', 'fix' => 'Supprimer la phrase.' ),
	),
	'uncertainties' => array( 'Ingrédients non visibles non vérifiables.' ),
);
$checks = lab_score_approval( $sound, 2, 6 );
$failed = array_keys( array_filter( $checks, static function ( $check ) { return ! $check['pass']; } ) );
msrwa_test_assert( array() === $failed, 'A sound verdict must pass every check; failed: ' . implode( ', ', $failed ) );

// Approving while holding a blocking finding is the failure that matters most.
$contradictory = $sound;
$contradictory['approved'] = true;
msrwa_test_assert( false === lab_score_approval( $contradictory, 2, 6 )['approval matches findings']['pass'], 'Approving despite a blocking finding must fail.' );

// No blocker and approved is a legitimate pass.
$clean = $sound;
$clean['approved'] = true;
$clean['findings'] = array( array( 'target' => 'article', 'severity' => 'minor', 'quote' => 'Une phrase.', 'reason' => 'Style.', 'fix' => 'Reformuler.' ) );
msrwa_test_assert( true === lab_score_approval( $clean, 2, 6 )['approval matches findings']['pass'], 'Approving with only minor findings must pass.' );

// An article finding without its exact sentence cannot be applied to the text.
$unquoted = $sound;
$unquoted['findings'][1]['quote'] = '';
msrwa_test_assert( false === lab_score_approval( $unquoted, 2, 6 )['findings are actionable']['pass'], 'An article finding with no quote must fail.' );

$unfixable = $sound;
$unfixable['findings'][0]['fix'] = '';
msrwa_test_assert( false === lab_score_approval( $unfixable, 2, 6 )['findings are actionable']['pass'], 'A finding with no fix must fail.' );

// Realism is judged apart from fidelity: an image can be a real photograph of the
// wrong dish, and collapsing the two hides exactly that case.
$no_realism = $sound;
unset( $no_realism['featured_image']['realism'] );
msrwa_test_assert( false === lab_score_approval( $no_realism, 2, 6 )['realism judged separately']['pass'], 'Realism must be judged for every image.' );

// The judge must count the panels it saw rather than echo the number requested.
$uncounted = $sound;
unset( $uncounted['facebook_image']['panels_counted'] );
msrwa_test_assert( false === lab_score_approval( $uncounted, 2, 6 )['collage panels counted']['pass'], 'The collage panels must be counted.' );

// An unreadable answer must not collect passes for findings it never made.
foreach ( array( null, array(), 'refus' ) as $broken ) {
	$checks = lab_score_approval( $broken, 2, 6 );
	$passes = array_keys( array_filter( $checks, static function ( $check ) { return $check['pass']; } ) );
	msrwa_test_assert( array() === $passes, 'An unreadable verdict must fail every check; passed: ' . implode( ', ', $passes ) );
}

// The prompt must keep the three distinctions that were wrong when first measured.
$prompt = file_get_contents( dirname( __DIR__ ) . '/tools/prompts/final_approval.tpl.txt' );
msrwa_test_contains( $prompt, 'never about whether it shows the right dish', 'Realism and fidelity must stay separate checks.' );
msrwa_test_contains( $prompt, 'is never a finding', 'A supported accompaniment must not be treated as a stray ingredient.' );
msrwa_test_contains( $prompt, 'not a finding at all', 'Ordinary cooking knowledge must not be a finding.' );
msrwa_test_contains( $prompt, 'If you would not hold the publication back for it, it is minor', 'Severity must be defined, or everything becomes blocking.' );

msrwa_test_done( 'approval' );
