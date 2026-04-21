<?php
/**
 * File: src/pro/includes/modules/messaging/class-threads-rest-controller.php
 * REST API controller for message thread management.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Threads_Rest_Controller extends \WP_REST_Controller {

	protected $namespace = 'client-sync-pro/v1';
	protected $rest_base = 'threads';

	/** @var Message_Repository */
	private $repo;

	public function __construct( Message_Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		// POST /threads — Create a new thread.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'client_id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => 'Client user ID.',
						],
						'subject' => [
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Thread subject.',
						],
						'appointment_id' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
							'description'       => 'Optional appointment ID.',
						],
						'body' => [
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
							'description'       => 'Optional initial message body.',
						],
					],
				],
			]
		);

		// GET /threads?client_id=X — List threads for a client.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [
						'client_id' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
							'description'       => 'Filter threads by client ID.',
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
							'default'           => 20,
							'sanitize_callback' => 'absint',
							'minimum'           => 1,
							'maximum'           => 100,
						],
					],
				],
			]
		);

		// GET /threads/{id} — Get a single thread with recent messages.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => 'Thread ID.',
						],
					],
				],
			]
		);
	}

	// ── Permission Callbacks ──────────────────────────────────────

	/**
	 * Permission check for creating a thread.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must be logged in.', 'client-sync-pro' ),
				[ 'status' => 401 ]
			);
		}

		$user_id = get_current_user_id();

		// Must have initiate permission.
		if ( ! Messaging_Permissions::can_initiate( $user_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to start new conversations.', 'client-sync-pro' ),
				[ 'status' => 403 ]
			);
		}

		// Rate limit.
		if ( class_exists( '\DependentMedia\ClientSync\Utility\Security_Helper' ) ) {
			$rate_check = \DependentMedia\ClientSync\Utility\Security_Helper::check_rate_limit(
				'thread_create_' . $user_id,
				5,
				300
			);
			if ( is_wp_error( $rate_check ) ) {
				return $rate_check;
			}
		}

		return true;
	}

	/**
	 * Permission check for listing threads.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must be logged in.', 'client-sync-pro' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Permission check for viewing a single thread.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must be logged in.', 'client-sync-pro' ),
				[ 'status' => 401 ]
			);
		}

		$thread_id = absint( $request->get_param( 'id' ) );
		if ( ! Messaging_Permissions::can_access_thread( get_current_user_id(), $thread_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have access to this thread.', 'client-sync-pro' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	// ── Callbacks ─────────────────────────────────────────────────

	/**
	 * Create a new thread, optionally with an initial message.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$user_id        = get_current_user_id();
		$client_id      = absint( $request->get_param( 'client_id' ) );
		$subject        = sanitize_text_field( $request->get_param( 'subject' ) );
		$appointment_id = absint( $request->get_param( 'appointment_id' ) );
		$body           = sanitize_textarea_field( $request->get_param( 'body' ) );

		// Validate client exists.
		$client = get_userdata( $client_id );
		if ( ! $client ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Invalid client ID.', 'client-sync-pro' ),
				[ 'status' => 400 ]
			);
		}

		// If appointment-scoped, check if a thread already exists.
		if ( $appointment_id > 0 ) {
			$existing = $this->repo->get_thread_for_appointment( $appointment_id );
			if ( $existing ) {
				return new \WP_REST_Response(
					[
						'success'   => true,
						'thread_id' => (int) $existing->thread_id,
						'existing'  => true,
					],
					200
				);
			}
		}

		$thread_id = $this->repo->create_thread(
			$client_id,
			$appointment_id > 0 ? $appointment_id : null,
			$subject
		);

		if ( false === $thread_id ) {
			return new \WP_Error(
				'rest_error',
				__( 'Failed to create thread. Encryption may not be configured.', 'client-sync-pro' ),
				[ 'status' => 500 ]
			);
		}

		// If initial body provided, create the first message.
		$message_id = null;
		if ( ! empty( $body ) ) {
			$message_id = $this->repo->create_message( $thread_id, $user_id, $client_id, $body );
		}

		// Audit log.
		if ( function_exists( 'clisyc_audit_log' ) ) {
			clisyc_audit_log( 'thread_created', 'thread', $thread_id, [
				'client_id'      => $client_id,
				'appointment_id' => $appointment_id,
				'creator_id'     => $user_id,
			] );
		}

		return new \WP_REST_Response(
			[
				'success'    => true,
				'thread_id'  => $thread_id,
				'message_id' => $message_id,
				'existing'   => false,
			],
			201
		);
	}

	/**
	 * List threads. Admins see all (optionally filtered by client_id).
	 * Clients see only their own.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$user_id   = get_current_user_id();
		$client_id = absint( $request->get_param( 'client_id' ) );
		$page      = absint( $request->get_param( 'page' ) ) ?: 1;
		$per_page  = absint( $request->get_param( 'per_page' ) ) ?: 20;
		$is_admin  = Messaging_Permissions::is_admin( $user_id );

		if ( $client_id > 0 ) {
			// If non-admin tries to view another client's threads, restrict.
			if ( ! $is_admin && $client_id !== $user_id ) {
				$client_id = $user_id;
			}
			$threads = $this->repo->get_threads_for_client( $client_id, $page, $per_page );
			$total   = $this->repo->get_thread_count_for_client( $client_id );
		} else {
			$threads = $this->repo->get_threads_for_user( $user_id, $is_admin, $page, $per_page );
			$total   = $is_admin
				? $this->get_total_thread_count()
				: $this->repo->get_thread_count_for_client( $user_id );
		}

		$data = [];
		foreach ( $threads as $thread ) {
			$client = get_userdata( (int) $thread->client_id );

			$data[] = [
				'thread_id'       => (int) $thread->thread_id,
				'client_id'       => (int) $thread->client_id,
				'client_name'     => $client ? $client->display_name : __( 'Unknown', 'client-sync-pro' ),
				'client_avatar'   => get_avatar_url( (int) $thread->client_id, [ 'size' => 40 ] ),
				'appointment_id'  => $thread->appointment_id ? (int) $thread->appointment_id : null,
				'subject'         => $thread->subject,
				'last_message_at' => $thread->last_message_at,
				'created_at'      => $thread->created_at,
				'unread_count'    => $this->get_thread_unread_count( (int) $thread->thread_id, $user_id ),
				'message_count'   => $this->repo->get_message_count( (int) $thread->thread_id ),
			];
		}

		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Get a single thread with its recent messages.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$thread_id = absint( $request->get_param( 'id' ) );
		$user_id   = get_current_user_id();

		$thread = $this->repo->get_thread( $thread_id );
		if ( ! $thread ) {
			return new \WP_Error(
				'rest_not_found',
				__( 'Thread not found.', 'client-sync-pro' ),
				[ 'status' => 404 ]
			);
		}

		$client   = get_userdata( (int) $thread->client_id );
		$messages = $this->repo->get_messages_for_thread( $thread_id, 1, 50 );

		// Mark as read.
		$this->repo->mark_thread_as_read( $thread_id, $user_id );

		$message_data = [];
		foreach ( $messages as $msg ) {
			$sender      = get_userdata( (int) $msg->sender_id );
			$attachments = $this->repo->get_attachments_for_message( (int) $msg->message_id );

			$message_data[] = [
				'message_id'    => (int) $msg->message_id,
				'sender_id'     => (int) $msg->sender_id,
				'sender_name'   => $sender ? $sender->display_name : __( 'Unknown', 'client-sync-pro' ),
				'sender_avatar' => get_avatar_url( (int) $msg->sender_id, [ 'size' => 40 ] ),
				'recipient_id'  => (int) $msg->recipient_id,
				'body'          => $msg->body,
				'is_read'       => (bool) $msg->is_read,
				'created_at'    => $msg->created_at,
				'attachments'   => array_map( function ( $att ) {
					return [
						'attachment_id' => (int) $att->attachment_id,
						'file_name'     => $att->file_name,
						'file_type'     => $att->file_type,
						'file_size'     => (int) $att->file_size,
					];
				}, $attachments ),
			];
		}

		return new \WP_REST_Response(
			[
				'thread_id'       => (int) $thread->thread_id,
				'client_id'       => (int) $thread->client_id,
				'client_name'     => $client ? $client->display_name : __( 'Unknown', 'client-sync-pro' ),
				'appointment_id'  => $thread->appointment_id ? (int) $thread->appointment_id : null,
				'subject'         => $thread->subject,
				'last_message_at' => $thread->last_message_at,
				'created_at'      => $thread->created_at,
				'messages'        => $message_data,
			],
			200
		);
	}

	// ── Private Helpers ───────────────────────────────────────────

	/**
	 * Get total thread count across all clients (for admin pagination).
	 *
	 * @return int Total count.
	 */
	private function get_total_thread_count(): int {
		global $wpdb;
		$schema = new Messaging_Schema();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$schema->get_threads_table()}"
		);
	}

	/**
	 * Get unread count for a specific thread and user.
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $user_id   User ID.
	 * @return int Unread count.
	 */
	private function get_thread_unread_count( int $thread_id, int $user_id ): int {
		global $wpdb;
		$schema = new Messaging_Schema();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$schema->get_messages_table()}
				 WHERE thread_id = %d AND recipient_id = %d AND is_read = 0",
				$thread_id,
				$user_id
			)
		);
	}
}
