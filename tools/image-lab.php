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

$prompt_file = __DIR__ . '/prompts/' . ( 'featured' === $kind ? 'featured_image' : 'facebook_image' ) . '.tpl.txt';
if ( ! file_exists( $prompt_file ) ) { fwrite( STDERR, "No such prompt: {$prompt_file}\n" ); exit( 2 ); }

$canonical = lab_canonical_recipe( $brief, $options );
$ingredients = array();
foreach ( (array) ( $canonical['ingredients'] ?? array() ) as $ingredient ) {
	$ingredients[] = trim( ( $ingredient['quantity'] ?? '' ) . ' ' . ( $ingredient['unit'] ?? '' ) . ' ' . ( $ingredient['name'] ?? '' ) );
}
$prompt = MSRWA_Prompt::compile( trim( file_get_contents( $prompt_file ) ), lab_settings() ) . "\n\n"
	. 'Recipe title: ' . (string) ( $canonical['title'] ?? $brief['title'] ) . "\n"
	. 'Exact ingredients: ' . implode( ', ', $ingredients ) . "\n"
	. 'Research package, including observations from real source images: ' . json_encode( $research, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
if ( 'facebook' === $kind ) {
	$steps = array();
	foreach ( (array) ( $canonical['steps'] ?? array() ) as $index => $step ) { $steps[] = ( $index + 1 ) . '. ' . ( $step['text'] ?? '' ); }
	$prompt .= "The six sections must follow these real steps, in order: " . implode( ' ', $steps ) . "\n";
}

$size = 'featured' === $kind ? '1024x1024' : '1024x1536';
$quality = $options['quality'] ?? 'medium';
$model = $options['model'] ?? 'gpt-image-2.5-flare';
$format = $options['format'] ?? 'webp';
$destination = __DIR__ . '/runs/' . $kind . '-' . gmdate( 'Ymd-His' ) . '.' . $format;

printf( "kind=%s model=%s size=%s quality=%s prompt=%d chars\n", $kind, $model, $size, $quality, strlen( $prompt ) );
$result = lab_image( $prompt, $model, $size, $quality, $format, $destination );
if ( isset( $result['error'] ) ) { fwrite( STDERR, 'Image error after ' . $result['seconds'] . "s: " . $result['error'] . "\n" ); exit( 1 ); }

$image_tokens = (int) ( $result['usage']['output_tokens'] ?? 0 );
$cost = lab_price( 'openai', $model, $result['usage'] );
printf( "%-20s %s\n", 'time', $result['seconds'] . 's' );
printf( "%-20s %s\n", 'file', $result['path'] );
printf( "%-20s %s KB\n", 'weight', number_format( $result['bytes'] / 1024, 1 ) );
printf( "%-20s %d\n", 'image tokens', $image_tokens );
printf( "%-20s %s\n", 'cost', null === $cost ? 'unknown rate' : sprintf( '$%.4f', $cost ) );
