<?php
/**
 * File: src/shared/includes/core/class-notification-event-registry.php
 * Centralizes all notification event type definitions and default templates.
 *
 * Extracted from class-notifications.php to follow the Single Responsibility Principle.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Notification_Event_Registry
 *
 * Single source of truth for all notification event type definitions,
 * default email templates (subject/body), and default SMS templates.
 */
class Notification_Event_Registry {

	/**
	 * Get the full array of defined notification events.
	 *
	 * This is the single source of truth for events.
	 *
	 * @return array
	 */
	public function get_event_types() {
		$events = [
			'new_appointment_client'          => [
				'label'           => __( 'New Appointment (Booked by Client)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'admin', 'client' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_id}',
					'{client_name}',
					'{client_email}',
					'{client_username}',
					'{client_first_name}',
					'{client_last_name}',
					'{client_display_name}',
					'{appointment_id}',
					'{appointment_date}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{appointment_time_slot}',
					'{appointment_duration}',
					'{appointment_notes}',
					'{appointment_edit_link_admin}',
				],
			],
			'new_appointment_admin'           => [
				'label'           => __( 'New Appointment (Created by Admin)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'client' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_id}',
					'{client_name}',
					'{client_email}',
					'{client_username}',
					'{client_first_name}',
					'{client_last_name}',
					'{client_display_name}',
					'{appointment_id}',
					'{appointment_date}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{appointment_time_slot}',
					'{appointment_duration}',
					'{appointment_notes}',
				],
			],
			'appointment_updated_admin'       => [
				'label'           => __( 'Appointment Updated (By Admin)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'client' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_id}',
					'{client_name}',
					'{client_email}',
					'{client_username}',
					'{client_first_name}',
					'{client_last_name}',
					'{client_display_name}',
					'{appointment_id}',
					'{appointment_date}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{appointment_time_slot}',
					'{appointment_duration}',
					'{appointment_notes}',
				],
			],
			'appointment_cancelled_admin'     => [
				'label'           => __( 'Appointment Cancelled (By Admin)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'client' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_id}',
					'{client_name}',
					'{client_email}',
					'{client_username}',
					'{client_first_name}',
					'{client_last_name}',
					'{client_display_name}',
					'{appointment_id}',
					'{appointment_date}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{appointment_time_slot}',
				],
			],
			// ** NEW EVENT **
			'appointment_cancelled_by_client' => [
				'label'           => __( 'Appointment Cancelled (By Client)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'admin', 'client' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_id}',
					'{client_name}',
					'{client_email}',
					'{appointment_id}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{appointment_edit_link_admin}',
				],
			],
			'new_client_registration_client'  => [
				'label'           => __( 'New Client Registration (Welcome Email)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'client' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_id}',
					'{client_name}',
					'{client_email}',
					'{client_username}',
					'{client_first_name}',
					'{client_last_name}',
					'{client_display_name}',
				],
			],
			'new_client_registration_admin'   => [
				'label'           => __( 'New Client Registration (Admin Notification)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'admin' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_id}',
					'{client_name}',
					'{client_email}',
					'{client_username}',
					'{client_first_name}',
					'{client_last_name}',
					'{client_display_name}',
				],
			],
			'payment_successful_client'       => [
				'label'           => __( 'Payment Successful (Client)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'client' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_id}',
					'{client_name}',
					'{client_email}',
					'{appointment_id}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{order_id}',
					'{order_total}',
					'{order_payment_method_title}',
				],
			],
			'payment_successful_admin'        => [
				'label'           => __( 'Payment Successful (Admin)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'admin' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_name}',
					'{client_email}',
					'{appointment_id}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{appointment_edit_link_admin}',
					'{order_id}',
					'{order_total}',
					'{order_link_admin}',
					'{order_payment_method_title}',
				],
			],
			'scheduled_payment_failure_client' => [
				'label'           => __( 'Scheduled Payment Failed (Client)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'client' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_name}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{failure_reason}',
					'{order_id}',
				],
			],
			'scheduled_payment_failure_admin' => [
				'label'           => __( 'Scheduled Payment Failed (Admin)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'admin' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_name}',
					'{client_email}',
					'{appointment_id}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{appointment_edit_link_admin}',
					'{failure_reason}',
					'{order_id}',
					'{order_link_admin}',
				],
			],
			'appointment_reminder'            => [
				'label'           => __( 'Appointment Reminder (Client)', 'client-sync' ), // FIXED
				'recipient_types' => [ 'client' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_id}',
					'{client_name}',
					'{client_email}',
					'{client_first_name}',
					'{client_last_name}',
					'{client_display_name}',
					'{appointment_id}',
					'{appointment_date}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{appointment_time_slot}',
					'{appointment_duration}',
					'{appointment_view_link}',
				],
			],
			'waitlist_joined'                 => [
				'label'           => __( 'Waitlist Joined (Client Confirmation)', 'client-sync' ),
				'recipient_types' => [ 'admin', 'client' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_name}',
					'{client_email}',
					'{appointment_id}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{waitlist_position}',
				],
			],
			'waitlist_promoted'               => [
				'label'           => __( 'Waitlist Promoted (Spot Available)', 'client-sync' ),
				'recipient_types' => [ 'admin', 'client' ],
				'placeholders'    => [
					'{site_name}',
					'{site_url}',
					'{client_name}',
					'{client_email}',
					'{appointment_id}',
					'{appointment_date_formatted}',
					'{appointment_time}',
					'{appointment_view_link}',
				],
			],
		];

		// *** REFACTORED ***: Updated option name
		$appointment_fields = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		$appt_event_keys    = [
			'new_appointment_client',
			'new_appointment_admin',
			'appointment_updated_admin',
			'payment_successful_client',
			'payment_successful_admin',
			'appointment_reminder',
		];
		foreach ( $events as $event_key => $event_data ) {
			if ( in_array( $event_key, $appt_event_keys ) ) {
				foreach ( $appointment_fields as $field_key => $field_config ) {
					$placeholder = '{appointment_field_' . $field_key . '}';
					if ( ! in_array( $placeholder, $events[ $event_key ]['placeholders'] ) ) {
						$events[ $event_key ]['placeholders'][] = $placeholder;
					}
				}
				sort( $events[ $event_key ]['placeholders'] );
			}
		}

		// *** REFACTORED ***: Updated filter name
		 return apply_filters( 'clisyc_notification_event_types', $events );
	}

	/**
	 * Get the default subject and body templates for a specific event.
	 *
	 * @param string $event_type The event type key.
	 * @return array
	 */
	public function get_default_templates_for_event( $event_type ) {
		$defaults        = [];
		$recipient_types = $this->get_event_types()[ $event_type ]['recipient_types'] ?? [];

		foreach ( $recipient_types as $type ) {
			$defaults[ $type . '_enabled' ] = ( $type === 'client' ); // Enable client by default
			$defaults[ $type . '_subject' ] = $this->get_default_notification_subject( $event_type, $type );
			$defaults[ $type . '_body' ]    = $this->get_default_notification_body( $event_type, $type );
		}

		// Add default SMS template (SMS only applies to client-facing events).
		if ( in_array( 'client', $recipient_types, true ) ) {
			$defaults['sms_enabled'] = false; // Off by default — admin must opt in.
			$defaults['sms_body']    = $this->get_default_sms_body( $event_type );
		}

		return $defaults;
	}

	/**
	 * Get the default notification subject for a given event type and recipient type.
	 *
	 * @param string $event_type     The event type key.
	 * @param string $recipient_type The recipient type (e.g. 'client', 'admin').
	 * @return string
	 */
	private function get_default_notification_subject( $event_type, $recipient_type ) {
		$subjects = [
			'new_appointment_client'           => [
				'client' => __( 'Your Appointment Confirmation - {site_name}', 'client-sync' ), // FIXED
				'admin'  => __( 'New Appointment Booking - {site_name}', 'client-sync' ), // FIXED
			],
			'new_appointment_admin'            => [ 'client' => __( 'An Appointment Was Scheduled For You - {site_name}', 'client-sync' ) ], // FIXED
			'appointment_updated_admin'        => [ 'client' => __( 'Your Appointment Details Have Been Updated - {site_name}', 'client-sync' ) ], // FIXED
			'appointment_cancelled_admin'      => [ 'client' => __( 'Your Appointment Has Been Cancelled - {site_name}', 'client-sync' ) ], // FIXED
			'appointment_cancelled_by_client'  => [ // ** NEW **
				'client' => __( 'Appointment Cancellation Confirmation - {site_name}', 'client-sync' ), // FIXED
				'admin'  => __( 'Client Cancellation: Appointment #{appointment_id} - {site_name}', 'client-sync' ), // FIXED
			],
			'new_client_registration_client'   => [ 'client' => __( 'Welcome to {site_name}!', 'client-sync' ) ], // FIXED
			'new_client_registration_admin'    => [ 'admin' => __( 'New Client Registration - {site_name}', 'client-sync' ) ], // FIXED
			'payment_successful_client'        => [ 'client' => __( 'Payment Received for Your Appointment - {site_name}', 'client-sync' ) ], // FIXED
			'payment_successful_admin'         => [ 'admin' => __( 'Payment Received for Appointment #{appointment_id} - {site_name}', 'client-sync' ) ], // FIXED
			'scheduled_payment_failure_client' => [ 'client' => __( 'Action Required: Problem Charging for Your Appointment - {site_name}', 'client-sync' ) ], // FIXED
			'scheduled_payment_failure_admin'  => [ 'admin' => __( 'Scheduled Payment FAILED for Appointment #{appointment_id} - {site_name}', 'client-sync' ) ], // FIXED
			'appointment_reminder'             => [ 'client' => __( 'Appointment Reminder for {appointment_date_formatted} at {appointment_time} - {site_name}', 'client-sync' ) ], // FIXED
			'waitlist_joined'                  => [
				'client' => __( 'You\'re on the Waitlist - {site_name}', 'client-sync' ),
				'admin'  => __( 'New Waitlist Entry for {appointment_date_formatted} - {site_name}', 'client-sync' ),
			],
			'waitlist_promoted'                => [
				'client' => __( 'A Spot Opened Up! Booking Confirmed - {site_name}', 'client-sync' ),
				'admin'  => __( 'Waitlist Promotion: Appointment #{appointment_id} Confirmed - {site_name}', 'client-sync' ),
			],
		];
		return $subjects[ $event_type ][ $recipient_type ] ?? __( 'Notification from {site_name}', 'client-sync' ); // FIXED
	}

	/**
	 * Get the default notification body for a given event type and recipient type.
	 *
	 * @param string $event_type     The event type key.
	 * @param string $recipient_type The recipient type (e.g. 'client', 'admin').
	 * @return string
	 */
	private function get_default_notification_body( $event_type, $recipient_type ) {
		$bodies = [
			'new_appointment_client'           => [
				'client' => __( "Hi {client_name},\n\nYour appointment is confirmed:\n\nDate: {appointment_date_formatted}\nTime: {appointment_time}\n\nThanks,\n{site_name}", 'client-sync' ), // FIXED
				'admin'  => __( "A new appointment has been booked:\n\nClient: {client_display_name} ({client_email})\nDate: {appointment_date_formatted}\nTime: {appointment_time}\n\nView/Edit: {appointment_edit_link_admin}", 'client-sync' ), // FIXED
			],
			'new_appointment_admin'            => [ 'client' => __( "Hi {client_name},\n\nAn administrator has scheduled an appointment for you:\n\nDate: {appointment_date_formatted}\nTime: {appointment_time}\n\nIf you have questions, please contact us.\n\nThanks,\n{site_name}", 'client-sync' ) ], // FIXED
			'appointment_updated_admin'        => [ 'client' => __( "Hi {client_name},\n\nYour appointment scheduled for {appointment_date_formatted} at {appointment_time} has been updated by an administrator.\n\nPlease contact us if you have any questions.\n\nThanks,\n{site_name}", 'client-sync' ) ], // FIXED
			'appointment_cancelled_admin'      => [ 'client' => __( "Hi {client_name},\n\nYour appointment scheduled for {appointment_date_formatted} at {appointment_time} has been cancelled by an administrator.\n\nPlease contact us if you believe this was in error or if you need to reschedule.\n\nThanks,\n{site_name}", 'client-sync' ) ], // FIXED
			'appointment_cancelled_by_client'  => [ // ** NEW **
				'client' => __( "Hi {client_name},\n\nThis confirms that your appointment scheduled for {appointment_date_formatted} at {appointment_time} has been successfully cancelled.\n\nIf you need to reschedule, please visit our booking page.\n\nThanks,\n{site_name}", 'client-sync' ), // FIXED
				'admin'  => __( "Client Cancellation Notice:\n\nThe following appointment has been cancelled by the client:\n\nClient: {client_display_name} ({client_email})\nAppointment ID: {appointment_id}\nOriginal Date & Time: {appointment_date_formatted} at {appointment_time}\n\nThe time slot has been made available again automatically.", 'client-sync' ), // FIXED
			],
			'new_client_registration_client'   => [ 'client' => __( "Hi {client_name},\n\nThank you for registering at {site_name}!\n\nYou can manage your account here: {site_url}/wp-admin/profile.php\n\nThanks,\nThe {site_name} Team", 'client-sync' ) ], // FIXED
			'new_client_registration_admin'    => [ 'admin' => __( "A new client has registered:\n\nUsername: {client_username}\nEmail: {client_email}\nName: {client_display_name}\n\nView Profile: {site_url}/wp-admin/user-edit.php?user_id={client_id}", 'client-sync' ) ], // FIXED
			'payment_successful_client'        => [ 'client' => __( "Hi {client_name},\n\nWe have successfully received payment ({order_total}) for your upcoming appointment:\n\nDate: {appointment_date_formatted}\nTime: {appointment_time}\n\nOrder Number: {order_id}\n\nWe look forward to seeing you!\n\nThanks,\n{site_name}", 'client-sync' ) ], // FIXED
			'payment_successful_admin'         => [ 'admin' => __( "Payment ({order_total}) received for appointment #{appointment_id}.\n\nClient: {client_display_name} ({client_email})\nDate: {appointment_date_formatted}\nTime: {appointment_time}\n\nWooCommerce Order: #{order_id}\nPayment Method: {order_payment_method_title}\n\nView Order: {order_link_admin}\nView Appointment: {appointment_edit_link_admin}", 'client-sync' ) ], // FIXED
			'scheduled_payment_failure_client' => [ 'client' => __( "Hi {client_name},\n\nWe encountered an issue attempting to process the scheduled payment for your upcoming appointment on {appointment_date_formatted} at {appointment_time}.\n\nReason: {failure_reason}\n\nPlease update your payment information in your account or contact us to arrange payment.\n\nThanks,\n{site_name}", 'client-sync' ) ], // FIXED
			'scheduled_payment_failure_admin'  => [ 'admin' => __( "ALERT: Scheduled payment failed for appointment #{appointment_id}.\n\nClient: {client_display_name} ({client_email})\nDate: {appointment_date_formatted}\nTime: {appointment_time}\n\nReason: {failure_reason}\n\nWooCommerce Order (Attempted): #{order_id}\nView Order: {order_link_admin}\nView Appointment: {appointment_edit_link_admin}", 'client-sync' ) ], // FIXED
			'appointment_reminder'             => [ 'client' => __( "Hi {client_name},\n\nThis is a friendly reminder for your upcoming appointment:\n\nDate: {appointment_date_formatted}\nTime: {appointment_time}\n\nIf you need to view or manage your appointment, you can do so here: {appointment_view_link}\n\nWe look forward to seeing you!\n\nThanks,\n{site_name}", 'client-sync' ) ], // FIXED
			'waitlist_joined'                  => [
				'client' => __( "Hi {client_name},\n\nYou have been added to the waitlist for the following time slot:\n\nDate: {appointment_date_formatted}\nTime: {appointment_time}\nYour Position: #{waitlist_position}\n\nIf a spot opens up, you will be automatically notified and your booking will be confirmed.\n\nThanks,\n{site_name}", 'client-sync' ),
				'admin'  => __( "A new waitlist entry has been created:\n\nClient: {client_name} ({client_email})\nDate: {appointment_date_formatted}\nTime: {appointment_time}\nWaitlist Position: #{waitlist_position}", 'client-sync' ),
			],
			'waitlist_promoted'                => [
				'client' => __( "Hi {client_name},\n\nGreat news! A spot has opened up and your booking has been confirmed:\n\nDate: {appointment_date_formatted}\nTime: {appointment_time}\n\nYou can view your appointment details here: {appointment_view_link}\n\nWe look forward to seeing you!\n\nThanks,\n{site_name}", 'client-sync' ),
				'admin'  => __( "A waitlisted client has been automatically promoted to a confirmed booking:\n\nClient: {client_name} ({client_email})\nAppointment ID: #{appointment_id}\nDate: {appointment_date_formatted}\nTime: {appointment_time}", 'client-sync' ),
			],
		];
		 return $bodies[ $event_type ][ $recipient_type ] ?? __( 'You have a new notification.', 'client-sync' ); // FIXED
	}

	/**
	 * Get the default SMS body template for a given event type.
	 *
	 * @param string $event_type The event type key.
	 * @return string
	 */
	public function get_default_sms_body( string $event_type ): string {
		$templates = [
			'new_appointment_client'          => __( 'Hi {client_name}, your appt on {appointment_date_formatted} at {appointment_time} is confirmed. - {site_name}', 'client-sync' ),
			'new_appointment_admin'           => __( '{site_name}: {client_name} booked for {appointment_date_formatted} at {appointment_time}.', 'client-sync' ),
			'appointment_updated_admin'       => __( 'Hi {client_name}, your appt on {appointment_date_formatted} has been updated. Check email for details. - {site_name}', 'client-sync' ),
			'appointment_cancelled_admin'     => __( 'Hi {client_name}, your appt on {appointment_date_formatted} at {appointment_time} has been cancelled. - {site_name}', 'client-sync' ),
			'appointment_cancelled_by_client' => __( 'Your appt on {appointment_date_formatted} at {appointment_time} has been cancelled. - {site_name}', 'client-sync' ),
			'appointment_reminder'            => __( 'Reminder: Appt on {appointment_date_formatted} at {appointment_time}. - {site_name}', 'client-sync' ),
			'payment_successful_client'       => __( 'Payment received for your appt on {appointment_date_formatted}. Thank you! - {site_name}', 'client-sync' ),
			'waitlist_joined'                 => __( "You're #{waitlist_position} on the waitlist for {appointment_date_formatted} at {appointment_time}. - {site_name}", 'client-sync' ),
			'waitlist_promoted'               => __( 'A spot opened! Your appt on {appointment_date_formatted} at {appointment_time} is confirmed. - {site_name}', 'client-sync' ),
		];

		return $templates[ $event_type ] ?? '';
	}
}
