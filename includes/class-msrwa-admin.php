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
		add_action( 'admin_post_msrwa_report', array( 'MSRWA_Operations', 'report' ) );
		add_action( 'admin_post_msrwa_export', array( 'MSRWA_Export', 'send' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** In the order the work happens: the pass, submitting, the record, the levers. */
	public static function menu() {
		$write = MSRWA_Rights::CREATE;
		$manage = MSRWA_Rights::MANAGE;

		add_menu_page( __( 'MS Recipes Writer', 'ms-recipes-writer-ai' ), __( 'MS Recipes Writer', 'ms-recipes-writer-ai' ), $write, 'msrwa', array( 'MSRWA_Screen_Pass', 'render' ), 'dashicons-food', 58 );
		add_submenu_page( 'msrwa', __( 'Le pass', 'ms-recipes-writer-ai' ), __( 'Le pass', 'ms-recipes-writer-ai' ), $write, 'msrwa', array( 'MSRWA_Screen_Pass', 'render' ) );
		add_submenu_page( 'msrwa', __( 'Nouveau lot', 'ms-recipes-writer-ai' ), __( 'Nouveau lot', 'ms-recipes-writer-ai' ), $write, 'msrwa-compose', array( 'MSRWA_Screen_Compose', 'render' ) );
		add_submenu_page( 'msrwa', __( 'Articles', 'ms-recipes-writer-ai' ), __( 'Articles', 'ms-recipes-writer-ai' ), $write, 'msrwa-articles', array( 'MSRWA_Screen_Articles', 'render' ) );
		add_submenu_page( 'msrwa', __( 'Analyse', 'ms-recipes-writer-ai' ), __( 'Analyse', 'ms-recipes-writer-ai' ), $manage, 'msrwa-analysis', array( 'MSRWA_Screen_Analysis', 'render' ) );
		add_submenu_page( 'msrwa', __( 'Moteur', 'ms-recipes-writer-ai' ), __( 'Moteur', 'ms-recipes-writer-ai' ), $manage, 'msrwa-engine', array( 'MSRWA_Screen_Engine', 'render' ) );
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
				/* translators: %d is a number of recipes. */
				'confirmDelete' => __( 'Supprimer %d recette(s) et tout ce que le moteur en a rapporté ? C’est irréversible.', 'ms-recipes-writer-ai' ),
				/* translators: 1: how many were done, 2: how many were not. */
				'someSkipped' => __( '%1$d traitée(s), %2$d ignorée(s) : l’action ne s’appliquait pas, ou elles ne vous appartiennent pas.', 'ms-recipes-writer-ai' ),
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
