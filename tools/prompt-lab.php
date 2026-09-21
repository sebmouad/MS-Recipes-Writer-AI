<?php
/**
 * Prompt lab — prove a prompt before it reaches the plugin.
 *
 * Runs one pipeline step against the real API with no WordPress, scores the
 * answer with the plugin's own quality gate, and records what it cost. A
 * prompt that wins here is promoted into the shipped defaults, which seed the
 * plugin database.
 *
 *   php tools/prompt-lab.php list
 *   php tools/prompt-lab.php show article
 *   php tools/prompt-lab.php show article --shipped=1
 *   php tools/prompt-lab.php run research --brief=tarte-pommes
 *   php tools/prompt-lab.php run article --research=tools/runs/research-....json
 *
 * The maintained lab prompt for every stage is declared by lab_steps(). Use
 * --shipped=1 to compare it with the current plugin default. Experimental
 * variants may still use includes/engine/prompts/<step>.<variant>.txt temporarily.
 */
define( 'MSRWA_LAB', true );
require __DIR__ . '/lib/steps.php';
require __DIR__ . '/lib/providers.php';
require __DIR__ . '/lib/pricing.php';

$argv = $_SERVER['argv'];
$command = $argv[1] ?? 'list';
$step = $argv[2] ?? '';
$options = array();
foreach ( array_slice( $argv, 2 ) as $argument ) {
	if ( preg_match( '/^--([a-z-]+)=(.*)$/', $argument, $match ) ) { $options[ $match[1] ] = $match[2]; }
}

$steps = lab_steps();

if ( 'list' === $command ) {
	printf( "%-18s %-26s %s\n", 'STEP', 'PROMPT KEYS', 'WHAT IT MUST PRODUCE' );
	foreach ( $steps as $name => $definition ) {
		printf( "%-18s %-26s %s\n", $name, $definition['file'], $definition['expects'] );
	}
	echo "\nMaintained prompt files:\n";
	foreach ( glob( MSRWA_Engine_Input::prompt_path( '*.txt' ) ) as $file ) { echo '  ' . basename( $file ) . "\n"; }
	exit( 0 );
}

if ( ! isset( $steps[ $step ] ) ) { fwrite( STDERR, "Unknown step '{$step}'. Run: php tools/prompt-lab.php list\n" ); exit( 2 ); }

if ( 'show' === $command ) {
	echo lab_prompt( $step, $options['variant'] ?? '', ! empty( $options['shipped'] ) ) . "\n";
	exit( 0 );
}

if ( 'promote' === $command ) {
	$variant = $argv[3] ?? '';
	if ( '' === $variant ) { fwrite( STDERR, "Usage: promote <step> <variant>\n" ); exit( 2 ); }
	$file = MSRWA_Engine_Input::prompt_path( $step . '.' . $variant . '.txt' );
	if ( ! file_exists( $file ) ) { fwrite( STDERR, "No such variant: {$file}\n" ); exit( 2 ); }
	echo "Copy this text into the '" . $steps[ $step ]['prompts'][0] . "' default in includes/class-msrwa-settings.php,\n";
	echo "then run php tests/run.php and commit. The database is seeded from those defaults.\n\n";
	echo file_get_contents( $file );
	exit( 0 );
}

if ( 'rescore' === $command ) {
	$file = $argv[3] ?? '';
	if ( ! file_exists( $file ) ) { fwrite( STDERR, "Usage: rescore <step> <tools/runs/file.json>\n" ); exit( 2 ); }
	$saved = json_decode( file_get_contents( $file ), true );
	$scores = lab_score( $step, $saved['output'] ?? '', lab_brief( $options['brief'] ?? 'tarte-pommes' ), $options );
	foreach ( $scores['checks'] as $label => $check ) { printf( "  %-1s %-30s %s\n", $check['pass'] ? '✓' : '✗', $label, $check['detail'] ); }
	printf( "\n%s  (%d/%d checks)\n", $scores['pass'] ? 'PASS' : 'FAIL', $scores['passed'], $scores['total'] );
	exit( $scores['pass'] ? 0 : 1 );
}

if ( 'run' !== $command ) { fwrite( STDERR, "Unknown command '{$command}'.\n" ); exit( 2 ); }

$brief = lab_brief( $options['brief'] ?? 'tarte-pommes' );
$provider = $options['provider'] ?? 'openai';
$tier = $options['tier'] ?? '';
$model = $options['model'] ?? ( '' !== $tier ? ( lab_tiers()[ $tier ][ $provider ] ?? '' ) : 'gpt-5.6-luna' );
if ( '' === $model ) { fwrite( STDERR, "No model for provider {$provider} tier {$tier}.\n" ); exit( 2 ); }
$brief_vision_usage = array( 'input_tokens' => 0, 'output_tokens' => 0 );
if ( 'research' === $step ) {
	$editor = lab_editor_brief( $brief );
	$editor['image_observations'] = array();
	foreach ( array_slice( (array) ( $editor['images'] ?? array() ), 0, 3 ) as $candidate ) {
		$url = is_array( $candidate ) ? (string) ( $candidate['image_url'] ?? '' ) : (string) $candidate;
		$image = lab_fetch_image( $url );
		if ( isset( $image['error'] ) ) { $editor['image_observations'][] = array( 'image_url' => $url, 'uncertainties' => $image['error'] ); continue; }
		$vision = lab_call_vision( $provider, $model, $image, 'Image fournie par l’éditeur' );
		$brief_vision_usage['input_tokens'] += (int) ( $vision['usage']['input_tokens'] ?? 0 );
		$brief_vision_usage['output_tokens'] += (int) ( $vision['usage']['output_tokens'] ?? 0 );
		$observed = MSRWA_Json::decode( (string) ( $vision['text'] ?? '' ) );
		$editor['image_observations'][] = is_array( $observed ) ? array_merge( array( 'image_url' => $url ), $observed ) : array( 'image_url' => $url, 'uncertainties' => 'Analyse visuelle non structurée.' );
	}
	$brief['editor_input'] = $editor;
}
$prompt = lab_prompt( $step, $options['variant'] ?? '', ! empty( $options['shipped'] ) );
$input = lab_build_input( $step, $prompt, $brief, $options );
$tokens = (int) ( $options['max-output'] ?? $steps[ $step ]['max_output'] );

$source = ! empty( $options['shipped'] ) ? 'shipped' : ( $options['variant'] ?? 'maintained' );
printf( "step=%s prompt=%s provider=%s model=%s tier=%s max_output=%d\n", $step, $source, $provider, $model, $tier ?: '-', $tokens );
printf( "prompt=%d chars, input=%d chars\n\n", strlen( $prompt ), strlen( $input ) );

$result = lab_call( $provider, $model, $input, $tokens, ! empty( $steps[ $step ]['json'] ), $steps[ $step ]['tools'] ?? array() );
if ( isset( $result['error'] ) ) { fwrite( STDERR, 'API error after ' . $result['seconds'] . "s: " . $result['error'] . "\n" ); exit( 1 ); }
$result['usage']['input_tokens'] = (int) ( $result['usage']['input_tokens'] ?? 0 ) + $brief_vision_usage['input_tokens'];
$result['usage']['output_tokens'] = (int) ( $result['usage']['output_tokens'] ?? 0 ) + $brief_vision_usage['output_tokens'];

if ( 'research' === $step ) {
	$package = MSRWA_Json::decode( $result['text'] );
	if ( is_array( $package ) ) {
		$vision = lab_enrich_research_images( $provider, $model, $package );
		$result['text'] = json_encode( $vision['package'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$result['usage']['input_tokens'] = (int) ( $result['usage']['input_tokens'] ?? 0 ) + (int) ( $vision['usage']['input_tokens'] ?? 0 );
		$result['usage']['output_tokens'] = (int) ( $result['usage']['output_tokens'] ?? 0 ) + (int) ( $vision['usage']['output_tokens'] ?? 0 );
	}
}

$cost = lab_price( $provider, $model, $result['usage'] );
$scores = lab_score( $step, $result['text'], $brief, $options );

$run = array(
	'step' => $step, 'variant' => $source, 'provider' => $provider, 'tier' => $tier, 'model' => $result['model'],
	'seconds' => $result['seconds'], 'usage' => $result['usage'], 'cost_usd' => $cost,
	'status' => $result['status'], 'scores' => $scores, 'output' => $result['text'], 'prompt' => $prompt,
);
$runs_directory = __DIR__ . '/runs';
if ( ! is_dir( $runs_directory ) && ! mkdir( $runs_directory, 0775, true ) ) { fwrite( STDERR, "Could not create {$runs_directory}.\n" ); exit( 1 ); }
$brief_name = preg_replace( '/[^a-z0-9-]+/i', '-', (string) ( $options['brief'] ?? 'tarte-pommes' ) );
$path = __DIR__ . '/runs/' . $brief_name . '-' . $step . '-' . $source . '-' . $provider . '-' . ( $tier ?: 'x' ) . '-' . gmdate( 'Ymd-His' ) . '.json';
file_put_contents( $path, json_encode( $run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

printf( "%-28s %s\n", 'time', $result['seconds'] . 's' );
printf( "%-28s in %d / out %d\n", 'tokens', (int) ( $result['usage']['input_tokens'] ?? 0 ), (int) ( $result['usage']['output_tokens'] ?? 0 ) );
printf( "%-28s %s\n", 'cost', null === $cost ? 'unknown model rate' : sprintf( '$%.4f', $cost ) );
printf( "%-28s %s\n", 'response status', (string) $result['status'] );

// An answer that stops exactly on the ceiling was cut, whatever status the
// provider reports. This has now bitten the article, the recipe, the approval
// and research in turn, each time as a mysterious parse failure.
$produced = (int) ( $result['usage']['output_tokens'] ?? 0 );
if ( $produced > 0 && $produced >= $tokens ) {
	printf( "\n!! TRUNCATED: %d output tokens against a ceiling of %d. Raise the step's max output setting; the answer below is incomplete and was billed in full.\n", $produced, $tokens );
}
echo "\nscorecard\n";
foreach ( $scores['checks'] as $label => $check ) {
	printf( "  %-1s %-30s %s\n", $check['pass'] ? '✓' : '✗', $label, $check['detail'] );
}
printf( "\n%s  (%d/%d checks)\n", $scores['pass'] ? 'PASS' : 'FAIL', $scores['passed'], $scores['total'] );
echo 'saved ' . $path . "\n";
exit( $scores['pass'] ? 0 : 1 );
