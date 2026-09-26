<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The article's HTML made ready for its draft, whatever the model did.
 *
 * Seen on real drafts: an article opening on a heading that repeats its own
 * title, paragraphs and headings starting with a label — « Introduction : »,
 * « Deuxième partie » — as if the text were a plan, and a first page that
 * ends without telling the reader the recipe goes on. The prompt asks for
 * a clean opening and a page one that announces page two; this makes sure
 * of both, in the article's own language.
 */
final class MSRWA_Article {

	/** A part of the text named as a part: never a heading of its own. */
	const PARTS = '(?:(?:première|deuxième|seconde|troisième|dernière) partie|partie\s*\d+|(?:premier|deuxième|second|seconde) article|article\s*\d+|page\s*\d+|(?:first|second|third|final) part|part\s*(?:\d+|one|two|three)|(?:primera|segunda|tercera) parte|parte\s*\d+)';

	/** The words a model uses to label a part of its text instead of writing it. */
	const LABELS = '(?:introduction|conclusion|en résumé|résumé|pour conclure|(?:première|deuxième|seconde|troisième|dernière) partie|partie\s*\d+|(?:premier|deuxième|second|seconde) article|article\s*\d+|page\s*\d+|(?:first|second|third|final) part|part\s*(?:\d+|one|two|three)|(?:primera|segunda|tercera) parte|parte\s*\d+|introducción|conclusión)';

	/** The line before the page break, per article language; %s is page two's heading. */
	public static function next_page_notices() {
		return array(
			'fr' => 'La suite de la recette, « %s », vous attend à la page 2.',
			'en' => 'The rest of the recipe, “%s”, continues on page 2.',
			'es' => 'La continuación de la receta, «%s», te espera en la página 2.',
			'ar' => 'تتمة الوصفة، «%s»، في الصفحة 2.',
		);
	}

	public static function tidy( $html, $title, $language = 'fr' ) {
		$html = (string) $html;
		// A first heading that is the title again: the post already shows it.
		$html = (string) preg_replace_callback( '#^\s*<h([1-3])[^>]*>(.*?)</h\1>\s*#isu', static function ( $match ) use ( $title ) {
			return self::same( $match[2], $title ) ? '' : $match[0];
		}, $html, 1 );
		// A heading that only names a part goes — « Introduction » or
		// « Conclusion » alone may be a section the site's plan asks for, and
		// stays; a label opening a heading or a paragraph is taken off what
		// follows it.
		$html = (string) preg_replace( '#<h([1-6])[^>]*>\s*' . self::PARTS . '\s*[:.\-–—]?\s*</h\1>\s*#iu', '', $html );
		$html = (string) preg_replace_callback( '#(<(?:h[1-6]|p|li)[^>]*>)\s*(?:<(strong|b|em)>)?\s*' . self::LABELS . '\s*[:\-–—]\s*(?:</\2>)?\s*(\S)#iu', static function ( $match ) {
			return $match[1] . mb_strtoupper( $match[3] );
		}, $html );
		return self::announce_page_two( $html, $language );
	}

	/**
	 * The line that tells the reader the recipe continues on page two, just
	 * before the break. The article is asked to write it; this adds one,
	 * naming what page two opens with, only when the last paragraph of page
	 * one does not already say so.
	 */
	private static function announce_page_two( $html, $language ) {
		$at = strpos( $html, '<!--nextpage-->' );
		if ( false === $at ) { return $html; }
		$before = substr( $html, 0, $at );
		$after = substr( $html, $at );
		if ( preg_match( '#<p[^>]*>((?:(?!</?p[\s>]).)*)</p>\s*$#isu', $before, $last ) && preg_match( '/page\s*(?:2|deux|two|suivante|next)|página\s*2|الصفحة/iu', wp_strip_all_tags( $last[1] ) ) ) { return $html; }
		$heading = preg_match( '#<h2[^>]*>(.*?)</h2>#isu', $after, $h ) ? trim( wp_strip_all_tags( $h[1] ) ) : '';
		if ( '' === $heading ) { $heading = MSRWA_Profile::page_two_heading( $language ); }
		$notices = self::next_page_notices();
		$notice = sprintf( $notices[ (string) $language ] ?? $notices['fr'], $heading );
		return rtrim( $before ) . "\n<p>" . esc_html( $notice ) . "</p>\n" . $after;
	}

	/** Whether two headings say the same, whatever their case, accents or punctuation. */
	private static function same( $a, $b ) {
		$norm = static function ( $text ) {
			$text = mb_strtolower( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
			$text = function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
			return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) );
		};
		$x = $norm( $a );
		$y = $norm( $b );
		return '' !== $x && ( $x === $y || ( mb_strlen( $y ) > 8 && 0 === strpos( $x, $y ) && mb_strlen( $x ) - mb_strlen( $y ) <= 3 ) );
	}
}
