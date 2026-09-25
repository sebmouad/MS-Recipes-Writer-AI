<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the screens call. Six routes, all authenticated by WordPress.
 */
final class MSRWA_REST {

	public static function register() {
		register_rest_route( 'msrwa/v1', '/diagnostics/config', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( 'MSRWA_Operations', 'preview' ) ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store' ), 10, 3 );
		register_rest_route( 'msrwa/v1', '/health', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( 'MSRWA_Operations', 'health' ) ) );
		register_rest_route( 'msrwa/v1', '/estimate', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'estimate' ) ) );
		register_rest_route( 'msrwa/v1', '/batches', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'create' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/pairs', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'pairs' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/schedule', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'schedule' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/dispatch', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'dispatch' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/runs', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'runs' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/photos/(?P<name>[a-f0-9]{32}\.(?:jpg|png|webp))', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'photo' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)', array( 'methods' => 'DELETE', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'remove' ) ) );
		register_rest_route( 'msrwa/v1', '/queue', array(
			array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'queue' ) ),
			array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'queue_control' ) ),
		) );
		register_rest_route( 'msrwa/v1', '/keys/check', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'check_keys' ) ) );
		register_rest_route( 'msrwa/v1', '/catalog/models', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'fetch_models' ) ) );
		register_rest_route( 'msrwa/v1', '/catalog/prices', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'fetch_prices' ) ) );
		register_rest_route( 'msrwa/v1', '/retention', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_manage' ), 'callback' => array( __CLASS__, 'prune' ) ) );
		register_rest_route( 'msrwa/v1', '/batches/(?P<id>\d+)/nudge', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'nudge' ) ) );
		register_rest_route( 'msrwa/v1', '/runs/bulk', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'bulk' ) ) );
		register_rest_route( 'msrwa/v1', '/runs/(?P<id>\d+)/redraw', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'redraw' ) ) );
		register_rest_route( 'msrwa/v1', '/runs/(?P<id>\d+)/retry', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'can_create' ), 'callback' => array( __CLASS__, 'retry' ) ) );
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

	public static function can_create() { return MSRWA_Rights::may_write(); }
	// The screens open on msrwa_manage; the routes behind their buttons have to
	// agree, or someone given the capability sees buttons that answer 403.
	public static function can_manage() { return MSRWA_Rights::may_manage(); }

	/**
	 * What a lot would cost, from the configuration that will actually run it.
	 *
	 * Derived rather than guessed: change a route on the Moteur screen and this
	 * follows. It stays an estimate, and the ceiling beside it is the number
	 * that cannot be exceeded.
	 */
	public static function estimate( WP_REST_Request $request ) {
		$profile = sanitize_key( (string) $request->get_param( 'profile' ) );
		$estimate = MSRWA_Estimate::lot(
			MSRWA_Profile::exists( $profile ) ? $profile : MSRWA_Profile::FULL,
			(int) $request->get_param( 'recipes' ),
			(int) $request->get_param( 'images' )
		);
		$site_ceiling = (float) MSRWA_Settings::get()['per_recipe_budget_usd'];
		// One ceiling for every lot: the site's, set in the settings.
		$ceiling = $site_ceiling;
		$fits = MSRWA_Estimate::fits( (float) $estimate['per_recipe_usd'], $ceiling );
		// Money is an operator's concern: a writer learns whether the lot fits,
		// never what it costs or what the ceiling is.
		if ( ! MSRWA_Rights::may_see_money() ) {
			return rest_ensure_response( array( 'recipes' => (int) $estimate['recipes'], 'fits' => $fits ) );
		}
		$estimate['ceiling_usd'] = $ceiling;
		$estimate['fits'] = $fits;
		return rest_ensure_response( $estimate );
	}

	/** Splits the submission, describes the photographs, pairs them. */
	public static function create( WP_REST_Request $request ) {
		// A text, photographs, or both. Without text, each dish the photographs
		// show becomes a recipe, and the writer confirms it with the pairing.
		$recipes = MSRWA_Intake::recipes( (string) $request->get_param( 'recipes' ) );

		// Photographs come from the writer's computer, never from the media
		// library: a lot can only carry pictures its writer actually sent, and
		// an attachment id posted here is not read. They are kept with the lot,
		// out of the library, which holds only what articles show.
		$files = MSRWA_Intake::files( $request->get_file_params()['photos'] ?? array() );
		$refused = MSRWA_Intake::check( $files, MSRWA_Admin::photo_bytes() );
		if ( '' !== $refused ) { return new WP_Error( 'msrwa_bad_photo', $refused, array( 'status' => 400 ) ); }
		if ( ! $recipes && ! $files ) { return new WP_Error( 'msrwa_no_recipes', __( 'Collez au moins une recette ou ajoutez au moins une photographie.', 'ms-recipes-writer-ai' ), array( 'status' => 400 ) ); }

		// The per-recipe ceiling is the site's unless the person may set it. It
		// used to be worked out here and then not used, so anybody who could
		// submit a lot could name their own ceiling by posting one.
		// Language and ceiling are the site's settings, never the request's: a
		// lot cannot name a language nobody reviews in or a ceiling of its own.
		$budget = (float) MSRWA_Settings::get()['per_recipe_budget_usd'];
		// The Facebook template is the lot's to choose, among the site's; an
		// unknown key is not an error, the site's default serves.
		$overrides = array();
		$template = sanitize_key( (string) $request->get_param( 'facebook_template' ) );
		if ( '' !== $template && isset( MSRWA_Profile::facebook_templates()[ $template ] ) ) { $overrides['images']['facebook_template'] = $template; }
		// A type the settings closed to editors is refused here too, not only
		// left off the form: the form is a courtesy, this is the rule.
		$profile = sanitize_key( (string) $request->get_param( 'profile' ) );
		if ( '' === $profile ) { $profile = (string) array_key_first( MSRWA_Profile::offered() ); }
		if ( ! MSRWA_Profile::allowed( $profile ) ) {
			return new WP_Error( 'msrwa_profile_closed', __( 'Ce type de lot n’est pas ouvert aux rédacteurs sur ce site. Choisissez-en un autre.', 'ms-recipes-writer-ai' ), array( 'status' => 403 ) );
		}
		$id = MSRWA_Batch::create(
			$recipes, $files, $budget,
			$profile,
			sanitize_key( (string) MSRWA_Settings::get()['site_language'] ),
			$overrides
		);
		if ( is_wp_error( $id ) ) { return new WP_Error( $id->get_error_code(), $id->get_error_message(), array( 'status' => 400 ) ); }
		$created = MSRWA_Batch::get( (int) $id );
		return rest_ensure_response( array( 'id' => (int) $id, 'recipes' => (int) ( $created['recipes'] ?? count( $recipes ) ), 'images' => (int) ( $created['images'] ?? 0 ) ) );
	}

	/**
	 * A refusal the writer can act on, not a server failure. Without a status
	 * the REST API answered 500 to "a photograph waits for your decision".
	 */
	private static function refused( WP_Error $error ) {
		$data = (array) $error->get_error_data();
		if ( empty( $data['status'] ) ) { $error->add_data( array_merge( $data, array( 'status' => 409 ) ) ); }
		return $error;
	}

	private static function batch( $id ) {
		$batch = MSRWA_Batch::get( absint( $id ) );
		if ( ! $batch || ! MSRWA_Batch::may_see( $batch ) ) { return null; }
		return $batch;
	}

	/** One of a lot's photographs, to someone who may see the lot. */
	public static function photo( WP_REST_Request $request ) {
		$batch = self::batch( $request['id'] );
		if ( ! $batch || ! MSRWA_Sources::serve( (int) $batch['id'], (string) $request['name'], array_map( 'intval', array_column( MSRWA_Run::for_batch( (int) $batch['id'] ), 'id' ) ) ) ) { return new WP_Error( 'msrwa_not_found', __( 'Photographie introuvable.', 'ms-recipes-writer-ai' ), array( 'status' => 404 ) ); }
		exit;
	}

	public static function pairs( WP_REST_Request $request ) {
		$batch = self::batch( $request['id'] );
		if ( ! $batch ) { return new WP_Error( 'msrwa_not_found', __( 'Lot introuvable.', 'ms-recipes-writer-ai' ), array( 'status' => 404 ) ); }
		if ( 'ready' !== $batch['status'] ) { return new WP_Error( 'msrwa_locked', __( 'Ce lot est déjà lancé ; son appariement ne change plus.', 'ms-recipes-writer-ai' ), array( 'status' => 409 ) ); }
		MSRWA_Batch::repair( (int) $batch['id'], (array) $request->get_param( 'pairs' ) );
		return rest_ensure_response( array( 'saved' => true ) );
	}

	/** Sets, or clears, the hour a lot is sent at. */
	public static function schedule( WP_REST_Request $request ) {
		$batch = self::batch( $request['id'] );
		if ( ! $batch ) { return new WP_Error( 'msrwa_not_found', __( 'Lot introuvable.', 'ms-recipes-writer-ai' ), array( 'status' => 404 ) ); }
		$when = MSRWA_Schedule::when( (int) $batch['id'], sanitize_text_field( (string) $request->get_param( 'at' ) ) );
		if ( is_wp_error( $when ) ) { return self::refused( $when ); }
		return rest_ensure_response( array( 'at' => $when ? gmdate( 'c', $when ) : null ) );
	}

	public static function dispatch( WP_REST_Request $request ) {
		$batch = self::batch( $request['id'] );
		if ( ! $batch ) { return new WP_Error( 'msrwa_not_found', __( 'Lot introuvable.', 'ms-recipes-writer-ai' ), array( 'status' => 404 ) ); }
		$started = MSRWA_Batch::dispatch( (int) $batch['id'] );
		if ( is_wp_error( $started ) ) { return self::refused( $started ); }
		return rest_ensure_response( array( 'started' => (int) $started ) );
	}

	/** What the batch screen polls while its runs advance. */
	public static function runs( WP_REST_Request $request ) {
		$batch = self::batch( $request['id'] );
		if ( ! $batch ) { return new WP_Error( 'msrwa_not_found', __( 'Lot introuvable.', 'ms-recipes-writer-ai' ), array( 'status' => 404 ) ); }
		$out = array();
		$stalled = 0;
		foreach ( MSRWA_Run::for_batch( (int) $batch['id'] ) as $run ) {
			$stalled += MSRWA_Run::overdue( $run ) ? 1 : 0;
			$out[] = array(
				'id' => (int) $run['id'], 'label' => (string) $run['label'], 'status' => (string) $run['status'],
				'step' => (string) $run['step'], 'steps_done' => (int) $run['steps_done'], 'steps_total' => (int) $run['steps_total'],
				'cost_usd' => (float) $run['cost_usd'], 'seconds' => (float) $run['seconds'],
				'approved' => null === $run['approved'] ? null : (bool) $run['approved'],
				'draft_post_id' => (int) $run['draft_post_id'],
			);
			if ( ! MSRWA_Rights::may_see_money() ) {
				$last = count( $out ) - 1;
				// How far along is the writer's to know, and their screen shows it:
				// stripping it painted "undefined/undefined" on every refresh. The
				// step's engine name, the cost and the time stay off.
				unset( $out[ $last ]['step'], $out[ $last ]['cost_usd'], $out[ $last ]['seconds'] );
			}
		}
		return rest_ensure_response( array( 'status' => (string) $batch['status'], 'runs' => $out, 'stalled' => $stalled ) );
	}

	/** What the queue is doing, for the screen that watches it. */
	public static function queue() {
		$state = MSRWA_Queue::state();
		$state['stalled'] = MSRWA_Queue::stalled();
		// Ceilings and spend are money: a writer may reach this route and is told
		// only whether the site can still spend, never how much.
		$state['budget'] = MSRWA_Rights::may_see_money()
			? MSRWA_Budget::state()
			: array_map( static function ( $budget ) { return array( 'exceeded' => (bool) $budget['exceeded'] ); }, MSRWA_Budget::state() );
		return rest_ensure_response( $state );
	}

	/** Holding the queue, and letting it go again. */
	public static function queue_control( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'do' ) );
		if ( 'hold' === $action ) { MSRWA_Queue::hold(); return rest_ensure_response( array( 'held' => true ) ); }
		if ( 'release' === $action ) { return rest_ensure_response( array( 'held' => false, 'rearmed' => MSRWA_Queue::release() ) ); }
		return new WP_Error( 'msrwa_unknown_action', __( 'Action inconnue.', 'ms-recipes-writer-ai' ), array( 'status' => 400 ) );
	}

	/** Whether each stored key opens its provider. Costs nothing: it lists models. */
	public static function check_keys() {
		return rest_ensure_response( MSRWA_Keys::check() );
	}

	/**
	 * Asks each provider what it serves. Free, and certain.
	 *
	 * The same listing the key check reads, asked for on its own so that
	 * refreshing the catalogue is not something an operator has to know is
	 * hidden inside a button about keys.
	 */
	public static function fetch_models() {
		$out = array();
		foreach ( MSRWA_Keys::check() as $provider => $verdict ) {
			$out[ $provider ] = array(
				'label' => $verdict['label'],
				'state' => $verdict['state'],
				'message' => $verdict['message'],
				'models' => (int) ( $verdict['models'] ?? 0 ),
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Reads the providers' published pricing pages. Costs a little, can be wrong.
	 *
	 * `models` names `provider:model_id` keys to check even when they already
	 * carry a rate, which is how a shipped rate is confirmed against its page.
	 */
	public static function fetch_prices( $request = null ) {
		$only = $request instanceof WP_REST_Request ? array_values( array_filter( array_map( 'sanitize_text_field', (array) $request->get_param( 'models' ) ) ) ) : array();
		return rest_ensure_response( MSRWA_Prices::lookup( $only ) );
	}

	/**
	 * One bounded pass of the retention policy, asked for rather than waited on.
	 *
	 * Bounded like the scheduled one: a site with a year of backlog clears it
	 * over several presses, not in one request the host kills.
	 */
	public static function prune() {
		return rest_ensure_response( MSRWA_Retention::sweep() );
	}

	/**
	 * The same decision applied to several recipes.
	 *
	 * Each one is checked on its own: a selection that includes a recipe this
	 * person may not touch, or one the action does not apply to, does that much
	 * and reports the rest rather than refusing everything or doing it anyway.
	 */
	public static function bulk( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'do' ) );
		if ( ! in_array( $action, array( 'cancel', 'retry', 'delete', 'prioritise' ), true ) ) {
			return new WP_Error( 'msrwa_unknown_action', __( 'Action inconnue.', 'ms-recipes-writer-ai' ), array( 'status' => 400 ) );
		}
		// Order across the queue is a decision over other people's work, so it
		// is not a writer's to make on their own recipes.
		if ( 'prioritise' === $action && ! MSRWA_Rights::may_manage() ) {
			return new WP_Error( 'msrwa_forbidden', __( 'L’ordre de la file est réservé aux administrateurs.', 'ms-recipes-writer-ai' ), array( 'status' => 403 ) );
		}
		if ( 'delete' === $action && ! MSRWA_Rights::may_delete() ) {
			return new WP_Error( 'msrwa_forbidden', __( 'La suppression est réservée aux administrateurs.', 'ms-recipes-writer-ai' ), array( 'status' => 403 ) );
		}

		$ids = array_slice( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'runs' ) ) ) ), 0, 100 );
		$priority = $request->has_param( 'priority' ) ? (int) $request->get_param( 'priority' ) : null;
		$done = 0;
		$skipped = array();

		foreach ( $ids as $id ) {
			$run = MSRWA_Run::get( $id );
			if ( ! $run || ! MSRWA_Run::may_see( $run ) ) { $skipped[] = $id; continue; }

			if ( 'prioritise' === $action ) {
				MSRWA_Queue::prioritise( $id, null === $priority ? 5 : $priority );
				$done++;
				continue;
			}
			if ( 'cancel' === $action ) {
				if ( MSRWA_Run::cancel( $id ) ) { $done++; } else { $skipped[] = $id; }
				continue;
			}
			if ( 'retry' === $action ) {
				if ( MSRWA_Run::may_retry( $run ) && MSRWA_Run::retry( $id ) ) { $done++; } else { $skipped[] = $id; }
				continue;
			}
			// Deleting destroys the evidence of what was spent, so a recipe
			// still moving is never deleted out from under its own worker.
			if ( in_array( (string) $run['status'], array( 'queued', 'running' ), true ) ) { $skipped[] = $id; continue; }
			if ( MSRWA_Run::delete( $id ) ) { $done++; } else { $skipped[] = $id; }
		}

		return rest_ensure_response( array( 'done' => $done, 'skipped' => array_values( $skipped ) ) );
	}

	/** Runs an overdue recipe's next wave from the page that watches it, when cron did not. */
	public static function nudge( WP_REST_Request $request ) {
		$batch = self::batch( $request['id'] );
		if ( ! $batch ) { return new WP_Error( 'msrwa_not_found', __( 'Lot introuvable.', 'ms-recipes-writer-ai' ), array( 'status' => 404 ) ); }
		return rest_ensure_response( array( 'moved' => MSRWA_Run::nudge( (int) $batch['id'] ) ) );
	}

	/** Picks a stopped run back up, without paying again for what succeeded. */
	public static function retry( WP_REST_Request $request ) {
		$run = MSRWA_Run::get( absint( $request['id'] ) );
		if ( ! $run || ! MSRWA_Run::may_see( $run ) ) { return new WP_Error( 'msrwa_not_found', __( 'Recette introuvable.', 'ms-recipes-writer-ai' ), array( 'status' => 404 ) ); }
		if ( ! MSRWA_Run::may_retry( $run ) ) { return new WP_Error( 'msrwa_not_stopped', __( 'Cette recette n’est pas arrêtée.', 'ms-recipes-writer-ai' ), array( 'status' => 409 ) ); }
		return rest_ensure_response( array( 'retried' => MSRWA_Run::retry( (int) $run['id'] ) ) );
	}

	/** Draws a refused image of a finished recipe again, from the judge's findings. */
	public static function redraw( WP_REST_Request $request ) {
		$run = MSRWA_Run::get( absint( $request['id'] ) );
		if ( ! $run || ! MSRWA_Run::may_see( $run ) ) { return new WP_Error( 'msrwa_not_found', __( 'Recette introuvable.', 'ms-recipes-writer-ai' ), array( 'status' => 404 ) ); }
		$refused = MSRWA_Run::redraw( (int) $run['id'], sanitize_key( (string) $request['kind'] ) );
		if ( '' !== $refused ) { return new WP_Error( 'msrwa_not_redrawn', $refused, array( 'status' => 409 ) ); }
		return rest_ensure_response( array( 'redrawn' => true ) );
	}

	public static function cancel( WP_REST_Request $request ) {
		$run = MSRWA_Run::get( absint( $request['id'] ) );
		if ( ! $run || ! MSRWA_Run::may_see( $run ) ) { return new WP_Error( 'msrwa_not_found', __( 'Run introuvable.', 'ms-recipes-writer-ai' ), array( 'status' => 404 ) ); }
		return rest_ensure_response( array( 'cancelled' => MSRWA_Run::cancel( (int) $run['id'] ) ) );
	}

	public static function remove( WP_REST_Request $request ) {
		$batch = self::batch( $request['id'] );
		if ( ! $batch ) { return new WP_Error( 'msrwa_not_found', __( 'Lot introuvable.', 'ms-recipes-writer-ai' ), array( 'status' => 404 ) ); }
		if ( 'running' === $batch['status'] ) { return new WP_Error( 'msrwa_running', __( 'Arrêtez les recettes avant de supprimer le lot.', 'ms-recipes-writer-ai' ), array( 'status' => 409 ) ); }
		return rest_ensure_response( array( 'deleted' => MSRWA_Batch::delete( (int) $batch['id'] ) ) );
	}
}
