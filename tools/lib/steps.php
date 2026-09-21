<?php
/**
 * Step definitions for the prompt lab: how each step's input is assembled and
 * what its answer must satisfy. The scoring reuses the plugin's own quality
 * gate, so a prompt that passes here passes in production for the same reasons.
 */

// The engine is the behaviour; this file is only the lab's way of reaching it.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' ); }
require_once dirname( __DIR__, 2 ) . '/includes/class-msrwa-json.php';
require_once dirname( __DIR__, 2 ) . '/includes/engine/load.php';

/** Loads plugin classes with the same stubs the offline tests use. */
function lab_boot() {
	static $booted = false;
	if ( $booted ) { return; }
	$booted = true;
	require_once dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
	require_once dirname( __DIR__, 2 ) . '/includes/engine/load.php';
	foreach ( array( 'recipe', 'quality', 'catalog', 'images', 'prompt', 'json', 'cost' ) as $class ) {
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
	// The engine runs on exactly what ships, so the lab hands it over rather than
	// letting it guess from a stubbed class.
	MSRWA_Engine_Input::use_settings( $settings );
	return $settings;
}

/*
 * Everything below reaches the engine. The lab owns only what is the lab's:
 * reading a fixture or a saved run off disk, and naming what it found.
 */

/*
 * Reading a saved run off disk is the lab's business: the engine is handed data
 * and never a path, which is what lets the plugin call the same code.
 */
function lab_json_file( $file, $what, $artifact = '' ) {
	if ( ! file_exists( $file ) ) { fwrite( STDERR, "No such {$what}: {$file}\n" ); exit( 2 ); }
	$data = json_decode( file_get_contents( $file ), true );
	if ( ! is_array( $data ) ) { fwrite( STDERR, "That {$what} is not valid JSON: {$file}\n" ); exit( 2 ); }

	// A run saved by this lab is a MSRWA_Result: the artifacts sit under
	// `artifacts`. Passing the whole envelope on as the artifact is how
	// --canonical=<run>.json came to feed the recipe step a document containing
	// ok, steps, totals and events instead of a recipe.
	if ( isset( $data['artifacts'] ) && is_array( $data['artifacts'] ) ) {
		$name = '' !== $artifact ? $artifact : $what;
		if ( ! isset( $data['artifacts'][ $name ] ) || ! is_array( $data['artifacts'][ $name ] ) ) {
			fwrite( STDERR, "That run carries no {$name} artifact: {$file}\n" );
			exit( 2 );
		}
		return $data['artifacts'][ $name ];
	}

	// An older single-step run kept its answer as a JSON string under `output`.
	if ( isset( $data['output'] ) && is_string( $data['output'] ) ) {
		$decoded = MSRWA_Json::decode( $data['output'] );
		if ( ! is_array( $decoded ) ) { fwrite( STDERR, "That {$what} is not valid JSON: {$file}\n" ); exit( 2 ); }
		return $decoded;
	}
	return $data;
}

function lab_brief( $name ) { return lab_json_file( dirname( __DIR__ ) . '/fixtures/' . basename( $name ) . '.json', 'brief' ); }

function lab_research_package( $brief, $options = array() ) {
	$file = (string) ( $options['research'] ?? '' );
	return '' === $file ? MSRWA_Engine_Input::research_package( $brief ) : lab_json_file( $file, 'research package', 'research' );
}

function lab_canonical_recipe( $brief, $options = array() ) {
	$file = (string) ( $options['canonical'] ?? '' );
	return '' === $file ? MSRWA_Engine_Input::canonical_recipe( $brief ) : lab_json_file( $file, 'canonical recipe', 'canonical' );
}

/**
 * The article the review, fact check and proofreading steps are measured on.
 * One fixed article for every provider and tier, so the comparison is fair.
 */
function lab_article_under_test( $options ) {
	static $article = null;
	if ( null !== $article ) { return $article; }
	$file = (string) ( $options['article'] ?? '' );
	if ( '' === $file ) {
		$candidates = glob( dirname( __DIR__ ) . '/runs/article-*.json' );
		sort( $candidates );
		$file = $candidates ? $candidates[0] : '';
	}
	if ( '' === $file ) { fwrite( STDERR, "No article to measure against. Run the article step first, or pass --article=<run.json>.\n" ); exit( 2 ); }
	$article = lab_json_file( $file, 'article', 'article' );
	return $article;
}

/** The brief with everything the flags point at resolved into it, as the engine reads it. */
function lab_working_brief( $step, $brief, $options = array() ) {
	$brief['research'] = lab_research_package( $brief, $options );
	$brief['canonical'] = lab_canonical_recipe( $brief, $options );
	if ( in_array( $step, array( 'review', 'fact_check', 'proofread', 'final_approval' ), true ) ) {
		$brief['article'] = lab_article_under_test( $options );
	}
	$feedback = (string) ( $options['feedback'] ?? '' );
	if ( '' !== $feedback ) { $brief['feedback'] = lab_json_file( $feedback, 'review feedback' ); }
	return $brief;
}
function lab_research_for_text( $research ) { return MSRWA_Engine_Input::research_for_text( $research ); }
function lab_image_prompt( $kind, $brief, $options = array(), $findings = array() ) {
	$prompt = MSRWA_Engine_Input::image_prompt( $kind, lab_working_brief( $kind . '_image', $brief, $options ), $options, $findings );
	// The provider refuses anything over 32000 characters, and did once the
	// research package grew. Fail here, where the cause is visible, not there.
	if ( strlen( $prompt ) > 30000 ) {
		fwrite( STDERR, 'The ' . $kind . ' image prompt is ' . strlen( $prompt ) . " characters; the provider refuses anything over 32000. Trim what research_for_image() forwards.\n" );
		exit( 1 );
	}
	return $prompt;
}
function lab_build_input( $step, $prompt, $brief, $options ) { return MSRWA_Engine_Input::build( $step, $prompt, lab_working_brief( $step, $brief, $options ), $options ); }
function lab_score( $step, $text, $brief, $options = array() ) { return MSRWA_Engine_Score::step( $step, $text, lab_working_brief( $step, $brief, $options ) ); }
function lab_score_approval( $verdict, $images, $panels ) { return MSRWA_Engine_Score::approval( $verdict, $images, $panels ); }
function lab_findings_for( $verdict, $target ) { return MSRWA_Engine_Score::findings_for( $verdict, $target ); }
function lab_images_to_retry( $verdict ) { return MSRWA_Engine_Score::images_to_retry( $verdict ); }
