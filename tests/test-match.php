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

// Photographs alone ask for recipes.
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
msrwa_test_contains( $source, "self::all_of( array( 'title' => \$text ), \$seen['images'] )", 'A one-line brief naming the one dish photographed is paired without a paid call.' );
$proposal_prompt = new ReflectionMethod( MSRWA_Match::class, 'proposal_prompt' );
$proposal_prompt->setAccessible( true );
msrwa_test_missing( $proposal_prompt->invoke( null, $shots, 'espagnol' ), 'en français', 'Nor are the titles or the reasons French on a Spanish site.' );

// --- Reading the lot: the writer's text is a brief, read whole -----------
// Nothing cuts it by lines or separators. One call decides the recipes, the
// brief first: every dish it asks for is a recipe, photographed or not; a dish
// photographed that it does not name is one more; and what it asks of every
// recipe travels with each.
$read = new ReflectionMethod( MSRWA_Match::class, 'read' );
$read->setAccessible( true );
$asked = array();
$answer_with = static function ( array $answer ) use ( &$asked ) {
	MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( &$asked, $answer ) {
		$asked[] = $payload;
		return array( 'status' => 200, 'raw' => json_encode( array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => json_encode( $answer, JSON_UNESCAPED_UNICODE ) ) ) ) ), 'usage' => array( 'input_tokens' => 900, 'output_tokens' => 120 ) ) ) );
	};
};
$config = MSRWA_Engine_Config::create( array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ) ) );

// Six dishes asked for, four photographs of four of them.
$six = "Pour la semaine, sans gluten : tarte normande, poulet yassa, daube provençale, clafoutis aux cerises, soupe à l’oignon et ratatouille.";
$four = array(
	array( 'file' => 'a.jpg', 'dish' => 'Tarte aux pommes', 'describes' => 'Une tarte aux pommes dorée.' ),
	array( 'file' => 'b.jpg', 'dish' => 'Yassa au poulet', 'describes' => 'Poulet aux oignons confits.' ),
	array( 'file' => 'c.jpg', 'dish' => 'Clafoutis', 'describes' => 'Un clafoutis aux cerises.' ),
	array( 'file' => 'd.jpg', 'dish' => 'Ratatouille', 'describes' => 'Légumes du soleil mijotés.' ),
);
$answer_with( array(
	'recipes' => array_map( static function ( $t ) { return array( 'title' => $t, 'from' => 'text', 'brief' => mb_strtolower( $t ) ); }, array( 'Tarte normande', 'Poulet yassa', 'Daube provençale', 'Clafoutis aux cerises', 'Soupe à l’oignon', 'Ratatouille' ) ),
	'general' => 'sans gluten',
	'pairs' => array(
		array( 'image' => 0, 'recipe' => 0, 'confidence' => 'haute', 'why' => 'Tarte de pommes.' ),
		array( 'image' => 1, 'recipe' => 1, 'confidence' => 'haute', 'why' => 'Yassa.' ),
		array( 'image' => 2, 'recipe' => 3, 'confidence' => 'haute', 'why' => 'Clafoutis.' ),
		array( 'image' => 3, 'recipe' => 5, 'confidence' => 'haute', 'why' => 'Ratatouille.' ),
	),
	'reasoning' => 'Six recettes, quatre photographiées.',
) );
$out = $read->invoke( null, $six, $four, $config, MSRWA_Intake::recipes( $six ) );
msrwa_test_assert( 6 === count( $out['recipes'] ), 'Six dishes asked for are six recipes, whatever the photographs: got ' . count( $out['recipes'] ) );
msrwa_test_assert( array( 0, 1, 3, 5 ) === array_column( $out['pairs'], 'recipe' ), 'Each photograph goes with its dish; two recipes stay without one.' );
msrwa_test_contains( $out['recipes'][2]['text'], 'sans gluten', 'What the brief asks of every recipe travels with each, photographed or not.' );
msrwa_test_assert( 1 === count( $asked ), 'One call reads the brief and pairs the photographs; got ' . count( $asked ) );
$prompt = json_encode( $asked[0], JSON_UNESCAPED_UNICODE );
msrwa_test_contains( $prompt, 'clafoutis aux cerises, soupe à l’oignon', 'The brief is handed over whole, as written.' );
msrwa_test_contains( $prompt, 'LA CONSIGNE PRIME', 'And the reading is told it comes first.' );
$collage_prompt = new ReflectionMethod( MSRWA_Match::class, 'read_prompt' );
$collage_prompt->setAccessible( true );
msrwa_test_contains( $collage_prompt->invoke( null, 'Tarte', array( array( 'file' => 'grille.jpg', 'dish' => 'Tarte', 'describes' => 'Six étapes.', 'collage' => true ) ) ), '(collage Facebook)', 'The reading is told which photograph is the writer’s collage.' );
$vision = new ReflectionMethod( MSRWA_Match::class, 'vision_instruction' );
$vision->setAccessible( true );
msrwa_test_contains( $vision->invoke( null, 'français', 'Observe.' ), 'a recipe card that sets pictures of the ingredients', 'An ingredient card with the finished dish is a collage too, not only a grid of steps.' );
msrwa_test_contains( $vision->invoke( null, 'français', 'Observe.' ), 'false for a single photograph of a dish, even with a caption', 'A captioned photograph is not a collage.' );

// The owner's rule, 2026-09-28: photographs are one recipe only when they show
// the very same preparation. Two versions of a dish — with or without almonds,
// with chicken or goat's cheese — are two recipes, whatever their name.
$versions = $collage_prompt->invoke( null, 'Tarte aux pommes', array(
	array( 'file' => 'a.jpg', 'dish' => 'Tarte aux pommes', 'describes' => 'Une tarte.', 'observation' => array( 'observable_details' => array( 'lamelles de pommes', 'amandes effilées' ) ) ),
	array( 'file' => 'b.jpg', 'dish' => 'Tarte aux pommes', 'describes' => 'Une tarte.', 'observation' => array( 'observable_details' => 'lamelles de pommes nappées' ) ),
) );
msrwa_test_contains( $versions, 'exactement la même préparation', 'The reading is told to group only the very same preparation.' );
msrwa_test_contains( $versions, 'amandes effilées', 'And is given each photograph’s details to tell versions apart.' );
msrwa_test_contains( $proposal_prompt->invoke( null, $shots, 'français' ), 'exactement la même préparation', 'The same rule holds without text.' );
$closest = new ReflectionMethod( MSRWA_Match::class, 'closest' );
$closest->setAccessible( true );
msrwa_test_assert( 0 === $closest->invoke( null, 'Yassa au poulet', array( array( 'title' => 'Poulet yassa' ) ) ), 'The same dish in other words is the brief’s recipe.' );
msrwa_test_assert( null === $closest->invoke( null, 'Tarte aux pommes et amandes', array( array( 'title' => 'Tarte aux pommes' ) ) ), 'Another version of it is not merged into it.' );
msrwa_test_contains( $source, "1 === count( \$seen['images'] ) && false === strpos( \$text, \"\\n\" )", 'Two photographs are always read, never grouped on a name alone.' );
msrwa_test_assert( $out['cost_usd'] > 0, 'What the reading cost is counted.' );

// A recipe the reading names from photographs that is plainly a dish of the
// brief is that dish; two shots of a dish the brief does not name make one
// recipe; one the reading made up with no photograph is dropped.
$asked = array();
$answer_with( array(
	'recipes' => array(
		array( 'title' => 'Poulet yassa', 'from' => 'text', 'brief' => "Poulet yassa\nMariner le poulet au citron." ),
		array( 'title' => '<b>Tarte normande</b>', 'from' => 'text', 'brief' => 'Tarte normande : pâte, pommes, crème.' ),
		array( 'title' => 'Yassa au poulet', 'from' => 'photos', 'brief' => '' ),
		array( 'title' => 'Crème brûlée', 'from' => 'photos', 'brief' => '' ),
		array( 'title' => 'Soupe inventée', 'from' => 'photos', 'brief' => '' ),
	),
	'pairs' => array(
		array( 'image' => 0, 'recipe' => 2, 'confidence' => 'basse' ),
		array( 'image' => 1, 'recipe' => 3, 'confidence' => 'haute' ),
		array( 'image' => 2, 'recipe' => 3, 'confidence' => 'haute' ),
		array( 'image' => 3, 'recipe' => null ),
	),
) );
$text = "Poulet yassa\nMariner le poulet au citron.\nTarte normande : pâte, pommes, crème.";
$shots = array(
	array( 'file' => 'y.jpg', 'dish' => 'Yassa au poulet', 'describes' => 'Poulet aux oignons.' ),
	array( 'file' => 'c1.jpg', 'dish' => 'Crème brûlée', 'describes' => 'Une crème caramélisée.' ),
	array( 'file' => 'c2.jpg', 'dish' => 'Crème brûlée', 'describes' => 'Une autre vue.' ),
	array( 'file' => 'flou.jpg', 'dish' => '', 'describes' => 'Image floue.' ),
);
$out = $read->invoke( null, $text, $shots, $config, MSRWA_Intake::recipes( $text ) );
$paired = $normalise->invoke( null, $out['pairs'], count( $out['recipes'] ), $shots );
$by = array();
foreach ( $paired as $pair ) { $by[ $pair['image'] ] = $pair; }
msrwa_test_assert( array( 'Poulet yassa', 'Tarte normande', 'Crème brûlée' ) === array_column( $out['recipes'], 'title' ), 'Two recipes of the brief and one new dish, no more: ' . json_encode( array_column( $out['recipes'], 'title' ), JSON_UNESCAPED_UNICODE ) );
msrwa_test_assert( 0 === $by[0]['recipe'] && empty( $by[0]['new_recipe'] ), 'The yassa the reading named from its photograph goes with the yassa of the brief.' );
msrwa_test_assert( 2 === $by[1]['recipe'] && 2 === $by[2]['recipe'] && ! empty( $by[1]['new_recipe'] ), 'Two photographs of a dish the brief does not name make one recipe of their own.' );
msrwa_test_assert( ! empty( $out['recipes'][2]['from_photographs'] ), 'That recipe is written from the photographs and says so.' );
msrwa_test_assert( null === $by[3]['recipe'] && ! empty( $by[3]['pending'] ), 'A photograph with no dish waits for the writer.' );
$ruled = $normalise->invoke( null, array( array( 'image' => 0, 'recipe' => null, 'reason' => 'excluded' ) ), 1, $shots );
msrwa_test_assert( ! empty( $ruled[0]['set_aside'] ) && empty( $ruled[0]['pending'] ), 'A photograph the brief itself rules out is set aside, not left waiting.' );
msrwa_test_assert( "Poulet yassa\nMariner le poulet au citron." === $out['recipes'][0]['text'], 'A recipe carries the writer’s own words about it.' );

// An answer that cannot be read: the plain first cut, and every photograph
// waits for the writer.
MSRWA_Engine_Call::$transport = static function () { return array( 'status' => 500, 'raw' => '' ); };
$text = "Tarte\npâte, pommes\n\nDaube\nbœuf, vin";
$out = $read->invoke( null, $text, $shots, $config, MSRWA_Intake::recipes( $text ) );
$paired = $normalise->invoke( null, $out['pairs'], count( $out['recipes'] ), $shots );
msrwa_test_assert( 2 === count( $out['recipes'] ) && ! empty( $out['errors'] ), 'Unreadable: the plain cut, and the error said.' );
msrwa_test_assert( 4 === count( array_filter( $paired, static function ( $pair ) { return ! empty( $pair['pending'] ); } ) ), 'And every photograph waits for the writer.' );

// Text alone is read as a brief too: only the reading can tell a list of
// dishes from one recipe's steps, or from a word for the whole lot.
$asked = array();
$answer_with( array( 'recipes' => array( array( 'title' => 'Tarte', 'from' => 'text', 'brief' => "Tarte\nPâte, pommes, crème." ) ), 'general' => '' ) );
$two = MSRWA_Match::run( array(), array(), $config, '', "Tarte\nPâte, pommes, crème." );
msrwa_test_assert( 1 === count( $asked ) && 1 === count( $two['recipes'] ) && 'Tarte' === $two['recipes'][0]['title'], 'A title and its ingredients are one recipe.' );
msrwa_test_assert( false === strpos( json_encode( $asked[0], JSON_UNESCAPED_UNICODE ), 'PHOTOGRAPHIES' ), 'Without photographs, nothing is paired.' );
MSRWA_Engine_Call::$transport = null;

// One recipe skips the pairing call only when every photograph plainly shows
// it; a photograph of another dish must reach the pairing, where it becomes a
// recipe of its own instead of illustrating the wrong one.
$all_of = new ReflectionMethod( MSRWA_Match::class, 'all_of' );
$all_of->setAccessible( true );
msrwa_test_assert( true === $all_of->invoke( null, array( 'title' => 'Poulet yassa' ), array( array( 'dish' => 'Yassa au poulet' ), array( 'dish' => '' ) ) ), 'The same dish in other words needs no call; an unrecognised photograph does not decide.' );
msrwa_test_assert( false === $all_of->invoke( null, array( 'title' => 'Tajine de poulet' ), array( array( 'dish' => 'Poulet yassa' ) ) ), 'Sharing an ingredient is not being the dish.' );
msrwa_test_assert( false === $all_of->invoke( null, array( 'title' => 'Poulet yassa' ), array( array( 'dish' => 'Poulet yassa' ), array( 'dish' => 'Tarte aux pommes' ) ) ), 'One photograph of something else is enough to ask.' );

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
