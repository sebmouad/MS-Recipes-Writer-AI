<?php
// The plugin header, the runtime constant and the README must agree: the
// version drives the database migration, so a mismatch ships a stale schema.
$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/ms-recipes-writer-ai.php' );
$readme = file_get_contents( $root . '/README.md' );
preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)\s*$/m', $plugin, $header );
preg_match( "/define\(\s*'MSRWA_VERSION',\s*'([0-9.]+)'\s*\)/", $plugin, $constant );
preg_match( '/La version `([0-9.]+)`/', $readme, $documented );
$failures = array();
if ( empty( $header[1] ) || empty( $constant[1] ) ) { $failures[] = 'Version header or constant missing.'; }
elseif ( $header[1] !== $constant[1] ) { $failures[] = 'Plugin header ' . $header[1] . ' does not match MSRWA_VERSION ' . $constant[1] . '.'; }
if ( empty( $documented[1] ) || $documented[1] !== ( $header[1] ?? '' ) ) { $failures[] = 'README documents ' . ( $documented[1] ?? 'nothing' ) . ' instead of ' . ( $header[1] ?? '?' ) . '.'; }
if ( false === strpos( $readme, '## Version ' . ( $header[1] ?? '' ) ) ) { $failures[] = 'README has no changelog section for version ' . ( $header[1] ?? '?' ) . '.'; }
foreach ( glob( $root . '/includes/*.php' ) as $file ) {
	if ( false === strpos( file_get_contents( $file ), "if ( ! defined( 'ABSPATH' ) ) { exit; }" ) ) { $failures[] = basename( $file ) . ' is missing the direct-access guard.'; }
}
if ( $failures ) { foreach ( $failures as $failure ) { fwrite( STDERR, 'FAIL: ' . $failure . "\n" ); } exit( 1 ); }
echo "MSRWA release metadata OK\n";
