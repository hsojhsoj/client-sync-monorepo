<?php
/**
 * File: src/pro/includes/notifications/class-webhook-channel.php -> client-sync-pro/includes/notifications/class-webhook-channel.php
 * Implements the notification channel for firing webhooks based on plugin events.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Notifications
 */
namespace ClientSyncPro\Notifications;

use DependentMedia\ClientSync\Core\Interfaces\Notification_Channel_Interface;
use ClientSyncPro\Services\Webhook_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

class Webhook_Channel implements Notification_Channel_Interface {
    
    public function get_id(): string { return 'webhook'; }
    public function get_label(): string { return __('Webhook', 'client-sync-pro'); }

    public function send(string $event_type, array $placeholder_data, array $recipients): bool {
        $webhooks = get_option('clisyc_webhooks', []);
        if (empty($webhooks) || !is_array($webhooks)) {
            return false;
        }

        $was_sent = false;
        
        foreach ($webhooks as $webhook) {
            if ( !empty($webhook['url']) && filter_var($webhook['url'], FILTER_VALIDATE_URL) &&
                 !empty($webhook['events']) && is_array($webhook['events']) &&
                 !empty($webhook['enabled']) &&
                 in_array($event_type, $webhook['events'], true)
            ) {
                Webhook_Service::send_payload($webhook['url'], $event_type, $placeholder_data);
                $was_sent = true;
            }
        }
        
        return $was_sent;
    }
}