<?php
/**
 * Measures how often a judge returns a usable verdict.
 *
 * The approval step is the last gate, so an answer that fails its own structural
 * contract is worse than a wrong one: it reads as "refused with no findings" and
 * nobody can act on it. This runs the same artifacts past the same prompt N times
 * per provider and counts what comes back.
 *
 *   php tools/judge-stability.php --brief=souris-agneau-four --article=... \
 *       --featured=... --facebook=... --runs=5 [--providers=openai,claude,gemini]
 */
define( 'MSRWA_LAB', true );
require __DIR__ . '/lib/steps.php';
require __DIR__ . '/lib/providers.php';
require __DIR__ . '/lib/pricing.php';

$options = array();
foreach ( array_slice( $_SERVER['argv'], 1 ) as $argument ) {
	if ( preg_match( '/^--([a-z-]+)=(.*)$/', $argument, $match ) ) { $options[ $match[1] ] = $match[2]; }
}
$runs = max( 1, (int) ( $options['runs'] ?? 3 ) );
$tier = $options['tier'] ?? 'medium';
$providers = array_filter( explode( ',', (string) ( $options['providers'] ?? 'openai,claude,gemini' ) ) );

$brief = lab_brief( $options['brief'] ?? 'souris-agneau-four' );
$settings = lab_settings();
$canonical = lab_canonical_recipe( $brief, $options );
$research = lab_research_package( $brief, $options );
$article = lab_article_under_test( $options );
$encode = static function ( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };
$prompt = MSRWA_Prompt::compile( trim( file_get_contents( MSRWA_Engine_Input::prompt_path( 'final_approval.tpl.txt' ) ) ), $settings )
	. "\n\nCANONICAL RECIPE: " . $encode( $canonical )
	. "\nRESEARCH PACKAGE: " . $encode( lab_research_for_text( $research ) )
	. "\n\n" . lab_visual_brief( $canonical, $research )
	. "\nARTICLE: " . $encode( $article );

$images = array();
foreach ( array( 'featured' => '1024x1024', 'facebook' => '1024x1536' ) as $kind => $size ) {
	$path = (string) ( $options[ $kind ] ?? '' );
	if ( '' === $path || ! file_exists( $path ) ) { fwrite( STDERR, "Missing --{$kind}\n" ); exit( 2 ); }
	$types = array( 'webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg' );
	$images[] = array( 'label' => $kind . ', ' . $size, 'mime' => $types[ strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ], 'data' => base64_encode( file_get_contents( $path ) ) );
}

printf( "%d runs per provider, tier=%s, %d images, prompt=%d chars\n\n", $runs, $tier, count( $images ), strlen( $prompt ) );
printf( "%-9s %-26s %6s %6s %9s  %s\n", 'PROVIDER', 'MODEL', 'SOUND', 'APPR', 'COST', 'DETAIL' );

$summary = array();
foreach ( $providers as $provider ) {
	$model = lab_model( $provider, $tier );
	$sound = 0; $approved = 0; $spent = 0.0; $notes = array(); $blocking = array();
	for ( $i = 0; $i < $runs; $i++ ) {
		$result = lab_call_judge( $provider, $model, $prompt, $images, (int) ( $options['max-output'] ?? $settings['approval_max_output_tokens'] ?? 6000 ) );
		if ( isset( $result['error'] ) ) { $notes[] = 'error'; continue; }
		$spent += (float) lab_price( $provider, $model, $result['usage'] );
		$verdict = MSRWA_Json::decode( $result['text'] );
		$checks = lab_score_approval( $verdict, count( $images ), (int) ( $settings['facebook_collage_steps'] ?? 6 ) );
		$ok = ! empty( $checks['valid JSON']['pass'] ) && ! empty( $checks['a verdict per artifact']['pass'] ) && ! empty( $checks['a refusal is justified']['pass'] );
		if ( $ok ) {
			$sound++;
			if ( ! empty( $verdict['approved'] ) ) { $approved++; }
			// What a judge blocks on matters more than whether it blocks: the same
			// artifacts scoring differently is only a problem when the reasons differ.
			foreach ( (array) ( $verdict['findings'] ?? array() ) as $finding ) {
				if ( is_array( $finding ) && 'blocking' === ( $finding['severity'] ?? '' ) ) {
					$blocking[] = ( $finding['target'] ?? '?' ) . ': ' . mb_substr( (string) ( $finding['reason'] ?? '' ), 0, 90 );
				}
			}
		} else { $notes[] = empty( $checks['valid JSON']['pass'] ) ? 'unparseable' : ( empty( $checks['a verdict per artifact']['pass'] ) ? 'partial' : 'unjustified' ); }
	}
	printf( "%-9s %-26s %3d/%-2d %3d/%-2d %9s  %s\n", $provider, $model, $sound, $runs, $approved, $sound, sprintf( '$%.4f', $spent ), $notes ? implode( ', ', array_count_values( $notes ) === array() ? $notes : array_map( static function ( $k, $v ) { return $v . '× ' . $k; }, array_keys( array_count_values( $notes ) ), array_count_values( $notes ) ) ) : 'all sound' );
	if ( $blocking ) {
		echo "          blocking reasons given:\n";
		foreach ( array_count_values( $blocking ) as $reason => $times ) { printf( "            %d× %s\n", $times, $reason ); }
	}
	$summary[ $provider ] = array( 'sound' => $sound, 'approved' => $approved, 'cost' => $spent );
}
