<?php
/**
 * Plugin Name: MS Recipes Writer AI
 * Description: Le rédacteur fournit plusieurs recettes et plusieurs photographies ; le plugin les apparie, construit un brief par recette et les envoie toutes au moteur.
 * Version: 0.3.2
 * Author: Mouad Sebhaoui
 * License: GPL-2.0-or-later
 * Text Domain: ms-recipes-writer-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MSRWA_VERSION', '0.3.2' );
define( 'MSRWA_FILE', __FILE__ );
define( 'MSRWA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSRWA_URL', plugin_dir_url( __FILE__ ) );

// Shared logic the engine builds on. The engine loads these itself when it is
// used without WordPress; here they are loaded once, first.
require_once MSRWA_DIR . 'includes/class-msrwa-json.php';
require_once MSRWA_DIR . 'includes/class-msrwa-recipe.php';
require_once MSRWA_DIR . 'includes/class-msrwa-quality.php';
require_once MSRWA_DIR . 'includes/class-msrwa-prompt.php';
require_once MSRWA_DIR . 'includes/class-msrwa-images.php';
require_once MSRWA_DIR . 'includes/class-msrwa-catalog.php';
require_once MSRWA_DIR . 'includes/class-msrwa-cost.php';
require_once MSRWA_DIR . 'includes/class-msrwa-settings.php';
require_once MSRWA_DIR . 'includes/engine/load.php';

// The plugin: one submission, matched, dispatched, and written down.
require_once MSRWA_DIR . 'includes/class-msrwa-db.php';
require_once MSRWA_DIR . 'includes/class-msrwa-engine-settings.php';
require_once MSRWA_DIR . 'includes/class-msrwa-intake.php';
require_once MSRWA_DIR . 'includes/class-msrwa-match.php';
require_once MSRWA_DIR . 'includes/class-msrwa-run.php';
require_once MSRWA_DIR . 'includes/class-msrwa-batch.php';
require_once MSRWA_DIR . 'includes/class-msrwa-draft.php';
require_once MSRWA_DIR . 'includes/class-msrwa-rest.php';
require_once MSRWA_DIR . 'includes/class-msrwa-admin.php';
require_once MSRWA_DIR . 'includes/class-msrwa-operations.php';
require_once MSRWA_DIR . 'includes/class-msrwa-plugin.php';

register_activation_hook( __FILE__, array( 'MSRWA_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MSRWA_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'MSRWA_Plugin', 'boot' ) );
