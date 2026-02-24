<?php
/**
 * File: src/shared/includes/utility/class-security-helper.php
 * Provides security utilities like Rate Limiting for REST API endpoints.
 */

namespace DependentMedia\ClientSync\Utility;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Security_Helper {

    /**
     * Check if the current request has exceeded the rate limit.
     *
     * @param string $action  A unique identifier for the action (e.g., 'fetch_slots').
     * @param int    $limit   Max requests allowed.
     * @param int    $seconds Time window in seconds.
     * @return bool|\WP_Error True if allowed, WP_Error if limit exceeded.
     */
    public static function check_rate_limit( string $action, int $limit = 50, int $seconds = 60 ) {
        // Bypass for site administrators only. Using manage_options rather than
        // edit_posts to prevent Contributors/Authors from bypassing rate limits.
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        // FIX: Sanitize and unslash the IP address
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        
        if ( empty( $ip ) ) {
            return true; // Cannot limit without IP
        }

        // Anonymize IP slightly for privacy/GDPR compliance if needed, 
        // but raw IP is standard for security throttling.
        $ip_hash = md5( $ip . $action ); 
        $transient_key = 'clisyc_rate_limit_' . $ip_hash;

        $data = get_transient( $transient_key );

        if ( is_array( $data ) ) {
            // Window still active — check if limit exceeded.
            if ( $data['count'] >= $limit ) {
                return new \WP_Error(
                    'rate_limit_exceeded',
                    __( 'Too many requests. Please try again later.', 'client-sync' ),
                    [ 'status' => 429 ]
                );
            }

            // Increment count. Re-set the transient with the remaining TTL
            // so the window doesn't slide forward on every request.
            $remaining_ttl = max( 1, $data['expires'] - time() );
            $data['count']++;
            set_transient( $transient_key, $data, $remaining_ttl );
        } else {
            // First request — start a new window.
            $data = [
                'count'   => 1,
                'expires' => time() + $seconds,
            ];
            set_transient( $transient_key, $data, $seconds );
        }

        return true;
    }
    
    /**
     * Validates that a request context is safe.
     *
     * @param string $context The context string passed from JS.
     * @return bool
     */
    public static function validate_admin_context( $context ) {
        if ( 'admin_editable' === $context || 'admin' === $context ) {
            return current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' );
        }
        return false;
    }
}