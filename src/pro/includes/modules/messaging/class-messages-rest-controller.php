<?php
/**
 * File: src/pro/includes/modules/messaging/class-messages-rest-controller.php
 * REST API controller for message CRUD operations.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Messages_Rest_Controller extends \WP_REST_Controller {

	protected $namespace = 'client-sync-pro/v1';
	protected $rest_base = 'messages';

	/** @var Message_Repository */
	private $repo;

	/** @var Messaging_Schema */
	private $schema_handler;

	public function __construct( Message_Repository $repo, Messaging_Schema $schema ) {
		$this->repo           = $repo;
		$this->schema_handler = $schema;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		// POST /messages — Send a message.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'thread_id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => 'Thread ID to post the message in.',
						],
						'body' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'description'       => 'Message body text.',
						],
					],
				],
			]
		);

		// GET /messages?thread_id=X — List messages in a thread.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [
						'thread_id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => 'Thread ID to list messages for.',
						],
						'page' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
							'minimum'           => 1,
						],
						'per_page' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 50,
							'sanitize_callback' => 'absint',
							'minimum'           => 1,
							'maximum'           => 100,
						],
					],
				],
			]
		);

		// PUT /messages/{id}/read — Mark a message as read.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/read',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'mark_read' ],
					'permission_callback' => [ $this, 'mark_read_permissions_check' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => 'Message ID to mark as read.',
						],
					],
				],
			]
		);
	}

	// ── Permission Callbacks ──────────────────────────────────────

	/**
	 * Check permission to send a message: user must be logged in,
	 * have reply permission, and can access the target thread.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to send messages.', 'client-sync-pro' ),
				[ 'status' => 401 ]
			);
		}

		$user_id = get_current_user_id();

		// Rate limit.
		if ( class_exists( '\DependentMedia\ClientSync\Utility\Security_Helper' ) ) {
			$rate_check = \DependentMedia\ClientSync\Utility\Security_Helper::check_rate_limit(
				'message_send_' . $user_id,
				Constants::RATE_LIMIT_MESSAGE_SEND,
				Constants::RATE_LIMIT_MESSAGE_WINDOW_SECS
			);
			if ( is_wp_error( $rate_check ) ) {
				return $rate_check;
			}
		}

		// Must have reply permission.
		if ( ! Messaging_Permissions::can_reply( $user_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to send messages.', 'client-sync-pro' ),
				[ 'status' => 403 ]
			);
		}

		// Must have access to the thread.
		$thread_id = absint( $request->get_param( 'thread_id' ) );
		if ( ! Messaging_Permissions::can_access_thread( $user_id, $thread_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have access to this thread.', 'client-sync-pro' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Check permission to read messages in a thread.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to view messages.', 'client-sync-pro' ),
				[ 'status' => 401 ]
			);
		}

		$thread_id = absint( $request->get_param( 'thread_id' ) );
		if ( ! Messaging_Permissions::can_access_thread( get_current_user_id(), $thread_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have access to this thread.', 'client-sync-pro' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Check permission to mark a message as read.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool|\WP_Error
	 */
	public function mark_read_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must be logged in.', 'client-sync-pro' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	// ── Callbacks ─────────────────────────────────────────────────

	/**
	 * Send a new message.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$user_id   = get_current_user_id();
		$thread_id = absint( $request->get_param( 'thread_id' ) );
		$body      = sanitize_textarea_field( $request->get_param( 'body' ) );

		if ( empty( $body ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Message body cannot be empty.', 'client-sync-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Determine recipient: if sender is admin, recipient is client; vice versa.
		$thread = $this->repo->get_thread( $thread_id );
		if ( ! $thread ) {
			return new \WP_Error(
				'rest_not_found',
				__( 'Thread not found.', 'client-sync-pro' ),
				[ 'status' => 404 ]
			);
		}

		$is_admin     = Messaging_Permissions::is_admin( $user_id );
		$recipient_id = $is_admin ? (int) $thread->client_id : $this->get_admin_recipient( $thread_id );

		if ( ! $recipient_id ) {
			return new \WP_Error(
				'rest_error',
				__( 'Could not determine message recipient.', 'client-sync-pro' ),
				[ 'status' => 500 ]
			);
		}

		$message_id = $this->repo->create_message( $thread_id, $user_id, $recipient_id, $body );

		if ( false === $message_id ) {
			return new \WP_Error(
				'rest_error',
				__( 'Failed to send message. Encryption may not be configured.', 'client-sync-pro' ),
				[ 'status' => 500 ]
			);
		}

		// Audit log.
		if ( function_exists( 'clisyc_audit_log' ) ) {
			clisyc_audit_log( 'message_sent', 'message', $message_id, [
				'thread_id'    => $thread_id,
				'sender_id'    => $user_id,
				'recipient_id' => $recipient_id,
			] );
		}

		// Trigger notification.
		$this->send_notification( $user_id, $recipient_id, $thread, $body );

		return new \WP_REST_Response(
			[
				'success'    => true,
				'message_id' => $message_id,
				'created_at' => current_time( 'mysql', true ),
			],
			201
		);
	}

	/**
	 * List messages in a thread.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$thread_id = absint( $request->get_param( 'thread_id' ) );
		$page      = absint( $request->get_param( 'page' ) ) ?: 1;
		$per_page  = absint( $request->get_param( 'per_page' ) ) ?: 50;
		$user_id   = get_current_user_id();

		$messages = $this->repo->get_messages_for_thread( $thread_id, $page, $per_page );
		$total    = $this->repo->get_message_count( $thread_id );

		// Mark messages as read for the current user.
		$this->repo->mark_thread_as_read( $thread_id, $user_id );

		// Audit log thread view.
		if ( function_exists( 'clisyc_audit_log' ) ) {
			clisyc_audit_log( 'thread_viewed', 'thread', $thread_id, [
				'user_id' => $user_id,
			] );
		}

		$data = [];
		foreach ( $messages as $msg ) {
			$sender   = get_userdata( (int) $msg->sender_id );
			$attachments = $this->repo->get_attachments_for_message( (int) $msg->message_id );

			$data[] = [
				'message_id'   => (int) $msg->message_id,
				'thread_id'    => (int) $msg->thread_id,
				'sender_id'    => (int) $msg->sender_id,
				'sender_name'  => $sender ? $sender->display_name : __( 'Unknown', 'client-sync-pro' ),
				'sender_avatar' => get_avatar_url( (int) $msg->sender_id, [ 'size' => 40 ] ),
				'recipient_id' => (int) $msg->recipient_id,
				'body'         => $msg->body,
				'is_read'      => (bool) $msg->is_read,
				'created_at'   => $msg->created_at,
				'attachments'  => array_map( function ( $att ) {
					return [
						'attachment_id' => (int) $att->attachment_id,
						'file_name'     => $att->file_name,
						'file_type'     => $att->file_type,
						'file_size'     => (int) $att->file_size,
					];
				}, $attachments ),
			];
		}

		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Mark a message as read.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mark_read( $request ) {
		$message_id = absint( $request->get_param( 'id' ) );
		$user_id    = get_current_user_id();

		$result = $this->repo->mark_as_read( $message_id, $user_id );

		if ( $result && function_exists( 'clisyc_audit_log' ) ) {
			clisyc_audit_log( 'message_read', 'message', $message_id, [
				'user_id' => $user_id,
			] );
		}

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	// ── Private Helpers ───────────────────────────────────────────

	/**
	 * Get the admin/practitioner to use as recipient when a client sends a message.
	 *
	 * Looks for the last admin who sent a message in the thread. Falls back to
	 * the site admin.
	 *
	 * @param int $thread_id Thread ID.
	 * @return int Admin user ID.
	 */
	private function get_admin_recipient( int $thread_id ): int {
		global $wpdb;

		// Find last admin sender in this thread.
		$messages_table = $this->schema_handler->get_messages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$admin_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT sender_id FROM {$messages_table}
				 WHERE thread_id = %d
				 ORDER BY created_at DESC",
				$thread_id
			)
		);

		if ( $admin_id ) {
			// Check each sender — find the first admin.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$sender_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT sender_id FROM {$messages_table}
					 WHERE thread_id = %d ORDER BY created_at DESC",
					$thread_id
				)
			);

			foreach ( $sender_ids as $sid ) {
				if ( Messaging_Permissions::is_admin( (int) $sid ) ) {
					return (int) $sid;
				}
			}
		}

		// Fallback: site admin.
		$admin_email = get_option( 'admin_email' );
		$admin_user  = get_user_by( 'email', $admin_email );

		return $admin_user ? $admin_user->ID : 1;
	}

	/**
	 * Trigger email/SMS notification for a new message.
	 *
	 * @param int    $sender_id    Sender user ID.
	 * @param int    $recipient_id Recipient user ID.
	 * @param object $thread       Thread object.
	 * @param string $body         Plaintext message body.
	 */
	private function send_notification( int $sender_id, int $recipient_id, object $thread, string $body ): void {
		$sender    = get_userdata( $sender_id );
		$recipient = get_userdata( $recipient_id );

		if ( ! $sender || ! $recipient ) {
			return;
		}

		$is_to_client = ! Messaging_Permissions::is_admin( $recipient_id );
		$event_type   = $is_to_client ? 'new_message_client' : 'new_message_admin';

		// Build preview: first 100 chars of body.
		$preview = wp_trim_words( $body, 20, '…' );

		// HIPAA sanitization: replace preview with portal link text.
		if ( function_exists( 'clisyc_is_hipaa_mode' ) && clisyc_is_hipaa_mode() ) {
			$preview = __( '[See secure portal]', 'client-sync-pro' );
		}

		// Build thread URL.
		if ( $is_to_client ) {
			// Find the page containing [clisyc_inbox], fall back to appointments page.
			$inbox_page = $this->find_inbox_page_id();
			$thread_url = $inbox_page ? add_query_arg( 'thread_id', $thread->thread_id, get_permalink( $inbox_page ) ) : site_url();
		} else {
			$thread_url = admin_url( 'admin.php?page=clisyc-clients&thread_id=' . $thread->thread_id );
		}

		$placeholders = [
			'{message_sender}'     => $sender->display_name,
			'{message_preview}'    => $preview,
			'{message_thread_url}' => $thread_url,
			'{client_name}'        => $is_to_client ? $recipient->display_name : $sender->display_name,
			'{client_email}'       => $is_to_client ? $recipient->user_email : $sender->user_email,
		];

		/**
		 * Fires when a messaging notification should be sent.
		 *
		 * @param string $event_type   Notification event type.
		 * @param int    $recipient_id Recipient user ID.
		 * @param array  $placeholders Template placeholders.
		 */
		do_action( 'clisyc_send_notification', $event_type, $recipient_id, $placeholders );
	}

	/**
	 * Find the page ID that contains the [clisyc_inbox] shortcode.
	 *
	 * @return int Page ID or 0 if not found.
	 */
	private function find_inbox_page_id(): int {
		// Check transient cache first.
		$cached = get_transient( 'clisyc_inbox_page_id' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		// Search published pages for the inbox shortcode.
		global $wpdb;
		$page_id = (int) $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'page'
			   AND post_status = 'publish'
			   AND post_content LIKE '%[clisyc_inbox%'
			 LIMIT 1"
		);

		// Fall back to the appointments page.
		if ( ! $page_id ) {
			$page_id = (int) get_option( Constants::OPTION_MY_APPOINTMENTS_PAGE, 0 );
		}

		// Cache for 1 hour.
		set_transient( 'clisyc_inbox_page_id', $page_id, HOUR_IN_SECONDS );

		return $page_id;
	}
}
