<?php
/**
 * File: src/pro/includes/modules/google_sync/class-google-sync-module.php
 * Main controller for the Google Sync module.
 *
 * This module is responsible for loading all classes and registering all hooks
 * related to Google Calendar synchronization. It will only initialize its
 * functionality if the Pro license is active.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/GoogleSync
 */

namespace ClientSyncPro\Modules\GoogleSync;

use ClientSyncPro\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Google_Sync_Module implements Module_Interface {

    /**
     * Initializes the Google Sync module.
     *
     * This method checks if the Pro license is active before loading any files
     * or registering any hooks. This ensures the feature is properly gated.
     *
     * {@inheritdoc}
     */
    public function initialize() {
        // Gate the entire feature behind the license check.
        if ( ! function_exists( 'clisyc_pro_is_license_active' ) || ! \clisyc_pro_is_license_active() ) {
            return;
        }

        // Load all necessary classes for this module.
        // Note the use of __DIR__ for reliable pathing.
        require_once __DIR__ . '/class-google-sync-handler.php';
        require_once __DIR__ . '/class-google-sync-manager.php';
        require_once __DIR__ . '/services/class-google-sync-service.php'; 

        // Instantiate the main controllers to register their hooks.
        // The service class is primarily used by these controllers, so it doesn't need its own instance here.
        new \ClientSyncPro\Google_Sync_Handler();
        new \ClientSyncPro\Google_Sync_Manager();
    }

}