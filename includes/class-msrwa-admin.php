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
		add_action( 'admin_post_msrwa_reset', array( __CLASS__, 'reset' ) );
		add_action( 'admin_post_msrwa_uninstall_choice', array( __CLASS__, 'uninstall_choice' ) );
		add_action( 'admin_post_msrwa_style', array( __CLASS__, 'save_style' ) );
		add_action( 'admin_post_msrwa_save_models', array( 'MSRWA_Screen_Models', 'save' ) );
		add_action( 'admin_post_msrwa_report', array( 'MSRWA_Operations', 'report' ) );
		add_action( 'admin_post_msrwa_export', array( 'MSRWA_Export', 'send' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_head', array( __CLASS__, 'unlist_hidden' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
	}

	/**
	 * The lot and recipe screens have no menu entry of their own, so WordPress
	 * showed the whole menu folded while one was open. They belong under the
	 * plugin's menu: a recipe under Articles, a lot under the pass.
	 */
	private static function hidden_parent() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return array( 'msrwa-batch' => 'msrwa', 'msrwa-run' => 'msrwa-articles', 'msrwa-compose' => 'msrwa' )[ $page ] ?? '';
	}

	/**
	 * WordPress recomputes the parent right after the `parent_file` filter,
	 * finds the page under `options.php`, and folds the menu again. Access has
	 * already been checked by then, so the entry can go before the menu is drawn.
	 */
	public static function unlist_hidden() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== self::hidden_parent() ) { remove_submenu_page( 'options.php', $page ); }
	}

	public static function parent_file( $file ) { return '' !== self::hidden_parent() ? 'msrwa' : $file; }

	public static function submenu_file( $file ) { return '' !== self::hidden_parent() ? self::hidden_parent() : $file; }

	/** In the order the work happens: the pass with its new lot, the record, the levers. */
	public static function menu() {
		// No upload rights, no plugin: a contributor does not see the menu at
		// all, and a page that was never registered cannot be opened by URL.
		if ( ! MSRWA_Rights::may_write() ) { return; }
		$write = MSRWA_Rights::CREATE;
		$manage = MSRWA_Rights::MANAGE;

		$title = __( 'MS Recipes AI', 'ms-recipes-writer-ai' );
		// First in the admin menu, above the dashboard: for the people who use
		// it, this is what they open WordPress for. A fraction, so it never
		// takes a slot another menu registered.
		add_menu_page( $title, self::title_with_waiting( $title ), $write, 'msrwa', array( 'MSRWA_Screen_Pass', 'render' ), 'dashicons-food', 1.01 );
		add_submenu_page( 'msrwa', __( 'Le pass', 'ms-recipes-writer-ai' ), __( 'Le pass', 'ms-recipes-writer-ai' ), $write, 'msrwa', array( 'MSRWA_Screen_Pass', 'render' ) );
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
		// The new-lot form now heads the pass: its old address still opens it.
		add_submenu_page( 'options.php', __( 'Le pass', 'ms-recipes-writer-ai' ), __( 'Le pass', 'ms-recipes-writer-ai' ), $write, 'msrwa-compose', array( 'MSRWA_Screen_Pass', 'render' ) );
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

		wp_enqueue_script( 'msrwa-admin', MSRWA_URL . 'assets/admin.js', array(), MSRWA_VERSION . '.' . filemtime( MSRWA_DIR . 'assets/admin.js' ), true );

		// Every word the script can print comes from here, so the interface is
		// translated in one place rather than half in PHP and half in English
		// buried in a bundle.
		wp_localize_script( 'msrwa-admin', 'MSRWA', array(
			'api' => esc_url_raw( rest_url( 'msrwa/v1' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			// Amounts the script paints read as the ones the page printed: the
			// same currency pattern and the reader's own separators.
			'money' => array(
				/* translators: %s is an amount of money. Put the currency sign where your language puts it. */
				'pattern' => __( '%s $', 'ms-recipes-writer-ai' ),
				'decimal' => isset( $GLOBALS['wp_locale'] ) ? (string) $GLOBALS['wp_locale']->number_format['decimal_point'] : '.',
				'thousands' => isset( $GLOBALS['wp_locale'] ) ? (string) $GLOBALS['wp_locale']->number_format['thousands_sep'] : ',',
			),
			'text' => array(
				'failed' => __( 'Une erreur est survenue.', 'ms-recipes-writer-ai' ),
				'oneRecipe' => __( '1 recette détectée', 'ms-recipes-writer-ai' ),
				/* translators: %d is a number of photographs. */
				'fromPhotos' => __( 'Aucun texte : chaque plat reconnu sur les photographies deviendra une recette (%d au plus).', 'ms-recipes-writer-ai' ),
				/* translators: %d is a number of recipes. */
				'manyRecipes' => __( '%d recettes détectées', 'ms-recipes-writer-ai' ),
				'noImage' => __( 'aucune photographie', 'ms-recipes-writer-ai' ),
				'oneImage' => __( '1 photographie', 'ms-recipes-writer-ai' ),
				/* translators: %d is a number of photographs. */
				'manyImages' => __( '%d photographies', 'ms-recipes-writer-ai' ),
				// The site's ceiling, for the estimate line; a writer is never sent it.
				'ceilingUsd' => MSRWA_Rights::may_see_money() ? (float) MSRWA_Settings::get()['per_recipe_budget_usd'] : 0,
				'photoBytes' => self::photo_bytes(),
				'photoCount' => MSRWA_Intake::MAX_PHOTOS,
				'postBytes' => self::post_bytes(),
				/* translators: 1: a file name, 2: the largest size allowed, in megabytes. */
				'photoTooBig' => __( '%1$s dépasse %2$s Mo.', 'ms-recipes-writer-ai' ),
				/* translators: %s is a file name. */
				'photoType' => __( '%s n’est pas une photographie JPEG, PNG ou WebP.', 'ms-recipes-writer-ai' ),
				/* translators: %d is the largest number of photographs a lot may carry. */
				'photoMany' => __( 'Un lot accepte au plus %d photographies.', 'ms-recipes-writer-ai' ),
				/* translators: %s is a size in megabytes. */
				'photoTotal' => __( 'Ensemble, ces photographies dépassent les %s Mo que ce serveur accepte en un envoi.', 'ms-recipes-writer-ai' ),
				'uploading' => __( 'Envoi des photographies…', 'ms-recipes-writer-ai' ),
				/* translators: %s is a file name. */
				'removePhoto' => __( 'Retirer %s', 'ms-recipes-writer-ai' ),
				'pastedTag' => __( 'collée', 'ms-recipes-writer-ai' ),
				'linkTag' => __( 'lien', 'ms-recipes-writer-ai' ),
				'linkNotHttps' => __( 'L’adresse doit commencer par https://.', 'ms-recipes-writer-ai' ),
				'linkUnseen' => __( 'aperçu indisponible — le site vérifiera à l’envoi', 'ms-recipes-writer-ai' ),
				/* translators: %d is the number of the pasted image, counting from 1. */
				'pastedName' => __( 'Image collée %d', 'ms-recipes-writer-ai' ),
				'recipeTitleOnly' => __( 'le nom seul', 'ms-recipes-writer-ai' ),
				'recipeWithDetails' => __( 'avec des précisions', 'ms-recipes-writer-ai' ),
				/* translators: 1: likely cost, 2: the ceiling, 3: number of recipes. */
				'estimate' => __( 'Environ %1$s pour %3$d recette(s) · jamais plus de %2$s : le plafond arrête une recette avant de le dépasser.', 'ms-recipes-writer-ai' ),
				'noRecipes' => __( 'Collez au moins une recette ou ajoutez au moins une photographie.', 'ms-recipes-writer-ai' ),
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
				'dishOnePhoto' => __( '1 photographie — écrite d’après elle', 'ms-recipes-writer-ai' ),
				/* translators: %d is a number of photographs. */
				'dishPhotos' => __( '%d photographies — écrite d’après elles', 'ms-recipes-writer-ai' ),
				'pairAside' => __( 'Mise de côté', 'ms-recipes-writer-ai' ),
				'pairChosen' => __( 'Choisie par vous', 'ms-recipes-writer-ai' ),
				'dishNoPhoto' => __( 'Sans photographie — références cherchées sur le web', 'ms-recipes-writer-ai' ),
				'dishDropped' => __( 'Ne sera pas écrite : sa photographie est écartée', 'ms-recipes-writer-ai' ),
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
				'previewThinking' => __( 'Réflexion', 'ms-recipes-writer-ai' ),
				/* translators: %s is an amount in US dollars. */
				'retryMax' => __( 'Au pire %s par recette, si la recherche use de toutes ses recherches permises.', 'ms-recipes-writer-ai' ),
				'retryOverCeiling' => __( 'Le plafond par recette est plus bas : il arrête les nouvelles tentatives avant de le franchir, la recette se termine et le dernier verdict va au rédacteur.', 'ms-recipes-writer-ai' ),
				/* translators: %s is an amount in US dollars. */
				'previewTotal' => __( 'Une recette complète est estimée à %s — une estimation, jamais une facture.', 'ms-recipes-writer-ai' ),
				'previewUnpriced' => __( 'Sans tarif, donc absentes du total :', 'ms-recipes-writer-ai' ),
			),
		) );
	}

	/** Settings, or settings and data, back to a fresh install. */
	public static function reset() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_reset' );
		$scope = 'all' === sanitize_key( (string) ( $_POST['scope'] ?? '' ) ) ? 'all' : 'settings';
		$forget_keys = ! empty( $_POST['forget_keys'] );
		$back = admin_url( 'admin.php?page=msrwa-settings' );
		if ( 'all' === $scope ) {
			// Everyone's work goes, not only the person's own.
			if ( ! current_user_can( MSRWA_Rights::VIEW_ALL ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
			$typed = trim( sanitize_text_field( wp_unslash( (string) ( $_POST['confirm'] ?? '' ) ) ) );
			if ( mb_strtoupper( $typed ) !== mb_strtoupper( self::erase_word() ) ) { wp_safe_redirect( add_query_arg( 'reset', 'unconfirmed', $back ) . '#ms-reset' ); exit; }
			$done = MSRWA_Reset::everything( $forget_keys );
			if ( is_wp_error( $done ) ) { wp_safe_redirect( add_query_arg( 'reset', 'busy', $back ) . '#ms-reset' ); exit; }
		} else {
			MSRWA_Reset::settings( $forget_keys );
		}
		wp_safe_redirect( add_query_arg( 'reset', $scope, $back ) );
		exit;
	}

	/** What deleting the plugin will remove, decided ahead of time. */
	public static function uninstall_choice() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_uninstall_choice' );
		MSRWA_Reset::choose_uninstall( sanitize_key( (string) ( $_POST['uninstall'] ?? '' ) ) );
		wp_safe_redirect( add_query_arg( 'reset', 'uninstall', admin_url( 'admin.php?page=msrwa-settings' ) ) . '#ms-reset' );
		exit;
	}

	/** The word typed to confirm that the data goes, in the screen's language. */
	public static function erase_word() {
		/* translators: the word a person types to confirm that all data is erased; one word, in capitals. */
		return __( 'EFFACER', 'ms-recipes-writer-ai' );
	}

	public static function save_settings() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_save_settings' );
		MSRWA_Settings::save( isset( $_POST['msrwa_settings'] ) ? wp_unslash( $_POST['msrwa_settings'] ) : array() );
		wp_safe_redirect( admin_url( 'admin.php?page=msrwa-settings&saved=1' ) );
		exit;
	}

	/** Adds or removes one of the Facebook collage's style references. */
	public static function save_style() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_style' );
		$refused = '';
		$remove = isset( $_POST['remove'] ) ? sanitize_file_name( wp_unslash( $_POST['remove'] ) ) : '';
		if ( '' !== $remove ) {
			MSRWA_Sources::remove_style( $remove );
		} elseif ( ! empty( $_FILES['style']['tmp_name'] ) ) {
			$refused = MSRWA_Sources::add_style( array( 'tmp_name' => (string) $_FILES['style']['tmp_name'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} else {
			$refused = __( 'Aucune image reçue.', 'ms-recipes-writer-ai' );
		}
		if ( '' !== $refused ) { set_transient( 'msrwa_style_refused_' . get_current_user_id(), $refused, MINUTE_IN_SECONDS ); }
		wp_safe_redirect( admin_url( 'admin.php?page=msrwa-settings&style=' . ( '' === $refused ? '1' : '0' ) . '#ms-style' ) );
		exit;
	}

	public static function save_engine() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_save_engine' );
		$invalid = MSRWA_Engine_Settings::save( isset( $_POST['msrwa_engine'] ) ? (array) wp_unslash( $_POST['msrwa_engine'] ) : array() );
		// The article language is the site's setting, shown on this screen too.
		$language = isset( $_POST['msrwa_site_language'] ) ? sanitize_key( wp_unslash( $_POST['msrwa_site_language'] ) ) : '';
		if ( MSRWA_Profile::language_exists( $language ) && ! MSRWA_Engine_Settings::$refused ) { MSRWA_Settings::save( array( 'site_language' => $language ) ); }
		if ( MSRWA_Engine_Settings::$refused ) {
			// Kept a minute for the redirected page to say which route and why.
			set_transient( 'msrwa_engine_refused_' . get_current_user_id(), MSRWA_Engine_Settings::$refused, MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'msrwa-engine', 'saved' => MSRWA_Engine_Settings::$refused ? 0 : 1, 'invalid' => implode( ',', $invalid ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * The largest photograph a writer may send: what the engine reads, or less
	 * when the server accepts less in one upload.
	 */
	public static function photo_bytes() {
		$engine = (int) ( MSRWA_Engine_Settings::effective( 'limits' )['max_image_bytes'] ?? 10000000 );
		$server = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		return max( 1, $server > 0 ? min( $engine, $server ) : $engine );
	}

	/** What the server accepts in one request, every photograph together. */
	public static function post_bytes() {
		return function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) : 0;
	}
}
