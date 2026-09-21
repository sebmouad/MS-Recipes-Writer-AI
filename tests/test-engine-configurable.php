<?php
// Nothing the engine does may be hardcoded. This reads the engine's own source
// and fails when a value that belongs in the configuration is written into the
// code instead — which is how endpoints, prices and thresholds got scattered
// across five files in the first place.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

$config = dirname( __DIR__ ) . '/includes/engine/class-msrwa-engine-config.php';
$engine = glob( dirname( __DIR__ ) . '/includes/engine/*.php' );

foreach ( $engine as $file ) {
	if ( $file === $config ) { continue; }
	$source = (string) file_get_contents( $file );
	$name = basename( $file );

	// A provider URL outside the configuration means a caller cannot point the
	// engine at a gateway, a proxy or a regional endpoint.
	preg_match_all( '#https?://[a-z0-9.-]+#i', $source, $urls );
	$hosts = array_values( array_unique( array_map( 'strtolower', $urls[0] ) ) );
	$allowed = array( 'https://' );
	$offending = array();
	foreach ( $hosts as $host ) {
		if ( in_array( $host, $allowed, true ) ) { continue; }
		$offending[] = $host;
	}
	msrwa_test_assert( empty( $offending ), $name . ' must not carry a provider URL; move it to the configuration. Found: ' . implode( ', ', $offending ) );

	// An environment variable name outside the configuration means a caller
	// cannot keep its keys anywhere else — including the .env.local allowlist,
	// which is derived from the same place so there is one list, not two.
	$keys = preg_match_all( '/\b(?:OPENAI|GEMINI|ANTHROPIC|MSRWA)_[A-Z_]*KEY\b/', $source );
	msrwa_test_assert( 0 === $keys, $name . ' names ' . $keys . ' API key variables; the configuration decides where keys live.' );
}

// Every scoring number a check compares against must exist as a threshold, so a
// caller can move it. This is the list the engine promises to expose.
$thresholds = MSRWA_Engine_Config::defaults()['thresholds'];
foreach ( array( 'research_minimums', 'research_outline_figures', 'article_accents_per_1000', 'article_closing_words', 'proofread_min_ratio', 'proofread_accent_slack', 'fact_check_max_fixes' ) as $key ) {
	msrwa_test_assert( isset( $thresholds[ $key ] ), $key . ' must be a threshold a caller can move.' );
}

// Moving a threshold must actually change the verdict, or it is decoration.
$thin = json_encode( array( 'pass' => false, 'corrections' => array_fill( 0, 5, array( 'before' => 'x', 'after' => 'y', 'source' => 'https://example.org' ) ) ) );
$brief = array( 'article' => array( 'content_html' => '<p>x</p>' ) );
$generous = MSRWA_Engine_Score::step( 'fact_check', $thin, $brief );
$strict = MSRWA_Engine_Score::step( 'fact_check', $thin, $brief, array( 'fact_check_max_fixes' => 2 ) );
msrwa_test_assert( true === $generous['checks']['surgical']['pass'], 'Five corrections pass the shipped ceiling of twelve.' );
msrwa_test_assert( false === $strict['checks']['surgical']['pass'], 'A caller lowering the ceiling to two must see five corrections fail.' );

// Every step that calls a model must resolve to a prompt, from a caller or from
// a template. A step whose prompt cannot be found says so rather than running.
$shipped = MSRWA_Engine_Config::create();
foreach ( MSRWA_Engine_Steps::all() as $step => $meta ) {
	if ( 'none' === $meta['capability'] ) { continue; }
	$prompt = $shipped->prompt( $step );
	msrwa_test_assert( '' !== $prompt['text'], $step . ' must resolve to a prompt; got ' . $prompt['source'] );
}
$absent = MSRWA_Engine_Config::create( array( 'steps' => array( 'article' => array( 'prompt' => 'no-such-file.tpl.txt' ) ) ) );
msrwa_test_assert( '' === $absent->prompt( 'article' )['text'], 'A prompt file that does not exist must resolve to nothing, not to a fatal error.' );
msrwa_test_assert( false !== strpos( $absent->prompt( 'article' )['source'], 'missing' ), 'A missing prompt must say it is missing.' );

// Every shipped model must price. A dotted path splits on the dot and model
// names contain them, so `models.openai.gpt-5.6-luna` resolved to nothing and a
// whole live run reported as free — which the per-call reporting is what caught.
$priced = MSRWA_Engine_Config::create();
$million = array( 'input_tokens' => 1000000, 'output_tokens' => 1000000 );
foreach ( MSRWA_Engine_Config::defaults()['models'] as $provider => $models ) {
	foreach ( array_keys( $models ) as $model ) {
		$cost = $priced->price( $provider, $model, $million );
		msrwa_test_assert( null !== $cost && $cost > 0, $model . ' must price; a model the engine ships and cannot price reports a run as free.' );
	}
}
msrwa_test_assert( null === $priced->price( 'openai', 'never-heard-of-it', $million ), 'A model with no rate is unknown, never free.' );

// Every tier must resolve to a model that prices, or a routing flag silently
// produces a run nobody can cost.
foreach ( MSRWA_Engine_Config::defaults()['tiers'] as $tier => $providers ) {
	foreach ( $providers as $provider => $model ) {
		$routed = MSRWA_Engine_Config::create( array(), array( 'routing' => array( 'article' => $provider . ':' . $tier ) ) );
		msrwa_test_assert( $model === $routed->model_for( 'article' )['model'], $provider . ':' . $tier . ' must resolve to ' . $model . '.' );
		msrwa_test_assert( null !== $routed->price( $provider, $model, $million ), $provider . ':' . $tier . ' resolves to ' . $model . ', which carries no rate.' );
	}
}

msrwa_test_done( 'engine configurability' );
