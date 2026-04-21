<?php
/**
 * File: src/pro/includes/modules/messaging/class-timeline-event-bridge.php
 * Hooks into existing WordPress/plugin actions and writes timeline entries.
 *
 * This bridge populates the unified client timeline by listening to:
 * - Appointment status transitions (created, cancelled, completed)
 * - User login/logout events
 * - Notification sends (email/SMS)
 * - Appointment note updates
 * - New message creation (internal)
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Timeline_Event_Bridge {

	/** @var Timeline_Repository */
	private $timeline_repo;

	public function __construct( Timeline_Repository $timeline_repo ) {
		$this->timeline_repo = $timeline_repo;
	}

	/**
	 * Register all hooks that feed into the timeline.
	 */
	public function register_hooks(): void {
		// Appointment status changes.
		add_action( 'transition_post_status', [ $this, 'on_appointment_status_change' ], 15, 3 );

		// User login / logout.
		add_action( 'wp_login', [ $this, 'on_user_login' ], 10, 2 );
		add_action( 'wp_logout', [ $this, 'on_user_logout' ], 10, 1 );

		// Notification sent (email/SMS) — requires the 1-line hook addition.
		add_action( 'clisyc_notification_sent', [ $this, 'on_notification_sent' ], 10, 3 );

		// Appointment note/content update.
		add_action( 'save_post_' . Constants::POST_TYPE_APPOINTMENT, [ $this, 'on_appointment_note_update' ], 25, 3 );

		// New message creation (internal).
		add_action( 'clisyc_message_created', [ $this, 'on_message_created' ], 10, 2 );
	}

	// ── Event Handlers ────────────────────────────────────────────

	/**
	 * Handle appointment status transitions.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function on_appointment_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			return;
		}

		if ( $new_status === $old_status ) {
			return;
		}

		$client_id = $this->get_appointment_client_id( $post->ID );
		if ( ! $client_id ) {
			return;
		}

		$actor_id = get_current_user_id() ?: 0;

		// Map status transitions to timeline event types.
		$event_type = null;
		$summary    = '';

		if ( 'auto-draft' === $old_status || 'new' === $old_status ) {
			$event_type = 'appointment_created';
			$summary    = sprintf(
				/* translators: %s: appointment title */
				__( 'Appointment created: %s', 'client-sync-pro' ),
				get_the_title( $post->ID )
			);
		} elseif ( Constants::STATUS_CANCELLED === $new_status ) {
			$event_type = 'appointment_cancelled';
			$summary    = sprintf(
				/* translators: %s: appointment title */
				__( 'Appointment cancelled: %s', 'client-sync-pro' ),
				get_the_title( $post->ID )
			);
		} elseif ( Constants::STATUS_COMPLETED === $new_status || 'draft' === $new_status ) {
			$event_type = 'appointment_completed';
			$summary    = sprintf(
				/* translators: %s: appointment title */
				__( 'Appointment completed: %s', 'client-sync-pro' ),
				get_the_title( $post->ID )
			);
		}

		if ( $event_type ) {
			$this->timeline_repo->insert_event(
				$client_id,
				$event_type,
				$post->ID,
				$actor_id,
				$summary
			);
		}
	}

	/**
	 * Handle user login event.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User object.
	 */
	public function on_user_login( string $user_login, \WP_User $user ): void {
		// Only track non-admin users (clients).
		if ( user_can( $user->ID, 'edit_others_posts' ) ) {
			return;
		}

		$this->timeline_repo->insert_event(
			$user->ID,
			'login',
			$user->ID,
			$user->ID,
			sprintf(
				/* translators: %s: user display name */
				__( '%s logged in', 'client-sync-pro' ),
				$user->display_name
			)
		);
	}

	/**
	 * Handle user logout event.
	 *
	 * @param int $user_id User ID being logged out.
	 */
	public function on_user_logout( int $user_id ): void {
		// Only track non-admin users (clients).
		if ( user_can( $user_id, 'edit_others_posts' ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		// Use a time-based source_id to avoid dedup conflict with login.
		$this->timeline_repo->insert_event(
			$user_id,
			'logout',
			$user_id,
			$user_id,
			sprintf(
				/* translators: %s: user display name */
				__( '%s logged out', 'client-sync-pro' ),
				$user->display_name
			)
		);
	}

	/**
	 * Handle notification sent event (email/SMS).
	 *
	 * @param string $event_type   Notification event type.
	 * @param int    $object_id    The primary object ID.
	 * @param array  $data         Additional notification data.
	 */
	public function on_notification_sent( string $event_type, int $object_id, array $data ): void {
		// Determine the client ID from the notification context.
		$client_id = $this->resolve_client_id_from_notification( $event_type, $object_id, $data );
		if ( ! $client_id ) {
			return;
		}

		// Determine whether this was email or SMS based on channel info.
		$channels      = $data['channels_sent'] ?? [];
		$timeline_type = in_array( 'sms', $channels, true ) ? 'sms_sent' : 'email_sent';

		$summary = sprintf(
			/* translators: 1: notification type label, 2: event type */
			__( '%1$s notification sent (%2$s)', 'client-sync-pro' ),
			'sms_sent' === $timeline_type ? 'SMS' : 'Email',
			str_replace( '_', ' ', $event_type )
		);

		$this->timeline_repo->insert_event(
			$client_id,
			$timeline_type,
			$object_id,
			get_current_user_id() ?: 0,
			$summary
		);
	}

	/**
	 * Handle appointment note/content updates.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update (not new).
	 */
	public function on_appointment_note_update( int $post_id, \WP_Post $post, bool $update ): void {
		if ( ! $update ) {
			return;
		}

		if ( Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			return;
		}

		// Only track if the content/notes actually changed.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$client_id = $this->get_appointment_client_id( $post_id );
		if ( ! $client_id ) {
			return;
		}

		// Check if post_content was modified.
		$previous_content = get_post_meta( $post_id, '_clisyc_previous_content', true );
		$current_content  = $post->post_content;

		if ( $previous_content === $current_content ) {
			return; // No actual note change.
		}

		// Store current content for next comparison.
		update_post_meta( $post_id, '_clisyc_previous_content', $current_content );

		$this->timeline_repo->insert_event(
			$client_id,
			'note_updated',
			$post_id,
			get_current_user_id() ?: 0,
			sprintf(
				/* translators: %s: appointment title */
				__( 'Notes updated for: %s', 'client-sync-pro' ),
				get_the_title( $post_id )
			)
		);
	}

	/**
	 * Handle new message creation.
	 *
	 * @param int   $message_id   New message ID.
	 * @param array $message_data Message data (thread_id, sender_id, recipient_id).
	 */
	public function on_message_created( int $message_id, array $message_data ): void {
		$thread_id    = $message_data['thread_id'] ?? 0;
		$sender_id    = $message_data['sender_id'] ?? 0;
		$recipient_id = $message_data['recipient_id'] ?? 0;

		if ( ! $thread_id || ! $sender_id || ! $recipient_id ) {
			return;
		}

		// Determine client ID: whichever one is NOT an admin.
		$client_id = Messaging_Permissions::is_admin( $sender_id ) ? $recipient_id : $sender_id;

		$sender = get_userdata( $sender_id );
		$summary = sprintf(
			/* translators: %s: sender display name */
			__( 'Message from %s', 'client-sync-pro' ),
			$sender ? $sender->display_name : __( 'Unknown', 'client-sync-pro' )
		);

		$this->timeline_repo->insert_event(
			$client_id,
			'message_sent',
			$message_id,
			$sender_id,
			$summary
		);
	}

	// ── Private Helpers ───────────────────────────────────────────

	/**
	 * Get the client user ID associated with an appointment.
	 *
	 * @param int $post_id Appointment post ID.
	 * @return int Client user ID, or 0 if not found.
	 */
	private function get_appointment_client_id( int $post_id ): int {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return 0;
		}

		// Appointment author is the client.
		return (int) $post->post_author;
	}

	/**
	 * Resolve a client ID from notification context.
	 *
	 * @param string $event_type Notification event type.
	 * @param int    $object_id  Primary object ID.
	 * @param array  $data       Additional data.
	 * @return int Client user ID, or 0 if not resolvable.
	 */
	private function resolve_client_id_from_notification( string $event_type, int $object_id, array $data ): int {
		// If object is an appointment, the author is the client.
		$post = get_post( $object_id );
		if ( $post && Constants::POST_TYPE_APPOINTMENT === $post->post_type ) {
			return (int) $post->post_author;
		}

		// If object is a user (e.g., new_user event), the object IS the client.
		$user = get_userdata( $object_id );
		if ( $user && ! user_can( $user->ID, 'edit_others_posts' ) ) {
			return $user->ID;
		}

		// If data contains a client_id, use it.
		if ( ! empty( $data['client_id'] ) ) {
			return (int) $data['client_id'];
		}

		return 0;
	}
}
