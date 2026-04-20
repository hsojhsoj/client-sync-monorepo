<?php
/**
 * File: src/shared/includes/frontend/class-manager-shortcodes.php -> client-sync/includes/frontend/class-manager-shortcodes.php
 * Handles shortcodes intended for manager-level users on the frontend.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 * MODIFIED: Fully implemented the manager calendar and dynamic appointment list with enhanced UI and Legend.
 * FIXED: Timezone selector dropdown and legend styling to match booking form.
 * FIXED: Added wp_enqueue_style for frontend CSS in render_manager_appointments.
 * UPDATED: Added HIPAA compliance security hardening for data exports.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend
 */

namespace DependentMedia\ClientSync\Frontend;

use DependentMedia\ClientSync\Services\FormRenderer;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Shortcodes\Shortcode_Helpers;
use DependentMedia\ClientSync\Utility\Security_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Manager_Shortcodes {

	use Shortcode_Helpers;

	private $plugin_name;
	private $version;

	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Check if HIPAA mode is active.
	 *
	 * Uses the global helper function if available, otherwise checks
	 * both the constant and database option.
	 *
	 * @return bool True if HIPAA mode is active, false otherwise.
	 */
	private function is_hipaa_mode_active(): bool {
		// Use global helper if available
		if ( function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) ) {
			return \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode();
		}

		// Fallback: Check constant first (set in wp-config.php for security)
		if ( defined( 'CLISYC_HIPAA_MODE' ) && CLISYC_HIPAA_MODE ) {
			return true;
		}

		// Fallback: Check database option
		return (bool) get_option( Constants::OPTION_HIPAA_MODE, false );
	}

	public function register_all() {
		add_shortcode( 'clisyc_manager_appointments', [ $this, 'render_manager_appointments' ] );
		add_shortcode( 'clisyc_manager_calendar', [ $this, 'render_manager_calendar' ] );
		add_shortcode( 'clisyc_manager_edit_appointment', [ $this, 'render_manager_edit_appointment' ] );
		add_shortcode( 'clisyc_manager_edit_notes', [ $this, 'render_manager_edit_notes' ] );
	}

	/**
	 * Register admin_post handlers for form submissions.
	 * This must be called in both admin and frontend contexts because
	 * forms POST to admin-post.php which runs in admin context.
	 */
	public function register_admin_post_handlers() {
		add_action( 'admin_post_clisyc_export_manager_appointments', [ $this, 'export_appointments_to_csv' ] );
		add_action( 'admin_post_clisyc_manager_edit_appointment_submit', [ $this, 'handle_manager_edit_appointment_submit' ] );
	}

	public function render_manager_appointments( $atts ) {
		if ( ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view this page.', 'client-sync' ) . '</p>';
		}

		// FIXED: Enqueue the frontend stylesheet for styling
		wp_enqueue_style( 'clisyc-frontend-style' );

		wp_enqueue_script( 'clisyc-manager-appointment-list' );
		wp_localize_script(
			'clisyc-manager-appointment-list',
			'clisycManagerAppointmentData',
			[
				'ajaxurl'             => admin_url( 'admin-ajax.php' ),
				'nonce'               => wp_create_nonce( 'clisyc_get_manager_appointments_list_nonce' ),
				'update_status_nonce' => wp_create_nonce( 'clisyc_update_appointment_status_nonce' ),
				'l10n'                => [
					'error'          => __( 'An error occurred.', 'client-sync' ),
					'noAppointments' => __( 'No appointments found matching your criteria.', 'client-sync' ),
					'colClient'      => __( 'Client', 'client-sync' ),
					'colService'     => __( 'Service', 'client-sync' ),
					'colDate'        => __( 'Date', 'client-sync' ),
					'colTime'        => __( 'Time', 'client-sync' ),
					'colStatus'      => __( 'Status', 'client-sync' ),
					'colActions'     => __( 'Actions', 'client-sync' ),
					'edit'           => __( 'Details', 'client-sync' ),
					'editNotes'      => __( 'Notes', 'client-sync' ),
				],
			]
		);

		$atts = shortcode_atts(
			[
				'filters'    => 'true',
				'search'     => 'true',
				'pagination' => 'true',
				'export'     => 'true',
			],
			$atts,
			'clisyc_manager_appointments'
		);

		ob_start();
		$view_file = clisyc_PLUGIN_DIR . 'includes/frontend/views/view-manager-appointment-list.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}
		return ob_get_clean();
	}

	public function export_appointments_to_csv() {
		if ( ! isset( $_POST['clisyc_export_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clisyc_export_nonce'] ) ), 'clisyc_export_manager_appointments_action' ) ) {
			wp_die( 'Security check failed.' );
		}
		if ( ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
			wp_die( 'Permission denied.' );
		}

		// =========================================================================
		// HIPAA COMPLIANCE: Block appointment export in HIPAA mode
		// CSV export contains PHI (client names, emails, appointment details).
		// =========================================================================
		if ( $this->is_hipaa_mode_active() ) {
			wp_die(
				esc_html__( 'Appointment export is disabled when HIPAA mode is active. CSV exports contain Protected Health Information (PHI) including client names and email addresses. To protect patient privacy, bulk data exports are not permitted in HIPAA-compliant mode.', 'client-sync' ),
				esc_html__( 'Export Blocked - HIPAA Mode Active', 'client-sync' ),
				[ 'response' => 403, 'back_link' => true ]
			);
		}

		$query_args_json = isset( $_POST['query_args'] ) ? sanitize_text_field( wp_unslash( $_POST['query_args'] ) ) : '{}';
		$query_args      = json_decode( $query_args_json, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $query_args ) ) {
			$query_args = [];
		}

		$query_args['posts_per_page'] = 500;
		$query_args['no_found_rows']  = true;
		$query_args['paged']          = 1;

		$appointments_query = new \WP_Query( $query_args );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=clisyc-appointments-export-' . wp_date( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		// Guard every cell against CSV formula injection (OWASP) — display_name,
		// user_email, and service title are all effectively user-supplied.
		fputcsv( $output, Security_Helper::csv_escape_row( [ 'Client Name', 'Client Email', 'Appointment Date', 'Appointment Time', 'Status', 'Service' ] ) );

		if ( $appointments_query->have_posts() ) {
			while ( $appointments_query->have_posts() ) {
				$appointments_query->the_post();
				$appt_id    = get_the_ID();
				$client     = get_user_by( 'id', get_post_field( 'post_author', $appt_id ) );
				$time_info  = $this->format_datetime_from_slot_id( get_post_meta( $appt_id, Constants::META_TIME_SLOT, true ) );
				$status_obj = get_post_status_object( get_post_status( $appt_id ) );

				$service     = '';
				$registry    = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
				$primary_key = null;
				foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
					if ( ! empty( $settings['primary'] ) ) {
						$primary_key = $slug;
						break;
					}
				}

				if ( $primary_key ) {
					$dimensions = get_post_meta( $appt_id, 'clisyc_slot_dimensions', true );
					$service_id = is_array( $dimensions ) && isset( $dimensions[ $primary_key ] ) ? $dimensions[ $primary_key ] : 0;
					if ( $service_id ) {
						$service = get_the_title( $service_id );
					}
				}

				fputcsv(
					$output,
					Security_Helper::csv_escape_row( [
						$client ? $client->display_name : 'N/A',
						$client ? $client->user_email : 'N/A',
						$time_info['date'],
						$time_info['time'],
						$status_obj ? $status_obj->label : get_post_status( $appt_id ),
						$service,
					] )
				);
			}
		}
		wp_reset_postdata();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );
		exit;
	}

	public function render_manager_calendar( $atts ) {
		if ( ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view this page.', 'client-sync' ) . '</p>';
		}

		// 1. Force load the core Calendar styles and the Vendor library
		wp_enqueue_style( 'clisyc-calendar' );
		wp_enqueue_style( 'clisyc-frontend-style' );
		wp_enqueue_script( 'clisyc-fullcalendar' ); // The vendor library
		wp_enqueue_script( 'clisyc-manager-calendar' );

		$details_page_id = (int) get_option( Constants::OPTION_APPOINTMENT_VIEW_PAGE, 0 );

		// Ensure we have a valid array of views
		$enabled_views = get_option( Constants::OPTION_CALENDAR_ENABLED_VIEWS, [ 'dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek' ] );

		wp_localize_script(
			'clisyc-manager-calendar',
			'clisycManagerCalendarData',
			[
				'ajaxurl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'clisyc_get_calendar_events_nonce' ),
				'enabledViews' => $enabled_views,
				'viewUrl'      => $details_page_id ? get_permalink( $details_page_id ) : get_permalink(),
				'l10n'         => [
					'loading'        => __( 'Loading Manager Data...', 'client-sync' ),
					'tzNoteLocal'    => __( 'Showing all appointments in your local timezone.', 'client-sync' ),
					/* translators: %s: The name of the selected timezone (e.g., "America/New_York"). */
					'tzNoteSelected' => __( 'Showing all appointments in: %s', 'client-sync' ),
					'none'           => __( 'None', 'client-sync' ),
				],
			]
		);

		ob_start();
		?>
		<div id="clisyc-manager-dashboard-wrapper" class="clisyc-container clisyc-booking-form-app">
			<!-- Header with Timezone and Clock -->
			<div class="clisyc-manager-header">
				<div class="clisyc-timezone-selector-group">
					<label class="clisyc-timezone-label" for="clisyc-manager-timezone-selector">
						<span class="dashicons dashicons-admin-site" aria-hidden="true"></span>
						<?php esc_html_e( 'Timezone Selection', 'client-sync' ); ?>
					</label>
					<div class="clisyc-select-wrapper">
						<select id="clisyc-manager-timezone-selector" class="clisyc-styled-select">
							<option value="local"><?php esc_html_e( 'My Local Time', 'client-sync' ); ?></option>
							<option value="UTC">UTC</option>
							<option value="America/New_York"><?php esc_html_e( 'New York (ET)', 'client-sync' ); ?></option>
							<option value="America/Chicago"><?php esc_html_e( 'Chicago (CT)', 'client-sync' ); ?></option>
							<option value="America/Denver"><?php esc_html_e( 'Denver (MT)', 'client-sync' ); ?></option>
							<option value="America/Los_Angeles"><?php esc_html_e( 'Los Angeles (PT)', 'client-sync' ); ?></option>
							<option value="Europe/London"><?php esc_html_e( 'London (GMT/BST)', 'client-sync' ); ?></option>
							<option value="Europe/Paris"><?php esc_html_e( 'Paris (CET)', 'client-sync' ); ?></option>
							<option value="Asia/Tokyo"><?php esc_html_e( 'Tokyo (JST)', 'client-sync' ); ?></option>
							<option value="Australia/Sydney"><?php esc_html_e( 'Sydney (AEST)', 'client-sync' ); ?></option>
						</select>
						<span class="clisyc-select-arrow" aria-hidden="true">
							<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M2.5 4.5L6 8L9.5 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</span>
					</div>
					<div id="clisyc-manager-timezone-note" class="clisyc-timezone-note"></div>
				</div>

				<div class="clisyc-live-clock-group">
					<label class="clisyc-clock-label"><?php esc_html_e( 'Current Time', 'client-sync' ); ?></label>
					<div id="clisyc-manager-live-clock" class="clisyc-live-clock">--:--:-- --</div>
					<div class="clisyc-tz-region">
						<span class="dashicons dashicons-location" aria-hidden="true"></span>
						<span id="clisyc-active-tz-label"><?php esc_html_e( 'Region: ...', 'client-sync' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Calendar -->
			<div class="clisyc-calendar-wrapper">
				<div id="clisyc-manager-calendar">
					<p id="clisyc-mgmt-loader" class="clisyc-calendar-loading"><?php esc_html_e( 'Initializing Manager Calendar...', 'client-sync' ); ?></p>
				</div>
			</div>

			<!-- The Legend (Key) - Matches Booking Form React Component Structure -->
			<div class="clisyc-calendar-legend">
				<span class="clisyc-legend-title"><?php esc_html_e( 'Legend:', 'client-sync' ); ?></span>
				<div class="clisyc-legend-items">
					<div class="clisyc-legend-item">
						<span class="clisyc-legend-indicator clisyc-legend-slot clisyc-legend-available" aria-hidden="true"></span>
						<span class="clisyc-legend-label"><?php esc_html_e( 'Available', 'client-sync' ); ?></span>
					</div>
					<div class="clisyc-legend-item">
						<span class="clisyc-legend-indicator clisyc-legend-slot clisyc-legend-booked" aria-hidden="true"></span>
						<span class="clisyc-legend-label"><?php esc_html_e( 'Booked', 'client-sync' ); ?></span>
					</div>
					<div class="clisyc-legend-item">
						<span class="clisyc-legend-indicator clisyc-legend-slot clisyc-legend-blocked" aria-hidden="true"></span>
						<span class="clisyc-legend-label"><?php esc_html_e( 'Blocked', 'client-sync' ); ?></span>
					</div>
					<div class="clisyc-legend-item">
						<span class="clisyc-legend-indicator clisyc-legend-highlight clisyc-legend-today" aria-hidden="true"></span>
						<span class="clisyc-legend-label"><?php esc_html_e( 'Today', 'client-sync' ); ?></span>
					</div>
					<div class="clisyc-legend-item">
						<span class="clisyc-legend-indicator clisyc-legend-line clisyc-legend-current-time" aria-hidden="true"></span>
						<span class="clisyc-legend-label"><?php esc_html_e( 'Current Time', 'client-sync' ); ?></span>
					</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function render_manager_edit_appointment( $atts ) {
		if ( ! current_user_can( apply_filters( 'clisyc_manager_edit_capability', 'edit_others_posts' ) ) ) {
			return '<p>' . esc_html__( 'You do not have permission to access this page.', 'client-sync' ) . '</p>';
		}

		$form_renderer  = new FormRenderer();
		$atts           = shortcode_atts( [ 'appointment_id' => 0 ], $atts, 'clisyc_manager_edit_appointment' );
		// Reading a GET parameter to determine which appointment to show is a safe, non-state-changing action.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$appointment_id = isset( $_GET['appointment_id'] ) ? absint( $_GET['appointment_id'] ) : absint( $atts['appointment_id'] );

		if ( ! $appointment_id || Constants::POST_TYPE_APPOINTMENT !== get_post_type( $appointment_id ) ) {
			return '<p>' . esc_html__( 'Invalid or missing appointment ID.', 'client-sync' ) . '</p>';
		}
		if ( ! current_user_can( 'edit_post', $appointment_id ) ) {
			return '<p>' . esc_html__( 'You do not have permission to edit this appointment.', 'client-sync' ) . '</p>';
		}

		// Enqueue frontend styles
		wp_enqueue_style( 'clisyc-frontend-style' );

		ob_start();

		// Display feedback from the form submission using transients.
		$feedback = get_transient( 'clisyc_manager_edit_feedback_' . get_current_user_id() );
		if ( $feedback && is_array( $feedback ) ) {
			$type  = $feedback['type'] ?? 'info';
			$text  = $feedback['text'] ?? '';
			$class = ( 'error' === $type ) ? 'clisyc-error-message' : ( ( 'success' === $type ) ? 'clisyc-success-message' : 'clisyc-info-message' );
			echo '<div class="clisyc-registration-message ' . esc_attr( $class ) . '"><p>' . wp_kses_post( $text ) . '</p></div>';
			delete_transient( 'clisyc_manager_edit_feedback_' . get_current_user_id() );
		}

		// Get appointment data
		$appointment      = get_post( $appointment_id );
		$client           = get_user_by( 'id', $appointment->post_author );
		$time_slot_raw    = get_post_meta( $appointment_id, Constants::META_TIME_SLOT, true );
		$booking_mode     = get_post_meta( $appointment_id, Constants::META_BOOKING_MODE, true ) ?: 'slot';
		$dimensions       = get_post_meta( $appointment_id, 'clisyc_slot_dimensions', true );
		$status_obj       = get_post_status_object( $appointment->post_status );
		$appointment_notes = $appointment->post_content;

		// Get formatted date/time
		$time_info = $this->format_datetime_from_slot_id( $time_slot_raw );

		// For date range bookings
		$start_date = get_post_meta( $appointment_id, Constants::META_START_DATE, true );
		$end_date   = get_post_meta( $appointment_id, Constants::META_END_DATE, true );

		// Get dimension info
		$registry           = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$custom_types       = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
		$enabled_dimensions = $registry['dimensions'] ?? [];

		// Build dimension display data
		$dimension_display = [];
		if ( is_array( $dimensions ) && ! empty( $dimensions ) ) {
			foreach ( $dimensions as $dim_slug => $dim_id ) {
				if ( $dim_id && isset( $enabled_dimensions[ $dim_slug ] ) ) {
					$dim_title = get_the_title( $dim_id );
					$dim_label = $custom_types[ $dim_slug ]['singular'] ?? ucfirst( str_replace( [ 'clisyc_', '_' ], [ '', ' ' ], $dim_slug ) );
					if ( $dim_title ) {
						$dimension_display[ $dim_label ] = $dim_title;
					}
				}
			}
		}

		// Get manager appointments list page for back link
		$back_url = '';
		$manager_list_page_id = $this->find_page_with_shortcode( 'clisyc_manager_appointments' );
		if ( $manager_list_page_id ) {
			$back_url = get_permalink( $manager_list_page_id );
		}

		echo '<div class="clisyc-container clisyc-manager-edit-container">';
		echo '<h2>' . esc_html__( 'Edit Appointment Details', 'client-sync' ) . '</h2>';

		// Back links
		echo '<p class="clisyc-back-links">';
		if ( $back_url ) {
			echo '<a href="' . esc_url( $back_url ) . '">' . esc_html__( '← Back to Appointments List', 'client-sync' ) . '</a>';
		}
		if ( current_user_can( 'edit_posts' ) ) {
			if ( $back_url ) {
				echo ' | ';
			}
			echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Constants::POST_TYPE_APPOINTMENT ) ) . '">' . esc_html__( 'Admin Appointments List', 'client-sync' ) . '</a>';
			echo ' | ';
			echo '<a href="' . esc_url( get_edit_post_link( $appointment_id ) ) . '">' . esc_html__( 'Edit in Admin', 'client-sync' ) . '</a>';
		}
		echo '</p>';

		// === APPOINTMENT SUMMARY SECTION ===
		echo '<div class="clisyc-appointment-summary">';
		echo '<h3>' . esc_html__( 'Appointment Summary', 'client-sync' ) . '</h3>';
		echo '<table class="clisyc-details-table">';

		// Status
		$status_label = $status_obj ? $status_obj->label : $appointment->post_status;
		$status_class = 'clisyc-status-badge clisyc-status-badge--' . esc_attr( str_replace( ' ', '_', strtolower( $status_label ) ) );
		echo '<tr><th>' . esc_html__( 'Status', 'client-sync' ) . '</th><td><span class="' . esc_attr( $status_class ) . '">' . esc_html( $status_label ) . '</span></td></tr>';

		// Client
		if ( $client ) {
			echo '<tr><th>' . esc_html__( 'Client', 'client-sync' ) . '</th><td>';
			echo esc_html( $client->display_name );
			if ( $client->user_email ) {
				echo ' <span class="clisyc-client-email">(' . esc_html( $client->user_email ) . ')</span>';
			}
			echo '</td></tr>';
		}

		// Date & Time
		if ( 'date_range' === $booking_mode && $start_date && $end_date ) {
			echo '<tr><th>' . esc_html__( 'Check-in', 'client-sync' ) . '</th><td>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $start_date ) ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Check-out', 'client-sync' ) . '</th><td>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $end_date ) ) ) . '</td></tr>';
			
			// Calculate nights
			$nights = ( strtotime( $end_date ) - strtotime( $start_date ) ) / DAY_IN_SECONDS;
			if ( $nights > 0 ) {
				/* translators: %d: Number of nights */
				echo '<tr><th>' . esc_html__( 'Duration', 'client-sync' ) . '</th><td>' . esc_html( sprintf( _n( '%d night', '%d nights', $nights, 'client-sync' ), $nights ) ) . '</td></tr>';
			}
		} else {
			echo '<tr><th>' . esc_html__( 'Date', 'client-sync' ) . '</th><td>' . esc_html( $time_info['date'] ?: __( 'Not set', 'client-sync' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Time', 'client-sync' ) . '</th><td>' . esc_html( $time_info['time'] ?: __( 'Not set', 'client-sync' ) ) . '</td></tr>';
		}

		// Dimensions (Service, Practitioner, Room, etc.)
		foreach ( $dimension_display as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		// Appointment ID
		echo '<tr><th>' . esc_html__( 'Appointment ID', 'client-sync' ) . '</th><td>#' . esc_html( $appointment_id ) . '</td></tr>';

		// Created date
		echo '<tr><th>' . esc_html__( 'Created', 'client-sync' ) . '</th><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $appointment->post_date ) ) ) . '</td></tr>';

		echo '</table>';
		echo '</div>';

		// === NOTES SECTION ===
		echo '<div class="clisyc-appointment-notes-section">';
		echo '<h3>' . esc_html__( 'Appointment Notes', 'client-sync' ) . '</h3>';
		if ( ! empty( $appointment_notes ) ) {
			echo '<div class="clisyc-notes-content">' . wp_kses_post( $appointment_notes ) . '</div>';
		} else {
			echo '<p class="clisyc-no-notes">' . esc_html__( 'No notes for this appointment.', 'client-sync' ) . '</p>';
		}
		echo '</div>';

		// === CUSTOM FIELDS SECTION ===
		$custom_fields_options = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		$field_order           = get_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, array_keys( $custom_fields_options ) );
		$field_order           = is_array( $field_order ) ? array_intersect( $field_order, array_keys( $custom_fields_options ) ) : [];
		foreach ( array_keys( $custom_fields_options ) as $key ) {
			if ( ! in_array( $key, $field_order ) ) {
				$field_order[] = $key;
			}
		}

		// The form now points to admin-post.php
		echo '<form method="post" class="clisyc-edit-appointment-form clisyc-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		// Add a hidden input for the action hook
		echo '<input type="hidden" name="action" value="clisyc_manager_edit_appointment_submit">';
		echo '<h3>' . esc_html__( 'Custom Details', 'client-sync' ) . '</h3>';

		if ( empty( $field_order ) && empty( $custom_fields_options ) ) {
			echo '<p>' . esc_html__( 'No appointment custom fields defined.', 'client-sync' ) . '</p>';
		} else {
			foreach ( $field_order as $field_key ) {
				if ( ! isset( $custom_fields_options[ $field_key ] ) || ! isset( $custom_fields_options[ $field_key ]['type'] ) ) {
					continue;
				}
				$field_data    = $custom_fields_options[ $field_key ];
				$current_value = get_post_meta( $appointment_id, $field_key, true );
				$form_renderer->render_frontend_custom_field( 'clisyc_custom_field[' . $field_key . ']', $field_data, $current_value, 'manager_edit', $field_key );
			}
		}

		// Add a hidden input for the appointment ID so the handler knows which post to update.
		echo '<input type="hidden" name="appointment_id" value="' . esc_attr( $appointment_id ) . '">';
		// The nonce action now includes the post ID to make it more specific and secure.
		wp_nonce_field( 'clisyc_edit_appointment_' . $appointment_id, 'clisyc_edit_appointment_nonce' );
		// Add the referer field so we can redirect back here.
		wp_referer_field( false );
		echo '<p><input type="submit" value="' . esc_attr__( 'Update Appointment', 'client-sync' ) . '" class="button button-primary clisyc-submit-button"></p></form></div>';

		return ob_get_clean();
	}

	/**
	 * Handle the manager edit appointment form submission.
	 */
	public function handle_manager_edit_appointment_submit() {
		// Verify user has permission
		if ( ! current_user_can( apply_filters( 'clisyc_manager_edit_capability', 'edit_others_posts' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'client-sync' ) );
		}

		// Get appointment ID
		$appointment_id = isset( $_POST['appointment_id'] ) ? absint( $_POST['appointment_id'] ) : 0;
		
		if ( ! $appointment_id ) {
			wp_die( esc_html__( 'Invalid appointment ID.', 'client-sync' ) );
		}

		// Verify nonce
		if ( ! isset( $_POST['clisyc_edit_appointment_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['clisyc_edit_appointment_nonce'] ), 'clisyc_edit_appointment_' . $appointment_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}

		// Verify user can edit this specific post
		if ( ! current_user_can( 'edit_post', $appointment_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this appointment.', 'client-sync' ) );
		}

		// Verify it's actually an appointment
		if ( Constants::POST_TYPE_APPOINTMENT !== get_post_type( $appointment_id ) ) {
			wp_die( esc_html__( 'Invalid appointment.', 'client-sync' ) );
		}

		// Process custom fields
		$custom_fields_options = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		$field_order           = get_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, array_keys( $custom_fields_options ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below
		$submitted_data = isset( $_POST['clisyc_custom_field'] ) ? wp_unslash( $_POST['clisyc_custom_field'] ) : [];

		$updated_count = 0;
		foreach ( $field_order as $field_key ) {
			if ( ! isset( $custom_fields_options[ $field_key ] ) ) {
				continue;
			}
			$field_definition = $custom_fields_options[ $field_key ];
			$raw_value        = $submitted_data[ $field_key ] ?? null;
			
			// Sanitize based on field type
			$sanitized_value = $this->sanitize_custom_field_value( $raw_value, $field_definition );
			
			update_post_meta( $appointment_id, $field_key, $sanitized_value );
			$updated_count++;
		}

		// Set feedback transient
		set_transient(
			'clisyc_manager_edit_feedback_' . get_current_user_id(),
			[
				'type' => 'success',
				'text' => __( 'Appointment updated successfully.', 'client-sync' ),
			],
			30
		);

		// Redirect back
		$redirect_url = wp_get_referer();
		if ( ! $redirect_url ) {
			// Fallback: redirect to the edit page
			$manager_edit_page_id = (int) get_option( Constants::OPTION_MANAGER_EDIT_PAGE, 0 );
			if ( $manager_edit_page_id ) {
				$redirect_url = add_query_arg( 'appointment_id', $appointment_id, get_permalink( $manager_edit_page_id ) );
			} else {
				$redirect_url = admin_url( 'edit.php?post_type=' . Constants::POST_TYPE_APPOINTMENT );
			}
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Sanitize a custom field value based on its type.
	 *
	 * @param mixed $value           The raw value.
	 * @param array $field_definition The field definition.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_custom_field_value( $value, $field_definition ) {
		$type = $field_definition['type'] ?? 'text';

		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'email':
				return sanitize_email( $value );
			case 'url':
				return esc_url_raw( $value );
			case 'number':
				return is_numeric( $value ) ? floatval( $value ) : '';
			case 'checkbox':
				return ! empty( $value ) ? 1 : 0;
			case 'select':
			case 'radio':
				return sanitize_key( $value );
			case 'date':
				return sanitize_text_field( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Find a page containing a specific shortcode.
	 *
	 * @param string $shortcode The shortcode to search for (without brackets).
	 * @return int|null Page ID or null if not found.
	 */
	private function find_page_with_shortcode( $shortcode ) {
		global $wpdb;
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$page_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} 
				WHERE post_type = 'page' 
				AND post_status = 'publish' 
				AND post_content LIKE %s 
				LIMIT 1",
				'%[' . $wpdb->esc_like( $shortcode ) . '%'
			)
		);

		return $page_id ? (int) $page_id : null;
	}

	public function render_manager_edit_notes( $atts ) {
		if ( ! current_user_can( apply_filters( 'clisyc_manager_edit_notes_capability', 'edit_others_posts' ) ) ) {
			return '<p>' . esc_html__( 'You do not have permission to access this page.', 'client-sync' ) . '</p>';
		}
		ob_start();
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' === $request_method && isset( $_POST['clisyc_update_notes_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clisyc_update_notes_nonce'] ) ), 'clisyc_update_notes' ) ) {
				echo '<div class="clisyc-registration-message clisyc-error-message"><p>' . esc_html__( 'Security check failed. Please try again.', 'client-sync' ) . '</p></div>';
			} else {
				$appointment_id   = isset( $_POST['appointment_id'] ) ? absint( $_POST['appointment_id'] ) : 0;
				$new_notes        = isset( $_POST['new_notes'] ) ? wp_kses_post( wp_unslash( $_POST['new_notes'] ) ) : '';
				$appointment_post = get_post( $appointment_id );
				if ( ! $appointment_post || Constants::POST_TYPE_APPOINTMENT !== $appointment_post->post_type || ! current_user_can( 'edit_post', $appointment_id ) ) {
					echo '<div class="clisyc-registration-message clisyc-error-message"><p>' . esc_html__( 'Invalid appointment ID or insufficient permissions to edit.', 'client-sync' ) . '</p></div>';
				} else {
					$update_args = [
						'ID'           => $appointment_id,
						'post_content' => $new_notes,
					];
					$updated_post_id_or_error = wp_update_post( $update_args, true );

					if ( is_wp_error( $updated_post_id_or_error ) ) {
						echo '<div class="clisyc-registration-message clisyc-error-message"><p>' . esc_html__( 'Failed to update notes.', 'client-sync' ) . ' ' . esc_html( $updated_post_id_or_error->get_error_message() ) . '</p></div>';
					} else {
						$current_content_check = get_post_field( 'post_content', $appointment_id );
						if ( $current_content_check === $new_notes ) {
							$notice_type = ( 0 === $updated_post_id_or_error && $appointment_post->post_content === $new_notes ) ? 'info' : 'success';
							/* translators: %d: The numeric ID of the appointment. */
							$message = ( 0 === $updated_post_id_or_error && $appointment_post->post_content === $new_notes ) ? sprintf( esc_html__( 'No changes detected in notes for appointment ID %d.', 'client-sync' ), $appointment_id ) : sprintf( esc_html__( 'Notes updated successfully for appointment ID %d.', 'client-sync' ), $appointment_id );
							echo '<div class="clisyc-registration-message clisyc-' . esc_attr( $notice_type ) . '-message"><p>' . wp_kses_post( $message ) . '</p></div>';
						} else {
							/* translators: %d: The numeric ID of the appointment. */
							echo '<div class="clisyc-registration-message clisyc-error-message"><p>' . esc_html__( 'Notes may not have updated as expected for appointment ID ', 'client-sync' ) . esc_html( $appointment_id ) . '. Please verify.</p></div>';
						}
					}
				}
			}
		}
		$show_list          = true;
		$appointment_id_get = isset( $_GET['appointment_id'] ) ? absint( $_GET['appointment_id'] ) : 0;
		if ( $appointment_id_get > 0 ) {
			$appointment_post_check = get_post( $appointment_id_get );
			if ( $appointment_post_check && Constants::POST_TYPE_APPOINTMENT === $appointment_post_check->post_type && current_user_can( 'edit_post', $appointment_id_get ) ) {
				$show_list     = false;
				$current_notes = $appointment_post_check->post_content;
				?>
				<div class="clisyc-container"><h2><?php esc_html_e( 'Edit Appointment Notes', 'client-sync' ); ?></h2>
				<?php $list_url = remove_query_arg( [ 'appointment_id', 'clisyc_message_type', 'clisyc_message_text' ] ); ?>
				 <p><a href="<?php echo esc_url( $list_url ); ?>">« <?php esc_html_e( 'Back to Appointment List', 'client-sync' ); ?></a></p>
				<form method="post" action="<?php echo esc_url( $list_url ); ?>">
					 <?php /* translators: %d: The numeric ID of the appointment. */ ?>
					 <p><label for="clisyc_new_notes_<?php echo esc_attr( $appointment_id_get ); ?>"><?php printf( esc_html__( 'Notes for Appointment #%d:', 'client-sync' ), esc_html( $appointment_id_get ) ); ?></label></p>
					 <textarea name="new_notes" id="clisyc_new_notes_<?php echo esc_attr( $appointment_id_get ); ?>" rows="10" style="width: 98%;"><?php echo esc_textarea( $current_notes ); ?></textarea>
					 <input type="hidden" name="appointment_id" value="<?php echo esc_attr( $appointment_id_get ); ?>">
					 <?php wp_nonce_field( 'clisyc_update_notes', 'clisyc_update_notes_nonce' ); ?>
					 <p><input type="submit" value="<?php esc_attr_e( 'Update Notes', 'client-sync' ); ?>" class="button button-primary clisyc-submit-button"></p>
				</form></div><hr style="margin: 20px 0;">
				<?php
			}
		}

		if ( $show_list ) {
			echo '<div class="clisyc-container"><h2>' . esc_html__( 'Appointments List (for Note Editing)', 'client-sync' ) . '</h2>';
			$paged              = get_query_var( 'paged' ) ? absint( get_query_var( 'paged' ) ) : 1;
			$args               = [
				'post_type'      => Constants::POST_TYPE_APPOINTMENT,
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'paged'          => $paged,
				'orderby'        => 'meta_value',
				'meta_key'       => Constants::META_TIME_SLOT,
				'order'          => 'DESC',
				'no_found_rows'  => false,
			];
			$appointments_query = new \WP_Query( $args );
			if ( $appointments_query->have_posts() ) {
				echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th scope="col" class="manage-column">' . esc_html__( 'Client', 'client-sync' ) . '</th><th scope="col" class="manage-column">' . esc_html__( 'Date', 'client-sync' ) . '</th><th scope="col" class="manage-column">' . esc_html__( 'Time', 'client-sync' ) . '</th><th scope="col" class="manage-column">' . esc_html__( 'Current Notes (Excerpt)', 'client-sync' ) . '</th><th scope="col" class="manage-column">' . esc_html__( 'Action', 'client-sync' ) . '</th></tr></thead><tbody id="the-list">';
				while ( $appointments_query->have_posts() ) {
					$appointments_query->the_post();
					$appt_id         = get_the_ID();
					$client_user_id  = get_post_field( 'post_author', $appt_id );
					$client_info     = get_userdata( $client_user_id );
					$client_name     = $client_info ? $client_info->display_name : 'N/A';
					$notes_content   = get_post_field( 'post_content', $appt_id );
					$notes_excerpt   = wp_trim_words( wp_strip_all_tags( $notes_content ), 15, '...' );
					$time_slot_meta  = get_post_meta( $appt_id, Constants::META_TIME_SLOT, true );

					$time_info      = $this->format_datetime_from_slot_id( $time_slot_meta );
					$display_date   = $time_info['date'];
					$display_time   = $time_info['time'];

					$edit_link = add_query_arg( 'appointment_id', $appt_id, remove_query_arg( [ 'clisyc_message_type', 'clisyc_message_text' ] ) );
					echo '<tr><td>' . esc_html( $client_name ) . '</td><td>' . esc_html( $display_date ) . '</td><td>' . esc_html( $display_time ) . '</td><td>' . esc_html( $notes_excerpt ) . '</td><td>';
					if ( current_user_can( 'edit_post', $appt_id ) ) {
						echo '<a href="' . esc_url( $edit_link ) . '" class="button button-secondary">' . esc_html__( 'Edit Notes', 'client-sync' ) . '</a>';
					} else {
						echo esc_html__( 'Edit Notes (No Perm)', 'client-sync' );
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';

				$big = 999999999;
				$pagination_html = paginate_links(
					[
						'base'    => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
						'format'  => '?paged=%#%',
						'current' => max( 1, $paged ),
						'total'   => $appointments_query->max_num_pages,
					]
				);
				echo wp_kses_post( $pagination_html );

			} else {
				echo '<p>' . esc_html__( 'No appointments found.', 'client-sync' ) . '</p>';
			}
			wp_reset_postdata();
			echo '</div>';
		}
		return ob_get_clean();
	}
}