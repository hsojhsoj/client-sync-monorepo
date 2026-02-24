<?php
/**
 * File: src/shared/includes/admin/settings/trait-settings-behavior.php
 * Behavior settings registration and callbacks (booking rules, links, self-service,
 * waitlist, spam protection, HIPAA, advanced).
 *
 * Extracted from Settings_Manager to keep each settings group focused.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Settings
 */

namespace DependentMedia\ClientSync\Admin\Settings;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Settings_Behavior {

	/**
	 * Register behavior-related settings, sections, and fields.
	 */
	protected function register_behavior_settings(): void {
		$group = 'clisyc_behavior_settings_group';
		$page  = 'clisyc-behavior-settings';

		// --- Register settings ---
		register_setting( $group, Constants::OPTION_MIN_BOOKING_NOTICE, [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 60 ] );
		register_setting( $group, Constants::OPTION_GLOBAL_BUFFER_BEFORE, [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ] );
		register_setting( $group, Constants::OPTION_GLOBAL_BUFFER_AFTER, [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ] );
		register_setting( $group, 'clisyc_calendar_show_overview_availability', [ 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_overview_availability_setting' ], 'default' => 'none' ] );
		register_setting( $group, Constants::OPTION_CALENDAR_SMART_START, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );
		register_setting( $group, 'clisyc_mysql_convert_tz_override', [ 'type' => 'string', 'sanitize_callback' => [$this, 'sanitize_convert_tz_override_setting'], 'default' => 'auto_detect' ] );
		register_setting( $group, Constants::OPTION_UNIVERSAL_SHORTCODE_MODE, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'slot' ] );
		register_setting( $group, Constants::OPTION_ENABLE_SELF_SERVICE, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );
		register_setting( $group, Constants::OPTION_CANCEL_CUTOFF_VALUE, [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 24 ] );
		register_setting( $group, Constants::OPTION_CANCEL_CUTOFF_UNIT, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'hours' ] );
		register_setting( $group, Constants::OPTION_CANCEL_REFUND_POLICY, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'none' ] );

		// Page ID settings.
		register_setting( $group, Constants::OPTION_BOOKING_PAGE_ID, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
		register_setting( $group, Constants::OPTION_APPOINTMENT_VIEW_PAGE, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
		register_setting( $group, Constants::OPTION_BOOKING_SUCCESS_PAGE, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
		register_setting( $group, Constants::OPTION_SEARCH_RESULTS_PAGE, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
		register_setting( $group, Constants::OPTION_CONTACT_PAGE, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
		register_setting( $group, Constants::OPTION_MANAGER_EDIT_PAGE, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );

		// Blocked periods.
		register_setting( $group, Constants::OPTION_GLOBAL_BLOCKED_PERIODS, [
			'type'              => 'array',
			'sanitize_callback' => function ( $value ) {
				if ( ! is_array( $value ) ) {
					return [];
				}
				$sanitized = [];
				foreach ( $value as $period ) {
					if ( ! is_array( $period ) ) {
						continue;
					}
					$clean = [];
					if ( isset( $period['start'] ) ) {
						$clean['start'] = sanitize_text_field( $period['start'] );
					}
					if ( isset( $period['end'] ) ) {
						$clean['end'] = sanitize_text_field( $period['end'] );
					}
					if ( isset( $period['reason'] ) ) {
						$clean['reason'] = sanitize_text_field( $period['reason'] );
					}
					if ( ! empty( $clean['start'] ) && ! empty( $clean['end'] ) ) {
						$sanitized[] = $clean;
					}
				}
				return $sanitized;
			},
		] );

		// Anti-spam.
		register_setting( $group, Constants::OPTION_ANTISPAM_HONEYPOT, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true ] );
		register_setting( $group, Constants::OPTION_ANTISPAM_TIME_CHECK, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true ] );

		// HIPAA.
		register_setting( $group, Constants::OPTION_HIPAA_MODE, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );
		register_setting( $group, Constants::OPTION_AUDIT_LOG_RETENTION, [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 2555 ] );
		register_setting( $group, Constants::OPTION_ANONYMIZE_EXTERNAL_SYNC, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true ] );

		// Waitlist.
		register_setting( $group, Constants::OPTION_WAITLIST_ENABLED, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );
		register_setting( $group, Constants::OPTION_WAITLIST_MAX_SIZE, [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ] );
		register_setting( $group, Constants::OPTION_WAITLIST_AUTO_PROMOTE, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true ] );

		// === SECTIONS & FIELDS ===

		// --- Booking Rules ---
		add_settings_section( 'clisyc_behavior_booking_rules_section', __( 'Booking Rules', 'client-sync' ), '__return_false', $page );
		add_settings_field( Constants::OPTION_MIN_BOOKING_NOTICE, __( 'Minimum Booking Notice', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_booking_rules_section', ['id' => Constants::OPTION_MIN_BOOKING_NOTICE, 'type' => 'number', 'class' => 'small-text', 'desc' => __('minutes. Prevents last-minute bookings by requiring this much advance notice.', 'client-sync'), 'default' => 60] );
		add_settings_field( Constants::OPTION_GLOBAL_BUFFER_BEFORE, __( 'Buffer Time Before', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_booking_rules_section', ['id' => Constants::OPTION_GLOBAL_BUFFER_BEFORE, 'type' => 'number', 'class' => 'small-text', 'desc' => __('minutes. Adds preparation time before each appointment.', 'client-sync'), 'default' => 0] );
		add_settings_field( Constants::OPTION_GLOBAL_BUFFER_AFTER, __( 'Buffer Time After', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_booking_rules_section', ['id' => Constants::OPTION_GLOBAL_BUFFER_AFTER, 'type' => 'number', 'class' => 'small-text', 'desc' => __('minutes. Adds cleanup/transition time after each appointment.', 'client-sync'), 'default' => 0] );
		add_settings_field(
			Constants::OPTION_UNIVERSAL_SHORTCODE_MODE,
			__( 'Universal Shortcode Default', 'client-sync' ),
			[ $this, 'render_settings_field_callback' ],
			$page,
			'clisyc_behavior_booking_rules_section',
			[
				'id' => Constants::OPTION_UNIVERSAL_SHORTCODE_MODE,
				'type' => 'select',
				'options' => [
					'slot'       => __( 'Time-Slot Calendar', 'client-sync' ),
					'date_range' => __( 'Date Range Search Form', 'client-sync' ),
				],
				'desc' => __( 'Default booking interface for <code>[clisyc_booking_form]</code> on generic pages.', 'client-sync' ),
			]
		);
		add_settings_field( Constants::OPTION_CALENDAR_SMART_START, __( 'Smart Start Date', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_booking_rules_section', [ 'id' => Constants::OPTION_CALENDAR_SMART_START, 'type' => 'checkbox', 'desc' => __( 'Calendar automatically displays the week of the next available slot instead of the current week.', 'client-sync' ) ] );

		// --- Frontend Links & Pages ---
		add_settings_section( 'clisyc_behavior_frontend_links_section', __( 'Frontend Links & Pages', 'client-sync' ), '__return_false', $page );
		add_settings_field( Constants::OPTION_BOOKING_PAGE_ID, __( 'Booking Page', 'client-sync' ), [ $this, 'render_page_dropdown_field' ], $page, 'clisyc_behavior_frontend_links_section', [ 'id' => Constants::OPTION_BOOKING_PAGE_ID, 'desc' => __( 'Main page with the <code>[clisyc_booking_form]</code> shortcode. Used for generating direct links to services.', 'client-sync' ) ] );
		add_settings_field( Constants::OPTION_LOGIN_PAGE_URL, __( 'Custom Login Page URL', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_frontend_links_section', ['id' => Constants::OPTION_LOGIN_PAGE_URL, 'type' => 'url', 'class' => 'regular-text', 'desc' => __('If set, guests will be redirected here instead of wp-login.php.', 'client-sync')] );
		add_settings_field( Constants::OPTION_APPOINTMENT_VIEW_PAGE, __( 'Appointment Detail Page', 'client-sync' ), [ $this, 'render_page_dropdown_field' ], $page, 'clisyc_behavior_frontend_links_section', [ 'id' => Constants::OPTION_APPOINTMENT_VIEW_PAGE, 'desc' => __( 'Page with <code>[clisyc_appointment_detail]</code> shortcode.', 'client-sync' ) ] );
		add_settings_field( Constants::OPTION_BOOKING_SUCCESS_PAGE, __( 'Booking Success Page', 'client-sync' ), [ $this, 'render_page_dropdown_field' ], $page, 'clisyc_behavior_frontend_links_section', [ 'id' => Constants::OPTION_BOOKING_SUCCESS_PAGE, 'desc' => __( 'Redirect destination after successful booking.', 'client-sync' ) ] );
		add_settings_field( Constants::OPTION_SEARCH_RESULTS_PAGE, __( 'Search Results Page', 'client-sync' ), [ $this, 'render_page_dropdown_field' ], $page, 'clisyc_behavior_frontend_links_section', [ 'id' => Constants::OPTION_SEARCH_RESULTS_PAGE, 'desc' => __( 'Page with <code>[clisyc_search_results]</code> shortcode.', 'client-sync' ) ] );
		add_settings_field( Constants::OPTION_MANAGER_EDIT_PAGE, __( 'Manager Edit Appointment Page', 'client-sync' ), [ $this, 'render_page_dropdown_field' ], $page, 'clisyc_behavior_frontend_links_section', [ 'id' => Constants::OPTION_MANAGER_EDIT_PAGE, 'desc' => __( 'Page with <code>[clisyc_manager_edit_appointment]</code> shortcode. Used by the Manager Appointments dashboard.', 'client-sync' ) ] );
		add_settings_field(
			Constants::OPTION_CONTACT_PAGE,
			__( 'Contact Page', 'client-sync' ),
			[ $this, 'render_page_dropdown_field' ],
			$page,
			'clisyc_behavior_frontend_links_section',
			[
				'id' => Constants::OPTION_CONTACT_PAGE,
				'desc' => __( 'Fallback page shown when no availability exists. Users can contact you for assistance.', 'client-sync' )
			]
		);

		// --- Self-Service ---
		add_settings_section( 'clisyc_behavior_self_service_section', __( 'Client Self-Service', 'client-sync' ), '__return_false', $page );
		add_settings_field( Constants::OPTION_ENABLE_SELF_SERVICE, __( 'Enable Self-Service', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_self_service_section', [ 'id' => Constants::OPTION_ENABLE_SELF_SERVICE, 'type' => 'checkbox', 'desc' => __( 'Allow logged-in clients to cancel or reschedule their own appointments.', 'client-sync' ) ] );
		add_settings_field( 'clisyc_cancellation_cutoff', __( 'Cancellation/Reschedule Cutoff', 'client-sync' ), [ $this, 'render_cancellation_cutoff_field' ], $page, 'clisyc_behavior_self_service_section' );
		add_settings_field( Constants::OPTION_CANCEL_REFUND_POLICY, __( 'Refund Policy on Cancellation', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_self_service_section', [ 'id' => Constants::OPTION_CANCEL_REFUND_POLICY, 'type' => 'select', 'options' => [ 'none' => __( 'No Refund', 'client-sync' ), 'full_refund' => __( 'Attempt Full Refund (Requires WooCommerce)', 'client-sync' ) ], 'desc' => __( 'Action for paid appointments when cancelled within allowed time.', 'client-sync' ) ] );

		// --- Waitlist ---
		add_settings_section( 'clisyc_behavior_waitlist_section', __( 'Waitlist', 'client-sync' ), [ $this, 'render_waitlist_section_header' ], $page );
		add_settings_field( Constants::OPTION_WAITLIST_ENABLED, __( 'Enable Waitlist', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_waitlist_section', [ 'id' => Constants::OPTION_WAITLIST_ENABLED, 'type' => 'checkbox', 'desc' => __( 'Allow clients to join a waitlist when a time slot is fully booked. They will be notified if a spot opens up.', 'client-sync' ) ] );
		add_settings_field( Constants::OPTION_WAITLIST_MAX_SIZE, __( 'Max Waitlist Size Per Slot', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_waitlist_section', [ 'id' => Constants::OPTION_WAITLIST_MAX_SIZE, 'type' => 'number', 'class' => 'small-text', 'desc' => __( 'Maximum number of people who can join the waitlist for a single slot. Set to 0 for unlimited.', 'client-sync' ), 'default' => 0 ] );
		add_settings_field( Constants::OPTION_WAITLIST_AUTO_PROMOTE, __( 'Auto-Promote on Cancellation', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_waitlist_section', [ 'id' => Constants::OPTION_WAITLIST_AUTO_PROMOTE, 'type' => 'checkbox', 'desc' => __( 'Automatically confirm the next person on the waitlist when a spot becomes available.', 'client-sync' ) ] );

		// --- Spam Protection ---
		add_settings_section( 'clisyc_behavior_antispam_section', __( 'Spam Protection', 'client-sync' ), [ $this, 'render_antispam_section_callback' ], $page );
		add_settings_field( Constants::OPTION_ANTISPAM_HONEYPOT, __( 'Enable Honeypot Field', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_antispam_section', [ 'id' => Constants::OPTION_ANTISPAM_HONEYPOT, 'type' => 'checkbox', 'desc' => __( 'Adds a hidden field to catch automated bots.', 'client-sync' ) ] );
		add_settings_field( Constants::OPTION_ANTISPAM_TIME_CHECK, __( 'Enable Timing Check', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_antispam_section', [ 'id' => Constants::OPTION_ANTISPAM_TIME_CHECK, 'type' => 'checkbox', 'desc' => __( 'Rejects submissions that occur too quickly (likely bots).', 'client-sync' ) ] );

		// --- HIPAA Compliance ---
		add_settings_section( 'clisyc_behavior_hipaa_section', __( 'HIPAA Compliance', 'client-sync' ), [ $this, 'render_hipaa_section_header' ], $page );
		add_settings_field( Constants::OPTION_HIPAA_MODE, __( 'Enable HIPAA Mode', 'client-sync' ), [ $this, 'render_hipaa_mode_field' ], $page, 'clisyc_behavior_hipaa_section' );
		add_settings_field( Constants::OPTION_AUDIT_LOG_RETENTION, __( 'Audit Log Retention', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_hipaa_section', [ 'id' => Constants::OPTION_AUDIT_LOG_RETENTION, 'type' => 'number', 'class' => 'small-text', 'default' => 2555, 'desc' => __( 'days. HIPAA requires minimum 6 years. Default is ~7 years (2555 days).', 'client-sync' ), ] );
		add_settings_field( Constants::OPTION_ANONYMIZE_EXTERNAL_SYNC, __( 'Anonymize External Sync', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_behavior_hipaa_section', [ 'id' => Constants::OPTION_ANONYMIZE_EXTERNAL_SYNC, 'type' => 'checkbox', 'desc' => __( 'Send only "Busy - Appt #ID" to Google Calendar instead of client names.', 'client-sync' ), ] );

		// --- Advanced ---
		add_settings_section( 'clisyc_behavior_advanced_section', __( 'Advanced Settings', 'client-sync' ), '__return_false', $page );
		$status_html = '<strong><span id="clisyc-detected-convert-tz-status">' . esc_html( ucfirst( get_option( Constants::OPTION_MYSQL_CONVERT_TZ, 'unknown' ) ) ) . '</span></strong>';
		$reset_link_html = '<br><a href="#" id="clisyc-reset-detection-link" class="clisyc-reset-detection-link">' . esc_html__( 'Reset Auto-Detection Status', 'client-sync' ) . '</a>';
		/* translators: 1: The current detected status. 2: A link to reset the status. */
		$tz_desc = sprintf( esc_html__( 'Current status: %1$s. %2$s', 'client-sync' ), $status_html, $reset_link_html );
		add_settings_field( 'clisyc_mysql_convert_tz_override', __('MySQL Timezone Method', 'client-sync'), [$this, 'render_settings_field_callback'], $page, 'clisyc_behavior_advanced_section', [ 'id' => 'clisyc_mysql_convert_tz_override', 'type' => 'select', 'options' => [ 'auto_detect' => __('Auto-Detect (Recommended)', 'client-sync'), 'force_mysql' => __('Force MySQL CONVERT_TZ', 'client-sync'), 'force_php' => __('Force PHP Conversion', 'client-sync'), ], 'desc' => $tz_desc ] );
	}

	// -----------------------------------------------------------------
	// Section Callbacks
	// -----------------------------------------------------------------

	public function render_waitlist_section_header() {
		echo '<p>' . esc_html__( 'When a time slot reaches capacity, let clients join a waitlist. They will be automatically promoted and notified when a cancellation frees a spot.', 'client-sync' ) . '</p>';
	}

	public function render_antispam_section_callback() {
		echo '<p>' . esc_html__( 'Protect your booking forms from spam submissions without requiring CAPTCHA.', 'client-sync' ) . '</p>';
	}

	/**
	 * Render the HIPAA section header with status indicator.
	 */
	public function render_hipaa_section_header(): void {
		$hipaa_helper = \DependentMedia\ClientSync\Services\HIPAA_Helper::get_instance();
		$status       = $hipaa_helper->get_status();

		echo '<div class="clisyc-hipaa-section-header">';
		echo '<p>' . esc_html__( 'Configure HIPAA compliance features for protecting Patient Health Information (PHI).', 'client-sync' ) . '</p>';

		if ( $status['enabled'] ) {
			if ( $status['operational'] ) {
				echo '<p class="clisyc-hipaa-status clisyc-hipaa-status--active">';
				echo '<span class="dashicons dashicons-shield-alt"></span> ';
				echo esc_html__( 'HIPAA Mode: Active & Operational', 'client-sync' );
				echo '</p>';
			} else {
				echo '<p class="clisyc-hipaa-status clisyc-hipaa-status--warning">';
				echo '<span class="dashicons dashicons-warning"></span> ';
				echo esc_html__( 'HIPAA Mode: Enabled but encryption not configured', 'client-sync' );
				echo '</p>';
			}
		}

		echo '</div>';
	}

	// -----------------------------------------------------------------
	// Field Callbacks
	// -----------------------------------------------------------------

	/**
	 * Render the HIPAA mode toggle field with lock indicator.
	 */
	public function render_hipaa_mode_field(): void {
		$hipaa_helper = \DependentMedia\ClientSync\Services\HIPAA_Helper::get_instance();
		$is_locked    = $hipaa_helper->is_hipaa_mode_locked();
		$is_enabled   = $hipaa_helper->is_hipaa_mode();

		$field_id   = Constants::OPTION_HIPAA_MODE;
		$disabled   = $is_locked ? 'disabled' : '';
		$checked    = $is_enabled ? 'checked' : '';

		echo '<fieldset>';
		echo '<label for="' . esc_attr( $field_id ) . '">';

		printf(
			'<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s %3$s />',
			esc_attr( $field_id ),
			esc_attr( $checked ),
			esc_attr( $disabled )
		);

		echo ' ' . esc_html__( 'Enable HIPAA compliance features (encryption, audit logging, anonymization).', 'client-sync' );
		echo '</label>';

		if ( $is_locked ) {
			$lock_status = CLISYC_HIPAA_MODE ? __( 'ON', 'client-sync' ) : __( 'OFF', 'client-sync' );
			echo '<p class="description clisyc-hipaa-locked">';
			echo '<span class="dashicons dashicons-lock"></span> ';
			printf(
				/* translators: %s: Current lock status (ON/OFF) */
				esc_html__( 'Locked by CLISYC_HIPAA_MODE constant in wp-config.php (currently: %s)', 'client-sync' ),
				'<strong>' . esc_html( $lock_status ) . '</strong>'
			);
			echo '</p>';

			printf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $field_id ),
				$is_enabled ? '1' : '0'
			);
		}

		if ( $is_enabled ) {
			$key_status = $hipaa_helper->validate_encryption_key();
			if ( $key_status['valid'] ) {
				echo '<p class="description" style="color: #00a32a;">';
				echo '<span class="dashicons dashicons-yes-alt"></span> ';
				echo esc_html__( 'Encryption key configured.', 'client-sync' );
				echo '</p>';
			} else {
				echo '<p class="description" style="color: #d63638;">';
				echo '<span class="dashicons dashicons-warning"></span> ';
				echo esc_html( $key_status['message'] );
				echo '</p>';
			}
		}

		echo '</fieldset>';
	}

	public function render_cancellation_cutoff_field() {
		$value = get_option( Constants::OPTION_CANCEL_CUTOFF_VALUE, 24 );
		$unit  = get_option( Constants::OPTION_CANCEL_CUTOFF_UNIT, 'hours' );

		printf( '<input type="number" id="clisyc_cancellation_cutoff_value" name="clisyc_cancellation_cutoff_value" value="%s" class="small-text" min="0" />', esc_attr( $value ) );

		echo '<select id="clisyc_cancellation_cutoff_unit" name="clisyc_cancellation_cutoff_unit">';
		printf( '<option value="hours" %s>%s</option>', selected( $unit, 'hours', false ), esc_html__( 'hours', 'client-sync' ) );
		printf( '<option value="days" %s>%s</option>', selected( $unit, 'days', false ), esc_html__( 'days', 'client-sync' ) );
		echo '</select>';

		echo '<p class="description">' . esc_html__( 'How far in advance clients must cancel or reschedule.', 'client-sync' ) . '</p>';
	}

	// -----------------------------------------------------------------
	// Sanitization
	// -----------------------------------------------------------------

	public function sanitize_overview_availability_setting( $input ) {
		$allowed_values = [ 'none', 'overview_preview', 'overview_bookable', 'overview_bookable_no_filter' ];
		return in_array( $input, $allowed_values, true ) ? $input : 'none';
	}

	public function sanitize_convert_tz_override_setting( $input ) {
		$allowed_values = [ 'auto_detect', 'force_mysql', 'force_php' ];
		if ( in_array( $input, $allowed_values, true ) ) {
			$current_value = get_option( Constants::OPTION_MYSQL_CONVERT_TZ_OR, 'auto_detect' );
			if ( $current_value !== $input ) {
				update_option( 'clisyc_mysql_convert_tz_status', 'unknown' );
				delete_transient( 'clisyc_convert_tz_notice_shown' );
			}
			return $input;
		}
		return 'auto_detect';
	}
}
