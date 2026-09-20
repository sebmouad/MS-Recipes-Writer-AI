<?php
// Filter, scope and pagination contracts for the admin lists, without WordPress.
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['caps'] = array();
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['caps'], true ); }
function get_current_user_id() { return 7; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function number_format_i18n( $value, $decimals = 0 ) { return number_format( (float) $value, $decimals ); }
function get_userdata( $id ) { return null; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_time( ...$args ) { return '2026-09-20 12:00:00'; }
class MSRWA_DB { public static function tables() { return array( 'jobs' => 'j_table', 'calls' => 'c_table' ); } }
class FakeWpdb { public $posts = 'wp_posts'; public function esc_like( $v ) { return addcslashes( (string) $v, '_%\\' ); } }
$wpdb = new FakeWpdb();
function esc_url_raw( $v ) { return (string) $v; }
function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
require dirname( __DIR__ ) . '/includes/class-msrwa-recipe.php';
require dirname( __DIR__ ) . '/includes/class-msrwa-publisher.php';
require dirname( __DIR__ ) . '/includes/class-msrwa-presentation.php';
require dirname( __DIR__ ) . '/includes/class-msrwa-lists.php';

$raw = array( 'msrwa_view' => 'articles', 'msrwa_status' => 'publish', 'msrwa_quality' => 'good', 'msrwa_author' => '99', 'msrwa_per_page' => '50', 'msrwa_paged' => '3', 'msrwa_order' => 'score', 'msrwa_days' => '30' );

// An editor without the cross-editor capability is pinned to their own rows.
$scoped = MSRWA_Lists::sanitize_args( $raw );
if ( 7 !== $scoped['author'] ) { throw new RuntimeException( 'A scoped editor must never read another author.' ); }
$GLOBALS['caps'] = array( 'msrwa_view_all' );
$wide = MSRWA_Lists::sanitize_args( $raw );
if ( 99 !== $wide['author'] ) { throw new RuntimeException( 'The administrator author filter must apply.' ); }

// Unknown values fall back to a neutral default instead of reaching a query.
$junk = MSRWA_Lists::sanitize_args( array( 'msrwa_view' => 'jobs', 'msrwa_status' => 'j.id=1 OR 1=1', 'msrwa_order' => 'DROP', 'msrwa_state' => 'nope', 'msrwa_per_page' => '9999', 'msrwa_days' => '4000' ) );
if ( '' !== $junk['post_status'] || 'recent' !== $junk['order'] || '' !== $junk['state'] || 20 !== $junk['per_page'] || 0 !== $junk['days'] ) { throw new RuntimeException( 'Unknown filter values must be dropped.' ); }

// A filter a list does not expose must not keep filtering it.
if ( '' !== MSRWA_Lists::sanitize_args( array( 'msrwa_view' => 'jobs', 'msrwa_status' => 'publish', 'msrwa_quality' => 'good' ) )['post_status'] ) { throw new RuntimeException( 'Hidden publication filter leaked into the jobs list.' ); }
if ( '' !== MSRWA_Lists::sanitize_args( array( 'msrwa_view' => 'articles', 'msrwa_stage' => 'review' ) )['stage'] ) { throw new RuntimeException( 'Hidden stage filter leaked into the articles list.' ); }

// Values travel as parameters, never inlined into SQL.
$conditions = MSRWA_Lists::conditions( MSRWA_Lists::sanitize_args( array( 'msrwa_view' => 'articles', 'msrwa_search' => "tarte' OR 1=1 --", 'msrwa_author' => '3', 'msrwa_status' => 'publish' ) ) );
$sql = implode( ' AND ', $conditions['where'] );
if ( false !== strpos( $sql, 'tarte' ) || false === strpos( $sql, '%s' ) ) { throw new RuntimeException( 'Search text must stay a prepared parameter.' ); }
if ( 5 !== count( $conditions["params"] ) ) { throw new RuntimeException( 'Prepared parameters do not match the conditions.' ); }

// Quality filters map to the stored article verdict.
$good = MSRWA_Lists::conditions( MSRWA_Lists::sanitize_args( array( 'msrwa_view' => 'articles', 'msrwa_quality' => 'good' ) ) )['where'];
if ( false === strpos( implode( '', $good ), 'quality_passed = 1' ) ) { throw new RuntimeException( 'The passing verdict must read the stored column.' ); }
echo "MSRWA list filter contracts OK\n";
