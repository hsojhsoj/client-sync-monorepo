<?php
/**
 * File: src/pro/includes/class-module-manager.php
 * Discovers and initializes all Pro modules.
 *
 * @package ClientSyncPro
 */

namespace ClientSyncPro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Module_Manager {

    /**
     * An array of instantiated module objects.
     * @var Module_Interface[]
     */
    private $modules = [];

    /**
     * Scans the modules directory, validates, and loads all found modules.
     */
    public function __construct() {
        $module_path = plugin_dir_path( __FILE__ ) . 'modules/';
        
        // Scan for module directories
        foreach ( glob( $module_path . '*', GLOB_ONLYDIR ) as $module_dir ) {
            $module_name = basename( $module_dir );
            // Standardize filename: e.g., for 'forms' directory, look for 'class-forms-module.php'
            $module_file = $module_dir . '/class-' . $module_name . '-module.php';

            if ( file_exists( $module_file ) ) {
                require_once $module_file;

                // Derive the class name from the module name
                // e.g., 'forms' -> 'Forms' -> 'Forms_Module'
                $class_name_base = str_replace( '-', '_', ucwords( $module_name, '-' ) );
                $class_name = "ClientSyncPro\\Modules\\{$class_name_base}\\{$class_name_base}_Module";
                
                if ( class_exists( $class_name ) && in_array( Module_Interface::class, class_implements( $class_name ) ) ) {
                    $this->modules[ $module_name ] = new $class_name();
                }
            }
        }
    }

    /**
     * Loops through all loaded modules and calls their initialize method.
     */
    public function initialize_modules() {
        foreach ( $this->modules as $module ) {
            $module->initialize();
        }
    }
}