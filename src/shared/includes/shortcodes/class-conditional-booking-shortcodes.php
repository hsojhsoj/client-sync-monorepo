<?php
/**
 * File: src/shared/includes/shortcodes/class-conditional-booking-shortcodes.php
 * Conditional shortcodes to show/hide content based on recent booking status.
 *
 * Usage:
 *   [clisyc_if_recent_booking timeout="10"]
 *       Your custom HTML here - only shows after a recent booking
 *   [/clisyc_if_recent_booking]
 *
 *   [clisyc_if_no_recent_booking timeout="10"]
 *       Your custom HTML here - only shows when NO recent booking
 *   [/clisyc_if_no_recent_booking]
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Conditional_Booking_Shortcodes {

    /**
     * Register the shortcodes.
     */
    public function register() {
        add_shortcode( 'clisyc_if_recent_booking', [ $this, 'render_if_recent_booking' ] );
        add_shortcode( 'clisyc_if_no_recent_booking', [ $this, 'render_if_no_recent_booking' ] );
    }

    /**
     * Render content only if there IS a recent booking.
     *
     * @param array  $atts    Shortcode attributes.
     * @param string $content The enclosed content.
     * @return string HTML output or empty string.
     */
    public function render_if_recent_booking( $atts, $content = null ) {
        $atts = shortcode_atts(
            [
                'timeout' => 10, // Minutes
            ],
            $atts,
            'clisyc_if_recent_booking'
        );

        $timeout_minutes = absint( $atts['timeout'] );
        $has_recent      = $this->has_recent_booking( $timeout_minutes );

        if ( $has_recent ) {
            // Process any nested shortcodes and return the content
            return do_shortcode( $content );
        }

        return '';
    }

    /**
     * Render content only if there is NO recent booking.
     *
     * @param array  $atts    Shortcode attributes.
     * @param string $content The enclosed content.
     * @return string HTML output or empty string.
     */
    public function render_if_no_recent_booking( $atts, $content = null ) {
        $atts = shortcode_atts(
            [
                'timeout' => 10, // Minutes
            ],
            $atts,
            'clisyc_if_no_recent_booking'
        );

        $timeout_minutes = absint( $atts['timeout'] );
        $has_recent      = $this->has_recent_booking( $timeout_minutes );

        if ( ! $has_recent ) {
            // Process any nested shortcodes and return the content
            return do_shortcode( $content );
        }

        return '';
    }

    /**
     * Check if there's a recent booking.
     *
     * @param int $timeout_minutes How far back to look.
     * @return bool True if recent booking exists.
     */
    private function has_recent_booking( int $timeout_minutes ): bool {
        // Method 1: Check URL parameters (set by booking redirect)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['clisyc_booking_status'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $status = sanitize_key( wp_unslash( $_GET['clisyc_booking_status'] ) );
            if ( in_array( $status, [ 'success_free', 'success_paid', 'success' ], true ) ) {
                // Verify appointment ID if provided
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                if ( isset( $_GET['appt_id'] ) || isset( $_GET['view_id'] ) ) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    $appt_id = isset( $_GET['appt_id'] ) ? absint( $_GET['appt_id'] ) : absint( $_GET['view_id'] );
                    if ( $appt_id > 0 && $this->user_can_view_appointment( $appt_id ) ) {
                        return true;
                    }
                }
                // Even without appt_id, the status param indicates recent booking
                return true;
            }
        }

        // Method 2: Check success transient
        $transient = get_transient( 'clisyc_booking_feedback_success' );
        if ( $transient ) {
            return true;
        }

        // Method 3: Check for user's recent appointments
        if ( is_user_logged_in() ) {
            return $this->user_has_recent_appointment( $timeout_minutes );
        }

        return false;
    }

    /**
     * Check if the current user has an appointment created within the timeout window.
     *
     * @param int $timeout_minutes Minutes to look back.
     * @return bool True if recent appointment exists.
     */
    private function user_has_recent_appointment( int $timeout_minutes ): bool {
        $cutoff_time = gmdate( 'Y-m-d H:i:s', strtotime( "-{$timeout_minutes} minutes" ) );

        $args = [
            'post_type'      => Constants::POST_TYPE_APPOINTMENT,
            'post_status'    => [ 'publish', Constants::STATUS_PENDING_PAYMENT ],
            'author'         => get_current_user_id(),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'date_query'     => [
                [
                    'after'     => $cutoff_time,
                    'inclusive' => true,
                ],
            ],
        ];

        $query = new \WP_Query( $args );

        return $query->have_posts();
    }

    /**
     * Verify the current user can view the specified appointment.
     *
     * @param int $appointment_id The appointment ID.
     * @return bool True if user can view.
     */
    private function user_can_view_appointment( int $appointment_id ): bool {
        $appointment = get_post( $appointment_id );

        if ( ! $appointment || Constants::POST_TYPE_APPOINTMENT !== $appointment->post_type ) {
            return false;
        }

        // Admins can view all
        if ( current_user_can( 'edit_others_posts' ) ) {
            return true;
        }

        // Users can view their own
        if ( is_user_logged_in() && (int) $appointment->post_author === get_current_user_id() ) {
            return true;
        }

        return false;
    }
}