<?php
/**
 * File: src/pro/includes/modules/messaging/class-timeline-rest-controller.php
 * REST API controller for the unified client timeline.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Timeline_Rest_Controller extends \WP_REST_Controller {

	protected $namespace = 'client-sync-pro/v1';
	protected $rest_base = 'timeline';

	/** @var Timeline_Repository */
	private $repo;

	public function __construct( Timeline_Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		// GET /timeline?client_id=X — Paginated timeline events.
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
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => 'Client user ID.',
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
						'filter' => [
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Filter by event type (e.g., message_sent, appointment_created).',
						],
					],
				],
			]
		);

		// GET /timeline/unread-count — Unread message badge count for current user.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/unread-count',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_unread_count' ],
					'permission_callback' => [ $this, 'get_unread_count_permissions_check' ],
				],
			]
		);

		// GET /timeline/event-types?client_id=X — Available event types for filter UI.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/event-types',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_event_types' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [
						'client_id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => 'Client user ID.',
						],
					],
				],
			]
		);
	}

	// ── Permission Callbacks ──────────────────────────────────────

	/**
	 * Check permission to view timeline: admin required for viewing any client's timeline.
	 * Clients can view their own.
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

		$user_id   = get_current_user_id();
		$client_id = absint( $request->get_param( 'client_id' ) );

		// Admin can view any client's timeline.
		if ( Messaging_Permissions::is_admin( $user_id ) ) {
			return true;
		}

		// Client can only view their own timeline.
		if ( $client_id === $user_id ) {
			return true;
		}

		return new \WP_Error(
			'rest_forbidden',
			__( 'You do not have access to this timeline.', 'client-sync-pro' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Check permission for unread count — must be logged in.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool|\WP_Error
	 */
	public function get_unread_count_permissions_check( $request ) {
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
	 * Get paginated timeline events for a client.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$client_id   = absint( $request->get_param( 'client_id' ) );
		$page        = absint( $request->get_param( 'page' ) ) ?: 1;
		$per_page    = absint( $request->get_param( 'per_page' ) ) ?: 20;
		$filter_type = sanitize_key( $request->get_param( 'filter' ) );

		$filter_type = ! empty( $filter_type ) ? $filter_type : null;

		$events = $this->repo->get_timeline( $client_id, $page, $per_page, $filter_type );
		$total  = $this->repo->get_timeline_count( $client_id, $filter_type );

		$data = [];
		foreach ( $events as $event ) {
			$actor = get_userdata( (int) $event->actor_id );

			$data[] = [
				'timeline_id' => (int) $event->timeline_id,
				'client_id'   => (int) $event->client_id,
				'event_type'  => $event->event_type,
				'source_id'   => (int) $event->source_id,
				'actor_id'    => (int) $event->actor_id,
				'actor_name'  => $actor ? $actor->display_name : __( 'System', 'client-sync-pro' ),
				'actor_avatar' => $actor ? get_avatar_url( (int) $event->actor_id, [ 'size' => 32 ] ) : '',
				'summary'     => $event->summary,
				'event_date'  => $event->event_date,
				'icon'        => $this->get_event_icon( $event->event_type ),
				'label'       => $this->get_event_label( $event->event_type ),
			];
		}

		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Get unread message count for the current user.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_unread_count( $request ) {
		$schema = new Messaging_Schema();
		$repo   = new Message_Repository( $schema );
		$count  = $repo->get_unread_count( get_current_user_id() );

		return new \WP_REST_Response(
			[
				'unread_count' => $count,
			],
			200
		);
	}

	/**
	 * Get distinct event types for a client (for filter UI).
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_event_types( $request ) {
		$client_id = absint( $request->get_param( 'client_id' ) );
		$types     = $this->repo->get_event_types_for_client( $client_id );

		$data = [];
		foreach ( $types as $type ) {
			$data[] = [
				'slug'  => $type,
				'label' => $this->get_event_label( $type ),
				'icon'  => $this->get_event_icon( $type ),
			];
		}

		return new \WP_REST_Response( $data, 200 );
	}

	// ── Private Helpers ───────────────────────────────────────────

	/**
	 * Get a dashicon class for an event type.
	 *
	 * @param string $event_type Event type slug.
	 * @return string Dashicons class name.
	 */
	private function get_event_icon( string $event_type ): string {
		$icons = [
			'message_sent'          => 'dashicons-email-alt',
			'appointment_created'   => 'dashicons-calendar-alt',
			'appointment_cancelled' => 'dashicons-dismiss',
			'appointment_completed' => 'dashicons-yes-alt',
			'email_sent'            => 'dashicons-email',
			'sms_sent'              => 'dashicons-smartphone',
			'login'                 => 'dashicons-admin-users',
			'logout'                => 'dashicons-migrate',
			'note_updated'          => 'dashicons-edit',
		];

		return $icons[ $event_type ] ?? 'dashicons-marker';
	}

	/**
	 * Get a human-readable label for an event type.
	 *
	 * @param string $event_type Event type slug.
	 * @return string Translated label.
	 */
	private function get_event_label( string $event_type ): string {
		$labels = [
			'message_sent'          => __( 'Message', 'client-sync-pro' ),
			'appointment_created'   => __( 'Appointment Created', 'client-sync-pro' ),
			'appointment_cancelled' => __( 'Appointment Cancelled', 'client-sync-pro' ),
			'appointment_completed' => __( 'Appointment Completed', 'client-sync-pro' ),
			'email_sent'            => __( 'Email Sent', 'client-sync-pro' ),
			'sms_sent'              => __( 'SMS Sent', 'client-sync-pro' ),
			'login'                 => __( 'Login', 'client-sync-pro' ),
			'logout'                => __( 'Logout', 'client-sync-pro' ),
			'note_updated'          => __( 'Note Updated', 'client-sync-pro' ),
		];

		return $labels[ $event_type ] ?? ucwords( str_replace( '_', ' ', $event_type ) );
	}
}
