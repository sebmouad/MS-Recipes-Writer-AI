<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The screens. One submission, its pairing, its runs, and what they cost.
 */
final class MSRWA_Admin {

	public static function hooks() {
		add_action( 'admin_post_msrwa_job_report', array( 'MSRWA_Operations', 'report' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_msrwa_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_msrwa_save_engine', array( __CLASS__, 'save_engine' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_menu_page( 'MS Recipes Writer', 'MS Recipes Writer', 'msrwa_create', 'msrwa', array( __CLASS__, 'batches' ), 'dashicons-edit-page', 58 );
		add_submenu_page( 'msrwa', 'Rédaction', 'Rédaction', 'msrwa_create', 'msrwa', array( __CLASS__, 'batches' ) );
		add_submenu_page( 'msrwa', 'Jobs', 'Jobs', 'msrwa_create', 'msrwa-jobs', array( __CLASS__, 'jobs' ) );
		add_submenu_page( 'msrwa', 'Planificateur', 'Planificateur', 'manage_options', 'msrwa-cron', array( 'MSRWA_Operations', 'cron' ) );
		add_submenu_page( 'msrwa', 'Statistiques', 'Statistiques', 'manage_options', 'msrwa-operations', array( 'MSRWA_Operations', 'screen' ) );
		add_submenu_page( 'msrwa', 'Moteur', 'Moteur', 'manage_options', 'msrwa-engine', array( __CLASS__, 'engine' ) );
		add_submenu_page( 'msrwa', 'Réglages', 'Réglages', 'manage_options', 'msrwa-settings', array( __CLASS__, 'settings' ) );
		add_submenu_page( null, 'Détail du lot', 'Détail du lot', 'msrwa_create', 'msrwa-batch', array( __CLASS__, 'batch' ) );
		add_submenu_page( null, 'Détail du run', 'Détail du run', 'msrwa_create', 'msrwa-run', array( __CLASS__, 'run' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'msrwa' ) ) { return; }
		wp_enqueue_media();
		wp_enqueue_style( 'msrwa-operations', MSRWA_URL . 'assets/operations.css', array( 'msrwa-admin' ), MSRWA_VERSION . '.' . filemtime( MSRWA_DIR . 'assets/operations.css' ) );
		wp_enqueue_style( 'msrwa-admin', MSRWA_URL . 'assets/admin.css', array(), MSRWA_VERSION . '.' . filemtime( MSRWA_DIR . 'assets/admin.css' ) );
		wp_enqueue_script( 'msrwa-admin', MSRWA_URL . 'assets/admin.js', array(), MSRWA_VERSION . '.' . filemtime( MSRWA_DIR . 'assets/admin.js' ), true );
		wp_localize_script( 'msrwa-admin', 'MSRWA', array( 'api' => esc_url_raw( rest_url( 'msrwa/v1' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	private static function guard( $capability = 'msrwa_create' ) {
		if ( ! current_user_can( $capability ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
	}

	// --- Lots ------------------------------------------------------------

	public static function navigation() {
		$current = sanitize_key( $_GET['page'] ?? 'msrwa' );
		$pages = array( 'msrwa' => 'Rédaction', 'msrwa-jobs' => 'Jobs' );
		if ( current_user_can( 'manage_options' ) ) { $pages += array( 'msrwa-operations' => 'Statistiques', 'msrwa-cron' => 'Planificateur', 'msrwa-engine' => 'Moteur', 'msrwa-settings' => 'Réglages' ); }
		echo '<nav class="msrwa-nav" aria-label="Navigation du plugin">';
		foreach ( $pages as $page => $label ) { echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '"' . ( $current === $page ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>'; }
		echo '</nav>';
	}

	public static function jobs() {
		self::guard();
		global $wpdb;
		$t = MSRWA_DB::tables();
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$status = sanitize_key( $_GET['status'] ?? '' );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$where = current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' ) ? '1=1' : $wpdb->prepare( 'owner_id = %d', get_current_user_id() );
		$statuses = array( 'queued' => 'En attente', 'running' => 'En cours', 'done' => 'Terminé', 'failed' => 'Échoué', 'cancelled' => 'Annulé' );
		if ( isset( $statuses[ $status ] ) ) { $where .= $wpdb->prepare( ' AND status = %s', $status ); }
		if ( '' !== $search ) { $where .= $wpdb->prepare( ' AND label LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' ); }
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['runs']} WHERE $where" );
		$page = min( $page, max( 1, (int) ceil( $total / 25 ) ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id,label,status,step,steps_done,steps_total,cost_usd,seconds,updated_at FROM {$t['runs']} WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", 25, ( $page - 1 ) * 25 ), ARRAY_A );
		echo '<div class="wrap msrwa-wrap"><h1>Jobs</h1>'; self::navigation();
		echo '<form class="msrwa-filters" method="get"><input type="hidden" name="page" value="msrwa-jobs"><label>Rechercher une recette<input name="s" value="' . esc_attr( $search ) . '"></label><label>État<select name="status"><option value="">Tous les états</option>';
		foreach ( $statuses as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $key, $status, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><button class="button button-primary">Filtrer</button><a class="button" href="' . esc_url( admin_url( 'admin.php?page=msrwa-jobs' ) ) . '">Réinitialiser</a></form><p>' . esc_html( $total ) . ' job(s). Dates UTC. Terminé ne signifie pas approuvé éditorialement.</p><div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>Recette</th><th>État</th><th>Progression</th><th>Coût estimé</th><th>Temps cumulé</th><th>Mise à jour</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=msrwa-run&run_id=' . (int) $row['id'] ) ) . '">' . esc_html( $row['label'] ) . '</a></td><td>' . esc_html( $statuses[ $row['status'] ] ?? $row['status'] ) . '</td><td>' . esc_html( $row['steps_done'] . '/' . $row['steps_total'] . ' ' . $row['step'] ) . '</td><td>' . esc_html( sprintf( '%.4f $', $row['cost_usd'] ) ) . '</td><td>' . esc_html( sprintf( '%.1f s', $row['seconds'] ) ) . '</td><td>' . esc_html( $row['updated_at'] ) . '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="6">Aucun job ne correspond aux filtres. Commencez depuis Rédaction.</td></tr>'; }
		echo '</tbody></table></div><nav class="msrwa-pagination" aria-label="Pages des jobs">';
		foreach ( array( $page - 1 => 'Précédent', $page + 1 => 'Suivant' ) as $target => $label ) {
			if ( $target < 1 || $target > ceil( $total / 25 ) ) { continue; }
			echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'msrwa-jobs', 'paged' => $target, 'status' => $status, 's' => $search ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav></div>';
	}

	public static function batches() {
		self::guard();
		$batches = MSRWA_Batch::recent( 30 );
		$keys = MSRWA_Settings::configured_providers();
		?>
		<div class="wrap msrwa-wrap">
			<h1>Rédaction</h1>
			<?php self::navigation(); ?>
			<p class="description">Collez vos recettes, séparées par une ligne de tirets. Ajoutez les photographies. Elles seront décrites puis associées aux recettes ; vous confirmez l’appariement avant que quoi que ce soit ne soit généré.</p>

			<?php if ( ! $keys ) : ?>
				<div class="notice notice-error"><p>Aucune clé d’API enregistrée. Rien ne peut être généré. <a href="<?php echo esc_url( admin_url( 'admin.php?page=msrwa-settings' ) ); ?>">Réglages</a></p></div>
			<?php endif; ?>
			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div class="notice notice-warning"><p><code>DISABLE_WP_CRON</code> est actif : les runs n’avanceront que si un cron serveur appelle <code>wp-cron.php</code>.</p></div>
			<?php endif; ?>

			<div class="msrwa-card">
				<h2>Nouveau lot</h2>
				<form id="msrwa-new-batch">
					<p>
						<label for="msrwa-recipes"><strong>Recettes</strong></label><br>
						<textarea id="msrwa-recipes" name="recipes" rows="14" class="large-text code" spellcheck="false" placeholder="Tarte aux pommes normande&#10;…&#10;&#10;---&#10;&#10;Poulet yassa&#10;…"></textarea>
						<span class="description">Une ligne de trois tirets ou plus sépare deux recettes. La première ligne de chaque bloc en devient le titre.</span>
					</p>
					<p>
						<button type="button" class="button" id="msrwa-pick-images">Choisir les photographies</button>
						<span id="msrwa-image-count" class="description">aucune</span>
						<input type="hidden" id="msrwa-images" name="images" value="">
					</p>
					<p>
						<label for="msrwa-budget"><strong>Plafond par recette, en dollars</strong></label><br>
						<input type="number" id="msrwa-budget" name="budget" value="0.20" step="0.01" min="0.01" class="small-text">
						<span class="description">Chaque run s’arrête plutôt que de dépasser ce plafond.</span>
					</p>
					<p><button type="submit" class="button button-primary">Décrire et apparier</button> <span id="msrwa-new-status" class="description"></span></p>
					<p class="description">L’appariement coûte un appel de vision par photographie, une seule fois.</p>
				</form>
			</div>

			<div class="msrwa-card">
				<h2>Lots récents</h2>
				<table class="widefat striped">
					<thead><tr><th>#</th><th>Lot</th><th>État</th><th>Recettes</th><th>Photos</th><th>Plafond</th><th></th></tr></thead>
					<tbody>
					<?php if ( ! $batches ) : ?><tr><td colspan="7">Aucun lot.</td></tr><?php endif; ?>
					<?php foreach ( $batches as $batch ) : ?>
						<tr>
							<td><?php echo esc_html( $batch['id'] ); ?></td>
							<td><?php echo esc_html( $batch['label'] ); ?></td>
							<td><?php echo esc_html( self::batch_state( $batch['status'] ) ); ?></td>
							<td><?php echo esc_html( $batch['recipes'] ); ?></td>
							<td><?php echo esc_html( $batch['images'] ); ?></td>
							<td><?php echo esc_html( sprintf( '%.2f $', (float) $batch['budget_usd'] ) ); ?></td>
							<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=msrwa-batch&batch_id=' . (int) $batch['id'] ) ); ?>">Ouvrir</a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private static function batch_state( $status ) {
		$labels = array( 'matching' => 'appariement en cours', 'ready' => 'à confirmer', 'running' => 'en cours', 'done' => 'terminé', 'failed' => 'échoué' );
		return $labels[ $status ] ?? $status;
	}

	// --- Un lot : l’appariement, puis les runs ---------------------------

	public static function batch() {
		self::guard();
		$batch = MSRWA_Batch::get( isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0 );
		if ( ! $batch || ! MSRWA_Batch::may_see( $batch ) ) { wp_die( esc_html__( 'Lot introuvable.', 'ms-recipes-writer-ai' ) ); }
		$matching = MSRWA_Batch::matching( (int) $batch['id'] );
		$runs = MSRWA_Run::for_batch( (int) $batch['id'] );
		$editable = 'ready' === $batch['status'];
		?>
		<div class="wrap msrwa-wrap" data-batch="<?php echo esc_attr( $batch['id'] ); ?>">
			<h1>Lot #<?php echo esc_html( $batch['id'] ); ?> — <?php echo esc_html( $batch['label'] ); ?></h1>
			<?php self::navigation(); ?>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=msrwa' ) ); ?>">Retour</a></p>

			<?php if ( '' !== (string) $batch['error_message'] ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $batch['error_message'] ); ?></p></div>
			<?php endif; ?>

			<div class="msrwa-card">
				<h2>Appariement</h2>
				<?php if ( ! empty( $matching['reasoning'] ) ) : ?><p class="description"><?php echo esc_html( $matching['reasoning'] ); ?></p><?php endif; ?>
				<p class="description">Coût de l’appariement : <?php echo esc_html( sprintf( '%.4f $', (float) ( $matching['cost_usd'] ?? 0 ) ) ); ?>. Corriger une association ne coûte rien : aucune photographie n’est décrite deux fois.</p>
				<table class="widefat striped" id="msrwa-pairs">
					<thead><tr><th>Photographie</th><th>Ce qu’elle montre</th><th>Confiance</th><th>Recette</th></tr></thead>
					<tbody>
					<?php foreach ( (array) $matching['images'] as $index => $image ) : ?>
						<?php
						$pair = array( 'recipe' => null, 'confidence' => 'basse', 'why' => '' );
						foreach ( (array) $matching['pairs'] as $candidate ) { if ( (int) $candidate['image'] === (int) $index ) { $pair = $candidate; } }
						?>
						<tr>
							<td>
								<?php if ( ! empty( $image['url'] ) ) : ?><img src="<?php echo esc_url( $image['url'] ); ?>" alt="" style="max-width:120px;height:auto;border-radius:6px"><br><?php endif; ?>
								<code><?php echo esc_html( $image['file'] ); ?></code>
							</td>
							<td><?php echo esc_html( '' !== (string) ( $image['dish'] ?? '' ) ? $image['dish'] . ' — ' : '' ); ?><?php echo esc_html( (string) ( $image['describes'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) $pair['confidence'] ); ?><br><small><?php echo esc_html( (string) $pair['why'] ); ?></small></td>
							<td>
								<select class="msrwa-pair" data-image="<?php echo esc_attr( $index ); ?>" <?php disabled( ! $editable ); ?>>
									<option value="" <?php selected( null === $pair['recipe'] ); ?>>— aucune —</option>
									<?php foreach ( (array) $matching['recipes'] as $recipe_index => $recipe ) : ?>
										<option value="<?php echo esc_attr( $recipe_index ); ?>" <?php selected( null !== $pair['recipe'] && (int) $recipe_index === (int) $pair['recipe'] ); ?>><?php echo esc_html( $recipe['title'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $editable ) : ?>
					<p>
						<button class="button" id="msrwa-save-pairs">Enregistrer l’appariement</button>
						<button class="button button-primary" id="msrwa-dispatch">Lancer les <?php echo esc_html( $batch['recipes'] ); ?> recettes</button>
						<span id="msrwa-batch-status" class="description"></span>
					</p>
					<p class="description">Une recette sans photographie est générée quand même : le moteur cherchera ses propres références.</p>
				<?php endif; ?>
			</div>

			<?php if ( $runs ) : ?>
			<div class="msrwa-card">
				<h2>Runs</h2>
				<table class="widefat striped" id="msrwa-runs">
					<thead><tr><th>#</th><th>Recette</th><th>État</th><th>Étapes</th><th>Coût</th><th>Durée</th><th>Verdict</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $runs as $run ) : ?>
						<tr data-run="<?php echo esc_attr( $run['id'] ); ?>">
							<td><?php echo esc_html( $run['id'] ); ?></td>
							<td><?php echo esc_html( $run['label'] ); ?></td>
							<td class="msrwa-run-state"><?php echo esc_html( $run['status'] ); ?></td>
							<td class="msrwa-run-steps"><?php echo esc_html( $run['steps_done'] . ' / ' . $run['steps_total'] ); ?></td>
							<td class="msrwa-run-cost"><?php echo esc_html( sprintf( '%.4f $', (float) $run['cost_usd'] ) ); ?></td>
							<td class="msrwa-run-seconds"><?php echo esc_html( sprintf( '%.1f s', (float) $run['seconds'] ) ); ?></td>
							<td><?php echo esc_html( null === $run['approved'] ? '—' : ( $run['approved'] ? 'approuvé par le juge' : 'refusé par le juge' ) ); ?></td>
							<td>
								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=msrwa-run&run_id=' . (int) $run['id'] ) ); ?>">Détail</a>
								<?php if ( (int) $run['draft_post_id'] ) : ?>
									<a class="button" href="<?php echo esc_url( get_edit_post_link( (int) $run['draft_post_id'] ) ); ?>">Brouillon</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Les runs avancent ensemble, une vague par tick de cron. Vous pouvez fermer cet onglet.</p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// --- Un run : tout ce que le moteur a rapporté -----------------------

	public static function run() {
		self::guard();
		$run = MSRWA_Run::get( isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0 );
		if ( ! $run || ! MSRWA_Run::may_see( $run ) ) { wp_die( esc_html__( 'Run introuvable.', 'ms-recipes-writer-ai' ) ); }
		$id = (int) $run['id'];
		$state = MSRWA_Run::state( $id );
		$totals = $state['totals'];
		$approval = (array) ( $state['artifacts']['approval'] ?? array() );
		?>
		<div class="wrap msrwa-wrap">
			<h1>Run #<?php echo esc_html( $id ); ?> — <?php echo esc_html( $run['label'] ); ?></h1>
			<?php self::navigation(); ?>
			<?php $report_url = wp_nonce_url( admin_url( 'admin-post.php?action=msrwa_job_report&run_id=' . $id ), 'msrwa_job_report_' . $id ); ?>
			<div class="msrwa-card">
				<h2>Rapport éditorial complet</h2>
				<p>État actuel : <strong><?php echo esc_html( $run['status'] ); ?></strong>. Le verdict du moteur ne remplace pas la validation éditoriale. Les temps sont cumulés par étape, hors attente cron.</p>
				<p><a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( $report_url ); ?>">Ouvrir le rapport en pleine page</a> <a class="button" href="<?php echo esc_url( $report_url . '&download=1' ); ?>">Télécharger le rapport HTML avec images</a></p>
				<iframe class="msrwa-report-frame" title="Rapport complet du job : recette, article, SEO, images et contrôles" sandbox="allow-same-origin" referrerpolicy="no-referrer" src="<?php echo esc_url( $report_url ); ?>"></iframe>
			</div>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=msrwa-batch&batch_id=' . (int) $run['batch_id'] ) ); ?>">Retour au lot</a>
				<?php if ( (int) $run['draft_post_id'] ) : ?><a class="button button-primary" href="<?php echo esc_url( get_edit_post_link( (int) $run['draft_post_id'] ) ); ?>">Ouvrir le brouillon</a><?php endif; ?>
			</p>

			<div class="msrwa-card">
				<h2>Total</h2>
				<div class="msrwa-fields">
					<dl><dt>État</dt><dd><?php echo esc_html( $run['status'] ); ?></dd></dl>
					<dl><dt>Coût</dt><dd><?php echo esc_html( sprintf( '%.4f $', (float) $totals['cost_usd'] ) ); ?></dd></dl>
					<dl><dt>Durée</dt><dd><?php echo esc_html( sprintf( '%.1f s', (float) $totals['seconds'] ) ); ?></dd></dl>
					<dl><dt>Tokens</dt><dd><?php echo esc_html( number_format_i18n( $totals['input_tokens'] ) . ' / ' . number_format_i18n( $totals['output_tokens'] ) ); ?></dd></dl>
				</div>
				<?php if ( ! empty( $totals['unpriced_steps'] ) ) : ?>
					<p class="description"><strong><?php echo esc_html( $totals['unpriced_steps'] ); ?> étape(s) sur un modèle sans tarif publié : la dépense de ce run n’est pas vérifiable.</strong></p>
				<?php endif; ?>
				<?php if ( '' !== (string) $run['error_message'] ) : ?><p class="description"><strong><?php echo esc_html( $run['error_message'] ); ?></strong></p><?php endif; ?>
			</div>

			<div class="msrwa-card">
				<h2>Étapes</h2>
				<table class="widefat striped">
					<thead><tr><th>Étape</th><th>Modèle</th><th>Durée</th><th>Coût</th><th>Score</th><th>Contrôles non satisfaits</th></tr></thead>
					<tbody>
					<?php foreach ( $state['steps'] as $step ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $step['step'] ); ?></strong><?php if ( '' !== $step['error'] ) : ?><br><em><?php echo esc_html( $step['error'] ); ?></em><?php endif; ?></td>
							<td><?php echo esc_html( $step['model'] ); ?></td>
							<td><?php echo esc_html( sprintf( '%.1f s', $step['seconds'] ) ); ?></td>
							<td><?php echo esc_html( null === $step['cost_usd'] ? 'tarif inconnu' : sprintf( '%.4f $', $step['cost_usd'] ) ); ?></td>
							<td><?php echo esc_html( null === $step['passed'] ? '—' : $step['passed'] . ' / ' . $step['total'] ); ?></td>
							<td>
								<?php $failed = array_filter( (array) $step['checks'], static function ( $check ) { return is_array( $check ) && empty( $check['pass'] ); } ); ?>
								<?php if ( ! $failed ) : ?>—<?php endif; ?>
								<?php foreach ( $failed as $label => $check ) : ?>
									<div><code><?php echo esc_html( $label ); ?></code> <?php echo esc_html( is_scalar( $check['detail'] ?? '' ) ? (string) $check['detail'] : '' ); ?></div>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="msrwa-card">
				<h2>Appels</h2>
				<table class="widefat striped">
					<thead><tr><th>Étape</th><th>Fournisseur / modèle</th><th>Entrée</th><th>Cache</th><th>Sortie</th><th>Durée</th><th>Coût</th></tr></thead>
					<tbody>
					<?php foreach ( MSRWA_Run::calls( $id ) as $call ) : ?>
						<tr>
							<td><?php echo esc_html( $call['step'] ); ?></td>
							<td><?php echo esc_html( $call['provider'] . ' / ' . $call['model'] ); ?><br><small><?php echo esc_html( $call['endpoint'] ); ?></small></td>
							<td><?php echo esc_html( number_format_i18n( (int) $call['input_tokens'] ) ); ?></td>
							<td><?php echo esc_html( (int) $call['cached_tokens'] ? round( 100 * (int) $call['cached_tokens'] / max( 1, (int) $call['input_tokens'] ) ) . ' %' : '—' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $call['output_tokens'] ) ); ?></td>
							<td><?php echo esc_html( sprintf( '%.1f s', (float) $call['seconds'] ) ); ?></td>
							<td><?php echo esc_html( empty( $call['priced'] ) ? 'tarif inconnu' : sprintf( '%.4f $', (float) $call['cost_usd'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $approval ) : ?>
			<div class="msrwa-card">
				<h2>Verdict du juge</h2>
				<p class="description">C’est l’avis du moteur sur sa propre production, jamais une validation éditoriale.</p>
				<p><strong><?php echo esc_html( empty( $approval['approved'] ) ? 'Refusé' : 'Approuvé' ); ?></strong></p>
				<?php if ( ! empty( $approval['findings'] ) ) : ?>
					<ul>
					<?php foreach ( (array) $approval['findings'] as $finding ) : ?>
						<li><strong><?php echo esc_html( (string) ( $finding['severity'] ?? '' ) ); ?></strong> — <?php echo esc_html( (string) ( $finding['target'] ?? '' ) ); ?> : <?php echo esc_html( (string) ( $finding['reason'] ?? '' ) ); ?></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<div class="msrwa-card">
				<h2>Déroulé</h2>
				<div class="msrwa-event-list">
					<?php foreach ( $state['events'] as $event ) : ?>
						<div><span><?php echo esc_html( sprintf( '%.1f s (vague)', (float) $event['at'] ) ); ?> <code><?php echo esc_html( $event['step'] ); ?></code></span><span><?php echo esc_html( $event['message'] ); ?></span><small><?php echo esc_html( $event['kind'] ); ?></small></div>
						<details><summary>Données de l’événement</summary><pre><?php echo esc_html( wp_json_encode( MSRWA_DB::sanitize( $event['data'] ?? array() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre></details>
					<?php endforeach; ?>
				</div>
			</div>
			<section class="msrwa-card"><h2>Livrables et données du moteur</h2>
			<?php foreach ( (array) $state['artifacts'] as $key => $artifact ) : ?>
				<details><summary><?php echo esc_html( $key ); ?></summary><pre><?php echo esc_html( wp_json_encode( MSRWA_DB::sanitize( $artifact ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre></details>
			<?php endforeach; ?>
			</section>
		</div>
		<?php
	}

	// --- Moteur et réglages ----------------------------------------------

	public static function engine() {
		self::guard( 'manage_options' );
		$defaults = MSRWA_Engine_Settings::defaults();
		$stored = MSRWA_Engine_Settings::stored();
		$invalid = isset( $_GET['invalid'] ) ? array_filter( explode( ',', sanitize_text_field( wp_unslash( $_GET['invalid'] ) ) ) ) : array();
		$encode = static function ( $value ) { return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };
		?>
		<div class="wrap msrwa-wrap">
			<h1>Moteur</h1>
			<?php self::navigation(); ?>
			<p class="description">Tout ce que le moteur utilise est modifiable ici, et rien n’est modifié à l’intérieur du moteur : ce qui est enregistré lui est remis comme couche appelante. Seule la différence avec ses valeurs par défaut est conservée.</p>
			<?php if ( isset( $_GET['saved'] ) && ! $invalid ) : ?><div class="notice notice-success"><p>Enregistré.</p></div><?php endif; ?>
			<?php if ( $invalid ) : ?><div class="notice notice-error"><p>Aucun changement enregistré. JSON invalide pour : <?php echo esc_html( implode( ', ', $invalid ) ); ?>.</p></div><?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="msrwa_save_engine">
				<?php wp_nonce_field( 'msrwa_save_engine' ); ?>
				<div class="msrwa-card">
					<h2>Langue</h2>
					<p><label for="msrwa-language">Langue de génération</label><input id="msrwa-language" type="text" name="msrwa_engine[language]" value="<?php echo esc_attr( $stored['language'] ?? $defaults['language'] ); ?>" class="small-text"> <span class="description">Défaut : <code><?php echo esc_html( $defaults['language'] ); ?></code></span></p>
				</div>
				<?php foreach ( array( 'Réglages' => MSRWA_Engine_Settings::simple(), 'Structures' => MSRWA_Engine_Settings::structural() ) as $section => $groups ) : ?>
					<h2><?php echo esc_html( $section ); ?></h2>
					<?php foreach ( $groups as $group => $help ) : ?>
						<?php $effective = MSRWA_Engine_Settings::effective( $group ); ?>
						<div class="msrwa-card">
							<h3><?php echo esc_html( $group ); ?><?php if ( isset( $stored[ $group ] ) ) : ?> <span class="description">— modifié</span><?php endif; ?></h3>
							<p class="description"><?php echo esc_html( $help ); ?></p>
							<p><textarea name="msrwa_engine[<?php echo esc_attr( $group ); ?>]" rows="<?php echo esc_attr( min( 24, max( 6, substr_count( $encode( $effective ), "\n" ) + 1 ) ) ); ?>" class="large-text code" spellcheck="false"><?php echo esc_textarea( $encode( $effective ) ); ?></textarea></p>
							<details><summary>Valeur par défaut du moteur</summary><pre class="code"><?php echo esc_html( $encode( $defaults[ $group ] ?? array() ) ); ?></pre></details>
						</div>
					<?php endforeach; ?>
				<?php endforeach; ?>
				<?php submit_button( 'Enregistrer' ); ?>
			</form>

			<div class="msrwa-card">
				<h2>Ce qui est réellement transmis</h2>
				<pre class="code"><?php echo esc_html( $encode( $stored ) ); ?></pre>
			</div>
		</div>
		<?php
	}

	public static function settings() {
		self::guard( 'manage_options' );
		$configured = MSRWA_Settings::configured_providers();
		?>
		<div class="wrap msrwa-wrap">
			<h1>Réglages</h1>
			<?php self::navigation(); ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Enregistré.</p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="msrwa_save_settings">
				<?php wp_nonce_field( 'msrwa_save_settings' ); ?>
				<div class="msrwa-card">
					<h2>Clés d’API</h2>
					<p class="description">Stockées chiffrées et jamais réaffichées. Laissez un champ vide pour conserver la clé enregistrée.</p>
					<?php foreach ( array( 'openai_key' => array( 'OpenAI', 'openai' ), 'gemini_key' => array( 'Gemini', 'gemini' ), 'claude_key' => array( 'Claude', 'anthropic' ) ) as $field => $provider ) : ?>
						<p>
							<label for="msrwa-<?php echo esc_attr( $field ); ?>"><strong><?php echo esc_html( $provider[0] ); ?></strong></label><br>
							<input type="password" id="msrwa-<?php echo esc_attr( $field ); ?>" name="msrwa_settings[<?php echo esc_attr( $field ); ?>]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo in_array( $provider[1], $configured, true ) ? 'clé enregistrée' : 'aucune clé'; ?>">
						</p>
					<?php endforeach; ?>
				</div>
				<?php submit_button( 'Enregistrer' ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_save_settings' );
		MSRWA_Settings::save( isset( $_POST['msrwa_settings'] ) ? wp_unslash( $_POST['msrwa_settings'] ) : array() );
		wp_safe_redirect( admin_url( 'admin.php?page=msrwa-settings&saved=1' ) );
		exit;
	}

	public static function save_engine() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_save_engine' );
		$invalid = MSRWA_Engine_Settings::save( isset( $_POST['msrwa_engine'] ) ? (array) wp_unslash( $_POST['msrwa_engine'] ) : array() );
		wp_safe_redirect( add_query_arg( array( 'page' => 'msrwa-engine', 'saved' => 1, 'invalid' => implode( ',', $invalid ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
