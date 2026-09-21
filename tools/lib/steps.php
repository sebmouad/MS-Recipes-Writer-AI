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

function lab_catalog() {
	lab_boot();
	return MSRWA_Catalog::defaults();
}

function lab_steps() {
	$s = lab_settings();
	return array(
		'research' => array(
			'prompts' => array( 'prompt_research' ), 'file' => 'research.tpl.txt', 'json' => true, 'max_output' => (int) $s['research_max_output_tokens'],
			'tools' => array( array( 'type' => 'web_search' ) ),
			'expects' => 'a sourced research package: ingredients, method and real-image observations',
		),
		'canonical_recipe' => array(
			'prompts' => array( 'prompt_recipe', 'prompt_nutrition' ), 'file' => 'canonical_recipe.tpl.txt', 'json' => true, 'max_output' => (int) $s['canonical_max_output_tokens'],
			'expects' => 'a recipe passing MSRWA_Recipe::validate',
		),
		'article' => array(
			'prompts' => array( 'prompt_article', 'prompt_seo' ), 'file' => 'article.tpl.txt', 'json' => true, 'max_output' => max( (int) $s['article_max_output_tokens'], MSRWA_Cost::output_budget( $s['quality_max_words'] ) ),
			'expects' => 'an article passing MSRWA_Quality plus the required outline',
		),
		'review' => array(
			'prompts' => array( 'prompt_review' ), 'file' => 'review.tpl.txt', 'json' => true, 'max_output' => (int) $s['review_max_output_tokens'],
			'expects' => 'a research-grounded verdict and precise findings',
		),
		'fact_check' => array(
			'prompts' => array( 'prompt_review' ), 'file' => 'fact_check.tpl.txt', 'json' => true, 'max_output' => 4000,
			'expects' => 'only the passages the sources contradict, quoted verbatim',
		),
		'proofread' => array(
			'prompts' => array( 'prompt_correction' ), 'file' => 'proofread.tpl.txt', 'json' => true, 'max_output' => max( (int) $s['article_max_output_tokens'], MSRWA_Cost::output_budget( $s['quality_max_words'] ) ),
			'expects' => 'the same article with its French corrected and every figure untouched',
		),
	);
}

/** The prompt under test: a candidate variant, or the shipped default. */
function lab_prompt( $step, $variant = '', $shipped = false ) {
	if ( '' !== $variant ) {
		$file = MSRWA_Engine_Input::prompt_path( $step . '.' . $variant . '.txt' );
		if ( ! file_exists( $file ) ) { fwrite( STDERR, "No such variant: {$file}\n" ); exit( 2 ); }
		$text = trim( file_get_contents( $file ) );
		// A template is compiled against the settings, exactly as the engine will.
		if ( false !== strpos( $text, '{{' ) ) {
			lab_boot();
			return MSRWA_Prompt::compile( $text, lab_settings() );
		}
		return $text;
	}
	if ( ! $shipped ) {
		$file = MSRWA_Engine_Input::prompt_path( lab_steps()[ $step ]['file'] );
		$text = trim( file_get_contents( $file ) );
		if ( false !== strpos( $text, '{{' ) ) {
			lab_boot();
			return MSRWA_Prompt::compile( $text, lab_settings() );
		}
		return $text;
	}
	$settings = lab_settings();
	$parts = array();
	foreach ( lab_steps()[ $step ]['prompts'] as $key ) { $parts[] = (string) $settings[ $key ]; }
	return trim( implode( "\n", $parts ) );
}


/** Regenerates one image with the judge's findings as corrections. */
function lab_regenerate_image( $kind, $brief, $options, $findings, $settings ) {
	$prompt = lab_image_prompt( $kind, $brief, $options, $findings );
	$size = MSRWA_Images::native_size( 'featured' === $kind ? $settings['featured_ratio'] : $settings['facebook_ratio'], 'featured' === $kind ? '1024x1024' : '1024x1536' );
	$quality = $options['quality'] ?? MSRWA_Images::quality( $settings, $kind );
	$model = $options['image-model'] ?? 'gpt-image-2.5-flare';
	$format = $options['format'] ?? 'webp';
	$name = preg_replace( '/[^a-z0-9-]+/i', '-', (string) ( $options['brief'] ?? 'tarte-pommes' ) );
	$destination = dirname( __DIR__ ) . '/runs/' . $name . '-' . $kind . '-retry-' . gmdate( 'Ymd-His' ) . '.' . $format;
	$result = lab_image( $prompt, $model, $size, $quality, $format, $destination );
	if ( isset( $result['error'] ) ) { fwrite( STDERR, 'Image error after ' . $result['seconds'] . "s: " . $result['error'] . "\n" ); exit( 1 ); }
	$cost = lab_price( 'openai', $model, $result['usage'] );
	// The image lab records what each generation cost; a retried image is a
	// generation too, and without this its cost and tokens existed only on screen.
	$meta = array(
		'step' => $kind . '_image', 'provider' => 'openai', 'model' => $model, 'size' => $size,
		'quality' => $quality, 'format' => $format, 'retry' => true, 'seconds' => $result['seconds'],
		'usage' => $result['usage'], 'cost_usd' => $cost, 'bytes' => $result['bytes'],
		'image_path' => $result['path'], 'corrections' => $findings, 'prompt' => $prompt,
	);
	file_put_contents( preg_replace( '/\.[a-z0-9]+$/i', '.json', $destination ), json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	return array( 'path' => $result['path'], 'seconds' => $result['seconds'], 'cost' => $cost, 'usage' => $result['usage'] );
}


/*
 * The rest of the lab's vocabulary lives in includes/engine now. These wrappers
 * keep the command-line tools reading as before while the engine owns the
 * behaviour, so what the lab measures and what the plugin runs cannot drift.
 */
function lab_editor_brief( $brief ) { return MSRWA_Engine_Input::editor_brief( $brief ); }

/*
 * Reading a saved run off disk is the lab's business: the engine is handed data
 * and never a path, which is what lets the plugin call the same code.
 */
function lab_json_file( $file, $what ) {
	if ( ! file_exists( $file ) ) { fwrite( STDERR, "No such {$what}: {$file}\n" ); exit( 2 ); }
	$data = json_decode( file_get_contents( $file ), true );
	if ( isset( $data['output'] ) && is_string( $data['output'] ) ) { $data = MSRWA_Json::decode( $data['output'] ); }
	if ( ! is_array( $data ) ) { fwrite( STDERR, "That {$what} is not valid JSON: {$file}\n" ); exit( 2 ); }
	return $data;
}

function lab_brief( $name ) { return lab_json_file( dirname( __DIR__ ) . '/fixtures/' . basename( $name ) . '.json', 'brief' ); }

function lab_research_package( $brief, $options = array() ) {
	$file = (string) ( $options['research'] ?? '' );
	return '' === $file ? MSRWA_Engine_Input::research_package( $brief ) : lab_json_file( $file, 'research package' );
}

function lab_canonical_recipe( $brief, $options = array() ) {
	$file = (string) ( $options['canonical'] ?? '' );
	return '' === $file ? MSRWA_Engine_Input::canonical_recipe( $brief ) : lab_json_file( $file, 'canonical recipe' );
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
	$article = lab_json_file( $file, 'article' );
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
function lab_visual_brief( $canonical, $research ) { return MSRWA_Engine_Input::visual_brief( $canonical, $research ); }
function lab_observed_appearance( $research ) { return MSRWA_Engine_Input::observed_appearance( $research ); }
function lab_observation_text( $value ) { return MSRWA_Engine_Input::observation_text( $value ); }
function lab_research_for_text( $research ) { return MSRWA_Engine_Input::research_for_text( $research ); }
function lab_research_for_image( $research ) { return MSRWA_Engine_Input::research_for_image( $research ); }
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
function lab_headings( $html ) { return MSRWA_Engine_Input::headings( $html ); }
function lab_fold( $text ) { return MSRWA_Engine_Score::fold( $text ); }
function lab_score( $step, $text, $brief, $options = array() ) { return MSRWA_Engine_Score::step( $step, $text, lab_working_brief( $step, $brief, $options ) ); }
function lab_score_approval( $verdict, $images, $panels ) { return MSRWA_Engine_Score::approval( $verdict, $images, $panels ); }
function lab_findings_for( $verdict, $target ) { return MSRWA_Engine_Score::findings_for( $verdict, $target ); }
function lab_images_to_retry( $verdict ) { return MSRWA_Engine_Score::images_to_retry( $verdict ); }
