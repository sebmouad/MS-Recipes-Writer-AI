<?php
// The draft is written by cron, where nobody is logged in, so the generated
// images went into the media library with no author. They carry the
// article's author, and the ones made before are credited on installation.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'db', 'draft' );
if ( true ) {
	if ( ! function_exists( 'clean_post_cache' ) ) { function clean_post_cache( $id ) { $GLOBALS['msrwa_cleaned'][] = (int) $id; } }
}

msrwa_test_contains( file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-draft.php' ), "'post_author' => (int) get_post_field( 'post_author', \$post_id )", 'A generated image is created with the article’s author.' );

$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->posts = 'wp_posts';
$GLOBALS['wpdb']->postmeta = 'wp_postmeta';
$GLOBALS['wpdb']->on( "a.post_author = 0", array( array( 'attachment' => 156, 'author' => 7 ), array( 'attachment' => 157, 'author' => 0 ) ) );
$credit = new ReflectionMethod( 'MSRWA_DB', 'credit_generated_images' );
$credit->setAccessible( true );
$credit->invoke( null );
$updates = $GLOBALS['wpdb']->matching( 'UPDATE wp_posts' );
msrwa_test_assert( 1 === count( $updates ) && false !== strpos( $updates[0], '"post_author":7' ), 'An authorless generated image takes its article’s author: ' . implode( ' | ', $updates ) );
msrwa_test_contains( $GLOBALS['wpdb']->log(), "'_msrwa_featured_generated','_msrwa_facebook_generated'", 'Only the images this plugin generated are touched.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'LIMIT 500', 'The backfill is bounded.' );

msrwa_test_done( 'generated images carry their article’s author' );
