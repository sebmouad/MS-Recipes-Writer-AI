<?php
// Run with wp eval-file on localhost only. Fixtures are rolled back, no API calls.
// Integration fixture: needs a real WordPress on localhost, so the offline
// runner reports it as skipped instead of failing.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { echo "SKIP tests/test-draft-integration.php needs local WP-CLI\n"; return; }
if ( 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { echo "SKIP tests/test-draft-integration.php runs on localhost only\n"; return; }
global $wpdb;
$tables = MSRWA_DB::tables();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$post_ids = array();
$wpdb->query( 'START TRANSACTION' );
try {
	$now = current_time( 'mysql', true );
	$wpdb->insert( $tables['batches'], array( 'owner_id' => get_current_user_id(), 'status' => 'paused', 'total' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$batch_id = $wpdb->insert_id;
	$wpdb->insert( $tables['jobs'], array( 'batch_id' => $batch_id, 'owner_id' => get_current_user_id(), 'title' => 'Fixture relecture', 'input_json' => wp_json_encode( array( 'source_text' => 'Recette originale de courgettes.' ) ), 'status' => 'running', 'stage' => 'article', 'lock_token' => 'draft-contract', 'lock_until' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'created_at' => $now, 'updated_at' => $now ) );
	$id = $wpdb->insert_id;
	$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['jobs']} WHERE id=%d", $id ) );
	$method = new ReflectionMethod( MSRWA_Pipeline::class, 'set_status' );
	$method->invoke( null, $job, 'failed', 'fixture_failure', 'Rédaction interrompue.', array() );
	$post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT draft_post_id FROM {$tables['jobs']} WHERE id=%d", $id ) );
	if ( ! $post_id ) { throw new RuntimeException( 'Failed job did not save a draft.' ); }
	$post_ids[] = $post_id;
	$post = get_post( $post_id );
	if ( 'draft' !== $post->post_status || false === strpos( $post->post_content, 'Recette originale de courgettes.' ) ) { throw new RuntimeException( 'Input fallback missing.' ); }
	if ( false !== strpos( $post->post_content, 'Rédaction interrompue' ) ) { throw new RuntimeException( 'Private report leaked to article.' ); }
	if ( $post_id !== MSRWA_Publisher::create_draft( $job, array(), true ) ) { throw new RuntimeException( 'Duplicate draft.' ); }
	ob_start(); MSRWA_Admin::render_editorial_meta_box( get_post( $post_id ) ); $html = ob_get_clean();
	if ( false === strpos( $html, 'Contenu' ) || false === strpos( $html, 'Non évalué' ) || false !== strpos( $html, 'Score de structure' ) ) { throw new RuntimeException( 'Partial AI report missing.' ); }
	// Mimic an already-published row without firing publication hooks.
	$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $post_id ) ); clean_post_cache( $post_id );
	$result = MSRWA_Publisher::create_draft( $job, array(), true );
	if ( ! is_wp_error( $result ) || 'draft_not_editable' !== $result->get_error_code() ) { throw new RuntimeException( 'Published article could be overwritten.' ); }
	$artifacts = array( 'output_options' => array( 'generate_featured_image' => 0, 'generate_facebook_image' => 0 ) );
	$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['calls']}" );
	foreach ( array( 'featured_image' => 'facebook_image', 'facebook_image' => 'final_review', 'final_review' => 'draft' ) as $stage => $next ) {
		$wpdb->update( $tables['jobs'], array( 'status' => 'running', 'stage' => $stage, 'lock_token' => 'draft-contract' ), array( 'id' => $id ) );
		$job->stage = $stage;
		$step = new ReflectionMethod( MSRWA_Pipeline::class, $stage );
		$step->invokeArgs( null, array( $job, &$artifacts ) );
		$actual = $wpdb->get_var( $wpdb->prepare( "SELECT stage FROM {$tables['jobs']} WHERE id=%d", $id ) );
		if ( $next !== $actual ) { throw new RuntimeException( 'Disabled image stage not skipped.' ); }
	}
	if ( $before !== (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['calls']}" ) ) { throw new RuntimeException( 'Disabled image made an API call.' ); }
	echo "MSRWA image generation and review skipped without API calls OK\n";
	echo "MSRWA local integration OK: failed draft, source fallback, private report, idempotence, published protection\n";
} finally {
	$wpdb->query( 'ROLLBACK' );
	foreach ( $post_ids as $post_id ) { clean_post_cache( $post_id ); }
}
