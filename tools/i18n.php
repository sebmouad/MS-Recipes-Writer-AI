<?php
/**
 * Extracts translatable strings and compiles the catalogues.
 *
 *   php tools/i18n.php extract     rewrite languages/<domain>.pot from the source
 *   php tools/i18n.php compile     build every languages/*.po into a .mo
 *   php tools/i18n.php status      what is translated and what is not
 *
 * There is no gettext toolchain on this machine and the plugin has no build
 * step, so both halves are written here: a scanner that understands the four
 * call shapes the code uses, and a .mo writer following the GNU format. A
 * translation nobody can compile is a translation nobody ships.
 */

const MSRWA_DOMAIN = 'ms-recipes-writer-ai';

function msrwa_i18n_root() { return dirname( __DIR__ ); }

/** Every translatable string in the plugin, with where it was found. */
function msrwa_i18n_extract() {
	$strings = array();
	$files = array_merge(
		glob( msrwa_i18n_root() . '/includes/*.php' ),
		glob( msrwa_i18n_root() . '/includes/**/*.php' ),
		array( msrwa_i18n_root() . '/ms-recipes-writer-ai.php' )
	);

	foreach ( $files as $file ) {
		$source = file_get_contents( $file );
		$lines = explode( "\n", $source );
		// __( 'text', 'domain' ) and esc_html__, esc_attr__, _e, esc_html_e.
		$pattern = '/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])' . preg_quote( MSRWA_DOMAIN, '/' ) . '\3\s*\)/';
		if ( preg_match_all( $pattern, $source, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $found as $match ) {
				$text = stripcslashes( $match[2][0] );
				$line = substr_count( substr( $source, 0, $match[0][1] ), "\n" ) + 1;
				if ( ! isset( $strings[ $text ] ) ) { $strings[ $text ] = array(); }
				$strings[ $text ][] = basename( $file ) . ':' . $line;
			}
		}
		unset( $lines );
	}

	ksort( $strings );
	return $strings;
}

function msrwa_i18n_po_escape( $text ) {
	return str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\"', '\n', '\t' ), $text );
}

function msrwa_i18n_write_pot( array $strings ) {
	$out = "# Copyright (C) MS Recipes Writer AI\n"
		. "msgid \"\"\nmsgstr \"\"\n"
		. "\"Project-Id-Version: MS Recipes Writer AI\\n\"\n"
		. "\"MIME-Version: 1.0\\n\"\n"
		. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
		. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
		. "\"X-Generator: tools/i18n.php\\n\"\n\n";

	foreach ( $strings as $text => $places ) {
		$out .= '#: ' . implode( ' ', array_unique( $places ) ) . "\n";
		$out .= 'msgid "' . msrwa_i18n_po_escape( $text ) . "\"\n";
		$out .= "msgstr \"\"\n\n";
	}

	$path = msrwa_i18n_root() . '/languages/' . MSRWA_DOMAIN . '.pot';
	file_put_contents( $path, $out );
	return $path;
}

/** Reads a .po into msgid => msgstr, ignoring anything untranslated. */
function msrwa_i18n_read_po( $file ) {
	$entries = array();
	$id = null;
	$str = null;
	$mode = '';
	foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || 0 === strpos( $line, '#' ) ) { continue; }
		if ( 0 === strpos( $line, 'msgid ' ) ) {
			if ( null !== $id && null !== $str && '' !== $str ) { $entries[ $id ] = $str; }
			$id = msrwa_i18n_po_unescape( substr( $line, 6 ) );
			$str = null;
			$mode = 'id';
			continue;
		}
		if ( 0 === strpos( $line, 'msgstr ' ) ) { $str = msrwa_i18n_po_unescape( substr( $line, 7 ) ); $mode = 'str'; continue; }
		if ( '"' === substr( $line, 0, 1 ) ) {
			$piece = msrwa_i18n_po_unescape( $line );
			if ( 'id' === $mode ) { $id .= $piece; } elseif ( 'str' === $mode ) { $str .= $piece; }
		}
	}
	if ( null !== $id && null !== $str && '' !== $str ) { $entries[ $id ] = $str; }
	unset( $entries[''] );
	return $entries;
}

function msrwa_i18n_po_unescape( $quoted ) {
	$quoted = trim( $quoted );
	if ( '"' === substr( $quoted, 0, 1 ) ) { $quoted = substr( $quoted, 1, -1 ); }
	return str_replace( array( '\n', '\t', '\"', '\\\\' ), array( "\n", "\t", '"', '\\' ), $quoted );
}

/**
 * Writes a GNU .mo file.
 *
 * Little-endian, the original strings sorted, each table holding a length and
 * an offset. WordPress reads exactly this and nothing else.
 */
function msrwa_i18n_write_mo( array $entries, $path ) {
	ksort( $entries );
	$ids = array_keys( $entries );
	$count = count( $ids );

	$original = '';
	$translated = '';
	$original_table = array();
	$translated_table = array();

	foreach ( $ids as $id ) {
		$original_table[] = array( strlen( $id ), strlen( $original ) );
		$original .= $id . "\0";
		$translated_table[] = array( strlen( $entries[ $id ] ), strlen( $translated ) );
		$translated .= $entries[ $id ] . "\0";
	}

	$header = 28;
	$original_offset = $header;
	$translated_offset = $original_offset + $count * 8;
	$hash_offset = $translated_offset + $count * 8;
	$originals_at = $hash_offset;
	$translations_at = $originals_at + strlen( $original );

	$out = pack( 'V', 0x950412de ) . pack( 'V', 0 ) . pack( 'V', $count )
		. pack( 'V', $original_offset ) . pack( 'V', $translated_offset )
		. pack( 'V', 0 ) . pack( 'V', $hash_offset );

	foreach ( $original_table as $entry ) { $out .= pack( 'V', $entry[0] ) . pack( 'V', $originals_at + $entry[1] ); }
	foreach ( $translated_table as $entry ) { $out .= pack( 'V', $entry[0] ) . pack( 'V', $translations_at + $entry[1] ); }
	$out .= $original . $translated;

	file_put_contents( $path, $out );
	return $count;
}

// Required by a test as well as run from a terminal, so the commands only
// execute when this file is the one that was invoked.
if ( 'cli' !== PHP_SAPI || empty( $argv[0] ) || realpath( $argv[0] ) !== realpath( __FILE__ ) ) { return; }

$command = $argv[1] ?? 'status';

if ( 'extract' === $command ) {
	$strings = msrwa_i18n_extract();
	$path = msrwa_i18n_write_pot( $strings );
	printf( "%d string(s) → %s\n", count( $strings ), basename( $path ) );
	exit( 0 );
}

if ( 'compile' === $command ) {
	foreach ( glob( msrwa_i18n_root() . '/languages/*.po' ) as $po ) {
		$entries = msrwa_i18n_read_po( $po );
		$mo = preg_replace( '/\.po$/', '.mo', $po );
		printf( "%-40s %d translation(s)\n", basename( $mo ), msrwa_i18n_write_mo( $entries, $mo ) );
	}
	exit( 0 );
}

$strings = msrwa_i18n_extract();
printf( "%d translatable string(s) in the source\n", count( $strings ) );
foreach ( glob( msrwa_i18n_root() . '/languages/*.po' ) as $po ) {
	$entries = msrwa_i18n_read_po( $po );
	$missing = array_diff( array_keys( $strings ), array_keys( $entries ) );
	printf( "%-30s %3d translated, %3d missing\n", basename( $po ), count( $entries ), count( $missing ) );
	if ( in_array( '--missing', $argv, true ) ) {
		foreach ( $missing as $text ) { echo '    ' . $text . "\n"; }
	}
}
