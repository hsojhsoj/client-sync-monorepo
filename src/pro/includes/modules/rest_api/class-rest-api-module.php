<?php
/**
 * File: src/pro/includes/modules/rest_api/class-rest-api-module.php
 * Main controller for the Pro REST API module.
 *
 * This module is responsible for loading all Pro-specific REST API controllers
 * and registering their routes. It will only initialize if the Pro license is active.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/RestApi
 */

namespace ClientSyncPro\Modules\RestApi;

use ClientSyncPro\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rest_Api_Module implements Module_Interface {

    /**
     * Initializes the REST API module by registering the hook to load controllers.
     * This method is called by the Module_Manager.
     * {@inheritdoc}
     */
    public function initialize() {
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /**
     * Callback for the 'rest_api_init' hook.
     *
     * It checks for an active license and then loads and registers all
     * Pro REST API controllers and meta fields.
     */
    public function register_rest_routes() {
        // Gate all Pro REST functionality behind the license check.
        if ( ! function_exists( 'clisyc_pro_is_license_active' ) || ! \clisyc_pro_is_license_active() ) {
            return;
        }
        
        // --- START: THE FIX ---
        // Register the meta fields needed by the React editors.
        // This is the correct place for this logic.
        $this->register_meta_fields_for_dimensions();
        // --- END: THE FIX ---

        // Load and register the Appointments controller for series bookings.
        require_once __DIR__ . '/class-appointments-controller.php';
        $appointments_controller = new \ClientSyncPro\RestAPI\Appointments_Controller();
        $appointments_controller->register_routes();

        // Future Pro REST controllers would be loaded and registered here.
    }
    
    /**
     * Registers the '_clisyc_schedule' and other meta fields for all enabled dimension CPTs
     * so they are accessible via the REST API for the block editor.
     */
    private function register_meta_fields_for_dimensions() {
        $registry = get_option( 'clisyc_dimension_registry', [] );
        $enabled_dimensions = $registry['dimensions'] ?? [];

        foreach ( array_keys( $enabled_dimensions ) as $cpt_slug ) {
            if ( post_type_exists( $cpt_slug ) ) {
                register_post_meta( $cpt_slug, '_clisyc_schedule', [
                    'show_in_rest'  => true,
                    'single'        => true,
                    'type'          => 'string',
                    'auth_callback' => function() {
                        return current_user_can( 'edit_posts' );
                    }
                ] );
                register_post_meta( $cpt_slug, '_clisyc_schedule_overrides', [
                    'show_in_rest'  => true,
                    'single'        => true,
                    'type'          => 'string',
                    'auth_callback' => function() {
                        return current_user_can( 'edit_posts' );
                    }
                ] );
            }
        }
    }
}