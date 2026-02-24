<?php
/**
 * Handles the [clisyc_appointment_detail] shortcode.
 *
 * Renders a detailed view of a single appointment.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Services\FormRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Appointment_Detail_Shortcode {

	use Shortcode_Helpers;

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'clisyc_appointment_detail', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the appointment detail shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		// Enqueue the base style and our NEW detail style
		wp_enqueue_style( 'clisyc-frontend-style' );
		wp_enqueue_style( 'clisyc-appointment-detail' );
		wp_enqueue_style( 'clisyc-image-marker' );

		$color_settings = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
		$css_variables  = [];
		if ( ! empty( $color_settings['icon_bg'] ) ) {
			$css_variables[] = '--clisyc-icon-bg: ' . esc_attr( $color_settings['icon_bg'] );
		}
		if ( ! empty( $color_settings['icon_text'] ) ) {
			$css_variables[] = '--clisyc-icon-text: ' . esc_attr( $color_settings['icon_text'] );
		}

		if ( ! empty( $css_variables ) ) {
			$inline_style = '.clisyc-appointment-detail-card {' . implode( ';', $css_variables ) . ';}';
			wp_add_inline_style( 'clisyc-appointment-detail', $inline_style );
		}

		ob_start();

		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( 'You must be logged in to view appointment details.', 'client-sync' ) . '</p>';
			return ob_get_clean();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$appointment_id = isset( $_GET['view_id'] ) ? absint( $_GET['view_id'] ) : 0;
		if ( ! $appointment_id ) {
			echo '<p>' . esc_html__( 'No appointment specified.', 'client-sync' ) . '</p>';
			return ob_get_clean();
		}

		$appointment_post = get_post( $appointment_id );

		if ( ! $appointment_post || Constants::POST_TYPE_APPOINTMENT !== $appointment_post->post_type ) {
			echo '<p>' . esc_html__( 'Appointment not found.', 'client-sync' ) . '</p>';
			return ob_get_clean();
		}

		if ( $appointment_post->post_author != get_current_user_id() && ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to view this appointment.', 'client-sync' ) . '</p>';
			return ob_get_clean();
		}

		// --- Prepare all variables for the template ---
		$title = get_the_title( $appointment_id );
		$notes = ! empty( $appointment_post->post_content ) ? wpautop( wp_kses_post( $appointment_post->post_content ) ) : esc_html__( 'No notes recorded.', 'client-sync' );

		// Initialize to empty to prevent undefined variable warnings later in the function (export_ical logic)
		$time_slot_id = '';

		// Check if this is a date-range booking first
		$start_date = get_post_meta( $appointment_id, Constants::META_START_DATE, true );
		$end_date   = get_post_meta( $appointment_id, Constants::META_END_DATE, true );

		if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
			// DATE-RANGE booking
			$formatted_date = $this->format_date_range( $start_date, $end_date );
			$formatted_time = __( 'All Day', 'client-sync' );
		} else {
			// Standard TIME-SLOT booking
			$time_slot_id   = get_post_meta( $appointment_id, Constants::META_TIME_SLOT, true );
			$time_info      = $this->format_datetime_from_slot_id( $time_slot_id );
			$formatted_date = $time_info['date'];
			$formatted_time = $time_info['time'];
		}

		$status_obj = get_post_status_object( get_post_status( $appointment_id ) );

		$all_custom_fields = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		$field_order       = get_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, array_keys( $all_custom_fields ) );
		$form_renderer     = new FormRenderer();

		// *** START: THIS IS THE CORRECTED/ADDED BLOCK ***
		// These variables were missing but are required by the new template.
		$dimension_keys = get_option( Constants::OPTION_AVAILABILITY_DIM_FIELDS, [] );
		if ( ! is_array( $field_order ) ) {
			$field_order = [];
		}
		$field_order = array_intersect( $field_order, array_keys( $all_custom_fields ) );
		foreach ( array_keys( $all_custom_fields ) as $key ) {
			if ( ! in_array( $key, $field_order ) ) {
				$field_order[] = $key;
			}
		}
		// *** END: THIS IS THE CORRECTED/ADDED BLOCK ***

		// Seat details — populated by Pro seat-selection module via filter.
		$seat_details = apply_filters( 'clisyc_appointment_seat_details', [], $appointment_id );

		// QR code data — populated by Pro seat-selection module via filter.
		$qr_code_data = apply_filters( 'clisyc_appointment_qr_code', '', $appointment_id );

		// Venue map SVG data — populated by Pro seat-selection module via filter.
		// Returns [ 'svg_markup' => string, 'all_seat_element_ids' => array, 'selected_seat_element_ids' => array ].
		$venue_map_data = apply_filters( 'clisyc_appointment_venue_map', [], $appointment_id );

		// Venue location data — populated by Pro seat-selection module via filter.
		// Returns [ 'venue_name', 'address', 'city', 'state', 'postal_code', 'country', 'map_embed' ].
		$venue_location_data = apply_filters( 'clisyc_appointment_venue_location', [], $appointment_id );

		$export_url            = '';
		$duration_minutes_ical = get_post_meta( $appointment_id, Constants::META_APPOINTMENT_DURATION, true );
		if ( ! empty( $time_slot_id ) && ! empty( $duration_minutes_ical ) && is_numeric( $duration_minutes_ical ) && $duration_minutes_ical > 0 ) {
			$export_url = add_query_arg(
				[
					'clisyc_action'  => 'export_ical',
					'appointment_id' => $appointment_id,
					'_wpnonce'       => wp_create_nonce( 'clisyc_export_ical_' . $appointment_id ),
				],
				home_url( '/' )
			);
		}

		// Enqueue QR code renderer if we have QR data to display.
		if ( ! empty( $qr_code_data ) ) {
			wp_enqueue_script( 'clisyc-frontend-qr' );
		}

		// Enqueue venue map highlighter if we have SVG data to display.
		if ( ! empty( $venue_map_data['svg_markup'] ) ) {
			wp_enqueue_script( 'clisyc-frontend-venue-map' );
		}

		// --- Include the new template file ---
		$template_file = CLISYC_SHARED_DIR . 'includes/frontend/views/view-appointment-detail.php';
		if ( file_exists( $template_file ) ) {
			include $template_file;
		} else {
			// Fallback to the old view if the new one isn't found (for safety)
			$old_template_file = CLISYC_SHARED_DIR . 'includes/frontend/views/view-appointment-shortcode.php';
			if ( file_exists( $old_template_file ) ) {
				include $old_template_file;
			} else {
				echo '<!-- Client Sync: Appointment detail view file not found. -->';
			}
		}

		return ob_get_clean();
	}
}
