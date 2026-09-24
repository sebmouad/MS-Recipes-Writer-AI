<?php
// The engine no longer redraws a refused image by itself: every test recipe
// redrew one and was judged again, a quarter of its cost. The editor reads the
// findings and decides. This is what they are offered, and what they are not.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'i18n', 'profile', 'db' );
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-batch.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-run.php';

$refused = array(
	'approved' => false,
	'featured_image' => array( 'verdict' => 'good', 'realism' => 'good', 'summary' => 'Crédible.' ),
	'facebook_image' => array( 'verdict' => 'bad', 'realism' => 'good', 'panels_counted' => 6, 'summary' => 'Ordre faux.' ),
	'findings' => array(
		array( 'target' => 'facebook_image', 'severity' => 'blocking', 'reason' => 'Le panneau 4 précède la cuisson.', 'fix' => 'Montrer la précuisson.' ),
		array( 'target' => 'featured_image', 'severity' => 'minor', 'reason' => 'Cadrage.', 'fix' => 'Resserrer.' ),
	),
);
$GLOBALS['msrwa_test_posts'][40] = (object) array( 'ID' => 40, 'post_title' => 'Tarte', 'post_content' => '<p>x</p>' );
$GLOBALS['msrwa_test_meta'][40]['_msrwa_judge_report'] = json_encode( $refused );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT * FROM', array( array( 'id' => 12, 'batch_id' => 3, 'owner_id' => 7, 'status' => 'done', 'draft_post_id' => 40 ) ) );
$run = array( 'id' => 12, 'batch_id' => 3, 'owner_id' => 7, 'status' => 'done', 'draft_post_id' => 40 );

msrwa_test_as_editor( 7 );
msrwa_test_assert( array( 'facebook' ) === MSRWA_Run::redrawable( $run ), 'Only the image with a blocking finding is offered for a redraw.' );
msrwa_test_as_editor( 8 );
msrwa_test_assert( array() === MSRWA_Run::redrawable( $run ), 'Nobody redraws another writer’s image.' );
msrwa_test_as_editor( 7 );
msrwa_test_assert( array() === MSRWA_Run::redrawable( array_merge( $run, array( 'status' => 'running' ) ) ), 'A recipe still in flight has nothing to redraw yet.' );
msrwa_test_assert( array() === MSRWA_Run::redrawable( array_merge( $run, array( 'draft_post_id' => 0 ) ) ), 'Nor one without a draft to put the image in.' );

// Asking for an image the judge did not refuse spends nothing.
$called = 0;
MSRWA_Engine_Call::$transport = static function () use ( &$called ) { $called++; return array( 'status' => 500, 'raw' => '' ); };
msrwa_test_assert( '' !== MSRWA_Run::redraw( 12, 'featured' ), 'An image the judge accepted cannot be redrawn from here.' );
msrwa_test_assert( 0 === $called, 'And asking costs nothing.' );

// Each redraw is paid for, so each image of a recipe may be redrawn twice.
$GLOBALS['msrwa_test_meta'][40]['_msrwa_facebook_redrawn'] = MSRWA_Run::REDRAWS;
msrwa_test_assert( array() === MSRWA_Run::redrawable( $run ), 'After its redraws an image is not offered again.' );
msrwa_test_assert( '' !== MSRWA_Run::redraw( 12, 'facebook' ) && 0 === $called, 'Nor redrawn by a request that skips the screen.' );
MSRWA_Engine_Call::$transport = null;

// The findings reach the image the editor asked for, through the engine's options.
$sent = array();
MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( &$sent ) {
	$sent[] = is_array( $payload ) ? json_encode( $payload, JSON_UNESCAPED_UNICODE ) : (string) $payload;
	return array( 'status' => 500, 'raw' => '{}' );
};
MSRWA_Engine::run_step( 'featured_image', array( 'title' => 'Tarte', 'artifacts' => array(
	'research' => array( 'facts' => array() ), 'canonical' => array( 'title' => 'Tarte', 'ingredients' => array(), 'steps' => array() ),
) ), array( 'config' => array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ) ), 'findings' => array( array( 'reason' => 'Persil ajouté.', 'fix' => 'Retirer le persil.' ) ) ) );
msrwa_test_contains( implode( "\n", $sent ), 'Retirer le persil', 'The judge’s fix is in the prompt the image is redrawn from.' );
MSRWA_Engine_Call::$transport = null;

msrwa_test_done( 'a refused image is redrawn only when the editor asks' );
