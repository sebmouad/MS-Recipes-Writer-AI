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
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', __( 'Accès refusé.', 'ms-recipes-writer-ai' ), array( 'status' => 403 ) ); }
		$raw = $request->get_param( 'config' );
		if ( ! is_array( $raw ) ) { return new WP_Error( 'invalid_config', __( 'Configuration attendue.', 'ms-recipes-writer-ai' ), array( 'status' => 400 ) ); }
		$parsed = MSRWA_Engine_Settings::parse( $raw );
		if ( $parsed['invalid'] ) { return new WP_Error( 'invalid_json', sprintf(
				/* translators: %s is a comma-separated list of group names. */
				__( 'JSON invalide : %s.', 'ms-recipes-writer-ai' ),
				implode( ', ', $parsed['invalid'] )
			), array( 'status' => 400 ) ); }
		try {
			$config = MSRWA_Engine_Config::create( $parsed['config'] );
			$routes = array();
			foreach ( $config->steps() as $name => $step ) {
				// A step with no capability calls no model and has no prompt file
				// — 'corrections' applies the fact-check verbatim, in code. Asking
				// for its route or its prompt is asking a question with no answer.
				if ( 'none' === ( $step['capability'] ?? '' ) ) { continue; }
				$route = $config->model_for( $name );
				$prompt = $config->prompt( $name );
				$routes[ $name ] = array( 'route' => $route, 'provider_known' => (bool) $config->get( 'providers.' . $route['provider'] ), 'price_known' => null !== $config->price( $route['provider'], $route['model'], array() ), 'prompt_source' => $prompt['source'], 'prompt_bytes' => strlen( $prompt['text'] ), 'max_output' => $config->max_output( $name ), 'attempts' => $config->attempts( $name ) );
			}
			return rest_ensure_response( array( 'notice' => __( 'Simulation locale : aucune sauvegarde, aucun appel API. Vérifiez les routes et les valeurs effectives ci-dessous.', 'ms-recipes-writer-ai' ), 'routes' => $routes, 'effective' => $config->to_array() ) );
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
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		if ( ! $run || ! MSRWA_Run::may_see( $run ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$state = MSRWA_Run::state( $id );
		$state['artifacts']['brief'] = (array) json_decode( (string) $run['brief_json'], true );
		$state['ok'] = 'done' === $run['status'] && ! empty( $state['ok'] );
		foreach ( array( 'featured', 'facebook' ) as $kind ) {
			$state['artifacts'][ $kind ] = self::safe_image( (array) ( $state['artifacts'][ $kind ] ?? array() ), $id );
		}
		require_once MSRWA_DIR . 'tools/report.php';
		$html = report_render( $state );
		$html = str_replace( 'Test laboratoire · une exécution du moteur', 'Job WordPress #' . $id . ' · ' . esc_html( $run['status'] ), $html );
		$html = str_replace( 'Article final prêt à publier', 'Article final — validation éditoriale requise', $html );
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

	/**
	 * Where every step would actually go, resolved locally.
	 *
	 * Answers the question that otherwise costs money to answer: is there a key
	 * for this route, and is its model priced? A key being present does not
	 * prove it works — only that a call would be attempted.
	 */
	public static function diagnostics() {
		$config = MSRWA_Engine_Config::create( MSRWA_Engine_Settings::stored(), array( 'settings' => MSRWA_Settings::engine_settings() ) );
		$rows = array();
		foreach ( $config->steps() as $step => $definition ) {
			$route = $config->model_for( $step );
			$provider = $config->provider( $route['provider'], $route['model'] );
			$rows[] = array( 'etape' => $step, 'capacite' => $definition['capability'], 'dependances' => implode( ', ', (array) $definition['needs'] ), 'modele' => $route['provider'] . ':' . $route['model'], 'cle_presente' => empty( $provider['has_key'] ) ? 'NON' : 'oui', 'tarif_connu' => null === $config->price( $route['provider'], $route['model'], array() ) ? 'NON' : 'oui', 'tokens_max' => $config->max_output( $step ), 'tentatives' => $config->attempts( $step ) );
		}
		self::table( __( 'Où irait chaque étape', 'ms-recipes-writer-ai' ), $rows );
		echo '<p class="ms-muted">' . esc_html__( 'Contrôle local uniquement, sans aucun appel facturé : la présence d’une clé ne prouve pas qu’elle est valide, seulement qu’un appel serait tenté.', 'ms-recipes-writer-ai' ) . '</p>';
	}

	private static function table( $title, array $rows ) {
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $rows ) { echo '<p class="ms-muted" style="padding:0 20px 18px">' . esc_html__( 'Aucune donnée.', 'ms-recipes-writer-ai' ) . '</p></section>'; return; }
		echo MSRWA_UI::scroll( $title ) . '<table class="ms-table"><thead><tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scroll() escapes its own.
		foreach ( array_keys( $rows[0] ) as $key ) { echo '<th scope="col">' . esc_html( str_replace( '_', ' ', $key ) ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr>'; foreach ( $row as $value ) { echo '<td>' . esc_html( null === $value ? '—' : $value ) . '</td>'; } echo '</tr>'; }
		echo '</tbody></table></div></section>';
	}
}
