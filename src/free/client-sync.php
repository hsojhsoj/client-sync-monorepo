<?php
/**
 * File: src/free/client-sync.php
 * Main entry point for the Client Sync plugin.
 *
 * @package   Client_Sync
 * @author    Joshua Jordan
 *
 * @wordpress-plugin
 * Plugin Name:       Client Sync
 * Plugin URI:        https://dependentmedia.com/client-sync/
 * Description:       A powerful and high-performance WordPress plugin to manage client registrations, appointments, payments, and appointment notes from a single, integrated system.
 * Version:           3.7.0
 * Author:            Joshua Jordan
 * Text Domain:       client-sync
 * Domain Path:       /languages
 * Author URI:        https://dependentmedia.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'clisyc_PLUGIN_DIR' ) ) {
	define( 'clisyc_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'clisyc_PLUGIN_URL' ) ) {
	define( 'clisyc_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'CLISYC_VERSION' ) ) {
	define( 'CLISYC_VERSION', '3.7.0' );
}

// --- START: THIS IS THE CORRECTED FIX ---
// This constant provides a reliable path to the 'shared' directory itself,
// allowing internal require statements to be consistent.
// Guard: may already be defined by the PHPUnit bootstrap or Composer autoload.
if ( ! defined( 'CLISYC_SHARED_DIR' ) ) {
    if ( file_exists( __DIR__ . '/../shared' ) ) {
        // We are in the monorepo (src/free/ -> src/shared/)
        define( 'CLISYC_SHARED_DIR', __DIR__ . '/../shared/' );
    } else {
        // We are in a built plugin (client-sync/ root)
        define( 'CLISYC_SHARED_DIR', __DIR__ . '/' );
    }
}
// --- END: THIS IS THE CORRECTED FIX ---

// PSR-4 autoloader — handles all class/interface/trait loading automatically.
require_once CLISYC_SHARED_DIR . 'includes/autoloader.php';

// The Plugin class lives in core/ but uses the root namespace — load it explicitly.
require_once CLISYC_SHARED_DIR . 'includes/core/class-plugin.php';


/**
 * Begins execution of the plugin.
 */
function clisyc_run_plugin() {
	$plugin = new \DependentMedia\ClientSync\Plugin();
	$plugin->run();
}

clisyc_run_plugin();