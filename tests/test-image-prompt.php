<?php
// Every assertion here is a refused image that was paid for. An image call is
// billed mostly on what it is sent, so a prompt that is both wrong and long
// costs twice: once for the generation, once for the regeneration it forces.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/tools/lib/steps.php';
lab_settings();

// The vision pass once reported another site's watermark and its yellow border
// as facts about the dish. Both travelled into the image prompt and were drawn.
$dirty = array(
	'title' => 'Tarte aux pommes',
	'canonical' => array(
		'title' => 'Tarte aux pommes', 'servings' => 8, 'cook_minutes' => 45,
		'ingredients' => array(
			array( 'quantity' => '1', 'unit' => 'pâte', 'name' => 'pâte sablée' ),
			array( 'quantity' => '6', 'unit' => '', 'name' => 'pommes à cuire' ),
		),
		'steps' => array( array( 'text' => 'Préchauffer le four.' ), array( 'text' => 'Garnir et cuire.' ) ),
		'equipment' => array( 'moule à tarte' ),
	),
	'research' => array( 'visual_observations' => array( array(
		'observable_details' => 'Une part de tarte dorée est posée au centre d’une assiette. Un texte noir lisible apparaît en bas à droite : « La Cuisine de Biscottine ». L’ensemble est photographié sur une surface en bois.',
		'composition' => 'Vue légèrement plongeante. L’image est encadrée par une fine bordure jaune.',
		'colours' => 'Doré et brun clair.',
		'textures' => 'Surface irrégulière, bords plus fermes.',
	) ) ),
);

$prompt = MSRWA_Engine_Input::image_prompt( 'featured', $dirty, array() );
msrwa_test_missing( $prompt, 'Biscottine', 'A watermark read off a source photograph must never reach an image prompt.' );
msrwa_test_missing( $prompt, 'bordure jaune', 'A border drawn on a source photograph is a fact about the file, not the dish.' );

// Colour and texture describe the food. observable_details and composition
// inventory the frame, and every measured image defect came through them: the
// watermark, the border, two hands holding the plate, red and green strips
// drawn as peppers, orange pieces drawn as carrots. Five defects, one channel.
msrwa_test_contains( $prompt, 'Doré et brun clair', 'Colour must reach the image prompt: it is what the dish actually looks like.' );
msrwa_test_contains( $prompt, 'Surface irrégulière', 'Texture must reach the image prompt.' );
msrwa_test_missing( $prompt, 'surface en bois', 'observable_details inventories the frame and must not reach an image prompt.' );
msrwa_test_missing( $prompt, 'plongeante', 'composition describes the framing and must not reach an image prompt.' );

// The sentence-level filter still protects whatever does reach a prompt.
msrwa_test_assert( '' === MSRWA_Engine_Input::about_the_dish( 'Un texte noir apparaît en bas à droite.' ), 'A sentence about the picture must be dropped whole.' );
msrwa_test_assert( 'La tarte est dorée.' === MSRWA_Engine_Input::about_the_dish( 'La tarte est dorée. Le plat est tenu à deux mains.' ), 'Only the offending sentence goes; got ' . MSRWA_Engine_Input::about_the_dish( 'La tarte est dorée. Le plat est tenu à deux mains.' ) );

// The observations come from other cooks. Where they disagree with the recipe,
// the recipe wins — that is what stopped peppers and a lemon slice appearing.
msrwa_test_contains( $prompt, 'ingredient list wins', 'The prompt must say which side wins when an observation and the recipe disagree.' );

// The six rules a real generation broke, restated last, where a model weighs most.
foreach ( array( 'BEFORE YOU DRAW', 'No hands', 'watermark', 'Serve it exactly as the brief above says' ) as $rule ) {
	msrwa_test_contains( $prompt, $rule, 'The closing rules must carry: ' . $rule );
}
msrwa_test_assert( strlen( $prompt ) - mb_strpos( $prompt, 'BEFORE YOU DRAW' ) < 1400, 'The closing rules must stay near the end to be weighed as closing rules.' );

// A unit that repeats the ingredient reads as two ingredients, which is exactly
// what the exact-ingredient list exists to prevent.
msrwa_test_contains( $prompt, '1 pâte sablée', 'A unit that repeats the name must be dropped, not printed twice.' );
msrwa_test_missing( $prompt, 'pâte pâte', 'The ingredient list must not stutter.' );

// The visual brief already distils the observations. Sending the raw package as
// well repeated the same sentences and was 38% of the featured prompt.
msrwa_test_missing( $prompt, 'What the real photographs showed', 'The raw observation package must not follow the brief that already distils it.' );
msrwa_test_missing( $prompt, 'dish_identity', 'An image model has no use for source URLs and evidence prose.' );

// A clean observation must survive untouched: the filter is for picture
// furniture, not for anything that mentions a word in passing.
$clean = $dirty;
$clean['research']['visual_observations'][0]['observable_details'] = 'Une tarte dorée aux pommes, bords fermes, sur une assiette blanche.';
$clean['research']['visual_observations'][0]['composition'] = 'Vue de trois quarts à hauteur de table.';
$clean['research']['visual_observations'][0]['colours'] = 'Doré soutenu, crème et brun clair.';
$kept = MSRWA_Engine_Input::image_prompt( 'featured', $clean, array() );
msrwa_test_contains( $kept, 'Doré soutenu', 'A clean colour note must reach the prompt intact.' );
// The vessel is still read from every field — it lifts one word out rather than
// quoting text back at the image model, so it carries no garnish with it.
msrwa_test_contains( $kept, 'plain ceramic plate', 'The serving vessel must still be read from the observations.' );
msrwa_test_missing( $kept, 'assiette blanche', 'But the sentence it was read from must not be quoted into the prompt.' );

// A caller may change which fields are trusted, like everything else.
MSRWA_Engine_Input::use_observation_fields( array( 'observable_details' ) );
msrwa_test_contains( MSRWA_Engine_Input::image_prompt( 'featured', $clean, array() ), 'assiette blanche', 'A caller may widen the fields an image prompt is built from.' );
MSRWA_Engine_Input::use_observation_fields( array( 'colours', 'textures' ) );

// The collage carries the panel count and the recipe's own order in its closing rules.
$collage = MSRWA_Engine_Input::image_prompt( 'facebook', $clean, array( 'collage_panels' => 6 ) );
msrwa_test_contains( $collage, 'Exactly 6 panels', 'The collage must restate its panel count last.' );
msrwa_test_contains( $collage, "recipe's own order", 'The collage must restate that the recipe fixes the order.' );

// A blind bake was drawn as a case heaped with beads on paper, and a seven-step
// quiche came back as four rows of two. Both are said last, the count very last.
msrwa_test_contains( $collage, 'no baking beans', 'A case baked blind must be drawn after its bake, without its weights.' );
msrwa_test_assert( strpos( $collage, 'no baking beans' ) < strpos( $collage, 'Exactly 6 panels' ), 'The panel count stays the last rule.' );
$steps_of = static function ( $count ) use ( $clean ) {
	$recipe = $clean;
	$recipe['canonical']['steps'] = array();
	for ( $i = 1; $i <= $count; $i++ ) { $recipe['canonical']['steps'][] = array( 'text' => 'Étape ' . $i . '.' ); }
	return MSRWA_Engine_Input::image_prompt( 'facebook', $recipe, array( 'collage_panels' => 6 ) );
};
msrwa_test_contains( $steps_of( 7 ), 'choose 5 moments', 'More steps than panels: the arithmetic is said, the dish taking the last panel.' );
$short = $steps_of( 3 );
msrwa_test_contains( $short, 'only 3 steps for 6 panels', 'Fewer steps than panels is said too.' );
msrwa_test_contains( $short, 'Never invent a step', 'The extra panels are filled with visible states, never with invented steps.' );
msrwa_test_contains( $steps_of( 6 ), 'nothing to select', 'As many steps as panels: one per panel.' );

// A Facebook template names its prompt and may set its own grid. The grid is
// said from the panel count: a site at four panels was told 2 × 3.
msrwa_test_contains( MSRWA_Engine_Input::grid( 4, 2 ), '2 columns × 2 rows', 'Four panels are two rows of two.' );
msrwa_test_contains( MSRWA_Engine_Input::grid( 5, 2 ), 'last row holding 1 panel', 'An odd count says how the last row is filled.' );
$templated = MSRWA_Engine_Input::image_prompt( 'facebook', $clean, array( 'collage_panels' => 4, 'collage_columns' => 2, 'facebook_prompt' => 'featured_image.tpl.txt' ) );
msrwa_test_contains( $templated, 'Exactly 4 panels', 'The template’s panel count is the one restated.' );
msrwa_test_missing( $templated, 'THE SIX MOMENTS', 'The template’s prompt file is the one drawn from, not the shipped collage’s.' );
$config = MSRWA_Engine_Config::create( array( 'images' => array( 'facebook_template' => 'nowhere' ) ) );
msrwa_test_assert( 'collage' === $config->facebook_template()['key'] && 6 === $config->facebook_template()['panels'], 'An unknown template falls back to the shipped collage, six panels.' );
$config = MSRWA_Engine_Config::create( array( 'images' => array( 'facebook_template' => 'hero', 'facebook_templates' => array( 'hero' => array( 'prompt' => 'featured_image.tpl.txt', 'panels' => 1, 'size' => '1024x1024', 'ratio' => '1:1' ) ) ) ) );
$hero = $config->facebook_template();
msrwa_test_assert( 'hero' === $hero['key'] && 1 === $hero['panels'] && '1024x1024' === $hero['size'], 'A template sets its own panels and size: ' . json_encode( $hero ) );

// Both images of one dish must be told the same serving presentation, or they
// disagree — which was three refusals in four before it was written into both.
$featured_serving = MSRWA_Engine_Input::serving_presentation( $clean['canonical'], $clean['research'], true );
msrwa_test_assert( false !== strpos( $kept, $featured_serving ) && false !== strpos( $collage, $featured_serving ), 'The featured photograph and the collage must be given the same serving presentation.' );

// The judge and the generator are given opposite things, on purpose. The
// generator draws what it is shown, so it must not see another cook's garnish.
// The judge compares, so it must — with the tier and the doubt attached.
$evidence_research = array(
	'visual_references' => array(
		array( 'image_url' => 'https://example.org/1.jpg', 'tier' => 1 ),
		array( 'image_url' => 'https://example.org/2.jpg', 'tier' => 2 ),
	),
	'visual_observations' => array(
		array( 'image_url' => 'https://example.org/1.jpg', 'observable_details' => 'Une tarte dorée servie sur une assiette blanche, avec du riz à côté.', 'colours' => 'Doré et brun clair.', 'textures' => 'Croûte ferme.', 'uncertainties' => 'La nature des morceaux orange est indéterminable.' ),
		array( 'image_url' => 'https://example.org/2.jpg', 'observable_details' => 'Une variante plus foncée.', 'colours' => 'Brun soutenu.' ),
	),
);
$evidence = MSRWA_Engine_Input::visual_evidence( $evidence_research );
msrwa_test_contains( $evidence, '(this dish)', 'A first-tier photograph must be named as evidence of this dish.' );
msrwa_test_contains( $evidence, 'weaker evidence', 'A second-tier photograph must be marked as weaker.' );
msrwa_test_contains( $evidence, 'could not identify', 'What the vision pass could not identify must travel with the evidence.' );
msrwa_test_contains( $evidence, 'Never require an element because a photograph had it', 'The evidence must never become a specification.' );
msrwa_test_contains( $evidence, 'assiette blanche', 'The judge, unlike the generator, may see what was in the frame.' );
msrwa_test_contains( MSRWA_Engine_Input::visual_evidence( array() ), 'VISUAL EVIDENCE: none', 'A run with no photograph must say so rather than stay silent.' );

// The judge's input must actually carry it: its prompt has promised these
// observations all along while research_for_text() stripped them out.
$judged = MSRWA_Engine_Input::build( 'final_approval', 'PROMPT', array_merge( $clean, array( 'research' => $evidence_research, 'article' => array( 'content_html' => '<p>x</p>' ) ) ) );
msrwa_test_contains( $judged, 'VISUAL EVIDENCE', 'The approval step must be sent the visual evidence.' );
msrwa_test_contains( $judged, 'CANONICAL RECIPE', 'The approval step must be sent the canonical recipe.' );
msrwa_test_contains( $judged, 'assiette blanche', 'The approval step must be sent what the photographs showed.' );

// And the generation path must still not be: this is the channel that drew a
// watermark, two hands, peppers and carrots into four separate images.
$generated = MSRWA_Engine_Input::image_prompt( 'featured', array_merge( $clean, array( 'research' => $evidence_research ) ), array() );
msrwa_test_missing( $generated, 'assiette blanche', 'The image generator must still not receive the frame inventory.' );
msrwa_test_missing( $generated, 'riz à côté', 'Another cook\'s accompaniment must never reach the generator.' );
msrwa_test_contains( $generated, 'Doré et brun clair', 'Colour must still reach the generator.' );

// A gratin was drawn lifted onto a plate: the fallback served everything on
// one. A dish baked to be served in its dish stays in it, even when a
// photograph's observations mention a plate; a stew goes to its deep dish.
$gratin = array( 'title' => 'Gratin de cabillaud à la béchamel', 'equipment' => array( 'four', 'plat à gratin', 'casserole' ) );
$plated = array( 'visual_observations' => array( array( 'observable_details' => 'Une portion sur une assiette blanche.' ) ) );
msrwa_test_contains( MSRWA_Engine_Input::serving_presentation( $gratin, $plated, true ), 'served in the baking dish it was cooked in', 'A gratin is served in its dish.' );
msrwa_test_contains( MSRWA_Engine_Input::serving_presentation( array( 'title' => 'Gratin', 'equipment' => array( 'quatre plats à gratin individuels' ) ), array(), true ), 'individual baking dishes', 'Individual gratins stay individual.' );
msrwa_test_contains( MSRWA_Engine_Input::serving_presentation( array( 'title' => 'Potée', 'equipment' => array( 'grand faitout', 'grand plat creux' ) ), array(), true ), 'deep serving dish', 'A stew goes to the deep dish the recipe names.' );
msrwa_test_contains( MSRWA_Engine_Input::serving_presentation( array( 'title' => 'Croquettes', 'equipment' => array( 'friteuse' ) ), array(), true ), 'plain ceramic plate', 'Anything else is still plated.' );

msrwa_test_done( 'image prompts' );
