<?php
/**
 * File: src/pro/includes/modules/packages/class-packages-module.php -> /client-sync-pro/includes/modules/packages/class-packages-module.php
 * Main controller for the Packages & Credits module.
 * REFACTORED: Now implements the Module_Interface for automatic loading.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Packages
 */

namespace ClientSyncPro\Modules\Packages;

// ADDED: Use statement for the new interface.
use ClientSyncPro\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) exit;

// MODIFIED: Class now implements the interface.
class Packages_Module implements Module_Interface {

    /**
     * Initializes the module by loading its components and registering their hooks.
     * This method is called by the Module_Manager.
     * {@inheritdoc}
     */
    public function initialize() {
        require_once __DIR__ . '/class-packages-cpt.php';
        $packages_cpt = new Packages_CPT();
        $packages_cpt->register_hooks();

        if ( class_exists( 'WooCommerce' ) ) {
            require_once __DIR__ . '/class-package-manager.php';
            $package_manager = new Package_Manager();
            $package_manager->register_hooks();
        }

        add_action( 'clisyc_register_extra_submenu_items', [ $this, 'add_menu_items' ] );
    }

    /**
     * Adds the Packages CPT link to the plugin submenu.
     * 
     * @param string $main_page_slug The main menu slug.
     */
    public function add_menu_items( $main_page_slug ) {
        add_submenu_page( 
            $main_page_slug, 
            __( 'Packages', 'client-sync-pro' ), 
            __( 'Packages', 'client-sync-pro' ), 
            'manage_options', 
            'edit.php?post_type=clisyc_package' 
        );
    }

}