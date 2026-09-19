<?php
// Lightweight contract checks runnable without a WordPress installation.
define( 'ABSPATH', __DIR__ );
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value ) { return filter_var( $value, FILTER_SANITIZE_URL ); }
function is_wp_error( $value ) { return false; }
class WP_Error { public function __construct() {} }
require dirname( __DIR__ ) . '/includes/class-msrwa-catalog.php';
require dirname( __DIR__ ) . '/includes/class-msrwa-recipe.php';
require dirname( __DIR__ ) . '/includes/class-msrwa-router.php';

class MSRWA_Settings {
	public static function get() {
		return array( 'mode' => 'automatic', 'openai_key' => '', 'gemini_key' => '', 'claude_key' => '', 'manual_models' => array( 'text' => 'openai:gpt-5.6-luna', 'review' => 'claude:claude-sonnet-5', 'image' => 'gemini:gemini-3.1-flash-image', 'search' => 'openai:gpt-5.6-luna' ) );
	}
}
define( 'MSRWA_OPENAI_KEY', 'contract-test-only' );

$eligible = MSRWA_Catalog::eligible( 'text' );
if ( ! isset( $eligible['openai:gpt-5.6-luna'] ) ) { throw new RuntimeException( 'Luna missing from catalog.' ); }
$input = MSRWA_Recipe::normalize_input( array( 'title' => '  Tarte <b>aux pommes</b> ', 'text' => "Farine\nPommes", 'images' => array( 'https://example.test/a.jpg', 'javascript:alert(1)' ) ) );
if ( 'Tarte aux pommes' !== $input['title'] || 1 !== count( $input['reference_images'] ) ) { throw new RuntimeException( 'Input normalization failed.' ); }
if ( ! MSRWA_Recipe::can_transition( 'intake', 'association' ) || MSRWA_Recipe::can_transition( 'intake', 'review' ) ) { throw new RuntimeException( 'Stage transition contract failed.' ); }
$route = MSRWA_Router::plan( 'text' );
if ( ! is_array( $route ) || 'openai' !== $route['provider'] || 'gpt-5.6-luna' !== $route['model'] ) { throw new RuntimeException( 'Automatic router contract failed.' ); }
echo "MSRWA contracts OK\n";
