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

msrwa_test_done( 'translation catalogues OK' );
