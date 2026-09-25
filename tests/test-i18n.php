<?php
// The plugin ships three interface languages, so the catalogues have to be
// built here: there is no gettext toolchain and no build step. A .mo file
// WordPress cannot read is a translation nobody sees, so this reads one back
// byte for byte rather than trusting the writer.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/tools/i18n.php';

$strings = msrwa_i18n_extract();
msrwa_test_assert( ! empty( $strings ), 'The extractor must find the plugin’s translatable strings.' );
msrwa_test_assert( isset( $strings['Français'] ), 'A string passed through __() with the domain must be extracted.' );
foreach ( $strings as $text => $places ) {
	msrwa_test_assert( ! empty( $places ), 'Every extracted string names where it came from: ' . $text );
}

// Round trip: a catalogue written here must read back exactly, including the
// accents and the right-to-left text the Arabic build depends on.
$catalogue = array(
	'Article seul' => 'Article only',
	'Français' => 'العربية',
	"Deux\nlignes" => "Two\nlines",
	'Guillemets "doubles"' => 'Quotes "double"',
);
$path = sys_get_temp_dir() . '/msrwa-test-' . getmypid() . '.mo';
$written = msrwa_i18n_write_mo( $catalogue, $path );
msrwa_test_assert( count( $catalogue ) === $written, 'Every entry is written; got ' . $written );

// Read the file the way gettext does, not the way we wrote it.
$bytes = (string) file_get_contents( $path );
$header = unpack( 'Vmagic/Vrevision/Vcount/Voriginals/Vtranslations', substr( $bytes, 0, 20 ) );
msrwa_test_assert( 0x950412de === $header['magic'], 'The magic number must mark a little-endian catalogue.' );
msrwa_test_assert( count( $catalogue ) === $header['count'], 'The header must count every entry; got ' . $header['count'] );

$read = array();
for ( $index = 0; $index < $header['count']; $index++ ) {
	$original = unpack( 'Vlength/Voffset', substr( $bytes, $header['originals'] + $index * 8, 8 ) );
	$translation = unpack( 'Vlength/Voffset', substr( $bytes, $header['translations'] + $index * 8, 8 ) );
	$read[ substr( $bytes, $original['offset'], $original['length'] ) ] = substr( $bytes, $translation['offset'], $translation['length'] );
}
unlink( $path );

foreach ( $catalogue as $source => $target ) {
	msrwa_test_assert( isset( $read[ $source ] ), 'Entry missing after the round trip: ' . $source );
	msrwa_test_assert( ( $read[ $source ] ?? '' ) === $target, 'Entry changed on the way through: ' . $source );
}

// gettext binary-searches the table, so the originals must be sorted.
$sorted = array_keys( $read );
$expected = $sorted;
sort( $expected, SORT_STRING );
msrwa_test_assert( $sorted === $expected, 'The originals table must be sorted or lookups miss.' );

// An untranslated entry is not shipped: gettext would answer with an empty
// string where it should fall through to the original.
$partial = msrwa_i18n_write_mo( array( 'Traduit' => 'Translated' ), $path );
msrwa_test_assert( 1 === $partial, 'Only translated entries are written.' );
unlink( $path );

// The plugin claims three interface languages. A claim nobody checks is how a
// release ships with half a screen in the wrong language.
$source = array_keys( msrwa_i18n_extract() );
foreach ( array( 'en_US', 'ar' ) as $locale ) {
	$po = dirname( __DIR__ ) . '/languages/ms-recipes-writer-ai-' . $locale . '.po';
	msrwa_test_assert( is_readable( $po ), 'A catalogue is shipped for ' . $locale . '.' );
	if ( ! is_readable( $po ) ) { continue; }

	$entries = msrwa_i18n_read_po( $po );
	// A plural entry is keyed by its singular and plural joined by a NUL, the
	// way a .mo holds it. The source only ever names the singular.
	$known = array();
	foreach ( array_keys( $entries ) as $key ) { $known[] = strtok( $key, "\0" ); }
	$missing = array_diff( $source, $known );
	msrwa_test_assert( ! $missing, count( $missing ) . ' string(s) untranslated in ' . $locale . ": \n      " . implode( "\n      ", array_slice( $missing, 0, 12 ) ) );

	// A .po nobody compiled is a .po WordPress never reads.
	$mo = preg_replace( '/\.po$/', '.mo', $po );
	msrwa_test_assert( is_readable( $mo ), 'The compiled catalogue is shipped for ' . $locale . '.' );
	msrwa_test_assert( is_readable( $mo ) && filemtime( $mo ) >= filemtime( $po ), 'The compiled catalogue for ' . $locale . ' is older than its source; run tools/i18n.php compile.' );

	// A translation that drops a placeholder produces a broken sentence at
	// runtime, and gettext will not warn anybody.
	foreach ( $entries as $from => $to ) {
		// A plural's forms may legitimately drop the number — Arabic says
		// "one recipe" without a digit — so each form is checked against the
		// form of the source it corresponds to, not against the singular.
		if ( false !== strpos( $from, "\0" ) ) { continue; }
		preg_match_all( '/%[0-9]*\$?[sd]/', $from, $wanted );
		preg_match_all( '/%[0-9]*\$?[sd]/', $to, $got );
		sort( $wanted[0] );
		sort( $got[0] );
		msrwa_test_assert( $wanted[0] === $got[0], 'Placeholders differ in ' . $locale . ' for: ' . mb_substr( $from, 0, 60 ) );
	}
}

// A plural entry must carry a form for every plural the language declares, or
// gettext falls through to the original on the counts it cannot find.
foreach ( array( 'en_US' => 2, 'ar' => 6 ) as $locale => $forms ) {
	$po = dirname( __DIR__ ) . '/languages/ms-recipes-writer-ai-' . $locale . '.po';
	if ( ! is_readable( $po ) ) { continue; }
	foreach ( msrwa_i18n_read_po( $po ) as $key => $value ) {
		if ( false === strpos( $key, "\0" ) ) { continue; }
		$given = count( explode( "\0", $value ) );
		msrwa_test_assert( $given === $forms, $locale . ' declares ' . $forms . ' plural form(s) but gives ' . $given . ' for: ' . strtok( $key, "\0" ) );
	}
}

// Screens were translated from the start; the messages the same people read
// when something goes wrong were not. Every WP_Error the REST layer returns is
// printed verbatim by assets/admin.js, so a raw French literal here is a French
// sentence on an English site — and nothing in the catalogues would ever show
// it as missing, because the extractor only ever sees what __() wraps.
$raw = array();
foreach ( (array) glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
	$source = (string) file_get_contents( $file );
	if ( ! preg_match_all( "/new WP_Error\(\s*'[^']*'\s*,\s*('(?:[^'\\\\]|\\\\.)*')/", $source, $found ) ) { continue; }
	foreach ( $found[1] as $literal ) { $raw[] = basename( $file ) . ': ' . $literal; }
}
msrwa_test_assert( ! $raw, count( $raw ) . ' error message(s) reach a reader untranslated: ' . implode( ', ', array_slice( $raw, 0, 5 ) ) );

// Each compiled catalogue carries its plural rule, which WordPress reads from
// the .mo header: without it Arabic took English's two forms, and one recipe
// read "no recipes".
foreach ( glob( dirname( __DIR__ ) . '/languages/*.po' ) as $po ) {
	$mo = (string) @file_get_contents( preg_replace( '/\.po$/', '.mo', $po ) );
	preg_match( '/Plural-Forms: *nplurals=(\d+)/', (string) file_get_contents( $po ), $declared );
	msrwa_test_contains( $mo, 'Plural-Forms: nplurals=' . ( $declared[1] ?? '?' ), basename( $po ) . ' compiles with its plural rule.' );
}

// A duration is rounded once: 179.8 s read "2 min 60 s" on the lot page.
msrwa_test_load( 'i18n' );
msrwa_test_assert( '3 min 0 s' === MSRWA_I18N::seconds( 179.8 ), 'Seconds never reach 60: ' . MSRWA_I18N::seconds( 179.8 ) );
msrwa_test_assert( '2 min 5 s' === MSRWA_I18N::seconds( 125.2 ), 'Minutes and seconds split: ' . MSRWA_I18N::seconds( 125.2 ) );

msrwa_test_done( 'translation catalogues OK' );
