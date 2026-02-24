<?php
/**
 * File: src/shared/includes/utility/class-query-monitor.php
 * Lightweight dev-mode query monitor that detects excessive database queries.
 *
 * Only active when SAVEQUERIES is defined and true. Logs warnings to the
 * PHP error log when a Client Sync page exceeds the configured query threshold,
 * helping catch N+1 query regressions during development.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Utility
 */

namespace DependentMedia\ClientSync\Utility;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Query_Monitor {

    /**
     * Maximum queries before a warning is logged.
     */
    private const QUERY_THRESHOLD = 50;

    /**
     * Maximum duplicate query ratio before a warning is logged.
     * If more than this percentage of queries are duplicates, flag it.
     */
    private const DUPLICATE_RATIO_THRESHOLD = 0.15;

    /**
     * Register the shutdown hook.
     * Only hooks in when SAVEQUERIES is enabled — zero overhead otherwise.
     */
    public function register_hooks(): void {
        if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
            return;
        }

        // Only monitor admin pages (where our plugin runs).
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'shutdown', [ $this, 'analyze_queries' ], 9999 );
    }

    /**
     * Analyze queries recorded by WordPress and log warnings if thresholds exceeded.
     */
    public function analyze_queries(): void {
        global $wpdb;

        // Only analyze on Client Sync pages.
        if ( ! $this->is_clisyc_request() ) {
            return;
        }

        $queries     = $wpdb->queries ?? [];
        $total       = count( $queries );
        $page_id     = $this->get_page_identifier();

        // Check total query count threshold.
        if ( $total > self::QUERY_THRESHOLD ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional dev-mode logging.
            error_log( sprintf(
                '[Client Sync Query Monitor] WARNING: %d queries on "%s" (threshold: %d). Review for N+1 patterns.',
                $total,
                $page_id,
                self::QUERY_THRESHOLD
            ) );
        }

        // Check for duplicate queries (N+1 pattern indicator).
        $query_strings = [];
        foreach ( $queries as $q ) {
            // Normalize: strip specific IDs/values to group similar queries.
            $normalized = preg_replace( '/\b\d+\b/', '?', $q[0] );
            $normalized = preg_replace( "/'.+?'/", "'?'", $normalized );
            $query_strings[] = $normalized;
        }

        $counts     = array_count_values( $query_strings );
        $duplicates = array_filter( $counts, function ( $count ) {
            return $count > 3;
        } );

        if ( ! empty( $duplicates ) && $total > 0 ) {
            $dup_total  = array_sum( $duplicates );
            $dup_ratio  = $dup_total / $total;

            if ( $dup_ratio > self::DUPLICATE_RATIO_THRESHOLD ) {
                // Log the top offenders.
                arsort( $duplicates );
                $top = array_slice( $duplicates, 0, 5, true );
                $details = [];
                foreach ( $top as $query => $count ) {
                    $details[] = sprintf( '  [%dx] %s', $count, substr( $query, 0, 120 ) );
                }

                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional dev-mode logging.
                error_log( sprintf(
                    "[Client Sync Query Monitor] N+1 SUSPECT on \"%s\": %d/%d queries (%.0f%%) are duplicates.\nTop repeated queries:\n%s",
                    $page_id,
                    $dup_total,
                    $total,
                    $dup_ratio * 100,
                    implode( "\n", $details )
                ) );
            }
        }
    }

    /**
     * Detect whether the current request is a Client Sync admin page or REST API call.
     *
     * @return bool
     */
    private function is_clisyc_request(): bool {
        // Check for REST API requests to our namespace.
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $rest_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
            return strpos( $rest_route, '/clisyc/' ) !== false
                || strpos( $rest_route, '/client-sync-pro/' ) !== false;
        }

        // Check for admin pages.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for logging context only.
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( strpos( $page, 'client-sync' ) !== false || strpos( $page, 'clisyc' ) !== false ) {
            return true;
        }

        // Check for CPT screens.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen ) {
            $post_type = $screen->post_type ?? '';
            if ( strpos( $post_type, 'clisyc_' ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a human-readable identifier for the current page/request.
     *
     * @return string
     */
    private function get_page_identifier(): string {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return 'REST ' . ( $GLOBALS['wp']->query_vars['rest_route'] ?? 'unknown' );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for logging context only.
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( $page ) {
            return 'admin:' . $page;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen ) {
            return 'screen:' . $screen->id;
        }

        return 'unknown';
    }
}
