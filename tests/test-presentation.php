<?php
define( 'ABSPATH', __DIR__ );
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( ...$args ) { return '2026-09-20 12:00:00'; }
require dirname( __DIR__ ) . '/includes/class-msrwa-publisher.php';
require dirname( __DIR__ ) . '/includes/class-msrwa-presentation.php';
foreach ( array( 'queued' => 'encours', 'running' => 'encours', 'paused_budget' => 'encours', 'needs_review' => 'completed', 'completed' => 'completed', 'failed' => 'error', 'uncertain' => 'error', 'cancelled' => 'canceled' ) as $internal => $expected ) {
	if ( $expected !== MSRWA_Presentation::state( $internal ) ) { throw new RuntimeException( $internal ); }
}
$a = array( 'quality_report' => array( 'score' => 100, 'pass' => true ), 'article' => array( 'content_html' => '<p>Recipe</p>' ), 'review' => array( 'pass' => false ) );
$review = array( 'status' => 'needs_review', 'artifacts_json' => json_encode( $a ) );
if ( 'review' !== MSRWA_Presentation::quality( $review )['code'] ) { throw new RuntimeException( 'High structural score hides review failure.' ); }
$empty = array( 'status' => 'queued' );
$without_article = MSRWA_Presentation::quality( $empty );
if ( 'none' !== $without_article['code'] || null !== $without_article['score'] || 0 !== $without_article['total'] ) { throw new RuntimeException( 'A job without an article must not carry a quality verdict.' ); }
$rescue_draft = MSRWA_Presentation::quality( array( 'status' => 'failed', 'draft_post_id' => 12 ) );
if ( 'incomplete' !== $rescue_draft['code'] || 1 !== $rescue_draft['total'] || null !== $rescue_draft['score'] ) { throw new RuntimeException( 'Saved draft must be measured without a fabricated score.' ); }
$batch = MSRWA_Presentation::batch( array( $review, $empty ) );
if ( 'encours' !== $batch['state'] || 1 !== $batch['quality']['evaluated'] || 1 !== $batch['quality']['total'] || 100 !== $batch['quality']['score'] ) { throw new RuntimeException( 'Batch quality must count articles, not jobs.' ); }
$pending = MSRWA_Presentation::batch( array( $empty, $empty ) );
if ( 'none' !== $pending['quality']['code'] || 0 !== $pending['quality']['total'] || null !== $pending['quality']['score'] ) { throw new RuntimeException( 'Batch without article must not carry a quality verdict.' ); }
$batch = MSRWA_Presentation::batch( array( $review, array( 'status' => 'uncertain', 'artifacts_json' => json_encode( $a ) ) ) );
if ( 'error' !== $batch['state'] || 'uncertain' !== $batch['quality']['code'] ) { throw new RuntimeException( 'Uncertainty hidden.' ); }
if ( 'canceled' !== MSRWA_Presentation::batch( array( array( 'status' => 'cancelled' ) ) )['state'] ) { throw new RuntimeException( 'Cancellation incorrect.' ); }
echo "MSRWA state/quality contracts OK\n";
