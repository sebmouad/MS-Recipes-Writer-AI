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

	public static function tidy( $html, $title, $language = 'fr', $dish = '' ) {
		$html = (string) $html;
		// The theme prints the post's title as the page's h1: one in the text
		// is a second title.
		$html = (string) preg_replace( '#<h1[^>]*>.*?</h1>\s*#isu', '', $html );
		// A first heading that is the title again, or opens on the dish's
		// name, repeats what the reader has just read above it (the owner's
		// rule, 2026-09-28): the name goes, the question it opened stays.
		$html = (string) preg_replace_callback( '#^\s*<h([2-3])([^>]*)>(.*?)</h\1>\s*#isu', static function ( $match ) use ( $title, $dish ) {
			if ( self::same( $match[3], $title ) || ( '' !== $dish && self::same( $match[3], $dish ) ) ) { return ''; }
			$rest = self::after_name( $match[3], '' !== $dish ? $dish : $title );
			if ( null === $rest ) { return $match[0]; }
			return count( preg_split( '/\s+/u', $rest ) ) < 3 ? '' : '<h' . $match[1] . $match[2] . '>' . esc_html( $rest ) . '</h' . $match[1] . ">\n";
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

	/**
	 * The post's title: the article's headline when it is a headline for this
	 * dish — plain words, the dish's name in it, a readable length — and the
	 * dish's name otherwise. Model output is data: tags go.
	 */
	public static function headline( $headline, $dish ) {
		$dish = trim( wp_strip_all_tags( (string) $dish ) );
		$headline = class_exists( 'MSRWA_Intake' ) ? MSRWA_Intake::plain_title( wp_strip_all_tags( (string) $headline ) ) : trim( wp_strip_all_tags( (string) $headline ) );
		$headline = rtrim( $headline, " !.\t" );
		$length = mb_strlen( $headline );
		if ( $length < 20 || $length > 90 || '' === $dish ) { return '' !== $dish ? $dish : $headline; }
		$fold = static function ( $text ) {
			$text = mb_strtolower( (string) $text );
			return function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
		};
		$named = array_values( array_filter( preg_split( '/[^\p{L}\p{N}]+/u', $fold( $dish ) ), static function ( $word ) { return mb_strlen( $word ) >= 4; } ) );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $fold( $headline ) );
		// Its first two telling words at least: « Osso buco à la milanaise… »
		// names the « Osso buco de veau classique ».
		foreach ( array_slice( $named, 0, 2 ) as $word ) {
			if ( ! in_array( $word, $words, true ) ) { return $dish; }
		}
		return mb_strtoupper( mb_substr( $headline, 0, 1 ) ) . mb_substr( $headline, 1 );
	}

	/**
	 * A heading without the dish's name it opens on — « La tarte aux pommes
	 * normande : quelle texture ? » gives « Quelle texture ? », « Quiche
	 * lorraine facile : une tarte dorée » gives « Une tarte dorée » — or null
	 * when it does not open on it. Case, accents and an article before the
	 * name do not matter; a word or two before a colon or dash go with it.
	 */
	public static function after_name( $heading, $name ) {
		$fold = static function ( $text ) {
			$text = mb_strtolower( (string) $text );
			return function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
		};
		$words = array_values( array_filter( preg_split( '/[^\p{L}\p{N}]+/u', $fold( wp_strip_all_tags( (string) $name ) ) ), 'strlen' ) );
		if ( count( $words ) < 1 ) { return null; }
		$text = trim( html_entity_decode( wp_strip_all_tags( (string) $heading ), ENT_QUOTES, 'UTF-8' ) );
		$pattern = '/^(?:(?:le|la|les|l|un|une|des|the|a|an|el|los|las)[\s\x{2019}\']+)?' . implode( '[^\p{L}\p{N}]+', array_map( static function ( $word ) { return preg_quote( $word, '/' ); }, $words ) ) . '(?![\p{L}\p{N}])/u';
		// Matched on the folded text, cut on the original: folding keeps one
		// character for one here, since only a word's letters are compared.
		$folded = '';
		foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$one = $fold( $char );
			$one = 1 === mb_strlen( $one ) ? $one : $char;
			$folded .= $one;
		}
		if ( ! preg_match( $pattern, $folded, $found ) ) { return null; }
		$rest = mb_substr( $text, mb_strlen( $found[0] ) );
		// « facile : une tarte dorée » — the name's own qualifier goes with it.
		$rest = (string) preg_replace( '/^\s*(?:[\p{L}\p{N}\x{2019}\'-]+\s+){0,2}?[\p{L}\p{N}\x{2019}\'-]*\s*[:\x{2013}\x{2014}]\s*/u', '', $rest, 1 );
		$rest = trim( (string) preg_replace( '/^[\s:,;.\x{2013}\x{2014}-]+/u', '', $rest ) );
		return '' === $rest ? '' : mb_strtoupper( mb_substr( $rest, 0, 1 ) ) . mb_substr( $rest, 1 );
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
