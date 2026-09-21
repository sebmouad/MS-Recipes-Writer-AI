<?php
/**
 * Image lab — generate a featured or Facebook image from the canonical recipe
 * and shared research package before the plugin depends on the prompt.
 *
 *   php tools/image-lab.php featured --research=tools/runs/research-....json
 *   php tools/image-lab.php facebook --research=... --quality=medium
 */
require __DIR__ . '/lib/steps.php';
require __DIR__ . '/lib/providers.php';
require __DIR__ . '/lib/pricing.php';

$argv = $_SERVER['argv'];
$kind = $argv[1] ?? 'featured';
$options = array();
foreach ( array_slice( $argv, 2 ) as $argument ) {
	if ( preg_match( '/^--([a-z-]+)=(.*)$/', $argument, $match ) ) { $options[ $match[1] ] = $match[2]; }
}
if ( ! in_array( $kind, array( 'featured', 'facebook' ), true ) ) { fwrite( STDERR, "Usage: php tools/image-lab.php <featured|facebook> --research=<research run> [--canonical=<canonical run>]\n" ); exit( 2 ); }

$brief = lab_brief( $options['brief'] ?? 'tarte-pommes' );
$research = lab_research_package( $brief, $options );
if ( empty( $research ) ) { fwrite( STDERR, "Pass --research=<a saved research run> or use a fixture containing research.\n" ); exit( 2 ); }

$prompt = lab_image_prompt( $kind, $brief, $options );

$settings = lab_settings();
$size = MSRWA_Images::native_size( 'featured' === $kind ? $settings['featured_ratio'] : $settings['facebook_ratio'], 'featured' === $kind ? '1024x1024' : '1024x1536' );
$quality = $options['quality'] ?? MSRWA_Images::quality( $settings, $kind );
$model = $options['model'] ?? 'gpt-image-2.5-flare';
$format = $options['format'] ?? 'webp';
$runs_directory = __DIR__ . '/runs';
if ( ! is_dir( $runs_directory ) && ! mkdir( $runs_directory, 0775, true ) ) { fwrite( STDERR, "Could not create {$runs_directory}.\n" ); exit( 1 ); }
$brief_name = preg_replace( '/[^a-z0-9-]+/i', '-', (string) ( $options['brief'] ?? 'tarte-pommes' ) );
$destination = __DIR__ . '/runs/' . $brief_name . '-' . $kind . '-' . gmdate( 'Ymd-His' ) . '.' . $format;

printf( "kind=%s model=%s size=%s quality=%s prompt=%d chars\n", $kind, $model, $size, $quality, strlen( $prompt ) );
$result = lab_image( $prompt, $model, $size, $quality, $format, $destination );
if ( isset( $result['error'] ) ) { fwrite( STDERR, 'Image error after ' . $result['seconds'] . "s: " . $result['error'] . "\n" ); exit( 1 ); }

$image_tokens = (int) ( $result['usage']['output_tokens'] ?? 0 );
$cost = lab_price( 'openai', $model, $result['usage'] );
$run_path = preg_replace( '/\.[a-z0-9]+$/i', '.json', $destination );
$run = array(
	'step' => $kind . '_image',
	'provider' => 'openai',
	'model' => $model,
	'size' => $size,
	'quality' => $quality,
	'format' => $format,
	'seconds' => $result['seconds'],
	'usage' => $result['usage'],
	'cost_usd' => $cost,
	'bytes' => $result['bytes'],
	'image_path' => $result['path'],
	'prompt' => $prompt,
);
file_put_contents( $run_path, json_encode( $run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
printf( "%-20s %s\n", 'time', $result['seconds'] . 's' );
printf( "%-20s %s\n", 'file', $result['path'] );
printf( "%-20s %s\n", 'run', $run_path );
printf( "%-20s %s KB\n", 'weight', number_format( $result['bytes'] / 1024, 1 ) );
printf( "%-20s %d\n", 'image tokens', $image_tokens );
printf( "%-20s %s\n", 'cost', null === $cost ? 'unknown rate' : sprintf( '$%.4f', $cost ) );
