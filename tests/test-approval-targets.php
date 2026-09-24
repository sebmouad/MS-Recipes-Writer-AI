<?php
// The judge is asked about what it was shown, and nothing else.
//
// Before this, it demanded both images by name and failed the step when either
// was missing — so a run that deliberately produced one image could never be
// judged at all, and was marked down for the absence of something nobody had
// ordered.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

$verdict = array(
	'approved' => true,
	'article' => array( 'verdict' => 'good', 'summary' => 'x' ),
	'featured_image' => array( 'verdict' => 'good', 'realism' => 'good', 'summary' => 'x' ),
	'findings' => array(),
	'uncertainties' => array(),
);

// One image: the collage is neither judged nor missed, and consistency — which
// needs two things to compare — is not asked for.
$one = MSRWA_Engine_Score::approval( $verdict, 1, 6, array( 'article', 'featured_image' ) );
msrwa_test_assert( ! empty( $one['a verdict per artifact']['pass'] ), 'A verdict for each artifact shown is enough; got ' . $one['a verdict per artifact']['detail'] );
msrwa_test_assert( ! isset( $one['collage panels counted'] ), 'A collage that was never made has no panels to count.' );
msrwa_test_assert( ! empty( $one['realism judged separately']['pass'] ), 'Realism is asked only of the image that exists.' );

// No image at all: the text is still judged, and no image check is invented.
$none = MSRWA_Engine_Score::approval( array_diff_key( $verdict, array( 'featured_image' => 1 ) ), 0, 6, array( 'article' ) );
msrwa_test_assert( ! empty( $none['a verdict per artifact']['pass'] ), 'An article alone can be judged.' );
msrwa_test_assert( ! isset( $none['realism judged separately'] ), 'Realism is not asked when nothing was drawn.' );
msrwa_test_assert( ! isset( $none['collage panels counted'] ), 'Panels are not counted when there is no collage.' );

// Both images: every check that existed before still exists.
$both = $verdict;
$both['facebook_image'] = array( 'verdict' => 'good', 'realism' => 'good', 'panels_counted' => 6, 'summary' => 'x' );
$both['consistency'] = array( 'verdict' => 'good', 'summary' => 'x' );
$full = MSRWA_Engine_Score::approval( $both, 2, 6, array( 'article', 'featured_image', 'facebook_image', 'consistency' ) );
msrwa_test_assert( isset( $full['collage panels counted'] ) && ! empty( $full['collage panels counted']['pass'] ), 'With a collage, its panels are still counted.' );
msrwa_test_assert( ! empty( $full['realism judged separately']['pass'] ), 'With both images, both are still judged for realism.' );
msrwa_test_assert( ! empty( $full['a verdict per artifact']['pass'] ), 'The full set still passes as it did.' );

// A missing verdict for an artifact that WAS shown is still a failure. The
// change must not have turned the contract into a suggestion.
$silent = MSRWA_Engine_Score::approval( array_diff_key( $both, array( 'facebook_image' => 1 ) ), 2, 6, array( 'article', 'featured_image', 'facebook_image' ) );
msrwa_test_assert( empty( $silent['a verdict per artifact']['pass'] ), 'An image that was shown and not judged is still a failure.' );
msrwa_test_assert( empty( $silent['collage panels counted']['pass'] ), 'A collage shown and not counted is still a failure.' );

// And the prompt must carry the placeholder the step fills in, or the judge is
// told nothing about what it holds.
$template = file_get_contents( dirname( __DIR__ ) . '/includes/engine/prompts/final_approval.tpl.txt' );
msrwa_test_contains( $template, 'IMAGES RECEIVED', 'The judge’s prompt points at the manifest.' );
msrwa_test_missing( $template, '{{images_received}}', 'Not through a placeholder: the prompt is compiled before the images exist, and it was emptied every time.' );
$built = MSRWA_Engine_Input::build( 'final_approval', 'PROMPT', array( 'title' => 'Tarte', 'images_received' => '- the Facebook image, 1024x1536, a 6-panel preparation collage;' ) );
msrwa_test_contains( $built, "IMAGES RECEIVED:\n- the Facebook image", 'The manifest reaches the judge with the data.' );
msrwa_test_contains( $template, 'WHAT YOU WERE NOT SENT', 'And it is told not to judge what it was not sent.' );

msrwa_test_done( 'the judge is asked only about what it saw' );
