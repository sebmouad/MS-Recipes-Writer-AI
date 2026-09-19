<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_REST {
	public static function register() {
		register_rest_route( 'msrwa/v1', '/catalog', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'catalog' ) ) );
		register_rest_route( 'msrwa/v1', '/test/openai', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'test_openai' ) ) );
		register_rest_route( 'msrwa/v1', '/batches', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'create_batch' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'get_batch' ) ) );
	}

	public static function can_read() { return current_user_can( 'edit_posts' ); }
	public static function can_create() { return current_user_can( 'edit_posts' ); }
	public static function can_manage() { return current_user_can( 'manage_options' ); }

	public static function catalog() { return rest_ensure_response( MSRWA_Catalog::models() ); }

	public static function test_openai() {
		$result = MSRWA_OpenAI::connection_test();
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	public static function create_batch( WP_REST_Request $request ) {
		global $wpdb;
		$payload = $request->get_json_params();
		$items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
		$settings = MSRWA_Settings::get();
		if ( empty( $items ) || count( $items ) > (int) $settings['max_batch'] ) { return new WP_Error( 'invalid_batch', 'Le lot est vide ou dépasse la limite configurée.', array( 'status' => 400 ) ); }
		$now = current_time( 'mysql', true );
		$t = MSRWA_DB::tables();
		$wpdb->insert( $t['batches'], array( 'owner_id' => get_current_user_id(), 'status' => 'queued', 'total' => count( $items ), 'settings_snapshot' => wp_json_encode( $settings ), 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%s', '%d', '%s', '%s', '%s' ) );
		$batch_id = (int) $wpdb->insert_id;
		foreach ( $items as $item ) {
			$title = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
			if ( '' === $title ) { continue; }
			$normalized = MSRWA_Recipe::normalize_input( $item );
			$models = array();
			$text_plan = MSRWA_Router::plan( 'text' );
			if ( ! is_wp_error( $text_plan ) ) { $models['text'] = $text_plan; }
			$image_plan = MSRWA_Router::plan( 'image_generation' );
			if ( ! is_wp_error( $image_plan ) ) { $models['featured_image'] = $image_plan; }
			$wpdb->insert( $t['jobs'], array( 'batch_id' => $batch_id, 'owner_id' => get_current_user_id(), 'title' => $title, 'input_json' => wp_json_encode( $normalized ), 'selected_models_json' => wp_json_encode( $models ), 'status' => 'queued', 'stage' => 'intake', 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		}
		MSRWA_DB::event( 'batch_created', $batch_id, 0, array( 'total' => count( $items ) ) );
		MSRWA_Queue::schedule_batch( $batch_id );
		return new WP_REST_Response( array( 'id' => $batch_id, 'status' => 'queued' ), 201 );
	}

	public static function get_batch( WP_REST_Request $request ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$id = absint( $request['id'] );
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['batches']} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $batch || (int) $batch['owner_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'not_found', 'Lot introuvable.', array( 'status' => 404 ) ); }
		$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT id,title,status,stage,error_code,error_message,created_at,updated_at FROM {$t['jobs']} WHERE batch_id = %d ORDER BY id ASC", $id ), ARRAY_A );
		$batch['jobs'] = $jobs;
		return rest_ensure_response( $batch );
	}
}
