<?php
// Rendering contracts for the Articles and Jobs screens: scoping, filters,
// pagination and escaping. No database, no WordPress, no network.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'recipe', 'publisher', 'presentation', 'lists', 'queue', 'admin' );

$artifacts = wp_json_encode( array(
	'article' => array( 'content_html' => '<p>Recette.</p>' ),
	'quality_report' => array( 'pass' => true, 'score' => 93 ),
	'review' => array( 'pass' => true ),
	'featured_image' => array( 'attachment_id' => 1 ),
	'facebook_image' => array( 'attachment_id' => 2 ),
	'image_reviews' => array( 'featured_image' => array( 'pass' => true ), 'facebook_image' => array( 'pass' => true ) ),
) );
$job_row = array(
	'id' => 9, 'batch_id' => 3, 'owner_id' => 7, 'title' => 'Tarte <script>alert(1)</script>', 'status' => 'completed',
	'stage' => 'review', 'draft_post_id' => 44, 'article_quality' => 'good', 'featured_quality' => 'good', 'facebook_quality' => 'needs_review', 'quality_score' => 93, 'quality_passed' => 1,
	'quality_checked_at' => '2026-09-20 11:00:00', 'cost_estimate' => '0.1234', 'correction_cycles' => 1,
	'correction_cycles_json' => '{"article":1}', 'attempts' => 2, 'retry_attempts' => 0,
	'error_code' => 'quality_gate', 'error_message' => 'Article trop court.', 'artifacts_json' => $artifacts,
	'selected_models_json' => '{"text":{"provider":"openai","model":"gpt-5.6-luna"}}',
	'created_at' => '2026-09-20 09:00:00', 'updated_at' => '2026-09-20 09:30:00',
	'post_status' => 'publish', 'post_title' => 'Tarte aux pommes', 'post_date' => '2026-09-20 09:30:00',
);

function msrwa_test_render( $query, $job_row, $health = array() ) {
	$wpdb = new MSRWA_Fake_Wpdb();
	if ( $health ) { $wpdb->on( "SUM(status IN ('queued','retry_wait'))", array( (object) $health ) ); }
	$wpdb->default_var( 3 )
		->on( 'FROM wp_msrwa_jobs j LEFT JOIN', array( $job_row ) )
		->on( 'FROM wp_msrwa_calls', array( array( 'job_id' => 9, 'calls' => 6, 'input_tokens' => 1200, 'output_tokens' => 3400, 'cost' => 0.1234, 'uncertain' => 0, 'failures' => 1, 'first_call' => '2026-09-20 09:01:00', 'last_call' => '2026-09-20 09:29:00' ) ) )
		->on( 'owner_id, COUNT(*)', array( array( 'owner_id' => 7, 'jobs' => 12 ) ) )
		->on( 'FROM wp_msrwa_batches', array( array( 'id' => 3, 'status' => 'running', 'total' => 2, 'completed' => 1, 'created_at' => '2026-09-20 08:00:00', 'updated_at' => '2026-09-20 09:30:00' ) ) )
		->on( 'batch_id,status FROM', array( array( 'batch_id' => 3, 'status' => 'completed' ) ) );
	$GLOBALS['wpdb'] = $wpdb;
	$_GET = $query;
	ob_start();
	MSRWA_Admin::page();
	return array( 'html' => ob_get_clean(), 'sql' => $wpdb->log() );
}

// An administrator filtering articles gets the author controls and the filters applied.
msrwa_test_as_admin( 1 );
$admin = msrwa_test_render( array( 'page' => 'ms-recipes-writer-ai', 'msrwa_view' => 'articles', 'msrwa_status' => 'publish', 'msrwa_search' => 'tarte', 'msrwa_author' => '7', 'msrwa_quality' => 'good', 'msrwa_days' => '30' ), $job_row );
msrwa_test_contains( $admin['sql'], 'j.draft_post_id > 0', 'The articles list must only read rows that produced an article.' );
msrwa_test_contains( $admin['sql'], "p.post_status = 'publish'", 'The publication filter must reach the query.' );
msrwa_test_contains( $admin['sql'], "article_quality = 'good'", 'The quality filter must read the stored AI verdict.' );
msrwa_test_contains( $admin['sql'], 'j.owner_id = 7', 'The administrator author filter must reach the query.' );
msrwa_test_contains( $admin['html'], 'name="msrwa_author"', 'Administrators need the author filter.' );
msrwa_test_contains( $admin['html'], 'msrwa-quality-good', 'The stored verdict must be rendered as a badge.' );
msrwa_test_contains( $admin['html'], 'msrwa-quality-needs_review', 'The dedicated Facebook realism verdict must be rendered.' );
msrwa_test_contains( $admin['html'], 'msrwa-post-status-publish', 'The publication state must be rendered.' );
msrwa_test_missing( $admin['html'], '<script>alert(1)</script>', 'Job titles must be escaped.' );

// An editor is pinned to their own rows even when the request forges another author.
msrwa_test_as_editor( 7 );
$editor = msrwa_test_render( array( 'page' => 'ms-recipes-writer-ai', 'msrwa_view' => 'articles', 'msrwa_author' => '99' ), $job_row );
msrwa_test_contains( $editor['sql'], 'j.owner_id = 7', 'A scoped editor must be filtered to their own rows.' );
msrwa_test_missing( $editor['sql'], 'owner_id = 99', 'A forged author filter must never reach the query.' );
msrwa_test_missing( $editor['html'], 'name="msrwa_author"', 'A scoped editor must not see the author filter.' );
msrwa_test_missing( $editor['html'], 'msrwa_author=99', 'A forged author must not be carried in list links.' );

// The jobs list carries the whole processing record and ignores article-only filters.
msrwa_test_as_admin( 1 );
$jobs = msrwa_test_render( array( 'page' => 'ms-recipes-writer-ai', 'msrwa_view' => 'jobs', 'msrwa_status' => 'publish', 'msrwa_stage' => 'review' ), $job_row );
msrwa_test_missing( $jobs['sql'], "p.post_status = 'publish'", 'A filter the jobs list does not expose must not filter it.' );
msrwa_test_contains( $jobs['sql'], "j.stage = 'review'", 'The stage filter must reach the query.' );
msrwa_test_contains( $jobs['sql'], 'FROM wp_msrwa_calls', 'Job rows must carry their provider call totals.' );
foreach ( array( 'Diagnostic technique', 'Modèles retenus', 'Tokens entrée / sortie', 'Corrections par élément', 'Premier / dernier appel', 'Propriétaire' ) as $field ) {
	msrwa_test_contains( $jobs['html'], $field, 'The job record must expose ' . $field . '.' );
}
msrwa_test_contains( $jobs['html'], 'Relecture', 'Stages must be rendered with their label.' );
msrwa_test_contains( $jobs['html'], 'msrwa-batch-action', 'Batch controls must stay reachable from the jobs view.' );

// Pagination appears once the result set exceeds one page.
$GLOBALS['msrwa_test_caps'] = array( 'edit_posts', 'msrwa_view_all', 'manage_options' );
$paged = msrwa_test_render( array( 'page' => 'ms-recipes-writer-ai', 'msrwa_view' => 'articles', 'msrwa_per_page' => '20', 'msrwa_paged' => '2' ), $job_row );
msrwa_test_missing( $paged['html'], 'msrwa-pagination', 'Three rows over twenty per page must not paginate.' );

// A job that can be relaunched offers the action; a finished one does not.
$retryable_row = array_merge( $job_row, array( 'status' => 'needs_review', 'quality_passed' => 0 ) );
$actionable = msrwa_test_render( array( 'page' => 'ms-recipes-writer-ai', 'msrwa_view' => 'jobs' ), $retryable_row );
msrwa_test_contains( $actionable['html'], 'msrwa-job-action" data-action="retry"', 'A job waiting for review must be relaunchable from the list.' );
msrwa_test_contains( $actionable['html'], 'msrwa-job-action" data-action="cancel"', 'An unfinished job must be cancellable from the list.' );
$finished = msrwa_test_render( array( 'page' => 'ms-recipes-writer-ai', 'msrwa_view' => 'jobs' ), $job_row );
msrwa_test_missing( $finished['html'], 'msrwa-job-action" data-action="retry"', 'A completed job must not offer a relaunch.' );
msrwa_test_missing( $finished['html'], 'msrwa-job-action" data-action="cancel"', 'A completed job must not offer a cancellation.' );

// An association waiting on the editor is confirmable where they already are.
$association = msrwa_test_render( array( 'page' => 'ms-recipes-writer-ai', 'msrwa_view' => 'jobs' ), array_merge( $job_row, array( 'status' => 'awaiting_input', 'stage' => 'association' ) ) );
msrwa_test_contains( $association['html'], 'msrwa-job-action" data-action="association"', 'A pending association must be confirmable from the list.' );

// The queue notice stays quiet while the queue is healthy and speaks when it is not.
msrwa_test_missing( $finished['html'], 'msrwa-queue-notice', 'A healthy queue must not warn anyone.' );
$stalled = msrwa_test_render( array( 'page' => 'ms-recipes-writer-ai', 'msrwa_view' => 'jobs' ), $job_row, array( 'waiting' => 5, 'running' => 0, 'expired' => 2, 'attention' => 1 ) );
msrwa_test_contains( $stalled['html'], 'msrwa-queue-notice', 'Interrupted workers must be reported on the screen editors use.' );
msrwa_test_contains( $stalled['html'], 'interrompu', 'The notice must say what happened.' );

msrwa_test_done( 'MSRWA admin list contracts' );
