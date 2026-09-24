<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only operational views: no provider call, no engine modification, no
 * spend.
 *
 * Two of these matter more than they look. `preview()` resolves a configuration
 * without saving it or calling anybody, so an administrator can find out that a
 * route has no key before a lot does. `report()` renders the full run report
 * under a content-security policy with its images confined to that run's own
 * directory — a saved path must never turn a report into a way to read any file
 * on the server.
 */
final class MSRWA_Operations {
	/** Resolve unsaved settings without saving, calling providers or spending. */
	public static function preview( $request ) {
		if ( ! MSRWA_Rights::may_manage() ) { return new WP_Error( 'forbidden', __( 'Accès refusé.', 'ms-recipes-writer-ai' ), array( 'status' => 403 ) ); }
		$raw = $request->get_param( 'config' );
		if ( ! is_array( $raw ) ) { return new WP_Error( 'invalid_config', __( 'Configuration attendue.', 'ms-recipes-writer-ai' ), array( 'status' => 400 ) ); }
		$parsed = MSRWA_Engine_Settings::parse( $raw );
		if ( $parsed['invalid'] ) { return new WP_Error( 'invalid_json', sprintf(
				/* translators: %s is a comma-separated list of group names. */
				__( 'JSON invalide : %s.', 'ms-recipes-writer-ai' ),
				implode( ', ', $parsed['invalid'] )
			), array( 'status' => 400 ) ); }
		try {
			// What is on screen, over the catalogue, as a save would resolve it.
			// Resolved against the engine alone, every model the catalogue
			// priced read as unpriced and the table said nothing about money.
			$config = MSRWA_Engine_Config::create( MSRWA_Engine_Settings::merge( array_filter( MSRWA_Catalog::for_engine() ), $parsed['config'] ), array( 'settings' => MSRWA_Settings::engine_settings() ) );
			$estimate = MSRWA_Estimate::recipe_on( $config, MSRWA_Profile::FULL );
			$routes = array();
			foreach ( $config->steps() as $name => $step ) {
				// A step with no capability calls no model and has no prompt file
				// — 'corrections' applies the fact-check verbatim, in code. Asking
				// for its route or its prompt is asking a question with no answer.
				if ( 'none' === ( $step['capability'] ?? '' ) ) { continue; }
				$prompt = $config->prompt( $name );
				$route = $config->model_for( MSRWA_Estimate::route_for( $name, (string) ( $step['capability'] ?? '' ), $config ) );
				$wire = $config->provider( $route['provider'], $route['model'], $name );
				$routes[ $name ] = array(
					'route' => $route, 'provider_known' => (bool) $wire, 'has_key' => ! empty( $wire['has_key'] ),
					'thinking' => 'image_generation' === ( $step['capability'] ?? '' ) ? '' : (string) ( $wire['thinking_level'] ?? '' ),
					'price_known' => null !== $config->price( $route['provider'], $route['model'], array() ),
					'cost_usd' => $estimate['steps'][ $name ]['cost_usd'] ?? null,
					'prompt_source' => $prompt['source'], 'prompt_bytes' => strlen( $prompt['text'] ), 'max_output' => $config->max_output( $name ), 'attempts' => $config->attempts( $name ),
				);
			}
			return rest_ensure_response( array(
				'notice' => __( 'Simulation locale : aucune sauvegarde, aucun appel API. Vérifiez les routes et les valeurs effectives ci-dessous.', 'ms-recipes-writer-ai' ),
				'routes' => $routes,
				'cost_usd' => $estimate['cost_usd'],
				'max_usd' => $estimate['max_usd'],
				'unpriced' => $estimate['unpriced'],
				'effective' => $config->to_array(),
			) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'invalid_config', __( 'Structure incompatible avec le moteur. Vérifiez les types et les champs des groupes JSON.', 'ms-recipes-writer-ai' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * Whether this installation is actually in the state the plugin expects.
	 *
	 * Written for the real test layer, which drives a live site over REST and
	 * otherwise has no way to look at a table. It reads nothing sensitive: the
	 * shape of the schema, which capabilities exist, whether cron is armed and
	 * whether uploads can be written. No key, no content, no spend.
	 */
	public static function health() {
		if ( ! MSRWA_Rights::may_manage() ) { return new WP_Error( 'forbidden', __( 'Accès refusé.', 'ms-recipes-writer-ai' ), array( 'status' => 403 ) ); }

		$tables = array();
		foreach ( MSRWA_DB::tables() as $name => $table ) { $tables[ $name ] = MSRWA_DB::table_exists( $table ); }

		$columns = array();
		$t = MSRWA_DB::tables();
		foreach ( array( 'batches' => array( 'profile', 'language', 'dispatch_at' ), 'steps' => array( 'checks_failed' ) ) as $table => $wanted ) {
			foreach ( $wanted as $column ) { $columns[ $table . '.' . $column ] = MSRWA_DB::column_exists( $t[ $table ], $column ); }
		}

		$roles = array();
		foreach ( MSRWA_Rights::roles() as $role_name => $capabilities ) {
			$role = get_role( $role_name );
			$roles[ $role_name ] = $role ? array_values( array_filter( $capabilities, static function ( $capability ) use ( $role ) { return $role->has_cap( $capability ); } ) ) : array();
		}

		$uploads = wp_upload_dir();
		$next = wp_next_scheduled( 'msrwa_cleanup' );

		return rest_ensure_response( array(
			'version' => MSRWA_VERSION,
			'schema' => (int) get_option( 'msrwa_schema', 0 ),
			'expected_schema' => MSRWA_DB::SCHEMA,
			'tables' => $tables,
			'columns' => $columns,
			'roles' => $roles,
			'providers' => MSRWA_Settings::configured_providers(),
			'cron' => array(
				'next' => $next ? gmdate( 'c', $next ) : null,
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			),
			'uploads_writable' => (bool) wp_is_writable( $uploads['basedir'] ),
			// Tables from the plugin that stood here before this one. Reported
			// so an operator can decide to drop them; never dropped from here.
			'dormant_tables' => MSRWA_DB::dormant(),
		) );
	}

	/** The lab renderer is shared verbatim; only its input is adapted for WP. */
	public static function report() {
		$id = absint( $_GET['run_id'] ?? 0 );
		check_admin_referer( 'msrwa_job_report_' . $id );
		$run = MSRWA_Run::get( $id );
		// Administrators only, and deliberately: the report names models,
		// tokens and amounts, none of which a writer may be shown. The scope
		// check stands beside it because an administrator without
		// `msrwa_view_all` still only sees their own lots.
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		if ( ! $run || ! MSRWA_Run::may_see( $run ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$state = self::report_state( $run );
		require_once MSRWA_DIR . 'tools/report.php';
		$html = report_render( $state );
		if ( ! in_array( $run['status'], array( 'done', 'failed', 'cancelled' ), true ) ) {
			$html = str_replace( 'Avec erreurs', 'En cours — rapport partiel', $html );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( "Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'" );
		header( 'X-Content-Type-Options: nosniff' );
		if ( ! empty( $_GET['download'] ) ) { header( 'Content-Disposition: attachment; filename="recipe-job-' . $id . '.html"' ); }
		echo $html;
		exit;
	}

	/**
	 * Everything the report shows about one job: the engine's state, its
	 * history, the photographs it was written from, and the steps its profile
	 * planned, so a step never planned is not shown as missing.
	 */
	public static function report_state( array $run ) {
		$id = (int) $run['id'];
		$state = MSRWA_Run::state( $id );
		$state['artifacts']['brief'] = (array) json_decode( (string) $run['brief_json'], true );
		$state['ok'] = 'done' === $run['status'] && ! empty( $state['ok'] );
		$state['eyebrow'] = sprintf( 'Tâche #%d · %s', $id, (string) $run['status'] );
		$state['history'] = MSRWA_History::for_run( $id );

		// The working copies of the article and the recipe are released once
		// WordPress holds them; the history kept what each step answered.
		$produces = array();
		foreach ( MSRWA_Engine_Steps::all() as $name => $step ) { $produces[ $name ] = (string) $step['produces']; }
		foreach ( $state['history'] as $row ) {
			if ( 'step' !== $row['stage'] || empty( $row['content']['answer'] ) ) { continue; }
			$key = $produces[ (string) $row['step'] ] ?? '';
			if ( '' !== $key && empty( $state['artifacts'][ $key ] ) ) { $state['artifacts'][ $key ] = $row['content']['answer']; }
		}
		foreach ( array( 'featured', 'facebook' ) as $kind ) {
			$state['artifacts'][ $kind ] = self::safe_image( (array) ( $state['artifacts'][ $kind ] ?? array() ), $id );
			// Once the draft holds an image, its working copy is deleted; the
			// report shows the one in the draft's media, which is the one a
			// reader gets. Only the attachment this job's draft records.
			$attachment = (int) $run['draft_post_id'] ? (int) get_post_meta( (int) $run['draft_post_id'], MSRWA_Draft::generated_key( $kind ), true ) : 0;
			if ( '' === (string) $state['artifacts'][ $kind ]['path'] && $attachment ) {
				$file = (string) get_attached_file( $attachment );
				$info = '' !== $file && is_readable( $file ) ? @getimagesize( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( $info && in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) && filesize( $file ) <= 20 * 1024 * 1024 ) {
					$state['artifacts'][ $kind ]['path'] = $file;
					$state['artifacts'][ $kind ]['mime'] = $info['mime'];
				}
			}
		}

		$batch = MSRWA_Batch::get( (int) $run['batch_id'] );
		$state['planned'] = MSRWA_Profile::steps( $batch ? $batch['profile'] : MSRWA_Profile::FULL, (array) ( MSRWA_Batch::config_for( (int) $run['batch_id'] )['steps'] ?? array() ) );
		$state['photos'] = self::embedded( MSRWA_Sources::run_dir( $id ), glob( MSRWA_Sources::run_dir( $id ) . '/*' ) );
		$found = array();
		foreach ( $state['history'] as $row ) {
			if ( 'web_reference' !== $row['stage'] ) { continue; }
			$file = basename( (string) ( $row['content']['file'] ?? '' ) );
			$data = self::embedded( MSRWA_Sources::run_dir( $id ) . '/references', array( MSRWA_Sources::run_dir( $id ) . '/references/' . $file ) );
			if ( isset( $data[ $file ] ) ) { $found[ (string) $row['content']['url'] ] = $data[ $file ]; }
		}
		$state['found'] = $found;
		return $state;
	}

	/**
	 * Stored photographs as small data URIs, keyed by name: the report is one
	 * self-contained page whose policy allows images from data: alone, and a
	 * photograph shown at every stage it went through was embedded whole four
	 * times — 6.9 MB for one recipe. Each is shrunk to 480 px once. Only
	 * stored names inside the given folder are read.
	 */
	private static function embedded( $dir, $files ) {
		$out = array();
		foreach ( (array) $files as $file ) {
			$path = MSRWA_Sources::path( $dir, basename( (string) $file ) );
			if ( '' === $path ) { continue; }
			// Decoding costs about four bytes a pixel: a 40-megapixel photograph
			// would take the report past a typical memory limit. It is shown by
			// its name alone rather than bring the page down.
			$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $size || (int) $size[0] * (int) $size[1] > 25000000 ) { continue; }
			$image = function_exists( 'imagecreatefromstring' ) ? @imagecreatefromstring( (string) file_get_contents( $path ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $image ) {
				$small = imagescale( $image, min( 480, imagesx( $image ) ) );
				ob_start();
				imagejpeg( $small ? $small : $image, null, 70 );
				$out[ basename( $path ) ] = 'data:image/jpeg;base64,' . base64_encode( (string) ob_get_clean() );
				imagedestroy( $image );
				continue;
			}
			// No GD: the file itself, when it is small enough to embed.
			$read = MSRWA_Sources::read( $dir, basename( $path ), 1024 * 1024 );
			if ( isset( $read['data'] ) ) { $out[ basename( $path ) ] = 'data:' . $read['mime'] . ';base64,' . $read['data']; }
		}
		return $out;
	}

	/** Saved paths must never turn the report into an arbitrary-file reader. */
	public static function safe_image( array $image, $id ) {
		$uploads = wp_upload_dir();
		$root = realpath( $uploads['basedir'] . '/msrwa/' . absint( $id ) );
		$path = empty( $image['path'] ) ? false : realpath( $image['path'] );
		$image['path'] = '';
		if ( ! $root || ! $path || 0 !== strpos( $path, $root . DIRECTORY_SEPARATOR ) || ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > 20 * 1024 * 1024 ) { return $image; }
		$info = @getimagesize( $path );
		if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { return $image; }
		$image['path'] = $path;
		$image['mime'] = $info['mime'];
		return $image;
	}
}
