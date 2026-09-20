<?php
define( 'ABSPATH', __DIR__ );
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_time( ...$args ) { return '2026-09-20 12:00:00'; }
require dirname( __DIR__ ) . '/includes/class-msrwa-publisher.php';
$empty = MSRWA_Publisher::editorial_report( array(), true );
if ( 'needs_review' !== $empty['status'] || 'unknown' !== $empty['article_quality'] || $empty['article_available'] || isset( $empty['score'] ) ) { throw new RuntimeException( 'Missing evidence must stay unevaluated.' ); }
$a = array( 'article' => array( 'content_html' => '<p>Recette.</p>' ), 'quality_report' => array( 'pass' => true, 'score' => 100 ), 'review' => array( 'pass' => false, 'findings' => array( array( 'reason' => 'Quantité à vérifier.' ) ) ), 'featured_image' => array( 'attachment_id' => 1 ), 'facebook_image' => array( 'attachment_id' => 2 ), 'image_reviews' => array( 'featured_image' => array( 'pass' => true ), 'facebook_image' => array( 'pass' => true ) ) );
$report = MSRWA_Publisher::editorial_report( $a );
if ( 'needs_review' !== $report['status'] || 'unknown' !== $report['article_quality'] || $report['text_review_passed'] ) { throw new RuntimeException( 'Structural score must not hide a missing AI verdict.' ); }
$a['review']['pass'] = true;
$a['review']['verdict'] = 'good';
foreach ( array( 'featured_image', 'facebook_image' ) as $key ) { $a['image_reviews'][ $key ]['realism'] = 'good'; }
if ( 'checks_passed' !== MSRWA_Publisher::editorial_report( $a )['status'] ) { throw new RuntimeException( 'Passing review report incorrect.' ); }
echo "MSRWA editorial report contracts OK\n";
foreach ( array( array( 0, 0 ), array( 1, 0 ), array( 0, 1 ), array( 1, 1 ) ) as $choices ) {
	$b = $a;
	$b['output_options'] = array( 'generate_featured_image' => $choices[0], 'generate_facebook_image' => $choices[1] );
	foreach ( array( 'featured_image', 'facebook_image' ) as $i => $key ) { if ( ! $choices[ $i ] ) { unset( $b[ $key ], $b['image_reviews'][ $key ] ); } }
	if ( 'checks_passed' !== MSRWA_Publisher::editorial_report( $b )['status'] ) { throw new RuntimeException( 'Disabled image flagged as missing.' ); }
}
echo "MSRWA four image output combinations OK\n";
$a['review']['verdict'] = 'needs_review';
$a['image_reviews']['featured_image']['realism'] = 'bad';
$report = MSRWA_Publisher::editorial_report( $a );
if ( 'needs_review' !== $report['article_quality'] || 'bad' !== $report['featured_quality'] || 'good' !== $report['facebook_quality'] || isset( $report['score'] ) ) { throw new RuntimeException( 'Independent AI verdicts must survive delivery.' ); }
