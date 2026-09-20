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
if ( null !== MSRWA_Presentation::quality( $empty )['score'] ) { throw new RuntimeException( 'Score fabricated.' ); }
$batch = MSRWA_Presentation::batch( array( $review, $empty ) );
if ( 'encours' !== $batch['state'] || 1 !== $batch['quality']['evaluated'] || 2 !== $batch['quality']['total'] || 100 !== $batch['quality']['score'] ) { throw new RuntimeException( 'Partial batch score/state incorrect.' ); }
$batch = MSRWA_Presentation::batch( array( $review, array( 'status' => 'uncertain' ) ) );
if ( 'error' !== $batch['state'] || 'uncertain' !== $batch['quality']['code'] ) { throw new RuntimeException( 'Uncertainty hidden.' ); }
if ( 'canceled' !== MSRWA_Presentation::batch( array( array( 'status' => 'cancelled' ) ) )['state'] ) { throw new RuntimeException( 'Cancellation incorrect.' ); }
echo "MSRWA state/quality contracts OK\n";
