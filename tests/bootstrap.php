<?php
/**
 * Shared harness for the offline test suite.
 *
 * It declares just enough of WordPress for plugin classes to run in plain PHP:
 * constants, escaping, capability and formatting helpers, plus a recording
 * $wpdb. Every helper is guarded so a test may define its own first.
 *
 * Capabilities come from $GLOBALS['msrwa_test_caps']; set them with
 * msrwa_test_as_editor() or msrwa_test_as_admin() before exercising a screen.
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'ARRAY_N' ) ) { define( 'ARRAY_N', 'ARRAY_N' ); }
if ( ! defined( 'MSRWA_VERSION' ) ) { define( 'MSRWA_VERSION', '0.0.0-test' ); }
if ( ! defined( 'MSRWA_DIR' ) ) { define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'MSRWA_URL' ) ) { define( 'MSRWA_URL', 'https://example.test/wp-content/plugins/ms-recipes-writer-ai/' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['msrwa_test_caps'] = array( 'edit_posts' );
$GLOBALS['msrwa_test_user'] = 7;
$GLOBALS['msrwa_test_failures'] = array();

function msrwa_test_as_editor( $user_id = 7 ) { $GLOBALS['msrwa_test_caps'] = array( 'edit_posts', 'msrwa_create', 'msrwa_view_own' ); $GLOBALS['msrwa_test_user'] = $user_id; }
function msrwa_test_as_admin( $user_id = 1 ) { $GLOBALS['msrwa_test_caps'] = array( 'edit_posts', 'msrwa_create', 'msrwa_manage', 'msrwa_view_all', 'manage_options' ); $GLOBALS['msrwa_test_user'] = $user_id; }

/** Records a failed expectation instead of stopping, so one run reports everything. */
function msrwa_test_assert( $condition, $message ) {
	if ( ! $condition ) { $GLOBALS['msrwa_test_failures'][] = $message; }
	return (bool) $condition;
}

function msrwa_test_contains( $haystack, $needle, $message ) { return msrwa_test_assert( false !== strpos( (string) $haystack, (string) $needle ), $message ); }
function msrwa_test_missing( $haystack, $needle, $message ) { return msrwa_test_assert( false === strpos( (string) $haystack, (string) $needle ), $message ); }

/** Prints the result and sets the exit code the runner reads. */
function msrwa_test_done( $label ) {
	if ( empty( $GLOBALS['msrwa_test_failures'] ) ) { echo $label . " OK\n"; exit( 0 ); }
	foreach ( $GLOBALS['msrwa_test_failures'] as $failure ) { fwrite( STDERR, 'FAIL: ' . $failure . "\n" ); }
	exit( 1 );
}

if ( ! function_exists( 'esc_html' ) ) { function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $value ) { return (string) $value; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $value ) { return (string) $value; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $value, $domain = '' ) { return $value; } }
if ( ! function_exists( 'esc_html_e' ) ) { function esc_html_e( $value, $domain = '' ) { echo $value; } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $value, $domain = '' ) { echo htmlspecialchars( (string) $value, ENT_QUOTES ); } }
if ( ! function_exists( '__' ) ) { function __( $value, $domain = '' ) { return $value; } }
if ( ! function_exists( '_n' ) ) { function _n( $single, $plural, $number, $domain = '' ) { return 1 === (int) $number ? $single : $plural; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $value ) { return (string) $value; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); } }
if ( ! function_exists( 'strip_shortcodes' ) ) { function strip_shortcodes( $value ) { return (string) $value; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $value ) { return '<p>' . (string) $value . '</p>'; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' ); } }
if ( ! function_exists( 'sanitize_user' ) ) { function sanitize_user( $value ) { return preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $value ); } }
if ( ! function_exists( 'absint' ) ) { function absint( $value ) { return abs( (int) $value ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $value ) { return $value; } }
if ( ! function_exists( 'wp_slash' ) ) { function wp_slash( $value ) { return $value; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); } }
if ( ! function_exists( 'wp_rand' ) ) { function wp_rand( $min = 0, $max = 1 ) { return $min; } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $type = 'mysql', $gmt = 0 ) { return 'timestamp' === $type ? 1789000000 : '2026-09-20 12:00:00'; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $value, $decimals = 0 ) { return number_format( (float) $value, $decimals ); } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $from, $to = 0 ) { return max( 0, (int) round( abs( $to - $from ) / 60 ) ) . ' min'; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $capability ) { return in_array( $capability, (array) $GLOBALS['msrwa_test_caps'], true ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return (int) $GLOBALS['msrwa_test_user']; } }
if ( ! function_exists( 'user_can' ) ) { function user_can( $user, $capability ) { return current_user_can( $capability ); } }
if ( ! function_exists( 'get_userdata' ) ) { function get_userdata( $user_id ) { return (object) array( 'ID' => (int) $user_id, 'display_name' => 'Éditeur ' . (int) $user_id ); } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $message = '' ) { throw new RuntimeException( (string) $message ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); } }
if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args ); } }
if ( ! function_exists( 'get_edit_post_link' ) ) { function get_edit_post_link( $post_id, $context = '' ) { return $post_id ? 'https://example.test/wp-admin/post.php?post=' . (int) $post_id . '&action=edit' : null; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $post_id ) { return 'https://example.test/?p=' . (int) $post_id; } }
if ( ! function_exists( 'get_post_status' ) ) { function get_post_status( $post_id ) { return 'draft'; } }
if ( ! function_exists( 'selected' ) ) { function selected( $selected, $current = true, $echo = true ) { $result = (string) $selected === (string) $current ? " selected='selected'" : ''; if ( $echo ) { echo $result; } return $result; } }
if ( ! function_exists( 'checked' ) ) { function checked( $checked, $current = true, $echo = true ) { $result = (string) $checked === (string) $current ? " checked='checked'" : ''; if ( $echo ) { echo $result; } return $result; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $text = '' ) { echo '<button type="submit">' . esc_html( $text ) . '</button>'; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $action = '' ) { echo '<input type="hidden" name="_wpnonce" value="test">'; } }
if ( ! function_exists( 'paginate_links' ) ) {
	function paginate_links( $args ) {
		$out = '';
		for ( $page = 1; $page <= (int) $args['total']; $page++ ) {
			$out .= '<a class="page-numbers' . ( $page === (int) $args['current'] ? ' current' : '' ) . '" href="' . str_replace( '%#%', $page, $args['base'] ) . '">' . $page . '</a>';
		}
		return $out;
	}
}
// A test that cares whether an event was scheduled sets this to false first;
// everything else reads a site whose cron is already armed.
$GLOBALS['msrwa_test_next_scheduled'] = 1789003600;
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['msrwa_test_next_scheduled']; } }
$GLOBALS['msrwa_test_scheduled'] = array();
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { $GLOBALS['msrwa_test_scheduled'][] = array( 'hook' => $hook, 'args' => $args, 'delay' => $timestamp - current_time( 'timestamp', true ) ); return true; } }
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { function wp_clear_scheduled_hook( $hook ) { return true; } }
function msrwa_test_scheduled_jobs() { return array_values( array_map( static function ( $event ) { return (int) reset( $event['args'] ); }, array_filter( $GLOBALS['msrwa_test_scheduled'], static function ( $event ) { return 'msrwa_process_job' === $event['hook']; } ) ) ); }
if ( ! function_exists( 'add_action' ) ) { function add_action( ...$args ) { return true; } }
if ( ! function_exists( 'add_meta_box' ) ) { function add_meta_box( ...$args ) { return true; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ) { return $value instanceof WP_Error; } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

require_once __DIR__ . '/lib/class-fake-wpdb.php';
require_once __DIR__ . '/lib/class-fake-role.php';
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();

/**
 * The roles this site has, and what each carries.
 *
 * Empty by default: a test that cares about capabilities says so, and one that
 * does not is not quietly given a site where every role exists.
 */
function msrwa_test_roles( array $roles = array() ) {
	$GLOBALS['msrwa_test_roles'] = $roles;
}
msrwa_test_roles();

if ( ! function_exists( 'get_role' ) ) {
	function get_role( $name ) {
		$roles = (array) ( $GLOBALS['msrwa_test_roles'] ?? array() );
		return isset( $roles[ $name ] ) ? new MSRWA_Fake_Role( (array) $roles[ $name ] ) : null;
	}
}

/** Loads plugin classes by short name, in dependency order. */
function msrwa_test_load( ...$classes ) {
	foreach ( $classes as $class ) { require_once dirname( __DIR__ ) . '/includes/class-msrwa-' . $class . '.php'; }
}

/** Minimal settings double; pass overrides for the keys a test cares about. */
function msrwa_test_settings( $overrides = array() ) {
	$GLOBALS['msrwa_test_settings'] = array_merge( array(
		'mode' => 'automatic', 'max_batch' => 50, 'max_concurrency' => 4, 'max_corrections' => 2,
		'max_reference_images' => 5, 'quality_min_score' => 90, 'quality_min_words' => 2000,
		'quality_max_words' => 4200, 'quality_min_headings' => 12, 'quality_min_paragraphs' => 28,
		'quality_min_ingredients' => 6, 'quality_min_steps' => 6, 'internal_links_max' => 3,
		'target_cost_usd' => 0.1, 'log_days' => 30,
	), $overrides );
}
msrwa_test_settings();

if ( ! class_exists( 'MSRWA_Settings' ) ) {
	class MSRWA_Settings {
		const FORM_FIELD = 'msrwa_settings';
		public static function get() { return $GLOBALS['msrwa_test_settings']; }
	}
}

// The real class, not a stand-in: its table list and its secret-stripping are
// what the plugin actually relies on, and a stub that drifts from them would
// let a test pass over code that does not exist.
// Options live in memory for the duration of a test, so classes that store
// their settings there run as they really do rather than through a double.
$GLOBALS['msrwa_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['msrwa_test_options'] ) ? $GLOBALS['msrwa_test_options'][ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['msrwa_test_options'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) { unset( $GLOBALS['msrwa_test_options'][ $key ] ); return true; }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) { return false; }
}
if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( $path ) { return true; }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() . '/msrwa-test', 'baseurl' => 'https://example.test/uploads' ); }
}
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true ) {
		echo '<p><button type="submit" class="button button-primary">' . esc_html( $text ) . '</button></p>';
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test">';
		if ( $display ) { echo $field; }
		return $field;
	}
}
// A test that needs a filter to fire sets $GLOBALS['msrwa_test_filters'][hook].
$GLOBALS['msrwa_test_filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return array_key_exists( $hook, $GLOBALS['msrwa_test_filters'] ) ? $GLOBALS['msrwa_test_filters'][ $hook ] : $value; }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) { return gmdate( $format, $timestamp ?: time() ); }
}
if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( $from, $to = 0 ) { return '1 min'; }
}
if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) { return number_format( (float) $bytes / 1024, $decimals ) . ' KB'; }
}

// Posts and their meta live in memory, so a class that reads back from
// WordPress is exercised rather than stubbed out.
$GLOBALS['msrwa_test_posts'] = array();
$GLOBALS['msrwa_test_meta'] = array();

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . '/'; }
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id = 0 ) { return $GLOBALS['msrwa_test_posts'][ (int) $id ] ?? null; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		$meta = $GLOBALS['msrwa_test_meta'][ (int) $id ] ?? array();
		if ( '' === $key ) { return $meta; }
		return $meta[ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) { $GLOBALS['msrwa_test_meta'][ (int) $id ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $id ) { return '/uploads/attachment-' . (int) $id . '.webp'; }
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $path ) { $GLOBALS['msrwa_test_deleted'][] = $path; }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) { return $url . '&' . $name . '=test'; }
}
if ( ! function_exists( 'wp_dropdown_users' ) ) {
	function wp_dropdown_users( $args = array() ) {
		$name = isset( $args['name'] ) ? $args['name'] : 'user';
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( isset( $args['id'] ) ? $args['id'] : $name ) . '"><option value="0">'
			. esc_html( isset( $args['show_option_all'] ) ? $args['show_option_all'] : '' ) . '</option></select>';
	}
}

if ( ! function_exists( 'paginate_links' ) ) {
	function paginate_links( $args = array() ) { return ''; }
}

if ( ! class_exists( 'MSRWA_DB' ) ) { require_once dirname( __DIR__ ) . '/includes/class-msrwa-db.php'; }
