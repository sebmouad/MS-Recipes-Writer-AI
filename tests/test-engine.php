<?php
// The engine is the plugin's core, so its two promises are tested here: it
// reports failure as data rather than killing its caller, and it knows which
// steps may run at the same time.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

// A run records what happened, including what failed, and still returns the rest.
$seen = array();
$result = new MSRWA_Result( function ( $event ) use ( &$seen ) { $seen[] = $event['kind']; } );
$result->step( 'research', array( 'seconds' => 40, 'cost_usd' => 0.02, 'passed' => 14, 'total' => 14 ) );
$result->step( 'featured_image', array( 'seconds' => 11, 'cost_usd' => 0.027 ) );
$result->step( 'facebook_image', array( 'seconds' => 13, 'cost_usd' => 0.033, 'error' => 'provider refused' ) );
$result->artifact( 'research', array( 'ok' => true ) );

msrwa_test_assert( false === $result->ok, 'A failed step must mark the run not ok.' );
msrwa_test_assert( 1 === count( $result->errors ), 'The failure must be recorded once.' );
msrwa_test_assert( 3 === count( $result->steps ), 'Every attempted step is recorded, failures included.' );
msrwa_test_assert( isset( $result->artifacts['research'] ), 'A partial run must still return what succeeded.' );
msrwa_test_assert( in_array( 'error', $seen, true ), 'The observer must see the failure as it happens.' );

$totals = $result->totals();
msrwa_test_assert( 64.0 === round( $totals['seconds'], 1 ), 'Seconds add up across steps; got ' . $totals['seconds'] );
msrwa_test_assert( 0.08 === round( $totals['cost_usd'], 2 ), 'Cost adds up across steps; got ' . $totals['cost_usd'] );
msrwa_test_assert( 0.027 === round( $totals['buckets']['featured'], 3 ), 'Each cost lands in its own budget bucket.' );
msrwa_test_assert( 0.02 === round( $totals['buckets']['other'], 3 ), 'Research is charged to other, not to the article.' );
msrwa_test_assert( isset( $result->to_array()['events'] ), 'The whole run must serialise for storage and for the report.' );

// A missing key is reported, never fatal: the plugin must survive a bad setting.
$before = getenv( 'OPENAI_API_KEY' );
putenv( 'OPENAI_API_KEY' );
putenv( 'MSRWA_OPENAI_KEY' );
$call = MSRWA_Engine_Call::text( 'openai', 'gpt-5.6-luna', 'hello', 10 );
msrwa_test_assert( ! empty( $call['error'] ), 'A missing API key must come back as an error, not an exit.' );
if ( false !== $before ) { putenv( 'OPENAI_API_KEY=' . $before ); }

// An unroutable model is a value too.
msrwa_test_assert( '' === MSRWA_Engine_Rates::model( 'nowhere', 'medium' ), 'An unknown provider resolves to no model rather than exiting.' );
msrwa_test_assert( null === MSRWA_Engine_Rates::price( 'nowhere', 'nothing', array() ), 'An unpriced model is unknown, never free.' );

// The order steps may run in. Three run beside each other in two of the waves,
// which is where the wall clock is won.
$done = array();
$remaining = MSRWA_Engine_Steps::names();
$waves = array();
while ( $remaining ) {
	$ready = MSRWA_Engine_Steps::ready( $done, $remaining );
	msrwa_test_assert( ! empty( $ready ), 'The pipeline must never deadlock; stuck on ' . implode( ', ', $remaining ) );
	$waves[] = $ready;
	foreach ( $ready as $name ) { $done[ MSRWA_Engine_Steps::get( $name )['produces'] ] = true; }
	$remaining = array_values( array_diff( $remaining, $ready ) );
}
msrwa_test_assert( array( 'research' ) === $waves[0], 'Research opens the pipeline.' );
msrwa_test_assert( array( 'canonical_recipe' ) === $waves[1], 'The recipe follows research alone.' );
msrwa_test_assert( 3 === count( $waves[2] ), 'The article and both images do not wait on each other.' );
msrwa_test_assert( in_array( 'featured_image', $waves[2], true ) && in_array( 'article', $waves[2], true ), 'Images need the recipe, not the article.' );
msrwa_test_assert( array( 'review', 'fact_check' ) === $waves[3], 'The two text reviews are independent of one another.' );
msrwa_test_assert( array( 'corrections' ) === $waves[4], 'Facts are corrected once both reviews have reported.' );
msrwa_test_assert( array( 'proofread' ) === $waves[5], 'Language is corrected last, on the text the facts were fixed in.' );
msrwa_test_assert( array( 'final_approval' ) === $waves[6], 'Approval is last: it judges the article a reader would get.' );

$missing = MSRWA_Engine_Steps::missing( 'article', array( 'research' => true ) );
msrwa_test_assert( array( 'canonical' ) === $missing, 'A step must say what it is still waiting for.' );

// Every step that calls a model names a prompt that exists, or the run fails there.
foreach ( MSRWA_Engine_Steps::all() as $name => $step ) {
	if ( 'none' === $step['capability'] ) {
		msrwa_test_assert( '' === $step['prompt'], $name . ' asks no model, so it must name no prompt.' );
		continue;
	}
	msrwa_test_assert( ! empty( $step['prompt'] ), $name . ' must name its prompt template.' );
	msrwa_test_assert( is_readable( MSRWA_Engine_Input::prompt_path( $step['prompt'] ) ), $step['prompt'] . ' must exist beside the engine.' );
}

// The fact check's corrections are applied in code, not by hand and not by a model.
$reviewed = MSRWA_Engine::run_step( 'corrections', array( 'title' => 'Tarte', 'artifacts' => array(
	'article' => array( 'content_html' => '<p>Cuire 45 minutes à 180 °C.</p><p>Reposer 10 minutes.</p>' ),
	'review' => array( 'pass' => false, 'findings' => array( array( 'severity' => 'minor', 'section' => 'Cuisson', 'reason' => 'Trop court', 'fix' => 'Développer' ) ) ),
	'fact_check' => array( 'pass' => false, 'corrections' => array(
		array( 'before' => 'Cuire 45 minutes à 180 °C.', 'after' => 'Cuire 40 minutes à 180 °C.', 'source' => 'https://example.org' ),
		array( 'before' => 'Une phrase que l’article ne contient pas.', 'after' => 'Peu importe.', 'source' => 'https://example.org' ),
	) ),
) ) );
$corrected = $reviewed->artifacts['corrected'];
msrwa_test_assert( false !== strpos( $corrected['content_html'], 'Cuire 40 minutes' ), 'A correction quoted verbatim must be applied exactly.' );
msrwa_test_assert( false !== strpos( $corrected['content_html'], 'Reposer 10 minutes' ), 'Nothing the fact check did not name may change.' );
msrwa_test_assert( 1 === count( $corrected['corrections_applied'] ), 'What was applied is recorded.' );
msrwa_test_assert( 1 === count( $corrected['corrections_for_the_editor'] ), 'A correction that cannot be located is handed to a person, never dropped.' );
msrwa_test_assert( 0.0 === $reviewed->totals()['cost_usd'], 'Applying a verbatim substitution calls no model and costs nothing.' );

// Configuration speaks the engine's vocabulary, and the caller overrides it in that
// vocabulary — never the other way round.
$config = MSRWA_Engine_Config::create(
	array( 'max_output' => array( 'article' => 9000 ), 'images' => array( 'featured_quality' => 'high' ) ),
	array( 'routing' => array( 'article' => 'claude:high' ), 'max_output' => array( 'article' => 99999 ) )
);
msrwa_test_assert( 'high' === $config->get( 'images.featured_quality' ), 'A caller value must survive where the run says nothing.' );
msrwa_test_assert( 32000 === $config->max_output( 'article' ), 'A ceiling above what providers accept is clamped, not forwarded.' );
msrwa_test_assert( 4500 === $config->max_output( 'canonical_recipe' ), 'An untouched default must stay the measured one.' );
msrwa_test_assert( 'claude' === $config->model_for( 'article' )['provider'], 'The run layer overrides the caller layer.' );
msrwa_test_assert( ! isset( $config->to_array()['settings'] ), 'The recorded configuration must never carry the settings, which hold keys.' );

// A brief with nothing in it costs nothing and kills nobody.
$empty = MSRWA_Engine::run( array( 'title' => '  ', 'text' => '' ) );
msrwa_test_assert( false === $empty->ok && 0 === count( $empty->steps ), 'An empty brief must be refused before anything is billed.' );

// A whole run without a key: every step reports, none exits, and the pipeline
// still terminates instead of waiting forever on an artifact that never came.
putenv( 'OPENAI_API_KEY' );
putenv( 'MSRWA_OPENAI_KEY' );
$run = MSRWA_Engine::run( array( 'title' => 'Tarte aux pommes' ) );
msrwa_test_assert( false === $run->ok, 'A run with no key cannot succeed.' );
msrwa_test_assert( count( MSRWA_Engine_Steps::names() ) === count( $run->errors ), 'Every step must account for itself, whether it ran or waited.' );
msrwa_test_assert( 0.0 === $run->totals()['cost_usd'], 'A call that never happened costs nothing.' );
if ( false !== $before ) { putenv( 'OPENAI_API_KEY=' . $before ); }

// Each capability reaches its own path: text, image generation and the judge that
// reads image bytes. None of the three may take the run down with it.
$artifacts = array(
	'research' => array( 'ingredients' => array( array( 'name' => 'pommes' ) ) ),
	'canonical' => array( 'title' => 'Tarte aux pommes', 'ingredients' => array( array( 'quantity' => '6', 'name' => 'pommes' ) ), 'steps' => array( array( 'text' => 'Éplucher' ) ) ),
	'article' => array( 'content_html' => '<h2>Test</h2><p>Bonjour</p>' ),
	'proofread' => array( 'content_html' => '<h2>Test</h2><p>Bonjour corrigé</p>' ),
	'featured' => array( 'kind' => 'featured', 'path' => '/no/such/image.webp', 'size' => '1024x1024', 'mime' => 'image/webp' ),
	'facebook' => array( 'kind' => 'facebook', 'path' => '/no/such/image.webp', 'size' => '1024x1536', 'mime' => 'image/webp' ),
);
$judged = MSRWA_Engine::run_step( 'final_approval', array( 'title' => 'Tarte aux pommes', 'artifacts' => $artifacts ) );
msrwa_test_assert( false !== strpos( $judged->errors[0]['message'], 'no longer readable' ), 'An image the judge cannot read is named, not skipped: ' . $judged->errors[0]['message'] );

// Every step that sends text to a model must carry what it is measured against.
// The approval step had no branch at all, so it judged the two images with no
// article, no recipe and no research, and refused for exactly that reason — at
// full price, three times over, because a refusal it cannot act on was being
// retried. Live running found it; nothing offline could have.
$full = array(
	'research' => array( 'ingredients' => array( array( 'name' => 'oignons' ) ), 'preparation' => array( array( 'text' => 'Confire', 'cue' => 'translucide' ) ), 'visual_observations' => array( array( 'colours' => 'ambré' ) ) ),
	'canonical' => array( 'title' => 'Poulet yassa', 'ingredients' => array( array( 'quantity' => '4', 'name' => 'oignons' ) ), 'steps' => array( array( 'text' => 'Confire les oignons' ) ) ),
	'article' => array( 'title' => 'Poulet yassa', 'content_html' => '<h2>Cuisson</h2><p>Confire les oignons 40 minutes.</p>' ),
);
$carries = array(
	'canonical_recipe' => array( 'EDITOR BRIEF', 'RESEARCH PACKAGE' ),
	'article' => array( 'Recette canonique', 'RESEARCH PACKAGE' ),
	'review' => array( 'CANONICAL RECIPE', 'RESEARCH PACKAGE', 'ARTICLE' ),
	'fact_check' => array( 'RESEARCH PACKAGE', 'CANONICAL RECIPE', 'ARTICLE' ),
	'proofread' => array( 'RESEARCH PACKAGE', 'CANONICAL RECIPE', 'ARTICLE TO CORRECT' ),
	'final_approval' => array( 'CANONICAL RECIPE', 'RESEARCH PACKAGE', 'ARTICLE' ),
);
foreach ( $carries as $step => $required ) {
	$built = MSRWA_Engine_Input::build( $step, 'PROMPT', array_merge( array( 'title' => 'Poulet yassa', 'text' => '', 'images' => array() ), $full ) );
	foreach ( $required as $marker ) {
		msrwa_test_assert( false !== strpos( $built, $marker ), $step . ' must be sent its ' . $marker . '.' );
	}
	msrwa_test_assert( false !== strpos( $built, 'oignons' ), $step . ' must be sent the recipe it is measured against, not just its headings.' );
}

msrwa_test_done( 'engine' );
