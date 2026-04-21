<?php
/**
 * File: src/pro/includes/modules/messaging/class-queue-rest-controller.php
 * REST API controller for the admin message queue (shared inbox).
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Queue_Rest_Controller extends \WP_REST_Controller {

	protected $namespace = 'client-sync-pro/v1';
	protected $rest_base = 'queue';

	/** @var Message_Repository */
	private $repo;

	public function __construct( Message_Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		// GET /queue — List queue threads.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
					'args'                => [
						'status' => [
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Filter by status: awaiting_reply, open, resolved, or empty for all active.',
						],
						'orderby' => [
							'required'          => false,
							'type'              => 'string',
							'default'           => 'oldest_unanswered',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Sort order: oldest_unanswered or newest.',
						],
						'page' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						],
						'per_page' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		// PUT /queue/{id}/status — Update thread status.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/status',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_status' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'status' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'New status: open, awaiting_reply, or resolved.',
						],
					],
				],
			]
		);

		// GET /queue/counts — Get status counts for filter badges.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/counts',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_counts' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
			]
		);
	}

	/**
	 * Permission check: admin only.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error True if permitted.
	 */
	public function admin_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'client-sync-pro' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! Messaging_Permissions::is_admin( get_current_user_id() ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access the message queue.', 'client-sync-pro' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET /queue — List threads in the queue.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function get_items( $request ) {
		$status   = $request->get_param( 'status' );
		$orderby  = $request->get_param( 'orderby' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$threads = $this->repo->get_queue_threads( $status, $orderby, $page, $per_page );
		$total   = $this->repo->get_queue_thread_count( $status );

		$data = [];
		foreach ( $threads as $thread ) {
			$client = get_userdata( (int) $thread->client_id );
			$sender = ! empty( $thread->last_message_sender_id )
				? get_userdata( (int) $thread->last_message_sender_id )
				: null;

			$data[] = [
				'thread_id'                => (int) $thread->thread_id,
				'client_id'                => (int) $thread->client_id,
				'client_name'              => $client ? $client->display_name : __( 'Unknown', 'client-sync-pro' ),
				'client_avatar'            => get_avatar_url( (int) $thread->client_id, [ 'size' => 40 ] ),
				'appointment_id'           => $thread->appointment_id ? (int) $thread->appointment_id : null,
				'subject'                  => $thread->subject,
				'status'                   => $thread->status ?? Constants::THREAD_STATUS_OPEN,
				'last_message_preview'     => $thread->last_message_preview ?? '',
				'last_message_sender_id'   => $thread->last_message_sender_id ? (int) $thread->last_message_sender_id : null,
				'last_message_sender_name' => $sender ? $sender->display_name : '',
				'last_message_at'          => $thread->last_message_created_at ?? $thread->last_message_at,
				'message_count'            => (int) ( $thread->message_count ?? 0 ),
				'created_at'               => $thread->created_at,
			];
		}

		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * PUT /queue/{id}/status — Update a thread's status.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function update_status( $request ) {
		$thread_id  = (int) $request->get_param( 'id' );
		$new_status = $request->get_param( 'status' );

		// Validate thread exists.
		$thread = $this->repo->get_thread( $thread_id );
		if ( ! $thread ) {
			return new \WP_Error(
				'rest_not_found',
				__( 'Thread not found.', 'client-sync-pro' ),
				[ 'status' => 404 ]
			);
		}

		$result = $this->repo->update_thread_status( $thread_id, $new_status );

		if ( ! $result ) {
			return new \WP_Error(
				'rest_error',
				__( 'Failed to update thread status.', 'client-sync-pro' ),
				[ 'status' => 500 ]
			);
		}

		// Audit log.
		if ( function_exists( 'clisyc_audit_log' ) ) {
			clisyc_audit_log( 'thread_status_changed', 'thread', $thread_id, [
				'new_status' => $new_status,
				'changed_by' => get_current_user_id(),
			] );
		}

		return new \WP_REST_Response(
			[
				'success'   => true,
				'thread_id' => $thread_id,
				'status'    => $new_status,
			],
			200
		);
	}

	/**
	 * GET /queue/counts — Get status counts for filter badges.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function get_counts( $request ) {
		$awaiting = $this->repo->get_queue_thread_count( Constants::THREAD_STATUS_AWAITING_REPLY );
		$open     = $this->repo->get_queue_thread_count( Constants::THREAD_STATUS_OPEN );
		$resolved = $this->repo->get_queue_thread_count( Constants::THREAD_STATUS_RESOLVED );

		return new \WP_REST_Response(
			[
				'awaiting_reply' => $awaiting,
				'open'           => $open,
				'resolved'       => $resolved,
				'total_active'   => $awaiting + $open,
			],
			200
		);
	}
}
