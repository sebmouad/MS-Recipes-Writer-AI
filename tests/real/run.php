<?php
/**
 * Runner for the real test layer.
 *
 * Real tests run inside a WordPress installation through WP-CLI and spend real
 * money with real providers. This runner checks the prerequisites first and
 * explains exactly what is missing rather than failing obscurely.
 *
 * Usage: php tests/real/run.php [name-fragment]
 */
$required = array(
	'MSRWA_TEST_WP_PATH'   => 'Absolute path to a WordPress install with the plugin active',
	'MSRWA_TEST_BUDGET_USD' => 'Maximum a single run may spend, for example 2.00',
	'MSRWA_OPENAI_KEY'     => 'OpenAI key with text, image and web search access',
);
$optional = array(
	'MSRWA_TEST_SITE_URL' => 'Site URL, for draft and structured-data checks',
	'MSRWA_GEMINI_KEY'    => 'Only if Gemini takes part in the routing',
	'MSRWA_CLAUDE_KEY'    => 'Only if Claude takes part in the routing',
);

$missing = array();
foreach ( $required as $name => $why ) {
	if ( '' === (string) getenv( $name ) ) { $missing[ $name ] = $why; }
}

echo "MS Recipes Writer — real test layer\n\n";
foreach ( array_merge( $required, $optional ) as $name => $why ) {
	$set = '' !== (string) getenv( $name );
	printf( "  %s %-24s %s\n", $set ? '[ok]' : '[--]', $name, $why );
}
echo "\n";

if ( $missing ) {
	echo "Not ready: " . count( $missing ) . " required value(s) missing.\n";
	echo "Set them in the environment (never in the repository), then run this again.\n";
	echo "See docs/TESTING.md, section Credentials.\n";
	exit( 2 );
}

$wp = rtrim( (string) getenv( 'MSRWA_TEST_WP_PATH' ), '/' );
if ( ! is_dir( $wp ) || ! file_exists( $wp . '/wp-load.php' ) ) {
	echo "MSRWA_TEST_WP_PATH does not look like a WordPress installation: {$wp}\n";
	exit( 2 );
}
exec( 'command -v wp', $found, $status );
if ( 0 !== $status ) {
	echo "WP-CLI is not installed; real tests run through `wp eval-file`.\n";
	echo "Install it from https://wp-cli.org/ and run this again.\n";
	exit( 2 );
}

$filter = isset( $argv[1] ) ? (string) $argv[1] : '';
$files = glob( __DIR__ . '/test-*.php' );
sort( $files );
if ( ! $files ) { echo "No real tests yet. Add tests/real/test-<subject>.php (see docs/TESTING.md).\n"; exit( 0 ); }

$passed = 0; $failed = 0;
foreach ( $files as $file ) {
	$name = basename( $file );
	if ( $filter && false === strpos( $name, $filter ) ) { continue; }
	$command = 'wp --path=' . escapeshellarg( $wp ) . ' eval-file ' . escapeshellarg( $file ) . ' 2>&1';
	$output = array(); $status = 0;
	exec( $command, $output, $status );
	if ( 0 === $status ) { $passed++; printf( "PASS  %-34s %s\n", $name, trim( (string) end( $output ) ) ); continue; }
	$failed++;
	printf( "FAIL  %s\n", $name );
	foreach ( $output as $line ) { echo '      ' . $line . "\n"; }
}
printf( "\n%d passed, %d failed\n", $passed, $failed );
exit( $failed ? 1 : 0 );
