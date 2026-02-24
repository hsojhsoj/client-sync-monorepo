<?php
/**
 * File: src/pro/includes/services/class-webhook-service.php -> client-sync-pro/includes/services/class-webhook-service.php
 * A service class for sending secure, signed webhook payloads to external URLs.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Services
 */
namespace ClientSyncPro\Services;

use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

class Webhook_Service {

    /**
     * Register AJAX hooks for webhook management.
     */
    public static function register_hooks() {
        add_action( 'wp_ajax_clisyc_test_webhook', [ __CLASS__, 'ajax_test_webhook' ] );
    }

    /**
     * AJAX handler: send a test payload to a webhook URL.
     */
    public static function ajax_test_webhook() {
        check_ajax_referer( 'clisyc_test_webhook', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
        }

        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid URL first.', 'client-sync-pro' ) ] );
        }

        $test_data = [
            'appointment_id' => 0,
            'client_name'    => 'Test Client',
            'client_email'   => 'test@example.com',
            'date'           => current_time( 'Y-m-d' ),
            'time'           => current_time( 'H:i' ),
            'status'         => 'confirmed',
            'note'           => 'This is a test webhook delivery from Client Sync.',
        ];

        self::send_payload( $url, 'test_delivery', $test_data );

        wp_send_json_success( [ 'message' => __( 'Test payload sent successfully.', 'client-sync-pro' ) ] );
    }

    /**
     * Sends a JSON payload to a specified URL with a security signature.
     *
     * @param string $url The destination URL for the webhook.
     * @param string $event The event being triggered (e.g., 'appointment_reminder').
     * @param array $data The data payload to send.
     */
    public static function send_payload(string $url, string $event, array $data) {
        $secret = get_option('clisyc_webhook_secret');
        if (empty($secret)) {
            // Generate a strong, random secret on the first use and save it.
            $secret = wp_generate_password(64, true, true);
            update_option('clisyc_webhook_secret', $secret);
        }

        $payload = ['event' => $event, 'data' => $data, 'timestamp' => time()];
        $signature = hash_hmac('sha256', wp_json_encode($payload), $secret);
        
        $response = wp_remote_post($url, [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-ClientSync-Signature' => $signature,
                'User-Agent' => 'ClientSync-Webhook/' . CLISYC_VERSION,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 10,
        ]);
        
        // Log errors for failed webhook deliveries.
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
            $error_message = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
            Debug_Logger::log( 'Webhook failed for event ' . $event . ' to URL ' . $url . '. Reason: ' . $error_message, 'Webhooks' );
        }
    }
}