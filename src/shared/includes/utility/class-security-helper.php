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

    /**
     * Neutralize CSV formula injection in a single cell value.
     *
     * A booking form user can submit a custom-field / notes value starting
     * with `=`, `+`, `-`, `@`, `\t`, or `\r`. When the admin exports those
     * records to CSV and opens the file in Excel / Google Sheets, the cell
     * is interpreted as a formula — enabling =HYPERLINK() phishing,
     * =WEBSERVICE() cell exfiltration, or (with Excel's DDE enabled)
     * command execution. This preventative prefixes a single quote so the
     * spreadsheet app treats the value as plain text.
     *
     * Apply to every cell value fed into `fputcsv()` that could contain
     * user-submitted data (client names, notes, custom-field values, etc.).
     * Header cells and integer IDs don't need this but passing them through
     * is harmless — the guard is a no-op on non-danger-character input.
     *
     * See OWASP "CSV Injection" (a.k.a. Formula Injection).
     *
     * @param mixed $value Cell value. Non-strings are stringified.
     * @return string      Safe cell value.
     */
    public static function csv_escape_cell( $value ): string {
        $str = (string) $value;
        if ( '' === $str ) {
            return $str;
        }
        $first = $str[0];
        if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first || "\t" === $first || "\r" === $first ) {
            return "'" . $str;
        }
        return $str;
    }

    /**
     * Map `csv_escape_cell` over an entire row. Convenience for the common
     * `fputcsv( $output, Security_Helper::csv_escape_row( $row ) )` pattern.
     *
     * @param array $row
     * @return array
     */
    public static function csv_escape_row( array $row ): array {
        return array_map( [ self::class, 'csv_escape_cell' ], $row );
    }
}