<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Decodes the JSON a model returns, which is not always the JSON a parser wants.
 *
 * Three failures were measured against real provider answers and each stage here
 * repairs exactly one of them: a sentence written before the object, a Markdown
 * fence around it, and a raw newline or tab left inside a string value. Nothing
 * else is repaired — a genuinely broken answer must still fail loudly.
 */
final class MSRWA_Json {

	/** Returns the decoded array, or null when the text cannot be read as one. */
	public static function decode( $text ) {
		$text = self::valid_utf8( (string) $text );
		$text = trim( $text );
		if ( '' === $text ) { return null; }
		foreach ( array( $text, self::unfence( $text ), self::slice( $text ) ) as $candidate ) {
			if ( '' === $candidate ) { continue; }
			$json = json_decode( $candidate, true );
			if ( is_array( $json ) ) { return $json; }
			$json = json_decode( self::escape_control_characters( $candidate ), true );
			if ( is_array( $json ) ) { return $json; }
		}
		return null;
	}

	/** Same contract as decode(), raising the pipeline's error when it fails. */
	/**
	 * Drops byte sequences that are not valid UTF-8. An answer stopped at
	 * max_tokens is cut mid-character, and that single broken character makes
	 * json_encode return false for the whole string — a 14 500-token answer was
	 * billed and stored as an empty one before this.
	 */
	public static function valid_utf8( $text ) {
		$text = (string) $text;
		if ( '' === $text || preg_match( '//u', $text ) ) { return $text; }
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]*$/', '', mb_convert_encoding( $text, 'UTF-8', 'UTF-8' ) );
	}

	private static function unfence( $text ) {
		if ( 0 !== strpos( $text, '```' ) ) { return ''; }
		return trim( preg_replace( '/^```[a-zA-Z]*\s*|\s*```$/', '', $text ) );
	}

	/** The outermost object or array, for answers that open with a sentence. */
	private static function slice( $text ) {
		$starts = array_filter( array( strpos( $text, '{' ), strpos( $text, '[' ) ), 'is_int' );
		$ends = array_filter( array( strrpos( $text, '}' ), strrpos( $text, ']' ) ), 'is_int' );
		if ( ! $starts || ! $ends ) { return ''; }
		$start = min( $starts );
		$end = max( $ends );
		return $end > $start ? substr( $text, $start, $end - $start + 1 ) : '';
	}

	/**
	 * Escapes control characters that appear inside string literals. Claude Sonnet
	 * wrote a real newline inside an 18 KB content_html value; every parser rejects
	 * it, and the answer was otherwise complete.
	 */
	private static function escape_control_characters( $text ) {
		$out = '';
		$in_string = false;
		$escaped = false;
		$length = strlen( $text );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $text[ $i ];
			if ( $escaped ) { $out .= $char; $escaped = false; continue; }
			if ( '\\' === $char && $in_string ) { $out .= $char; $escaped = true; continue; }
			if ( '"' === $char ) { $in_string = ! $in_string; $out .= $char; continue; }
			if ( $in_string && $char < ' ' ) {
				$map = array( "\n" => '\\n', "\r" => '\\r', "\t" => '\\t', "\f" => '\\f', "\x08" => '\\b' );
				$out .= isset( $map[ $char ] ) ? $map[ $char ] : sprintf( '\\u%04x', ord( $char ) );
				continue;
			}
			$out .= $char;
		}
		return $out;
	}
}
