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
 *   php tools/prompt-lab.php run article [--variant=v2] [--brief=tarte-pommes] [--model=gpt-5.6-luna]
 *   php tools/prompt-lab.php promote article v2
 *
 * Candidate prompts live in tools/prompts/<step>.<variant>.txt; without a
 * variant the shipped default is used, so "run" always measures what ships.
 */
define( 'MSRWA_LAB', true );
require __DIR__ . '/lib/api.php';
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
		printf( "%-18s %-26s %s\n", $name, implode( '+', $definition['prompts'] ), $definition['expects'] );
	}
	echo "\nVariants present:\n";
	foreach ( glob( __DIR__ . '/prompts/*.txt' ) as $file ) { echo '  ' . basename( $file ) . "\n"; }
	exit( 0 );
}

if ( ! isset( $steps[ $step ] ) ) { fwrite( STDERR, "Unknown step '{$step}'. Run: php tools/prompt-lab.php list\n" ); exit( 2 ); }

if ( 'show' === $command ) {
	echo lab_prompt( $step, $options['variant'] ?? '' ) . "\n";
	exit( 0 );
}

if ( 'promote' === $command ) {
	$variant = $argv[3] ?? '';
	if ( '' === $variant ) { fwrite( STDERR, "Usage: promote <step> <variant>\n" ); exit( 2 ); }
	$file = __DIR__ . '/prompts/' . $step . '.' . $variant . '.txt';
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
	$scores = lab_score( $step, $saved['output'] ?? '', lab_brief( $options['brief'] ?? 'tarte-pommes' ) );
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
$prompt = lab_prompt( $step, $options['variant'] ?? '' );
$input = lab_build_input( $step, $prompt, $brief, $options );
$tokens = (int) ( $options['max-output'] ?? $steps[ $step ]['max_output'] );

printf( "step=%s variant=%s provider=%s model=%s tier=%s max_output=%d\n", $step, $options['variant'] ?? 'shipped', $provider, $model, $tier ?: '-', $tokens );
printf( "prompt=%d chars, input=%d chars\n\n", strlen( $prompt ), strlen( $input ) );

$result = lab_call( $provider, $model, $input, $tokens, ! empty( $steps[ $step ]['json'] ), $steps[ $step ]['tools'] ?? array() );
if ( isset( $result['error'] ) ) { fwrite( STDERR, 'API error after ' . $result['seconds'] . "s: " . $result['error'] . "\n" ); exit( 1 ); }

$cost = lab_price( $provider, $model, $result['usage'] );
$scores = lab_score( $step, $result['text'], $brief );

$run = array(
	'step' => $step, 'variant' => $options['variant'] ?? 'shipped', 'provider' => $provider, 'tier' => $tier, 'model' => $result['model'],
	'seconds' => $result['seconds'], 'usage' => $result['usage'], 'cost_usd' => $cost,
	'status' => $result['status'], 'scores' => $scores, 'output' => $result['text'], 'prompt' => $prompt,
);
$path = __DIR__ . '/runs/' . $step . '-' . ( $options['variant'] ?? 'shipped' ) . '-' . $provider . '-' . ( $tier ?: 'x' ) . '-' . gmdate( 'Ymd-His' ) . '.json';
file_put_contents( $path, json_encode( $run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

printf( "%-28s %s\n", 'time', $result['seconds'] . 's' );
printf( "%-28s in %d / out %d\n", 'tokens', (int) ( $result['usage']['input_tokens'] ?? 0 ), (int) ( $result['usage']['output_tokens'] ?? 0 ) );
printf( "%-28s %s\n", 'cost', null === $cost ? 'unknown model rate' : sprintf( '$%.4f', $cost ) );
printf( "%-28s %s\n", 'response status', (string) $result['status'] );
echo "\nscorecard\n";
foreach ( $scores['checks'] as $label => $check ) {
	printf( "  %-1s %-30s %s\n", $check['pass'] ? '✓' : '✗', $label, $check['detail'] );
}
printf( "\n%s  (%d/%d checks)\n", $scores['pass'] ? 'PASS' : 'FAIL', $scores['passed'], $scores['total'] );
echo 'saved ' . $path . "\n";
exit( $scores['pass'] ? 0 : 1 );
