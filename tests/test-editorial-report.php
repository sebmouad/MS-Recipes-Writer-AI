<?php
define( 'ABSPATH', __DIR__ );
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_time( ...$args ) { return '2026-09-20 12:00:00'; }
require dirname( __DIR__ ) . '/includes/class-msrwa-publisher.php';
$empty = MSRWA_Publisher::editorial_report( array(), true );
if ( 'needs_review' !== $empty['status'] || null !== $empty['score'] || $empty['article_available'] ) { throw new RuntimeException( 'Incomplete draft must not get a fabricated passing score.' ); }
$a = array( 'article' => array( 'content_html' => '<p>Recette.</p>' ), 'quality_report' => array( 'pass' => true, 'score' => 100 ), 'review' => array( 'pass' => false, 'findings' => array( array( 'reason' => 'Quantité à vérifier.' ) ) ), 'featured_image' => array( 'attachment_id' => 1 ), 'facebook_image' => array( 'attachment_id' => 2 ), 'image_reviews' => array( 'featured_image' => array( 'pass' => true ), 'facebook_image' => array( 'pass' => true ) ) );
$report = MSRWA_Publisher::editorial_report( $a );
if ( 'needs_review' !== $report['status'] || 100 !== $report['score'] || $report['text_review_passed'] ) { throw new RuntimeException( 'Structural score must not hide a failed editorial review.' ); }
$a['review']['pass'] = true;
if ( 'checks_passed' !== MSRWA_Publisher::editorial_report( $a )['status'] ) { throw new RuntimeException( 'Passing review report incorrect.' ); }
echo "MSRWA editorial report contracts OK\n";
