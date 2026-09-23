<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The menu, the assets, and the two forms that post back.
 *
 * Every screen lives in its own class; this only decides who may reach which
 * one and hands WordPress the pieces it needs.
 */
final class MSRWA_Admin {

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_msrwa_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_msrwa_save_engine', array( __CLASS__, 'save_engine' ) );
		add_action( 'admin_post_msrwa_save_models', array( 'MSRWA_Screen_Models', 'save' ) );
		add_action( 'admin_post_msrwa_report', array( 'MSRWA_Operations', 'report' ) );
		add_action( 'admin_post_msrwa_export', array( 'MSRWA_Export', 'send' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** In the order the work happens: the pass, submitting, the record, the levers. */
	public static function menu() {
		$write = MSRWA_Rights::CREATE;
		$manage = MSRWA_Rights::MANAGE;

		$title = __( 'MS Recipes Writer', 'ms-recipes-writer-ai' );
		add_menu_page( $title, self::title_with_waiting( $title ), $write, 'msrwa', array( 'MSRWA_Screen_Pass', 'render' ), 'dashicons-food', 58 );
		add_submenu_page( 'msrwa', __( 'Le pass', 'ms-recipes-writer-ai' ), __( 'Le pass', 'ms-recipes-writer-ai' ), $write, 'msrwa', array( 'MSRWA_Screen_Pass', 'render' ) );
		add_submenu_page( 'msrwa', __( 'Nouveau lot', 'ms-recipes-writer-ai' ), __( 'Nouveau lot', 'ms-recipes-writer-ai' ), $write, 'msrwa-compose', array( 'MSRWA_Screen_Compose', 'render' ) );
		add_submenu_page( 'msrwa', __( 'Articles', 'ms-recipes-writer-ai' ), __( 'Articles', 'ms-recipes-writer-ai' ), $write, 'msrwa-articles', array( 'MSRWA_Screen_Articles', 'render' ) );
		add_submenu_page( 'msrwa', __( 'Analyse', 'ms-recipes-writer-ai' ), __( 'Analyse', 'ms-recipes-writer-ai' ), $manage, 'msrwa-analysis', array( 'MSRWA_Screen_Analysis', 'render' ) );
		add_submenu_page( 'msrwa', __( 'Moteur', 'ms-recipes-writer-ai' ), __( 'Moteur', 'ms-recipes-writer-ai' ), $manage, 'msrwa-engine', array( 'MSRWA_Screen_Engine', 'render' ) );
		add_submenu_page( 'msrwa', __( 'Modèles', 'ms-recipes-writer-ai' ), __( 'Modèles', 'ms-recipes-writer-ai' ), $manage, 'msrwa-models', array( 'MSRWA_Screen_Models', 'render' ) );
		add_submenu_page( 'msrwa', __( 'Diagnostic', 'ms-recipes-writer-ai' ), __( 'Diagnostic', 'ms-recipes-writer-ai' ), $manage, 'msrwa-diagnostics', array( 'MSRWA_Screen_Diagnostics', 'render' ) );
		add_submenu_page( 'msrwa', __( 'Réglages', 'ms-recipes-writer-ai' ), __( 'Réglages', 'ms-recipes-writer-ai' ), $manage, 'msrwa-settings', array( 'MSRWA_Screen_Settings', 'render' ) );

		// Reached from a ticket, never from the menu. Registered under the real
		// parent and then hidden, rather than with a null parent: WordPress no
		// longer finds a title for a page parented to nothing, and every such
		// screen carries a deprecation notice across the top of the admin.
		// `options.php` is a real page and not a menu, so a child of it is
		// registered with a title and an access check but appears nowhere.
		// A null parent leaves WordPress unable to find a title, which puts a
		// deprecation notice across the top of every such screen; removing the
		// submenu afterwards takes the access check with it and returns 403.
		add_submenu_page( 'options.php', __( 'Lot', 'ms-recipes-writer-ai' ), __( 'Lot', 'ms-recipes-writer-ai' ), $write, 'msrwa-batch', array( 'MSRWA_Screen_Batch', 'render' ) );
		add_submenu_page( 'options.php', __( 'Recette', 'ms-recipes-writer-ai' ), __( 'Recette', 'ms-recipes-writer-ai' ), $write, 'msrwa-run', array( 'MSRWA_Screen_Run', 'render' ) );
	}

	/**
	 * The menu title, carrying how many drafts are waiting for this reader.
	 *
	 * An editor had no way to learn that work had arrived without opening the
	 * pass and looking, so a lot finished at three in the morning waited until
	 * somebody thought to check. This is the signal WordPress itself uses for
	 * comments and updates, and it means the same thing here.
	 *
	 * The count is `MSRWA_Ledger::now()`, which scopes to the reader: an editor
	 * is told about their own drafts, a manager about every one. Nothing is
	 * cached — it is a single aggregate over a table retention keeps small, and
	 * a cache would buy a stale number an uninstall would then have to clean up.
	 */
	private static function title_with_waiting( $title ) {
		// A fresh install has no tables yet, and the first admin page load is
		// where the migration runs. Asking now would be a query against nothing.
		if ( ! get_option( 'msrwa_schema', 0 ) ) { return $title; }

		$waiting = (int) MSRWA_Ledger::now()['to_read'];
		if ( $waiting < 1 ) { return $title; }

		return $title . ' <span class="update-plugins count-' . $waiting . '"><span class="plugin-count">'
			. esc_html( number_format_i18n( $waiting ) ) . '</span></span>';
	}

	public static function assets( $hook ) {
		$ours = false !== strpos( (string) $hook, 'msrwa' );
		// The verdict box lives on the post editor, so the stylesheet has to
		// reach there too — otherwise an editor sees unstyled findings.
		$editing = in_array( (string) $hook, array( 'post.php', 'post-new.php' ), true );
		if ( ! $ours && ! $editing ) { return; }

		wp_enqueue_style( 'msrwa-admin', MSRWA_URL . 'assets/admin.css', array(), MSRWA_VERSION . '.' . filemtime( MSRWA_DIR . 'assets/admin.css' ) );
		if ( ! $ours ) { return; }

		wp_enqueue_media();
		wp_enqueue_script( 'msrwa-admin', MSRWA_URL . 'assets/admin.js', array(), MSRWA_VERSION . '.' . filemtime( MSRWA_DIR . 'assets/admin.js' ), true );

		// Every word the script can print comes from here, so the interface is
		// translated in one place rather than half in PHP and half in English
		// buried in a bundle.
		wp_localize_script( 'msrwa-admin', 'MSRWA', array(
			'api' => esc_url_raw( rest_url( 'msrwa/v1' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'text' => array(
				'failed' => __( 'Une erreur est survenue.', 'ms-recipes-writer-ai' ),
				'oneRecipe' => __( '1 recette détectée', 'ms-recipes-writer-ai' ),
				/* translators: %d is a number of recipes. */
				'manyRecipes' => __( '%d recettes détectées', 'ms-recipes-writer-ai' ),
				'oneImage' => __( '1 photographie', 'ms-recipes-writer-ai' ),
				/* translators: %d is a number of photographs. */
				'manyImages' => __( '%d photographies', 'ms-recipes-writer-ai' ),
				'pickImages' => __( 'Photographies des recettes', 'ms-recipes-writer-ai' ),
				/* translators: 1: likely cost, 2: the ceiling, 3: number of recipes. */
				'estimate' => __( 'Environ %1$s pour %3$d recette(s), et au maximum %2$s : le plafond arrête un run avant de le dépasser.', 'ms-recipes-writer-ai' ),
				'noRecipes' => __( 'Il n’y a aucune recette dans ce texte.', 'ms-recipes-writer-ai' ),
				'describing' => __( 'Description des photographies…', 'ms-recipes-writer-ai' ),
				'saving' => __( 'Enregistrement…', 'ms-recipes-writer-ai' ),
				'askingProviders' => __( 'Interrogation des fournisseurs…', 'ms-recipes-writer-ai' ),
				'readingPrices' => __( 'Lecture des pages de tarifs…', 'ms-recipes-writer-ai' ),
				'reloadToSee' => __( 'Rechargez la page pour voir le catalogue à jour.', 'ms-recipes-writer-ai' ),
				/* translators: %d is a number of models. */
				'modelsListed' => __( '%d modèle(s)', 'ms-recipes-writer-ai' ),
				'nothingToPrice' => __( 'Rien à chercher : chaque modèle servi a déjà un tarif.', 'ms-recipes-writer-ai' ),
				/* translators: 1: number of prices found, 2: number asked about. */
				'pricesFound' => __( '%1$d tarif(s) trouvé(s) sur %2$d demandé(s).', 'ms-recipes-writer-ai' ),
				'pricesAreIndicative' => __( 'Ces tarifs sont une indication à vérifier, jamais une facture. Rechargez la page.', 'ms-recipes-writer-ai' ),
				'saved' => __( 'Appariement enregistré.', 'ms-recipes-writer-ai' ),
				'sending' => __( 'Envoi au moteur…', 'ms-recipes-writer-ai' ),
				'retrying' => __( 'Reprise…', 'ms-recipes-writer-ai' ),
				/* translators: %s is a comma-separated list of step names. */
				'unpriced' => __( 'Le total est incomplet : %s tourne(nt) sur un modèle sans tarif publié.', 'ms-recipes-writer-ai' ),
				'onePicked' => __( '1 recette sélectionnée', 'ms-recipes-writer-ai' ),
				/* translators: %d is a number of recipes. */
				'manyPicked' => __( '%d recettes sélectionnées', 'ms-recipes-writer-ai' ),
				'applying' => __( 'Application…', 'ms-recipes-writer-ai' ),
				'holding' => __( 'Suspension…', 'ms-recipes-writer-ai' ),
				'releasing' => __( 'Reprise de la file…', 'ms-recipes-writer-ai' ),
				'pruning' => __( 'Nettoyage…', 'ms-recipes-writer-ai' ),
				'checkingKeys' => __( 'Vérification…', 'ms-recipes-writer-ai' ),
				/* translators: %d is a number of recipes. */
				'confirmDelete' => __( 'Supprimer %d recette(s) et tout ce que le moteur en a rapporté ? C’est irréversible.', 'ms-recipes-writer-ai' ),
				'confirmBatchDelete' => __( 'Supprimer ce lot et tout ce que le moteur en a rapporté ? Les brouillons déjà produits sont conservés. C’est irréversible.', 'ms-recipes-writer-ai' ),
				'passUrl' => admin_url( 'admin.php?page=msrwa' ),
				/* translators: 1: estimated cost per recipe, 2: the per-recipe ceiling. */
				'overCeiling' => __( 'Attention : une recette est estimée à %1$s, au-dessus du plafond de %2$s. Elle s’arrêterait en route ; le lot sera refusé au lancement. Relevez le plafond ou choisissez une sortie plus légère.', 'ms-recipes-writer-ai' ),
				'overCeilingWriter' => __( 'Ce lot dépasse le plafond par recette fixé pour le site et sera refusé au lancement. Choisissez une sortie plus légère, ou demandez à un administrateur de relever le plafond.', 'ms-recipes-writer-ai' ),
				/* translators: 1: how many were done, 2: how many were not. */
				'someSkipped' => __( '%1$d traitée(s), %2$d ignorée(s) : l’action ne s’appliquait pas, ou elles ne vous appartiennent pas.', 'ms-recipes-writer-ai' ),
				'copied' => __( 'Rapport copié.', 'ms-recipes-writer-ai' ),
				'copyManually' => __( 'Sélectionné : copiez avec votre raccourci habituel.', 'ms-recipes-writer-ai' ),
				'previewStep' => __( 'Étape', 'ms-recipes-writer-ai' ),
				'previewRoute' => __( 'Route', 'ms-recipes-writer-ai' ),
				'previewKey' => __( 'Clé présente', 'ms-recipes-writer-ai' ),
				'previewPrice' => __( 'Tarif connu', 'ms-recipes-writer-ai' ),
				'previewCost' => __( 'Coût estimé', 'ms-recipes-writer-ai' ),
				/* translators: %s is an amount in US dollars. */
				'previewTotal' => __( 'Une recette complète est estimée à %s — une estimation, jamais une facture.', 'ms-recipes-writer-ai' ),
				'previewUnpriced' => __( 'Sans tarif, donc absentes du total :', 'ms-recipes-writer-ai' ),
			),
		) );
	}

	public static function save_settings() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_save_settings' );
		MSRWA_Settings::save( isset( $_POST['msrwa_settings'] ) ? wp_unslash( $_POST['msrwa_settings'] ) : array() );
		wp_safe_redirect( admin_url( 'admin.php?page=msrwa-settings&saved=1' ) );
		exit;
	}

	public static function save_engine() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_save_engine' );
		$invalid = MSRWA_Engine_Settings::save( isset( $_POST['msrwa_engine'] ) ? (array) wp_unslash( $_POST['msrwa_engine'] ) : array() );
		wp_safe_redirect( add_query_arg( array( 'page' => 'msrwa-engine', 'saved' => 1, 'invalid' => implode( ',', $invalid ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
