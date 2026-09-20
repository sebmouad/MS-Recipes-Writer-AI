<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Quality {
	public static function benchmark( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : MSRWA_Settings::get();
		return array(
			'words'      => absint( $settings['quality_min_words'] ?? 2000 ),
			'headings'   => absint( $settings['quality_min_headings'] ?? 12 ),
			'paragraphs' => absint( $settings['quality_min_paragraphs'] ?? 28 ),
			'ingredients'=> absint( $settings['quality_min_ingredients'] ?? 6 ),
			'steps'      => absint( $settings['quality_min_steps'] ?? 6 ),
			'source'     => 'quality_contract',
		);
	}

	public static function prompt_contract( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : MSRWA_Settings::get();
		$benchmark = self::benchmark( $settings );
		return "\nCONTRAT QUALITÉ MESURABLE : rédige entre " . (int) $benchmark['words'] . ' et ' . (int) ( $settings['quality_max_words'] ?? 4200 ) . ' mots utiles, avec au moins ' . (int) $benchmark['headings'] . ' titres h2/h3, ' . (int) $benchmark['paragraphs'] . ' paragraphes, ' . (int) $benchmark['ingredients'] . ' ingrédients lorsque la recette le justifie et ' . (int) $benchmark['steps'] . " étapes. Évite le remplissage, les répétitions et les promesses non étayées. Le JSON doit respecter exactement le schéma demandé et content_html doit être du HTML valide. Inclure choix des ingrédients, substitutions sûres, méthode détaillée, erreurs à éviter, conservation, variantes, service, FAQ et conclusion utile. Retourne recipe_meta comme objet vide : les métadonnées sont reprises directement de la recette canonique par le moteur. Respecte 35–70 caractères pour seo_title, 120–170 pour seo_description et 120–260 pour excerpt. Préfère le bas de la plage de longueur sans passer sous le minimum.";
	}

	public static function evaluate( $article, $canonical, $settings = null ) {
		$settings = is_array( $settings ) ? $settings : MSRWA_Settings::get();
		$article = is_array( $article ) ? $article : array();
		$canonical = is_array( $canonical ) ? $canonical : array();
		$benchmark = self::benchmark( $settings );
		$content = (string) ( $article['content_html'] ?? '' );
		$metrics = array(
			'words'       => self::word_count( $content ),
			'headings'    => preg_match_all( '/<h[2-3]\b/i', $content ),
			'paragraphs'  => preg_match_all( '/<p\b/i', $content ),
			'ingredients' => count( array_filter( (array) ( $canonical['ingredients'] ?? array() ) ) ),
			'steps'       => count( array_filter( (array) ( $canonical['steps'] ?? array() ) ) ),
			'internal_links' => count( array_filter( (array) ( $article['internal_links'] ?? array() ) ) ),
		);
		$findings = array();
		$score = 0;
		$blockers = array();
		self::score_threshold( $score, $findings, $blockers, 'content_html', $metrics['words'], $benchmark['words'], 25, true, 'Développer l’article avec des informations culinaires utiles, sans répétition.' );
		$html_ok = false !== stripos( $content, '<p' ) && false !== stripos( $content, '<h2' );
		self::score_boolean( $score, $findings, $blockers, 'content_html', $html_ok, 5, true, 'Retourner un HTML sémantique valide avec h2, h3, p, ul/ol et li.' );
		self::score_threshold( $score, $findings, $blockers, 'headings', $metrics['headings'], $benchmark['headings'], 5, false, 'Structurer davantage le contenu avec des titres descriptifs.' );
		self::score_threshold( $score, $findings, $blockers, 'paragraphs', $metrics['paragraphs'], $benchmark['paragraphs'], 5, false, 'Découper les explications en paragraphes lisibles.' );

		$core_fields = array( 'servings', 'prep_minutes', 'cook_minutes', 'cuisine', 'calories_estimate' );
		$core_present = 0;
		foreach ( $core_fields as $field ) { if ( array_key_exists( $field, $canonical ) && '' !== (string) $canonical[ $field ] ) { $core_present++; } }
		self::score_threshold( $score, $findings, $blockers, 'recipe_core', $core_present, count( $core_fields ), 10, true, 'Compléter portions, préparation, cuisson, cuisine et calories estimées.' );
		self::score_threshold( $score, $findings, $blockers, 'ingredients', $metrics['ingredients'], $benchmark['ingredients'], 7, false, 'Compléter la liste seulement si la recette exige réellement davantage d’ingrédients.' );
		self::score_threshold( $score, $findings, $blockers, 'steps', $metrics['steps'], $benchmark['steps'], 8, false, 'Produire des étapes complètes sans découpage artificiel.' );

		$enrichment = 0;
		foreach ( array( 'difficulty', 'equipment', 'notes', 'faq', 'keywords' ) as $field ) { if ( ! empty( $canonical[ $field ] ) ) { $enrichment++; } }
		self::score_threshold( $score, $findings, $blockers, 'recipe_enrichment', $enrichment, 5, 15, false, 'Ajouter difficulté, équipement, notes, FAQ et mots-clés cohérents.' );

		$seo_title_length = self::text_length( (string) ( $article['seo_title'] ?? '' ) );
		$seo_description_length = self::text_length( (string) ( $article['seo_description'] ?? '' ) );
		$excerpt_length = self::text_length( (string) ( $article['excerpt'] ?? '' ) );
		self::score_boolean( $score, $findings, $blockers, 'seo_title', $seo_title_length >= 35 && $seo_title_length <= 70, 3, false, 'Garder le titre SEO entre 35 et 70 caractères.' );
		self::score_boolean( $score, $findings, $blockers, 'seo_description', $seo_description_length >= 120 && $seo_description_length <= 170, 4, false, 'Garder la description SEO entre 120 et 170 caractères.' );
		self::score_boolean( $score, $findings, $blockers, 'excerpt', $excerpt_length >= 120 && $excerpt_length <= 260, 3, false, 'Fournir un extrait autonome entre 120 et 260 caractères.' );

		$discovery = 0;
		if ( ! empty( $article['slug'] ) ) { $discovery++; }
		if ( ! empty( $article['tags'] ) ) { $discovery++; }
		if ( ! empty( $article['categories'] ) ) { $discovery++; }
		if ( ! empty( $article['facebook_caption'] ) ) { $discovery++; }
		if ( $metrics['internal_links'] >= min( 2, absint( $settings['internal_links_max'] ?? 0 ) ) ) { $discovery++; }
		self::score_threshold( $score, $findings, $blockers, 'distribution', $discovery, 5, 10, false, 'Compléter slug, taxonomies, légende Facebook et liens internes autorisés.' );

		$score = min( 100, max( 0, (int) round( $score ) ) );
		$minimum = min( 100, max( 1, absint( $settings['quality_min_score'] ?? 90 ) ) );
		return array( 'pass' => empty( $blockers ) && $score >= $minimum, 'score' => $score, 'minimum_score' => $minimum, 'metrics' => $metrics, 'benchmark' => $benchmark, 'findings' => $findings, 'blockers' => array_values( array_unique( $blockers ) ) );
	}

	private static function word_count( $html ) {
		$html = preg_replace( '/<\/?(?:p|h[1-6]|li|div|section|article|br|blockquote|table|tr|td|th)\b[^>]*>/i', ' ', strip_shortcodes( (string) $html ) );
		$text = trim( wp_strip_all_tags( $html ) );
		return preg_match_all( '/\p{L}+(?:[’\'-]\p{L}+)*/u', $text );
	}

	private static function text_length( $value ) { return function_exists( 'mb_strlen' ) ? mb_strlen( trim( $value ) ) : strlen( trim( $value ) ); }

	private static function score_threshold( &$score, &$findings, &$blockers, $field, $actual, $target, $weight, $blocking, $fix ) {
		$target = max( 1, (float) $target );
		$ratio = min( 1, max( 0, (float) $actual / $target ) );
		$score += $weight * $ratio;
		if ( $actual < $target ) {
			$findings[] = array( 'severity' => $blocking ? 'blocking' : 'major', 'field' => $field, 'reason' => sprintf( 'Valeur %s, minimum %s.', $actual, $target ), 'fix' => $fix );
			if ( $blocking ) { $blockers[] = $field; }
		}
	}

	private static function score_boolean( &$score, &$findings, &$blockers, $field, $passed, $weight, $blocking, $fix ) {
		if ( $passed ) { $score += $weight; return; }
		$findings[] = array( 'severity' => $blocking ? 'blocking' : 'major', 'field' => $field, 'reason' => 'Critère non satisfait.', 'fix' => $fix );
		if ( $blocking ) { $blockers[] = $field; }
	}
}
