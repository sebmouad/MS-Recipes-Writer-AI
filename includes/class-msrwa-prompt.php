<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compiles a prompt template against the settings.
 *
 * A prompt is never written twice. The template holds the editorial intent and
 * the settings hold the numbers, so changing the minimum word count, the
 * required sections, the image size or the page split changes what the model
 * is asked for — in the plugin and in the prompt lab alike, because both call
 * this same compiler.
 *
 * Syntax, deliberately small:
 *   {{name}}                       a value
 *   {{#if name}} … {{/if}}         kept when the value is true or non-empty
 *   {{#unless name}} … {{/unless}} the opposite
 */
final class MSRWA_Prompt {
	/** Every value a template may use, derived from the settings. */
	public static function variables( $settings = null ) {
		$s = is_array( $settings ) ? $settings : MSRWA_Settings::get();
		$total = max( 300, (int) ( $s['quality_min_words'] ?? 2800 ) );
		$two_pages = ! empty( $s['article_pagination_enabled'] );
		$page1 = $two_pages ? (int) round( $total * 0.53 ) : 0;
		$page2 = $two_pages ? $total - $page1 : 0;
		$sections = self::sections( $s );
		return array(
			'language'             => self::language_name( $s ),
			// The typography rule below it is French orthography, not a
			// universal one. Asked of an English or Arabic article it demands
			// accents that language does not have, and the article then fails
			// a check it could never pass.
			'french'               => 'fr' === (string) ( $s['site_language'] ?? 'fr' ),
			'words_total'          => $total,
			'words_maximum'        => max( $total, (int) ( $s['quality_max_words'] ?? $total + 1400 ) ),
			'two_pages'            => $two_pages,
			'words_page1'          => $page1,
			'words_page2'          => $page2,
			'page_break_marker'    => '<!--nextpage-->',
			'page2_opening'        => (string) ( $s['article_page2_heading'] ?? 'Préparation de la recette étape par étape' ),
			'required_sections'    => self::numbered( $sections ),
			'sections_count'       => count( $sections ),
			'headings_minimum'     => (int) ( $s['quality_min_headings'] ?? 12 ),
			'paragraphs_minimum'   => (int) ( $s['quality_min_paragraphs'] ?? 28 ),
			'faq_minimum'          => 3,
			'faq_maximum'          => 5,
			'excerpt_min'          => 120,
			'excerpt_max'          => 260,
			'seo_title_min'        => 35,
			'seo_title_max'        => 70,
			'seo_description_min'  => 120,
			'seo_description_max'  => 170,
			'internal_links_max'   => (int) ( $s['internal_links_max'] ?? 3 ),
			'featured_size'        => self::image_size( 'featured', $s ),
			'facebook_size'        => self::image_size( 'facebook', $s ),
			'featured_ratio'       => (string) ( $s['featured_ratio'] ?? '1:1' ),
			'facebook_ratio'       => (string) ( $s['facebook_ratio'] ?? '4:5' ),
			'image_format'         => strtoupper( (string) ( $s['image_format'] ?? 'webp' ) ),
			'image_quality'        => (string) ( $s['image_quality'] ?? 'medium' ),
			// The collage prompt is written for six moments in a 2 × 3 grid; a
			// count the site could change contradicted it at any other value.
			'facebook_steps'       => 6,
			'research_facts_max'      => (int) ( $s['research_facts_max'] ?? 12 ),
			'research_references_max' => (int) ( $s['research_references_max'] ?? 6 ),
		);
	}

	/** The outline an article must carry. Administrator editable, order kept. */
	public static function sections( $settings = null ) {
		$s = is_array( $settings ) ? $settings : MSRWA_Settings::get();
		$sections = isset( $s['required_sections'] ) && is_array( $s['required_sections'] ) ? $s['required_sections'] : array();
		$sections = array_values( array_filter( array_map( 'trim', array_map( 'strval', $sections ) ) ) );
		return $sections ? $sections : self::default_sections();
	}

	public static function default_sections() {
		return array(
			'Introduction : la promesse du plat, sa texture, le moment de le servir',
			'Pourquoi cette recette fonctionne : l’équilibre technique',
			'Les ingrédients et leur rôle, avec les quantités exactes',
			'Comment choisir les produits déterminants',
			'Par quoi remplacer : substitutions réalistes et leurs conséquences',
			'Le matériel nécessaire et ses équivalents',
			'Ce qu’il faut préparer avant de commencer',
			'La préparation étape par étape, avec le signe de réussite de chaque étape',
			'Les erreurs à éviter, leur cause et comment les rattraper',
			'Conservation et réchauffage',
			'Variantes et adaptations, sans inventer une nouvelle recette',
			'Avec quoi servir : accompagnements, dressage, découpe',
			'FAQ : questions réelles, réponses autonomes',
			'Conclusion éditoriale : ce que le lecteur retient',
		);
	}

	/** Compiles a template. Unknown variables are removed rather than printed. */
	public static function compile( $template, $settings = null, $extra = array() ) {
		$values = array_merge( self::variables( $settings ), is_array( $extra ) ? $extra : array() );
		$out = (string) $template;
		$out = preg_replace_callback( '/\{\{#if\s+([a-z0-9_]+)\}\}(.*?)\{\{\/if\}\}/is', static function ( $match ) use ( $values ) {
			return empty( $values[ $match[1] ] ) ? '' : $match[2];
		}, $out );
		$out = preg_replace_callback( '/\{\{#unless\s+([a-z0-9_]+)\}\}(.*?)\{\{\/unless\}\}/is', static function ( $match ) use ( $values ) {
			return empty( $values[ $match[1] ] ) ? $match[2] : '';
		}, $out );
		$out = preg_replace_callback( '/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static function ( $match ) use ( $values ) {
			$value = $values[ $match[1] ] ?? '';
			if ( is_bool( $value ) ) { return $value ? 'true' : 'false'; }
			if ( is_array( $value ) ) { return implode( ', ', $value ); }
			return (string) $value;
		}, $out );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $out ) );
	}

	private static function numbered( $sections ) {
		$out = array();
		foreach ( array_values( $sections ) as $index => $section ) { $out[] = ( $index + 1 ) . '. ' . $section; }
		return implode( "\n", $out );
	}

	private static function image_size( $kind, $settings ) {
		$ratio = 'facebook' === $kind ? ( $settings['facebook_ratio'] ?? '4:5' ) : ( $settings['featured_ratio'] ?? '1:1' );
		return MSRWA_Images::native_size( $ratio, 'facebook' === $kind ? '1024x1536' : '1024x1024' );
	}

	private static function language_name( $settings ) {
		$codes = array( 'fr' => 'French', 'en' => 'English', 'ar' => 'Arabic', 'es' => 'Spanish' );
		$code = (string) ( $settings['site_language'] ?? 'fr' );
		return $codes[ $code ] ?? $codes['fr'];
	}
}
