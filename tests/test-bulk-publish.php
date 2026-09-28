<?php
// Publishing several articles from the Articles screen, exactly as WordPress's
// own bulk edit does: the status alone, through wp_update_post. Each is
// checked on its own: a draft, and only a draft, this person may publish.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'rest' );

$GLOBALS['updated'] = array();
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $post, $wp_error = false ) {
		$GLOBALS['updated'][] = $post;
		$GLOBALS['msrwa_test_posts'][ (int) $post['ID'] ]->post_status = $post['post_status'];
		return (int) $post['ID'];
	}
}
$post = static function ( $id, $status ) { $GLOBALS['msrwa_test_posts'][ $id ] = (object) array( 'ID' => $id, 'post_status' => $status, 'post_title' => 'Article ' . $id ); };
$publish = new ReflectionMethod( MSRWA_REST::class, 'publish' );
$publish->setAccessible( true );
$run = static function ( $post_id, $approved = 1 ) { return array( 'id' => $post_id, 'status' => 'done', 'draft_post_id' => $post_id, 'approved' => $approved ); };

msrwa_test_as_admin();
$GLOBALS['msrwa_test_caps'][] = 'publish_post';
$post( 301, 'draft' );
$post( 302, 'pending' );
$post( 303, 'publish' );
$post( 304, 'future' );
$post( 305, 'trash' );
$post( 306, 'draft' );

msrwa_test_assert( true === $publish->invoke( null, $run( 301 ) ), 'A draft is published.' );
msrwa_test_assert( 'publish' === $GLOBALS['msrwa_test_posts'][301]->post_status, 'And is published indeed.' );
msrwa_test_assert( array( 'ID' => 301, 'post_status' => 'publish' ) === $GLOBALS['updated'][0], 'Through the status alone: WordPress dates it as its own Publish would.' );
msrwa_test_assert( false === $publish->invoke( null, $run( 302 ) ) && 'pending' === $GLOBALS['msrwa_test_posts'][302]->post_status, 'Drafts only: a post pending review is left as it is.' );
foreach ( array( 303 => 'already published', 304 => 'scheduled', 305 => 'in the bin' ) as $id => $what ) {
	msrwa_test_assert( false === $publish->invoke( null, $run( $id ) ), 'A post ' . $what . ' is left as it is.' );
}
msrwa_test_assert( true === $publish->invoke( null, $run( 306, 0 ) ), 'A draft marked to fix is published like any other: WordPress does not know the mark.' );
msrwa_test_assert( false === $publish->invoke( null, $run( 999 ) ), 'A post deleted from WordPress is skipped.' );
msrwa_test_assert( false === $publish->invoke( null, array( 'id' => 1, 'status' => 'running', 'draft_post_id' => 0 ) ), 'A recipe with no article yet has nothing to publish.' );

// Someone who may not publish this post does not, whatever they send.
$GLOBALS['msrwa_test_caps'] = array_values( array_diff( $GLOBALS['msrwa_test_caps'], array( 'publish_post' ) ) );
$post( 307, 'draft' );
msrwa_test_assert( false === $publish->invoke( null, $run( 307 ) ) && 'draft' === $GLOBALS['msrwa_test_posts'][307]->post_status, 'Without the right to publish it, a draft stays a draft.' );

// The action is offered only to someone who may publish, and asked first.
$screen = file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-screen-articles.php' );
msrwa_test_contains( $screen, "current_user_can( 'publish_posts' ) ) : ?>\n\t\t\t\t\t\t<option value=\"publish\">", 'The bulk menu offers publishing only to someone who may publish.' );
msrwa_test_contains( file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' ), "'publish' === action && !window.confirm(", 'Publishing asks first.' );

msrwa_test_done( 'several articles are published at once, each checked on its own' );
