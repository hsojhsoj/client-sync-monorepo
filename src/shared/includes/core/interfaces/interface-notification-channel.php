<?php
/**
 * File: src/shared/includes/core/interfaces/interface-notification-channel.php -> client-sync/includes/core/interfaces/interface-notification-channel.php
 * Defines the contract for all notification channels (Email, SMS, etc.).
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core/Interfaces
 */
namespace DependentMedia\ClientSync\Core\Interfaces;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Interface for all notification channels (Email, SMS, Webhook, etc.).
 * Ensures that any channel can be seamlessly integrated into the notification system.
 */
interface Notification_Channel_Interface {
    
    /**
     * Get the unique identifier for the channel.
     * @return string e.g., 'email', 'sms'.
     */
    public function get_id(): string;

    /**
     * Get the human-readable label for the channel for UI purposes.
     * @return string e.g., 'Email', 'SMS'.
     */
    public function get_label(): string;

    /**
     * Send a notification for a given event.
     *
     * @param string $event_type The event being triggered (e.g., 'appointment_reminder').
     * @param array $placeholder_data The processed data for replacement.
     * @param array $recipients An array of recipient data.
     * @return bool True on successful dispatch, false on failure.
     */
    public function send( string $event_type, array $placeholder_data, array $recipients ): bool;
}