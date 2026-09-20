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
 *       [--brief=tarte-pommes] [--provider=openai] [--tier=medium]
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
$images = array_values( array_filter( array(
	approval_image( $options['featured'] ?? '', 'featured, ' . MSRWA_Images::native_size( $settings['featured_ratio'], '1024x1024' ) ),
	approval_image( $options['facebook'] ?? '', 'facebook collage, ' . MSRWA_Images::native_size( $settings['facebook_ratio'], '1024x1536' ) ),
) ) );
if ( ! $images ) { fwrite( STDERR, "Pass --featured= and --facebook= with the generated image files.\n" ); exit( 2 ); }

$encode = static function ( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };
$prompt = MSRWA_Prompt::compile( trim( file_get_contents( __DIR__ . '/prompts/final_approval.tpl.txt' ) ), $settings )
	. "\n\nCANONICAL RECIPE: " . $encode( $canonical )
	. "\nRESEARCH PACKAGE: " . $encode( $research )
	. "\nARTICLE: " . $encode( $article );

printf( "provider=%s model=%s images=%d prompt=%d chars\n", $provider, $model, count( $images ), strlen( $prompt ) );
foreach ( $images as $image ) { printf( "  %-28s %s KB\n", $image['label'], number_format( $image['bytes'] / 1024, 1 ) ); }

$result = lab_call_judge( $provider, $model, $prompt, $images, (int) ( $settings['approval_max_output_tokens'] ?? 6000 ) );
if ( isset( $result['error'] ) ) { fwrite( STDERR, 'Approval error after ' . $result['seconds'] . "s: " . $result['error'] . "\n" ); exit( 1 ); }

$verdict = MSRWA_Json::decode( $result['text'] );
$cost = lab_price( $provider, $model, $result['usage'] );
$checks = lab_score_approval( $verdict, count( $images ), (int) ( $settings['facebook_collage_steps'] ?? 6 ) );

printf( "\n%-20s %s\n", 'time', $result['seconds'] . 's' );
printf( "%-20s in %d / out %d\n", 'tokens', $result['usage']['input_tokens'] ?? 0, $result['usage']['output_tokens'] ?? 0 );
printf( "%-20s %s\n", 'cost', null === $cost ? 'unknown rate' : sprintf( '$%.4f', $cost ) );
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
	'seconds' => $result['seconds'], 'usage' => $result['usage'], 'cost_usd' => $cost, 'status' => $result['status'] ?? '',
	'images' => array_map( static function ( $image ) { return array( 'label' => $image['label'], 'path' => $image['path'], 'bytes' => $image['bytes'] ); }, $images ),
	'scores' => array( 'checks' => $checks, 'passed' => $passed, 'total' => count( $checks ), 'pass' => $passed === count( $checks ) ),
	'output' => $result['text'],
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
echo "saved {$path}\n";
