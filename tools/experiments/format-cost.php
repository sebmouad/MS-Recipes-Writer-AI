<?php
/**
 * Measures what an exchange format costs, in tokens the provider actually bills.
 *
 * We choose how to hand data TO a model; the model's answer must stay JSON
 * because we parse it. So this compares renderings of the same research package
 * and reports the provider's own input_tokens, not an estimate.
 *
 * It also reports cached input tokens: a prompt whose long prefix never changes
 * is cached automatically by the provider, and the second identical call shows
 * how much of it was reused.
 *
 *   php tools/format-cost.php --brief=souris-agneau-four [--provider=openai]
 */
define( 'MSRWA_LAB', true );
require __DIR__ . '/lib/steps.php';
require __DIR__ . '/lib/providers.php';
require __DIR__ . '/lib/pricing.php';

$options = array();
foreach ( array_slice( $_SERVER['argv'], 1 ) as $argument ) {
	if ( preg_match( '/^--([a-z-]+)=(.*)$/', $argument, $match ) ) { $options[ $match[1] ] = $match[2]; }
}
$provider = $options['provider'] ?? 'openai';
$model = $options['model'] ?? lab_model( $provider, 'medium' );

$brief = lab_brief( $options['brief'] ?? 'souris-agneau-four' );
$package = lab_research_for_text( lab_research_package( $brief, $options ) );

/** Key: value lines, one per field, lists indented. No braces, quotes or commas. */
function format_lines( $value, $indent = 0 ) {
	$pad = str_repeat( '  ', $indent );
	if ( is_scalar( $value ) || null === $value ) { return ( null === $value ? '' : (string) $value ); }
	$out = '';
	$list = array_keys( (array) $value ) === range( 0, count( (array) $value ) - 1 );
	foreach ( (array) $value as $key => $item ) {
		if ( $list ) {
			if ( is_scalar( $item ) ) { $out .= $pad . '- ' . $item . "\n"; continue; }
			$out .= $pad . "-\n" . format_lines( $item, $indent + 1 );
			continue;
		}
		if ( is_scalar( $item ) || null === $item ) { $out .= $pad . $key . ': ' . format_lines( $item ) . "\n"; continue; }
		$out .= $pad . $key . ":\n" . format_lines( $item, $indent + 1 );
	}
	return $out;
}

/** One record per line, fields separated by | — the densest readable shape. */
function format_pipes( $value ) {
	$out = '';
	foreach ( (array) $value as $key => $item ) {
		if ( is_scalar( $item ) || null === $item ) { $out .= $key . ': ' . $item . "\n"; continue; }
		$list = array_keys( (array) $item ) === range( 0, count( (array) $item ) - 1 );
		if ( ! $list ) { $out .= $key . ': ' . implode( ' | ', array_map( static function ( $k, $v ) { return $k . '=' . ( is_scalar( $v ) ? $v : json_encode( $v, JSON_UNESCAPED_UNICODE ) ); }, array_keys( (array) $item ), array_values( (array) $item ) ) ) . "\n"; continue; }
		$out .= $key . ":\n";
		foreach ( (array) $item as $row ) {
			if ( is_scalar( $row ) ) { $out .= '  ' . $row . "\n"; continue; }
			$fields = array();
			foreach ( (array) $row as $k => $v ) { if ( null !== $v && '' !== $v ) { $fields[] = $k . '=' . ( is_scalar( $v ) ? $v : json_encode( $v, JSON_UNESCAPED_UNICODE ) ); } }
			$out .= '  ' . implode( ' | ', $fields ) . "\n";
		}
	}
	return $out;
}

$renderings = array(
	'json'          => json_encode( $package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
	'json pretty'   => json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
	'indented lines'=> format_lines( $package ),
	'pipe records'  => format_pipes( $package ),
);

$instruction = "Read the research package below and answer with a JSON object holding one key, \"dish\", whose value is the name of the dish.\n\nRESEARCH PACKAGE:\n";
printf( "%-16s %8s %9s %9s %10s %s\n", 'FORMAT', 'CHARS', 'IN-TOK', 'CACHED', 'COST', 'READ BACK' );
foreach ( $renderings as $name => $rendering ) {
	$result = lab_call( $provider, $model, $instruction . $rendering, 200, true );
	if ( isset( $result['error'] ) ) { printf( "%-16s %8d  %s\n", $name, strlen( $rendering ), $result['error'] ); continue; }
	$decoded = MSRWA_Json::decode( $result['text'] ?? '' );
	$cached = (int) ( $result['usage']['cached_input_tokens'] ?? 0 );
	printf( "%-16s %8d %9d %9d %10s %s\n", $name, strlen( $rendering ), (int) $result['usage']['input_tokens'], $cached,
		sprintf( '$%.5f', (float) lab_price( $provider, $model, $result['usage'] ) ),
		is_array( $decoded ) ? mb_substr( (string) ( $decoded['dish'] ?? '?' ), 0, 40 ) : 'unreadable' );
}
