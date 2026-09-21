<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only operational views; no provider calls and no engine modifications. */
final class MSRWA_Operations {
	/** Resolve unsaved settings without saving, calling providers or spending. */
	public static function preview( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', 'Accès refusé.', array( 'status' => 403 ) ); }
		$raw = $request->get_param( 'config' );
		if ( ! is_array( $raw ) ) { return new WP_Error( 'invalid_config', 'Configuration attendue.', array( 'status' => 400 ) ); }
		$parsed = MSRWA_Engine_Settings::parse( $raw );
		if ( $parsed['invalid'] ) { return new WP_Error( 'invalid_json', 'JSON invalide : ' . implode( ', ', $parsed['invalid'] ), array( 'status' => 400 ) ); }
		try {
			$config = MSRWA_Engine_Config::create( $parsed['config'] );
			$routes = array();
			foreach ( $config->steps() as $name => $step ) {
				$route = $config->model_for( $name );
				$prompt = $config->prompt( $name );
				$routes[ $name ] = array( 'route' => $route, 'provider_known' => (bool) $config->get( 'providers.' . $route['provider'] ), 'price_known' => null !== $config->price( $route['provider'], $route['model'], array() ), 'prompt_source' => $prompt['source'], 'prompt_bytes' => strlen( $prompt['text'] ), 'max_output' => $config->max_output( $name ), 'attempts' => $config->attempts( $name ) );
			}
			return rest_ensure_response( array( 'notice' => 'Simulation locale : aucune sauvegarde, aucun appel API. Vérifiez les routes et les valeurs effectives ci-dessous.', 'routes' => $routes, 'effective' => $config->to_array() ) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'invalid_config', 'Structure incompatible avec le moteur. Vérifiez les types et les champs des groupes JSON.', array( 'status' => 400 ) );
		}
	}

	/** The lab renderer is shared verbatim; only its input is adapted for WP. */
	public static function report() {
		$id = absint( $_GET['run_id'] ?? 0 );
		check_admin_referer( 'msrwa_job_report_' . $id );
		$run = MSRWA_Run::get( $id );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Accès refusé.' ); }
		if ( ! $run || ! MSRWA_Run::may_see( $run ) || ( ! current_user_can( 'msrwa_create' ) && ! current_user_can( 'manage_options' ) ) ) { wp_die( 'Accès refusé.' ); }
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

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Accès refusé.' ); }
		global $wpdb;
		$t = MSRWA_DB::tables();
		$days = max( 1, min( 365, absint( $_GET['days'] ?? 30 ) ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$where = $wpdb->prepare( 'r.created_at >= %s', $since );
		echo '<div class="wrap msrwa-wrap"><h1>Statistiques</h1>';
		MSRWA_Admin::navigation();
		$summary = (array) $wpdb->get_row( "SELECT COUNT(*) AS jobs,SUM(r.status='running') AS active,SUM(r.status='failed') AS failed,SUM(r.cost_usd) AS cost,AVG(r.seconds) AS seconds FROM {$t['runs']} r WHERE $where", ARRAY_A );
		echo '<div class="msrwa-summary-grid">';
		foreach ( array( 'Jobs' => (int) ( $summary['jobs'] ?? 0 ), 'En cours' => (int) ( $summary['active'] ?? 0 ), 'Échecs' => (int) ( $summary['failed'] ?? 0 ), 'Coût estimé' => sprintf( '%.4f $', $summary['cost'] ?? 0 ), 'Temps moyen cumulé' => sprintf( '%.1f s', $summary['seconds'] ?? 0 ) ) as $label => $value ) {
			echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
		}
		echo '</div>';
		echo '<details class="msrwa-metric-help"><summary>À propos des indicateurs</summary><p>Période basée sur le début des jobs, en UTC. Coûts estimés hors appariement. Les appels sans tarif restent signalés séparément.</p></details>';
		echo '<form method="get"><input type="hidden" name="page" value="msrwa-operations"><label>Derniers jours <input type="number" name="days" min="1" max="365" value="' . esc_attr( $days ) . '"></label> <button class="button">Actualiser</button></form>';
		self::table( 'Générations et verdicts', (array) $wpdb->get_results( "SELECT r.status, COUNT(*) AS generations, SUM(r.approved = 1) AS approuvees, SUM(r.draft_post_id > 0) AS brouillons, SUM(r.cost_usd) AS cout_usd, AVG(r.seconds) AS secondes_moyennes FROM {$t['runs']} r WHERE $where GROUP BY r.status", ARRAY_A ) );
		self::table( 'Étapes : qualité, tentatives et latence', (array) $wpdb->get_results( "SELECT s.step, s.status, COUNT(*) AS executions, SUM(s.attempts) AS tentatives, SUM(s.passed) AS controles_reussis, SUM(s.total) AS controles_total, SUM(s.cost_usd) AS cout_usd, AVG(s.seconds) AS secondes_moyennes FROM {$t['steps']} s JOIN {$t['runs']} r ON r.id=s.run_id WHERE $where GROUP BY s.step,s.status", ARRAY_A ) );
		self::table( 'Appels : fournisseurs, modèles et cache', (array) $wpdb->get_results( "SELECT c.provider,c.model,c.tier,c.status,COUNT(*) AS appels,SUM(c.input_tokens) AS tokens_entree,SUM(c.output_tokens) AS tokens_sortie,SUM(c.cached_tokens) AS tokens_cache,SUM(c.cost_usd) AS cout_usd,SUM(c.cost_usd IS NULL) AS non_chiffres,AVG(c.seconds) AS secondes_moyennes FROM {$t['calls']} c JOIN {$t['runs']} r ON r.id=c.run_id WHERE $where GROUP BY c.provider,c.model,c.tier,c.status", ARRAY_A ) );
		self::table( 'Événements observés', (array) $wpdb->get_results( "SELECT e.kind,e.step,COUNT(*) AS evenements FROM {$t['events']} e JOIN {$t['runs']} r ON r.id=e.run_id WHERE $where GROUP BY e.kind,e.step", ARRAY_A ) );
		self::table( 'Livrables enregistrés', (array) $wpdb->get_results( "SELECT a.artifact_key,COUNT(*) AS livrables,SUM(a.bytes) AS octets FROM {$t['artifacts']} a JOIN {$t['runs']} r ON r.id=a.run_id WHERE $where GROUP BY a.artifact_key", ARRAY_A ) );
		$recent = (array) $wpdb->get_results( "SELECT r.id,r.label,r.status,r.step,r.error_message,r.updated_at FROM {$t['runs']} r WHERE $where ORDER BY r.id DESC LIMIT 50", ARRAY_A );
		self::table( '50 dernières générations — suivi et erreurs', $recent );
		echo '<p>';
		foreach ( $recent as $run ) { echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=msrwa-run&run_id=' . absint( $run['id'] ) ) ) . '">Run #' . absint( $run['id'] ) . '</a> '; }
		echo '</p><section class="msrwa-card"><h2>Santé du planificateur</h2>';
		$next = wp_next_scheduled( 'msrwa_cleanup' );
		self::table( 'Cron (UTC)', array( array( 'prochain_controle' => $next ? gmdate( 'Y-m-d H:i:s', $next ) : 'ABSENT', 'dernier_controle' => get_option( 'msrwa_watchdog_at', 'Jamais' ), 'declenchement' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Cron serveur requis' : 'Visites WordPress' ) ) );
		echo '<p>WP-Cron dépend des visites. Pour un suivi régulier, configurer un cron serveur. Un contrôle ne génère aucun contenu ; il réarme uniquement les travaux éligibles.</p></section>';
		self::diagnostics();
		echo '</div>';
	}

	public static function diagnostics() {
		$config = MSRWA_Engine_Config::create( MSRWA_Engine_Settings::stored(), array( 'settings' => array( 'keys' => MSRWA_Settings::engine_keys() ) ) );
		$rows = array();
		foreach ( $config->steps() as $step => $definition ) {
			$route = $config->model_for( $step );
			$provider = $config->provider( $route['provider'], $route['model'] );
			$rows[] = array( 'etape' => $step, 'capacite' => $definition['capability'], 'dependances' => implode( ', ', (array) $definition['needs'] ), 'modele' => $route['provider'] . ':' . $route['model'], 'cle_presente' => empty( $provider['has_key'] ) ? 'NON' : 'oui', 'tarif_connu' => null === $config->price( $route['provider'], $route['model'], array() ) ? 'NON' : 'oui', 'tokens_max' => $config->max_output( $step ), 'tentatives' => $config->attempts( $step ) );
		}
		self::table( 'Diagnostic local des routes effectives', $rows );
		echo '<p>Contrôle local uniquement : la présence d’une clé ne prouve pas sa validité distante. Aucun appel facturé. Les valeurs ci-dessous sont résolues et bornées par le moteur.</p>';
		foreach ( $config->to_array() as $group => $value ) {
			echo '<details class="msrwa-card"><summary>' . esc_html( $group ) . '</summary><pre>' . esc_html( wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
		}
	}

	public static function cron() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Accès refusé.' ); }
		global $wpdb;
		$t = MSRWA_DB::tables();
		echo '<div class="wrap msrwa-wrap"><h1>Planificateur</h1>'; MSRWA_Admin::navigation();
		$next = wp_next_scheduled( 'msrwa_cleanup' );
		self::table( 'Diagnostic du planificateur (UTC)', array( array( 'prochain_controle' => $next ? gmdate( 'Y-m-d H:i:s', $next ) : 'Absent : recharger une page du site', 'dernier_controle' => get_option( 'msrwa_watchdog_at', 'Jamais exécuté' ), 'mode' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Cron serveur requis' : 'Déclenché par les visites' ) ) );
		self::table( 'File active', (array) $wpdb->get_results( "SELECT status,COUNT(*) AS jobs,MIN(updated_at) AS plus_ancien FROM {$t['runs']} WHERE status IN ('queued','running') GROUP BY status", ARRAY_A ) );
		self::table( 'Baux expirés — 50 derniers', (array) $wpdb->get_results( "SELECT id,label,step,lock_until,updated_at FROM {$t['runs']} WHERE status='running' AND lock_until < UTC_TIMESTAMP() ORDER BY lock_until ASC LIMIT 50", ARRAY_A ) );
		echo '<section class="msrwa-card"><h2>Comment suivre les traitements</h2><p>Le contrôle automatique passe toutes les cinq minutes quand WordPress exécute son cron. Il réarme les travaux en attente et récupère les baux expirés. Un onglet ouvert n’est pas nécessaire.</p><p>Vérifiez que « dernier contrôle » avance ; sur un site peu visité, configurez un déclenchement serveur.</p><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=msrwa-cron' ) ) . '">Actualiser le diagnostic</a></p></section></div>';
	}

	private static function table( $title, array $rows ) {
		echo '<section class="msrwa-card"><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $rows ) { echo '<p>Aucune donnée pour cette période.</p></section>'; return; }
		echo '<div class="msrwa-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr( $title ) . '"><table class="widefat striped"><thead><tr>';
		foreach ( array_keys( $rows[0] ) as $key ) { echo '<th scope="col">' . esc_html( str_replace( '_', ' ', $key ) ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr>'; foreach ( $row as $value ) { echo '<td>' . esc_html( null === $value ? '—' : $value ) . '</td>'; } echo '</tr>'; }
		echo '</tbody></table></div></section>';
	}
}
