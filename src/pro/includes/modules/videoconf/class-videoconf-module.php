<?php
/**
 * File: src/pro/includes/modules/videoconf/class-videoconf-module.php
 * Main controller for the Video Conferencing module.
 *
 * Auto-discovered by Module_Manager. Loads Zoom and Google Meet providers,
 * the notification bridge, and the manager that wires appointment hooks.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Videoconf
 */

namespace ClientSyncPro\Modules\Videoconf;

use ClientSyncPro\Module_Interface;
use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) exit;

class Videoconf_Module implements Module_Interface {

    /**
     * Initialize the Video Conferencing module.
     *
     * Only loads when Pro license is active AND a video provider is configured.
     *
     * {@inheritdoc}
     */
    public function initialize() {
        // Gate behind Pro license.
        if ( ! function_exists( 'clisyc_pro_is_license_active' ) || ! \clisyc_pro_is_license_active() ) {
            return;
        }

        // Skip if no provider is configured.
        if ( get_option( Constants::OPTION_VIDEO_PROVIDER, 'none' ) === 'none' ) {
            return;
        }

        // Load all module classes.
        require_once __DIR__ . '/providers/interface-videoconf-provider.php';
        require_once __DIR__ . '/providers/class-google-meet-provider.php';
        require_once __DIR__ . '/providers/class-zoom-provider.php';
        require_once __DIR__ . '/services/class-zoom-auth-service.php';
        require_once __DIR__ . '/class-videoconf-provider-factory.php';
        require_once __DIR__ . '/class-videoconf-manager.php';
        require_once __DIR__ . '/class-videoconf-notification-bridge.php';

        // Instantiate controllers — they register hooks in their constructors.
        new Videoconf_Manager();
        new Videoconf_Notification_Bridge();
    }
}
