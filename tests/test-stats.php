<?php
// Statistics contracts: quality is reported for the articles produced, and the
// delivery verdict is not confused with the structural gate.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'recipe', 'publisher', 'presentation', 'stats' );
msrwa_test_as_admin( 1 );

$report = wp_json_encode( array( 'text_review_passed' => true, 'article_quality' => 'good', 'featured_quality' => 'good', 'facebook_quality' => 'good' ) );
$failing = wp_json_encode( array( 'text_review_passed' => false, 'article_quality' => 'needs_review', 'featured_quality' => 'bad', 'facebook_quality' => 'needs_review' ) );
$GLOBALS['wpdb']
	->on( 'ORDER BY j.id DESC LIMIT 25', array(
		array( 'id' => 9, 'batch_id' => 3, 'title' => 'Tarte', 'status' => 'completed', 'stage' => 'draft', 'artifacts_json' => '', 'draft_post_id' => 44, 'article_quality' => 'good', 'featured_quality' => 'good', 'facebook_quality' => 'good', 'quality_checked_at' => '2026-09-20 10:00:00', 'cost_estimate' => '0.05', 'correction_cycles' => 0, 'attempts' => 1, 'retry_attempts' => 0, 'created_at' => '2026-09-20 09:00:00', 'updated_at' => '2026-09-20 09:20:00', 'quality_json' => $report ),
	) )
	->on( 'AND j.draft_post_id > 0', array(
		array( 'cost_estimate' => '0.05', 'article_quality' => 'good', 'featured_quality' => 'good', 'facebook_quality' => 'good', 'quality_checked_at' => '2026-09-20 10:00:00', 'quality_json' => $report ),
		array( 'cost_estimate' => '0.40', 'article_quality' => 'needs_review', 'featured_quality' => 'bad', 'facebook_quality' => 'needs_review', 'quality_checked_at' => '2026-09-20 10:00:00', 'quality_json' => $failing ),
	) );

$details = MSRWA_Stats::details( 30, 0 );
$quality = $details['quality'];
$sql = $GLOBALS['wpdb']->log();

msrwa_test_contains( $sql, 'AND j.draft_post_id > 0', 'Quality statistics must count articles, not every job.' );
msrwa_test_assert( 2 === $quality['articles'], 'Both articles must be counted.' );
msrwa_test_assert( 1 === $quality['approved'], 'Only the article with a good AI verdict is approved.' );
msrwa_test_assert( 2 === $quality['evaluated'], 'Both AI quality reports are counted.' );
msrwa_test_assert( 1 === $quality['within_target_cost'], 'Only the article under the target cost counts.' );

// A recent job carries the vocabulary the screens render.
$recent = $details['recent_jobs'][0];
msrwa_test_assert( 'completed' === $recent['display_status'], 'Recent jobs must carry the public state.' );
msrwa_test_assert( 'good' === $recent['display_quality']['code'], 'A delivered article with passing checks reads as good.' );
msrwa_test_assert( ! array_key_exists( 'artifacts_json', $recent ), 'Raw artifacts must not travel to the screen.' );

msrwa_test_done( 'MSRWA statistics contracts' );
