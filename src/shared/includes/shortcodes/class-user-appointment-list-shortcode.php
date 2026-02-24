<?php
/**
 * Handles the [clisyc_view_notes] shortcode.
 *
 * Renders a paginated list of the current user's appointments.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class User_Appointment_List_Shortcode {

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'clisyc_view_notes', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the user appointment list shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		// --- START: NEW ASSET ENQUEUEING BLOCK ---
		wp_enqueue_style( 'clisyc-frontend-style' );
		wp_enqueue_script( 'clisyc-frontend-appointment-list' );
		wp_localize_script(
			'clisyc-frontend-appointment-list',
			'clisycUserAppointmentsData',
			[
				'ajaxurl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'clisyc_get_user_appointments_nonce' ),
				// Also pass enabled views to the list table page in case it's used there
				'enabledViews' => get_option( Constants::OPTION_CALENDAR_ENABLED_VIEWS, [ 'dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek' ] ),
				'l10n'         => [
					'errorLoading'          => __( 'An error occurred while loading appointments.', 'client-sync' ),
					'viewDetails'           => __( 'View Details', 'client-sync' ),
					'cancelAppointment'     => __( 'Cancel Appointment', 'client-sync' ),
					'rescheduleAppointment' => __( 'Reschedule Appointment', 'client-sync' ),
					'confirmCancel'         => __( 'Are you sure you want to cancel this appointment?', 'client-sync' ),
					'confirmReschedule'     => __( 'This will cancel your current appointment and let you book a new one. Are you sure you want to continue?', 'client-sync' ),
					'previous'              => __( 'Previous', 'client-sync' ),
					'next'                  => __( 'Next', 'client-sync' ),
				],
			]
		);
		// --- END: NEW ASSET ENQUEUEING BLOCK ---

		if ( ! is_user_logged_in() ) {
			/* translators: %s: URL to the login page. */
			$login_prompt_text = __( 'Please <a href="%s">log in</a> to view your appointments.', 'client-sync' );
			return '<p>' . sprintf( wp_kses( $login_prompt_text, [ 'a' => [ 'href' => [] ] ] ), esc_url( wp_login_url( get_permalink() ) ) ) . '</p>';
		}

		ob_start();
		$view_file = clisyc_PLUGIN_DIR . 'includes/frontend/views/view-appointment-list-shortcode.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			echo '<p>' . esc_html__( 'Error: Appointment list view file not found.', 'client-sync' ) . '</p>';
		}
		return ob_get_clean();
	}
}
