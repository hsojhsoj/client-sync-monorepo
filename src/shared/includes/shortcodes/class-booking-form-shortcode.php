<?php
/**
 * File: src/shared/includes/shortcodes/class-booking-form-shortcode.php
 * 
 * UPDATED: Now supports date_range booking mode.
 * UPDATED: Added show_booked_toggle and show_booked_default shortcode attributes.
 * 
 * Usage:
 *   [clisyc_booking_form]                                                    - Default (no toggle)
 *   [clisyc_booking_form show_booked_toggle="true"]                          - Shows toggle, starts unchecked
 *   [clisyc_booking_form show_booked_toggle="true" show_booked_default="true"] - Shows toggle, starts checked
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Booking_Form_Shortcode {

    /**
     * Render the booking form.
     * This method should be merged with your existing render_appointment_form method.
     */
    public function render_appointment_form( $atts = [] ) {
        // Parse attributes
        $atts = shortcode_atts( [
            'service_id'          => 0,
            'booking_mode'        => '', // Can be 'slot' or 'date_range' - if empty, will auto-detect
            'show_booked_toggle'  => 'false', // Show the "Show booked slots" toggle
            'show_booked_default' => 'false', // Default state of the toggle (if shown)
        ], $atts, 'clisyc_appointment_form' );

        // Determine booking mode
        $booking_mode = $this->determine_booking_mode( $atts );

        // Get date range settings if applicable
        $date_range_settings = [];
        if ( 'date_range' === $booking_mode ) {
            $date_range_settings = $this->get_date_range_settings();
        }

        // Parse boolean attributes
        $show_booked_toggle  = filter_var( $atts['show_booked_toggle'], FILTER_VALIDATE_BOOLEAN );
        $show_booked_default = filter_var( $atts['show_booked_default'], FILTER_VALIDATE_BOOLEAN );

        // Build the data to pass to JavaScript
        $localized_data = [
            // ... your existing data ...
            'restUrl'                => rest_url( 'clisyc/v1/slots' ),
            'restNonce'              => wp_create_nonce( 'wp_rest' ),
            'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
            'formNonce'              => wp_create_nonce( 'clisyc_appointment_nonce' ),
            'filterOrder'            => $this->get_filter_order(),
            'availabilityDimensions' => $this->get_availability_dimensions(),
            'isGuest'                => ! is_user_logged_in(),
            'customFieldDefinitions' => $this->get_custom_field_definitions(),
            'successPageUrl'         => $this->get_success_page_url(),
            'currencySymbol'         => $this->get_currency_symbol(),
            
            // Booking mode settings
            'bookingMode'            => $booking_mode,
            'dateRangeSettings'      => $date_range_settings,
            
            // NEW: Show booked slots settings
            'showBookedToggle'       => $show_booked_toggle,
            'showBookedDefault'      => $show_booked_default,

            // Waitlist
            'waitlist_enabled'       => \DependentMedia\ClientSync\Core\Waitlist_Manager::is_enabled(),

            // Recurring series (Pro only)
            'proRecurringEnabled'    => function_exists( 'clisyc_pro_is_license_active' ) && clisyc_pro_is_license_active(),
            'restRootUrl'            => esc_url_raw( rest_url() ),

            // WooCommerce / Stripe integration flags
            'wc_integration_enabled'     => (bool) get_option( Constants::OPTION_WC_ENABLED, false ),
            'stripe_integration_enabled' => (bool) get_option( Constants::OPTION_STRIPE_ENABLED, false ),

            // Seat selection: linked venue ID (resolved from preselected service if available)
            'linkedVenueId' => $this->get_linked_venue_id( $atts ),

            // ... rest of your existing data ...
        ];

        // Enqueue scripts
        $this->enqueue_assets();

        // Localize data for JavaScript
        wp_localize_script( 'clisyc-booking-form', 'clisycBookingFormData', $localized_data );

        // Return the container div
        return '<div id="clisyc-booking-form-react-root"></div>';
    }

    /**
     * Determine the booking mode based on configuration.
     *
     * @param array $atts Shortcode attributes
     * @return string 'slot' or 'date_range'
     */
    private function determine_booking_mode( $atts ) {
        // If explicitly set in shortcode, use that
        if ( ! empty( $atts['booking_mode'] ) && in_array( $atts['booking_mode'], [ 'slot', 'date_range' ], true ) ) {
            return $atts['booking_mode'];
        }

        // Check global setting
        $global_mode = get_option( Constants::OPTION_DEFAULT_BOOKING_MODE, 'slot' );
        
        // Check if primary dimension has date_range mode
        $primary_dim_slug = $this->get_primary_dimension_slug();
        if ( $primary_dim_slug ) {
            $registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
            $dim_config = $registry['dimensions'][ $primary_dim_slug ] ?? [];
            
            if ( ! empty( $dim_config['booking_mode'] ) ) {
                return $dim_config['booking_mode'];
            }
        }

        return $global_mode;
    }

    /**
     * Get date range settings from options.
     *
     * @return array
     */
    private function get_date_range_settings() {
        return [
            'minStay'    => (int) get_option( 'clisyc_min_stay_nights', 1 ),
            'maxStay'    => (int) get_option( 'clisyc_max_stay_nights', 365 ),
            'bufferDays' => (int) get_option( Constants::OPTION_DEFAULT_BUFFER_DAYS, 0 ),
            'checkInDays'  => get_option( 'clisyc_allowed_checkin_days', [] ), // e.g., [0, 6] for Sun/Sat only
            'checkOutDays' => get_option( 'clisyc_allowed_checkout_days', [] ),
        ];
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

    /**
     * Get filter order from settings.
     *
     * @return array
     */
    private function get_filter_order() {
        $registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
        return $registry['order'] ?? [];
    }

    /**
     * Get availability dimensions configuration.
     *
     * @return array
     */
    private function get_availability_dimensions() {
        $registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
        $dimensions = [];
        
        foreach ( $registry['dimensions'] ?? [] as $slug => $config ) {
            $dimensions[ $slug ] = [
                'label' => $config['label'] ?? ucfirst( str_replace( 'clisyc_', '', $slug ) ),
                'icon'  => $config['icon'] ?? 'dashicons-admin-generic',
            ];
        }
        
        return $dimensions;
    }

    /**
     * Get currency symbol.
     *
     * @return string
     */
    private function get_currency_symbol() {
        // Try WooCommerce first
        if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
            return get_woocommerce_currency_symbol();
        }
        
        // Fall back to option or default
        return get_option( 'clisyc_currency_symbol', '$' );
    }

    /**
     * Get custom field definitions.
     *
     * @return array
     */
    private function get_custom_field_definitions() {
        return get_option( 'clisyc_custom_booking_fields', [] );
    }

    /**
     * Get success page URL.
     *
     * @return string
     */
    private function get_success_page_url() {
        $page_id = (int) get_option( 'clisyc_booking_success_page', 0 );
        
        if ( $page_id ) {
            return get_permalink( $page_id );
        }
        
        return '';
    }

    /**
     * Enqueue booking form assets.
     */
    private function enqueue_assets() {
        $asset_file = clisyc_PLUGIN_DIR . 'assets/dist/booking-form/index.asset.php';
        $assets     = file_exists( $asset_file ) ? require $asset_file : [ 'dependencies' => [], 'version' => '1.0.0' ];

        $dependencies = $assets['dependencies'];

        // Register the shared FullCalendar vendor chunk (webpack code-split).
        $vendor_fc_path = clisyc_PLUGIN_DIR . 'assets/dist/vendor-fullcalendar/index.asset.php';
        if ( file_exists( $vendor_fc_path ) ) {
            $vendor_fc = include $vendor_fc_path;
            wp_register_script(
                'clisyc-vendor-fullcalendar',
                clisyc_PLUGIN_URL . 'assets/dist/vendor-fullcalendar/index.js',
                $vendor_fc['dependencies'] ?? [],
                $vendor_fc['version'] ?? null,
                true
            );
            $dependencies[] = 'clisyc-vendor-fullcalendar';
        }

        wp_enqueue_script(
            'clisyc-booking-form',
            clisyc_PLUGIN_URL . 'assets/dist/booking-form/index.js',
            $dependencies,
            $assets['version'],
            true
        );

        wp_enqueue_style(
            'clisyc-booking-form',
            clisyc_PLUGIN_URL . 'assets/dist/booking-form/style.css',
            [],
            $assets['version']
        );

        // Also load dashicons
        wp_enqueue_style( 'dashicons' );
    }

    /**
     * Get the linked venue ID for seat selection.
     *
     * Resolves from preselected service dimension item if available.
     *
     * @param array $atts Shortcode attributes.
     * @return int Venue ID, or 0 if no venue linked.
     */
    private function get_linked_venue_id( array $atts ): int {
        // Try preselected service from shortcode attributes.
        $service_id = 0;
        if ( ! empty( $atts['service'] ) ) {
            $service_id = absint( $atts['service'] );
        }

        if ( ! $service_id ) {
            return 0;
        }

        return absint( get_post_meta( $service_id, Constants::META_LINKED_VENUE_ID, true ) );
    }
}