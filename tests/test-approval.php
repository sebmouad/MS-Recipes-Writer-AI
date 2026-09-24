<?php
// The last gate before a reader. Its danger is not a crash but a judge that waves
// everything through, or one that blocks everything — both make the gate useless.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images', 'prompt', 'json' );
require_once dirname( __DIR__ ) . '/tools/lib/steps.php';

$sound = array(
	'approved' => false,
	'featured_image' => array( 'verdict' => 'good', 'realism' => 'good', 'summary' => 'Photographie crédible.' ),
	'facebook_image' => array( 'verdict' => 'bad', 'realism' => 'good', 'panels_counted' => 6, 'summary' => 'Garniture absente.' ),
	'consistency' => array( 'verdict' => 'bad', 'summary' => 'Le dernier panneau diffère.' ),
	'findings' => array(
		array( 'target' => 'facebook_image', 'severity' => 'blocking', 'quote' => '', 'reason' => 'Persil absent de la recette.', 'fix' => 'Retirer la garniture verte.' ),
		array( 'target' => 'featured_image', 'severity' => 'minor', 'reason' => 'Cadrage large.', 'fix' => 'Resserrer.' ),
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
$clean['findings'] = array( array( 'target' => 'facebook_image', 'severity' => 'minor', 'reason' => 'Lumière.', 'fix' => 'Adoucir.' ) );
msrwa_test_assert( true === lab_score_approval( $clean, 2, 6 )['approval matches findings']['pass'], 'Approving with only minor findings must pass.' );

// The text is the review's: a finding filed against the article is a finding
// against something the judge was never shown.
$text = $sound;
$text['findings'][] = array( 'target' => 'article', 'severity' => 'minor', 'reason' => 'Style.', 'fix' => 'Reformuler.' );
msrwa_test_assert( false === lab_score_approval( $text, 2, 6 )['findings are addressed']['pass'], 'The judge may not file a finding against the article.' );

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
$prompt = file_get_contents( dirname( __DIR__ ) . '/includes/engine/prompts/final_approval.tpl.txt' );
// The owner's direction, 2026-09-21: realism is the gate, principal ingredients
// only, and small details are not worth refusing over. Four earlier calibrations
// each over-reached — a potted plant read as an ingredient, rice with a yassa
// blocked, seventeen blocking findings on one tarte — so these are asserted.
// A panel of baking beans on paper passed, as did an eight-cell grid: the
// collage's blocking rules now name equipment and empty cells.
msrwa_test_contains( $prompt, 'equipment rather than food', 'A panel showing equipment the recipe removes must block.' );
msrwa_test_contains( $prompt, 'a blank or empty cell', 'An empty cell must block.' );
msrwa_test_contains( $prompt, 'breaks a blocking rule of check 4', 'Image severity must defer to the collage rules.' );
msrwa_test_contains( $prompt, 'the primary check', 'Realism must be named the primary check.' );
// The owner's rule, 2026-09-21, and it is asymmetric: an image may omit a
// secondary ingredient, because it can be dissolved, buried or out of frame. It
// may not add one, because a reader cooking the recipe cannot produce that plate.
msrwa_test_contains( $prompt, 'Only the PRINCIPAL ingredients must be visible', 'Only principal ingredients may be reported missing.' );
msrwa_test_contains( $prompt, 'Never report an ingredient as missing unless it is principal', 'A missing secondary ingredient must not be a finding.' );
msrwa_test_contains( $prompt, 'is BLOCKING, however small', 'An ingredient that is not in the recipe must block.' );
msrwa_test_contains( $prompt, 'Do not count either', 'Counting objects in a photograph must not be a defect.' );
msrwa_test_contains( $prompt, 'nothing inedible is an ingredient', 'Styling props must not be read as ingredients.' );
msrwa_test_contains( $prompt, 'SEVERITY:', 'Severity must be defined, or everything becomes blocking.' );
msrwa_test_contains( $prompt, 'doubt resolves to minor', 'For images, doubt resolves towards shipping.' );
// The article is held to its sources by the review, before the judge; the
// judge is sent neither, and must not be asked about them (owner, 2026-09-24).
msrwa_test_contains( $prompt, 'which you take as given', 'The judge must take the canonical recipe as the reference.' );
msrwa_test_missing( $prompt, 'THE TEXT IS JUDGED STRICTLY', 'The judge no longer judges the text.' );
msrwa_test_missing( $prompt, '"article":', 'Nor answers about it.' );
msrwa_test_contains( $prompt, 'an editor decides whether it is redrawn', 'A refusal goes to the editor, not to an automatic redraw.' );

// A refusal decides what gets regenerated. Getting this wrong wastes an image
// generation per attempt, which is the expensive half of the retry loop.
$blocked_collage = array( 'approved' => false, 'findings' => array(
	array( 'target' => 'facebook_image', 'severity' => 'blocking', 'reason' => 'Ordre des panneaux.', 'fix' => 'Suivre la recette.' ),
	array( 'target' => 'article', 'severity' => 'blocking', 'reason' => 'Durée contradictoire.', 'fix' => 'Corriger la durée.' ),
	array( 'target' => 'featured_image', 'severity' => 'minor', 'reason' => 'Cadrage.', 'fix' => 'Resserrer.' ),
) );
msrwa_test_assert( array( 'facebook' ) === lab_images_to_retry( $blocked_collage ), 'Only the image with a blocking finding is regenerated.' );

// An article finding is not an image problem: regenerating a photograph cannot fix a sentence.
$article_only = array( 'approved' => false, 'findings' => array(
	array( 'target' => 'article', 'severity' => 'blocking', 'reason' => 'Quantité fausse.', 'fix' => 'Corriger.' ),
) );
msrwa_test_assert( array() === lab_images_to_retry( $article_only ), 'An article-only refusal must not regenerate an image.' );

// A consistency break names no single image; measurement put every one on the collage.
$inconsistent = array( 'approved' => false, 'findings' => array(
	array( 'target' => 'consistency', 'severity' => 'blocking', 'reason' => 'Vaisselle différente.', 'fix' => 'Même assiette.' ),
) );
msrwa_test_assert( array( 'facebook' ) === lab_images_to_retry( $inconsistent ), 'A consistency break is charged to the collage.' );

// It must not be charged twice when the collage is already being regenerated.
$both = array( 'approved' => false, 'findings' => array(
	array( 'target' => 'facebook_image', 'severity' => 'blocking', 'reason' => 'Ordre.', 'fix' => 'Réordonner.' ),
	array( 'target' => 'consistency', 'severity' => 'blocking', 'reason' => 'Vaisselle.', 'fix' => 'Même assiette.' ),
) );
msrwa_test_assert( array( 'facebook' ) === lab_images_to_retry( $both ), 'The collage must be regenerated once, not twice.' );

$featured_too = array( 'approved' => false, 'findings' => array(
	array( 'target' => 'featured_image', 'severity' => 'blocking', 'reason' => 'Ingrédient absent.', 'fix' => 'Retirer.' ),
	array( 'target' => 'consistency', 'severity' => 'blocking', 'reason' => 'Vaisselle.', 'fix' => 'Même assiette.' ),
) );
msrwa_test_assert( array( 'featured', 'facebook' ) === lab_images_to_retry( $featured_too ), 'Both images may be regenerated in one attempt.' );

// An approval ends the loop even when minor findings remain.
$approved = array( 'approved' => true, 'findings' => array(
	array( 'target' => 'facebook_image', 'severity' => 'minor', 'reason' => 'Lumière.', 'fix' => 'Adoucir.' ),
) );
msrwa_test_assert( array() === lab_images_to_retry( $approved ), 'An approval must stop the loop.' );
msrwa_test_assert( array() === lab_images_to_retry( null ), 'An unreadable verdict must not trigger a blind regeneration.' );

// A retry is a correction, not another roll of the dice: the findings must reach
// the prompt, or the model repeats the same mistake at the same price.
$brief = lab_brief( 'tarte-pommes' );
$plain = lab_image_prompt( 'facebook', $brief, array() );
msrwa_test_missing( $plain, 'THIS IMAGE WAS REFUSED', 'A first attempt carries no correction block.' );
$corrected = lab_image_prompt( 'facebook', $brief, array(), array( array( 'reason' => 'Les panneaux sont dans le désordre.', 'fix' => 'Suivre l’ordre canonique.' ) ) );
msrwa_test_contains( $corrected, 'THIS IMAGE WAS REFUSED', 'A retry must say the previous attempt was refused.' );
msrwa_test_contains( $corrected, 'Les panneaux sont dans le désordre.', 'The reason must reach the prompt.' );
msrwa_test_contains( $corrected, 'Suivre l’ordre canonique.', 'The fix must reach the prompt.' );

// A recipe with exactly as many steps as panels has nothing to select, and
// leaving the model to select anyway is what reordered the panels: it hoisted
// the batter to panel two, ahead of lining the case, in three runs of five.
msrwa_test_contains( $plain, 'nothing to select', 'A six-step recipe must pin its panels rather than invite a selection.' );
msrwa_test_contains( $plain, 'Foncer le moule', 'The canonical steps must reach the prompt in order.' );
$position = array();
foreach ( array( 'Foncer le moule', 'Éplucher les pommes', 'Disposer les pommes', 'Battre les œufs' ) as $step ) {
	$position[ $step ] = strpos( $plain, $step );
	msrwa_test_assert( false !== $position[ $step ], 'Step "' . $step . '" must appear in the prompt.' );
}
msrwa_test_assert( $position['Foncer le moule'] < $position['Battre les œufs'], 'Lining the case must be asked for before beating the custard.' );

// A collage that ends on a whole dish is refused whatever the judge concluded,
// and the refusal redraws the collage with a fix it can follow.
$whole = $sound;
$whole['approved'] = true;
$whole['findings'] = array();
$whole['facebook_image']['last_panel_opened'] = false;
$enforced = MSRWA_Engine_Score::enforce( $whole );
msrwa_test_assert( false === $enforced['approved'], 'A whole last panel must refuse the collage.' );
msrwa_test_assert( array( 'facebook' ) === MSRWA_Engine_Score::images_to_retry( $enforced ), 'A whole last panel must redraw the collage and nothing else.' );
msrwa_test_assert( true === lab_score_approval( $enforced, 2, 6 )['approval matches findings']['pass'], 'The enforced refusal must be coherent with its findings.' );
$opened = $whole;
$opened['facebook_image']['last_panel_opened'] = true;
msrwa_test_assert( true === MSRWA_Engine_Score::enforce( $opened )['approved'], 'An opened last panel changes nothing.' );
unset( $opened['facebook_image']['last_panel_opened'] );
msrwa_test_assert( $opened === MSRWA_Engine_Score::enforce( $opened ), 'A verdict that does not answer is left as it is.' );
$said = $whole;
$said['findings'] = array( array( 'target' => 'facebook_image', 'severity' => 'blocking', 'quote' => '', 'reason' => 'Quiche entière.', 'fix' => 'Couper une part.' ) );
msrwa_test_assert( 1 === count( MSRWA_Engine_Score::enforce( $said )['findings'] ), 'The judge’s own finding is not repeated.' );
msrwa_test_contains( MSRWA_Engine_Score::enforce( $whole, 'fr' )['findings'][0]['reason'], 'dernier panneau', 'The finding is in the site’s language.' );
msrwa_test_contains( MSRWA_Engine_Score::enforce( $whole, 'en' )['findings'][0]['reason'], 'last panel', 'The finding is in the site’s language.' );

msrwa_test_done( 'approval' );
