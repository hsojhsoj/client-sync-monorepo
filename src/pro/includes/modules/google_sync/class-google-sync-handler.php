<?php
/**
 * File: client-sync-pro/includes/modules/google_sync/class-google-sync-handler.php
 * Handles admin-post actions for Google Sync.
 * MODIFIED: Added a handler for the manual "Sync Now" button and whitelisted Google for safe redirection.
 * @package ClientSyncPro
 */

namespace ClientSyncPro;

use ClientSyncPro\Services\Google_Sync_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

class Google_Sync_Handler {
    public function __construct() {
        add_action( 'admin_post_clisyc_google_sync_redirect', [ $this, 'handle_auth_redirect' ] );
        add_action( 'admin_post_clisyc_google_sync_disconnect', [ $this, 'handle_disconnect' ] );
        add_action( 'admin_post_clisyc_google_sync_now', [ $this, 'handle_manual_sync' ] );
    }

    public function handle_auth_redirect() {
        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( $_GET['_wpnonce'] ) : '';

        if ( ! $user_id || ! wp_verify_nonce( $nonce, 'google_sync_auth_' . $user_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        $sync_service = new Google_Sync_Service();
        $auth_url = $sync_service->get_auth_url( $user_id );

        if ( ! $auth_url ) {
            wp_die( 'Could not generate authorization URL. Please ensure Google API credentials are set in Client Sync settings.' );
        }

        // --- START: THE FIX ---
        // Whitelist the Google domain so wp_safe_redirect allows it.
        add_filter( 'allowed_redirect_hosts', function( $hosts ) {
            $hosts[] = 'accounts.google.com';
            return $hosts;
        });

        wp_safe_redirect( $auth_url );
        exit; // Always call exit after a redirect
        // --- END: THE FIX ---
    }
    
    public function handle_disconnect() {
        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( $_GET['_wpnonce'] ) : '';

        if ( ! $user_id || ! wp_verify_nonce( $nonce, 'google_sync_disconnect_' . $user_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }
        
        Google_Sync_Service::disconnect( $user_id );
        
        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }

    // START: NEW HANDLER METHOD
    public function handle_manual_sync() {
        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( $_GET['_wpnonce'] ) : '';

        if ( ! $user_id || ! wp_verify_nonce( $nonce, 'google_sync_now_' . $user_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        $sync_service = new Google_Sync_Service();
        $result = $sync_service->sync_calendar_from_google( $user_id );
        
        set_transient( 'clisyc_admin_feedback', [ 'message' => $result['message'] ], 30 );
        
        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }
    // END: NEW HANDLER METHOD
}