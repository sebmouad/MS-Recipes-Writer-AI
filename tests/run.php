<?php
/**
 * Runs every offline test in its own PHP process.
 *
 * Usage: php tests/run.php [name-fragment]
 * Exit code is non-zero when a test fails, so CI can gate on it.
 */
$root = dirname( __DIR__ );
$filter = isset( $argv[1] ) ? (string) $argv[1] : '';
$files = glob( $root . '/tests/test-*.php' );
sort( $files );

$lint_failures = array();
foreach ( array_merge( glob( $root . '/includes/*.php' ), glob( $root . '/tests/*.php' ), glob( $root . '/tests/lib/*.php' ), array( $root . '/ms-recipes-writer-ai.php', $root . '/uninstall.php' ) ) as $file ) {
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );
	if ( 0 !== $status ) { $lint_failures[] = $file . ': ' . implode( ' ', $output ); }
	$output = array();
}
echo $lint_failures ? "LINT  " . count( $lint_failures ) . " file(s) failed\n" : "LINT  ok\n";
foreach ( $lint_failures as $failure ) { echo '      ' . $failure . "\n"; }

$passed = 0; $failed = 0; $skipped = 0;
foreach ( $files as $file ) {
	$name = basename( $file );
	if ( $filter && false === strpos( $name, $filter ) ) { continue; }
	$output = array(); $status = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );
	$text = implode( "\n", $output );
	if ( 0 === $status && false !== strpos( $text, 'SKIP' ) ) { $skipped++; printf( "SKIP  %-34s %s\n", $name, trim( strtok( $text, "\n" ) ) ); continue; }
	if ( 0 === $status ) { $passed++; printf( "PASS  %-34s %s\n", $name, trim( strtok( $text, "\n" ) ) ); continue; }
	$failed++;
	printf( "FAIL  %s\n", $name );
	foreach ( $output as $line ) { echo '      ' . $line . "\n"; }
}
printf( "\n%d passed, %d failed, %d skipped\n", $passed, $failed, $skipped );
exit( $failed || $lint_failures ? 1 : 0 );
