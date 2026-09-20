<?php
define( 'ABSPATH', __DIR__ );
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function home_url( $path ) { return 'https://example.com' . $path; }
function wp_make_link_relative( $url ) { return preg_replace( '~^https?://[^/]+~', '', $url ); }
function esc_url_raw( $url ) { return $url; }
require dirname( __DIR__ ) . '/includes/class-msrwa-publisher.php';
$method = new ReflectionMethod( MSRWA_Publisher::class, 'insert_internal_links' );
$links = array( array( 'url' => '/tarte/', 'anchor' => 'tarte aux pommes' ), array( 'url' => '/creme/', 'anchor' => 'crème vanillée' ), array( 'url' => '/absent/', 'anchor' => 'ancre absente' ) );
$html = '<h2>Tarte aux pommes</h2><p>Essayez une tarte aux pommes avec une <strong>crème vanillée</strong>.</p><p>Une tarte aux pommes.</p>';
$result = $method->invoke( null, $html, $links );
if ( 2 !== substr_count( $result, '<a href=' ) || false !== strpos( $result, '<section' ) || false !== strpos( $result, '/absent/' ) || false === strpos( $result, '<h2>Tarte aux pommes</h2>' ) ) { throw new RuntimeException( 'Inline placement failed.' ); }
if ( strip_tags( $result ) !== strip_tags( $html ) ) { throw new RuntimeException( 'Article wording changed.' ); }
if ( $result !== $method->invoke( null, $result, $links ) ) { throw new RuntimeException( 'Duplicate links.' ); }
$existing = '<p><a href="/other/">tarte aux pommes</a></p>';
if ( $existing !== $method->invoke( null, $existing, $links ) ) { throw new RuntimeException( 'Nested anchor.' ); }
$external = array( array( 'url' => 'https://evil.example/tarte/', 'anchor' => 'tarte aux pommes' ) );
if ( $html !== $method->invoke( null, $html, $external ) ) { throw new RuntimeException( 'External target allowed.' ); }
echo "MSRWA contextual link contracts OK\n";
