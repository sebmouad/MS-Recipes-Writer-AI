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
			'prompts' => array( 'prompt_research' ), 'json' => true, 'max_output' => (int) $s['research_max_output_tokens'],
			'tools' => array( array( 'type' => 'web_search' ) ),
			'expects' => 'JSON with recipe_facts, references, uncertainties',
		),
		'canonical_recipe' => array(
			'prompts' => array( 'prompt_recipe', 'prompt_nutrition' ), 'json' => true, 'max_output' => (int) $s['canonical_max_output_tokens'],
			'expects' => 'a recipe passing MSRWA_Recipe::validate',
		),
		'article' => array(
			'prompts' => array( 'prompt_article', 'prompt_seo' ), 'json' => true, 'max_output' => max( (int) $s['article_max_output_tokens'], MSRWA_Cost::output_budget( $s['quality_max_words'] ) ),
			'expects' => 'an article passing MSRWA_Quality plus the required outline',
		),
		'review' => array(
			'prompts' => array( 'prompt_review' ), 'json' => true, 'max_output' => (int) $s['review_max_output_tokens'],
			'expects' => 'JSON with pass, findings, corrected_artifact',
		),
		'article_full' => array(
			'prompts' => array( 'prompt_article' ), 'json' => true, 'max_output' => 16000,
			'expects' => 'the canonical recipe and the complete article in one call',
		),
		'fact_check' => array(
			'prompts' => array( 'prompt_review' ), 'json' => true, 'max_output' => 4000,
			'expects' => 'only the passages the sources contradict, quoted verbatim',
		),
		'proofread' => array(
			'prompts' => array( 'prompt_correction' ), 'json' => true, 'max_output' => max( (int) $s['article_max_output_tokens'], MSRWA_Cost::output_budget( $s['quality_max_words'] ) ),
			'expects' => 'the same article with its French corrected and every figure untouched',
		),
		'article_part1' => array(
			'prompts' => array( 'prompt_article' ), 'json' => true, 'max_output' => 6000,
			'expects' => 'page one: intro, ingredients and their role, choice, substitutions, equipment, plus continuity_context',
		),
		'article_part2' => array(
			'prompts' => array( 'prompt_article' ), 'json' => true, 'max_output' => 6000,
			'expects' => 'page two: preparation, mistakes, storage, variants, serving, FAQ, conclusion',
		),
	);
}

/** The prompt under test: a candidate variant, or the shipped default. */
function lab_prompt( $step, $variant = '' ) {
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

/** What research observed about the finished dish, carried into every later step. */
function lab_visual_reference( $brief ) {
	$research = is_array( $brief['research'] ?? null ) ? $brief['research'] : array();
	$reference = $research['visual_reference'] ?? array();
	return is_array( $reference ) ? $reference : array();
}

/** Assembles the same input the pipeline would send for this step. */
function lab_build_input( $step, $prompt, $brief, $options ) {
	$settings = lab_settings();
	$encode = static function ( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };
	$visual = $encode( lab_visual_reference( $brief ) );
	if ( 'research' === $step ) {
		return $prompt . "\nEntrée éditeur : " . $encode( array( 'title' => $brief['title'], 'source_text' => $brief['text'] ) );
	}
	if ( 'canonical_recipe' === $step ) {
		return $prompt . "\nEntrée : " . $encode( array( 'title' => $brief['title'], 'source_text' => $brief['text'] ) )
			. ' Recherche : ' . $encode( $brief['research'] ?? array() )
			. "\nVISUAL REFERENCE: " . $visual;
	}
	if ( 'article_full' === $step ) {
		return $prompt . "\nBRIEF: " . $encode( array( 'title' => $brief['title'], 'source_text' => $brief['text'] ) )
			. "\nRESEARCH: " . $encode( $brief['research'] ?? array() );
	}
	if ( 'article' === $step ) {
		lab_boot();
		return $prompt . MSRWA_Quality::prompt_contract( $settings )
			. "\nRecette canonique : " . $encode( $brief['canonical'] ?? array() )
			. "\nRecherche : " . $encode( $brief['research'] ?? array() )
			. "\nVISUAL REFERENCE: " . $visual;
	}
	if ( 'review' === $step ) {
		return $prompt . "\nCANONICAL RECIPE: " . $encode( $brief['canonical'] ?? array() )
			. "\nARTICLE: " . $encode( lab_article_under_test( $options ) );
	}
	if ( 'fact_check' === $step ) {
		return $prompt . "\nRESEARCH SOURCES: " . $encode( $brief['research'] ?? array() )
			. "\nCANONICAL RECIPE: " . $encode( $brief['canonical'] ?? array() )
			. "\nARTICLE: " . $encode( lab_article_under_test( $options )['content_html'] ?? '' );
	}
	if ( 'proofread' === $step ) {
		return $prompt . "\nARTICLE TO CORRECT: " . $encode( lab_article_under_test( $options )['content_html'] ?? '' );
	}
	if ( 'article_part1' === $step ) {
		return $prompt . "\nRECETTE CANONIQUE : " . $encode( $brief['canonical'] ?? array() )
			. "\nRECHERCHE : " . $encode( $brief['research'] ?? array() );
	}
	if ( 'article_part2' === $step ) {
		$handoff = $options['handoff'] ?? '';
		if ( '' !== $handoff && file_exists( $handoff ) ) {
			$previous = json_decode( file_get_contents( $handoff ), true );
			$decoded = json_decode( (string) ( $previous['output'] ?? '' ), true );
			$handoff = (string) ( $decoded['continuity_context'] ?? '' );
			$part1_html = (string) ( $decoded['content_html'] ?? '' );
		} else {
			$part1_html = '';
		}
		return $prompt . "\nRECETTE CANONIQUE : " . $encode( $brief['canonical'] ?? array() )
			. "\nCONTEXTE DE CONTINUITÉ DE LA PARTIE 1 : " . $handoff
			. "\nTITRES DÉJÀ TRAITÉS EN PARTIE 1 : " . $encode( lab_headings( $part1_html ) );
	}
	return $prompt;
}

/** Scores an answer against the contract of its step. */
function lab_score( $step, $text, $brief ) {
	lab_boot();
	$settings = lab_settings();
	$checks = array();
	$json = MSRWA_Json::decode( $text );
	$checks['valid JSON'] = array( 'pass' => is_array( $json ), 'detail' => is_array( $json ) ? count( $json ) . ' keys' : 'not parseable' );
	$json = is_array( $json ) ? $json : array();

	if ( 'research' === $step ) {
		foreach ( array( 'recipe_facts', 'references', 'uncertainties' ) as $key ) {
			$checks[ $key ] = array( 'pass' => isset( $json[ $key ] ) && is_array( $json[ $key ] ), 'detail' => isset( $json[ $key ] ) ? count( (array) $json[ $key ] ) . ' entries' : 'missing' );
		}
		$sourced = 0;
		foreach ( (array) ( $json['references'] ?? array() ) as $reference ) { if ( ! empty( $reference['url'] ) ) { $sourced++; } }
		$checks['references carry a URL'] = array( 'pass' => $sourced > 0, 'detail' => $sourced . ' with a URL' );

		// Every later prompt reads this object, so a missing facet degrades the
		// recipe, the article and both images at once.
		$facets = array( 'colour', 'surface', 'texture', 'plating', 'garnish', 'doneness_cues' );
		$reference = is_array( $json['visual_reference'] ?? null ) ? $json['visual_reference'] : array();
		$filled = array();
		$empty = array();
		foreach ( $facets as $facet ) {
			$value = trim( (string) ( $reference[ $facet ] ?? '' ) );
			if ( '' !== $value ) { $filled[ $facet ] = $value; } else { $empty[] = $facet; }
		}
		$checks['visual reference complete'] = array( 'pass' => empty( $empty ), 'detail' => $empty ? 'missing: ' . implode( ', ', $empty ) : count( $filled ) . ' facets' );
		$words = str_word_count( implode( ' ', $filled ), 0, 'àâäçéèêëîïôöùûüœÀÂÄÇÉÈÊËÎÏÔÖÙÛÜŒ' );
		$checks['visual reference is usable'] = array( 'pass' => $words >= 40, 'detail' => $words . ' words across the facets, 40 minimum' );
		$banned = array( 'délicieux', 'delicieux', 'authentique', 'savoureux', 'réconfortant', 'gourmand', 'incontournable' );
		$found = array();
		foreach ( $banned as $word ) { if ( false !== mb_stripos( implode( ' ', $filled ), $word ) ) { $found[] = $word; } }
		$checks['visual reference is observable'] = array( 'pass' => empty( $found ), 'detail' => $found ? 'unseeable: ' . implode( ', ', $found ) : 'no unseeable adjective' );
	}

	if ( 'canonical_recipe' === $step ) {
		$errors = MSRWA_Recipe::validate( $json );
		$checks['recipe schema'] = array( 'pass' => empty( $errors ), 'detail' => $errors ? implode( ', ', array_keys( $errors ) ) : 'valid' );
		$checks['ingredients'] = array( 'pass' => count( (array) ( $json['ingredients'] ?? array() ) ) >= (int) $settings['quality_min_ingredients'], 'detail' => count( (array) ( $json['ingredients'] ?? array() ) ) . ' items' );
		$checks['steps'] = array( 'pass' => count( (array) ( $json['steps'] ?? array() ) ) >= (int) $settings['quality_min_steps'], 'detail' => count( (array) ( $json['steps'] ?? array() ) ) . ' steps' );
	}

	if ( 'article_full' === $step || 'article' === $step ) {
		$canonical = 'article_full' === $step ? ( $json['recipe'] ?? array() ) : ( $brief['canonical'] ?? array() );
		if ( 'article_full' === $step ) {
			$errors = MSRWA_Recipe::validate( $canonical );
			$checks['recipe schema'] = array( 'pass' => empty( $errors ), 'detail' => $errors ? implode( ', ', array_keys( $errors ) ) : 'valid' );
			$times_ok = (int) ( $canonical['prep_minutes'] ?? 0 ) + (int) ( $canonical['cook_minutes'] ?? 0 ) <= (int) ( $canonical['total_minutes'] ?? 0 );
			$checks['times add up'] = array( 'pass' => $times_ok, 'detail' => (int) ( $canonical['prep_minutes'] ?? 0 ) . ' + ' . (int) ( $canonical['cook_minutes'] ?? 0 ) . ' vs ' . (int) ( $canonical['total_minutes'] ?? 0 ) . ' total' );
		}
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

	if ( 'article_part1' === $step || 'article_part2' === $step ) {
		$content = (string) ( $json['content_html'] ?? '' );
		$words = preg_match_all( '/\p{L}+(?:[’\'-]\p{L}+)*/u', strip_tags( $content ) );
		$target = 'article_part1' === $step ? 1500 : 1300;
		$checks['words'] = array( 'pass' => $words >= $target * 0.85, 'detail' => $words . ' / ' . $target . ' expected' );
		$accents = lab_accent_density( $content );
		$checks['French typography'] = array( 'pass' => $accents >= 12, 'detail' => sprintf( '%.1f accented characters per 1000', $accents ) );
		$headings = lab_headings( $content );
		$checks['headings'] = array( 'pass' => count( $headings ) >= 4, 'detail' => count( $headings ) . ' headings' );
		$sections = 'article_part1' === $step
			? array( 'ingrédients' => array( 'ingredient' ), 'choix' => array( 'choisir', 'choix', 'selection' ), 'substitutions' => array( 'substitut', 'remplacer' ), 'matériel' => array( 'materiel', 'equipement', 'ustensile' ) )
			: array( 'préparation' => array( 'preparation', 'etape' ), 'erreurs' => array( 'erreur', 'eviter' ), 'conservation' => array( 'conservation', 'conserver' ), 'variantes' => array( 'variante', 'version' ), 'service' => array( 'service', 'servir', 'accompagn' ), 'faq' => array( 'faq', 'question' ) );
		$missing = array();
		foreach ( $sections as $section => $synonyms ) {
			$found = false;
			foreach ( $synonyms as $synonym ) { foreach ( $headings as $heading ) { if ( false !== strpos( lab_fold( $heading ), $synonym ) ) { $found = true; break 2; } } }
			if ( ! $found ) { $missing[] = $section; }
		}
		$checks['required sections'] = array( 'pass' => empty( $missing ), 'detail' => $missing ? 'missing: ' . implode( ', ', $missing ) : 'all present' );
		if ( 'article_part1' === $step ) {
			$handoff = (string) ( $json['continuity_context'] ?? '' );
			$complete = false !== strpos( $handoff, 'INGREDIENTS:' ) && false !== strpos( $handoff, 'A_NE_PAS_CONTREDIRE:' );
			$checks['continuity handoff'] = array( 'pass' => $complete, 'detail' => $complete ? strlen( $handoff ) . ' chars' : 'missing or incomplete' );
			$checks['no preparation steps'] = array( 'pass' => ! preg_match( '/<h[23][^>]*>[^<]*(préparation de la recette|étape par étape)/iu', $content ), 'detail' => 'preparation left to page two' );
		} else {
			$opens = preg_match( '/^\s*<h2[^>]*>\s*Préparation de la recette étape par étape/iu', trim( $content ) );
			$checks['opens on the preparation'] = array( 'pass' => (bool) $opens, 'detail' => $opens ? 'exact opening heading' : 'wrong opening' );
			$checks['faq field'] = array( 'pass' => isset( $json['faq'] ) && count( (array) $json['faq'] ) >= 3, 'detail' => count( (array) ( $json['faq'] ?? array() ) ) . ' entries' );
			$checks['visual notes'] = array( 'pass' => mb_strlen( (string) ( $json['visual_final_notes'] ?? '' ) ) >= 200, 'detail' => mb_strlen( (string) ( $json['visual_final_notes'] ?? '' ) ) . ' chars for the image prompt' );
		}
	}

	if ( 'fact_check' === $step ) {
		$article = (string) ( lab_article_under_test( array() )['content_html'] ?? '' );
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
		$original = (string) ( lab_article_under_test( array() )['content_html'] ?? '' );
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
		$candidates = glob( __DIR__ . '/../runs/article-v4-*.json' );
		sort( $candidates );
		$file = $candidates ? $candidates[0] : '';
	}
	if ( '' === $file || ! file_exists( $file ) ) { fwrite( STDERR, "No article to measure against. Run the article step first, or pass --article=<run.json>.\n" ); exit( 2 ); }
	$run = json_decode( file_get_contents( $file ), true );
	$article = json_decode( (string) ( $run['output'] ?? '' ), true );
	$article = is_array( $article ) ? $article : array();
	return $article;
}
