<?php
// The Articles screen follows each recipe's post into WordPress: published,
// scheduled, in the bin or deleted there, and renamed on the way. The state is
// read from the post, never remembered by the plugin.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'i18n', 'ui', 'ledger', 'profile' );
if ( true ) {
	if ( ! function_exists( 'get_preview_post_link' ) ) { function get_preview_post_link( $post ) { return 'https://example.test/?p=' . (int) $post->ID . '&preview=true'; } }
}
msrwa_test_as_admin();
$GLOBALS['msrwa_test_caps'][] = 'delete_post';

$render = static function ( array $run ) {
	$run += array( 'id' => 7, 'batch_id' => 0, 'owner_id' => 1, 'status' => 'done', 'step' => '', 'steps_done' => 9, 'steps_total' => 9, 'approved' => 1, 'priority' => 0, 'profile' => 'full', 'language' => 'fr' );
	ob_start(); MSRWA_UI::run_table( array( $run ) ); return (string) ob_get_clean();
};
$GLOBALS['msrwa_test_posts'][201] = (object) array( 'ID' => 201, 'post_status' => 'publish', 'post_title' => 'Tarte normande au calvados', 'post_date_gmt' => '2026-09-24 08:00:00', 'post_modified_gmt' => '2026-09-24 08:00:00' );
$GLOBALS['msrwa_test_posts'][202] = (object) array( 'ID' => 202, 'post_status' => 'trash', 'post_title' => 'Poulet yassa', 'post_date_gmt' => '2026-09-23 08:00:00', 'post_modified_gmt' => '2026-09-24 09:00:00' );
$GLOBALS['msrwa_test_posts'][203] = (object) array( 'ID' => 203, 'post_status' => 'draft', 'post_title' => 'Daube', 'post_date_gmt' => '0000-00-00 00:00:00', 'post_modified_gmt' => '2026-09-24 10:00:00' );

$published = $render( array( 'label' => 'Tarte aux pommes normande', 'draft_post_id' => 201 ) );
msrwa_test_contains( $published, 'Publié', 'A published article says so.' );
msrwa_test_contains( $published, '?p=201', 'And links to the page itself.' );
msrwa_test_contains( $published, 'action=edit', 'And to the editor.' );
msrwa_test_contains( $published, 'Tarte normande au calvados', 'A post renamed in WordPress shows its current title.' );

$binned = $render( array( 'label' => 'Poulet yassa', 'draft_post_id' => 202 ) );
msrwa_test_contains( $binned, 'Dans la corbeille', 'A post in the bin says so.' );
msrwa_test_contains( $binned, 'action=untrash', 'And offers to restore it.' );
msrwa_test_missing( $binned, 'action=edit', 'A post in the bin is not edited.' );
msrwa_test_missing( $binned, 'Intitulé dans WordPress', 'A title the post still shares is not repeated.' );

$draft = $render( array( 'label' => 'Daube', 'draft_post_id' => 203 ) );
msrwa_test_contains( $draft, 'Brouillon', 'A draft says so.' );
msrwa_test_contains( $draft, 'preview=true', 'And is previewed rather than viewed.' );

$gone = $render( array( 'label' => 'Soupe', 'draft_post_id' => 999 ) );
msrwa_test_contains( $gone, 'Supprimé de WordPress', 'A post deleted outright says so, and the recipe’s record stays.' );
$pending = $render( array( 'label' => 'En cours', 'draft_post_id' => 0, 'status' => 'running' ) );
msrwa_test_assert( false === strpos( $pending, 'ms-run-action' ) && false === strpos( $pending, 'Brouillon' ), 'A recipe with no article yet shows nothing about one.' );

// "To review" and "to fix" are for drafts: once the editor has published the
// post, the flag gives way to what WordPress did with it.
$refused = array( 'status' => 'done', 'approved' => 0, 'label' => 'Tarte' );
msrwa_test_assert( 'à corriger' === MSRWA_UI::state_of( $refused + array( 'draft_post_id' => 203, 'post_status' => 'draft' ) )['label'], 'A refused draft is still to fix.' );
msrwa_test_assert( 'publié' === MSRWA_UI::state_of( $refused + array( 'draft_post_id' => 201, 'post_status' => 'publish' ) )['label'], 'A refused article that was published reads published.' );
msrwa_test_assert( 'publié' === MSRWA_UI::state_of( array( 'status' => 'done', 'approved' => 1, 'draft_post_id' => 201, 'post_status' => 'publish' ) )['label'], 'An article to review that was published reads published.' );
msrwa_test_assert( 'programmé' === MSRWA_UI::state_of( $refused + array( 'draft_post_id' => 201, 'post_status' => 'future' ) )['label'], 'A scheduled one reads scheduled.' );
msrwa_test_assert( 'supprimé' === MSRWA_UI::state_of( $refused + array( 'draft_post_id' => 999, 'post_status' => null ) )['label'], 'A deleted one reads deleted.' );
msrwa_test_assert( 'échec' === MSRWA_UI::state_of( array( 'status' => 'failed', 'draft_post_id' => 0 ) )['label'], 'A failure is still a failure.' );

$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->posts = 'wp_posts';
MSRWA_Ledger::runs( array( 'status' => 'attention' ) );
msrwa_test_contains( $GLOBALS['wpdb']->log(), "r.approved = 0 AND (r.draft_post_id = 0 OR p.post_status IN ('draft','pending'))", 'A refused article leaves "needs a decision" once its post is published.' );
MSRWA_Ledger::now();
msrwa_test_contains( $GLOBALS['wpdb']->log(), "r.approved = 0 AND p.post_status IN ('draft','pending') THEN 1", 'And stops counting as "to fix".' );

// The filter is the post's state, read in the query, inside the reader's scope.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->posts = 'wp_posts';
MSRWA_Ledger::runs( array( 'post' => 'trash', 'search' => 'tarte' ) );
$sql = implode( "\n", $GLOBALS['wpdb']->matching( 'LEFT JOIN wp_posts p ON p.ID = r.draft_post_id' ) );
msrwa_test_contains( $sql, "WHEN p.post_status = 'trash' THEN 'trash'", 'The bin is a state read from the post.' );
msrwa_test_contains( $sql, "END = 'trash'", 'And filtered on.' );
msrwa_test_contains( $sql, 'p.post_title LIKE', 'The search finds a post by the title it was given in WordPress.' );
MSRWA_Ledger::runs( array( 'post' => 'DROP TABLE' ) );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'DROP', 'An unknown state is ignored, never written into the query.' );
msrwa_test_assert( array( 'draft', 'future', 'publish', 'trash', 'deleted', 'none' ) === MSRWA_Ledger::post_buckets(), 'Six states, every post in exactly one.' );

msrwa_test_done( 'the articles follow their posts into WordPress' );
