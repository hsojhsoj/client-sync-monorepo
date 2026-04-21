<?php
/**
 * File: src/pro/includes/modules/messaging/class-message-repository.php
 * CRUD operations for message threads, messages, and attachments.
 * All message bodies are always encrypted (not gated by HIPAA toggle).
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Services\Encryption_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Message_Repository {

	/** @var Messaging_Schema */
	private $schema;

	/** @var Encryption_Service */
	private $encryption;

	public function __construct( Messaging_Schema $schema ) {
		$this->schema     = $schema;
		$this->encryption = Encryption_Service::get_instance();
	}

	// ── Threads ────────────────────────────────────────────────────

	/**
	 * Create a new message thread.
	 *
	 * @param int      $client_id      Client user ID.
	 * @param int|null $appointment_id Optional appointment ID (NULL for general).
	 * @param string   $subject        Thread subject (will be encrypted).
	 * @return int|false Thread ID on success, false on failure.
	 */
	public function create_thread( int $client_id, ?int $appointment_id, string $subject = '' ) {
		global $wpdb;

		$encrypted_subject = '';
		if ( '' !== $subject ) {
			$encrypted_subject = $this->encryption->encrypt( $subject );
			if ( false === $encrypted_subject ) {
				return false; // Fail-safe: refuse to store plaintext.
			}
		}

		$now    = current_time( 'mysql', true );
		$result = $wpdb->insert(
			$this->schema->get_threads_table(),
			[
				'client_id'       => $client_id,
				'appointment_id'  => $appointment_id,
				'subject'         => $encrypted_subject,
				'last_message_at' => null,
				'created_at'      => $now,
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);

		return false !== $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get a single thread by ID.
	 *
	 * @param int $thread_id Thread ID.
	 * @return object|null Thread object with decrypted subject, or null.
	 */
	public function get_thread( int $thread_id ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$thread = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->schema->get_threads_table()} WHERE thread_id = %d",
				$thread_id
			)
		);

		if ( ! $thread ) {
			return null;
		}

		$thread->subject = $this->decrypt_value( $thread->subject );

		return $thread;
	}

	/**
	 * Get threads for a specific client, ordered by most recent message.
	 *
	 * @param int $client_id Client user ID.
	 * @param int $page      Page number (1-based).
	 * @param int $per_page  Results per page.
	 * @return array Array of thread objects with decrypted subjects.
	 */
	public function get_threads_for_client( int $client_id, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$threads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->schema->get_threads_table()}
				 WHERE client_id = %d
				 ORDER BY COALESCE(last_message_at, created_at) DESC
				 LIMIT %d OFFSET %d",
				$client_id,
				$per_page,
				$offset
			)
		);

		foreach ( $threads as $thread ) {
			$thread->subject = $this->decrypt_value( $thread->subject );
		}

		return $threads;
	}

	/**
	 * Get threads visible to a specific user (admin sees all, client sees own).
	 *
	 * @param int  $user_id  User ID.
	 * @param bool $is_admin Whether the user is an admin.
	 * @param int  $page     Page number.
	 * @param int  $per_page Results per page.
	 * @return array Array of thread objects.
	 */
	public function get_threads_for_user( int $user_id, bool $is_admin = false, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		if ( $is_admin ) {
			// Admin sees all threads.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$threads = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->schema->get_threads_table()}
					 ORDER BY COALESCE(last_message_at, created_at) DESC
					 LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
		} else {
			// Client sees only their own threads.
			$threads = $this->get_threads_for_client( $user_id, $page, $per_page );
			return $threads; // Already decrypted.
		}

		foreach ( $threads as $thread ) {
			$thread->subject = $this->decrypt_value( $thread->subject );
		}

		return $threads;
	}

	/**
	 * Get thread count for a client.
	 *
	 * @param int $client_id Client user ID.
	 * @return int Thread count.
	 */
	public function get_thread_count_for_client( int $client_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->schema->get_threads_table()} WHERE client_id = %d",
				$client_id
			)
		);
	}

	/**
	 * Get thread for a specific appointment (if one exists).
	 *
	 * @param int $appointment_id Appointment post ID.
	 * @return object|null Thread object or null.
	 */
	public function get_thread_for_appointment( int $appointment_id ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$thread = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->schema->get_threads_table()} WHERE appointment_id = %d",
				$appointment_id
			)
		);

		if ( $thread ) {
			$thread->subject = $this->decrypt_value( $thread->subject );
		}

		return $thread;
	}

	// ── Messages ───────────────────────────────────────────────────

	/**
	 * Create a new message. Body is always encrypted.
	 *
	 * @param int    $thread_id    Thread ID.
	 * @param int    $sender_id    Sender user ID.
	 * @param int    $recipient_id Recipient user ID.
	 * @param string $body         Message body (plaintext — will be encrypted).
	 * @return int|false Message ID on success, false on failure.
	 */
	public function create_message( int $thread_id, int $sender_id, int $recipient_id, string $body ) {
		global $wpdb;

		// Always encrypt — messaging is inherently PHI.
		if ( ! $this->encryption->is_available() ) {
			return false; // Fail-safe: encryption key not configured.
		}

		$encrypted_body = $this->encryption->encrypt( $body );
		if ( false === $encrypted_body ) {
			return false; // Fail-safe: refuse to store plaintext.
		}

		$now    = current_time( 'mysql', true );
		$result = $wpdb->insert(
			$this->schema->get_messages_table(),
			[
				'thread_id'    => $thread_id,
				'sender_id'    => $sender_id,
				'recipient_id' => $recipient_id,
				'body'         => $encrypted_body,
				'is_read'      => 0,
				'created_at'   => $now,
			],
			[ '%d', '%d', '%d', '%s', '%d', '%s' ]
		);

		if ( false === $result ) {
			return false;
		}

		$message_id = (int) $wpdb->insert_id;

		// Update thread's last_message_at and auto-set status.
		$is_sender_admin = Messaging_Permissions::is_admin( $sender_id );
		$thread_update   = [ 'last_message_at' => $now ];

		if ( $is_sender_admin ) {
			// Admin replied — set to 'open' unless thread was explicitly resolved.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$current_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM {$this->schema->get_threads_table()} WHERE thread_id = %d",
					$thread_id
				)
			);
			if ( Constants::THREAD_STATUS_RESOLVED !== $current_status ) {
				$thread_update['status'] = Constants::THREAD_STATUS_OPEN;
			}
		} else {
			// Client sent a message — mark as awaiting admin reply.
			$thread_update['status'] = Constants::THREAD_STATUS_AWAITING_REPLY;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->schema->get_threads_table(),
			$thread_update,
			[ 'thread_id' => $thread_id ]
		);

		// Invalidate the queue count transient.
		delete_transient( 'clisyc_queue_awaiting_count' );

		/**
		 * Fires after a message is created successfully.
		 *
		 * @param int   $message_id   The new message ID.
		 * @param array $message_data Message details for event bridge.
		 */
		do_action( 'clisyc_message_created', $message_id, [
			'thread_id'    => $thread_id,
			'sender_id'    => $sender_id,
			'recipient_id' => $recipient_id,
		] );

		return $message_id;
	}

	/**
	 * Get messages for a thread, ordered chronologically.
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $page      Page number (1-based).
	 * @param int $per_page  Results per page.
	 * @return array Array of message objects with decrypted bodies.
	 */
	public function get_messages_for_thread( int $thread_id, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->schema->get_messages_table()}
				 WHERE thread_id = %d
				 ORDER BY created_at ASC
				 LIMIT %d OFFSET %d",
				$thread_id,
				$per_page,
				$offset
			)
		);

		foreach ( $messages as $message ) {
			$message->body = $this->encryption->decrypt( $message->body );
		}

		return $messages;
	}

	/**
	 * Mark a single message as read.
	 *
	 * @param int $message_id Message ID.
	 * @param int $user_id    The user marking the message as read (must be the recipient).
	 * @return bool True if updated, false otherwise.
	 */
	public function mark_as_read( int $message_id, int $user_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->schema->get_messages_table(),
			[ 'is_read' => 1 ],
			[
				'message_id'   => $message_id,
				'recipient_id' => $user_id,
				'is_read'      => 0,
			],
			[ '%d' ],
			[ '%d', '%d', '%d' ]
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Mark all messages in a thread as read for a user.
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $user_id   Recipient user ID.
	 * @return int Number of messages marked as read.
	 */
	public function mark_thread_as_read( int $thread_id, int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->schema->get_messages_table()}
				 SET is_read = 1
				 WHERE thread_id = %d AND recipient_id = %d AND is_read = 0",
				$thread_id,
				$user_id
			)
		);

		return (int) $updated;
	}

	/**
	 * Get unread message count for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int Unread count.
	 */
	public function get_unread_count( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->schema->get_messages_table()}
				 WHERE recipient_id = %d AND is_read = 0",
				$user_id
			)
		);
	}

	/**
	 * Get the most recent messages across all threads (for dashboard widget).
	 *
	 * @param int $limit Number of messages to return.
	 * @return array Array of message objects with decrypted bodies and thread/user info.
	 */
	public function get_recent_messages( int $limit = 5 ): array {
		global $wpdb;

		$messages_table = $this->schema->get_messages_table();
		$threads_table  = $this->schema->get_threads_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, t.client_id, t.appointment_id, t.subject AS thread_subject
				 FROM {$messages_table} m
				 JOIN {$threads_table} t ON m.thread_id = t.thread_id
				 ORDER BY m.created_at DESC
				 LIMIT %d",
				$limit
			)
		);

		foreach ( $messages as $message ) {
			$message->body           = $this->encryption->decrypt( $message->body );
			$message->thread_subject = $this->decrypt_value( $message->thread_subject );
		}

		return $messages;
	}

	/**
	 * Get message count for a thread.
	 *
	 * @param int $thread_id Thread ID.
	 * @return int Message count.
	 */
	public function get_message_count( int $thread_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->schema->get_messages_table()} WHERE thread_id = %d",
				$thread_id
			)
		);
	}

	// ── Attachments ────────────────────────────────────────────────

	/**
	 * Create an attachment record.
	 *
	 * @param int    $message_id Message ID.
	 * @param string $file_name  Original filename (will be encrypted).
	 * @param string $file_path  Path to encrypted file on disk.
	 * @param string $file_type  MIME type.
	 * @param int    $file_size  Original file size in bytes.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	public function create_attachment( int $message_id, string $file_name, string $file_path, string $file_type, int $file_size ) {
		global $wpdb;

		// Encrypt the original filename.
		$encrypted_name = $this->encryption->encrypt( $file_name );
		if ( false === $encrypted_name ) {
			return false;
		}

		$result = $wpdb->insert(
			$this->schema->get_attachments_table(),
			[
				'message_id' => $message_id,
				'file_name'  => $encrypted_name,
				'file_path'  => $file_path,
				'file_type'  => $file_type,
				'file_size'  => $file_size,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%d', '%s' ]
		);

		return false !== $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get attachments for a message.
	 *
	 * @param int $message_id Message ID.
	 * @return array Array of attachment objects with decrypted filenames.
	 */
	public function get_attachments_for_message( int $message_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->schema->get_attachments_table()} WHERE message_id = %d",
				$message_id
			)
		);

		foreach ( $attachments as $att ) {
			$att->file_name = $this->encryption->decrypt( $att->file_name );
		}

		return $attachments;
	}

	/**
	 * Get a single attachment by ID.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return object|null Attachment object with decrypted filename, or null.
	 */
	public function get_attachment( int $attachment_id ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$att = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->schema->get_attachments_table()} WHERE attachment_id = %d",
				$attachment_id
			)
		);

		if ( $att ) {
			$att->file_name = $this->encryption->decrypt( $att->file_name );
		}

		return $att;
	}

	/**
	 * Delete attachment record and its encrypted file from disk.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_attachment( int $attachment_id ): bool {
		global $wpdb;

		$att = $this->get_attachment( $attachment_id );
		if ( ! $att ) {
			return false;
		}

		// Delete the encrypted file from disk.
		if ( ! empty( $att->file_path ) && file_exists( $att->file_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $att->file_path );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete(
			$this->schema->get_attachments_table(),
			[ 'attachment_id' => $attachment_id ],
			[ '%d' ]
		);
	}

	// ── Queue (Admin Shared Inbox) ─────────────────────────────────

	/**
	 * Get threads for the message queue, joined with the latest message.
	 *
	 * @param string $status   Filter: 'awaiting_reply', 'open', 'resolved', or '' for all active.
	 * @param string $orderby  'oldest_unanswered' or 'newest'.
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Results per page.
	 * @return array Array of thread objects with decrypted subjects and last message preview.
	 */
	public function get_queue_threads(
		string $status = '',
		string $orderby = 'oldest_unanswered',
		int $page = 1,
		int $per_page = 20
	): array {
		global $wpdb;

		$threads_table  = $this->schema->get_threads_table();
		$messages_table = $this->schema->get_messages_table();
		$offset         = ( $page - 1 ) * $per_page;

		// Build status clause.
		$allowed_statuses = [
			Constants::THREAD_STATUS_OPEN,
			Constants::THREAD_STATUS_AWAITING_REPLY,
			Constants::THREAD_STATUS_RESOLVED,
		];

		if ( '' !== $status && in_array( $status, $allowed_statuses, true ) ) {
			$status_clause = $wpdb->prepare( 'AND t.status = %s', $status );
		} else {
			// Default: show all active (non-resolved).
			$status_clause = $wpdb->prepare(
				'AND t.status IN (%s, %s)',
				Constants::THREAD_STATUS_OPEN,
				Constants::THREAD_STATUS_AWAITING_REPLY
			);
		}

		// Build order clause.
		if ( 'newest' === $orderby ) {
			$order_clause = 'COALESCE(t.last_message_at, t.created_at) DESC';
		} else {
			// Oldest unanswered: awaiting_reply first, then by time ascending.
			$order_clause = "FIELD(t.status, 'awaiting_reply', 'open', 'resolved'), COALESCE(t.last_message_at, t.created_at) ASC";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$threads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*,
						m.body         AS last_message_body,
						m.sender_id    AS last_message_sender_id,
						m.created_at   AS last_message_created_at,
						(SELECT COUNT(*) FROM {$messages_table} WHERE thread_id = t.thread_id) AS message_count
				 FROM {$threads_table} t
				 LEFT JOIN {$messages_table} m ON m.message_id = (
					 SELECT message_id FROM {$messages_table}
					 WHERE thread_id = t.thread_id
					 ORDER BY created_at DESC
					 LIMIT 1
				 )
				 WHERE 1=1 {$status_clause}
				 ORDER BY {$order_clause}
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		// Decrypt subjects and message previews.
		foreach ( $threads as $thread ) {
			$thread->subject = $this->decrypt_value( $thread->subject );

			if ( ! empty( $thread->last_message_body ) ) {
				$decrypted = $this->encryption->decrypt( $thread->last_message_body );
				$thread->last_message_preview = $decrypted ? wp_trim_words( $decrypted, 15, '...' ) : '';
			} else {
				$thread->last_message_preview = '';
			}
			unset( $thread->last_message_body ); // Don't expose full encrypted body.
		}

		return $threads;
	}

	/**
	 * Get total thread count for the queue with a given status filter.
	 *
	 * @param string $status Status filter (empty = all active).
	 * @return int Count.
	 */
	public function get_queue_thread_count( string $status = '' ): int {
		global $wpdb;

		$threads_table = $this->schema->get_threads_table();

		$allowed_statuses = [
			Constants::THREAD_STATUS_OPEN,
			Constants::THREAD_STATUS_AWAITING_REPLY,
			Constants::THREAD_STATUS_RESOLVED,
		];

		if ( '' !== $status && in_array( $status, $allowed_statuses, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$threads_table} WHERE status = %s",
					$status
				)
			);
		}

		// Default: all active (non-resolved).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$threads_table} WHERE status IN (%s, %s)",
				Constants::THREAD_STATUS_OPEN,
				Constants::THREAD_STATUS_AWAITING_REPLY
			)
		);
	}

	/**
	 * Update a thread's status.
	 *
	 * @param int    $thread_id Thread ID.
	 * @param string $status    New status (open, awaiting_reply, resolved).
	 * @return bool True on success.
	 */
	public function update_thread_status( int $thread_id, string $status ): bool {
		global $wpdb;

		$allowed = [
			Constants::THREAD_STATUS_OPEN,
			Constants::THREAD_STATUS_AWAITING_REPLY,
			Constants::THREAD_STATUS_RESOLVED,
		];

		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->schema->get_threads_table(),
			[ 'status' => $status ],
			[ 'thread_id' => $thread_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( false !== $result ) {
			delete_transient( 'clisyc_queue_awaiting_count' );
		}

		return false !== $result;
	}

	/**
	 * Get count of threads with status 'awaiting_reply'.
	 *
	 * @return int Count of unanswered threads.
	 */
	public function get_awaiting_reply_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->schema->get_threads_table()} WHERE status = %s",
				Constants::THREAD_STATUS_AWAITING_REPLY
			)
		);
	}

	// ── Cleanup ────────────────────────────────────────────────────

	/**
	 * Delete messages (and their attachments) older than a given number of days.
	 * Respects HIPAA minimum retention when HIPAA mode is active.
	 *
	 * @param int $retention_days Number of days to retain. 0 = keep forever.
	 * @return int Number of messages deleted.
	 */
	public function cleanup_old_messages( int $retention_days ): int {
		if ( 0 === $retention_days ) {
			return 0; // Keep forever.
		}

		// Enforce HIPAA minimum retention.
		if ( function_exists( 'clisyc_is_hipaa_mode' ) && clisyc_is_hipaa_mode() ) {
			$retention_days = max( $retention_days, \DependentMedia\ClientSync\Constants::HIPAA_AUDIT_MIN_RETENTION_DAYS );
		}

		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

		// Get messages to delete (to clean up attachments).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$old_message_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT message_id FROM {$this->schema->get_messages_table()} WHERE created_at < %s",
				$cutoff
			)
		);

		if ( empty( $old_message_ids ) ) {
			return 0;
		}

		// Delete attachments (files + records) for these messages.
		$ids_placeholder = implode( ',', array_map( 'absint', $old_message_ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attachments = $wpdb->get_results(
			"SELECT attachment_id, file_path FROM {$this->schema->get_attachments_table()}
			 WHERE message_id IN ({$ids_placeholder})"
		);

		foreach ( $attachments as $att ) {
			if ( ! empty( $att->file_path ) && file_exists( $att->file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $att->file_path );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"DELETE FROM {$this->schema->get_attachments_table()} WHERE message_id IN ({$ids_placeholder})"
		);

		// Delete the messages.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			"DELETE FROM {$this->schema->get_messages_table()} WHERE message_id IN ({$ids_placeholder})"
		);

		// Clean up empty threads.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE t FROM {$this->schema->get_threads_table()} t
			 LEFT JOIN {$this->schema->get_messages_table()} m ON t.thread_id = m.thread_id
			 WHERE m.message_id IS NULL AND t.created_at < '{$cutoff}'"
		);

		return (int) $deleted;
	}

	// ── Private Helpers ────────────────────────────────────────────

	/**
	 * Decrypt a value if it appears to be encrypted.
	 *
	 * @param string $value Possibly encrypted value.
	 * @return string Decrypted or original value.
	 */
	private function decrypt_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( $this->encryption->is_encrypted( $value ) ) {
			$decrypted = $this->encryption->decrypt( $value );
			return false !== $decrypted ? $decrypted : $value;
		}

		return $value;
	}
}
