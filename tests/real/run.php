<?php
/**
 * Runner for the real test layer.
 *
 * Real tests drive a live WordPress over REST with an application password and
 * may spend real money. This runner checks the prerequisites first and names
 * what is missing rather than failing obscurely.
 *
 * Usage: php tests/real/run.php [name-fragment]
 */
$required = array(
	'MSRWA_TEST_SITE_URL'   => 'Site URL, for example https://example.com',
	'MSRWA_WP_USER'         => 'WordPress user with the administrator role',
	'MSRWA_WP_APP_PASSWORD' => 'That user\'s application password',
);
$optional = array(
	'MSRWA_TEST_BUDGET_USD' => 'Cap for one run; tests that call a provider refuse to start without it',
);

echo "MS Recipes Writer — real tests\n\n";
$missing = array();
foreach ( array_merge( $required, $optional ) as $name => $why ) {
	$set = '' !== (string) getenv( $name );
	printf( "  %s %-24s %s\n", $set ? '[ok]' : '[--]', $name, $why );
	if ( ! $set && isset( $required[ $name ] ) ) { $missing[] = $name; }
}
echo "\n";
if ( $missing ) {
	echo 'Not ready: ' . implode( ', ', $missing ) . " missing.\n";
	echo "Set them in the environment, never in the repository. See docs/TESTING.md.\n";
	exit( 2 );
}

$filter = isset( $argv[1] ) ? (string) $argv[1] : '';
$files = glob( __DIR__ . '/test-*.php' );
sort( $files );
if ( ! $files ) { echo "No real tests yet. Add tests/real/test-<subject>.php — see docs/TESTING.md.\n"; exit( 0 ); }

$passed = 0; $failed = 0; $skipped = 0;
foreach ( $files as $file ) {
	$name = basename( $file );
	if ( $filter && false === strpos( $name, $filter ) ) { continue; }
	$output = array(); $status = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );
	$text = implode( "\n", $output );
	if ( 0 === $status && 0 === strpos( ltrim( $text ), 'SKIP' ) ) { $skipped++; printf( "SKIP  %-30s %s\n", $name, trim( strtok( ltrim( $text ), "\n" ) ) ); continue; }
	if ( 0 === $status ) {
		$passed++;
		printf( "PASS  %-30s %s\n", $name, trim( (string) end( $output ) ) );
		foreach ( $output as $line ) { if ( false !== strpos( $line, '·' ) ) { echo $line . "\n"; } }
		continue;
	}
	$failed++;
	printf( "FAIL  %s\n", $name );
	foreach ( $output as $line ) { echo '      ' . $line . "\n"; }
}
printf( "\n%d passed, %d failed, %d skipped\n", $passed, $failed, $skipped );
exit( $failed ? 1 : 0 );
