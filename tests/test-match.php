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

// --- Photographs without any text ----------------------------------------
// Each dish the photographs show becomes a recipe; two shots of one dish are
// one recipe, and a dish no photograph was given is not one.

$proposal = new ReflectionMethod( MSRWA_Match::class, 'proposal' );
$proposal->setAccessible( true );
$shots = array(
	array( 'file' => 'tarte-1.jpg', 'dish' => 'tarte aux pommes', 'describes' => 'Une tarte dorée aux pommes en lamelles.' ),
	array( 'file' => 'tarte-2.jpg', 'dish' => 'tarte normande', 'describes' => 'Une part de tarte aux pommes.' ),
	array( 'file' => 'flou.jpg', 'dish' => '', 'describes' => 'Une surface brune floue.' ),
	array( 'file' => 'yassa.jpg', 'dish' => 'poulet yassa', 'describes' => 'Du poulet aux oignons confits.' ),
);
$proposed = $proposal->invoke( null, array(
	'recipes' => array( array( 'title' => '<b>Tarte aux pommes</b>' ), array( 'title' => 'Soupe inventée' ), array( 'title' => 'Poulet yassa' ) ),
	'pairs' => array(
		array( 'image' => 0, 'recipe' => 0, 'confidence' => 'haute' ),
		array( 'image' => 1, 'recipe' => 0, 'confidence' => 'haute' ),
		array( 'image' => 2, 'recipe' => null ),
		array( 'image' => 3, 'recipe' => 2, 'confidence' => 'haute' ),
	),
	'reasoning' => 'Deux plats.',
), $shots );
msrwa_test_assert( 2 === count( $proposed['recipes'] ), 'Two dishes photographed are two recipes, and the one nobody photographed is dropped; got ' . count( $proposed['recipes'] ) );
msrwa_test_assert( 'Tarte aux pommes' === $proposed['recipes'][0]['title'], 'A proposed title is plain text: model output is data, never markup.' );
msrwa_test_assert( ! empty( $proposed['recipes'][0]['from_photographs'] ), 'A recipe named from photographs says so, for the lot screen.' );
msrwa_test_contains( $proposed['recipes'][0]['text'], 'Une part de tarte aux pommes.', 'Both shots of the tart describe the one recipe.' );
msrwa_test_contains( $proposed['recipes'][0]['text'], 'Aucun texte fourni', 'The brief says the recipe is to be established from sources.' );
$yassa = array_values( array_filter( $proposed['pairs'], static function ( $pair ) { return 3 === $pair['image']; } ) );
msrwa_test_assert( 1 === $yassa[0]['recipe'], 'Dropping the invented dish moves the yassa photograph to the yassa recipe; got ' . var_export( $yassa[0]['recipe'], true ) );
$paired = $normalise->invoke( null, $proposed['pairs'], count( $proposed['recipes'] ), $shots );
$blur = array_values( array_filter( $paired, static function ( $pair ) { return 2 === $pair['image']; } ) );
msrwa_test_assert( null === $blur[0]['recipe'], 'A photograph with no recognised dish belongs to no recipe.' );

$nothing = $proposal->invoke( null, array( 'recipes' => array( array( 'title' => 'Tarte' ) ), 'pairs' => array() ), $shots );
msrwa_test_assert( array() === $nothing['recipes'], 'A title no photograph was given yields no recipe at all.' );

// Text alone pays for no pairing call; photographs alone ask for recipes.
msrwa_test_contains( $source, "if ( ! \$seen['images'] ) {", 'Without photographs, nothing is paired or billed.' );
msrwa_test_contains( $source, '$decision = self::propose( $seen[\'images\'], $config );', 'Without text, the photographs propose the recipes.' );

// The dish names become titles when there is no text, and the reasons are
// read by the writer: both come in the lot's language, not always French.
$language = new ReflectionMethod( MSRWA_Match::class, 'language' );
$language->setAccessible( true );
msrwa_test_assert( 'espagnol' === $language->invoke( null, MSRWA_Engine_Config::create( array( 'settings' => array( 'site_language' => 'es' ) ) ) ), 'A Spanish lot is described in Spanish.' );
msrwa_test_assert( 'français' === $language->invoke( null, MSRWA_Engine_Config::create( array() ) ), 'A lot that says nothing is French, as before.' );
$instruction = new ReflectionMethod( MSRWA_Match::class, 'vision_instruction' );
$instruction->setAccessible( true );
msrwa_test_assert( 2 === substr_count( $instruction->invoke( null, 'anglais' ), 'in anglais' ), 'The dish and its description are asked for in the lot’s language.' );
// One look serves the pairing and the research: the engine's own observation
// instruction, and the engine is handed the reading so it does not look again.
msrwa_test_contains( $instruction->invoke( null, 'anglais' ), 'observable_details', 'The pairing asks for the engine’s observation in the same call.' );
msrwa_test_contains( $source, "1 === count( \$recipes )", 'A lot of one recipe is paired without a paid call.' );
$proposal_prompt = new ReflectionMethod( MSRWA_Match::class, 'proposal_prompt' );
$proposal_prompt->setAccessible( true );
msrwa_test_missing( $proposal_prompt->invoke( null, $shots, 'espagnol' ), 'en français', 'Nor are the titles or the reasons French on a Spanish site.' );

// --- A photograph of a dish the text does not name ---------------------
// The owner's rule: it is not thrown away. It becomes a recipe of its own, as
// in a lot with no text; photographs of one dish become one recipe; one with
// no dish on it waits for the writer.
$strays = new ReflectionMethod( MSRWA_Match::class, 'strays' );
$strays->setAccessible( true );
$shots = array(
	array( 'file' => 'yassa.jpg', 'dish' => 'Poulet yassa', 'describes' => 'Poulet aux oignons.' ),
	array( 'file' => 'tarte.jpg', 'dish' => 'Tarte aux pommes', 'describes' => 'Une tarte dorée.' ),
	array( 'file' => 'tarte-2.jpg', 'dish' => 'tarte normande', 'describes' => 'Une part de tarte.' ),
	array( 'file' => 'flou.jpg', 'dish' => '', 'describes' => 'Image floue.' ),
);
$asked = array();
MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( &$asked ) {
	$asked[] = $payload;
	$answer = array( 'recipes' => array( array( 'title' => 'Tarte aux pommes' ) ), 'pairs' => array( array( 'image' => 0, 'recipe' => 0 ), array( 'image' => 1, 'recipe' => 0 ) ), 'reasoning' => 'Deux vues d’une même tarte.' );
	return array( 'status' => 200, 'raw' => json_encode( array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => json_encode( $answer ) ) ) ) ), 'usage' => array( 'input_tokens' => 300, 'output_tokens' => 60 ) ) ) );
};
$decision = array( 'pairs' => array( array( 'image' => 0, 'recipe' => 0, 'confidence' => 'haute' ), array( 'image' => 1, 'recipe' => null ), array( 'image' => 2, 'recipe' => null ), array( 'image' => 3, 'recipe' => null ) ), 'reasoning' => '', 'cost_usd' => 0.001, 'seconds' => 1, 'errors' => array() );
$config = MSRWA_Engine_Config::create( array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ) ) );
$out = $strays->invoke( null, array( array( 'title' => 'Poulet yassa', 'text' => 'Poulet, oignons.' ) ), $shots, $decision, $config );
MSRWA_Engine_Call::$transport = null;
$by = array();
foreach ( $out['pairs'] as $pair ) { $by[ $pair['image'] ] = $pair; }
msrwa_test_assert( 2 === count( $out['recipes'] ) && 'Tarte aux pommes' === $out['recipes'][1]['title'] && ! empty( $out['recipes'][1]['from_photographs'] ), 'The tart the text did not name becomes a recipe of its own: ' . json_encode( array_column( $out['recipes'], 'title' ), JSON_UNESCAPED_UNICODE ) );
msrwa_test_assert( 1 === $by[1]['recipe'] && 1 === $by[2]['recipe'] && ! empty( $by[1]['new_recipe'] ), 'Both photographs of it go with it, marked as a new recipe.' );
msrwa_test_assert( 0 === $by[0]['recipe'], 'The yassa stays with the yassa.' );
msrwa_test_assert( null === $by[3]['recipe'] && ! empty( $by[3]['pending'] ), 'The photograph nobody can name waits for the writer.' );
msrwa_test_assert( 1 === count( $asked ) && false === strpos( json_encode( $asked[0], JSON_UNESCAPED_UNICODE ), 'flou.jpg' ), 'One grouping call, about the named strays only.' );
msrwa_test_assert( $out['cost_usd'] > 0.001, 'And its cost is counted with the pairing.' );

// No grouping answer: one recipe per dish name, so none is lost.
MSRWA_Engine_Call::$transport = static function () { return array( 'status' => 500, 'raw' => '' ); };
$out = $strays->invoke( null, array( array( 'title' => 'Poulet yassa', 'text' => 'x' ) ), $shots, $decision, $config );
MSRWA_Engine_Call::$transport = null;
msrwa_test_assert( 3 === count( $out['recipes'] ), 'Without the grouping, each dish name is a recipe: ' . json_encode( array_column( $out['recipes'], 'title' ), JSON_UNESCAPED_UNICODE ) );

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
