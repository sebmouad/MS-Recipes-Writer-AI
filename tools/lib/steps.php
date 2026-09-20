<?php
/**
 * Step definitions for the prompt lab: how each step's input is assembled and
 * what its answer must satisfy. The scoring reuses the plugin's own quality
 * gate, so a prompt that passes here passes in production for the same reasons.
 */

/** Loads plugin classes with the same stubs the offline tests use. */
function lab_boot() {
	static $booted = false;
	if ( $booted ) { return; }
	$booted = true;
	require_once dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
	foreach ( array( 'recipe', 'quality', 'catalog' ) as $class ) {
		require_once dirname( __DIR__, 2 ) . '/includes/class-msrwa-' . $class . '.php';
	}
}

/** Shipped settings, read straight from the plugin so the lab tests what ships. */
function lab_settings() {
	static $settings = null;
	if ( null !== $settings ) { return $settings; }
	lab_boot();
	if ( ! class_exists( 'MSRWA_Settings_Real', false ) ) {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-msrwa-settings.php' );
		$source = str_replace( 'final class MSRWA_Settings', 'final class MSRWA_Settings_Real', $source );
		$source = preg_replace( '/^<\?php\s*/', '', $source, 1 );
		$source = str_replace( "if ( ! defined( 'ABSPATH' ) ) { exit; }", '', $source );
		eval( $source );
	}
	$settings = MSRWA_Settings_Real::defaults();
	return $settings;
}

function lab_catalog() {
	lab_boot();
	return MSRWA_Catalog::defaults();
}

function lab_steps() {
	$s = lab_settings();
	return array(
		'research' => array(
			'prompts' => array( 'prompt_research' ), 'json' => true, 'max_output' => (int) $s['research_max_output_tokens'],
			'tools' => array( array( 'type' => 'web_search' ) ),
			'expects' => 'JSON with recipe_facts, references, uncertainties',
		),
		'canonical_recipe' => array(
			'prompts' => array( 'prompt_recipe', 'prompt_nutrition' ), 'json' => true, 'max_output' => (int) $s['canonical_max_output_tokens'],
			'expects' => 'a recipe passing MSRWA_Recipe::validate',
		),
		'article' => array(
			'prompts' => array( 'prompt_article', 'prompt_seo' ), 'json' => true, 'max_output' => max( 6000, (int) $s['article_max_output_tokens'] ),
			'expects' => 'an article passing MSRWA_Quality plus the required outline',
		),
		'review' => array(
			'prompts' => array( 'prompt_review' ), 'json' => true, 'max_output' => (int) $s['review_max_output_tokens'],
			'expects' => 'JSON with pass, findings, corrected_artifact',
		),
	);
}

/** The prompt under test: a candidate variant, or the shipped default. */
function lab_prompt( $step, $variant = '' ) {
	if ( '' !== $variant ) {
		$file = __DIR__ . '/../prompts/' . $step . '.' . $variant . '.txt';
		if ( ! file_exists( $file ) ) { fwrite( STDERR, "No such variant: {$file}\n" ); exit( 2 ); }
		return trim( file_get_contents( $file ) );
	}
	$settings = lab_settings();
	$parts = array();
	foreach ( lab_steps()[ $step ]['prompts'] as $key ) { $parts[] = (string) $settings[ $key ]; }
	return trim( implode( "\n", $parts ) );
}

function lab_brief( $name ) {
	$file = __DIR__ . '/../fixtures/' . basename( $name ) . '.json';
	if ( ! file_exists( $file ) ) { fwrite( STDERR, "No such brief: {$file}\n" ); exit( 2 ); }
	$brief = json_decode( file_get_contents( $file ), true );
	if ( ! is_array( $brief ) ) { fwrite( STDERR, "Brief is not valid JSON: {$file}\n" ); exit( 2 ); }
	return $brief;
}

/** Assembles the same input the pipeline would send for this step. */
function lab_build_input( $step, $prompt, $brief, $options ) {
	$settings = lab_settings();
	$encode = static function ( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };
	if ( 'research' === $step ) {
		return $prompt . "\nEntrée éditeur : " . $encode( array( 'title' => $brief['title'], 'source_text' => $brief['text'] ) );
	}
	if ( 'canonical_recipe' === $step ) {
		return $prompt . "\nEntrée : " . $encode( array( 'title' => $brief['title'], 'source_text' => $brief['text'] ) )
			. ' Recherche : ' . $encode( $brief['research'] ?? array() );
	}
	if ( 'article' === $step ) {
		lab_boot();
		return $prompt . MSRWA_Quality::prompt_contract( $settings )
			. "\nRecette canonique : " . $encode( $brief['canonical'] ?? array() )
			. "\nRecherche : " . $encode( $brief['research'] ?? array() );
	}
	if ( 'review' === $step ) {
		return $prompt . "\nRECETTE : " . $encode( $brief['canonical'] ?? array() )
			. "\nARTICLE : " . $encode( $brief['article'] ?? array() );
	}
	return $prompt;
}

/** Scores an answer against the contract of its step. */
function lab_score( $step, $text, $brief ) {
	lab_boot();
	$settings = lab_settings();
	$checks = array();
	$json = json_decode( trim( (string) $text ), true );
	$checks['valid JSON'] = array( 'pass' => is_array( $json ), 'detail' => is_array( $json ) ? count( $json ) . ' keys' : 'not parseable' );
	$json = is_array( $json ) ? $json : array();

	if ( 'research' === $step ) {
		foreach ( array( 'recipe_facts', 'references', 'uncertainties' ) as $key ) {
			$checks[ $key ] = array( 'pass' => isset( $json[ $key ] ) && is_array( $json[ $key ] ), 'detail' => isset( $json[ $key ] ) ? count( (array) $json[ $key ] ) . ' entries' : 'missing' );
		}
		$sourced = 0;
		foreach ( (array) ( $json['references'] ?? array() ) as $reference ) { if ( ! empty( $reference['url'] ) ) { $sourced++; } }
		$checks['references carry a URL'] = array( 'pass' => $sourced > 0, 'detail' => $sourced . ' with a URL' );
	}

	if ( 'canonical_recipe' === $step ) {
		$errors = MSRWA_Recipe::validate( $json );
		$checks['recipe schema'] = array( 'pass' => empty( $errors ), 'detail' => $errors ? implode( ', ', array_keys( $errors ) ) : 'valid' );
		$checks['ingredients'] = array( 'pass' => count( (array) ( $json['ingredients'] ?? array() ) ) >= (int) $settings['quality_min_ingredients'], 'detail' => count( (array) ( $json['ingredients'] ?? array() ) ) . ' items' );
		$checks['steps'] = array( 'pass' => count( (array) ( $json['steps'] ?? array() ) ) >= (int) $settings['quality_min_steps'], 'detail' => count( (array) ( $json['steps'] ?? array() ) ) . ' steps' );
	}

	if ( 'article' === $step ) {
		$quality = MSRWA_Quality::evaluate( $json, $brief['canonical'] ?? array(), $settings );
		$checks['quality gate'] = array( 'pass' => ! empty( $quality['pass'] ), 'detail' => 'score ' . (int) $quality['score'] . '/100' . ( empty( $quality['blockers'] ) ? '' : ', blocked on ' . implode( ', ', $quality['blockers'] ) ) );
		$words = (int) ( $quality['metrics']['words'] ?? 0 );
		$checks['words'] = array( 'pass' => $words >= (int) $settings['quality_min_words'], 'detail' => $words . ' / ' . (int) $settings['quality_min_words'] );
		$checks['headings'] = array( 'pass' => (int) ( $quality['metrics']['headings'] ?? 0 ) >= (int) $settings['quality_min_headings'], 'detail' => (int) ( $quality['metrics']['headings'] ?? 0 ) . ' / ' . (int) $settings['quality_min_headings'] );
		$content = (string) ( $json['content_html'] ?? '' );
		$missing = array();
		foreach ( lab_required_sections() as $section ) {
			if ( ! preg_match( '/<h[23][^>]*>[^<]*' . preg_quote( $section, '/' ) . '/iu', $content ) ) { $missing[] = $section; }
		}
		$checks['required sections'] = array( 'pass' => empty( $missing ), 'detail' => $missing ? 'missing: ' . implode( ', ', $missing ) : 'all present' );
		$checks['no metadata in body'] = array( 'pass' => ! preg_match( '/meta.?description|slug\s*:|mots.?cl(é|e)s\s*:/iu', $content ), 'detail' => 'body carries prose only' );
	}

	if ( 'review' === $step ) {
		$checks['verdict is boolean'] = array( 'pass' => array_key_exists( 'pass', $json ) && is_bool( $json['pass'] ), 'detail' => isset( $json['pass'] ) ? var_export( $json['pass'], true ) : 'missing' );
		$checks['findings are structured'] = array( 'pass' => isset( $json['findings'] ) && is_array( $json['findings'] ), 'detail' => count( (array) ( $json['findings'] ?? array() ) ) . ' findings' );
	}

	$passed = 0;
	foreach ( $checks as $check ) { if ( $check['pass'] ) { $passed++; } }
	return array( 'checks' => $checks, 'passed' => $passed, 'total' => count( $checks ), 'pass' => $passed === count( $checks ) );
}

/** The outline the specification requires an article to carry. */
function lab_required_sections() {
	return array( 'ingrédient', 'substitution', 'matériel', 'préparation', 'erreur', 'conservation', 'variante', 'FAQ' );
}
