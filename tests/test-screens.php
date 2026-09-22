<?php
// Every screen renders, escapes, and shows each reader only what they may see.
// A screen that fatals on an empty site is the first thing a new installation
// meets, so they are all exercised with nothing in the database.
// Production classes first, so the harness's stand-ins step aside: a double
// answering for MSRWA_Settings would be exactly what hides a broken screen.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $class ) { require_once $class; }
require_once MSRWA_DIR . 'includes/engine/load.php';
require __DIR__ . '/bootstrap.php';

function msrwa_render( $callable ) {
	ob_start();
	try { call_user_func( $callable ); } catch ( Throwable $error ) { ob_end_clean(); return array( 'error' => $error->getMessage() ); }
	return array( 'html' => ob_get_clean() );
}

// --- A writer ----------------------------------------------------------

msrwa_test_as_editor( 7 );
foreach ( array(
	'MSRWA_Screen_Pass' => array( 'MSRWA_Screen_Pass', 'render' ),
	'MSRWA_Screen_Compose' => array( 'MSRWA_Screen_Compose', 'render' ),
	'MSRWA_Screen_Articles' => array( 'MSRWA_Screen_Articles', 'render' ),
) as $name => $callable ) {
	$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
	$_GET = array();
	$out = msrwa_render( $callable );
	msrwa_test_assert( isset( $out['html'] ), $name . ' must render for a writer; got: ' . ( $out['error'] ?? '' ) );
	if ( ! isset( $out['html'] ) ) { continue; }
	msrwa_test_contains( $out['html'], 'class="wrap msrwa"', $name . ' uses the shared shell.' );
	msrwa_test_missing( $out['html'], 'page=msrwa-settings&', $name . ' does not link a writer to administrator screens.' );
}

// The screens a writer may not reach refuse before touching the database.
foreach ( array(
	'MSRWA_Screen_Analysis' => array( 'MSRWA_Screen_Analysis', 'render' ),
	'MSRWA_Screen_Engine' => array( 'MSRWA_Screen_Engine', 'render' ),
	'MSRWA_Screen_Settings' => array( 'MSRWA_Screen_Settings', 'render' ),
) as $name => $callable ) {
	$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
	$out = msrwa_render( $callable );
	msrwa_test_assert( isset( $out['error'] ), $name . ' must refuse a writer.' );
	msrwa_test_assert( ! $GLOBALS['wpdb']->queries, $name . ' must refuse before it queries.' );
}

// --- An administrator --------------------------------------------------

msrwa_test_as_admin();
foreach ( array(
	'MSRWA_Screen_Pass' => array( 'MSRWA_Screen_Pass', 'render' ),
	'MSRWA_Screen_Analysis' => array( 'MSRWA_Screen_Analysis', 'render' ),
	'MSRWA_Screen_Engine' => array( 'MSRWA_Screen_Engine', 'render' ),
	'MSRWA_Screen_Settings' => array( 'MSRWA_Screen_Settings', 'render' ),
) as $name => $callable ) {
	$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
	$_GET = array();
	$out = msrwa_render( $callable );
	msrwa_test_assert( isset( $out['html'] ), $name . ' must render for an administrator; got: ' . ( $out['error'] ?? '' ) );
}

// The engine screen must reach every configuration group, or a promise that
// nothing is hardcoded quietly stops being true.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$engine = msrwa_render( array( 'MSRWA_Screen_Engine', 'render' ) );
foreach ( array_keys( array_merge( MSRWA_Engine_Settings::simple(), MSRWA_Engine_Settings::structural() ) ) as $group ) {
	msrwa_test_contains( $engine['html'] ?? '', 'msrwa_engine[' . $group . ']', 'The engine screen has a field for ' . $group . '.' );
}

// A key must never reach a screen, whatever else is on it.
msrwa_test_missing( $engine['html'] ?? '', 'key_env', 'The engine screen does not print where keys are kept.' );

// --- An empty site is not an error -------------------------------------

msrwa_test_contains( ( msrwa_render( array( 'MSRWA_Screen_Pass', 'render' ) )['html'] ?? '' ), 'ms-empty', 'With nothing to show, the pass invites the reader to act.' );

// --- A writer is never shown money, on the way in either ----------------

$GLOBALS['msrwa_test_options'][ MSRWA_Settings::OPTION ] = array( 'per_recipe_budget_usd' => 0.33 );

msrwa_test_as_editor( 7 );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$html = msrwa_render( array( 'MSRWA_Screen_Compose', 'render' ) )['html'] ?? '';
msrwa_test_missing( $html, 'id="ms-budget"', 'A writer is not asked for a ceiling they are not allowed to see.' );
msrwa_test_missing( $html, 'plafond', 'A writer is not told about ceilings on the way in either.' );

msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$html = msrwa_render( array( 'MSRWA_Screen_Compose', 'render' ) )['html'] ?? '';
msrwa_test_contains( $html, 'id="ms-budget"', 'Somebody who may set a ceiling is asked for one.' );
msrwa_test_contains( $html, 'value="0.33"', 'The ceiling offered is the site’s, not a number hardcoded in a template.' );

unset( $GLOBALS['msrwa_test_options'][ MSRWA_Settings::OPTION ] );

// The pass and the compose screen used to print the site's spend and a price per
// profile to writers — milestone five says an editor sees no cost anywhere.
msrwa_test_as_editor( 7 );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$pass = msrwa_render( array( 'MSRWA_Screen_Pass', 'render' ) )['html'] ?? '';
msrwa_test_missing( $pass, ' $<', 'A writer’s pass shows no amount.' );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'SUM(r.cost_usd)', 'A writer’s pass does not even ask for the spend.' );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
msrwa_test_missing( msrwa_render( array( 'MSRWA_Screen_Compose', 'render' ) )['html'] ?? '', 'ms-choice-cost', 'A writer is not shown a price per profile.' );

// A lot defaults to the site's article language, not always French.
msrwa_test_as_admin();
$GLOBALS['msrwa_test_options'][ MSRWA_Settings::OPTION ] = array( 'site_language' => 'en' );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
msrwa_test_contains( msrwa_render( array( 'MSRWA_Screen_Compose', 'render' ) )['html'] ?? '', "value=\"en\"  selected='selected'", 'The site language is the one offered.' );
unset( $GLOBALS['msrwa_test_options'][ MSRWA_Settings::OPTION ] );

// --- A writer reads a sentence, not a stack trace ----------------------------

msrwa_test_assert( 'stop' === MSRWA_UI::reason( array( 'status' => 'failed' ), array( array( 'step' => 'research', 'error' => 'No API key for claude.' ) ) )[0], 'A missing key stops.' );
msrwa_test_contains( MSRWA_UI::reason( array( 'status' => 'failed' ), array( array( 'step' => 'research', 'error' => 'No API key for claude.' ) ) )[1], 'clé', 'A missing key is said as a missing key.' );
msrwa_test_missing( MSRWA_UI::reason( array( 'status' => 'failed' ), array( array( 'step' => 'research', 'error' => 'No API key for claude.' ) ) )[1], 'claude', 'Without naming the provider to a writer.' );
msrwa_test_contains( MSRWA_UI::reason( array( 'status' => 'failed', 'error_message' => 'Plafond de 0.1000 $ atteint avant review.' ) )[1], 'plafond', 'A ceiling is said as a ceiling.' );
msrwa_test_contains( MSRWA_UI::reason( array( 'status' => 'failed' ), array( array( 'step' => 'article', 'error' => 'HTTP 503' ) ) )[1], 'répondu', 'An outage is said as one.' );
// Seen live: Anthropic answers 400 when the account is out of credit.
msrwa_test_contains( MSRWA_UI::reason( array( 'status' => 'failed' ), array( array( 'step' => 'canonical_recipe', 'error' => 'HTTP 400: {"error":{"message":"Your credit balance is too low to access the Anthropic API."}}' ) ) )[1], 'crédit', 'An empty provider account is said as one, not as a broken step.' );
msrwa_test_contains( MSRWA_UI::reason( array( 'status' => 'failed' ), array( array( 'step' => 'article', 'error' => 'recipe schema' ) ) )[1], 'Rédaction', 'Anything else names the step in words.' );
msrwa_test_contains( MSRWA_UI::reason( array( 'status' => 'done', 'draft_post_id' => 3, 'approved' => null ) )[1], 'Rien n’est publié', 'A finished recipe is never phrased as approved.' );

// --- Throwing a lot away is an administrator's, and never mid-flight ----

function msrwa_batch_html( $status, $owner ) {
	$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
	$GLOBALS['wpdb']->on( 'FROM wp_msrwa_batches WHERE id', array(
		array( 'id' => 4, 'owner_id' => $owner, 'label' => 'Plats du soir', 'status' => $status, 'recipes' => 2, 'images' => 0,
			'budget_usd' => 0.2, 'profile' => 'article', 'language' => 'fr', 'dispatch_at' => null,
			'config_json' => '', 'matching_json' => '', 'error_message' => '', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00' ),
	) );
	$_GET = array( 'batch_id' => 4 );
	$out = msrwa_render( array( 'MSRWA_Screen_Batch', 'render' ) );
	$_GET = array();
	return $out['html'] ?? '';
}

msrwa_test_as_admin( 1 );
msrwa_test_contains( msrwa_batch_html( 'ready', 1 ), 'ms-batch-delete', 'An administrator can throw away a lot that is not moving.' );
msrwa_test_missing( msrwa_batch_html( 'running', 1 ), 'ms-batch-delete', 'A lot with recipes in flight is never deleted from under them.' );

msrwa_test_as_editor( 7 );
msrwa_test_missing( msrwa_batch_html( 'ready', 7 ), 'ms-batch-delete', 'A writer cannot throw away a lot, not even their own.' );

// --- A lot that has not left is still somebody's ------------------------

msrwa_test_as_editor( 7 );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Batch::waiting();
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'owner_id = 7', 'A writer sees only their own lots waiting to leave.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), "status = 'ready'", 'Only lots that have not started are listed.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'ORDER BY (dispatch_at IS NULL), dispatch_at ASC', 'The one with an hour on it comes first.' );

msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Batch::waiting();
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'owner_id =', 'An administrator sees every lot waiting to leave.' );

// The section is silent when there is nothing waiting, rather than printing an
// empty table on the one screen meant to be read at a glance.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
msrwa_test_missing( ( msrwa_render( array( 'MSRWA_Screen_Pass', 'render' ) )['html'] ?? '' ), 'Pas encore parti', 'Nothing waiting, nothing said.' );

// --- Queue order is said where it is true, and only there ---------------

$waiting = array( 'id' => 9, 'batch_id' => 1, 'owner_id' => 1, 'label' => 'Tarte', 'status' => 'queued', 'step' => '', 'steps_done' => 0, 'steps_total' => 10, 'approved' => null, 'priority' => 5, 'draft_post_id' => 0 );
ob_start(); MSRWA_UI::ticket( $waiting, '#' ); $html = ob_get_clean();
msrwa_test_contains( $html, 'passe devant', 'A recipe pushed to the front says so while it waits.' );

$waiting['priority'] = 0;
ob_start(); MSRWA_UI::ticket( $waiting, '#' ); $html = ob_get_clean();
msrwa_test_missing( $html, 'passe devant', 'A recipe in the ordinary order says nothing about order.' );

// Reordering a recipe that is already moving would change nothing, so it is
// never offered.
$waiting['status'] = 'running';
$waiting['priority'] = 5;
ob_start(); MSRWA_UI::ticket( $waiting, '#' ); $html = ob_get_clean();
msrwa_test_missing( $html, 'passe devant', 'A recipe already running is past the queue.' );

// --- The REST base is not always a path ---------------------------------

// On a site without pretty permalinks the base is ?rest_route=/msrwa/v1, so a
// path carrying its own "?" lands inside that value and the route is never
// found. Every parameter goes through endpoint(), which picks the separator.
$script = (string) file_get_contents( MSRWA_DIR . 'assets/admin.js' );
msrwa_test_assert( ! preg_match( "/call\(\s*'\/[^']*'\s*\+[^,)]*\?/", $script ), 'No call pastes a query string onto its path.' );
msrwa_test_contains( $script, "url.indexOf('?') === -1 ? '?' : '&'", 'The separator is chosen from what the base already has.' );

msrwa_test_done( 'screens render and refuse correctly' );
