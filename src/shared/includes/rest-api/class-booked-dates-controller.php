<?php
/**
 * File: src/shared/includes/rest-api/class-booked-dates-controller.php
 * REST API endpoint for retrieving booked dates for date-range mode.
 * 
 * Endpoint: GET /clisyc/v1/slots/booked-dates
 * 
 * Parameters:
 * - start: Start date (ISO 8601)
 * - end: End date (ISO 8601)  
 * - dimension_id: The primary dimension ID (e.g., room/property ID)
 * 
 * Returns: Array of booked date strings (YYYY-MM-DD format)
 */

namespace DependentMedia\ClientSync\RestAPI;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Security_Helper;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Booked_Dates_Controller extends WP_REST_Controller {

    protected $namespace = 'clisyc/v1';
    protected $rest_base = 'slots/booked-dates';

    /**
     * Register routes.
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_booked_dates' ],
                    'permission_callback' => '__return_true', // Public endpoint
                    'args'                => $this->get_collection_params(),
                ],
            ]
        );
    }

    /**
     * Get collection parameters.
     */
    public function get_collection_params() {
        return [
            'start' => [
                'required'          => true,
                'type'              => 'string',
                'format'            => 'date-time',
                'description'       => 'Start date in ISO 8601 format',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'end' => [
                'required'          => true,
                'type'              => 'string',
                'format'            => 'date-time',
                'description'       => 'End date in ISO 8601 format',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'dimension_id' => [
                'required'          => true,
                'type'              => 'integer',
                'description'       => 'Primary dimension post ID',
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /**
     * Get booked dates for a dimension within a date range.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_booked_dates( WP_REST_Request $request ) {
        $rate_check = Security_Helper::check_rate_limit( 'booked_dates', Constants::RATE_LIMIT_BOOKED_DATES, Constants::RATE_LIMIT_WINDOW_SECS );
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        $start_str    = $request->get_param( 'start' );
        $end_str      = $request->get_param( 'end' );
        $dimension_id = $request->get_param( 'dimension_id' );

        // Validate dimension exists and is actually a dimension CPT.
        $dimension_post = get_post( $dimension_id );
        if ( ! $dimension_post ) {
            return new WP_Error(
                'invalid_dimension',
                __( 'Invalid dimension ID', 'client-sync' ),
                [ 'status' => 400 ]
            );
        }

        // Verify the post belongs to a registered dimension CPT.
        $registry   = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
        $dim_slugs  = array_keys( $registry['dimensions'] ?? [] );
        if ( ! in_array( $dimension_post->post_type, $dim_slugs, true ) ) {
            return new WP_Error(
                'invalid_dimension',
                __( 'Invalid dimension ID', 'client-sync' ),
                [ 'status' => 400 ]
            );
        }

        try {
            $start_date = new \DateTime( $start_str );
            $end_date   = new \DateTime( $end_str );
        } catch ( \Exception $e ) {
            return new WP_Error(
                'invalid_dates',
                __( 'Invalid date format', 'client-sync' ),
                [ 'status' => 400 ]
            );
        }

        // Cap the date range to 366 days maximum.
        if ( $start_date->diff( $end_date )->days > 366 ) {
            return new WP_Error(
                'range_too_large',
                __( 'Date range too large. Maximum 366 days allowed.', 'client-sync' ),
                [ 'status' => 400 ]
            );
        }

        // Get the primary dimension slug
        $primary_dim_slug = $this->get_primary_dimension_slug();
        if ( ! $primary_dim_slug ) {
            return new WP_Error(
                'no_primary_dimension',
                __( 'No primary dimension configured', 'client-sync' ),
                [ 'status' => 500 ]
            );
        }

        // Fetch booked dates
        $booked_dates = $this->fetch_booked_dates(
            $start_date->format( 'Y-m-d' ),
            $end_date->format( 'Y-m-d' ),
            $dimension_id,
            $primary_dim_slug
        );

        return new WP_REST_Response( $booked_dates, 200 );
    }

    /**
     * Fetch all booked dates for a dimension within a date range.
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date   End date (Y-m-d)
     * @param int    $dimension_id Primary dimension ID
     * @param string $primary_dim_slug Primary dimension slug
     * @return array Array of booked date strings
     */
    private function fetch_booked_dates( $start_date, $end_date, $dimension_id, $primary_dim_slug ) {
        global $wpdb;

        $booked_dates = [];
        $post_type    = Constants::POST_TYPE_APPOINTMENT;

        // Query for date_range bookings (using start_date and end_date meta)
        // SECURITY: All meta keys and post type use %s placeholders instead of PHP interpolation.
        $date_range_query = $wpdb->prepare(
            "SELECT DISTINCT
                pm_start.meta_value as start_date,
                pm_end.meta_value as end_date
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_start
                ON p.ID = pm_start.post_id AND pm_start.meta_key = %s
            INNER JOIN {$wpdb->postmeta} pm_end
                ON p.ID = pm_end.post_id AND pm_end.meta_key = %s
            INNER JOIN {$wpdb->postmeta} pm_mode
                ON p.ID = pm_mode.post_id AND pm_mode.meta_key = %s AND pm_mode.meta_value = 'date_range'
            INNER JOIN {$wpdb->postmeta} pm_dim
                ON p.ID = pm_dim.post_id AND pm_dim.meta_key = %s AND pm_dim.meta_value = %s
            WHERE p.post_type = %s
            AND p.post_status IN ('publish', 'confirmed', 'clisyc_pending_pay', 'clisyc_paid_on_day', 'wc-processing', 'wc-completed')
            AND (
                (pm_start.meta_value <= %s AND pm_end.meta_value >= %s)
                OR (pm_start.meta_value >= %s AND pm_start.meta_value <= %s)
            )",
            Constants::META_START_DATE,
            Constants::META_END_DATE,
            Constants::META_BOOKING_MODE,
            '_clisyc_' . str_replace( 'clisyc_', '', $primary_dim_slug ),
            $dimension_id,
            $post_type,
            $end_date,
            $start_date,
            $start_date,
            $end_date
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above; caching not appropriate for real-time availability data.
        $date_range_bookings = $wpdb->get_results( $date_range_query, ARRAY_A );

        // Also check for slot-based bookings that might overlap with date range queries
        // This handles cases where someone has mixed booking modes
        $slot_query = $wpdb->prepare(
            "SELECT DISTINCT
                DATE(pm_slot.meta_value) as booking_date
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_slot
                ON p.ID = pm_slot.post_id AND pm_slot.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} pm_mode
                ON p.ID = pm_mode.post_id AND pm_mode.meta_key = %s
            INNER JOIN {$wpdb->postmeta} pm_dim
                ON p.ID = pm_dim.post_id AND pm_dim.meta_key = %s AND pm_dim.meta_value = %s
            WHERE p.post_type = %s
            AND p.post_status IN ('publish', 'confirmed', 'clisyc_pending_pay', 'clisyc_paid_on_day', 'wc-processing', 'wc-completed')
            AND (pm_mode.meta_value IS NULL OR pm_mode.meta_value = 'slot')
            AND DATE(pm_slot.meta_value) >= %s
            AND DATE(pm_slot.meta_value) <= %s",
            Constants::META_TIME_SLOT,
            Constants::META_BOOKING_MODE,
            '_clisyc_' . str_replace( 'clisyc_', '', $primary_dim_slug ),
            $dimension_id,
            $post_type,
            $start_date,
            $end_date
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above; caching not appropriate for real-time availability data.
        $slot_bookings = $wpdb->get_col( $slot_query );

        // Process date range bookings - expand to individual dates
        foreach ( $date_range_bookings as $booking ) {
            $range_start = new \DateTime( $booking['start_date'] );
            $range_end   = new \DateTime( $booking['end_date'] );
            
            // Include all dates from start to end-1 (checkout date is available)
            while ( $range_start < $range_end ) {
                $date_str = $range_start->format( 'Y-m-d' );
                
                // Only add if within requested range
                if ( $date_str >= $start_date && $date_str <= $end_date ) {
                    $booked_dates[] = $date_str;
                }
                
                $range_start->modify( '+1 day' );
            }
        }

        // Add slot-based booking dates
        foreach ( $slot_bookings as $date ) {
            if ( $date && ! in_array( $date, $booked_dates, true ) ) {
                $booked_dates[] = $date;
            }
        }

        // Also get any buffer days configured
        $buffer_days = $this->get_buffer_days( $dimension_id );
        if ( $buffer_days > 0 ) {
            $booked_dates = $this->apply_buffer_days( $booked_dates, $buffer_days, $start_date, $end_date );
        }

        // Sort and remove duplicates
        $booked_dates = array_unique( $booked_dates );
        sort( $booked_dates );

        return $booked_dates;
    }

    /**
     * Get buffer days setting for a dimension.
     *
     * @param int $dimension_id
     * @return int
     */
    private function get_buffer_days( $dimension_id ) {
        // Check dimension-specific setting first
        $buffer = (int) get_post_meta( $dimension_id, '_clisyc_buffer_days', true );
        
        if ( $buffer > 0 ) {
            return $buffer;
        }

        // Fall back to global setting
        return (int) get_option( Constants::OPTION_DEFAULT_BUFFER_DAYS, 0 );
    }

    /**
     * Apply buffer days around booked dates.
     *
     * @param array  $booked_dates Original booked dates
     * @param int    $buffer_days  Number of buffer days
     * @param string $range_start  Range start date
     * @param string $range_end    Range end date
     * @return array Updated booked dates with buffers
     */
    private function apply_buffer_days( $booked_dates, $buffer_days, $range_start, $range_end ) {
        $buffered_dates = $booked_dates;

        foreach ( $booked_dates as $date_str ) {
            $date = new \DateTime( $date_str );

            // Add buffer days before
            for ( $i = 1; $i <= $buffer_days; $i++ ) {
                $buffer_date = ( clone $date )->modify( "-{$i} days" );
                $buffer_str  = $buffer_date->format( 'Y-m-d' );
                
                if ( $buffer_str >= $range_start && $buffer_str <= $range_end ) {
                    $buffered_dates[] = $buffer_str;
                }
            }

            // Add buffer days after
            for ( $i = 1; $i <= $buffer_days; $i++ ) {
                $buffer_date = ( clone $date )->modify( "+{$i} days" );
                $buffer_str  = $buffer_date->format( 'Y-m-d' );
                
                if ( $buffer_str >= $range_start && $buffer_str <= $range_end ) {
                    $buffered_dates[] = $buffer_str;
                }
            }
        }

        return array_unique( $buffered_dates );
    }

    /**
     * Get the primary dimension slug.
     *
     * @return string|null
     */
    private function get_primary_dimension_slug() {
        $registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
        
        foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
            if ( ! empty( $settings['primary'] ) ) {
                return $slug;
            }
        }

        return null;
    }
}