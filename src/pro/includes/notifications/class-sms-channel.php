<?php
/**
 * File: src/pro/includes/notifications/class-sms-channel.php -> client-sync-pro/includes/notifications/class-sms-channel.php
 * Implements the notification channel for sending SMS reminders and alerts.
 *
 * UPDATED: Added HIPAA compliance safety checks. SMS messages will use
 * pre-sanitized placeholder data when HIPAA mode is active.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Notifications
 */
namespace ClientSyncPro\Notifications;

use DependentMedia\ClientSync\Core\Interfaces\Notification_Channel_Interface;
use DependentMedia\ClientSync\Utility\Debug_Logger;
use ClientSyncPro\Services\Sms_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

class Sms_Channel implements Notification_Channel_Interface {
    
    public function get_id(): string { return 'sms'; }
    public function get_label(): string { return __('SMS', 'client-sync-pro'); }

    public function send(string $event_type, array $placeholder_data, array $recipients): bool {
        // Load the phone normalizer utility.
        require_once dirname( __DIR__ ) . '/utilities/class-phone-normalizer.php';

        $settings = get_option('clisyc_notification_settings', [])[$event_type] ?? [];
        if (empty($settings['sms_enabled'])) {
            return false;
        }

        $sms_body = $settings['sms_body'] ?? '';
        if (empty(trim($sms_body))) return false;

        // =========================================================================
        // HIPAA COMPLIANCE: Additional safety check for SMS
        // SMS is particularly sensitive as messages may be visible on lock screens.
        // The Notifications class should have already sanitized PHI placeholders,
        // but we add an extra layer of protection here.
        // =========================================================================
        $placeholder_data = $this->ensure_hipaa_safe_for_sms( $placeholder_data );

        $message = $this->process_placeholders($sms_body, $placeholder_data);
        $sms_sent = false;

        foreach ($recipients as $recipient) {
            if ($recipient['type'] === 'client' && !empty($placeholder_data['{client_id}'])) {
                $client_id = (int) $placeholder_data['{client_id}'];

                if (get_user_meta($client_id, 'clisyc_sms_opt_out', true)) {
                    continue;
                }

                $phone = get_user_meta($client_id, 'phone_number', true);
                if ($phone) {
                    // Normalize to E.164 format required by Twilio.
                    $phone = \ClientSyncPro\Utilities\Phone_Normalizer::to_e164( $phone );
                    if ( ! \ClientSyncPro\Utilities\Phone_Normalizer::is_valid_e164( $phone ) ) {
                        Debug_Logger::log( 'Invalid E.164 phone format for client ID ' . $client_id, 'SMS' );
                        continue;
                    }

                    if (Sms_Service::send_sms($phone, $message, $event_type)) {
                        if (isset($placeholder_data['{appointment_id}']) && is_numeric($placeholder_data['{appointment_id}'])) {
                            update_post_meta($placeholder_data['{appointment_id}'], '_clisyc_sms_last_sent_type', $event_type);
                            update_post_meta($placeholder_data['{appointment_id}'], '_clisyc_sms_last_sent_time', current_time('mysql'));
                        }
                        $sms_sent = true;
                    }
                }
            }
        }
        return $sms_sent;
    }

    /**
     * Ensure placeholder data is HIPAA-safe for SMS transmission.
     * 
     * SMS messages are particularly sensitive because:
     * - They may appear on device lock screens
     * - They are stored by carriers
     * - They may be forwarded or screenshot
     * 
     * This method provides an additional safety layer beyond what
     * the Notifications class provides.
     * 
     * @param array $placeholder_data The placeholder data from Notifications.
     * @return array The HIPAA-safe placeholder data.
     */
    private function ensure_hipaa_safe_for_sms( array $placeholder_data ): array {
        // Check if HIPAA mode is active
        if ( ! $this->is_hipaa_mode_active() ) {
            return $placeholder_data;
        }

        // If already anonymized by Notifications class, we're good
        if ( ! empty( $placeholder_data['_hipaa_anonymized'] ) ) {
            return $placeholder_data;
        }

        // Fallback: Apply SMS-specific anonymization
        // This should rarely be needed as Notifications handles it,
        // but provides defense-in-depth
        $sensitive_placeholders = [
            '{appointment_notes}' => __( '[See portal]', 'client-sync-pro' ),
            '{client_username}'   => '',
            '{phone_number}'      => '',
        ];

        foreach ( $sensitive_placeholders as $key => $replacement ) {
            if ( isset( $placeholder_data[ $key ] ) && ! empty( $placeholder_data[ $key ] ) ) {
                $placeholder_data[ $key ] = $replacement;
            }
        }

        // Anonymize any custom field placeholders in HIPAA mode
        foreach ( $placeholder_data as $key => $value ) {
            if ( strpos( $key, '{appointment_field_' ) === 0 && ! empty( $value ) ) {
                // In SMS, we're extra cautious - anonymize all custom fields in HIPAA mode
                $placeholder_data[ $key ] = __( '[See portal]', 'client-sync-pro' );
            }
        }

        return $placeholder_data;
    }

    /**
     * Check if HIPAA mode is currently active.
     * 
     * @return bool True if HIPAA mode is active.
     */
    private function is_hipaa_mode_active(): bool {
        if ( function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) ) {
            return \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode();
        }
        return false;
    }

    private function process_placeholders(string $template, array $data): string {
        $clean_data = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $clean_data[$key] = (string) $value;
            }
        }
        if (!empty($clean_data)) {
            $template = str_replace(array_keys($clean_data), array_values($clean_data), $template);
        }
        $template = preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $template);
        return $template;
    }
}