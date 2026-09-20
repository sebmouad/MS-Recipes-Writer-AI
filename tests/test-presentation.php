<?php
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-msrwa-presentation.php';
foreach ( array( 'queued' => 'encours', 'needs_review' => 'completed', 'completed' => 'completed', 'failed' => 'error', 'cancelled' => 'canceled' ) as $internal => $expected ) {
	if ( $expected !== MSRWA_Presentation::state( $internal ) ) { throw new RuntimeException( $internal ); }
}
$article = MSRWA_Presentation::quality( array( 'status' => 'completed', 'article_quality' => 'good' ) );
if ( 'good' !== $article['code'] || null !== $article['score'] ) { throw new RuntimeException( 'AI article verdict must be textual.' ); }
if ( 'Mauvais' !== MSRWA_Presentation::verdict( 'bad' )['label'] ) { throw new RuntimeException( 'Bad verdict label missing.' ); }
if ( 'Non générée' !== MSRWA_Presentation::verdict( 'not_generated' )['label'] ) { throw new RuntimeException( 'Disabled image verdict missing.' ); }
$batch = MSRWA_Presentation::batch( array( array( 'status' => 'completed', 'article_quality' => 'good' ), array( 'status' => 'needs_review', 'article_quality' => 'needs_review' ) ) );
if ( 'needs_review' !== $batch['quality']['code'] ) { throw new RuntimeException( 'Batch must expose the worst textual verdict.' ); }
echo "MSRWA AI verdict presentation OK\n";
