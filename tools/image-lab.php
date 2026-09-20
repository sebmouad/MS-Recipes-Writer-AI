<?php
/**
 * Image lab — generate a featured or Facebook image from a proven prompt and
 * the visual notes an article produced, so the picture can be judged before
 * the plugin depends on the prompt.
 *
 *   php tools/image-lab.php featured --run=tools/runs/article-v4-....json
 *   php tools/image-lab.php facebook --run=... --quality=medium
 */
require __DIR__ . '/lib/api.php';
require __DIR__ . '/lib/steps.php';

$argv = $_SERVER['argv'];
$kind = $argv[1] ?? 'featured';
$options = array();
foreach ( array_slice( $argv, 2 ) as $argument ) {
	if ( preg_match( '/^--([a-z-]+)=(.*)$/', $argument, $match ) ) { $options[ $match[1] ] = $match[2]; }
}
if ( ! in_array( $kind, array( 'featured', 'facebook' ), true ) ) { fwrite( STDERR, "Usage: php tools/image-lab.php <featured|facebook> --run=<article run json>\n" ); exit( 2 ); }

$run_file = $options['run'] ?? '';
if ( ! file_exists( $run_file ) ) { fwrite( STDERR, "Pass --run=<a saved article run> so the image follows the article.\n" ); exit( 2 ); }
$run = json_decode( file_get_contents( $run_file ), true );
$article = json_decode( (string) ( $run['output'] ?? '' ), true );
$brief = lab_brief( $options['brief'] ?? 'tarte-pommes' );

$prompt_file = __DIR__ . '/prompts/' . ( 'featured' === $kind ? 'featured_image' : 'facebook_image' ) . '.' . ( $options['variant'] ?? 'v1' ) . '.txt';
if ( ! file_exists( $prompt_file ) ) { fwrite( STDERR, "No such prompt: {$prompt_file}\n" ); exit( 2 ); }

$canonical = $brief['canonical'] ?? array();
$ingredients = array();
foreach ( (array) ( $canonical['ingredients'] ?? array() ) as $ingredient ) {
	$ingredients[] = trim( ( $ingredient['quantity'] ?? '' ) . ' ' . ( $ingredient['unit'] ?? '' ) . ' ' . ( $ingredient['name'] ?? '' ) );
}
$prompt = trim( file_get_contents( $prompt_file ) ) . "\n\n"
	. 'Recipe title: ' . (string) ( $canonical['title'] ?? $brief['title'] ) . "\n"
	. 'Exact ingredients: ' . implode( ', ', $ingredients ) . "\n"
	. 'Final visual notes from the completed article, highest priority: ' . (string) ( $article['visual_final_notes'] ?? '' ) . "\n";
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

$catalog = lab_catalog();
$rate = $catalog['openai'][ $model ]['output'] ?? null;
$image_tokens = (int) ( $result['usage']['output_tokens'] ?? 0 );
$cost = null !== $rate && $image_tokens ? $image_tokens * (float) $rate / 1000000 : null;
printf( "%-20s %s\n", 'time', $result['seconds'] . 's' );
printf( "%-20s %s\n", 'file', $result['path'] );
printf( "%-20s %s KB\n", 'weight', number_format( $result['bytes'] / 1024, 1 ) );
printf( "%-20s %d\n", 'image tokens', $image_tokens );
printf( "%-20s %s\n", 'cost', null === $cost ? 'unknown rate' : sprintf( '$%.4f', $cost ) );
