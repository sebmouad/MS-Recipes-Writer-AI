<?php
/**
 * Approval lab — the last gate before anything reaches a reader.
 *
 * One call sees the article and both finished images together, which is the only
 * point in the pipeline where the three can be checked against each other. Until
 * this existed the images were signed off by eye, and the corrections the text
 * reviews asked for were applied by hand.
 *
 *   php tools/approval-lab.php --article=tools/runs/<article run>.json \
 *       --featured=tools/runs/<file>.webp --facebook=tools/runs/<file>.webp \
 *       [--brief=tarte-pommes] [--provider=openai] [--tier=medium] [--attempts=3]
 *
 * With --attempts above 1 a refusal is not the end: the images the judge blocked
 * are regenerated with its findings as corrections and submitted again, until it
 * approves or the attempts run out. Measurement put a single collage generation
 * at roughly one approval in three, so the accepted artifact is what costs money,
 * not the attempt.
 */
define( 'MSRWA_LAB', true );
require __DIR__ . '/lib/steps.php';
require __DIR__ . '/lib/providers.php';
require __DIR__ . '/lib/pricing.php';

$options = array();
foreach ( array_slice( $_SERVER['argv'], 1 ) as $argument ) {
	if ( preg_match( '/^--([a-z-]+)=(.*)$/', $argument, $match ) ) { $options[ $match[1] ] = $match[2]; }
}

$brief = lab_brief( $options['brief'] ?? 'tarte-pommes' );
$provider = $options['provider'] ?? 'openai';
$tier = $options['tier'] ?? 'medium';
$model = $options['model'] ?? lab_model( $provider, $tier );

$canonical = lab_canonical_recipe( $brief, $options );
$research = lab_research_package( $brief, $options );
$article = lab_article_under_test( $options );
if ( empty( $article['content_html'] ) ) { fwrite( STDERR, "Pass --article=<a saved article run> or use a fixture containing an article.\n" ); exit( 2 ); }

/** Reads a generated image from disk; the judge needs the bytes, not a path. */
function approval_image( $path, $label ) {
	if ( '' === (string) $path ) { return null; }
	if ( ! file_exists( $path ) ) { fwrite( STDERR, "No such image: {$path}\n" ); exit( 2 ); }
	$bytes = file_get_contents( $path );
	$types = array( 'webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg' );
	$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( ! isset( $types[ $extension ] ) ) { fwrite( STDERR, "Unsupported image type: {$extension}\n" ); exit( 2 ); }
	return array( 'label' => $label, 'mime' => $types[ $extension ], 'data' => base64_encode( $bytes ), 'bytes' => strlen( $bytes ), 'path' => $path );
}

$settings = lab_settings();
$paths = array( 'featured' => (string) ( $options['featured'] ?? '' ), 'facebook' => (string) ( $options['facebook'] ?? '' ) );
if ( '' === $paths['featured'] && '' === $paths['facebook'] ) { fwrite( STDERR, "Pass --featured= and --facebook= with the generated image files.\n" ); exit( 2 ); }
$labels = array(
	'featured' => 'featured, ' . MSRWA_Images::native_size( $settings['featured_ratio'], '1024x1024' ),
	'facebook' => 'facebook collage, ' . MSRWA_Images::native_size( $settings['facebook_ratio'], '1024x1536' ),
);
$attempts = max( 1, min( 6, (int) ( $options['attempts'] ?? 1 ) ) );
$spent = 0.0;
$history = array();

$encode = static function ( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };
$prompt = MSRWA_Prompt::compile( trim( file_get_contents( __DIR__ . '/prompts/final_approval.tpl.txt' ) ), $settings )
	. "\n\nCANONICAL RECIPE: " . $encode( $canonical )
	. "\nRESEARCH PACKAGE: " . $encode( lab_research_for_text( $research ) )
	. "\n\n" . lab_visual_brief( $canonical, $research )
	. "\nARTICLE: " . $encode( $article );

for ( $attempt = 1; ; $attempt++ ) {
	$images = array();
	foreach ( $paths as $kind => $path ) {
		$image = approval_image( $path, $labels[ $kind ] );
		if ( $image ) { $images[ $kind ] = $image; }
	}
	if ( ! $images ) { fwrite( STDERR, "No images to judge.\n" ); exit( 2 ); }

	printf( "\n--- attempt %d of %d ---\nprovider=%s model=%s images=%d prompt=%d chars\n", $attempt, $attempts, $provider, $model, count( $images ), strlen( $prompt ) );
	foreach ( $images as $image ) { printf( "  %-28s %-52s %s KB\n", $image['label'], basename( $image['path'] ), number_format( $image['bytes'] / 1024, 1 ) ); }

	$result = lab_call_judge( $provider, $model, $prompt, array_values( $images ), (int) ( $settings['approval_max_output_tokens'] ?? 6000 ) );
	if ( isset( $result['error'] ) ) { fwrite( STDERR, 'Approval error after ' . $result['seconds'] . "s: " . $result['error'] . "\n" ); exit( 1 ); }

	$verdict = MSRWA_Json::decode( $result['text'] );
	$cost = lab_price( $provider, $model, $result['usage'] );
	$spent += (float) $cost;
	$checks = lab_score_approval( $verdict, count( $images ), (int) ( $settings['facebook_collage_steps'] ?? 6 ) );
	$history[] = array( 'attempt' => $attempt, 'approved' => ! empty( $verdict['approved'] ), 'judge_cost_usd' => $cost, 'seconds' => $result['seconds'], 'images' => array_map( static function ( $image ) { return basename( $image['path'] ); }, $images ) );

	// A verdict that fails its own structural contract is not a refusal, it is a
	// non-answer: gpt-5.6-luna closed the root object early and left the image
	// verdicts outside it, which reads as "refused, no findings". Ask again
	// rather than regenerate images against a decision nobody made.
	$structurally_sound = ! empty( $checks['valid JSON']['pass'] ) && ! empty( $checks['a verdict per artifact']['pass'] ) && ! empty( $checks['a refusal is justified']['pass'] );
	if ( ! $structurally_sound ) {
		if ( $attempt >= $attempts ) { break; }
		$kept = count( array_filter( $checks, static function ( $check ) { return ! empty( $check['pass'] ); } ) );
		printf( "\nthe verdict is malformed (%d/%d contracts); asking again without touching the images\n", $kept, count( $checks ) );
		continue;
	}

	$retry = lab_images_to_retry( $verdict );
	if ( ! $retry || $attempt >= $attempts ) { break; }

	printf( "\nrefused; regenerating: %s\n", implode( ', ', $retry ) );
	foreach ( $retry as $kind ) {
		$findings = lab_findings_for( $verdict, $kind . '_image' );
		if ( 'facebook' === $kind ) { $findings = array_merge( $findings, lab_findings_for( $verdict, 'consistency' ) ); }
		$regenerated = lab_regenerate_image( $kind, $brief, $options, $findings, $settings );
		printf( "  %-10s %s  %ss  %s\n", $kind, basename( $regenerated['path'] ), $regenerated['seconds'], null === $regenerated['cost'] ? 'unknown rate' : sprintf( '$%.4f', $regenerated['cost'] ) );
		$paths[ $kind ] = $regenerated['path'];
		$spent += (float) $regenerated['cost'];
	}
}

printf( "\n%-20s %d of %d\n", 'attempts used', count( $history ), $attempts );
printf( "%-20s %s\n", 'time (last call)', $result['seconds'] . 's' );
printf( "%-20s in %d / out %d\n", 'tokens', $result['usage']['input_tokens'] ?? 0, $result['usage']['output_tokens'] ?? 0 );
printf( "%-20s %s\n", 'cost (last judge)', null === $cost ? 'unknown rate' : sprintf( '$%.4f', $cost ) );
printf( "%-20s %s\n", 'cost until accepted', sprintf( '$%.4f', $spent ) );
printf( "%-20s %s\n\n", 'response status', (string) ( $result['status'] ?? '' ) );

echo "scorecard\n";
$passed = 0;
foreach ( $checks as $label => $check ) {
	printf( "  %s %-32s %s\n", $check['pass'] ? '✓' : '✗', $label, $check['detail'] );
	$passed += $check['pass'] ? 1 : 0;
}
printf( "\n%s  (%d/%d checks)\n", $passed === count( $checks ) ? 'PASS' : 'FAIL', $passed, count( $checks ) );

if ( is_array( $verdict ) ) {
	printf( "\nverdict: %s\n", ! empty( $verdict['approved'] ) ? 'APPROVED' : 'REFUSED' );
	foreach ( (array) ( $verdict['findings'] ?? array() ) as $finding ) {
		printf( "  [%s] %s — %s\n", strtoupper( (string) ( $finding['severity'] ?? '?' ) ), (string) ( $finding['target'] ?? '?' ), (string) ( $finding['reason'] ?? '' ) );
	}
}

$path = __DIR__ . '/runs/' . preg_replace( '/[^a-z0-9-]+/i', '-', (string) ( $options['brief'] ?? 'tarte-pommes' ) ) . '-final_approval-' . $provider . '-' . $tier . '-' . gmdate( 'Ymd-His' ) . '.json';
file_put_contents( $path, json_encode( array(
	'step' => 'final_approval', 'provider' => $provider, 'tier' => $tier, 'model' => $model,
	'seconds' => $result['seconds'], 'usage' => $result['usage'], 'cost_usd' => $cost, 'total_cost_usd' => round( $spent, 6 ), 'attempts' => $history, 'status' => $result['status'] ?? '',
	'images' => array_map( static function ( $image ) { return array( 'label' => $image['label'], 'path' => $image['path'], 'bytes' => $image['bytes'] ); }, array_values( $images ) ),
	'scores' => array( 'checks' => $checks, 'passed' => $passed, 'total' => count( $checks ), 'pass' => $passed === count( $checks ) ),
	'output' => $result['text'],
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
echo "saved {$path}\n";
