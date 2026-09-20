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
		$text = trim( (string) $text );
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
	public static function decode_or_fail( $text, $label ) {
		$json = self::decode( $text );
		if ( null === $json ) { throw new Exception( 'Sortie JSON invalide pour ' . $label . '.' ); }
		return $json;
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
