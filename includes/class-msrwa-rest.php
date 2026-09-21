<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_REST {
	public static function register() {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store' ), 10, 3 );
		register_rest_route( 'msrwa/v1', '/catalog', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'catalog' ) ) );
		register_rest_route( 'msrwa/v1', '/catalog/sync', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'sync_catalog' ) ) );
		register_rest_route( 'msrwa/v1', '/test/openai', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'test_openai' ) ) );
		register_rest_route( 'msrwa/v1', '/test/(?P<provider>gemini|claude)', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'test_provider' ) ) );
		register_rest_route( 'msrwa/v1', '/batches', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'create_batch' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'get_batch' ) ) );
		register_rest_route( 'msrwa/v1', '/jobs/(?P<id>\d+)/retry', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'retry_job' ) ) );
		register_rest_route( 'msrwa/v1', '/jobs/(?P<id>\d+)/cancel', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'cancel_job' ) ) );
		register_rest_route( 'msrwa/v1', '/jobs/(?P<id>\d+)/association', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'resolve_association' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/pause', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'pause_batch' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/resume', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'resume_batch' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/cancel', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'cancel_batch' ) ) );
		register_rest_route( 'msrwa/v1', '/lab/runs', array(
			array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'lab_runs' ) ),
			array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'lab_start' ) ),
		) );
		register_rest_route( 'msrwa/v1', '/lab/runs/(?P<id>\d+)/cancel', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'lab_cancel' ) ) );
		register_rest_route( 'msrwa/v1', '/stats', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'stats' ) ) );
		register_rest_route( 'msrwa/v1', '/events', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'events' ) ) );
		register_rest_route( 'msrwa/v1', '/export', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_read' ), 'callback' => array( __CLASS__, 'export' ) ) );
	}

	/**
	 * Page caches treat an application-password request as a guest request and
	 * can store the answer for everyone, so every response in this namespace
	 * declares itself private. Verified on a LiteSpeed host serving a cached
	 * catalogue to anonymous visitors.
	 */
	public static function no_store( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response ) { return $response; }
		if ( 0 !== strpos( ltrim( (string) $request->get_route(), '/' ), 'msrwa/' ) ) { return $response; }
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		$response->header( 'X-Accel-Expires', '0' );
		return $response;
	}

	public static function can_read() { return current_user_can( 'msrwa_view_own' ) || current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' ); }
	public static function can_create() { return current_user_can( 'msrwa_create' ) || current_user_can( 'msrwa_manage' ) || current_user_can( 'manage_options' ); }
	public static function can_manage() { return current_user_can( 'manage_options' ); }

	public static function catalog() { return rest_ensure_response( array( 'models' => MSRWA_Catalog::models(), 'status' => MSRWA_Catalog::status() ) ); }

	public static function sync_catalog( WP_REST_Request $request ) {
		$provider = sanitize_key( $request->get_param( 'provider' ) ?: 'openai' );
		$result = MSRWA_Catalog::sync( $provider );
		if ( is_wp_error( $result ) ) { return $result; }
		MSRWA_DB::event( 'catalog_synced', 0, 0, array( 'provider' => $provider, 'count' => $result['count'] ) );
		return rest_ensure_response( $result );
	}

	public static function test_openai() {
		$result = MSRWA_OpenAI::connection_test();
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	public static function test_provider( WP_REST_Request $request ) {
		$result = MSRWA_Providers::connection_test( sanitize_key( $request['provider'] ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	public static function create_batch( WP_REST_Request $request ) {
		global $wpdb;
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$raw_items = $request->get_param( 'items' );
			$decoded_items = is_string( $raw_items ) ? json_decode( wp_unslash( $raw_items ), true ) : $raw_items;
			$payload = array( 'items' => is_array( $decoded_items ) ? $decoded_items : array() );
		}
		$items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
		$settings = MSRWA_Settings::get();
		if ( empty( $items ) || count( $items ) > (int) $settings['max_batch'] ) { return new WP_Error( 'invalid_batch', 'Le lot est vide ou dépasse la limite configurée.', array( 'status' => 400 ) ); }
		foreach ( $items as &$item ) {
			if ( ! is_array( $item ) ) { return new WP_Error( 'invalid_item', 'Chaque entrée du lot doit être un objet.', array( 'status' => 400 ) ); }
			if ( '' === trim( isset( $item['title'] ) ? (string) $item['title'] : '' ) && ! empty( $item['text'] ) ) {
				$lines = preg_split( '/\r?\n/', trim( wp_strip_all_tags( (string) $item['text'] ) ) );
				$item['title'] = sanitize_text_field( isset( $lines[0] ) ? substr( $lines[0], 0, 160 ) : '' );
			}
			if ( '' === trim( isset( $item['title'] ) ? (string) $item['title'] : '' ) ) { return new WP_Error( 'invalid_item', 'Ajoutez une recette ou un titre dans le texte fourni.', array( 'status' => 400 ) ); }
		}
		unset( $item );
		$now = current_time( 'mysql', true );
		$t = MSRWA_DB::tables();
		$snapshot = $settings;
		foreach ( array( 'openai_key', 'gemini_key', 'claude_key' ) as $secret ) { $snapshot[ $secret ] = ''; }
		$wpdb->insert( $t['batches'], array( 'owner_id' => get_current_user_id(), 'status' => 'queued', 'total' => count( $items ), 'settings_snapshot' => wp_json_encode( $snapshot ), 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%s', '%d', '%s', '%s', '%s' ) );
		$batch_id = (int) $wpdb->insert_id;
		MSRWA_DB::snapshot( 'settings', $snapshot, $batch_id, 0 );
		$job_ids = array();
		foreach ( $items as $item ) {
			$title = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
			if ( '' === $title ) { continue; }
			$normalized = MSRWA_Recipe::normalize_input( $item );
			$models = array();
			foreach ( array( 'text' => array( 'capability' => 'text', 'stage' => 'text' ), 'search' => array( 'capability' => 'web_search', 'stage' => 'search' ), 'review' => array( 'capability' => 'text', 'stage' => 'review' ), 'vision' => array( 'capability' => 'vision', 'stage' => 'review' ), 'image' => array( 'capability' => 'image_generation', 'stage' => 'image' ) ) as $key => $route ) {
				$plan = MSRWA_Router::plan( $route['capability'], $route['stage'] );
				if ( ! is_wp_error( $plan ) ) { $models[ $key ] = $plan; }
			}
			$wpdb->insert( $t['jobs'], array( 'batch_id' => $batch_id, 'owner_id' => get_current_user_id(), 'title' => $title, 'input_json' => wp_json_encode( $normalized ), 'selected_models_json' => wp_json_encode( $models ), 'status' => 'queued', 'stage' => 'intake', 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
			$job_id = (int) $wpdb->insert_id;
			$output_options = array_intersect_key( $settings, array_flip( array( 'generate_featured_image', 'generate_facebook_image', 'article_pagination_enabled', 'article_pagination_min_words', 'article_pagination_split_percent' ) ) );
			$wpdb->update( $t['jobs'], array( 'artifacts_json' => wp_json_encode( array( 'output_options' => $output_options ) ) ), array( 'id' => $job_id ), array( '%s' ), array( '%d' ) );
			MSRWA_DB::store_artifact( $job_id, $batch_id, 'output_options', $output_options );
			$job_ids[] = $job_id;
			MSRWA_DB::snapshot( 'input', $normalized, $batch_id, $job_id );
			MSRWA_DB::snapshot( 'model_plan', $models, $batch_id, $job_id );
		}
		$upload_errors = array();
		$file_params = $request->get_file_params();
		if ( ! empty( $job_ids ) && ! empty( $file_params ) && class_exists( 'MSRWA_Storage' ) ) {
			$reference_job_id = (int) reset( $job_ids );
			$uploaded = MSRWA_Storage::store_uploads( $reference_job_id, $file_params );
			$upload_errors = isset( $uploaded['errors'] ) ? $uploaded['errors'] : array();
			if ( ! empty( $uploaded['valid'] ) ) {
				foreach ( $job_ids as $job_id ) {
					$input_json = $wpdb->get_var( $wpdb->prepare( "SELECT input_json FROM {$t['jobs']} WHERE id = %d", $job_id ) );
					$input_data = json_decode( (string) $input_json, true );
					$input_data = is_array( $input_data ) ? $input_data : array();
					$input_data['reference_images'] = array_merge( isset( $input_data['reference_images'] ) && is_array( $input_data['reference_images'] ) ? $input_data['reference_images'] : array(), $uploaded['valid'] );
					$wpdb->update( $t['jobs'], array( 'input_json' => wp_json_encode( $input_data ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job_id ), array( '%s', '%s' ), array( '%d' ) );
					MSRWA_DB::snapshot( 'input', $input_data, $batch_id, $job_id );
				}
			}
			if ( $upload_errors ) { MSRWA_DB::event( 'reference_upload_rejected', $batch_id, $reference_job_id, array( 'errors' => $upload_errors ) ); }
		}
		// The batch total must match the jobs that exist, or it can never complete.
		if ( count( $job_ids ) !== count( $items ) ) {
			$wpdb->update( $t['batches'], array( 'total' => count( $job_ids ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $batch_id ), array( '%d', '%s' ), array( '%d' ) );
		}
		if ( empty( $job_ids ) ) {
			$wpdb->update( $t['batches'], array( 'status' => 'failed', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $batch_id ), array( '%s', '%s' ), array( '%d' ) );
			return new WP_Error( 'batch_not_created', 'Aucune recette n’a pu être enregistrée pour ce lot.', array( 'status' => 500 ) );
		}
		MSRWA_DB::event( 'batch_created', $batch_id, 0, array( 'total' => count( $job_ids ) ) );
		MSRWA_Queue::schedule_batch( $batch_id );
		$response = array( 'id' => $batch_id, 'status' => 'queued', 'total' => count( $job_ids ) );
		if ( $upload_errors ) { $response['reference_upload_errors'] = $upload_errors; }
		return new WP_REST_Response( $response, 201 );
	}

	public static function get_batch( WP_REST_Request $request ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$id = absint( $request['id'] );
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['batches']} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $batch || (int) $batch['owner_id'] !== get_current_user_id() && ! current_user_can( 'msrwa_view_all' ) && ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'not_found', 'Lot introuvable.', array( 'status' => 404 ) ); }
		$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT id,title,status,stage,error_code,error_message,cost_estimate,draft_post_id,attempts,retry_attempts,correction_cycles,correction_cycles_json,created_at,updated_at FROM {$t['jobs']} WHERE batch_id = %d ORDER BY id ASC", $id ), ARRAY_A );
		$batch['jobs'] = $jobs;
		return rest_ensure_response( $batch );
	}

	public static function retry_job( WP_REST_Request $request ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$id = absint( $request['id'] );
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['jobs']} WHERE id = %d", $id ) );
		if ( ! $job || ( (int) $job->owner_id !== get_current_user_id() && ! current_user_can( 'msrwa_view_all' ) && ! current_user_can( 'manage_options' ) ) ) { return new WP_Error( 'not_found', 'Job introuvable.', array( 'status' => 404 ) ); }
		if ( ! in_array( $job->status, array( 'failed', 'needs_review', 'awaiting_input', 'uncertain', 'paused_budget', 'paused', 'awaiting_admin' ), true ) ) { return new WP_Error( 'job_not_retryable', 'Ce job n’est pas dans un état relançable.', array( 'status' => 409 ) ); }
		$wpdb->update( $t['jobs'], array( 'status' => 'queued', 'retry_attempts' => 0, 'error_code' => null, 'error_message' => null, 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		MSRWA_DB::event( 'job_retry_requested', $job->batch_id, $id, array( 'stage' => $job->stage ) );
		MSRWA_Queue::schedule_job( $id );
		return rest_ensure_response( array( 'id' => $id, 'status' => 'queued', 'stage' => $job->stage ) );
	}

	public static function cancel_job( WP_REST_Request $request ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$id = absint( $request['id'] );
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['jobs']} WHERE id = %d", $id ) );
		if ( ! $job || ( (int) $job->owner_id !== get_current_user_id() && ! current_user_can( 'msrwa_view_all' ) && ! current_user_can( 'manage_options' ) ) ) { return new WP_Error( 'not_found', 'Job introuvable.', array( 'status' => 404 ) ); }
		if ( ! MSRWA_Queue::cancel_job( $id ) ) { return new WP_Error( 'job_not_cancelled', 'Ce job est déjà terminé ou annulé.', array( 'status' => 409 ) ); }
		return rest_ensure_response( array( 'id' => $id, 'status' => 'cancelled' ) );
	}

	public static function resolve_association( WP_REST_Request $request ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$id = absint( $request['id'] );
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['jobs']} WHERE id = %d", $id ) );
		if ( ! $job || ( (int) $job->owner_id !== get_current_user_id() && ! current_user_can( 'msrwa_view_all' ) && ! current_user_can( 'manage_options' ) ) ) { return new WP_Error( 'not_found', 'Job introuvable.', array( 'status' => 404 ) ); }
		if ( 'awaiting_input' !== $job->status || 'association' !== $job->stage ) { return new WP_Error( 'association_not_pending', 'Ce job n’attend pas une confirmation d’association.', array( 'status' => 409 ) ); }
		$payload = $request->get_json_params();
		if ( empty( $payload['confirmed'] ) ) { return new WP_Error( 'association_confirmation_required', 'La confirmation explicite est requise.', array( 'status' => 400 ) ); }
		$artifacts = json_decode( (string) $job->artifacts_json, true );
		$artifacts = is_array( $artifacts ) ? $artifacts : array();
		$artifacts['association'] = isset( $artifacts['association'] ) && is_array( $artifacts['association'] ) ? $artifacts['association'] : array();
		$artifacts['association']['editor_confirmed'] = true;
		$artifacts['association']['needs_editor'] = false;
		$updated = $wpdb->update( $t['jobs'], array( 'artifacts_json' => wp_json_encode( $artifacts ), 'status' => 'queued', 'retry_attempts' => 0, 'error_code' => null, 'error_message' => null, 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id, 'status' => 'awaiting_input' ), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ), array( '%d', '%s' ) );
		if ( ! $updated ) { return new WP_Error( 'association_update_failed', 'La confirmation n’a pas pu être enregistrée.', array( 'status' => 409 ) ); }
		MSRWA_DB::store_artifacts( $id, $job->batch_id, $artifacts );
		MSRWA_DB::snapshot( 'editor_decision', array( 'type' => 'association_confirmation', 'confirmed' => true ), $job->batch_id, $id );
		MSRWA_DB::event( 'association_confirmed', $job->batch_id, $id );
		MSRWA_Queue::schedule_job( $id );
		return rest_ensure_response( array( 'id' => $id, 'status' => 'queued', 'stage' => 'association' ) );
	}

	private static function owned_batch( $id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['batches']} WHERE id = %d", absint( $id ) ) );
		if ( ! $batch || (int) $batch->owner_id !== get_current_user_id() && ! current_user_can( 'msrwa_view_all' ) && ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'not_found', 'Lot introuvable.', array( 'status' => 404 ) ); }
		return $batch;
	}

	public static function pause_batch( WP_REST_Request $request ) {
		$batch = self::owned_batch( $request['id'] );
		if ( is_wp_error( $batch ) ) { return $batch; }
		if ( ! MSRWA_Queue::pause_batch( $batch->id ) ) { return new WP_Error( 'batch_not_paused', 'Ce lot est déjà terminé ou annulé.', array( 'status' => 409 ) ); }
		return rest_ensure_response( array( 'id' => (int) $batch->id, 'status' => 'paused' ) );
	}

	public static function resume_batch( WP_REST_Request $request ) {
		$batch = self::owned_batch( $request['id'] );
		if ( is_wp_error( $batch ) ) { return $batch; }
		if ( ! MSRWA_Queue::resume_batch( $batch->id ) ) { return new WP_Error( 'batch_not_resumed', 'Ce lot n’est pas suspendu ou en attente de validation.', array( 'status' => 409 ) ); }
		return rest_ensure_response( array( 'id' => (int) $batch->id, 'status' => 'queued' ) );
	}

	public static function cancel_batch( WP_REST_Request $request ) {
		$batch = self::owned_batch( $request['id'] );
		if ( is_wp_error( $batch ) ) { return $batch; }
		if ( ! MSRWA_Queue::cancel_batch( $batch->id ) ) { return new WP_Error( 'batch_not_cancelled', 'Ce lot est déjà terminé ou annulé.', array( 'status' => 409 ) ); }
		return rest_ensure_response( array( 'id' => (int) $batch->id, 'status' => 'cancelled' ) );
	}

	public static function stats( WP_REST_Request $request ) {
		$days = absint( $request->get_param( 'days' ) ?: 7 );
		$owner_id = current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' ) ? 0 : get_current_user_id();
		$from = $request->get_param( 'from' );
		$to = $request->get_param( 'to' );
		if ( $from && $to ) {
			$current = MSRWA_Stats::summary_range( $from, $to, $owner_id );
			if ( is_wp_error( $current ) ) { return $current; }
			$start = strtotime( $current['from'] ); $end = strtotime( $current['to'] ); $duration = max( 86400, $end - $start );
			$previous = MSRWA_Stats::summary_range( gmdate( 'Y-m-d H:i:s', $start - $duration ), gmdate( 'Y-m-d H:i:s', $start ), $owner_id );
			$current['previous'] = is_wp_error( $previous ) ? array() : $previous;
			return rest_ensure_response( $current );
		}
		return rest_ensure_response( MSRWA_Stats::summary( $days, $owner_id ) );
	}

	public static function events( WP_REST_Request $request ) {
		$page = absint( $request->get_param( 'page' ) ?: 1 );
		$owner_id = current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' ) ? 0 : get_current_user_id();
		return rest_ensure_response( MSRWA_Stats::events( $page, 50, $owner_id ) );
	}

	public static function export( WP_REST_Request $request ) {
		$dataset = sanitize_key( $request->get_param( 'dataset' ) ?: 'jobs' );
		$from = sanitize_text_field( $request->get_param( 'from' ) ?: gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ) );
		$to = sanitize_text_field( $request->get_param( 'to' ) ?: gmdate( 'Y-m-d' ) );
		$page = absint( $request->get_param( 'page' ) ?: 1 );
		$per_page = absint( $request->get_param( 'per_page' ) ?: 100 );
		$owner_id = current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' ) ? 0 : get_current_user_id();
		$result = MSRWA_Stats::export( $dataset, $from, $to, $page, $per_page, $owner_id );
		if ( is_wp_error( $result ) ) { return $result; }
		$format = strtolower( sanitize_key( $request->get_param( 'format' ) ?: 'json' ) );
		if ( 'csv' !== $format ) { return rest_ensure_response( $result ); }
		$columns = array();
		foreach ( $result['rows'] as $row ) { $columns = array_unique( array_merge( $columns, array_keys( $row ) ) ); }
		$handle = fopen( 'php://temp', 'r+' );
		fputcsv( $handle, $columns );
		foreach ( $result['rows'] as $row ) {
			$values = array();
			foreach ( $columns as $column ) { $value = isset( $row[ $column ] ) ? $row[ $column ] : ''; $values[] = is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : $value; }
			fputcsv( $handle, $values );
		}
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );
		$response = new WP_REST_Response( $csv, 200 );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename=msrwa-' . $dataset . '-' . gmdate( 'Ymd-His' ) . '.csv' );
		return $response;
	}

	/**
	 * The laboratory's runs, as the screen polls them.
	 *
	 * Only what the table shows: the stored result carries the whole run and is
	 * far too large to hand back every two seconds.
	 */
	public static function lab_runs() {
		$out = array();
		foreach ( MSRWA_Lab::recent( 30 ) as $run ) {
			$out[] = array(
				'id' => (int) $run['id'],
				'label' => (string) $run['label'],
				'status' => (string) $run['status'],
				'step' => (string) $run['step'],
				'steps_done' => (int) $run['steps_done'],
				'steps_total' => (int) $run['steps_total'],
				'cost_usd' => (float) $run['cost_usd'],
				'seconds' => (float) $run['seconds'],
				'error_message' => (string) $run['error_message'],
			);
		}
		return rest_ensure_response( array( 'runs' => $out ) );
	}

	/** Starts a run and hands back its number. Cron does the rest. */
	public static function lab_start( $request ) {
		$fixture = sanitize_key( (string) $request->get_param( 'fixture' ) );
		$brief = '' !== $fixture ? MSRWA_Lab_Screen::brief( $fixture ) : array();
		if ( ! $brief ) {
			$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
			if ( '' === $title ) { return new WP_Error( 'msrwa_lab_no_brief', 'Donnez un sujet ou un titre.', array( 'status' => 400 ) ); }
			$brief = array( 'type' => 'title', 'title' => $title, 'text' => '', 'images' => array() );
		}

		$budget = round( (float) $request->get_param( 'budget' ), 4 );
		if ( $budget <= 0 ) { return new WP_Error( 'msrwa_lab_no_budget', 'Un run sans plafond de dépense n’est pas lancé d’ici.', array( 'status' => 400 ) ); }

		$id = MSRWA_Lab::create( $brief, array( 'limits' => array( 'budget_usd' => $budget ) ) );
		if ( ! $id ) { return new WP_Error( 'msrwa_lab_not_created', 'Le run n’a pas pu être enregistré.', array( 'status' => 500 ) ); }
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public static function lab_cancel( $request ) {
		$run = MSRWA_Lab::get( absint( $request['id'] ) );
		if ( ! $run || ! MSRWA_Lab::may_see( $run ) ) { return new WP_Error( 'msrwa_lab_not_found', 'Run introuvable.', array( 'status' => 404 ) ); }
		return rest_ensure_response( array( 'cancelled' => MSRWA_Lab::cancel( (int) $run['id'] ) ) );
	}
}
