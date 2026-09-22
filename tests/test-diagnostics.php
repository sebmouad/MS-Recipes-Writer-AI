<?php
// The diagnostic screen exists to answer "why is nothing happening", so the
// answer has to be right on a site where something really is wrong. Production
// classes first, so the harness's stand-in for MSRWA_Settings steps aside: a
// double would report keys this site does not have.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $class ) { require_once $class; }
require_once MSRWA_DIR . 'includes/engine/load.php';
require __DIR__ . '/bootstrap.php';

msrwa_test_as_admin();

/** The tone reported for one check, by title. */
function msrwa_tone( array $checks, $title ) {
	foreach ( $checks as $check ) {
		if ( $title === $check['title'] ) { return $check['tone']; }
	}
	return 'missing';
}

// --- A site where nothing has been set up ------------------------------

$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$bare = MSRWA_Diagnostics::checks();

msrwa_test_assert( 'stop' === msrwa_tone( $bare, 'Tables' ), 'No tables is a stop, not a warning: nothing can run.' );
msrwa_test_assert( 'stop' === msrwa_tone( $bare, 'Clés d’API' ), 'No key at all is a stop.' );
msrwa_test_assert( 'stop' === msrwa_tone( $bare, 'Routage' ), 'A step routed to a provider with no key is a stop.' );
msrwa_test_assert( 'stop' === MSRWA_Diagnostics::worst( $bare ), 'The worst tone is what the head reports.' );

// Every check says what was measured, and a check that is not green says what
// to do about it — a diagnosis with no remedy sends the reader back to guessing.
foreach ( $bare as $check ) {
	msrwa_test_assert( '' !== trim( (string) $check['detail'] ), $check['title'] . ' reports what it measured.' );
	msrwa_test_assert(
		'good' === $check['tone'] || '' !== trim( (string) $check['remedy'] ),
		$check['title'] . ' says what to do about it.'
	);
	msrwa_test_assert( in_array( $check['tone'], array( 'good', 'warn', 'stop' ), true ), $check['title'] . ' carries a tone a stylesheet knows.' );
}

// --- A site that has been installed, with a role that lost a capability ---

$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SHOW TABLES', array( 'wp_msrwa_runs' ) );
update_option( 'msrwa_schema', MSRWA_DB::SCHEMA );
msrwa_test_roles( array(
	'administrator' => array( 'msrwa_create', 'msrwa_view_all', 'msrwa_manage' ),
	'editor' => array(),
) );
$installed = MSRWA_Diagnostics::checks();

msrwa_test_assert( 'good' === msrwa_tone( $installed, 'Tables' ), 'Tables present and the schema current reads green.' );
msrwa_test_assert( 'warn' === msrwa_tone( $installed, 'Droits' ), 'A role that lost a capability is a warning, not a stop: the work still runs for everyone else.' );

// A schema older than this release means the migration has not run yet. It is a
// warning and not a stop, because the next admin page load performs it.
update_option( 'msrwa_schema', MSRWA_DB::SCHEMA - 1 );
msrwa_test_assert( 'warn' === msrwa_tone( MSRWA_Diagnostics::checks(), 'Tables' ), 'A schema behind this release warns rather than stops.' );

// --- worst() ------------------------------------------------------------

$tone = static function ( $value ) { return array( 'tone' => $value, 'title' => 'x', 'detail' => 'x', 'remedy' => '' ); };
msrwa_test_assert( 'stop' === MSRWA_Diagnostics::worst( array( $tone( 'good' ), $tone( 'warn' ), $tone( 'stop' ) ) ), 'One stop outranks any number of warnings.' );
msrwa_test_assert( 'warn' === MSRWA_Diagnostics::worst( array( $tone( 'good' ), $tone( 'warn' ) ) ), 'A warning outranks green.' );
msrwa_test_assert( 'good' === MSRWA_Diagnostics::worst( array( $tone( 'good' ) ) ), 'All green reads green.' );
msrwa_test_assert( 'good' === MSRWA_Diagnostics::worst( array() ), 'Nothing to report is not a failure.' );

// --- The report somebody pastes into a support thread -------------------

$report = MSRWA_Diagnostics::report( $bare );
msrwa_test_contains( $report, MSRWA_VERSION, 'The report names the version it came from.' );
msrwa_test_contains( $report, 'PHP ', 'The report names the PHP version.' );
foreach ( $bare as $check ) {
	msrwa_test_contains( $report, $check['title'], 'The report carries the ' . $check['title'] . ' line.' );
}
// It is meant to be pasted in public, so it must not carry anything private.
foreach ( array( 'sk-', 'key_env', ABSPATH ) as $secret ) {
	msrwa_test_missing( $report, $secret, 'The report carries no ' . $secret . '.' );
}

msrwa_test_done( 'diagnostics' );
