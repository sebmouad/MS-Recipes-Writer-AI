<?php
// The pairing decides what every article is illustrated with, so what comes
// back from the model is checked before it can reach a brief. A wrong pairing
// is not a cosmetic problem: it puts another dish's photograph on the article.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-intake.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-match.php';

$normalise = new ReflectionMethod( MSRWA_Match::class, 'normalise' );
$normalise->setAccessible( true );
$images = array( array( 'file' => 'a.jpg', 'dish' => 'tarte aux pommes' ), array( 'file' => 'b.jpg', 'dish' => 'poulet yassa' ), array( 'file' => 'c.jpg', 'dish' => 'daube' ) );
$clean = function ( $pairs, $recipes = 2 ) use ( $normalise, $images ) { return $normalise->invoke( null, $pairs, $recipes, $images ); };

// A photograph on which no dish could be recognised is never paired by the
// model, however sure it claims to be: "in doubt, do not pair" is a promise
// the screen makes, so the code keeps it.
$blank = $normalise->invoke( null, array( array( 'image' => 0, 'recipe' => 0, 'confidence' => 'haute', 'why' => 'aucun plat visible' ) ), 1, array( array( 'file' => 'brun.jpg', 'dish' => '' ) ) );
msrwa_test_assert( null === $blank[0]['recipe'] && 'basse' === $blank[0]['confidence'], 'A photograph with no recognised dish waits for the writer.' );
// A photograph that was never described is not "unrecognised": the rule once
// read the raw uploads, found no dish on any of them, and unpaired them all.
$raw = $normalise->invoke( null, array( array( 'image' => 0, 'recipe' => 0, 'confidence' => 'haute', 'why' => 'tarte' ) ), 1, array( array( 'file' => 'tarte.jpg' ) ) );
msrwa_test_assert( 0 === $raw[0]['recipe'], 'The rule applies to described photographs only.' );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-match.php' );
msrwa_test_contains( $source, "self::normalise( \$decision['pairs'], count( \$recipes ), \$seen['images'] )", 'The pairing is checked against the described photographs.' );

// Every photograph comes back, whether or not the answer mentioned it.
$all = $clean( array( array( 'image' => 0, 'recipe' => 1, 'confidence' => 'haute', 'why' => 'ok' ) ) );
msrwa_test_assert( 3 === count( $all ), 'Every photograph is accounted for; got ' . count( $all ) );
$unmentioned = array_values( array_filter( $all, static function ( $pair ) { return 1 === $pair['image']; } ) );
msrwa_test_assert( null === $unmentioned[0]['recipe'], 'A photograph the answer skipped is unassigned, not lost.' );

// An index pointing at nothing is dropped rather than carried into a brief.
$out_of_range = $clean( array( array( 'image' => 9, 'recipe' => 0 ), array( 'image' => 0, 'recipe' => 7 ) ) );
$first = array_values( array_filter( $out_of_range, static function ( $pair ) { return 0 === $pair['image']; } ) );
msrwa_test_assert( null === $first[0]['recipe'], 'A recipe index out of range becomes no recipe, never recipe 7.' );
msrwa_test_assert( 3 === count( $out_of_range ), 'An image index out of range adds nothing; got ' . count( $out_of_range ) );

// One photograph cannot illustrate two recipes: the first claim wins.
$twice = $clean( array( array( 'image' => 2, 'recipe' => 0 ), array( 'image' => 2, 'recipe' => 1 ) ) );
$claimed = array_values( array_filter( $twice, static function ( $pair ) { return 2 === $pair['image']; } ) );
msrwa_test_assert( 1 === count( $claimed ), 'A photograph is claimed once; got ' . count( $claimed ) );
msrwa_test_assert( 0 === $claimed[0]['recipe'], 'The first claim is the one kept.' );

// An invented confidence is not repeated back as if the model had said it.
$odd = $clean( array( array( 'image' => 0, 'recipe' => 0, 'confidence' => 'absolue' ) ) );
msrwa_test_assert( 'basse' === $odd[0]['confidence'], 'An unknown confidence reads as low, never as stated.' );

// Rubbish in the list is skipped without taking the rest of it down.
$rubbish = $clean( array( 'pas un tableau', null, array( 'image' => 1, 'recipe' => 0 ) ) );
msrwa_test_assert( 3 === count( $rubbish ), 'A malformed entry is skipped, not fatal; got ' . count( $rubbish ) );

// --- The brief handed to the engine -------------------------------------

$brief = MSRWA_Match::brief(
	array( 'title' => 'Tarte aux pommes', 'text' => 'pâte, pommes, crème' ),
	array( array( 'id' => 12, 'url' => 'https://example.test/a.jpg', 'title' => 'Tarte' ) )
);
msrwa_test_assert( 'Tarte aux pommes' === $brief['title'] && 'recipe' === $brief['type'], 'The brief carries the writer’s own title.' );
msrwa_test_assert( 1 === count( $brief['images'] ) && 12 === $brief['images'][0]['id'], 'The paired photographs travel with it.' );
msrwa_test_contains( $brief['text'], 'pommes', 'The writer’s own text is the instruction.' );

// The engine must accept it: a brief it cannot read is a run that never starts.
$normalised = new ReflectionMethod( MSRWA_Engine::class, 'normalise_brief' );
$normalised->setAccessible( true );
$engine_brief = $normalised->invoke( null, $brief );
msrwa_test_assert( 'Tarte aux pommes' === $engine_brief['title'], 'The engine reads the title the plugin wrote.' );

msrwa_test_done( 'matching OK' );
