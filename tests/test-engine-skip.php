<?php
// The rewrite is skipped only when every check before it found nothing to act
// on, and a step that never ran is never read as a step that found nothing.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

$skip = new ReflectionMethod( MSRWA_Engine::class, 'nothing_to_do' );
$skip->setAccessible( true );

$config = MSRWA_Engine_Config::create();
msrwa_test_assert( true === $config->get( 'skip_when_clean.proofread' ), 'The engine ships with the clean-article skip on.' );

$clean = new MSRWA_Result();
$clean->artifact( 'review', array( 'pass' => true, 'findings' => array() ) );
$clean->artifact( 'corrected', array( 'content_html' => '<p>x</p>', 'corrections_for_the_editor' => array() ) );
msrwa_test_assert( '' !== $skip->invoke( null, 'proofread', $config, $clean ), 'A review that passed with no finding leaves nothing to rewrite.' );

// Anything anyone objected to brings the rewrite back.
$found = new MSRWA_Result();
$found->artifact( 'review', array( 'pass' => true, 'findings' => array( array( 'severity' => 'minor', 'section' => 'Conservation' ) ) ) );
$found->artifact( 'corrected', array( 'content_html' => '<p>x</p>', 'corrections_for_the_editor' => array() ) );
msrwa_test_assert( '' === $skip->invoke( null, 'proofread', $config, $found ), 'A finding, however minor, must still be rewritten for.' );

$failed = new MSRWA_Result();
$failed->artifact( 'review', array( 'pass' => false, 'findings' => array() ) );
$failed->artifact( 'corrected', array( 'content_html' => '<p>x</p>', 'corrections_for_the_editor' => array() ) );
msrwa_test_assert( '' === $skip->invoke( null, 'proofread', $config, $failed ), 'A review that did not pass is never treated as clean.' );

$unapplied = new MSRWA_Result();
$unapplied->artifact( 'review', array( 'pass' => true, 'findings' => array() ) );
$unapplied->artifact( 'corrected', array( 'content_html' => '<p>x</p>', 'corrections_for_the_editor' => array( array( 'before' => 'a', 'after' => 'b' ) ) ) );
msrwa_test_assert( '' === $skip->invoke( null, 'proofread', $config, $unapplied ), 'A correction that could not be applied is work the rewrite must do.' );

// A step that never ran is not a step that found nothing: a review missing
// because it failed must never be read as a clean bill of health.
$absent = new MSRWA_Result();
$absent->artifact( 'corrected', array( 'content_html' => '<p>x</p>', 'corrections_for_the_editor' => array() ) );
msrwa_test_assert( '' === $skip->invoke( null, 'proofread', $config, $absent ), 'A missing review must never be read as a passing one.' );

// And the caller keeps the last word, as it does over everything else.
$always = MSRWA_Engine_Config::create( array( 'skip_when_clean' => array( 'proofread' => false ) ) );
msrwa_test_assert( '' === $skip->invoke( null, 'proofread', $always, $clean ), 'A caller that wants the rewrite every time must get it.' );

// The skipped step still hands the approval an article to judge, at no cost.
$skipped = new ReflectionMethod( MSRWA_Engine::class, 'skipped' );
$skipped->setAccessible( true );
$outcome = $skipped->invoke( null, 'proofread', $clean, 'nothing to rewrite' );
msrwa_test_assert( 0.0 === $outcome['cost_usd'], 'A step that did not run costs nothing.' );
msrwa_test_assert( '<p>x</p>' === $outcome['artifact']['content_html'], 'The corrected article is what travels on to the approval.' );
msrwa_test_assert( '' === $outcome['error'], 'Skipping is not a failure.' );

msrwa_test_done( 'engine clean-article skip OK' );
