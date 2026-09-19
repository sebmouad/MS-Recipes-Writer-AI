<?php
/**
 * Plugin Name: MS Recipes Writer AI
 * Description: Génération éditoriale culinaire orchestrée avec fournisseurs IA, validation et file persistante.
 * Version: 0.1.0
 * Author: Mouad Sebhaoui
 * License: GPL-2.0-or-later
 * Text Domain: ms-recipes-writer-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MSRWA_VERSION', '0.1.0' );
define( 'MSRWA_FILE', __FILE__ );
define( 'MSRWA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSRWA_URL', plugin_dir_url( __FILE__ ) );

require_once MSRWA_DIR . 'includes/class-msrwa-db.php';
require_once MSRWA_DIR . 'includes/class-msrwa-settings.php';
require_once MSRWA_DIR . 'includes/class-msrwa-catalog.php';
require_once MSRWA_DIR . 'includes/class-msrwa-openai.php';
require_once MSRWA_DIR . 'includes/class-msrwa-queue.php';
require_once MSRWA_DIR . 'includes/class-msrwa-rest.php';
require_once MSRWA_DIR . 'includes/class-msrwa-admin.php';
require_once MSRWA_DIR . 'includes/class-msrwa-plugin.php';

register_activation_hook( __FILE__, array( 'MSRWA_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MSRWA_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'MSRWA_Plugin', 'boot' ) );
