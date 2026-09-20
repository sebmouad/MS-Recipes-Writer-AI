<?php
// A page cache must never be allowed to store a plugin API response: an
// application-password request looks anonymous to it.
require __DIR__ . '/bootstrap.php';

class WP_REST_Response {
	public $headers = array();
	public function header( $name, $value ) { $this->headers[ $name ] = $value; }
}
class WP_REST_Request_Double {
	private $route;
	public function __construct( $route ) { $this->route = $route; }
	public function get_route() { return $this->route; }
}
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( ...$args ) { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( ...$args ) { return true; } }
msrwa_test_load( 'rest' );

$response = MSRWA_REST::no_store( new WP_REST_Response(), null, new WP_REST_Request_Double( '/msrwa/v1/catalog' ) );
msrwa_test_contains( $response->headers['Cache-Control'] ?? '', 'no-store', 'Plugin responses must forbid storing.' );
msrwa_test_contains( $response->headers['Cache-Control'] ?? '', 'private', 'Plugin responses must be marked private.' );
msrwa_test_assert( 'no-cache' === ( $response->headers['X-LiteSpeed-Cache-Control'] ?? '' ), 'LiteSpeed must be told not to cache the namespace.' );

$other = MSRWA_REST::no_store( new WP_REST_Response(), null, new WP_REST_Request_Double( '/wp/v2/posts' ) );
msrwa_test_assert( array() === $other->headers, 'Only this plugin namespace may be touched.' );

$passthrough = MSRWA_REST::no_store( 'not-a-response', null, new WP_REST_Request_Double( '/msrwa/v1/stats' ) );
msrwa_test_assert( 'not-a-response' === $passthrough, 'A non-response value must pass through untouched.' );

msrwa_test_done( 'MSRWA REST cache contracts' );
