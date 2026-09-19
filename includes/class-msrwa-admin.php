<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Admin {
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		$parent = function_exists( 'menu_page_url' ) && $GLOBALS['menu'] && self::has_ms_tools() ? 'ms-tools' : null;
		if ( $parent ) {
			add_submenu_page( $parent, 'MS Recipes Writer AI', 'MS Recipes Writer AI', 'edit_posts', 'ms-recipes-writer-ai', array( __CLASS__, 'page' ) );
		} else {
			add_menu_page( 'MS Recipes Writer AI', 'MS Recipes Writer AI', 'edit_posts', 'ms-recipes-writer-ai', array( __CLASS__, 'page' ), 'dashicons-edit-page', 58 );
		}
		add_submenu_page( 'ms-recipes-writer-ai', 'Réglages', 'Réglages', 'manage_options', 'ms-recipes-writer-ai-settings', array( __CLASS__, 'settings_page' ) );
	}

	private static function has_ms_tools() {
		foreach ( (array) $GLOBALS['menu'] as $item ) { if ( isset( $item[2] ) && 'ms-tools' === $item[2] ) { return true; } }
		return false;
	}

	public static function settings() { register_setting( 'msrwa_settings', MSRWA_Settings::OPTION, array( 'sanitize_callback' => array( 'MSRWA_Settings', 'sanitize' ) ) ); }

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'ms-recipes-writer-ai' ) ) { return; }
		wp_enqueue_style( 'msrwa-admin', MSRWA_URL . 'assets/admin.css', array(), MSRWA_VERSION );
		wp_enqueue_script( 'msrwa-admin', MSRWA_URL . 'assets/admin.js', array(), MSRWA_VERSION, true );
		wp_localize_script( 'msrwa-admin', 'MSRWA', array( 'api' => esc_url_raw( rest_url( 'msrwa/v1' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	public static function page() {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		?>
		<?php $stats = MSRWA_Stats::summary( 7, current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' ) ? 0 : get_current_user_id() ); ?><div class="wrap msrwa-wrap"><h1>MS Recipes Writer AI</h1><section class="msrwa-card"><h2>Activité des 7 derniers jours</h2><div class="msrwa-fields"><div><strong><?php echo esc_html( $stats['total_jobs'] ); ?></strong><span>jobs</span></div><div><strong><?php echo esc_html( $stats['estimated_cost_usd'] ); ?> $</strong><span>coût estimé</span></div><div><strong><?php echo esc_html( array_sum( array_column( $stats['calls'], 'count' ) ) ); ?></strong><span>appels</span></div></div></section>
			<div class="msrwa-grid"><section class="msrwa-card"><h2>Nouveau lot</h2><p>Ajoutez un titre par ligne. Les fournisseurs et modèles restent contrôlés par l’administration.</p><textarea id="msrwa-titles" rows="8" placeholder="Titre de recette"></textarea><button class="button button-primary" id="msrwa-create">Créer le lot</button><p id="msrwa-message" role="status"></p></section>
			<section class="msrwa-card"><h2>État du système</h2><dl><dt>Mode imposé</dt><dd><?php echo esc_html( 'automatic' === MSRWA_Settings::get()['mode'] ? 'Automatique' : 'Manuel' ); ?></dd><dt>Limite de lot</dt><dd><?php echo esc_html( MSRWA_Settings::get()['max_batch'] ); ?></dd><dt>Parallélisme</dt><dd><?php echo esc_html( MSRWA_Settings::get()['max_concurrency'] ); ?></dd></dl><p>Les appels payants sont suspendus tant qu’un budget de test explicite n’est pas validé.</p></section></div>
		</div><?php
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$s = MSRWA_Settings::get();
		?><div class="wrap msrwa-wrap"><h1>MS Recipes Writer AI — Réglages</h1><form method="post" action="options.php"><?php settings_fields( 'msrwa_settings' ); ?><div class="msrwa-card"><h2>Mode et limites</h2><label>Mode imposé<select name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[mode]"><option value="automatic" <?php selected( $s['mode'], 'automatic' ); ?>>Automatique — équilibre qualité/coût</option><option value="manual" <?php selected( $s['mode'], 'manual' ); ?>>Manuel — réglages administrateur</option></select></label><div class="msrwa-fields"><label>Lot maximum<input type="number" min="1" max="50" name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[max_batch]" value="<?php echo esc_attr( $s['max_batch'] ); ?>"></label><label>Simultané maximum<input type="number" min="1" max="4" name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[max_concurrency]" value="<?php echo esc_attr( $s['max_concurrency'] ); ?>"></label><label>Corrections maximum<input type="number" min="0" max="2" name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[max_corrections]" value="<?php echo esc_attr( $s['max_corrections'] ); ?>"></label></div></div>
		<div class="msrwa-card"><h2>Fournisseurs</h2><?php foreach ( array( 'openai' => 'OpenAI', 'gemini' => 'Gemini', 'claude' => 'Claude' ) as $key => $label ) : ?><label><?php echo esc_html( $label ); ?> API key<input type="password" autocomplete="new-password" name="<?php echo esc_attr( MSRWA_Settings::OPTION . '[' . $key . '_key]' ); ?>" value="" placeholder="Clé conservée si laissée vide"></label><?php endforeach; ?><p class="description">Les clés ne sont jamais affichées ni écrites dans les journaux. Leur présence seule n’active pas d’appel payant.</p><?php if ( current_user_can( 'manage_options' ) ) : ?><button type="button" class="button" id="msrwa-test-openai">Tester OpenAI</button> <span id="msrwa-openai-status" role="status"></span><?php endif; ?></div>
		<div class="msrwa-card"><h2>Budgets</h2><label><input type="checkbox" name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[allow_paid_tests]" value="1" <?php checked( $s['allow_paid_tests'], 1 ); ?>> Autoriser les appels API payants de test</label><div class="msrwa-fields"><label>Budget de test (USD)<input type="number" min="0" max="1000" step="0.01" name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[test_budget_usd]" value="<?php echo esc_attr( $s['test_budget_usd'] ); ?>"></label><label>Par recette (USD)<input type="number" min="0" step="0.01" name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[per_recipe_budget_usd]" value="<?php echo esc_attr( $s['per_recipe_budget_usd'] ); ?>"></label><label>Par jour (USD)<input type="number" min="0" step="0.01" name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[daily_budget_usd]" value="<?php echo esc_attr( $s['daily_budget_usd'] ); ?>"></label><label>Par mois (USD)<input type="number" min="0" step="0.01" name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[monthly_budget_usd]" value="<?php echo esc_attr( $s['monthly_budget_usd'] ); ?>"></label><label>Réserve image incertaine (USD)<input type="number" min="0.01" step="0.01" name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[image_reserve_usd]" value="<?php echo esc_attr( $s['image_reserve_usd'] ); ?>"></label></div><p class="description">Zéro signifie aucune limite additionnelle. Les coûts image restent marqués incertains jusqu’à la réconciliation fournisseur.</p></div>
		<div class="msrwa-card"><h2>Liens internes</h2><label><input type="checkbox" name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[internal_links_enabled]" value="1" <?php checked( $s['internal_links_enabled'], 1 ); ?>> Suggérer des liens vers les recettes déjà publiées</label><label>Nombre maximal de liens<input type="number" min="0" max="10" name="<?php echo esc_attr( MSRWA_Settings::OPTION ); ?>[internal_links_max]" value="<?php echo esc_attr( $s['internal_links_max'] ); ?>"></label><p class="description">Les liens sont proposés à l’agent depuis le contenu local publié. Aucun domaine externe ne sera ajouté par cette fonctionnalité.</p></div>
		<div class="msrwa-card"><h2>Modèles manuels par étape</h2><p class="description">Utilisés uniquement lorsque le mode Manuel est imposé. Les modèles doivent rester stables et compatibles avec l’étape.</p><?php foreach ( array( 'text' => 'Rédaction et structure', 'review' => 'Relecture', 'image' => 'Images', 'search' => 'Recherche' ) as $stage => $label ) : ?><label><?php echo esc_html( $label ); ?><select name="<?php echo esc_attr( MSRWA_Settings::OPTION . '[manual_models][' . $stage . ']' ); ?>"><?php foreach ( MSRWA_Catalog::models() as $provider => $models ) : foreach ( $models as $model_id => $model ) : ?><option value="<?php echo esc_attr( $provider . ':' . $model_id ); ?>" <?php selected( $s['manual_models'][ $stage ], $provider . ':' . $model_id ); ?>><?php echo esc_html( $provider . ' — ' . $model['label'] ); ?></option><?php endforeach; endforeach; ?></select></label><?php endforeach; ?></div>
		<div class="msrwa-card"><h2>Prompts</h2><p class="description">Ces valeurs par défaut servent aux premiers tests et à la première installation. Vous pouvez ensuite les adapter sans modifier le code. Les sorties structurées restent obligatoires.</p><?php foreach ( array( 'prompt_research' => 'Recherche web', 'prompt_recipe' => 'Recette canonique', 'prompt_article' => 'Article et métadonnées', 'prompt_review' => 'Relecture et correction', 'prompt_image' => 'Image principale', 'prompt_facebook_image' => 'Image Facebook' ) as $key => $label ) : ?><label><?php echo esc_html( $label ); ?><textarea rows="6" name="<?php echo esc_attr( MSRWA_Settings::OPTION . '[' . $key . ']' ); ?>"><?php echo esc_textarea( $s[ $key ] ); ?></textarea></label><?php endforeach; ?></div><?php submit_button( 'Enregistrer les réglages' ); ?></form></div><?php
	}
}
