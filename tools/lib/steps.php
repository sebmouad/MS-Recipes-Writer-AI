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
		$file = __DIR__ . '/../prompts/' . $step . '.' . $variant . '.txt';
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
		$file = __DIR__ . '/../prompts/' . lab_steps()[ $step ]['file'];
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

/** Normalizes title-, article- and image-led editor briefs into one contract. */
function lab_editor_brief( $brief ) {
	// A malformed editor_input used to be forwarded as-is. The research model then
	// answered, truthfully, that it had been given no dish to research, and the run
	// still cost money and scored seven of ten.
	if ( isset( $brief['editor_input'] ) && is_array( $brief['editor_input'] ) ) {
		$editor = $brief['editor_input'];
		if ( '' === trim( (string) ( $editor['title'] ?? '' ) ) && '' === trim( (string) ( $editor['text'] ?? '' ) ) ) {
			fwrite( STDERR, "The brief's editor_input carries neither a title nor a text; there is nothing to research.\n" );
			exit( 2 );
		}
		return $editor;
	}
	return array(
		'type' => 'article',
		'title' => (string) ( $brief['title'] ?? '' ),
		'text' => (string) ( $brief['text'] ?? '' ),
		'images' => array_values( (array) ( $brief['images'] ?? array() ) ),
	);
}

/** Loads a saved research run or the fixture package. Downstream stages share it unchanged. */
function lab_research_package( $brief, $options = array() ) {
	lab_boot();
	$file = (string) ( $options['research'] ?? '' );
	if ( '' === $file ) { return is_array( $brief['research'] ?? null ) ? $brief['research'] : array(); }
	if ( ! file_exists( $file ) ) { fwrite( STDERR, "No such research package: {$file}\n" ); exit( 2 ); }
	$data = json_decode( file_get_contents( $file ), true );
	if ( isset( $data['output'] ) && is_string( $data['output'] ) ) { $data = MSRWA_Json::decode( $data['output'] ); }
	if ( ! is_array( $data ) ) { fwrite( STDERR, "Research package is not valid JSON: {$file}\n" ); exit( 2 ); }
	return $data;
}

/** Loads the saved canonical step when supplied, otherwise the fixture recipe. */
function lab_canonical_recipe( $brief, $options = array() ) {
	lab_boot();
	$file = (string) ( $options['canonical'] ?? '' );
	if ( '' === $file ) { return is_array( $brief['canonical'] ?? null ) ? $brief['canonical'] : array(); }
	if ( ! file_exists( $file ) ) { fwrite( STDERR, "No such canonical recipe: {$file}\n" ); exit( 2 ); }
	$data = json_decode( file_get_contents( $file ), true );
	if ( isset( $data['output'] ) && is_string( $data['output'] ) ) { $data = MSRWA_Json::decode( $data['output'] ); }
	if ( ! is_array( $data ) ) { fwrite( STDERR, "Canonical recipe is not valid JSON: {$file}\n" ); exit( 2 ); }
	return $data;
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
	$editor = lab_editor_brief( $brief );
	$research = lab_research_package( $brief, $options );
	$text_research = lab_research_for_text( $research );
	$canonical = lab_canonical_recipe( $brief, $options );
	if ( 'research' === $step ) {
		return $prompt . "\nEDITOR BRIEF: " . $encode( $editor );
	}
	if ( 'canonical_recipe' === $step ) {
		return $prompt . "\nEDITOR BRIEF: " . $encode( $editor ) . "\nRESEARCH PACKAGE: " . $encode( $text_research )
			. "\n" . lab_observed_appearance( $research );
	}
	if ( 'article' === $step ) {
		lab_boot();
		$feedback = array();
		$feedback_file = (string) ( $options['feedback'] ?? '' );
		if ( '' !== $feedback_file && file_exists( $feedback_file ) ) {
			$saved = json_decode( file_get_contents( $feedback_file ), true );
			$feedback = MSRWA_Json::decode( (string) ( $saved['output'] ?? '' ) );
			$feedback = is_array( $feedback ) ? $feedback : array();
		}
		return $prompt . MSRWA_Quality::prompt_contract( $settings )
			. "\nRecette canonique : " . $encode( $canonical )
			. "\nRESEARCH PACKAGE: " . $encode( $text_research )
			. "\n" . lab_visual_brief( $canonical, $research )
			. ( $feedback ? "\nREVIEW FINDINGS TO CORRECT IN THE COMPLETE RETURNED ARTICLE: " . $encode( $feedback ) : '' );
	}
	if ( 'review' === $step ) {
		return $prompt . "\nCANONICAL RECIPE: " . $encode( $canonical )
			. "\nRESEARCH PACKAGE: " . $encode( $text_research )
			. "\nARTICLE: " . $encode( lab_article_under_test( $options ) );
	}
	if ( 'fact_check' === $step ) {
		return $prompt . "\nRESEARCH PACKAGE: " . $encode( $text_research )
			. "\nCANONICAL RECIPE: " . $encode( $canonical )
			. "\nARTICLE: " . $encode( lab_article_under_test( $options )['content_html'] ?? '' );
	}
	if ( 'proofread' === $step ) {
		return $prompt . "\nRESEARCH PACKAGE: " . $encode( $research )
			. "\nCANONICAL RECIPE: " . $encode( $canonical )
			. "\nARTICLE TO CORRECT: " . $encode( lab_article_under_test( $options )['content_html'] ?? '' );
	}
	return $prompt;
}

/**
 * Scores an approval verdict. The risk here is not a missing key but a judge that
 * waves everything through, so the checks demand a verdict per artifact, a panel
 * count it actually made, and findings precise enough to act on: a blocking
 * finding that refuses to approve, and a quote on every article finding, since a
 * paraphrase cannot be applied to the text.
 */
function lab_score_approval( $verdict, $image_count, $expected_panels ) {
	$checks = array();
	$readable = is_array( $verdict ) && ! empty( $verdict );
	$checks['valid JSON'] = array( 'pass' => $readable, 'detail' => $readable ? count( $verdict ) . ' keys' : 'not parseable' );
	$verdict = $readable ? $verdict : array();

	$checks['approved is a boolean'] = array( 'pass' => isset( $verdict['approved'] ) && is_bool( $verdict['approved'] ), 'detail' => isset( $verdict['approved'] ) ? var_export( $verdict['approved'], true ) : 'missing' );

	$verdicts = array( 'good', 'reservations', 'bad' );
	$missing = array();
	foreach ( array( 'article', 'featured_image', 'facebook_image', 'consistency' ) as $target ) {
		$value = (string) ( $verdict[ $target ]['verdict'] ?? '' );
		if ( ! in_array( $value, $verdicts, true ) ) { $missing[] = $target; }
	}
	$checks['a verdict per artifact'] = array( 'pass' => empty( $missing ), 'detail' => $missing ? 'missing or invalid: ' . implode( ', ', $missing ) : '4 verdicts' );

	$realism = array();
	foreach ( array( 'featured_image', 'facebook_image' ) as $target ) {
		if ( ! in_array( (string) ( $verdict[ $target ]['realism'] ?? '' ), $verdicts, true ) ) { $realism[] = $target; }
	}
	$checks['realism judged separately'] = array( 'pass' => empty( $realism ), 'detail' => $realism ? 'missing: ' . implode( ', ', $realism ) : 'both images' );

	// The judge must count the panels it was shown, not repeat the number asked for.
	$panels = $verdict['facebook_image']['panels_counted'] ?? null;
	$checks['collage panels counted'] = array( 'pass' => is_int( $panels ) && $panels > 0, 'detail' => null === $panels ? 'missing' : $panels . ' counted, ' . $expected_panels . ' expected' );

	$findings = array_values( array_filter( (array) ( $verdict['findings'] ?? array() ), 'is_array' ) );
	$blocking = 0;
	$unquoted = 0;
	$bad_target = 0;
	$targets = array( 'article', 'featured_image', 'facebook_image', 'consistency' );
	foreach ( $findings as $finding ) {
		if ( 'blocking' === ( $finding['severity'] ?? '' ) ) { $blocking++; }
		if ( ! in_array( (string) ( $finding['target'] ?? '' ), $targets, true ) ) { $bad_target++; }
		if ( 'article' === ( $finding['target'] ?? '' ) && '' === trim( (string) ( $finding['quote'] ?? '' ) ) ) { $unquoted++; }
		if ( '' === trim( (string) ( $finding['fix'] ?? '' ) ) ) { $unquoted++; }
	}
	$checks['findings are addressed'] = array( 'pass' => $readable && 0 === $bad_target, 'detail' => $bad_target ? $bad_target . ' with no valid target' : count( $findings ) . ' findings' );
	$checks['findings are actionable'] = array( 'pass' => $readable && 0 === $unquoted, 'detail' => $unquoted ? $unquoted . ' without a quote or a fix' : 'every finding carries a fix' );

	// A refusal nobody can act on is not a verdict.
	$justified = $readable && ( ! empty( $verdict['approved'] ) || count( $findings ) > 0 );
	$checks['a refusal is justified'] = array( 'pass' => $justified, 'detail' => $justified ? 'ok' : 'refused with no findings' );

	// A blocking finding and an approval cannot both stand.
	$coherent = $readable && ! ( $blocking > 0 && ! empty( $verdict['approved'] ) );
	$checks['approval matches findings'] = array( 'pass' => $coherent, 'detail' => $coherent ? ( $blocking . ' blocking, approved ' . var_export( ! empty( $verdict['approved'] ), true ) ) : 'approved despite ' . $blocking . ' blocking findings' );

	$checks['uncertainties reported'] = array( 'pass' => isset( $verdict['uncertainties'] ) && is_array( $verdict['uncertainties'] ), 'detail' => isset( $verdict['uncertainties'] ) ? count( (array) $verdict['uncertainties'] ) . ' entries' : 'missing' );

	return $checks;
}

/**
 * One observation field as prose. The vision model returns some of these as a
 * list of sentences and some as a sentence; casting a list to string yields the
 * word "Array", which then travels into an image prompt as the description of
 * the dish.
 */
function lab_observation_text( $value ) {
	if ( is_array( $value ) ) {
		$parts = array();
		foreach ( $value as $item ) { if ( is_scalar( $item ) ) { $parts[] = trim( (string) $item ); } }
		return trim( implode( ' ', array_filter( $parts ) ) );
	}
	return is_scalar( $value ) ? trim( (string) $value ) : '';
}

/**
 * Turns the recipe and the research into constraints an image model can obey,
 * instead of the JSON dump it used to receive.
 *
 * Every line here answers a failure the approval step actually raised: two
 * pastry rolls where the recipe says one, seven apples where it says six, a
 * cooling rack and a serving board that appear in no step, and a final panel
 * browner than the photographs of the real dish.
 */
function lab_visual_brief( $canonical, $research ) {
	$countable = array( 'pièce', 'pièces', 'piece', 'pieces', 'rouleau', 'rouleaux', 'gousse', 'gousses', 'tranche', 'tranches', 'feuille', 'feuilles', 'branche', 'branches', 'oeuf', 'œuf', 'unité', 'unités', '' );
	$counts = array();
	$measured = array();
	foreach ( (array) ( $canonical['ingredients'] ?? array() ) as $ingredient ) {
		if ( ! is_array( $ingredient ) ) { continue; }
		$name = trim( (string) ( $ingredient['name'] ?? '' ) );
		$quantity = trim( (string) ( $ingredient['quantity'] ?? '' ) );
		$unit = trim( (string) ( $ingredient['unit'] ?? '' ) );
		if ( '' === $name ) { continue; }
		if ( '' !== $quantity && is_numeric( str_replace( ',', '.', $quantity ) ) && in_array( mb_strtolower( $unit ), $countable, true ) ) {
			$counts[] = $name . ' — exactly ' . $quantity . ( '' === $unit ? '' : ' ' . $unit );
		} else {
			$measured[] = trim( $quantity . ' ' . $unit . ' ' . $name );
		}
	}

	$lines = array( 'VISUAL BRIEF — derived from this recipe and binding. Each line below exists because a real image failed on it.' );
	$lines[] = '• NO GARNISH THAT IS NOT AN INGREDIENT. The most common defect in these images, across every dish tried, is a sprig of herb laid on the finished plate — rosemary, thyme, parsley, coriander, a bay leaf — because that is how this kind of dish is usually photographed. If the ingredient list below does not contain it, it does not go in the picture, in any panel, however conventional it looks. The same applies to a citrus wedge, a grind of visible spice, a drizzle, a dusting or a scattering of seeds. Serve the dish bare rather than garnish it with something the cook was never told to buy.';
	if ( $counts ) {
		$lines[] = '• Countable ingredients, exact numbers: ' . implode( '; ', $counts ) . '. Where a panel lays the ingredients out — the mise en place — show exactly these numbers, not one more pack, roll, fruit or egg "for composition". This binds the ingredient display only. A later panel showing the dish being made or served need not have them all in shot, and a few of the same fruit resting in the background of a finished shot is styling, not a miscount.';
	}
	if ( $measured ) {
		$lines[] = '• Measured ingredients, no count to respect, show a believable amount: ' . implode( '; ', $measured ) . '.';
	}
	$equipment = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) ( $canonical['equipment'] ?? array() ) ) ) ) );
	if ( $equipment ) {
		$lines[] = '• The cookware this recipe names: ' . implode( ', ', $equipment ) . '. These must be the ones actually used for the steps that need them, and in one colour and material throughout — the same tin in every panel it appears in. Ordinary kitchen things a cook obviously needs to perform a step are fine and expected: a board to peel on, a bowl to mix in, a spoon, a knife, a cloth. What is a defect is a support that changes how the finished dish is presented — a cooling rack, a board or a plate standing in for the serving vessel in the last panel alone, or a second tin of a different colour.';
	}
	$servings = (int) ( $canonical['servings'] ?? 0 );
	$cook = (int) ( $canonical['cook_minutes'] ?? 0 );
	if ( $servings > 0 || $cook > 0 ) {
		$lines[] = '• Scale and doneness: ' . ( $servings > 0 ? 'serves ' . $servings . '. ' : '' ) . ( $cook > 0 ? 'Cooked ' . $cook . ' minutes, so the colour is what that produces — not darker for drama.' : '' );
	}

	$observed = array();
	foreach ( (array) ( $research['visual_observations'] ?? array() ) as $observation ) {
		if ( ! is_array( $observation ) ) { continue; }
		foreach ( array( 'colours', 'textures', 'observable_details', 'composition' ) as $key ) {
			$value = lab_observation_text( $observation[ $key ] ?? '' );
			if ( '' !== $value ) { $observed[] = $value; }
		}
	}
	if ( $observed ) {
		$lines[] = '• How the real dish looks, observed in photographs of it: ' . implode( ' ', array_slice( array_unique( $observed ), 0, 8 ) ) . ' The finished dish must match this. It may not look more cooked, more darkly coloured or more elaborately garnished than these observations describe.';
	}

	// Every image is generated in its own call, so nothing makes them agree unless
	// the same decision is written into both. Three refusals in four came from the
	// featured photograph and the collage's last panel serving the dish differently.
	$lines[] = '• ONE SERVING PRESENTATION, shared by every image of this recipe: ' . lab_serving_presentation( $canonical, $research ) . ' The featured photograph and the last panel of the collage must show the finished dish presented that same way, in the same vessel and at the same degree of colour. They are two photographs of one dish, taken minutes apart.';

	return implode( "\n", $lines ) . "\n";
}

/**
 * What was observed in real photographs, as prose. The canonical recipe is
 * written before a visual brief can be derived from it, so this is the part of
 * the brief that does not depend on the recipe.
 */
function lab_observed_appearance( $research ) {
	$observed = array();
	foreach ( (array) ( $research['visual_observations'] ?? array() ) as $observation ) {
		if ( ! is_array( $observation ) ) { continue; }
		foreach ( array( 'observable_details', 'colours', 'textures', 'composition' ) as $key ) {
			$value = lab_observation_text( $observation[ $key ] ?? '' );
			if ( '' !== $value ) { $observed[] = $value; }
		}
	}
	if ( ! $observed ) { return ''; }
	return "OBSERVED APPEARANCE — taken from real photographs of this dish, not from a description of it: "
		. implode( ' ', array_slice( array_unique( $observed ), 0, 8 ) )
		. " Use it for the signs a cook reads by eye: what the surface does, what a cut reveals, what correctly cooked looks like. It establishes appearance only — never an ingredient, a quantity or a step it cannot show.\n";
}

/**
 * The single way the finished dish is presented, as a decision rather than a
 * description. Describing the dish was not enough: two calls that each read
 * "whole, seen at three quarters" still chose a plate and a tin, and the
 * approval step blocked the pair every time. The vessel has to be named.
 */
function lab_serving_presentation( $canonical, $research ) {
	$text = '';
	foreach ( (array) ( $research['visual_observations'] ?? array() ) as $observation ) {
		if ( ! is_array( $observation ) ) { continue; }
		foreach ( array( 'observable_details', 'composition', 'colours' ) as $key ) { $text .= ' ' . lab_observation_text( $observation[ $key ] ?? '' ); }
	}
	$text .= ' ' . (string) ( $research['visual_reference']['plating'] ?? '' );

	// A vessel the observations actually name wins; otherwise one is chosen here,
	// because leaving it open is what let the two images disagree.
	$vessels = array(
		'assiette' => 'served on a plain ceramic plate, out of any cooking vessel',
		'plat de service' => 'served on a plain serving dish, out of any cooking vessel',
		'plat rond' => 'served on a round serving dish, out of any cooking vessel',
		'moule' => 'presented in its own baking tin, the same tin as the earlier panels',
		'bol' => 'served in a bowl',
		'planche' => 'served on a wooden board',
		'cocotte' => 'served in the cooking pot it was made in',
		'poêle' => 'served in the pan it was made in',
	);
	$decision = '';
	foreach ( $vessels as $needle => $sentence ) {
		if ( false !== mb_stripos( $text, $needle ) ) { $decision = $sentence; break; }
	}
	if ( '' === $decision ) { $decision = 'removed from whatever it was cooked in and served whole on a plain ceramic plate'; }

	$appearance = trim( preg_replace( '/\s+/', ' ', $text ) );
	return $decision . ', whole and centred, photographed from a three-quarter angle at table height, never from directly above'
		. ( '' === $appearance ? '' : ', and at exactly the colour the observations record: ' . mb_substr( $appearance, 0, 240 ) );
}

/**
 * The prompt one image generation receives. Shared by the image lab and the
 * approval retry loop so a regenerated image is built exactly like a first one,
 * plus the defects the approval step asked to correct.
 *
 * $findings is the list the judge returned for this image; passing it is what
 * makes a retry a correction rather than another roll of the dice.
 */
function lab_image_prompt( $kind, $brief, $options = array(), $findings = array() ) {
	lab_boot();
	$settings = lab_settings();
	$encode = static function ( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };
	$research = lab_research_package( $brief, $options );
	$canonical = lab_canonical_recipe( $brief, $options );
	$ingredients = array();
	foreach ( (array) ( $canonical['ingredients'] ?? array() ) as $ingredient ) {
		$ingredients[] = trim( ( $ingredient['quantity'] ?? '' ) . ' ' . ( $ingredient['unit'] ?? '' ) . ' ' . ( $ingredient['name'] ?? '' ) );
	}
	$file = dirname( __DIR__ ) . '/prompts/' . ( 'featured' === $kind ? 'featured_image' : 'facebook_image' ) . '.tpl.txt';
	$prompt = MSRWA_Prompt::compile( trim( file_get_contents( $file ) ), $settings ) . "\n\n"
		. 'Recipe title: ' . (string) ( $canonical['title'] ?? $brief['title'] ) . "\n"
		. 'Exact ingredients: ' . implode( ', ', $ingredients ) . "\n\n"
		. lab_visual_brief( $canonical, $research ) . "\n"
		. 'What the real photographs showed, for anything the brief above does not cover: ' . $encode( lab_research_for_image( $research ) ) . "\n";

	if ( 'facebook' === $kind ) {
		$all_steps = array_values( (array) ( $canonical['steps'] ?? array() ) );
		$steps = array();
		$selected = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $options['steps'] ?? '' ) ) ) ) );
		if ( $selected ) {
			if ( 6 !== count( $selected ) ) { fwrite( STDERR, "Facebook --steps must contain exactly six comma-separated canonical step numbers.\n" ); exit( 2 ); }
			foreach ( $selected as $number ) {
				if ( isset( $all_steps[ $number - 1 ] ) ) { $steps[] = count( $steps ) + 1 . '. ' . ( $all_steps[ $number - 1 ]['text'] ?? '' ); }
			}
			if ( 6 !== count( $steps ) ) { fwrite( STDERR, "One or more Facebook --steps numbers do not exist in the canonical recipe.\n" ); exit( 2 ); }
			$prompt .= 'Use these six editor-selected canonical moments, in this order: ' . implode( ' ', $steps ) . "\n";
		} else {
			foreach ( $all_steps as $index => $step ) { $steps[] = ( $index + 1 ) . '. ' . ( $step['text'] ?? '' ); }
			$panels = (int) ( $settings['facebook_collage_steps'] ?? 6 );
			if ( count( $all_steps ) === $panels ) {
				// Nothing to choose: every step is a panel. Leaving the model to select
				// anyway is what produced the ordering failures — it hoisted the batter
				// to panel two, ahead of lining the case, in three runs out of five.
				$prompt .= 'The recipe has exactly ' . $panels . ' steps, so there is nothing to select. Use these, one per panel, in this order: ' . implode( ' ', $steps ) . "\n";
			} else {
				$prompt .= 'Canonical step pool, numbered in the order the recipe performs them: ' . implode( ' ', $steps ) . "\nSelect exactly " . $panels . " visually distinct moments using the storyboard contract, then lay them out in ascending step number; do not sample mechanically or show passive filler.\n";
			}
		}
	}

	// The provider rejects a prompt over 32000 characters, and did so once the
	// research package grew. Fail here, where the cause is visible, not there.
	if ( strlen( $prompt ) > 30000 ) {
		fwrite( STDERR, 'The ' . $kind . " image prompt is " . strlen( $prompt ) . " characters; the provider refuses anything over 32000. Trim what lab_research_for_image() forwards.\n" );
		exit( 1 );
	}

	$findings = array_values( array_filter( (array) $findings, 'is_array' ) );
	if ( $findings ) {
		$prompt .= "\nTHIS IMAGE WAS REFUSED. An independent editor inspected the previous attempt and listed what is wrong with it. Produce the same image with each of these corrected, and change nothing else:\n";
		foreach ( $findings as $index => $finding ) {
			$prompt .= ( $index + 1 ) . '. ' . trim( (string) ( $finding['reason'] ?? '' ) ) . ' — ' . trim( (string) ( $finding['fix'] ?? '' ) ) . "\n";
		}
	}
	return $prompt;
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

/**
 * The part of the research an image model can act on.
 *
 * The whole package used to be sent, and it grew past the provider's 32,000
 * character limit: the collage call was rejected outright. Temperatures, source
 * URLs, food-safety rules and originality notes cannot change a photograph, and
 * the visual brief already distils what can.
 */
function lab_research_for_image( $research ) {
	$research = is_array( $research ) ? $research : array();
	$observations = array();
	foreach ( (array) ( $research['visual_observations'] ?? array() ) as $observation ) {
		if ( ! is_array( $observation ) ) { continue; }
		$observations[] = array(
			'observable_details' => lab_observation_text( $observation['observable_details'] ?? '' ),
			'composition' => lab_observation_text( $observation['composition'] ?? '' ),
			'colours' => lab_observation_text( $observation['colours'] ?? '' ),
			'textures' => lab_observation_text( $observation['textures'] ?? '' ),
		);
	}
	return array(
		'dish_identity' => $research['dish_identity'] ?? array(),
		'visual_observations' => $observations,
	);
}

/**
 * The research a text step can act on.
 *
 * `originality_notes` describes how the research was conducted, `visual_references`
 * are URLs, and `visual_observations` describe a photograph — none of it changes a
 * recipe, a correction or a fact check, and the article receives the appearance
 * distilled in its visual brief instead. Dropping them takes about 6 100 characters
 * off every text call that carries the package.
 */
function lab_research_for_text( $research ) {
	$research = is_array( $research ) ? $research : array();
	foreach ( array( 'originality_notes', 'visual_references', 'visual_observations' ) as $key ) { unset( $research[ $key ] ); }
	return $research;
}

/** The blocking findings the judge raised against one image. */
function lab_findings_for( $verdict, $target ) {
	$out = array();
	foreach ( (array) ( ( is_array( $verdict ) ? $verdict : array() )['findings'] ?? array() ) as $finding ) {
		if ( ! is_array( $finding ) || 'blocking' !== ( $finding['severity'] ?? '' ) ) { continue; }
		if ( $target === ( $finding['target'] ?? '' ) ) { $out[] = $finding; }
	}
	return $out;
}

/**
 * Which images a refusal asks us to regenerate. A consistency finding names no
 * single image, so it is charged to the collage: the featured image is one
 * photograph of the finished dish and the collage is the piece that has to agree
 * with it, and measurement put every consistency break on the collage's side.
 */
function lab_images_to_retry( $verdict ) {
	if ( ! is_array( $verdict ) || ! empty( $verdict['approved'] ) ) { return array(); }
	$retry = array();
	foreach ( array( 'featured' => 'featured_image', 'facebook' => 'facebook_image' ) as $kind => $target ) {
		if ( lab_findings_for( $verdict, $target ) ) { $retry[] = $kind; }
	}
	if ( lab_findings_for( $verdict, 'consistency' ) && ! in_array( 'facebook', $retry, true ) ) { $retry[] = 'facebook'; }
	return $retry;
}

/** Scores an answer against the contract of its step. */
function lab_score( $step, $text, $brief, $options = array() ) {
	lab_boot();
	$settings = lab_settings();
	$checks = array();
	$json = MSRWA_Json::decode( $text );
	$checks['valid JSON'] = array( 'pass' => is_array( $json ), 'detail' => is_array( $json ) ? count( $json ) . ' keys' : 'not parseable' );
	$json = is_array( $json ) ? $json : array();

	if ( 'research' === $step ) {
		// An empty array is not evidence. Every one of these keys passing on zero
		// entries is how a package with no facts and no sources scored 7/10.
		$required = array( 'ingredients' => 4, 'preparation' => 4, 'references' => 2, 'visual_references' => 1, 'visual_observations' => 1, 'uncertainties' => 0 );
		foreach ( $required as $key => $minimum ) {
			$count = isset( $json[ $key ] ) && is_array( $json[ $key ] ) ? count( $json[ $key ] ) : -1;
			$checks[ $key ] = array( 'pass' => $count >= $minimum, 'detail' => $count < 0 ? 'missing' : $count . ' entries, ' . $minimum . ' minimum' );
		}
		$sourced = 0;
		foreach ( (array) ( $json['references'] ?? array() ) as $reference ) { if ( ! empty( $reference['url'] ) ) { $sourced++; } }
		$checks['references carry a URL'] = array( 'pass' => $sourced > 0, 'detail' => $sourced . ' with a URL' );
		$real_images = 0;
		foreach ( (array) ( $json['visual_references'] ?? array() ) as $reference ) {
			if ( preg_match( '#^https://#i', (string) ( $reference['image_url'] ?? '' ) ) && preg_match( '#^https://#i', (string) ( $reference['source_url'] ?? '' ) ) ) { $real_images++; }
		}
		$checks['real image provenance'] = array( 'pass' => $real_images > 0, 'detail' => $real_images . ' image references with HTTPS image and source URLs' );
		$observed = count( (array) ( $json['visual_observations'] ?? array() ) );
		$checks['images inspected'] = array( 'pass' => $observed > 0, 'detail' => $observed . ' visual observations extracted from image bytes' );

		// The package now carries the recipe the sources describe, so the canonical
		// step builds rather than invents. An outline with no times is not an outline.
		$outline = is_array( $json['recipe_outline'] ?? null ) ? $json['recipe_outline'] : array();
		$timed = 0;
		foreach ( array( 'servings', 'prep_minutes', 'cook_minutes', 'total_minutes' ) as $key ) { if ( null !== ( $outline[ $key ] ?? null ) && '' !== $outline[ $key ] ) { $timed++; } }
		$checks['recipe outline'] = array( 'pass' => $timed >= 3, 'detail' => $timed . ' of 4 figures given' );

		$cued = 0;
		foreach ( (array) ( $json['preparation'] ?? array() ) as $step ) { if ( is_array( $step ) && '' !== trim( (string) ( $step['cue'] ?? '' ) ) ) { $cued++; } }
		$steps_total = count( (array) ( $json['preparation'] ?? array() ) );
		$checks['every step has its sign'] = array( 'pass' => $steps_total > 0 && $cued === $steps_total, 'detail' => $cued . ' of ' . $steps_total . ' steps carry a visible cue' );

		$sourced = 0;
		foreach ( (array) ( $json['ingredients'] ?? array() ) as $ingredient ) { if ( is_array( $ingredient ) && '' !== trim( (string) ( $ingredient['source_url'] ?? '' ) ) ) { $sourced++; } }
		$ingredients_total = count( (array) ( $json['ingredients'] ?? array() ) );
		$checks['every ingredient is sourced'] = array( 'pass' => $ingredients_total > 0 && $sourced === $ingredients_total, 'detail' => $sourced . ' of ' . $ingredients_total . ' carry a source' );

		$tier1 = 0;
		foreach ( (array) ( $json['visual_references'] ?? array() ) as $reference ) { if ( is_array( $reference ) && 1 === (int) ( $reference['tier'] ?? 0 ) ) { $tier1++; } }
		$checks['photographs of this dish'] = array( 'pass' => $tier1 > 0, 'detail' => $tier1 . ' tier-1 references, ' . ( count( (array) ( $json['visual_references'] ?? array() ) ) - $tier1 ) . ' tier-2' );
	}

	if ( 'canonical_recipe' === $step ) {
		$errors = MSRWA_Recipe::validate( $json );
		$checks['recipe schema'] = array( 'pass' => empty( $errors ), 'detail' => $errors ? implode( ', ', array_keys( $errors ) ) : 'valid' );
		$checks['ingredients'] = array( 'pass' => count( (array) ( $json['ingredients'] ?? array() ) ) >= (int) $settings['quality_min_ingredients'], 'detail' => count( (array) ( $json['ingredients'] ?? array() ) ) . ' items' );
		$checks['steps'] = array( 'pass' => count( (array) ( $json['steps'] ?? array() ) ) >= (int) $settings['quality_min_steps'], 'detail' => count( (array) ( $json['steps'] ?? array() ) ) . ' steps' );
	}

	if ( 'article' === $step ) {
		$canonical = lab_canonical_recipe( $brief, $options );
		$quality = MSRWA_Quality::evaluate( $json, $canonical, $settings );
		$checks['quality gate'] = array( 'pass' => ! empty( $quality['pass'] ), 'detail' => 'score ' . (int) $quality['score'] . '/100' . ( empty( $quality['blockers'] ) ? '' : ', blocked on ' . implode( ', ', $quality['blockers'] ) ) );
		$words = (int) ( $quality['metrics']['words'] ?? 0 );
		$checks['words'] = array( 'pass' => $words >= (int) $settings['quality_min_words'], 'detail' => $words . ' / ' . (int) $settings['quality_min_words'] );
		$checks['headings'] = array( 'pass' => (int) ( $quality['metrics']['headings'] ?? 0 ) >= (int) $settings['quality_min_headings'], 'detail' => (int) ( $quality['metrics']['headings'] ?? 0 ) . ' / ' . (int) $settings['quality_min_headings'] );
		$content = (string) ( $json['content_html'] ?? '' );
		$headings = lab_headings( $content );
		$missing = array();
		foreach ( lab_required_sections() as $section => $synonyms ) {
			$found = false;
			foreach ( $synonyms as $synonym ) {
				foreach ( $headings as $heading ) { if ( false !== strpos( lab_fold( $heading ), $synonym ) ) { $found = true; break 2; } }
			}
			if ( ! $found ) { $missing[] = $section; }
		}
		$checks['required sections'] = array( 'pass' => empty( $missing ), 'detail' => $missing ? 'missing: ' . implode( ', ', $missing ) : count( lab_required_sections() ) . '/' . count( lab_required_sections() ) . ' present' );
		$closing = lab_closing_section( $content );
		$checks['closing section'] = array( 'pass' => $closing['words'] >= 60 && ! $closing['is_question'], 'detail' => $closing['words'] . ' words under "' . mb_substr( $closing['heading'], 0, 40 ) . '"' );
		$accents = lab_accent_density( $content );
		$checks['French typography'] = array( 'pass' => $accents >= 12, 'detail' => sprintf( '%.1f accented characters per 1000 (French prose sits near 30)', $accents ) );
		$checks['two parts'] = array( 'pass' => false !== strpos( $content, '<!--nextpage-->' ) || ! empty( $json['content_html_part2'] ), 'detail' => false !== strpos( $content, '<!--nextpage-->' ) ? 'page break present' : 'single block' );
		$checks['no metadata in body'] = array( 'pass' => ! preg_match( '/meta.?description|slug\s*:|mots.?cl(é|e)s\s*:/iu', $content ), 'detail' => 'body carries prose only' );
		$fields = array( 'title', 'excerpt', 'seo_title', 'seo_description', 'slug', 'tags', 'categories', 'recipe_meta', 'internal_links', 'facebook_caption', 'faq', 'visual_final_notes' );
		$absent = array();
		foreach ( $fields as $field ) { if ( ! array_key_exists( $field, $json ) ) { $absent[] = $field; } }
		$checks['fields the plugin needs'] = array( 'pass' => empty( $absent ), 'detail' => $absent ? 'missing: ' . implode( ', ', $absent ) : count( $fields ) . ' fields present' );
	}

	if ( 'fact_check' === $step ) {
		$article = (string) ( lab_article_under_test( $options )['content_html'] ?? '' );
		$plain = html_entity_decode( strip_tags( $article ), ENT_QUOTES, 'UTF-8' );
		$checks['verdict is boolean'] = array( 'pass' => array_key_exists( 'pass', $json ) && is_bool( $json['pass'] ), 'detail' => isset( $json['pass'] ) ? var_export( $json['pass'], true ) : 'missing' );
		$corrections = (array) ( $json['corrections'] ?? array() );
		$quoted = 0; $invented = 0;
		foreach ( $corrections as $correction ) {
			$before = trim( (string) ( $correction['before'] ?? '' ) );
			if ( '' === $before ) { continue; }
			if ( false !== mb_strpos( $plain, $before ) ) { $quoted++; } else { $invented++; }
		}
		$checks['quotes the real text'] = array( 'pass' => 0 === $invented, 'detail' => $corrections ? $quoted . ' verbatim, ' . $invented . ' not found in the article' : 'no correction proposed' );
		$sourced = 0;
		foreach ( $corrections as $correction ) { if ( ! empty( $correction['source'] ) ) { $sourced++; } }
		$checks['each fix cites a source'] = array( 'pass' => count( $corrections ) === $sourced, 'detail' => $sourced . '/' . count( $corrections ) );
		$checks['surgical'] = array( 'pass' => count( $corrections ) <= 12, 'detail' => count( $corrections ) . ' corrections proposed' );
	}

	if ( 'proofread' === $step ) {
		$original = (string) ( lab_article_under_test( $options )['content_html'] ?? '' );
		$corrected = (string) ( $json['content_html'] ?? '' );
		$checks['returns the article'] = array( 'pass' => mb_strlen( $corrected ) > 0.7 * mb_strlen( $original ), 'detail' => mb_strlen( $corrected ) . ' vs ' . mb_strlen( $original ) . ' characters' );
		$before_headings = count( lab_headings( $original ) );
		$after_headings = count( lab_headings( $corrected ) );
		$checks['structure preserved'] = array( 'pass' => $before_headings === $after_headings, 'detail' => $after_headings . ' headings vs ' . $before_headings );
		$checks['page break kept'] = array( 'pass' => false !== strpos( $corrected, '<!--nextpage-->' ), 'detail' => false !== strpos( $corrected, '<!--nextpage-->' ) ? 'present' : 'lost' );
		preg_match_all( '/\d+(?:[.,]\d+)?/', strip_tags( $original ), $before_numbers );
		preg_match_all( '/\d+(?:[.,]\d+)?/', strip_tags( $corrected ), $after_numbers );
		sort( $before_numbers[0] ); sort( $after_numbers[0] );
		$checks['no figure altered'] = array( 'pass' => $before_numbers[0] === $after_numbers[0], 'detail' => count( $after_numbers[0] ) . ' figures, ' . ( $before_numbers[0] === $after_numbers[0] ? 'identical' : 'CHANGED' ) );
		$checks['French typography'] = array( 'pass' => lab_accent_density( $corrected ) >= lab_accent_density( $original ) - 1, 'detail' => sprintf( '%.1f vs %.1f per 1000', lab_accent_density( $corrected ), lab_accent_density( $original ) ) );
	}

	if ( 'review' === $step ) {
		$checks['verdict is boolean'] = array( 'pass' => array_key_exists( 'pass', $json ) && is_bool( $json['pass'] ), 'detail' => isset( $json['pass'] ) ? var_export( $json['pass'], true ) : 'missing' );
		$checks['findings are structured'] = array( 'pass' => isset( $json['findings'] ) && is_array( $json['findings'] ), 'detail' => count( (array) ( $json['findings'] ?? array() ) ) . ' findings' );
	}

	$passed = 0;
	foreach ( $checks as $check ) { if ( $check['pass'] ) { $passed++; } }
	return array( 'checks' => $checks, 'passed' => $passed, 'total' => count( $checks ), 'pass' => $passed === count( $checks ) );
}

/**
 * The outline the specification requires, each with the wordings that satisfy
 * it. Matching ignores accents and case so typography is measured separately.
 */
function lab_required_sections() {
	return array(
		'ingrédients'  => array( 'ingredient' ),
		'choix'        => array( 'choisir', 'choix', 'selection' ),
		'substitutions'=> array( 'substitut', 'remplacer', 'alternative' ),
		'matériel'     => array( 'materiel', 'equipement', 'ustensile' ),
		'préparation'  => array( 'preparation', 'etape', 'pas a pas' ),
		'erreurs'      => array( 'erreur', 'piege', 'eviter' ),
		'conservation' => array( 'conservation', 'conserver', 'rechauff' ),
		'variantes'    => array( 'variante', 'version', 'adaptation' ),
		'service'      => array( 'service', 'servir', 'accompagn', 'decoupe' ),
		'faq'          => array( 'faq', 'questions frequentes', 'question' ),
	);
}

/** Heading texts of an article body. */
function lab_headings( $html ) {
	preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', (string) $html, $matches );
	$out = array();
	foreach ( (array) $matches[1] as $heading ) { $out[] = trim( strip_tags( $heading ) ); }
	return $out;
}

/** Lowercase, accent-free form, for matching content rather than spelling. */
function lab_fold( $text ) {
	$text = mb_strtolower( (string) $text, 'UTF-8' );
	$map = array( 'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','œ'=>'oe','æ'=>'ae' );
	return strtr( $text, $map );
}

/**
 * Accented characters per thousand. A French recipe written without accents
 * is a writing mistake, not a style: real prose sits around thirty.
 */
function lab_accent_density( $html ) {
	$text = trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $html ) ) );
	$length = mb_strlen( $text, 'UTF-8' );
	if ( ! $length ) { return 0.0; }
	preg_match_all( '/[àâäéèêëîïôöùûüçœæ]/ui', $text, $matches );
	return round( count( $matches[0] ) * 1000 / $length, 2 );
}

/**
 * The article's closing section: whatever sits under the last h2. An editor
 * titles it "Une recette à refaire", not "Conclusion", so it is measured by
 * position and substance rather than by its wording.
 */
function lab_closing_section( $html ) {
	$parts = preg_split( '/<h2[^>]*>/i', (string) $html );
	$last = trim( (string) end( $parts ) );
	$heading = '';
	if ( preg_match( '/^(.*?)<\/h2>/is', $last, $match ) ) { $heading = trim( strip_tags( $match[1] ) ); $last = substr( $last, strlen( $match[0] ) ); }
	$words = preg_match_all( '/\p{L}+/u', strip_tags( $last ) );
	return array( 'heading' => $heading, 'words' => (int) $words, 'is_question' => false !== strpos( $heading, '?' ) );
}

/**
 * The article the review, fact check and proofreading steps are measured on.
 * One fixed article for every provider and tier, so the comparison is fair.
 */
function lab_article_under_test( $options ) {
	static $article = null;
	if ( null !== $article ) { return $article; }
	$file = $options['article'] ?? '';
	if ( '' === $file ) {
		$candidates = glob( __DIR__ . '/../runs/article-*.json' );
		sort( $candidates );
		$file = $candidates ? $candidates[0] : '';
	}
	if ( '' === $file || ! file_exists( $file ) ) { fwrite( STDERR, "No article to measure against. Run the article step first, or pass --article=<run.json>.\n" ); exit( 2 ); }
	$run = json_decode( file_get_contents( $file ), true );
	$article = json_decode( (string) ( $run['output'] ?? '' ), true );
	$article = is_array( $article ) ? $article : array();
	return $article;
}
