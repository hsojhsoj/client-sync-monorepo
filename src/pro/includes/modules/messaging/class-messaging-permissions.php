<?php
/**
 * File: src/pro/includes/modules/messaging/class-messaging-permissions.php
 * Role-based permission checks for the messaging module.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Messaging_Permissions {

	/**
	 * Check if a user can initiate new message threads.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True if the user's role allows initiating.
	 */
	public static function can_initiate( int $user_id ): bool {
		$allowed_roles = get_option( Constants::OPTION_MESSAGING_ROLES_INITIATE, [ 'administrator', 'editor' ] );

		if ( ! is_array( $allowed_roles ) ) {
			$allowed_roles = [ 'administrator', 'editor' ];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return ! empty( array_intersect( $user->roles, $allowed_roles ) );
	}

	/**
	 * Check if a user can reply to existing message threads.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True if the user's role allows replying.
	 */
	public static function can_reply( int $user_id ): bool {
		$allowed_roles = get_option( Constants::OPTION_MESSAGING_ROLES_REPLY, [ 'administrator', 'editor', 'subscriber' ] );

		if ( ! is_array( $allowed_roles ) ) {
			$allowed_roles = [ 'administrator', 'editor', 'subscriber' ];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return ! empty( array_intersect( $user->roles, $allowed_roles ) );
	}

	/**
	 * Check if a user can access a specific thread.
	 *
	 * Admins (edit_others_posts) can access all threads.
	 * Clients can only access threads where they are the client.
	 *
	 * @param int $user_id   WordPress user ID.
	 * @param int $thread_id Thread ID.
	 * @return bool True if the user can access the thread.
	 */
	public static function can_access_thread( int $user_id, int $thread_id ): bool {
		// Admin can access all threads.
		if ( user_can( $user_id, 'edit_others_posts' ) ) {
			return true;
		}

		// Client can only access own threads.
		global $wpdb;
		$schema = new Messaging_Schema();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$client_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT client_id FROM {$schema->get_threads_table()} WHERE thread_id = %d",
				$thread_id
			)
		);

		return (int) $client_id === $user_id;
	}

	/**
	 * Check if a user can access a specific attachment.
	 *
	 * The user must have access to the thread that contains the message
	 * which owns the attachment.
	 *
	 * @param int $user_id       WordPress user ID.
	 * @param int $attachment_id Attachment ID.
	 * @return bool True if the user can access the attachment.
	 */
	public static function can_access_attachment( int $user_id, int $attachment_id ): bool {
		global $wpdb;
		$schema = new Messaging_Schema();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$thread_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT m.thread_id
				 FROM {$schema->get_attachments_table()} a
				 JOIN {$schema->get_messages_table()} m ON a.message_id = m.message_id
				 WHERE a.attachment_id = %d",
				$attachment_id
			)
		);

		if ( ! $thread_id ) {
			return false;
		}

		return self::can_access_thread( $user_id, (int) $thread_id );
	}

	/**
	 * Check if a user is an admin/practitioner (non-client).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True if admin/practitioner.
	 */
	public static function is_admin( int $user_id ): bool {
		return user_can( $user_id, 'edit_others_posts' );
	}
}
