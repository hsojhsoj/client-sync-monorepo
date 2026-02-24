<?php
/**
 * File: src/shared/includes/admin/settings/trait-settings-notifications.php
 * Notification settings registration and callbacks.
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

trait Settings_Notifications {

	/**
	 * Register notification-related settings, sections, and fields.
	 */
	protected function register_notification_settings(): void {
		$group = 'clisyc_notification_settings_group';
		$page  = 'clisyc-notifications-settings';

		register_setting( $group, Constants::OPTION_NOTIFICATION_SETTINGS, [ $this, 'sanitize_notification_settings' ] );
		register_setting( $group, Constants::OPTION_EMAIL_FROM_NAME, 'sanitize_text_field' );
		register_setting( $group, Constants::OPTION_EMAIL_FROM_ADDRESS, 'sanitize_email' );
		register_setting( $group, Constants::OPTION_NOTIFICATION_ADMINS, [ $this, 'sanitize_admin_recipients' ] );
		register_setting( $group, Constants::OPTION_REMINDER_SETTINGS, [ $this, 'sanitize_reminder_settings' ] );

		add_settings_section( 'clisyc_notification_general_section', __( 'General Email Settings', 'client-sync' ), null, $page );
		add_settings_field( Constants::OPTION_EMAIL_FROM_NAME, __( 'Email "From" Name', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_notification_general_section', ['id' => Constants::OPTION_EMAIL_FROM_NAME, 'type' => 'text', 'default' => get_bloginfo( 'name' )] );
		add_settings_field( Constants::OPTION_EMAIL_FROM_ADDRESS, __( 'Email "From" Address', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_notification_general_section', ['id' => Constants::OPTION_EMAIL_FROM_ADDRESS, 'type' => 'email', 'default' => get_option( 'admin_email' )] );
		add_settings_field( Constants::OPTION_NOTIFICATION_ADMINS, __( 'Admin Notification Recipients', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_notification_general_section', ['id' => Constants::OPTION_NOTIFICATION_ADMINS, 'type' => 'textarea', 'default' => get_option( 'admin_email' ), 'desc' => __('Comma-separated email addresses.', 'client-sync')] );

		add_settings_section( 'clisyc_appointment_reminders_section', __( 'Appointment Reminder Settings', 'client-sync' ), [ $this, 'render_reminders_section_callback' ], $page );
		add_settings_field( Constants::OPTION_REMINDER_SETTINGS . '[enabled]', __( 'Enable Reminders', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_appointment_reminders_section', ['id' => Constants::OPTION_REMINDER_SETTINGS . '[enabled]', 'type' => 'checkbox', 'option_name' => Constants::OPTION_REMINDER_SETTINGS, 'path' => ['enabled'], 'desc' => __('Send automatic email reminders to clients before their appointments.', 'client-sync')] );
		add_settings_field( Constants::OPTION_REMINDER_SETTINGS . '[lead_time_value]', __( 'Send Reminder Before', 'client-sync' ), [ $this, 'render_reminder_lead_time_field' ], $page, 'clisyc_appointment_reminders_section' );

		$notifications_class = new \DependentMedia\ClientSync\Core\Notifications();
		$event_types = $notifications_class->get_event_types();
		foreach ( $event_types as $event_key => $event_data ) {
			add_settings_section( 'clisyc_notification_section_' . $event_key, $event_data['label'], [ $this, 'render_notification_section_header' ], $page, ['placeholders' => $event_data['placeholders']] );
			foreach ( $event_data['recipient_types'] as $recipient_type ) {
				$base_id = "clisyc_notification_settings[{$event_key}][{$recipient_type}";
				$title_prefix = ('admin' === $recipient_type) ? __('Admin', 'client-sync') : __('Client', 'client-sync');
				/* translators: %s: Recipient type (e.g. "Admin" or "Client"). */
				add_settings_field( $base_id . '_enabled]', sprintf( esc_html__( '%s Email Notification', 'client-sync' ), $title_prefix ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_notification_section_' . $event_key, ['id' => $base_id . '_enabled]', 'type' => 'checkbox', 'option_name' => Constants::OPTION_NOTIFICATION_SETTINGS, 'path' => [$event_key, $recipient_type.'_enabled']] );
				/* translators: %s: Recipient type (e.g. "Admin" or "Client"). */
				add_settings_field( $base_id . '_subject]', sprintf( esc_html__( '%s Email Subject', 'client-sync' ), $title_prefix ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_notification_section_' . $event_key, ['id' => $base_id . '_subject]', 'type' => 'text', 'option_name' => Constants::OPTION_NOTIFICATION_SETTINGS, 'path' => [$event_key, $recipient_type.'_subject'], 'class' => 'regular-text'] );
				/* translators: %s: Recipient type (e.g. "Admin" or "Client"). */
				add_settings_field( $base_id . '_body]', sprintf( esc_html__( '%s Email Body', 'client-sync' ), $title_prefix ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_notification_section_' . $event_key, ['id' => $base_id . '_body]', 'type' => 'wp_editor', 'option_name' => Constants::OPTION_NOTIFICATION_SETTINGS, 'path' => [$event_key, $recipient_type.'_body']] );
			}

			// Add SMS fields for client-facing notifications if Pro is active.
			if ( in_array('client', $event_data['recipient_types'], true) && function_exists('clisyc_pro_is_license_active') && clisyc_pro_is_license_active() ) {
				$sms_base_id = "clisyc_notification_settings[{$event_key}][sms";
				add_settings_field( $sms_base_id . '_enabled]', __('Client SMS Notification', 'client-sync'), [$this, 'render_settings_field_callback'], $page, 'clisyc_notification_section_' . $event_key, ['id' => $sms_base_id . '_enabled]', 'type' => 'checkbox', 'option_name' => Constants::OPTION_NOTIFICATION_SETTINGS, 'path' => [$event_key, 'sms_enabled']]);
				add_settings_field( $sms_base_id . '_body]', __('Client SMS Body', 'client-sync'), [$this, 'render_settings_field_callback'], $page, 'clisyc_notification_section_' . $event_key, ['id' => $sms_base_id . '_body]', 'type' => 'textarea', 'option_name' => Constants::OPTION_NOTIFICATION_SETTINGS, 'path' => [$event_key, 'sms_body'], 'desc' => __('Max 160 characters recommended for single SMS.', 'client-sync')]);
			}
		}
	}

	// -----------------------------------------------------------------
	// Section Callbacks
	// -----------------------------------------------------------------

	public function render_reminders_section_callback() {
		echo '<p>' . esc_html__( 'Configure automatic reminder emails sent to clients before their appointments.', 'client-sync' ) . '</p>';
	}

	public function render_notification_section_header( $args ) {
		if ( ! empty( $args['placeholders'] ) && is_array( $args['placeholders'] ) ) {
			$placeholder_list = implode( ', ', array_map( function( $p ) { return '<code>' . esc_html( $p ) . '</code>'; }, $args['placeholders'] ) );
			echo '<p class="description">' . esc_html__( 'Available placeholders:', 'client-sync' ) . ' ' . wp_kses( $placeholder_list, [ 'code' => [] ] ) . '</p>';
		}
	}

	// -----------------------------------------------------------------
	// Field Callbacks
	// -----------------------------------------------------------------

	public function render_reminder_lead_time_field() {
		$settings = get_option( Constants::OPTION_REMINDER_SETTINGS, [] );
		$value = $settings['lead_time_value'] ?? 24;
		$unit  = $settings['lead_time_unit'] ?? 'hours';

		printf( '<input type="number" name="%s[lead_time_value]" value="%s" class="small-text" min="1" />', esc_attr( Constants::OPTION_REMINDER_SETTINGS ), esc_attr( $value ) );

		printf( '<select name="%s[lead_time_unit]">', esc_attr( Constants::OPTION_REMINDER_SETTINGS ) );
		printf( '<option value="hours" %s>%s</option>', selected( $unit, 'hours', false ), esc_html__( 'hours', 'client-sync' ) );
		printf( '<option value="days" %s>%s</option>', selected( $unit, 'days', false ), esc_html__( 'days', 'client-sync' ) );
		echo '</select>';

		echo '<p class="description">' . esc_html__( 'How long before the appointment should the reminder be sent?', 'client-sync' ) . '</p>';
	}

	// -----------------------------------------------------------------
	// Sanitization
	// -----------------------------------------------------------------

	public function sanitize_notification_settings( $input ) {
		$output = get_option( Constants::OPTION_NOTIFICATION_SETTINGS, [] );
		$notifications_class = new \DependentMedia\ClientSync\Core\Notifications();
		$event_types = $notifications_class->get_event_types();

		if ( ! is_array( $input ) ) {
			return $output;
		}

		foreach ( $event_types as $event_key => $event_data ) {
			if ( isset( $input[ $event_key ] ) && is_array( $input[ $event_key ] ) ) {
				foreach ( $event_data['recipient_types'] as $recipient_type ) {
					$base_key = $recipient_type;
					$output[ $event_key ][ $base_key . '_enabled' ] = isset( $input[ $event_key ][ $base_key . '_enabled' ] ) ? 1 : 0;
					$output[ $event_key ][ $base_key . '_subject' ] = isset( $input[ $event_key ][ $base_key . '_subject' ] ) ? sanitize_text_field( wp_unslash( $input[ $event_key ][ $base_key . '_subject' ] ) ) : '';
					$output[ $event_key ][ $base_key . '_body' ] = isset( $input[ $event_key ][ $base_key . '_body' ] ) ? wp_kses_post( wp_unslash( $input[ $event_key ][ $base_key . '_body' ] ) ) : '';
				}
				if ( in_array( 'client', $event_data['recipient_types'], true ) ) {
					$output[ $event_key ]['sms_enabled'] = ! empty( $input[ $event_key ]['sms_enabled'] );
					$output[ $event_key ]['sms_body'] = isset( $input[ $event_key ]['sms_body'] ) ? sanitize_textarea_field( wp_unslash( $input[ $event_key ]['sms_body'] ) ) : '';
				}
			}
		}
		return $output;
	}

	public function sanitize_reminder_settings( $input ) {
		$output = get_option( Constants::OPTION_REMINDER_SETTINGS, [] );
		if ( ! is_array( $input ) ) {
			return $output;
		}
		$output['enabled'] = isset( $input['enabled'] ) ? 1 : 0;
		$output['lead_time_value'] = isset( $input['lead_time_value'] ) ? max( 1, absint( $input['lead_time_value'] ) ) : 24;
		$allowed_units = [ 'hours', 'days' ];
		$output['lead_time_unit'] = isset( $input['lead_time_unit'] ) && in_array( $input['lead_time_unit'], $allowed_units, true ) ? $input['lead_time_unit'] : 'hours';
		return $output;
	}

	public function sanitize_admin_recipients( $input ) {
		$emails_raw = isset( $input ) ? sanitize_textarea_field( wp_unslash( $input ) ) : '';
		$emails = array_map( 'trim', explode( ',', $emails_raw ) );
		return implode( ',', array_filter( $emails, 'is_email' ) );
	}
}
