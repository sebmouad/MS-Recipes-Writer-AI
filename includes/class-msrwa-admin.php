<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Admin {
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_msrwa_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'editorial_meta_box' ) );
	}

	public static function editorial_meta_box( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) || ! get_post_meta( $post->ID, '_msrwa_job_id', true ) ) { return; }
		add_meta_box( 'msrwa-editorial-review', 'MS Recipes Writer — Aide à la relecture', array( __CLASS__, 'render_editorial_meta_box' ), 'post', 'normal', 'high' );
	}

	public static function render_editorial_meta_box( $post ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$id = absint( get_post_meta( $post->ID, '_msrwa_job_id', true ) );
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT owner_id FROM {$t['jobs']} WHERE id=%d", $id ) );
		if ( ! $job || ( (int) $job->owner_id !== get_current_user_id() && ! current_user_can( 'msrwa_view_all' ) && ! current_user_can( 'manage_options' ) ) ) { echo '<p>Rapport réservé au propriétaire du job et aux administrateurs.</p>'; return; }
		$json = $wpdb->get_var( $wpdb->prepare( "SELECT content_json FROM {$t['artifacts']} WHERE job_id=%d AND artifact_key='editorial_review' AND status='current' ORDER BY version DESC LIMIT 1", $id ) );
		self::render_editorial_report( json_decode( (string) $json, true ) );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ms-recipes-writer-ai-job-detail&job_id=' . $id ) ) . '">Voir le détail du job #' . esc_html( $id ) . '</a></p>';
	}

	private static function render_editorial_report( $report ) {
		if ( ! is_array( $report ) ) { echo '<p>Rapport non disponible.</p>'; return; }
		$needs_review = 'needs_review' === ( $report['status'] ?? '' );
		echo '<p><strong>' . ( $needs_review ? 'À vérifier avant publication' : 'Contrôles automatiques réussis' ) . '</strong></p>';
		echo '<p>Score de structure : <strong>' . ( isset( $report['score'] ) ? esc_html( $report['score'] ) . ' %' : 'Non évalué' ) . '</strong> · Relecture du texte : ' . ( ! empty( $report['text_review_passed'] ) ? 'validée' : 'à vérifier' ) . '</p>';
		if ( empty( $report['article_available'] ) ) { echo '<p><strong>Article non généré : ce brouillon contient uniquement les éléments disponibles.</strong></p>'; }
		echo '<p>Ces contrôles concernent la version générée. Vérifiez le contenu, les quantités et les images avant de publier.</p>';
		if ( ! empty( $report['findings'] ) ) {
			echo '<ul>';
			foreach ( $report['findings'] as $finding ) {
				if ( ! is_array( $finding ) || empty( $finding['reason'] ) ) { continue; }
				echo '<li><strong>' . esc_html( $finding['field'] ?? 'Observation' ) . '</strong> — ' . esc_html( $finding['reason'] );
				if ( ! empty( $finding['fix'] ) && is_scalar( $finding['fix'] ) ) { echo '<br>Conseil : ' . esc_html( $finding['fix'] ); }
				echo '</li>';
			}
			echo '</ul>';
		} else { echo '<p>Aucun défaut signalé par les contrôles exécutés.</p>'; }
	}

	public static function menu() {
		add_menu_page( 'MS Recipes Writer', 'MS Recipes Writer', 'edit_posts', 'ms-recipes-writer-ai', array( __CLASS__, 'page' ), 'dashicons-edit-page', 58 );
		add_submenu_page( 'ms-recipes-writer-ai', 'Créer des Articles/Images', 'Créer des Articles/Images', 'edit_posts', 'ms-recipes-writer-ai', array( __CLASS__, 'page' ) );
		add_submenu_page( 'ms-recipes-writer-ai', 'Statistiques', 'Statistiques', 'edit_posts', 'ms-recipes-writer-ai-stats', array( __CLASS__, 'stats_page' ) );
		add_submenu_page( 'ms-recipes-writer-ai', 'Configuration', 'Configuration', 'manage_options', 'ms-recipes-writer-ai-settings', array( __CLASS__, 'settings_page' ) );
		add_submenu_page( null, 'Détail du lot', 'Détail du lot', 'edit_posts', 'ms-recipes-writer-ai-job', array( __CLASS__, 'job_page' ) );
		add_submenu_page( null, 'Détail du job', 'Détail du job', 'edit_posts', 'ms-recipes-writer-ai-job-detail', array( __CLASS__, 'job_detail_page' ) );
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_save_settings' );
		$raw = isset( $_POST[ MSRWA_Settings::FORM_FIELD ] ) ? wp_unslash( $_POST[ MSRWA_Settings::FORM_FIELD ] ) : array();
		MSRWA_Settings::save( is_array( $raw ) ? $raw : array(), 'admin' );
		$catalog = isset( $_POST['msrwa_catalog'] ) ? wp_unslash( $_POST['msrwa_catalog'] ) : array();
		MSRWA_Catalog::save_admin( is_array( $catalog ) ? $catalog : array() );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-settings', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'ms-recipes-writer-ai' ) ) { return; }
		wp_enqueue_style( 'msrwa-admin', MSRWA_URL . 'assets/admin.css', array(), MSRWA_VERSION . '.' . filemtime( MSRWA_DIR . 'assets/admin.css' ) );
		wp_add_inline_style( 'msrwa-admin', '.msrwa-job-kpis{grid-template-columns:repeat(4,minmax(0,1fr))}.msrwa-job-kpis strong{font-size:16px;overflow-wrap:anywhere}.msrwa-job-list{display:grid;gap:14px}.msrwa-job-card{border:1px solid #dce6ea;border-radius:11px;padding:18px;background:#fbfdfd}.msrwa-job-card header{display:flex;justify-content:space-between;gap:16px}.msrwa-job-card h3{margin:5px 0 0;font-size:17px}.msrwa-job-number{color:#607480;font-size:12px;font-weight:700;text-transform:uppercase}.msrwa-job-state{text-align:right}.msrwa-job-state span{display:block;color:#0b605b;font-weight:700;text-transform:capitalize}.msrwa-job-state small{color:#607480}.msrwa-job-meta{display:flex;flex-wrap:wrap;gap:7px 14px;margin:16px 0;color:#50636f;font-size:13px}.msrwa-job-notice{margin:14px 0;padding:12px;border-left:3px solid #d63638;border-radius:0 7px 7px 0;background:#fff5f5}.msrwa-job-notice p{margin:5px 0 0}.msrwa-job-findings{margin:14px 0;padding:12px;border-radius:7px;background:#f1f7f7}.msrwa-job-findings ul{margin:8px 0 0 18px}.msrwa-job-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.msrwa-job-action-status{font-size:13px}.msrwa-event-list{display:grid;gap:0}.msrwa-event-list>div{display:grid;grid-template-columns:minmax(180px,1fr) minmax(180px,1fr) auto;gap:10px;padding:11px 0;border-bottom:1px solid #edf1f3}.msrwa-event-list>div:last-child{border-bottom:0}.msrwa-event-list span,.msrwa-event-list small{color:#667684}@media (max-width:782px){.msrwa-job-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.msrwa-job-card header{display:block}.msrwa-job-state{text-align:left;margin-top:10px}.msrwa-event-list>div{grid-template-columns:1fr}.msrwa-job-actions{align-items:flex-start;flex-direction:column}}' );
		wp_add_inline_style( 'msrwa-admin', '.msrwa-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.msrwa-detail-grid .msrwa-card{margin:0}.msrwa-mini-list{display:grid;gap:0}.msrwa-mini-list>div{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:12px;align-items:center;padding:11px 0;border-bottom:1px solid #edf1f3}.msrwa-mini-list>div:last-child{border-bottom:0}.msrwa-mini-list span,.msrwa-mini-list small{color:#667684}.msrwa-mini-list small{text-align:right}@media(max-width:782px){.msrwa-detail-grid{grid-template-columns:1fr;gap:12px}.msrwa-mini-list>div{grid-template-columns:1fr;gap:3px}}' );
		wp_add_inline_style( 'msrwa-admin', '.msrwa-stats-kpis{grid-template-columns:repeat(auto-fit,minmax(135px,1fr))}' );
		wp_enqueue_script( 'msrwa-admin', MSRWA_URL . 'assets/admin.js', array(), MSRWA_VERSION . '.' . filemtime( MSRWA_DIR . 'assets/admin.js' ), true );
		wp_localize_script( 'msrwa-admin', 'MSRWA', array( 'api' => esc_url_raw( rest_url( 'msrwa/v1' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	public static function page() {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$settings = MSRWA_Settings::get();
		$args = MSRWA_Lists::sanitize_args( $_GET );
		?>
		<div class="wrap msrwa-wrap msrwa-create-page"><div class="msrwa-page-head"><div><h1>MS Recipes Writer</h1><p class="description">Créez des articles et des images à partir d’un seul brief culinaire.</p></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ms-recipes-writer-ai-stats' ) ); ?>">Voir les statistiques</a></div><section class="msrwa-card msrwa-composer"><h2>Créer des Articles/Images</h2><p class="description">Fournissez une recette, un titre ou des instructions. Les images peuvent être ajoutées par URL ou depuis votre ordinateur.</p><form id="msrwa-create-form" enctype="multipart/form-data" data-max-reference-images="<?php echo esc_attr( $settings['max_reference_images'] ); ?>"><label for="msrwa-recipe">Texte ou recette fournie<textarea id="msrwa-recipe" rows="12" maxlength="20000" placeholder="Exemple : Tarte aux pommes... ingrédients, étapes, temps et consignes éditoriales."></textarea></label><div class="msrwa-reference-grid"><label for="msrwa-image-urls">Images de référence — URLs<textarea id="msrwa-image-urls" rows="5" placeholder="Une URL HTTPS par ligne"></textarea><span class="description">Les URLs sont vérifiées puis stockées temporairement hors du document public.</span></label><label for="msrwa-reference-files">Images de référence — depuis l’ordinateur<input type="file" id="msrwa-reference-files" name="reference_files[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple><span id="msrwa-files-help" class="description">Jusqu’à <?php echo esc_html( $settings['max_reference_images'] ); ?> images, taille maximale 10 Mo par fichier.</span></label></div><div class="msrwa-form-actions"><button type="submit" class="button button-primary button-hero" id="msrwa-create">Créer le lot</button><span id="msrwa-message" role="status" aria-live="polite"></span></div></form></section>
		<nav class="msrwa-range-tabs" aria-label="Listes"><a class="<?php echo 'articles' === $args['view'] ? 'is-active' : ''; ?>" <?php echo 'articles' === $args['view'] ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( self::list_url( array( 'msrwa_view' => 'articles' ) ) ); ?>">Articles</a><a class="<?php echo 'jobs' === $args['view'] ? 'is-active' : ''; ?>" <?php echo 'jobs' === $args['view'] ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( self::list_url( array( 'msrwa_view' => 'jobs' ) ) ); ?>">Jobs</a></nav>
		<?php if ( 'jobs' === $args['view'] ) { self::render_jobs_list( $args, $settings ); } else { self::render_articles_list( $args ); } ?>
		</div>
		<?php
	}

	/** Builds a list URL from the current request, so filters survive navigation. */
	private static function list_url( $overrides = array() ) {
		$keep = array( 'msrwa_view', 'msrwa_search', 'msrwa_status', 'msrwa_quality', 'msrwa_state', 'msrwa_stage', 'msrwa_batch', 'msrwa_days', 'msrwa_order', 'msrwa_per_page', 'msrwa_paged' );
		if ( MSRWA_Lists::can_view_all() ) { $keep[] = 'msrwa_author'; }
		$query = array( 'page' => 'ms-recipes-writer-ai' );
		foreach ( $keep as $key ) {
			if ( array_key_exists( $key, $overrides ) ) { continue; }
			if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) { $query[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); }
		}
		foreach ( $overrides as $key => $value ) { if ( '' !== $value && null !== $value ) { $query[ $key ] = $value; } }
		unset( $query['msrwa_paged'] );
		if ( isset( $overrides['msrwa_paged'] ) ) { $query['msrwa_paged'] = absint( $overrides['msrwa_paged'] ); }
		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	private static function render_select( $name, $label, $options, $selected, $any_label ) {
		echo '<label>' . esc_html( $label ) . '<select name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $any_label ) . '</option>';
		foreach ( $options as $value => $option_label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( $option_label ) . '</option>';
		}
		echo '</select></label>';
	}

	/** One filter bar for both lists; only the relevant controls are rendered. */
	private static function render_filters( $args, $result ) {
		$can_view_all = MSRWA_Lists::can_view_all();
		?>
		<form class="msrwa-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="ms-recipes-writer-ai">
			<input type="hidden" name="msrwa_view" value="<?php echo esc_attr( $args['view'] ); ?>">
			<label>Recherche<input type="search" name="msrwa_search" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="Titre ou numéro de job"></label>
			<?php
			if ( 'articles' === $args['view'] ) {
				self::render_select( 'msrwa_status', 'Publication', MSRWA_Lists::post_status_labels(), $args['post_status'], 'Tous les statuts' );
				self::render_select( 'msrwa_quality', 'Qualité', array_intersect_key( MSRWA_Lists::quality_labels(), array_flip( array( 'good', 'review', 'incomplete', 'uncertain', 'pending' ) ) ), $args['quality'], 'Toutes qualités' );
			} else {
				self::render_select( 'msrwa_stage', 'Étape', MSRWA_Lists::stage_labels(), $args['stage'], 'Toutes les étapes' );
			}
			self::render_select( 'msrwa_state', 'État', MSRWA_Lists::state_labels(), $args['state'], 'Tous les états' );
			if ( $can_view_all ) { self::render_select( 'msrwa_author', 'Auteur', MSRWA_Lists::authors(), $args['author'], 'Tous les auteurs' ); }
			self::render_select( 'msrwa_days', 'Période', array( 1 => 'Aujourd’hui', 7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '1 an' ), $args['days'], 'Depuis toujours' );
			self::render_select( 'msrwa_order', 'Tri', MSRWA_Lists::order_labels(), $args['order'], 'Plus récents' );
			self::render_select( 'msrwa_per_page', 'Par page', array_combine( MSRWA_Lists::PER_PAGE_CHOICES, MSRWA_Lists::PER_PAGE_CHOICES ), $args['per_page'], '20' );
			?>
			<div class="msrwa-filter-actions"><button type="submit" class="button button-primary">Filtrer</button> <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai', 'msrwa_view' => $args['view'] ), admin_url( 'admin.php' ) ) ); ?>">Réinitialiser</a><span class="msrwa-result-count"><?php echo esc_html( number_format_i18n( $result['total'] ) ); ?> résultat<?php echo $result['total'] > 1 ? 's' : ''; ?><?php echo $result['pages'] > 1 ? esc_html( ' · page ' . number_format_i18n( $result['paged'] ) . ' / ' . number_format_i18n( $result['pages'] ) ) : ''; ?></span></div>
		</form>
		<?php
	}

	private static function render_pagination( $args, $result ) {
		if ( $result['pages'] < 2 ) { return; }
		$links = paginate_links( array(
			'base'      => self::list_url( array( 'msrwa_paged' => '%#%' ) ),
			'format'    => '',
			'current'   => $result['paged'],
			'total'     => $result['pages'],
			'prev_text' => '‹ Précédent',
			'next_text' => 'Suivant ›',
		) );
		if ( $links ) { echo '<nav class="msrwa-pagination" aria-label="Pagination">' . wp_kses_post( $links ) . '</nav>'; }
	}

	private static function render_post_status( $row ) {
		$labels = MSRWA_Lists::post_status_labels();
		$status = $row['draft_post_id'] && $row['post_status'] ? (string) $row['post_status'] : 'missing';
		$label = $labels[ $status ] ?? $status;
		echo '<span class="msrwa-post-status msrwa-post-status-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/** Every article produced, filtered and paginated. Quality belongs to the article. */
	private static function render_articles_list( $args ) {
		$result = MSRWA_Lists::articles( $args );
		$can_view_all = MSRWA_Lists::can_view_all();
		$columns = $can_view_all ? 9 : 8;
		?>
		<section class="msrwa-card"><div class="msrwa-section-head"><div><h2>Articles</h2><p class="description">Qualité mesurée sur chaque article produit. <?php echo esc_html( $can_view_all ? 'Tous les éditeurs sont visibles ; utilisez le filtre Auteur.' : 'Vous voyez uniquement vos articles.' ); ?></p></div></div>
		<?php self::render_filters( $args, $result ); ?>
		<div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>Article</th><th>Publication</th><th>Qualité</th><th>État</th><?php if ( $can_view_all ) : ?><th>Auteur</th><?php endif; ?><th>Lot</th><th>Coût</th><th>Mis à jour</th><th>Actions</th></tr></thead><tbody>
		<?php if ( empty( $result['rows'] ) ) : ?><tr><td colspan="<?php echo esc_attr( $columns ); ?>">Aucun article pour ces filtres.</td></tr><?php else : foreach ( $result['rows'] as $row ) :
			$post_id = (int) $row['draft_post_id'];
			$edit_url = $post_id ? get_edit_post_link( $post_id, 'raw' ) : '';
			$view_url = $post_id && 'publish' === $row['post_status'] ? get_permalink( $post_id ) : '';
			$owner = get_userdata( (int) $row['owner_id'] );
		?>
			<tr>
				<td><strong><?php echo esc_html( $row['post_title'] ?: $row['title'] ); ?></strong><br><small>Job #<?php echo esc_html( $row['id'] ); ?> · créé le <?php echo esc_html( $row['created_at'] ); ?></small></td>
				<td><?php self::render_post_status( $row ); ?></td>
				<td class="msrwa-quality-cell"><?php MSRWA_Presentation::render_quality( $row['quality'] ); ?></td>
				<td class="msrwa-batch-status"><?php echo esc_html( MSRWA_Lists::state_labels()[ MSRWA_Presentation::state( $row['status'] ) ] ); ?></td>
				<?php if ( $can_view_all ) : ?><td><?php echo esc_html( $owner ? $owner->display_name : '#' . (int) $row['owner_id'] ); ?></td><?php endif; ?>
				<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-job', 'batch_id' => absint( $row['batch_id'] ) ), admin_url( 'admin.php' ) ) ); ?>">#<?php echo esc_html( $row['batch_id'] ); ?></a></td>
				<td><?php echo esc_html( number_format_i18n( (float) $row['cost_estimate'], 4 ) ); ?> $</td>
				<td><?php echo esc_html( $row['updated_at'] ); ?></td>
				<td class="msrwa-actions"><?php if ( $edit_url ) : ?><a class="button button-primary" href="<?php echo esc_url( $edit_url ); ?>">Modifier</a> <?php endif; ?><?php if ( $view_url ) : ?><a class="button" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener">Voir</a> <?php endif; ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-job-detail', 'job_id' => absint( $row['id'] ) ), admin_url( 'admin.php' ) ) ); ?>">Détails</a></td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody></table></div><?php self::render_pagination( $args, $result ); ?></section>
		<?php
	}

	/** Jobs with their complete processing record, filtered and paginated. */
	private static function render_jobs_list( $args, $settings ) {
		$result = MSRWA_Lists::jobs( $args );
		$can_view_all = MSRWA_Lists::can_view_all();
		$columns = $can_view_all ? 11 : 10;
		?>
		<section class="msrwa-card"><div class="msrwa-section-head"><div><h2>Jobs</h2><p class="description">Traitement complet de chaque recette : état, étape, modèles, appels, tokens, coûts, corrections et erreurs.</p></div></div>
		<?php self::render_filters( $args, $result ); ?>
		<div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>Job</th><?php if ( $can_view_all ) : ?><th>Auteur</th><?php endif; ?><th>Lot</th><th>État</th><th>Étape</th><th>Tentatives</th><th>Corrections</th><th>Appels</th><th>Tokens</th><th>Coût</th><th>Durée</th></tr></thead><tbody>
		<?php if ( empty( $result['rows'] ) ) : ?><tr><td colspan="<?php echo esc_attr( $columns ); ?>">Aucun job pour ces filtres.</td></tr><?php else : foreach ( $result['rows'] as $row ) :
			$owner = get_userdata( (int) $row['owner_id'] );
			$edit_url = $row['draft_post_id'] ? get_edit_post_link( (int) $row['draft_post_id'], 'raw' ) : '';
			$cycle_parts = array();
			foreach ( $row['cycles'] as $element => $cycles ) { $cycle_parts[] = sanitize_key( $element ) . ' ' . absint( $cycles ) . '/' . absint( $settings['max_corrections'] ); }
			$model_parts = array();
			foreach ( $row['models'] as $stage => $plan ) { if ( is_array( $plan ) && isset( $plan['provider'], $plan['model'] ) ) { $model_parts[] = sanitize_key( $stage ) . ' : ' . $plan['provider'] . ' / ' . $plan['model']; } }
		?>
			<tr>
				<td><strong>#<?php echo esc_html( $row['id'] ); ?></strong> — <?php echo esc_html( $row['title'] ); ?>
					<details class="msrwa-row-details"><summary>Tout afficher</summary><dl>
						<dt>Diagnostic technique</dt><dd><?php echo esc_html( $row['status'] ); ?><?php echo $row['error_code'] ? esc_html( ' · ' . $row['error_code'] ) : ''; ?></dd>
						<?php if ( $row['error_message'] ) : ?><dt>Message</dt><dd><?php echo esc_html( $row['error_message'] ); ?></dd><?php endif; ?>
						<dt>Article</dt><dd><?php if ( $edit_url ) : ?><a href="<?php echo esc_url( $edit_url ); ?>">Brouillon #<?php echo esc_html( (int) $row['draft_post_id'] ); ?></a> — <?php self::render_post_status( $row ); ?><?php else : ?>Aucun article produit<?php endif; ?></dd>
						<dt>Qualité de l’article</dt><dd><?php MSRWA_Presentation::render_quality( $row['quality'] ); ?></dd>
						<dt>Modèles retenus</dt><dd><?php echo esc_html( $model_parts ? implode( ' · ', $model_parts ) : 'Aucun plan enregistré' ); ?></dd>
						<dt>Corrections par élément</dt><dd><?php echo esc_html( $cycle_parts ? implode( ' · ', $cycle_parts ) : 'Aucune' ); ?></dd>
						<dt>Appels fournisseurs</dt><dd><?php echo esc_html( number_format_i18n( (int) $row['calls']['calls'] ) ); ?> appel(s) · <?php echo esc_html( number_format_i18n( (int) $row['calls']['failures'] ) ); ?> en échec · <?php echo esc_html( number_format_i18n( (int) $row['calls']['uncertain'] ) ); ?> incertain(s)</dd>
						<dt>Tokens entrée / sortie</dt><dd><?php echo esc_html( number_format_i18n( (int) $row['calls']['input_tokens'] ) . ' / ' . number_format_i18n( (int) $row['calls']['output_tokens'] ) ); ?></dd>
						<dt>Coût des appels</dt><dd><?php echo esc_html( number_format_i18n( (float) $row['calls']['cost'], 6 ) ); ?> $ (job : <?php echo esc_html( number_format_i18n( (float) $row['cost_estimate'], 6 ) ); ?> $)</dd>
						<dt>Premier / dernier appel</dt><dd><?php echo esc_html( ( $row['calls']['first_call'] ?: '—' ) . ' → ' . ( $row['calls']['last_call'] ?: '—' ) ); ?></dd>
						<dt>Créé / mis à jour</dt><dd><?php echo esc_html( $row['created_at'] . ' → ' . $row['updated_at'] ); ?></dd>
						<dt>Propriétaire</dt><dd><?php echo esc_html( $owner ? $owner->display_name . ' (#' . (int) $row['owner_id'] . ')' : '#' . (int) $row['owner_id'] ); ?></dd>
					</dl><p><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-job-detail', 'job_id' => absint( $row['id'] ) ), admin_url( 'admin.php' ) ) ); ?>">Diagnostic complet</a> <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-job', 'batch_id' => absint( $row['batch_id'] ) ), admin_url( 'admin.php' ) ) ); ?>">Lot #<?php echo esc_html( $row['batch_id'] ); ?></a></p></details>
				</td>
				<?php if ( $can_view_all ) : ?><td><?php echo esc_html( $owner ? $owner->display_name : '#' . (int) $row['owner_id'] ); ?></td><?php endif; ?>
				<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-job', 'batch_id' => absint( $row['batch_id'] ) ), admin_url( 'admin.php' ) ) ); ?>">#<?php echo esc_html( $row['batch_id'] ); ?></a></td>
				<td class="msrwa-batch-status"><?php echo esc_html( MSRWA_Lists::state_labels()[ MSRWA_Presentation::state( $row['status'] ) ] ); ?></td>
				<td><?php echo esc_html( MSRWA_Lists::stage_labels()[ $row['stage'] ] ?? $row['stage'] ); ?></td>
				<td><?php echo esc_html( $row['attempts'] . ' · retry ' . $row['retry_attempts'] . '/3' ); ?></td>
				<td><?php echo esc_html( $row['correction_cycles'] . ' / ' . absint( $settings['max_corrections'] ) ); ?></td>
				<td><?php echo esc_html( number_format_i18n( (int) $row['calls']['calls'] ) ); ?></td>
				<td><?php echo esc_html( number_format_i18n( (int) $row['calls']['input_tokens'] + (int) $row['calls']['output_tokens'] ) ); ?></td>
				<td><?php echo esc_html( number_format_i18n( (float) $row['cost_estimate'], 4 ) ); ?> $</td>
				<td><?php echo esc_html( human_time_diff( 0, (int) $row['duration_seconds'] ) ); ?></td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody></table></div><?php self::render_pagination( $args, $result ); ?></section>
		<?php self::render_batch_panel( $args ); ?>
		<?php
	}

	/** Batch controls stay reachable from the jobs view without crowding it. */
	private static function render_batch_panel( $args ) {
		global $wpdb;
		$tables = MSRWA_DB::tables();
		$owner_clause = MSRWA_Lists::can_view_all() ? ( $args['author'] ? $wpdb->prepare( ' AND owner_id = %d', absint( $args['author'] ) ) : '' ) : $wpdb->prepare( ' AND owner_id = %d', get_current_user_id() );
		$recent_batches = $wpdb->get_results( "SELECT id,status,total,completed,created_at,updated_at FROM {$tables['batches']} WHERE 1=1{$owner_clause} ORDER BY id DESC LIMIT 20", ARRAY_A );
		$batch_views = array();
		if ( $recent_batches ) {
			$batch_ids = implode( ',', array_map( 'absint', array_column( $recent_batches, 'id' ) ) );
			$batch_jobs = $wpdb->get_results( "SELECT batch_id,status FROM {$tables['jobs']} WHERE batch_id IN ({$batch_ids})", ARRAY_A );
			$grouped = array();
			foreach ( $batch_jobs as $row ) { $grouped[ $row['batch_id'] ][] = $row; }
			foreach ( $recent_batches as $row ) { $batch_views[ $row['id'] ] = MSRWA_Presentation::batch( $grouped[ $row['id'] ] ?? array() ); }
		}
		?>
		<section class="msrwa-card"><details class="msrwa-batch-panel" <?php echo $args['batch'] ? 'open' : ''; ?>><summary>Lots récents — pause, reprise et annulation</summary><div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>Lot</th><th>État</th><th>Progression</th><th>Créé</th><th>Mis à jour</th><th>Actions</th></tr></thead><tbody><?php if ( empty( $recent_batches ) ) : ?><tr><td colspan="6">Aucun lot.</td></tr><?php else : foreach ( $recent_batches as $batch ) : ?><tr><td>#<?php echo esc_html( $batch['id'] ); ?></td><td class="msrwa-batch-status"><?php echo esc_html( MSRWA_Lists::state_labels()[ $batch_views[ $batch['id'] ]['state'] ] ); ?></td><td><?php echo esc_html( $batch_views[ $batch['id'] ]['finished'] . ' / ' . $batch['total'] ); ?></td><td><?php echo esc_html( $batch['created_at'] ); ?></td><td><?php echo esc_html( $batch['updated_at'] ); ?></td><td class="msrwa-actions"><a class="button" href="<?php echo esc_url( self::list_url( array( 'msrwa_view' => 'jobs', 'msrwa_batch' => absint( $batch['id'] ) ) ) ); ?>">Voir les jobs</a> <a class="button msrwa-batch-details" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-job', 'batch_id' => absint( $batch['id'] ) ), admin_url( 'admin.php' ) ) ); ?>">Détails</a> <button type="button" class="button msrwa-batch-action" data-action="pause" data-batch-id="<?php echo esc_attr( $batch['id'] ); ?>">Pause</button> <button type="button" class="button msrwa-batch-action" data-action="resume" data-batch-id="<?php echo esc_attr( $batch['id'] ); ?>">Reprendre</button> <button type="button" class="button msrwa-batch-action" data-action="cancel" data-batch-id="<?php echo esc_attr( $batch['id'] ); ?>">Annuler</button></td></tr><?php endforeach; endif; ?></tbody></table></div></details></section>
		<?php
	}

	public static function job_page() {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$settings = MSRWA_Settings::get();
		$batch_id = isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0;
		if ( ! $batch_id ) { wp_die( esc_html__( 'Lot introuvable.', 'ms-recipes-writer-ai' ) ); }
		global $wpdb;
		$tables = MSRWA_DB::tables();
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['batches']} WHERE id = %d", $batch_id ), ARRAY_A );
		$can_view_all = current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' );
		if ( ! $batch || (int) $batch['owner_id'] !== get_current_user_id() && ! $can_view_all ) { wp_die( esc_html__( 'Lot introuvable.', 'ms-recipes-writer-ai' ) ); }
		$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['jobs']} WHERE batch_id = %d ORDER BY id ASC", $batch_id ), ARRAY_A );
		$events = $wpdb->get_results( $wpdb->prepare( "SELECT event_type, job_id, payload_json, created_at FROM {$tables['events']} WHERE batch_id = %d ORDER BY id DESC LIMIT 80", $batch_id ), ARRAY_A );
		$calls = $wpdb->get_results( $wpdb->prepare( "SELECT job_id, provider, model, operation, status, input_tokens, output_tokens, cost_estimate, started_at FROM {$tables['calls']} WHERE batch_id = %d ORDER BY id DESC LIMIT 100", $batch_id ), ARRAY_A );
		?>
		<div class="wrap msrwa-wrap msrwa-job-page">
			<div class="msrwa-page-head">
				<div><h1>Détail du lot #<?php echo esc_html( $batch_id ); ?></h1><p class="description">Suivi durable des étapes, appels, coûts et actions. Les prompts, clés et chemins privés ne sont jamais affichés.</p></div>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ms-recipes-writer-ai' ) ); ?>">Retour aux lots</a>
			</div>
			<section class="msrwa-kpis msrwa-job-kpis">
				<?php $batch_view = MSRWA_Presentation::batch( $jobs ); ?><div><span>État du lot</span><strong><?php echo esc_html( $batch_view['state'] ); ?></strong></div><div><span>Qualité des articles</span><?php MSRWA_Presentation::render_quality( $batch_view['quality'] ); ?></div>
				<div><span>Progression</span><strong><?php echo esc_html( $batch_view['finished'] . ' / ' . $batch['total'] ); ?></strong></div>
				<div><span>Créé</span><strong><?php echo esc_html( $batch['created_at'] ); ?></strong></div>
				<div><span>Mis à jour</span><strong><?php echo esc_html( $batch['updated_at'] ); ?></strong></div>
			</section>
			<section class="msrwa-card">
				<div class="msrwa-section-head"><div><h2>Jobs</h2><p class="description">Chaque job peut être relancé seulement dans un état compatible. Annuler empêche les étapes suivantes ; les appels déjà acceptés restent potentiellement facturés.</p></div></div>
				<div class="msrwa-job-list">
				<?php foreach ( $jobs as $job ) :
					$input = json_decode( (string) $job['input_json'], true );
					$input = is_array( $input ) ? $input : array();
					$artifacts = json_decode( (string) $job['artifacts_json'], true );
					$artifacts = is_array( $artifacts ) ? $artifacts : array();
					$reference_count = ! empty( $input['reference_images'] ) && is_array( $input['reference_images'] ) ? count( $input['reference_images'] ) : 0;
					$quality = ! empty( $artifacts['quality_report'] ) && is_array( $artifacts['quality_report'] ) ? $artifacts['quality_report'] : array();
					$element_cycles = ! empty( $artifacts['correction_cycles'] ) && is_array( $artifacts['correction_cycles'] ) ? $artifacts['correction_cycles'] : array();
					$cycle_parts = array();
					foreach ( $element_cycles as $element => $cycles ) { $cycle_parts[] = sanitize_key( $element ) . ' ' . absint( $cycles ) . '/' . absint( $settings['max_corrections'] ); }
					$can_retry = in_array( $job['status'], array( 'failed', 'needs_review', 'uncertain', 'paused_budget', 'paused', 'awaiting_admin' ), true );
					$awaiting_association = 'awaiting_input' === $job['status'] && 'association' === $job['stage'];
					$draft_url = ! empty( $job['draft_post_id'] ) ? get_edit_post_link( (int) $job['draft_post_id'], 'raw' ) : '';
				?>
					<article class="msrwa-job-card" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
						<header><div><span class="msrwa-job-number">Job #<?php echo esc_html( $job['id'] ); ?></span><h3><?php echo esc_html( $job['title'] ); ?></h3></div><div class="msrwa-job-summary"><div class="msrwa-job-state"><small>État</small><span><?php echo esc_html( MSRWA_Presentation::state( $job['status'] ) ); ?></span><small>Étape : <?php echo esc_html( $job['stage'] ); ?></small></div><div class="msrwa-quality-cell"><small>Qualité de l’article</small><?php MSRWA_Presentation::render_quality( MSRWA_Presentation::quality( $job ) ); ?></div></div></header>
						<div class="msrwa-job-meta"><span>Exécutions : <?php echo esc_html( $job['attempts'] ); ?></span><span>Retries : <?php echo esc_html( $job['retry_attempts'] ); ?>/3</span><span>Corrections totales : <?php echo esc_html( $job['correction_cycles'] ); ?></span><?php if ( $cycle_parts ) : ?><span><?php echo esc_html( implode( ' · ', $cycle_parts ) ); ?></span><?php endif; ?><span>Références : <?php echo esc_html( $reference_count ); ?></span><span>Coût estimé : <?php echo esc_html( number_format_i18n( (float) $job['cost_estimate'], 4 ) ); ?> $</span><?php if ( $quality ) : ?><span>Structure : <?php echo esc_html( $quality['score'] ?? 0 ); ?> %</span><?php endif; ?></div>
						<?php if ( ! empty( $job['error_message'] ) ) : ?><div class="msrwa-job-notice"><strong><?php echo esc_html( $job['error_code'] ?: 'Information' ); ?></strong><p><?php echo esc_html( $job['error_message'] ); ?></p></div><?php endif; ?>
						<?php if ( $awaiting_association ) : ?><div class="msrwa-job-findings"><strong>Association à confirmer</strong><p>Confirmez que ce texte et ces références concernent bien cette recette. Le job reprendra ensuite à la recherche.</p><?php if ( ! empty( $artifacts['association']['notes'] ) && is_array( $artifacts['association']['notes'] ) ) : ?><ul><?php foreach ( array_slice( $artifacts['association']['notes'], 0, 4 ) as $note ) : ?><li><?php echo esc_html( $note ); ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
						<?php if ( $quality ) : ?><div class="msrwa-job-findings"><strong>Contrôle de structure : <?php echo esc_html( $quality['score'] ?? 0 ); ?> % — <?php echo empty( $quality['pass'] ) ? 'correction requise' : 'validé'; ?></strong><p><?php echo esc_html( number_format_i18n( $quality['metrics']['words'] ?? 0 ) ); ?> / <?php echo esc_html( number_format_i18n( $quality['benchmark']['words'] ?? 0 ) ); ?> mots ; <?php echo esc_html( number_format_i18n( $quality['metrics']['headings'] ?? 0 ) ); ?> / <?php echo esc_html( number_format_i18n( $quality['benchmark']['headings'] ?? 0 ) ); ?> titres ; contrat qualité DB.</p><?php if ( ! empty( $quality['findings'] ) && is_array( $quality['findings'] ) ) : ?><ul><?php foreach ( array_slice( $quality['findings'], 0, 5 ) as $finding ) : ?><li><?php echo esc_html( is_array( $finding ) ? ( $finding['field'] ?? 'qualité' ) . ' : ' . ( $finding['reason'] ?? '' ) : $finding ); ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
						<?php if ( ! empty( $artifacts['review']['findings'] ) && is_array( $artifacts['review']['findings'] ) ) : ?><div class="msrwa-job-findings"><strong>Dernière relecture</strong><ul><?php foreach ( array_slice( $artifacts['review']['findings'], 0, 6 ) as $finding ) : ?><li><?php echo esc_html( is_scalar( $finding ) ? $finding : ( $finding['reason'] ?? '' ) ); ?><?php if ( is_array( $finding ) && ! empty( $finding['fix'] ) && is_scalar( $finding['fix'] ) ) : ?><br><small>Conseil : <?php echo esc_html( $finding['fix'] ); ?></small><?php endif; ?></li><?php endforeach; ?></ul></div><?php endif; ?>
						<footer class="msrwa-job-actions"><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-job-detail', 'job_id' => absint( $job['id'] ) ), admin_url( 'admin.php' ) ) ); ?>">Détails complets</a><?php if ( $draft_url ) : ?><a class="button button-primary" href="<?php echo esc_url( $draft_url ); ?>">Ouvrir le brouillon</a><?php endif; ?><?php if ( $awaiting_association ) : ?><button type="button" class="button button-primary msrwa-job-action" data-action="association" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">Confirmer l’association</button><?php endif; ?><?php if ( $can_retry ) : ?><button type="button" class="button msrwa-job-action" data-action="retry" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">Relancer</button><?php endif; ?><?php if ( ! in_array( $job['status'], array( 'completed', 'cancelled' ), true ) ) : ?><button type="button" class="button msrwa-job-action" data-action="cancel" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">Annuler le job</button><?php endif; ?><span class="msrwa-job-action-status" role="status"></span></footer>
					</article>
				<?php endforeach; ?>
				</div>
			</section>
			<section class="msrwa-card"><div class="msrwa-section-head"><div><h2>Journal des étapes</h2><p class="description">80 événements les plus récents, sans contenu de prompt ni secrets.</p></div></div><div class="msrwa-event-list"><?php if ( empty( $events ) ) : ?><p class="description">Aucun événement enregistré.</p><?php else : foreach ( $events as $event ) : $payload = json_decode( (string) $event['payload_json'], true ); $payload = is_array( $payload ) ? $payload : array(); ?><div><strong><?php echo esc_html( $event['event_type'] ); ?></strong><span><?php echo esc_html( $event['created_at'] ); ?><?php echo $event['job_id'] ? ' — Job #' . esc_html( $event['job_id'] ) : ''; ?></span><?php if ( ! empty( $payload['code'] ) ) : ?><small><?php echo esc_html( $payload['code'] ); ?></small><?php endif; ?></div><?php endforeach; endif; ?></div></section>
			<section class="msrwa-card"><div class="msrwa-section-head"><div><h2>Appels et coûts</h2><p class="description">Historique technique et estimation capturée pour ce lot.</p></div></div><div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>Job</th><th>Fournisseur</th><th>Modèle</th><th>Opération</th><th>État</th><th>Tokens</th><th>Coût</th><th>Date</th></tr></thead><tbody><?php if ( empty( $calls ) ) : ?><tr><td colspan="8">Aucun appel fournisseur à ce stade.</td></tr><?php else : foreach ( $calls as $call ) : ?><tr><td>#<?php echo esc_html( $call['job_id'] ); ?></td><td><?php echo esc_html( $call['provider'] ); ?></td><td><?php echo esc_html( $call['model'] ); ?></td><td><?php echo esc_html( $call['operation'] ); ?></td><td><?php echo esc_html( $call['status'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $call['input_tokens'] + (int) $call['output_tokens'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (float) $call['cost_estimate'], 4 ) ); ?> $</td><td><?php echo esc_html( $call['started_at'] ); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
		</div>
		<?php
	}

	public static function job_detail_page() {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;
		global $wpdb;
		$t = MSRWA_DB::tables();
		$job = $job_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['jobs']} WHERE id = %d", $job_id ), ARRAY_A ) : null;
		$can_view_all = current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' );
		if ( ! $job || ( (int) $job['owner_id'] !== get_current_user_id() && ! $can_view_all ) ) { wp_die( esc_html__( 'Job introuvable.', 'ms-recipes-writer-ai' ) ); }
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['batches']} WHERE id = %d", $job['batch_id'] ), ARRAY_A );
		$events = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['events']} WHERE job_id = %d ORDER BY id DESC LIMIT 300", $job_id ), ARRAY_A );
		$calls = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['calls']} WHERE job_id = %d ORDER BY id DESC LIMIT 200", $job_id ), ARRAY_A );
		$artifacts = $wpdb->get_results( $wpdb->prepare( "SELECT artifact_key,version,status,content_json,content_hash,created_at FROM {$t['artifacts']} WHERE job_id = %d ORDER BY artifact_key ASC,version DESC LIMIT 200", $job_id ), ARRAY_A );
		$snapshots = $wpdb->get_results( $wpdb->prepare( "SELECT snapshot_type,version,data_json,data_hash,created_at FROM {$t['snapshots']} WHERE job_id = %d OR (batch_id = %d AND job_id = 0) ORDER BY id DESC LIMIT 200", $job_id, $job['batch_id'] ), ARRAY_A );
		// The queries above take the most recent rows; the screens read chronologically.
		$events = array_reverse( $events );
		$calls = array_reverse( $calls );
		$snapshots = array_reverse( $snapshots );
		$input = json_decode( (string) $job['input_json'], true );
		$models = json_decode( (string) $job['selected_models_json'], true );
		$legacy_artifacts = json_decode( (string) $job['artifacts_json'], true );
		$owner = get_userdata( (int) $job['owner_id'] );
		$draft_url = $job['draft_post_id'] ? get_edit_post_link( (int) $job['draft_post_id'], 'raw' ) : '';
		?>
		<div class="wrap msrwa-wrap msrwa-job-debug">
			<div class="msrwa-page-head"><div><h1>Job #<?php echo esc_html( $job_id ); ?> — <?php echo esc_html( $job['title'] ); ?></h1><p class="description">Historique durable : données, versions, décisions, appels, tokens, coûts et résultats. Secrets et chemins privés masqués.</p></div><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-job', 'batch_id' => $job['batch_id'] ), admin_url( 'admin.php' ) ) ); ?>">Retour au lot</a></div>
			<section class="msrwa-kpis msrwa-stats-kpis"><div><span>État</span><strong><?php echo esc_html( MSRWA_Presentation::state( $job['status'] ) ); ?></strong></div><div><span>Qualité de l’article</span><?php MSRWA_Presentation::render_quality( MSRWA_Presentation::quality( $job ) ); ?></div><div><span>Étape</span><strong><?php echo esc_html( $job['stage'] ); ?></strong></div><div><span>Coût</span><strong><?php echo esc_html( number_format_i18n( (float) $job['cost_estimate'], 4 ) ); ?> $</strong></div><div><span>Tokens</span><strong><?php echo esc_html( number_format_i18n( array_sum( wp_list_pluck( $calls, 'input_tokens' ) ) + array_sum( wp_list_pluck( $calls, 'output_tokens' ) ) ) ); ?></strong></div><div><span>Corrections</span><strong><?php echo esc_html( $job['correction_cycles'] ); ?></strong></div><div><span>Exécutions</span><strong><?php echo esc_html( $job['attempts'] ); ?></strong></div><div><span>Retries étape</span><strong><?php echo esc_html( $job['retry_attempts'] ); ?>/3</strong></div></section>
			<section class="msrwa-card"><h2>Identité et état</h2><div class="msrwa-fields"><dl><dt>Lot</dt><dd>#<?php echo esc_html( $job['batch_id'] ); ?></dd></dl><dl><dt>Éditeur</dt><dd><?php echo esc_html( $owner ? $owner->display_name : '#' . $job['owner_id'] ); ?></dd></dl><dl><dt>Créé</dt><dd><?php echo esc_html( $job['created_at'] ); ?></dd></dl><dl><dt>Mis à jour</dt><dd><?php echo esc_html( $job['updated_at'] ); ?></dd></dl><dl><dt>Diagnostic technique</dt><dd><?php echo esc_html( $job['status'] ); ?></dd></dl><dl><dt>Erreur</dt><dd><?php echo esc_html( trim( $job['error_code'] . ' ' . $job['error_message'] ) ?: 'Aucune' ); ?></dd></dl><dl><dt>Brouillon</dt><dd><?php if ( $draft_url ) : ?><a href="<?php echo esc_url( $draft_url ); ?>">Ouvrir #<?php echo esc_html( $job['draft_post_id'] ); ?></a><?php else : ?>Non créé<?php endif; ?></dd></dl></div></section>
			<section class="msrwa-card"><h2>Aide à la relecture</h2><?php $editorial_json = $wpdb->get_var( $wpdb->prepare( "SELECT content_json FROM {$t['artifacts']} WHERE job_id=%d AND artifact_key='editorial_review' AND status='current' ORDER BY version DESC LIMIT 1", $job_id ) ); self::render_editorial_report( json_decode( (string) $editorial_json, true ) ); ?></section>
			<div class="msrwa-detail-grid"><section class="msrwa-card"><h2>Entrée originale</h2><?php self::render_debug_json( is_array( $input ) ? $input : array() ); ?></section><section class="msrwa-card"><h2>Plan de modèles</h2><?php self::render_debug_json( is_array( $models ) ? $models : array() ); ?></section></div>
			<section class="msrwa-card"><div class="msrwa-section-head"><div><h2>Appels API</h2><p class="description">Tous appels, identifiants fournisseur, HTTP, tokens, coût et certitude.</p></div></div><div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>#</th><th>Opération</th><th>Fournisseur / modèle</th><th>Résultat API / HTTP</th><th>Tokens entrée / sortie</th><th>Coût</th><th>Requête</th><th>Début / fin</th></tr></thead><tbody><?php if ( ! $calls ) : ?><tr><td colspan="8">Aucun appel.</td></tr><?php else : foreach ( $calls as $call ) : ?><tr><td><?php echo esc_html( $call['id'] ); ?></td><td><?php echo esc_html( $call['operation'] ); ?></td><td><?php echo esc_html( $call['provider'] . ' / ' . $call['model'] ); ?></td><td><?php echo esc_html( $call['status'] . ' / ' . $call['http_status'] ); ?><?php if ( $call['error_code'] ) : ?><br><small><?php echo esc_html( $call['error_code'] ); ?></small><?php endif; ?></td><td><?php echo esc_html( number_format_i18n( $call['input_tokens'] ) . ' / ' . number_format_i18n( $call['output_tokens'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (float) $call['cost_estimate'], 6 ) ); ?> $<?php echo $call['uncertain'] ? ' estimé' : ''; ?></td><td><code><?php echo esc_html( $call['request_id'] ?: substr( $call['payload_hash'], 0, 12 ) ); ?></code></td><td><?php echo esc_html( $call['started_at'] ); ?><br><?php echo esc_html( $call['finished_at'] ?: 'en cours' ); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
			<section class="msrwa-card"><h2>Entrées et sorties API</h2><p class="description">Payloads diagnostiques persistés pour chaque appel. Données binaires et secrets exclus.</p><?php if ( ! $calls ) : ?><p>Aucun appel.</p><?php else : foreach ( $calls as $call ) : $request_payload = json_decode( (string) ( $call['request_json'] ?? '' ), true ); $response_payload = json_decode( (string) ( $call['response_json'] ?? '' ), true ); ?><details><summary>#<?php echo esc_html( $call['id'] ); ?> — <?php echo esc_html( $call['operation'] ); ?> — <?php echo esc_html( $call['status'] ); ?></summary><div class="msrwa-detail-grid"><div><h4>Entrée</h4><?php self::render_debug_json( is_array( $request_payload ) ? $request_payload : array() ); ?></div><div><h4>Sortie</h4><?php self::render_debug_json( is_array( $response_payload ) ? $response_payload : array() ); ?></div></div></details><?php endforeach; endif; ?></section>
		<section class="msrwa-card"><h2>Artefacts versionnés</h2><?php if ( ! $artifacts && is_array( $legacy_artifacts ) ) : foreach ( $legacy_artifacts as $key => $content ) : ?><details><summary><?php echo esc_html( $key ); ?></summary><?php self::render_debug_json( $content ); ?></details><?php endforeach; elseif ( ! $artifacts ) : ?><p>Aucun artefact.</p><?php else : foreach ( $artifacts as $artifact ) : $content = json_decode( (string) $artifact['content_json'], true ); ?><details><summary><?php echo esc_html( $artifact['artifact_key'] ); ?> v<?php echo esc_html( $artifact['version'] ); ?> — <?php echo esc_html( $artifact['status'] ); ?> — <?php echo esc_html( $artifact['created_at'] ); ?></summary><?php self::render_debug_json( $content ); ?><?php if ( is_array( $content ) && ! empty( $content['attachment_id'] ) ) : echo wp_get_attachment_image( absint( $content['attachment_id'] ), 'medium', false, array( 'class' => 'msrwa-debug-image' ) ); endif; ?></details><?php endforeach; endif; ?></section>
			<section class="msrwa-card"><h2>Snapshots</h2><?php if ( ! $snapshots ) : ?><p>Aucun snapshot.</p><?php else : foreach ( $snapshots as $snapshot ) : ?><details><summary><?php echo esc_html( $snapshot['snapshot_type'] ); ?> v<?php echo esc_html( $snapshot['version'] ); ?> — <?php echo esc_html( $snapshot['created_at'] ); ?></summary><?php if ( 'settings' === $snapshot['snapshot_type'] && ! current_user_can( 'manage_options' ) ) : ?><p>Snapshot configuration réservé aux administrateurs.</p><?php else : self::render_debug_json( json_decode( (string) $snapshot['data_json'], true ) ); endif; ?></details><?php endforeach; endif; ?></section>
			<section class="msrwa-card"><h2>Chronologie des décisions</h2><div class="msrwa-event-list"><?php if ( ! $events ) : ?><p>Aucun événement.</p><?php else : foreach ( $events as $event ) : ?><div><strong><?php echo esc_html( $event['event_type'] ); ?></strong><span><?php echo esc_html( $event['created_at'] ); ?></span><details><summary>Données</summary><?php self::render_debug_json( json_decode( (string) $event['payload_json'], true ) ); ?></details></div><?php endforeach; endif; ?></div></section>
		</div><?php
	}

	private static function render_debug_json( $value ) {
		$value = self::redact_debug_value( $value );
		echo '<pre class="msrwa-debug-json">' . esc_html( wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
	}

	private static function redact_debug_value( $value, $parent = '' ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$name = strtolower( (string) $key );
				if ( preg_match( '/(?:api.?key|secret|password|authorization|lock_token)/i', $name ) ) { $out[ $key ] = '[masqué]'; continue; }
				if ( 'path' === $name || substr( $name, -5 ) === '_path' ) { $out[ $key ] = is_string( $item ) ? basename( $item ) : '[chemin masqué]'; continue; }
				$out[ $key ] = self::redact_debug_value( $item, $name );
			}
			return $out;
		}
		return $value;
	}

	public static function stats_page() {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$owner_id = current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' ) ? 0 : get_current_user_id();
		$range = isset( $_GET['msrwa_range'] ) ? sanitize_key( wp_unslash( $_GET['msrwa_range'] ) ) : '7';
		$range = in_array( $range, array( 'today', '7', '30' ), true ) ? $range : '7';
		$days = 'today' === $range ? 1 : absint( $range );
		$stats = MSRWA_Stats::summary( $days, $owner_id );
		$details = MSRWA_Stats::details( $days, $owner_id );
		$performance = isset( $details['performance'] ) && is_array( $details['performance'] ) ? $details['performance'] : array();
		$call_count = array_sum( array_column( $stats['calls'], 'count' ) );
		$completed = 0;
		foreach ( $stats['jobs'] as $status => $count ) { if ( 'completed' === MSRWA_Presentation::state( $status ) ) { $completed += (int) $count; } }
		$average_duration = ! empty( $performance['completed'] ) ? human_time_diff( 0, (int) round( $performance['average_completed_seconds'] ?? 0 ) ) : '—';
		$failed = ( isset( $stats['jobs']['failed'] ) ? (int) $stats['jobs']['failed'] : 0 ) + ( isset( $stats['jobs']['needs_review'] ) ? (int) $stats['jobs']['needs_review'] : 0 );
		$from = gmdate( 'Y-m-d', time() - max( 0, $days - 1 ) * DAY_IN_SECONDS );
		$to = gmdate( 'Y-m-d' );
		$export_base = rest_url( 'msrwa/v1/export' );
		?>
		<div class="wrap msrwa-wrap msrwa-stats-page"><div class="msrwa-page-head"><div><h1>Statistiques</h1><p class="description"><?php echo esc_html( $owner_id ? 'Vos résultats, coûts et événements.' : 'Résultats, coûts et événements de tous les éditeurs.' ); ?></p></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ms-recipes-writer-ai' ) ); ?>">Créer un article</a></div><nav class="msrwa-range-tabs" aria-label="Période"><a class="<?php echo 'today' === $range ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-stats', 'msrwa_range' => 'today' ), admin_url( 'admin.php' ) ) ); ?>">Aujourd’hui</a><a class="<?php echo '7' === $range ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-stats', 'msrwa_range' => '7' ), admin_url( 'admin.php' ) ) ); ?>">7 jours</a><a class="<?php echo '30' === $range ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-stats', 'msrwa_range' => '30' ), admin_url( 'admin.php' ) ) ); ?>">30 jours</a></nav><section class="msrwa-kpis msrwa-stats-kpis"><div><span>Jobs</span><strong><?php echo esc_html( $stats['total_jobs'] ); ?></strong></div><div><span>Terminés</span><strong><?php echo esc_html( $completed ); ?></strong></div><div><span>À vérifier / échecs</span><strong><?php echo esc_html( $failed ); ?></strong></div><div><span>Appels</span><strong><?php echo esc_html( $call_count ); ?></strong></div><div><span>Coût estimé</span><strong><?php echo esc_html( number_format_i18n( (float) $stats['estimated_cost_usd'], 4 ) ); ?> $</strong></div><div><span>Coût / terminé</span><strong><?php echo esc_html( number_format_i18n( (float) ( $performance['cost_per_completed'] ?? 0 ), 4 ) ); ?> $</strong></div><div><span>Durée moy. terminée</span><strong><?php echo esc_html( $average_duration ); ?></strong></div></section><section class="msrwa-card"><div class="msrwa-section-head"><div><h2>Appels par fournisseur et modèle</h2><p class="description">Les coûts sont estimés selon le tarif capturé au moment de l’appel ; les réponses incertaines restent identifiées dans le journal.</p></div><div><a class="button" href="<?php echo esc_url( add_query_arg( array( 'dataset' => 'jobs', 'from' => $from, 'to' => $to, 'format' => 'csv' ), $export_base ) ); ?>">Jobs CSV</a> <a class="button" href="<?php echo esc_url( add_query_arg( array( 'dataset' => 'calls', 'from' => $from, 'to' => $to, 'format' => 'csv' ), $export_base ) ); ?>">Appels CSV</a> <a class="button" href="<?php echo esc_url( add_query_arg( array( 'dataset' => 'events', 'from' => $from, 'to' => $to, 'format' => 'csv' ), $export_base ) ); ?>">Journal CSV</a></div></div><div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>Fournisseur</th><th>Modèle</th><th>Étape</th><th>Résultat API</th><th>Appels</th><th>Entrée</th><th>Sortie</th><th>Coût estimé</th></tr></thead><tbody><?php if ( empty( $stats['calls'] ) ) : ?><tr><td colspan="8">Aucun appel pour cette période.</td></tr><?php else : foreach ( $stats['calls'] as $call ) : ?><tr><td><?php echo esc_html( $call['provider'] ); ?></td><td><?php echo esc_html( $call['model'] ); ?></td><td><?php echo esc_html( $call['operation'] ); ?></td><td><?php echo esc_html( $call['status'] ); ?></td><td><?php echo esc_html( $call['count'] ); ?></td><td><?php echo esc_html( number_format_i18n( $call['input_tokens'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $call['output_tokens'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (float) $call['cost'], 4 ) ); ?> $</td></tr><?php endforeach; endif; ?></tbody></table></div></section></div>
		<?php self::render_stats_details( $details, ! $owner_id ); ?>
		<?php
	}

	private static function render_stats_details( $details, $show_editors ) {
		$details = is_array( $details ) ? $details : array();
		$state_totals = array();
		foreach ( $details['statuses'] ?? array() as $row ) {
			$state = MSRWA_Presentation::state( $row['status'] );
			if ( ! isset( $state_totals[ $state ] ) ) { $state_totals[ $state ] = array( 'status' => $state, 'count' => 0, 'cost' => 0 ); }
			$state_totals[ $state ]['count'] += (int) $row['count']; $state_totals[ $state ]['cost'] += (float) $row['cost'];
		}
		$details['statuses'] = array_values( $state_totals );
		?>
		<div class="wrap msrwa-wrap msrwa-stats-details">
			<?php $quality = isset( $details['quality'] ) && is_array( $details['quality'] ) ? $details['quality'] : array(); ?>
			<section class="msrwa-kpis msrwa-stats-kpis"><div><span>Articles produits</span><strong><?php echo esc_html( number_format_i18n( (int) ( $quality['articles'] ?? 0 ) ) ); ?></strong></div><div><span>Score de structure moyen</span><strong><?php echo esc_html( number_format_i18n( (float) ( $quality['average_score'] ?? 0 ), 1 ) ); ?> %</strong></div><div><span>Structure conforme</span><strong><?php echo esc_html( number_format_i18n( (float) ( $quality['pass_rate'] ?? 0 ), 1 ) ); ?> %</strong></div><div><span>Mots moyens</span><strong><?php echo esc_html( number_format_i18n( (float) ( $quality['average_words'] ?? 0 ), 0 ) ); ?></strong></div><div><span>Articles sous coût cible</span><strong><?php echo esc_html( number_format_i18n( (int) ( $quality['within_target_cost'] ?? 0 ) ) ); ?></strong></div><div><span>Coût cible</span><strong><?php echo esc_html( number_format_i18n( (float) ( $quality['target_cost_usd'] ?? 0.10 ), 2 ) ); ?> $</strong></div></section>
			<section class="msrwa-card"><div class="msrwa-section-head"><div><h2>Activité par fonctionnalité</h2><p class="description">Chaque appel fournisseur est rattaché à l’étape qui l’a demandé.</p></div></div><div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>Fonctionnalité</th><th>Résultat API</th><th>Appels</th><th>Entrée</th><th>Sortie</th><th>Coût estimé</th></tr></thead><tbody><?php if ( empty( $details['operations'] ) ) : ?><tr><td colspan="6">Aucune activité pour cette période.</td></tr><?php else : foreach ( $details['operations'] as $row ) : ?><tr><td><?php echo esc_html( $row['operation'] ); ?></td><td><?php echo esc_html( $row['status'] ); ?></td><td><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['input_tokens'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['output_tokens'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (float) $row['cost'], 4 ) ); ?> $</td></tr><?php endforeach; endif; ?></tbody></table></div></section>
			<div class="msrwa-detail-grid"><section class="msrwa-card"><h2>État des jobs</h2><div class="msrwa-mini-list"><?php if ( empty( $details['statuses'] ) ) : ?><p class="description">Aucun job pour cette période.</p><?php else : foreach ( $details['statuses'] as $row ) : ?><div><strong><?php echo esc_html( $row['status'] ); ?></strong><span><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?> job(s)</span><small><?php echo esc_html( number_format_i18n( (float) $row['cost'], 4 ) ); ?> $</small></div><?php endforeach; endif; ?></div></section><section class="msrwa-card"><h2>Événements</h2><div class="msrwa-mini-list"><?php if ( empty( $details['events'] ) ) : ?><p class="description">Aucun événement pour cette période.</p><?php else : foreach ( array_slice( $details['events'], 0, 12 ) as $row ) : ?><div><strong><?php echo esc_html( $row['event_type'] ); ?></strong><span><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?> occurrence(s)</span></div><?php endforeach; endif; ?></div></section></div>
			<?php if ( $show_editors ) : ?><section class="msrwa-card"><div class="msrwa-section-head"><div><h2>Activité par éditeur</h2><p class="description">Visible uniquement aux administrateurs.</p></div></div><div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>Éditeur</th><th>Jobs</th><th>Terminés</th><th>Coût estimé</th></tr></thead><tbody><?php if ( empty( $details['editors'] ) ) : ?><tr><td colspan="4">Aucune activité pour cette période.</td></tr><?php else : foreach ( $details['editors'] as $editor ) : ?><tr><td><?php echo esc_html( $editor['name'] ); ?></td><td><?php echo esc_html( number_format_i18n( $editor['jobs'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $editor['completed'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (float) $editor['cost'], 4 ) ); ?> $</td></tr><?php endforeach; endif; ?></tbody></table></div></section><?php endif; ?>
			<section class="msrwa-card"><div class="msrwa-section-head"><div><h2>Jobs récents</h2><p class="description">Coût, qualité de l’article produit, corrections et accès au diagnostic complet.</p></div></div><div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>Job</th><th>État</th><th>Qualité de l’article</th><th>Corrections</th><th>Coût</th><th>Durée</th><th></th></tr></thead><tbody><?php if ( empty( $details['recent_jobs'] ) ) : ?><tr><td colspan="7">Aucun job.</td></tr><?php else : foreach ( $details['recent_jobs'] as $job ) : $duration = max( 0, strtotime( $job['updated_at'] ) - strtotime( $job['created_at'] ) ); ?><tr><td>#<?php echo esc_html( $job['id'] ); ?> — <?php echo esc_html( $job['title'] ); ?></td><td><?php echo esc_html( MSRWA_Presentation::state( $job['status'] ) ); ?></td><td><?php MSRWA_Presentation::render_quality( $job['display_quality'] ); ?></td><td><?php echo esc_html( $job['correction_cycles'] ); ?></td><td><?php echo esc_html( number_format_i18n( (float) $job['cost_estimate'], 4 ) ); ?> $</td><td><?php echo esc_html( human_time_diff( 0, $duration ) ); ?></td><td><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-job-detail', 'job_id' => $job['id'] ), admin_url( 'admin.php' ) ) ); ?>">Détails</a></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
		</div>
		<?php
	}

	private static function render_settings_database_sections() {
		self::render_execution_settings( MSRWA_Settings::get() );
		global $wpdb;
		$t = MSRWA_DB::tables();
		$providers = MSRWA_Catalog::providers();
		$models = MSRWA_Catalog::models( true );
		$prompt_versions = MSRWA_Settings::prompt_versions();
		$history = MSRWA_Settings::history( 30 );
		$counts = array();
		foreach ( $t as $key => $table ) { $counts[ $key ] = MSRWA_DB::table_exists( $table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0; }
		?>
		<section class="msrwa-card" id="msrwa-settings-providers"><h2>Fournisseurs et API</h2><p class="description">Configurez les endpoints, délais et méthodes d’authentification de chaque fournisseur.</p><?php foreach ( $providers as $provider ) : ?><details open><summary><?php echo esc_html( $provider['label'] . ' — ' . $provider['provider_key'] ); ?></summary><label><input type="checkbox" name="msrwa_catalog[providers][<?php echo esc_attr( $provider['provider_key'] ); ?>][enabled]" value="1" <?php checked( $provider['enabled'], 1 ); ?>> Fournisseur actif</label><div class="msrwa-fields"><?php foreach ( $provider['api_config'] as $key => $value ) : ?><label><?php echo esc_html( $key ); ?><input type="text" name="msrwa_catalog[providers][<?php echo esc_attr( $provider['provider_key'] ); ?>][api_config][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>"></label><?php endforeach; ?></div><p class="description">Capacités : <?php echo esc_html( implode( ', ', $provider['capabilities'] ) ); ?>. Adaptateur : <?php echo esc_html( $provider['adapter_class'] ); ?>.</p></details><?php endforeach; ?></section>
		<section class="msrwa-card"><h2>Modèles, capacités et tarifs</h2><p class="description">Tarifs en USD par million de tokens. Vérifier la source officielle avant modification.</p><?php foreach ( $models as $provider => $provider_models ) : ?><h3><?php echo esc_html( ucfirst( $provider ) ); ?></h3><?php foreach ( $provider_models as $model_id => $model ) : ?><details><summary><?php echo esc_html( $model['label'] . ' — ' . $model_id ); ?></summary><div class="msrwa-fields"><label>Libellé<input type="text" name="msrwa_catalog[models][<?php echo esc_attr( $provider ); ?>][<?php echo esc_attr( $model_id ); ?>][label]" value="<?php echo esc_attr( $model['label'] ); ?>"></label><label>Entrée / 1M<input type="number" min="0" step="0.000001" name="msrwa_catalog[models][<?php echo esc_attr( $provider ); ?>][<?php echo esc_attr( $model_id ); ?>][input]" value="<?php echo esc_attr( $model['input'] ?? 0 ); ?>"></label><label>Sortie / 1M<input type="number" min="0" step="0.000001" name="msrwa_catalog[models][<?php echo esc_attr( $provider ); ?>][<?php echo esc_attr( $model_id ); ?>][output]" value="<?php echo esc_attr( $model['output'] ?? 0 ); ?>"></label><label>Entrée image / 1M<input type="number" min="0" step="0.000001" name="msrwa_catalog[models][<?php echo esc_attr( $provider ); ?>][<?php echo esc_attr( $model_id ); ?>][image_input]" value="<?php echo esc_attr( $model['image_input'] ?? 0 ); ?>"></label><label>Source officielle<input type="url" name="msrwa_catalog[models][<?php echo esc_attr( $provider ); ?>][<?php echo esc_attr( $model_id ); ?>][source_url]" value="<?php echo esc_attr( $model['source'] ?? '' ); ?>"></label></div><p><?php foreach ( array( 'stable' => 'Stable', 'enabled' => 'Actif', 'text' => 'Texte', 'vision' => 'Vision', 'web_search' => 'Recherche web', 'image_generation' => 'Image' ) as $capability => $label ) : ?><label class="msrwa-inline-check"><input type="checkbox" name="msrwa_catalog[models][<?php echo esc_attr( $provider ); ?>][<?php echo esc_attr( $model_id ); ?>][<?php echo esc_attr( $capability ); ?>]" value="1" <?php checked( ! empty( $model[ $capability ] ) ); ?>> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></p><h4>Paramètres API du modèle</h4><div class="msrwa-fields"><?php foreach ( (array) ( $model['api_specifics'] ?? array( 'identifier' => $model_id ) ) as $specific_key => $specific_value ) : if ( ! is_scalar( $specific_value ) ) { continue; } ?><label><?php echo esc_html( $specific_key ); ?><input type="text" name="msrwa_catalog[models][<?php echo esc_attr( $provider ); ?>][<?php echo esc_attr( $model_id ); ?>][api_specifics][<?php echo esc_attr( $specific_key ); ?>]" value="<?php echo esc_attr( $specific_value ); ?>"></label><?php endforeach; ?></div></details><?php endforeach; endforeach; ?></section>
		<section class="msrwa-card" id="msrwa-settings-history"><h2>Versions prompts</h2><div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>Prompt</th><th>Version</th><th>État</th><th>Auteur</th><th>Date</th></tr></thead><tbody><?php foreach ( $prompt_versions as $row ) : $user = get_userdata( (int) $row['created_by'] ); ?><tr><td><?php echo esc_html( $row['label'] ); ?><br><code><?php echo esc_html( $row['prompt_key'] ); ?></code></td><td><?php echo esc_html( $row['version'] ); ?></td><td><?php echo $row['is_active'] ? 'Active' : 'Archivée'; ?></td><td><?php echo esc_html( $user ? $user->display_name : 'Système' ); ?></td><td><?php echo esc_html( $row['created_at'] ); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
		<section class="msrwa-card"><h2>Historique configuration</h2><div class="msrwa-table-scroll"><table class="widefat striped"><thead><tr><th>Groupe</th><th>Clé</th><th>Source</th><th>Auteur</th><th>Date</th></tr></thead><tbody><?php if ( ! $history ) : ?><tr><td colspan="5">Aucune modification.</td></tr><?php else : foreach ( $history as $row ) : $user = get_userdata( (int) $row['changed_by'] ); ?><tr><td><?php echo esc_html( $row['group_name'] ); ?></td><td><code><?php echo esc_html( $row['setting_key'] ); ?></code></td><td><?php echo esc_html( $row['change_source'] ); ?></td><td><?php echo esc_html( $user ? $user->display_name : 'Système' ); ?></td><td><?php echo esc_html( $row['created_at'] ); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
		<section class="msrwa-card"><h2>Inventaire des tables</h2><div class="msrwa-fields"><?php foreach ( $counts as $key => $count ) : ?><dl><dt><?php echo esc_html( $key ); ?></dt><dd><?php echo esc_html( number_format_i18n( $count ) ); ?> lignes</dd></dl><?php endforeach; ?></div><p class="description">Entrées, sorties, artefacts et snapshots restent versionnés. Anciennes versions conservées selon politique de rétention.</p></section>
		<?php
	}

	private static function render_execution_settings( $s ) {
		?><section class="msrwa-card"><h2>Cadrage Facebook</h2><div class="msrwa-fields"><label>Ajustement au ratio<select name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[facebook_image_fit]"><option value="contain" <?php selected( $s['facebook_image_fit'], 'contain' ); ?>>Image entière, marges si nécessaire</option><option value="cover" <?php selected( $s['facebook_image_fit'], 'cover' ); ?>>Recadrage centré</option></select></label><label>Couleur des marges<input type="color" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[image_padding_color]" value="<?php echo esc_attr( $s['image_padding_color'] ); ?>"></label></div><p class="description">Le mode image entière préserve les six étapes du collage.</p></section><?php
		$fields = array( 'web_search_max_tool_calls' => 'Recherche web : appels maximum par requête', 'research_max_output_tokens' => 'Recherche : tokens de sortie', 'association_max_output_tokens' => 'Association : tokens de sortie', 'canonical_max_output_tokens' => 'Recette : tokens de sortie', 'router_max_output_tokens' => 'Sélection des modèles : tokens de sortie', 'vision_max_output_tokens' => 'Analyse visuelle : tokens de sortie', 'image_review_max_output_tokens' => 'Relecture image : tokens de sortie', 'research_fallback_max_results' => 'Recherche de secours : résultats maximum', 'text_reserve_margin_usd' => 'Marge de réservation texte (USD)', 'research_fallback_cost_usd' => 'Recherche de secours : coût par appel (USD)' );
		?><section class="msrwa-card"><h2>Exécution avancée</h2><div class="msrwa-fields"><?php foreach ( $fields as $key => $label ) : ?><label><?php echo esc_html( $label ); ?><input type="number" min="0" step="<?php echo false !== strpos( $key, 'usd' ) ? '0.001' : '1'; ?>" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>"></label><?php endforeach; ?></div></section><?php
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$s = MSRWA_Settings::get();
		$health = MSRWA_Queue::health();
		$benchmark = MSRWA_Quality::benchmark( $s );
		?><div class="wrap msrwa-wrap"><h1>MS Recipes Writer AI — Configuration</h1><p class="description">Gérez les modèles, prompts, coûts, limites et règles qualité.</p><nav class="msrwa-range-tabs" aria-label="Sections"><a href="#msrwa-settings-quality">Qualité</a><a href="#msrwa-settings-providers">Fournisseurs et modèles</a><a href="#msrwa-settings-costs">Budgets</a><a href="#msrwa-settings-history">Historique</a></nav><?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Configuration enregistrée.</p></div><?php endif; ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="msrwa_save_settings"><?php wp_nonce_field( 'msrwa_save_settings' ); ?><div class="msrwa-card"><h2>Mode et limites</h2><label>Mode imposé<select name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[mode]"><option value="automatic" <?php selected( $s['mode'], 'automatic' ); ?>>Automatique — équilibre qualité/coût</option><option value="manual" <?php selected( $s['mode'], 'manual' ); ?>>Manuel — réglages administrateur</option></select></label><div class="msrwa-fields"><label>Lot maximum<input type="number" min="1" max="50" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[max_batch]" value="<?php echo esc_attr( $s['max_batch'] ); ?>"></label><label>Simultané maximum<input type="number" min="1" max="4" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[max_concurrency]" value="<?php echo esc_attr( $s['max_concurrency'] ); ?>"></label><label>Corrections maximum<input type="number" min="0" max="2" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[max_corrections]" value="<?php echo esc_attr( $s['max_corrections'] ); ?>"></label></div></div>
		<div class="msrwa-card" id="msrwa-settings-quality"><h2>Contrat qualité</h2><p class="description">Critères autonomes appliqués à chaque recette.</p><div class="msrwa-fields"><label>Score minimum /100<input type="number" min="1" max="100" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[quality_min_score]" value="<?php echo esc_attr( $s['quality_min_score'] ); ?>"></label><label>Mots minimum<input type="number" min="300" max="8000" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[quality_min_words]" value="<?php echo esc_attr( $s['quality_min_words'] ); ?>"></label><label>Mots maximum<input type="number" min="500" max="10000" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[quality_max_words]" value="<?php echo esc_attr( $s['quality_max_words'] ); ?>"></label><label>Titres h2/h3 minimum<input type="number" min="3" max="80" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[quality_min_headings]" value="<?php echo esc_attr( $s['quality_min_headings'] ); ?>"></label><label>Paragraphes minimum<input type="number" min="5" max="150" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[quality_min_paragraphs]" value="<?php echo esc_attr( $s['quality_min_paragraphs'] ); ?>"></label><label>Ingrédients indicatifs<input type="number" min="1" max="50" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[quality_min_ingredients]" value="<?php echo esc_attr( $s['quality_min_ingredients'] ); ?>"></label><label>Étapes indicatives<input type="number" min="1" max="40" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[quality_min_steps]" value="<?php echo esc_attr( $s['quality_min_steps'] ); ?>"></label><label>Sortie article max (tokens)<input type="number" min="1000" max="20000" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[article_max_output_tokens]" value="<?php echo esc_attr( $s['article_max_output_tokens'] ); ?>"></label><label>Sortie relecture max (tokens)<input type="number" min="500" max="10000" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[review_max_output_tokens]" value="<?php echo esc_attr( $s['review_max_output_tokens'] ); ?>"></label><label>Mots minimum pour division<input type="number" min="300" max="8000" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[article_pagination_min_words]" value="<?php echo esc_attr( $s['article_pagination_min_words'] ); ?>"></label><label>Position division (%)<input type="number" min="30" max="70" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[article_pagination_split_percent]" value="<?php echo esc_attr( $s['article_pagination_split_percent'] ); ?>"></label></div><p><label><input type="checkbox" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[article_pagination_enabled]" value="1" <?php checked( $s['article_pagination_enabled'], 1 ); ?>> Diviser les articles en deux pages WordPress</label></p><p class="description">Contrat actuel : <?php echo esc_html( number_format_i18n( $benchmark['words'] ) ); ?> mots, <?php echo esc_html( number_format_i18n( $benchmark['headings'] ) ); ?> titres et <?php echo esc_html( number_format_i18n( $benchmark['paragraphs'] ) ); ?> paragraphes minimum.</p></div>
		<div class="msrwa-card msrwa-queue-health"><h2>Santé de la file</h2><p class="description">État actuel : <strong><?php echo esc_html( $health['status'] ); ?></strong> — moteur : <?php echo esc_html( $health['driver'] ); ?>.</p><div class="msrwa-fields"><dl><dt>En attente</dt><dd><?php echo esc_html( number_format_i18n( $health['waiting'] ) ); ?></dd></dl><dl><dt>En cours</dt><dd><?php echo esc_html( number_format_i18n( $health['running'] ) ); ?></dd></dl><dl><dt>Workers expirés</dt><dd><?php echo esc_html( number_format_i18n( $health['expired_workers'] ) ); ?></dd></dl><dl><dt>À vérifier</dt><dd><?php echo esc_html( number_format_i18n( $health['needs_attention'] ) ); ?></dd></dl></div><p class="description">Dernier progrès : <?php echo esc_html( $health['last_progress_at'] ?: 'aucun' ); ?>. Prochain nettoyage : <?php echo esc_html( $health['next_cleanup_at'] ?: 'non planifié' ); ?>.</p><p class="description">Pour un traitement indépendant des visites, configurez un cron serveur qui exécute WP-CLI ou wp-cron selon votre hébergement. Le plugin ne modifie jamais le cron serveur lui-même.</p></div>
		<div class="msrwa-card"><h2>Fournisseurs</h2><?php foreach ( array( 'openai' => 'OpenAI', 'gemini' => 'Gemini', 'claude' => 'Claude' ) as $key => $label ) : ?><label><?php echo esc_html( $label ); ?> API key<input type="password" autocomplete="new-password" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD . '[' . $key . '_key]' ); ?>" value="" placeholder="Clé conservée si laissée vide"></label><?php endforeach; ?><p class="description">Les clés ne sont jamais affichées ni écrites dans les journaux. Les appels sont déclenchés uniquement par un job lancé et restent soumis aux budgets opérationnels.</p><?php if ( current_user_can( 'manage_options' ) ) : ?><p><button type="button" class="button msrwa-test-provider" data-provider="openai">Tester OpenAI</button> <span id="msrwa-openai-status" role="status"></span></p><p><button type="button" class="button msrwa-test-provider" data-provider="gemini">Tester Gemini</button> <span id="msrwa-gemini-status" role="status"></span></p><p><button type="button" class="button msrwa-test-provider" data-provider="claude">Tester Claude</button> <span id="msrwa-claude-status" role="status"></span></p><?php endif; ?></div>
		<div class="msrwa-card"><h2>Recherche de secours</h2><label>Mode<select name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[research_fallback_provider]"><option value="none" <?php selected( $s['research_fallback_provider'], 'none' ); ?>>Désactivée</option><option value="custom_json" <?php selected( $s['research_fallback_provider'], 'custom_json' ); ?>>Service JSON personnalisé</option></select></label><label>URL HTTPS du service<input type="url" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[research_fallback_url]" value="<?php echo esc_attr( $s['research_fallback_url'] ); ?>" placeholder="https://…"></label><label>Clé du service<input type="password" autocomplete="new-password" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[research_fallback_key]" value="" placeholder="Clé conservée si laissée vide"></label><p class="description">Appelée uniquement après l’échec de la recherche native. Le service doit accepter POST JSON {query,max_results} et retourner results[{url,title,content}]. Les URL privées, HTTP et redirections non sûres sont refusées.</p></div>
		<div class="msrwa-card" id="msrwa-settings-costs"><h2>Budgets et coûts</h2><p class="description">Limites opérationnelles. Zéro désactive uniquement la limite concernée.</p><div class="msrwa-fields"><label>Par recette (USD)<input type="number" min="0" step="0.01" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[per_recipe_budget_usd]" value="<?php echo esc_attr( $s['per_recipe_budget_usd'] ); ?>"></label><label>Coût cible par recette (USD)<input type="number" min="0" step="0.01" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[target_cost_usd]" value="<?php echo esc_attr( $s['target_cost_usd'] ); ?>"></label><label>Par jour (USD)<input type="number" min="0" step="0.01" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[daily_budget_usd]" value="<?php echo esc_attr( $s['daily_budget_usd'] ); ?>"></label><label>Par mois (USD)<input type="number" min="0" step="0.01" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[monthly_budget_usd]" value="<?php echo esc_attr( $s['monthly_budget_usd'] ); ?>"></label><label>Recherche web / appel (USD)<input type="number" min="0" step="0.001" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[web_search_tool_cost_usd]" value="<?php echo esc_attr( $s['web_search_tool_cost_usd'] ); ?>"></label><label>Estimation image principale (USD)<input type="number" min="0.001" step="0.001" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[featured_image_estimate_usd]" value="<?php echo esc_attr( $s['featured_image_estimate_usd'] ); ?>"></label><label>Estimation image Facebook (USD)<input type="number" min="0.001" step="0.001" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[facebook_image_estimate_usd]" value="<?php echo esc_attr( $s['facebook_image_estimate_usd'] ); ?>"></label><label>Réserve vision (USD)<input type="number" min="0.001" step="0.001" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[vision_reserve_usd]" value="<?php echo esc_attr( $s['vision_reserve_usd'] ); ?>"></label></div><p class="description">Zéro signifie aucune limite additionnelle. L’API OpenAI Image ne garantit pas l’usage token pour tous les modèles : les deux estimations configurables servent alors au budget et aux statistiques.</p></div>
		<div class="msrwa-card"><h2>Rétention et maintenance</h2><div class="msrwa-fields"><label>Journaux détaillés (jours)<input type="number" min="1" max="365" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[log_days]" value="<?php echo esc_attr( $s['log_days'] ); ?>"></label><label>Fichiers temporaires terminés (jours)<input type="number" min="1" max="90" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[temp_days]" value="<?php echo esc_attr( $s['temp_days'] ); ?>"></label><label>Statistiques agrégées (mois)<input type="number" min="1" max="60" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[aggregate_months]" value="<?php echo esc_attr( $s['aggregate_months'] ); ?>"></label></div><p class="description">Le nettoyage quotidien respecte ces durées. Les brouillons et médias finaux WordPress ne sont jamais supprimés ; les fichiers nécessaires aux jobs non terminés restent protégés.</p></div>
		<div class="msrwa-card"><h2>Liens internes</h2><label><input type="checkbox" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[internal_links_enabled]" value="1" <?php checked( $s['internal_links_enabled'], 1 ); ?>> Intégrer des liens contextuels vers les recettes déjà publiées</label><label>Nombre maximal de liens<input type="number" min="0" max="10" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[internal_links_max]" value="<?php echo esc_attr( $s['internal_links_max'] ); ?>"></label><p class="description">Liens placés sur des expressions pertinentes dans les paragraphes, sans section dédiée. Une ancre absente du texte est ignorée. Aucun domaine externe ne sera accepté.</p></div>
		<div class="msrwa-card"><h2>Sorties image</h2><div class="msrwa-fields"><label>Ratio image principale<select name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[featured_ratio]"><?php foreach ( array( '1:1', '4:5', '3:2', '2:3' ) as $ratio ) : ?><option value="<?php echo esc_attr( $ratio ); ?>" <?php selected( $s['featured_ratio'], $ratio ); ?>><?php echo esc_html( $ratio ); ?></option><?php endforeach; ?></select></label><label>Ratio Facebook<select name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[facebook_ratio]"><?php foreach ( array( '4:5', '1:1', '2:3', '3:2' ) as $ratio ) : ?><option value="<?php echo esc_attr( $ratio ); ?>" <?php selected( $s['facebook_ratio'], $ratio ); ?>><?php echo esc_html( $ratio ); ?></option><?php endforeach; ?></select></label><label>Qualité OpenAI<select name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[image_quality]"><?php foreach ( array( 'low', 'medium', 'high', 'xhigh', 'max', 'auto' ) as $quality ) : ?><option value="<?php echo esc_attr( $quality ); ?>" <?php selected( $s['image_quality'], $quality ); ?>><?php echo esc_html( $quality ); ?></option><?php endforeach; ?></select></label><label>Format de sortie OpenAI<select name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[image_format]"><?php foreach ( array( 'webp', 'jpeg', 'png' ) as $format ) : ?><option value="<?php echo esc_attr( $format ); ?>" <?php selected( $s['image_format'], $format ); ?>><?php echo esc_html( $format ); ?></option><?php endforeach; ?></select></label></div><p class="description">Les formats et qualités natifs dépendent du fournisseur. Le recadrage conserve le fichier final dans le format choisi sans étirer l’image.</p></div>
		<div class="msrwa-card"><h2>Mapping des intégrations</h2><p class="description">Les valeurs sont les clés de métadonnées utilisées par le thème et MS Facebook Posts. Seules les clés connues sont acceptées.</p><textarea rows="8" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[integration_mapping_json]"><?php echo esc_textarea( wp_json_encode( $s['integration_mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea></div>
		<div class="msrwa-card"><h2>Modèles manuels par étape</h2><p class="description">Utilisés uniquement lorsque le mode Manuel est imposé. Les modèles doivent rester stables et compatibles avec l’étape.</p><?php foreach ( array( 'text' => 'Rédaction et structure', 'review' => 'Relecture', 'image' => 'Images', 'search' => 'Recherche' ) as $stage => $label ) : ?><label><?php echo esc_html( $label ); ?><select name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD . '[manual_models][' . $stage . ']' ); ?>"><?php foreach ( MSRWA_Catalog::models() as $provider => $models ) : foreach ( $models as $model_id => $model ) : ?><option value="<?php echo esc_attr( $provider . ':' . $model_id ); ?>" <?php selected( $s['manual_models'][ $stage ], $provider . ':' . $model_id ); ?>><?php echo esc_html( $provider . ' — ' . $model['label'] ); ?></option><?php endforeach; endforeach; ?></select></label><?php endforeach; ?></div>
		<div class="msrwa-card"><h2>Prompts</h2><p class="description">Personnalisez les consignes de chaque agent. Les sorties structurées restent obligatoires.</p><label>Nombre maximal d’images de référence par recette<input type="number" min="0" max="10" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[max_reference_images]" value="<?php echo esc_attr( $s['max_reference_images'] ); ?>"></label><label><input type="checkbox" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[visual_reference_search]" value="1" <?php checked( $s['visual_reference_search'], 1 ); ?>> Rechercher des références visuelles web pour guider les images</label><label>Maximum de références visuelles analysées<input type="number" min="0" max="10" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD ); ?>[visual_reference_max]" value="<?php echo esc_attr( $s['visual_reference_max'] ); ?>"></label><p class="description">Les images trouvées sont téléchargées temporairement hors du site public, analysées pour leurs choix visuels, puis ne servent jamais d’actif réutilisable ou à reproduire.</p><?php foreach ( array( 'prompt_router' => 'Sélection automatique des modèles', 'prompt_research' => 'Recherche web', 'prompt_association' => 'Association', 'prompt_reference_vision' => 'Analyse vision des références', 'prompt_recipe' => 'Recette canonique', 'prompt_nutrition' => 'Nutrition estimée', 'prompt_article' => 'Article et métadonnées', 'prompt_internal_links' => 'Liens contextuels dans l’article', 'prompt_seo' => 'SEO', 'prompt_correction' => 'Correction', 'prompt_review' => 'Relecture et correction', 'prompt_image' => 'Image principale', 'prompt_image_review' => 'Contrôle image', 'prompt_image_correction' => 'Correction image', 'prompt_facebook_image' => 'Image Facebook' ) as $key => $label ) : ?><label><?php echo esc_html( $label ); ?><textarea rows="6" name="<?php echo esc_attr( MSRWA_Settings::FORM_FIELD . '[' . $key . ']' ); ?>"><?php echo esc_textarea( $s[ $key ] ); ?></textarea></label><?php endforeach; ?></div><?php self::render_settings_database_sections(); submit_button( 'Enregistrer les réglages' ); ?></form></div><?php
	}
}
