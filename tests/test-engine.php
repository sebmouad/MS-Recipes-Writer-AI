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
$nowhere = MSRWA_Engine_Config::create( array(), array( 'routing' => array( 'article' => 'nowhere:medium' ) ) );
msrwa_test_assert( '' === $nowhere->model_for( 'article' )['model'], 'An unknown provider resolves to no model rather than exiting.' );
msrwa_test_assert( null === $nowhere->price( 'nowhere', 'nothing', array() ), 'An unpriced model is unknown, never free.' );

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
msrwa_test_assert( array( 'review', 'final_approval' ) === $waves[3], 'One review reads the text while the judge looks at the images: neither waits on the other.' );
msrwa_test_assert( array( 'corrections' ) === $waves[4], 'Facts are corrected once the review has reported.' );
msrwa_test_assert( array( 'proofread' ) === $waves[5], 'Language is corrected last, on the text the facts were fixed in.' );
msrwa_test_assert( 6 === count( $waves ), 'Six waves, where there were seven.' );

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

// The review's corrections are applied in code, not by hand and not by a model.
$reviewed = MSRWA_Engine::run_step( 'corrections', array( 'title' => 'Tarte', 'artifacts' => array(
	'article' => array( 'content_html' => '<p>Cuire 45 minutes à 180 °C.</p><p>Reposer 10 minutes.</p>' ),
	'review' => array( 'pass' => false, 'findings' => array( array( 'severity' => 'minor', 'section' => 'Cuisson', 'reason' => 'Trop court', 'fix' => 'Développer' ) ), 'corrections' => array(
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

// An explanation no source gives is removed, not rewritten: an empty
// replacement takes the sentence, its space, and a paragraph left empty.
$trimmed = MSRWA_Engine::run_step( 'corrections', array( 'title' => 'Souris', 'artifacts' => array(
	'article' => array( 'content_html' => '<p>Utilisez un bouillon. Le premier est plus neutre.</p><p>Il renforce le goût.</p><p>Servez chaud.</p>' ),
	'review' => array( 'pass' => true, 'findings' => array(), 'corrections' => array(
		array( 'before' => 'Le premier est plus neutre.', 'after' => '' ),
		array( 'before' => 'Il renforce le goût.', 'after' => '' ),
	) ),
) ) );
msrwa_test_assert( '<p>Utilisez un bouillon.</p><p>Servez chaud.</p>' === $trimmed->artifacts['corrected']['content_html'], 'An unsupported sentence is removed cleanly (got ' . $trimmed->artifacts['corrected']['content_html'] . ').' );

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
	'article' => array( 'CANONICAL RECIPE', 'RESEARCH PACKAGE' ),
	'review' => array( 'CANONICAL RECIPE', 'RESEARCH PACKAGE', 'ARTICLE' ),
	'final_approval' => array( 'CANONICAL RECIPE' ),
);
// The judge sees the images, the recipe and what real photographs showed; the
// text is the review's, and sending it again was most of the judge's input.
$judge_input = MSRWA_Engine_Input::build( 'final_approval', 'PROMPT', array_merge( array( 'title' => 'Poulet yassa', 'text' => '', 'images' => array() ), $full ) );
msrwa_test_missing( $judge_input, 'ARTICLE:', 'The judge is not sent the article.' );
msrwa_test_missing( $judge_input, 'RESEARCH PACKAGE', 'Nor the research package.' );
foreach ( $carries as $step => $required ) {
	$built = MSRWA_Engine_Input::build( $step, 'PROMPT', array_merge( array( 'title' => 'Poulet yassa', 'text' => '', 'images' => array() ), $full ) );
	foreach ( $required as $marker ) {
		msrwa_test_assert( false !== strpos( $built, $marker ), $step . ' must be sent its ' . $marker . '.' );
	}
	msrwa_test_assert( false !== strpos( $built, 'oignons' ), $step . ' must be sent the recipe it is measured against, not just its headings.' );
}

// Nothing the engine does is hardcoded. Every endpoint, price, tier, threshold,
// step and prompt is a configuration key, and a caller replaces any of them
// without touching the ones beside it.
$tuned = MSRWA_Engine_Config::create(
	array(
		'providers'  => array( 'openai' => array( 'text_endpoint' => 'https://gateway.example.test/v1/responses' ) ),
		'models'     => array( 'openai' => array( 'my-model' => array( 1.0, 2.0 ) ) ),
		'tiers'      => array( 'medium' => array( 'openai' => 'my-model' ) ),
		'thresholds' => array( 'article_accents_per_1000' => 25 ),
		'steps'      => array( 'article' => array( 'bucket' => 'other' ) ),
		'prompts'    => array( 'article' => 'MY OWN PROMPT in {{language}}' ),
		'limits'     => array( 'http_timeout' => 42 ),
	),
	array( 'routing' => array( 'article' => 'openai:medium' ) )
);
$wire = $tuned->provider( 'openai', 'my-model' );
msrwa_test_assert( 'my-model' === $tuned->model_for( 'article' )['model'], 'A caller may redefine what a tier resolves to.' );
msrwa_test_assert( 3.0 === $tuned->price( 'openai', 'my-model', array( 'input_tokens' => 1000000, 'output_tokens' => 1000000 ) ), 'A caller may price a model the engine has never heard of.' );
msrwa_test_assert( 'https://gateway.example.test/v1/responses' === $wire['text_endpoint'], 'A caller may point a provider at its own gateway.' );
msrwa_test_assert( 42 === $wire['timeout'], 'A caller may set the HTTP timeout.' );
msrwa_test_assert( false !== strpos( $tuned->provider( 'gemini', 'x' )['text_endpoint'], 'googleapis.com' ), 'Overriding one provider must leave the others alone.' );
msrwa_test_assert( false !== strpos( implode( ' ', $wire['headers'] ), 'Authorization: Bearer' ), 'Overriding an endpoint must not drop the headers beside it.' );
msrwa_test_assert( 25 === $tuned->thresholds()['article_accents_per_1000'], 'A caller may move a scoring threshold.' );
msrwa_test_assert( 60 === $tuned->thresholds()['article_closing_words'], 'Moving one threshold must leave the others at their measured value.' );
msrwa_test_assert( 'other' === $tuned->steps()['article']['bucket'], 'A caller may recharge a step to another budget bucket.' );
msrwa_test_assert( array( 'research', 'canonical' ) === $tuned->steps()['article']['needs'], 'Retuning one field of a step must not erase the rest of it.' );
msrwa_test_assert( 'caller' === $tuned->prompt( 'article' )['source'], 'A caller may supply the prompt text itself.' );
msrwa_test_assert( false !== strpos( $tuned->prompt( 'article' )['text'], 'French' ), 'A caller-supplied prompt is compiled like any other.' );
msrwa_test_assert( 'template article.tpl.txt' === MSRWA_Engine_Config::create()->prompt( 'article' )['source'], 'With no prompt given, the shipped template is used and says so.' );

// A caller may add a step the engine has never had.
$extended = MSRWA_Engine_Config::create( array( 'steps' => array( 'translate' => array( 'label' => 'Traduction', 'needs' => array( 'article' ), 'produces' => 'translated' ) ) ) );
msrwa_test_assert( isset( $extended->steps()['translate'] ), 'A caller may add a step.' );
msrwa_test_assert( 'text' === $extended->steps()['translate']['capability'], 'An added step gets sensible defaults for what it did not say.' );
msrwa_test_assert( in_array( 'translate', MSRWA_Engine_Steps::ready( array( 'article' => true ), null, (array) $extended->get( 'steps' ) ), true ), 'An added step takes part in the wave scheduling like any other.' );

// The record says who decided what, and never says where a key is kept.
$recorded = $tuned->to_array();
msrwa_test_assert( 'caller' === $recorded['_provenance']['providers'], 'The record must name the layer that set each value.' );
msrwa_test_assert( 'run' === $recorded['_provenance']['routing'], 'A run-level override must be recorded as such.' );
msrwa_test_assert( 'engine' === $recorded['_provenance']['images'], 'An untouched value must be recorded as the engine default.' );
msrwa_test_assert( ! isset( $recorded['providers']['openai']['key_env'] ), 'The record must not say where an API key is kept.' );
msrwa_test_assert( ! isset( $recorded['settings'] ), 'The record must never carry the settings, which hold keys.' );

// The engine says what it did with the data, not only what it cost.
$told = array();
$reported = MSRWA_Engine::run( array( 'title' => 'Tarte aux pommes' ), array( 'only' => array( 'research' ) ), function ( $event ) use ( &$told ) { $told[ $event['kind'] ][] = $event; } );
msrwa_test_assert( isset( $told['config'] ), 'A run must report what it was configured with.' );
msrwa_test_assert( isset( $told['config'][0]['data']['provenance'] ), 'The configuration report must carry the provenance, not just a sentence.' );
msrwa_test_assert( isset( $told['input'] ), 'A run must report what each step was given.' );
$given = $told['input'][0]['data'];
foreach ( array( 'prompt_source', 'prompt_chars', 'input_chars', 'attached', 'ceiling', 'web_search', 'route' ) as $field ) {
	msrwa_test_assert( array_key_exists( $field, $given ), 'The input report must carry ' . $field . '.' );
}

// Independent steps are asked together. The engine already knew which ones those
// were; asking them one at a time was pure waiting — 138 seconds for the article
// and both images where 71 would do.
msrwa_test_assert( 4 === MSRWA_Engine_Config::create()->get( 'limits.concurrency' ), 'A wave runs four calls at once unless the caller says otherwise.' );
msrwa_test_assert( 1 === MSRWA_Engine_Config::create( array( 'limits' => array( 'concurrency' => 0 ) ) )->get( 'limits.concurrency' ), 'Concurrency below one means one, not none.' );
msrwa_test_assert( 12 === MSRWA_Engine_Config::create( array( 'limits' => array( 'concurrency' => 99 ) ) )->get( 'limits.concurrency' ), 'Concurrency is clamped to what a provider will tolerate.' );

// Splitting the request from the reading of it must not change what a call is.
$planned = MSRWA_Engine_Call::plan_text( 'openai', 'gpt-5.6-luna', 'bonjour', 500, true, false, array( 'text_endpoint' => 'https://example.test/v1', 'headers' => array( 'X: 1' ), 'has_key' => true, 'timeout' => 30 ) );
msrwa_test_assert( 'https://example.test/v1' === $planned['request']['url'], 'A plan carries the endpoint it will call.' );
msrwa_test_assert( 'bonjour' === $planned['request']['payload']['input'], 'A plan carries the input it will send.' );
msrwa_test_assert( 500 === $planned['request']['payload']['max_output_tokens'], 'A plan carries its ceiling.' );
msrwa_test_assert( 30 === $planned['request']['timeout'], 'A plan carries the configured timeout.' );

// Reading an answer back is the same work whoever made the call.
$read = MSRWA_Engine_Call::read( $planned, array( 'status' => 200, 'seconds' => 1.2, 'error' => '', 'raw' => json_encode( array(
	'output_text' => '{"ok":true}', 'status' => 'completed', 'model' => 'gpt-5.6-luna',
	'usage' => array( 'input_tokens' => 120, 'output_tokens' => 9, 'input_tokens_details' => array( 'cached_tokens' => 96 ) ),
) ) ) );
msrwa_test_assert( '{"ok":true}' === $read['text'], 'A read must return the answer.' );
msrwa_test_assert( 96 === $read['usage']['cached_input_tokens'], 'A read must report what the provider served from its cache.' );
msrwa_test_assert( 1.2 === $read['seconds'], 'A read must keep the call\'s own duration, not the wave\'s.' );

$failed = MSRWA_Engine_Call::read( $planned, array( 'status' => 429, 'seconds' => 0.4, 'error' => '', 'raw' => 'slow down' ) );
msrwa_test_assert( ! empty( $failed['error'] ), 'An HTTP failure inside a batch is still an error, not an exception.' );

// One bad request must not disturb the others sharing its wave.
$mixed = MSRWA_Engine_Call::http_many( array(
	'good' => array( 'url' => 'https://example.test/one', 'headers' => array(), 'payload' => array(), 'timeout' => 1 ),
	'bad'  => array( 'url' => 'not-a-url', 'headers' => array(), 'payload' => array(), 'timeout' => 1 ),
), 4 );
msrwa_test_assert( array( 'good', 'bad' ) === array_keys( $mixed ), 'Every request in a batch must come back under its own key.' );
foreach ( $mixed as $name => $answer ) {
	msrwa_test_assert( isset( $answer['status'] ) && isset( $answer['seconds'] ), $name . ' must come back in the shape of a call, however it went.' );
}

// A later version of the article replaces only what it filled. A live
// proofread answered with an empty body; merged whole, it replaced a 21 505-
// character article with nothing, the approval judged a blank page and the
// run ended without a draft.
$merged = array_merge( array( 'content_html' => '<p>Article</p>', 'excerpt' => 'Résumé', 'slug' => 'tarte' ), MSRWA_Engine::filled( array( 'content_html' => '', 'excerpt' => '  ', 'notes' => array(), 'title' => 'Tarte normande' ) ) );
msrwa_test_assert( '<p>Article</p>' === $merged['content_html'], 'An empty body never replaces the article before it.' );
msrwa_test_assert( 'Résumé' === $merged['excerpt'], 'Nor does a blank excerpt.' );
msrwa_test_assert( 'Tarte normande' === $merged['title'], 'What the later version did fill still wins.' );
msrwa_test_assert( ! isset( $merged['notes'] ), 'An empty list is not a value.' );

msrwa_test_done( 'engine' );
