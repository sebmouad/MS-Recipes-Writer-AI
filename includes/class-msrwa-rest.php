<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the screens call. Six routes, all authenticated by WordPress.
 */
final class MSRWA_REST {

	public static function register() {
		register_rest_route( 'msrwa/v1', '/diagnostics/config', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( 'MSRWA_Operations', 'preview' ) ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store' ), 10, 3 );
		register_rest_route( 'msrwa/v1', '/batches', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'create' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/pairs', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'pairs' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/dispatch', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'dispatch' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/runs', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'runs' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)', array( 'methods' => 'DELETE', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'remove' ) ) );
		register_rest_route( 'msrwa/v1', '/runs/(?P<id>\d+)/cancel', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'cancel' ) ) );
	}

	/**
	 * Page caches treat an application-password request as a guest request and
	 * can store the answer for everyone, so every response here is private.
	 */
	public static function no_store( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response ) { return $response; }
		if ( 0 !== strpos( ltrim( (string) $request->get_route(), '/' ), 'msrwa/' ) ) { return $response; }
		foreach ( array( 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private', 'Pragma' => 'no-cache', 'X-LiteSpeed-Cache-Control' => 'no-cache', 'X-Accel-Expires' => '0' ) as $header => $value ) {
			$response->header( $header, $value );
		}
		return $response;
	}

	public static function can_create() { return current_user_can( 'msrwa_create' ) || current_user_can( 'manage_options' ); }
	public static function can_manage() { return current_user_can( 'manage_options' ); }

	/** Splits the submission, describes the photographs, pairs them. */
	public static function create( WP_REST_Request $request ) {
		$recipes = MSRWA_Intake::recipes( (string) $request->get_param( 'recipes' ) );
		if ( ! $recipes ) { return new WP_Error( 'msrwa_no_recipes', 'Aucune recette lisible dans ce texte.', array( 'status' => 400 ) ); }

		$ids = $request->get_param( 'images' );
		$ids = is_string( $ids ) ? array_filter( array_map( 'absint', explode( ',', $ids ) ) ) : (array) $ids;
		$images = MSRWA_Intake::images( $ids );

		$id = MSRWA_Batch::create( $recipes, $images, (float) $request->get_param( 'budget' ) );
		if ( is_wp_error( $id ) ) { return $id; }
		return rest_ensure_response( array( 'id' => (int) $id, 'recipes' => count( $recipes ), 'images' => count( $images ) ) );
	}

	private static function batch( $id ) {
		$batch = MSRWA_Batch::get( absint( $id ) );
		if ( ! $batch || ! MSRWA_Batch::may_see( $batch ) ) { return null; }
		return $batch;
	}

	public static function pairs( WP_REST_Request $request ) {
		$batch = self::batch( $request['id'] );
		if ( ! $batch ) { return new WP_Error( 'msrwa_not_found', 'Lot introuvable.', array( 'status' => 404 ) ); }
		if ( 'ready' !== $batch['status'] ) { return new WP_Error( 'msrwa_locked', 'Ce lot est déjà lancé ; son appariement ne change plus.', array( 'status' => 409 ) ); }
		MSRWA_Batch::repair( (int) $batch['id'], (array) $request->get_param( 'pairs' ) );
		return rest_ensure_response( array( 'saved' => true ) );
	}

	public static function dispatch( WP_REST_Request $request ) {
		$batch = self::batch( $request['id'] );
		if ( ! $batch ) { return new WP_Error( 'msrwa_not_found', 'Lot introuvable.', array( 'status' => 404 ) ); }
		$started = MSRWA_Batch::dispatch( (int) $batch['id'] );
		if ( is_wp_error( $started ) ) { return $started; }
		return rest_ensure_response( array( 'started' => (int) $started ) );
	}

	/** What the batch screen polls while its runs advance. */
	public static function runs( WP_REST_Request $request ) {
		$batch = self::batch( $request['id'] );
		if ( ! $batch ) { return new WP_Error( 'msrwa_not_found', 'Lot introuvable.', array( 'status' => 404 ) ); }
		$out = array();
		foreach ( MSRWA_Run::for_batch( (int) $batch['id'] ) as $run ) {
			$out[] = array(
				'id' => (int) $run['id'], 'label' => (string) $run['label'], 'status' => (string) $run['status'],
				'step' => (string) $run['step'], 'steps_done' => (int) $run['steps_done'], 'steps_total' => (int) $run['steps_total'],
				'cost_usd' => (float) $run['cost_usd'], 'seconds' => (float) $run['seconds'],
				'approved' => null === $run['approved'] ? null : (bool) $run['approved'],
				'draft_post_id' => (int) $run['draft_post_id'],
			);
		}
		return rest_ensure_response( array( 'status' => (string) $batch['status'], 'runs' => $out ) );
	}

	public static function cancel( WP_REST_Request $request ) {
		$run = MSRWA_Run::get( absint( $request['id'] ) );
		if ( ! $run || ! MSRWA_Run::may_see( $run ) ) { return new WP_Error( 'msrwa_not_found', 'Run introuvable.', array( 'status' => 404 ) ); }
		return rest_ensure_response( array( 'cancelled' => MSRWA_Run::cancel( (int) $run['id'] ) ) );
	}

	public static function remove( WP_REST_Request $request ) {
		$batch = self::batch( $request['id'] );
		if ( ! $batch ) { return new WP_Error( 'msrwa_not_found', 'Lot introuvable.', array( 'status' => 404 ) ); }
		if ( 'running' === $batch['status'] ) { return new WP_Error( 'msrwa_running', 'Arrêtez les runs avant de supprimer le lot.', array( 'status' => 409 ) ); }
		return rest_ensure_response( array( 'deleted' => MSRWA_Batch::delete( (int) $batch['id'] ) ) );
	}
}
