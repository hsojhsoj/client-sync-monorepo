<?php
/**
 * File: src/shared/includes/core/class-notifications.php
 * Orchestrates all notifications for the Client Sync plugin.
 *
 * This class handles triggering notification events and dispatching them to
 * all registered channels (e.g., Email, SMS, Webhooks).
 *
 * Delegates event definitions to Notification_Event_Registry and placeholder
 * data building to Placeholder_Data_Builder (Single Responsibility Principle).
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */
namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Core\Interfaces\Notification_Channel_Interface;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

class Notifications {

	private $channels = [];

	/** @var Notification_Event_Registry */
	private $event_registry;

	/** @var Placeholder_Data_Builder */
	private $placeholder_builder;

	public function __construct() {
		$this->channels['email'] = new \DependentMedia\ClientSync\Core\NotificationChannels\Email_Channel();

		$pro_channels = apply_filters( 'clisyc_notification_channels', [] );

		foreach ( $pro_channels as $channel ) {
			if ( $channel instanceof Notification_Channel_Interface ) {
				$this->channels[ $channel->get_id() ] = $channel;
			}
		}

		$this->event_registry      = new Notification_Event_Registry();
		$this->placeholder_builder = new Placeholder_Data_Builder();
	}

	/**
	 * Register all hooks related to notification functionality.
	 */
	public function register_hooks() {
		// Hooks that trigger notifications from appointment status changes
		add_action( 'transition_post_status', [ $this, 'trigger_appointment_status_change_notifications' ], 10, 3 );
		// Hooks that trigger notifications for new user registration
		add_action( 'user_register', [ $this, 'trigger_new_user_notifications' ], 10, 1 );
		// Admin notice for permanently failed notifications.
		add_action( 'admin_notices', [ $this, 'display_failed_notifications_notice' ] );
	}

	/**
	 * Handle a notification that has permanently failed after all retries.
	 *
	 * Logs to audit trail, fires an action hook, and increments an admin-visible counter.
	 *
	 * @param string $event_type      The notification event type.
	 * @param int    $object_id       The related object ID (e.g., appointment ID).
	 * @param array  $failed_channels Channel IDs that failed.
	 * @param array  $data            Additional notification data.
	 */
	private function handle_permanently_failed_notification( string $event_type, int $object_id, array $failed_channels, array $data ): void {
		Debug_Logger::log(
			sprintf(
				'Notification permanently failed after 3 retries — event: %s, object: %d, channels: %s',
				$event_type,
				$object_id,
				implode( ', ', $failed_channels )
			),
			'Notifications'
		);

		// Log to audit trail if available.
		if ( class_exists( '\\DependentMedia\\ClientSync\\Services\\Audit_Logger' ) ) {
			\DependentMedia\ClientSync\Services\Audit_Logger::log(
				'notification_failed',
				'notification',
				$object_id,
				[
					'event_type'      => $event_type,
					'failed_channels' => $failed_channels,
					'retry_count'     => $data['_retry_count'] ?? 3,
				]
			);
		}

		// Fire action hook so other systems can react.
		do_action( 'clisyc_notification_permanently_failed', [
			'event_type'      => $event_type,
			'object_id'       => $object_id,
			'failed_channels' => $failed_channels,
			'data'            => $data,
		] );

		// Increment admin-visible failure counter.
		$count = (int) get_transient( 'clisyc_failed_notifications_count' );
		set_transient( 'clisyc_failed_notifications_count', $count + 1, 7 * DAY_IN_SECONDS );
	}

	/**
	 * Display an admin notice when notifications have permanently failed.
	 */
	public function display_failed_notifications_notice(): void {
		$count = (int) get_transient( 'clisyc_failed_notifications_count' );
		if ( $count <= 0 ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'clisyc_dismiss_failed_notifications', '1' ),
			'clisyc_dismiss_failed_notif'
		);

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			/* translators: %d: number of failed notifications */
			esc_html( sprintf(
				_n(
					'Client Sync: %d notification failed to send after 3 retries. Check the audit log for details.',
					'Client Sync: %d notifications failed to send after 3 retries. Check the audit log for details.',
					$count,
					'client-sync'
				),
				$count
			) ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'client-sync' )
		);

		// Handle dismissal.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['clisyc_dismiss_failed_notifications'] ) ) {
			check_admin_referer( 'clisyc_dismiss_failed_notif' );
			delete_transient( 'clisyc_failed_notifications_count' );
			wp_safe_redirect( remove_query_arg( [ 'clisyc_dismiss_failed_notifications', '_wpnonce' ] ) );
			exit;
		}
	}

	/**
	 * The main method for sending a notification.
	 *
	 * @param string $event_type A unique key for the notification event.
	 * @param int    $object_id  The primary object ID (e.g., Appointment ID, User ID).
	 * @param array  $data       Optional additional data for placeholders.
	 * @return bool True if at least one notification was sent successfully, false otherwise.
	 */
	public function send( $event_type, $object_id, $data = [] ) {
		$placeholder_data = $this->placeholder_builder->prepare_placeholder_data( $event_type, $object_id, $data );
		if ( ! $placeholder_data ) {
			return false;
		}

		$recipients = $this->get_recipients_for_event( $event_type, $object_id, $data );
		if ( empty( $recipients ) ) {
			return false;
		}

		$sent_successfully = false;
		$failed_channels   = [];
		foreach ( $this->channels as $channel_id => $channel ) {
			try {
				if ( $channel->send( $event_type, $placeholder_data, $recipients ) ) {
					$sent_successfully = true;
				}
			} catch ( \Throwable $e ) {
				$failed_channels[] = $channel_id;
				Debug_Logger::log(
					sprintf( 'Notification channel "%s" failed for event "%s": %s', $channel_id, $event_type, $e->getMessage() ),
					'Notifications'
				);
			}
		}

		// Schedule retry for failed channels if Action Scheduler is available.
		if ( ! empty( $failed_channels ) && function_exists( 'as_schedule_single_action' ) ) {
			$retry_count = $data['_retry_count'] ?? 0;
			if ( $retry_count < 3 ) {
				as_schedule_single_action(
					time() + ( 300 * ( $retry_count + 1 ) ),
					'clisyc_retry_notification',
					[ $event_type, $object_id, array_merge( $data, [ '_retry_count' => $retry_count + 1, '_channels' => $failed_channels ] ) ]
				);
			} else {
				// Dead letter: all retries exhausted. Log, fire action, and alert admin.
				$this->handle_permanently_failed_notification( $event_type, $object_id, $failed_channels, $data );
			}
		}

		return $sent_successfully;
	}

	/**
	 * Triggers notifications when an appointment's status changes.
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function trigger_appointment_status_change_notifications( $new_status, $old_status, $post ) {
		// *** REFACTORED ***: Updated CPT name
		if ( Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			return;
		}
		if ( $old_status === 'new' || $old_status === 'auto-draft' || $new_status === 'auto-draft' || $new_status === $old_status ) {
			return;
		}

		if ( $new_status === 'trash' && $old_status !== 'trash' ) {
			// Note: This is for admin-initiated cancellations. Client-initiated cancellations are handled separately.
			$this->send( 'appointment_cancelled_admin', $post->ID );
		}
		if ( $new_status === 'pending' && $old_status !== 'pending' ) {
			$this->send( 'appointment_pending_admin', $post->ID );
		}
		// *** REFACTORED ***: Updated custom status names
		if ( $new_status === Constants::STATUS_PENDING_PAYMENT && $old_status !== Constants::STATUS_PENDING_PAYMENT ) {
			$this->send( 'appointment_payment_due_client', $post->ID );
			$this->send( 'appointment_updated_admin', $post->ID );
		}
		// *** REFACTORED ***: Updated custom status names
		if ( $new_status === Constants::STATUS_PAID_ON_DAY && $old_status !== Constants::STATUS_PAID_ON_DAY ) {
			$this->send( 'payment_successful_client', $post->ID );
			$this->send( 'payment_successful_admin', $post->ID );
		}
		// *** REFACTORED ***: Updated custom status names
		if ( $new_status === Constants::STATUS_FAILED_ON_DAY && $old_status !== Constants::STATUS_FAILED_ON_DAY ) {
			$this->send( 'scheduled_payment_failure_client', $post->ID );
			$this->send( 'scheduled_payment_failure_admin', $post->ID );
		}
	}

	/**
	 * Triggers notifications when a new user registers.
	 * @param int $user_id The new user's ID.
	 */
	public function trigger_new_user_notifications( $user_id ) {
		$this->send( 'new_client_registration_admin', $user_id );
		$this->send( 'new_client_registration_client', $user_id );
	}

	/**
	 * Retrieves the settings for a specific notification event, merged with defaults.
	 * @param string $event_type The key of the event.
	 * @return array|null The settings array for the event, or null.
	 */
	private function get_notification_settings( $event_type ) {
		// *** REFACTORED ***: Updated option name
		$all_settings   = get_option( Constants::OPTION_NOTIFICATION_SETTINGS, [] );
		$defaults       = $this->event_registry->get_default_templates_for_event( $event_type );
		$event_settings = isset( $all_settings[ $event_type ] ) ? $all_settings[ $event_type ] : [];

		return wp_parse_args( $event_settings, $defaults );
	}

	/**
	 * Returns the full array of defined notification events.
	 *
	 * Delegates to Notification_Event_Registry.
	 *
	 * @return array
	 */
	public function get_event_types() {
		return $this->event_registry->get_event_types();
	}

	/**
	 * Gets default SMS body templates for each event type.
	 *
	 * Delegates to Notification_Event_Registry.
	 *
	 * @param string $event_type The event key.
	 * @return string Default SMS body, or empty string for admin-only events.
	 */
	public function get_default_sms_body( string $event_type ): string {
		return $this->event_registry->get_default_sms_body( $event_type );
	}

	/**
	 * Determines the email recipients for a given event.
	 * @param string $event_type The event key.
	 * @param int    $object_id  The object ID.
	 * @param array  $data       Additional data.
	 * @return array An array of recipient arrays, each with 'type' and 'email'.
	 */
	private function get_recipients_for_event( $event_type, $object_id, $data ) {
		$recipients        = [];
		$event_definitions = $this->get_event_types();
		if ( ! isset( $event_definitions[ $event_type ] ) ) {
			return [];
		}

		$recipient_types = $event_definitions[ $event_type ]['recipient_types'];

		if ( in_array( 'admin', $recipient_types, true ) ) {
			// *** REFACTORED ***: Updated option name
			$admin_emails = get_option( Constants::OPTION_NOTIFICATION_ADMINS, '' ) ?: get_option( 'admin_email' );
			$emails_array = array_map( 'trim', explode( ',', $admin_emails ) );
			foreach ( $emails_array as $email ) {
				if ( is_email( $email ) ) {
					$recipients[] = [ 'type' => 'admin', 'email' => $email ];
				}
			}
		}

		if ( in_array( 'client', $recipient_types, true ) ) {
			$client_email = $this->get_client_email_for_event( $event_type, $object_id, $data );
			if ( is_email( $client_email ) ) {
				$recipients[] = [ 'type' => 'client', 'email' => $client_email ];
			}
		}
		return $recipients;
	}

	/**
	 * Helper to get the client's email based on the event context.
	 * @param string $event_type The event key.
	 * @param int    $object_id  The object ID.
	 * @return string|null The client's email or null.
	 */
	private function get_client_email_for_event( $event_type, $object_id, $data = [] ) {
		$user_id = null;
		switch ( $event_type ) {
			case 'new_appointment_client':
			case 'new_appointment_admin':
			case 'appointment_updated_admin':
			case 'appointment_cancelled_admin':
			case 'appointment_cancelled_by_client': // ** NEW **
			case 'appointment_reminder':
			case 'payment_successful_client':
			case 'payment_successful_admin':
			case 'scheduled_payment_failure_client':
			case 'scheduled_payment_failure_admin':
			case 'waitlist_joined':
			case 'waitlist_promoted':
				$appointment = get_post( $object_id );
				// *** REFACTORED ***: Updated CPT name
				if ( $appointment && Constants::POST_TYPE_APPOINTMENT === $appointment->post_type ) {
					$user_id = $appointment->post_author;
				}
				break;
			case 'new_client_registration_client':
			case 'new_client_registration_admin':
				$user_id = $object_id;
				break;
		}
		if ( $user_id ) {
			$user_data = get_userdata( $user_id );
			return $user_data ? $user_data->user_email : null;
		}
		return null;
	}

} // End of class
