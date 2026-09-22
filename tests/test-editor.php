<?php
// The verdict is the one thing this plugin promises to put in front of an
// editor before they publish, so what it says — and what it refuses to say —
// is held here. Production classes first: the verdict reads post meta through
// MSRWA_Settings and MSRWA_Run, and a double for either would hide a hole.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $class ) { require_once $class; }
require_once MSRWA_DIR . 'includes/engine/load.php';
require __DIR__ . '/bootstrap.php';

msrwa_test_as_editor( 7 );

// --- Nothing judged it ---------------------------------------------------

$GLOBALS['msrwa_test_meta'] = array( 11 => array( '_msrwa_run_id' => 4 ) );
$none = MSRWA_Editor::verdict( 11 );
msrwa_test_assert( false === $none['judged'], 'A run with no final judgement says so rather than inventing one.' );
msrwa_test_assert( array() === $none['findings'], 'And reports no findings.' );
msrwa_test_assert( '' === MSRWA_Editor::tone( $none ), 'An unjudged article is not coloured as a problem.' );
msrwa_test_assert( 4 === $none['run_id'], 'The verdict knows which run produced the draft.' );

// --- A judgement with something blocking in it ---------------------------

$GLOBALS['msrwa_test_meta'] = array(
	12 => array(
		'_msrwa_run_id' => 9,
		'_msrwa_judge_report' => wp_json_encode( array(
			'approved' => false,
			'findings' => array(
				array( 'severity' => 'blocking', 'target' => 'article', 'reason' => 'Durée non sourcée.', 'quote' => 'une semaine', 'fix' => 'Ramener à trois jours.' ),
				array( 'severity' => 'minor', 'target' => 'featured', 'reason' => 'Coin surexposé.' ),
			),
			// A malformed entry must not take the panel down with it.
			'uncertainties' => array( 'Calories non recoupées.', '', array( 'not a string' ) ),
		) ),
		'_msrwa_seo_title' => 'Tarte aux pommes normande',
		'_msrwa_seo_description' => '',
	),
);
$verdict = MSRWA_Editor::verdict( 12 );

msrwa_test_assert( true === $verdict['judged'], 'A report that exists is read.' );
msrwa_test_assert( 2 === count( $verdict['findings'] ), 'Every finding is carried.' );
msrwa_test_assert( 1 === $verdict['blocking'], 'Only the blocking ones are counted as blocking.' );
msrwa_test_assert( true === $verdict['findings'][0]['blocking'], 'A blocking finding is marked as one.' );
msrwa_test_assert( false === $verdict['findings'][1]['blocking'], 'A minor one is not.' );
msrwa_test_assert( 'stop' === MSRWA_Editor::tone( $verdict ), 'Anything blocking colours the verdict as a stop.' );
msrwa_test_assert( array( 'Calories non recoupées.' ) === $verdict['uncertainties'], 'Empty and malformed uncertainties are dropped, not printed.' );
msrwa_test_assert( 1 === count( $verdict['seo'] ), 'Only the fields the article actually wrote are offered.' );

// Minor findings alone are a warning, never a stop.
$minor = $verdict;
$minor['blocking'] = 0;
array_shift( $minor['findings'] );
msrwa_test_assert( 'warn' === MSRWA_Editor::tone( $minor ), 'Minor findings warn rather than stop.' );
msrwa_test_assert( '' === MSRWA_Editor::tone( array( 'blocking' => 0, 'findings' => array() ) ), 'Nothing found is not a warning.' );

// --- What the box prints -------------------------------------------------

$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
ob_start();
MSRWA_Editor::render( (object) array( 'ID' => 12 ) );
$html = ob_get_clean();

msrwa_test_contains( $html, 'Durée non sourcée.', 'The reason reaches the reader.' );
msrwa_test_contains( $html, 'une semaine', 'So does the passage it objected to.' );
msrwa_test_contains( $html, 'Ramener à trois jours.', 'And the correction it proposed.' );
msrwa_test_contains( $html, 'Tarte aux pommes normande', 'The SEO title the article wrote is offered.' );
msrwa_test_contains( $html, 'ms-note-stop', 'A blocking finding is coloured as one.' );

// Invariant: a finished article is work waiting for a reader. No phrasing here
// may suggest the machine approved it.
foreach ( array( 'approuvé', 'validé', 'prêt à publier' ) as $forbidden ) {
	msrwa_test_missing( $html, $forbidden, 'The verdict never phrases itself as approval: ' . $forbidden );
}

// The script that carries this into the block editor must exist and register
// both places an editor looks — the sidebar, and the pre-publish check.
$script = (string) file_get_contents( MSRWA_DIR . 'assets/editor.js' );
msrwa_test_contains( $script, 'PluginPrePublishPanel', 'The verdict reaches the pre-publish check.' );
msrwa_test_contains( $script, 'PluginDocumentSettingPanel', 'And the post sidebar.' );
msrwa_test_contains( $script, 'msrwa-verdict', 'Registered under a name of this plugin’s own.' );

msrwa_test_done( 'the verdict an editor reads' );
