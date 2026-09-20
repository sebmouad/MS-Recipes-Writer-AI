<?php
// Offline transport contracts. Never sends an API request.
define( 'ABSPATH', __DIR__ );
define( 'MSRWA_OPENAI_KEY', 'offline-test-only' );
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return sanitize_text_field( $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error { public function __construct( ...$args ) {} }
class MSRWA_Settings { public static function get() { return array( 'manual_models' => array(), 'openai_model' => 'fixture', 'web_search_max_tool_calls' => 1 ); } }
class MSRWA_Catalog {
    public static function models() { return array( 'openai' => array( 'fixture' => array( 'stable' => true ) ) ); }
    public static function endpoint( ...$args ) { return 'https://api.openai.com/v1/responses'; }
    public static function timeout( ...$args ) { return 60; }
}
function wp_remote_post( $url, $args ) { $GLOBALS['captured_payload'] = json_decode( $args['body'], true ); return array(); }
function wp_remote_retrieve_response_code( $response ) { return 200; }
function wp_remote_retrieve_body( $response ) { return '{"id":"offline","status":"completed","output_text":"{}","output":[{"type":"web_search_call","action":{"type":"search"}},{"type":"web_search_call","action":{"type":"open_page"}}],"usage":{"input_tokens":8,"output_tokens":2}}'; }
require dirname( __DIR__ ) . '/includes/class-msrwa-openai.php';
$input = 'Review JSON: {"content_html":"<h2>Recette</h2><p>Citron</p>"}';
MSRWA_OpenAI::responses_text( $input, 'fixture', 100, array(), false, true );
if ( $GLOBALS['captured_payload']['input'] !== $input ) { throw new RuntimeException( 'Article HTML lost in API transport.' ); }
if ( 'json_object' !== $GLOBALS['captured_payload']['text']['format']['type'] ) { throw new RuntimeException( 'Structured text output not enabled.' ); }
$result = MSRWA_OpenAI::responses_text( 'Research; return JSON.', 'fixture', 100, array( array( 'type' => 'web_search' ) ), true, true );
if ( 1 !== $result['search_calls'] || 1 !== $GLOBALS['captured_payload']['max_tool_calls'] ) { throw new RuntimeException( 'Search accounting or request limit incorrect.' ); }
if ( isset( $GLOBALS['captured_payload']['text'] ) || 'required' !== $GLOBALS['captured_payload']['tool_choice'] ) { throw new RuntimeException( 'Web search incorrectly combines JSON mode.' ); }
echo "MSRWA OpenAI transport contracts OK\n";
