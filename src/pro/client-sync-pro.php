<?php
/**
 * File: src/pro/client-sync-pro.php
 * Main plugin file for the Client Sync Pro add-on.
 *
 * @wordpress-plugin
 * Plugin Name:       Client Sync Pro
 * Description:       Unlocks advanced features for the Client Sync plugin.
 * Version:           1.6.3
 * Author:            Joshua Jordan
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       client-sync-pro
 */


if ( ! defined( 'WPINC' ) ) die;

// --- START: REVISED PLUGIN LOADING LOGIC ---

function clisyc_pro_load() {
	if ( ! class_exists( 'DependentMedia\ClientSync\Plugin' ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error"><p><strong>Client Sync Pro deactivated:</strong> The core Client Sync plugin must be installed and active for the Pro add-on to function.</p></div>';
		});
		deactivate_plugins( plugin_basename( __FILE__ ) );
		return;
	}

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-license-manager.php';
	$license_manager = new \ClientSyncPro\License_Manager();
	$license_manager->register_hooks();

	// Self-hosted auto-update checker (pass.dependentmedia.com).
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-update-manager.php';
	( new \ClientSyncPro\Update_Manager() )->register_hooks();
    
    require_once plugin_dir_path( __FILE__ ) . 'includes/Module_Interface.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-module-manager.php';
    
    $module_manager = new \ClientSyncPro\Module_Manager();
    $module_manager->initialize_modules();

    add_filter('clisyc_notification_channels', 'clisyc_pro_register_notification_channels');

    require_once plugin_dir_path( __FILE__ ) . 'includes/services/class-webhook-service.php';
    \ClientSyncPro\Services\Webhook_Service::register_hooks();
}

add_action( 'plugins_loaded', 'clisyc_pro_load', 11 );

// --- END: REVISED PLUGIN LOADING LOGIC ---


function clisyc_pro_is_license_active() {
    return \ClientSyncPro\License_Manager::is_license_active();
}

function clisyc_pro_register_notification_channels($channels) {
    if ( ! clisyc_pro_is_license_active() ) {
        return $channels;
    }
    
    require_once plugin_dir_path(__FILE__) . 'includes/notifications/class-sms-channel.php';
    require_once plugin_dir_path(__FILE__) . 'includes/notifications/class-webhook-channel.php';
    
    $channels['sms'] = new \ClientSyncPro\Notifications\Sms_Channel();
    $channels['webhook'] = new \ClientSyncPro\Notifications\Webhook_Channel();
    
    return $channels;
}