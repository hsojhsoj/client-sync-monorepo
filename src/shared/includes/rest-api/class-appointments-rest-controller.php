<?php
/**
 * File: src/shared/includes/rest-api/class-appointments-rest-controller.php
 * Provides RESTful CRUD endpoints for appointments.
 *
 * Endpoints:
 *   GET    /clisyc/v1/appointments            - List (filterable by date, status, client)
 *   GET    /clisyc/v1/appointments/{id}       - Single appointment
 *   PUT    /clisyc/v1/appointments/{id}       - Update (status, notes, reschedule)
 *   DELETE /clisyc/v1/appointments/{id}       - Cancel appointment
 *
 * All endpoints require edit_posts capability.
 *
 * @package    ClientSync
 * @subpackage ClientSync/RestAPI
 */

namespace DependentMedia\ClientSync\RestAPI;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Security_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Appointments_Rest_Controller extends \WP_REST_Controller {

	protected $namespace = 'clisyc/v1';
	protected $rest_base = 'appointments';

	/**
	 * Registers REST routes.
	 */
	public function register_routes(): void {

		// GET /appointments — collection.
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'page'       => [ 'default' => 1,  'sanitize_callback' => 'absint' ],
					'per_page'   => [ 'default' => 20, 'sanitize_callback' => 'absint' ],
					'status'     => [ 'default' => '', 'sanitize_callback' => 'sanitize_key' ],
					'start_date' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'end_date'   => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'client_id'  => [ 'default' => 0,  'sanitize_callback' => 'absint' ],
					'search'     => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );

		// GET/PUT/DELETE /appointments/{id} — single item.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			[
				'methods'             => 'PUT, PATCH',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => [ $this, 'check_edit_permission' ],
				'args'                => [
					'status' => [ 'sanitize_callback' => 'sanitize_key' ],
					'notes'  => [ 'sanitize_callback' => 'sanitize_textarea_field' ],
				],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => [ $this, 'check_edit_permission' ],
			],
		] );
	}

	// =====================================================================
	// Permission Callbacks
	// =====================================================================

	/**
	 * Read access requires edit_posts (managers + admins).
	 */
	public function check_permissions(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Write access requires manage_options.
	 */
	public function check_edit_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// =====================================================================
	// GET /appointments
	// =====================================================================

	/**
	 * Returns a paginated, filterable list of appointments.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$rate_check = Security_Helper::check_rate_limit( 'appointments_list', Constants::RATE_LIMIT_APPOINTMENTS, Constants::RATE_LIMIT_WINDOW_SECS );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$page       = max( 1, $request->get_param( 'page' ) );
		$per_page   = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
		$status     = $request->get_param( 'status' );
		$start_date = $request->get_param( 'start_date' );
		$end_date   = $request->get_param( 'end_date' );
		$client_id  = $request->get_param( 'client_id' );
		$search     = $request->get_param( 'search' );

		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'meta_value',
			'meta_key'       => Constants::META_APPOINTMENT_DATE,
			'order'          => 'DESC',
		];

		if ( $search ) {
			$args['s'] = $search;
		}

		if ( $client_id ) {
			$args['author'] = $client_id;
		}

		$meta_query = [];
		if ( $start_date && $end_date ) {
			$meta_query[] = [
				'key'     => Constants::META_APPOINTMENT_DATE,
				'value'   => [ $start_date, $end_date ],
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			];
		} elseif ( $start_date ) {
			$meta_query[] = [
				'key'     => Constants::META_APPOINTMENT_DATE,
				'value'   => $start_date,
				'compare' => '>=',
				'type'    => 'DATE',
			];
		} elseif ( $end_date ) {
			$meta_query[] = [
				'key'     => Constants::META_APPOINTMENT_DATE,
				'value'   => $end_date,
				'compare' => '<=',
				'type'    => 'DATE',
			];
		}

		// Filter by status (maps to post_status).
		$valid_statuses = [ 'publish', Constants::STATUS_CONFIRMED, Constants::STATUS_CANCELLED, Constants::STATUS_COMPLETED, Constants::STATUS_PENDING, Constants::STATUS_NO_SHOW, Constants::STATUS_PENDING_PAYMENT, Constants::STATUS_PAID_ON_DAY, 'trash' ];
		if ( $status && in_array( $status, $valid_statuses, true ) ) {
			$args['post_status'] = $status;
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		$query = new \WP_Query( $args );
		$items = [];

		// Prime meta and user caches to avoid N+1 queries in prepare_appointment().
		if ( $query->posts ) {
			$post_ids   = wp_list_pluck( $query->posts, 'ID' );
			$author_ids = array_unique( wp_list_pluck( $query->posts, 'post_author' ) );
			update_postmeta_cache( $post_ids );
			if ( $author_ids ) {
				$user_query = new \WP_User_Query( [
					'include'     => $author_ids,
					'fields'      => 'all',
					'count_total' => false,
				] );
				$user_query->get_results();
			}

			// PERFORMANCE FIX: Prime dimension post cache to prevent N+1
			// get_post() calls when resolving dimension labels.
			$dim_post_ids = [];
			foreach ( $query->posts as $p ) {
				$dims = get_post_meta( $p->ID, Constants::META_SLOT_DIMENSIONS, true );
				if ( is_array( $dims ) ) {
					foreach ( $dims as $dim_id ) {
						$dim_post_ids[] = (int) $dim_id;
					}
				}
			}
			$dim_post_ids = array_unique( array_filter( $dim_post_ids ) );
			if ( $dim_post_ids ) {
				_prime_post_caches( $dim_post_ids, false, false );
			}
		}

		foreach ( $query->posts as $post ) {
			$items[] = $this->prepare_appointment( $post );
		}

		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );

		return $response;
	}

	// =====================================================================
	// GET /appointments/{id}
	// =====================================================================

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$post = get_post( $request['id'] );

		if ( ! $post || Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			return new \WP_Error( 'not_found', __( 'Appointment not found.', 'client-sync' ), [ 'status' => 404 ] );
		}

		return new \WP_REST_Response( $this->prepare_appointment( $post ), 200 );
	}

	// =====================================================================
	// PUT/PATCH /appointments/{id}
	// =====================================================================

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$post = get_post( $request['id'] );

		if ( ! $post || Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			return new \WP_Error( 'not_found', __( 'Appointment not found.', 'client-sync' ), [ 'status' => 404 ] );
		}

		$changes = false;

		// Update status.
		$new_status = $request->get_param( 'status' );
		$allowed    = [ 'publish', Constants::STATUS_CONFIRMED, Constants::STATUS_CANCELLED, Constants::STATUS_COMPLETED, Constants::STATUS_NO_SHOW ];
		if ( $new_status && in_array( $new_status, $allowed, true ) ) {
			wp_update_post( [ 'ID' => $post->ID, 'post_status' => $new_status ] );
			$changes = true;
		}

		// Update notes.
		$notes = $request->get_param( 'notes' );
		if ( null !== $notes ) {
			update_post_meta( $post->ID, Constants::META_NOTES, $notes );
			$changes = true;
		}

		if ( ! $changes ) {
			return new \WP_Error( 'no_changes', __( 'No valid fields to update.', 'client-sync' ), [ 'status' => 400 ] );
		}

		return new \WP_REST_Response( $this->prepare_appointment( get_post( $post->ID ) ), 200 );
	}

	// =====================================================================
	// DELETE /appointments/{id}
	// =====================================================================

	/**
	 * Cancels an appointment (moves to trash).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$post = get_post( $request['id'] );

		if ( ! $post || Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			return new \WP_Error( 'not_found', __( 'Appointment not found.', 'client-sync' ), [ 'status' => 404 ] );
		}

		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => Constants::STATUS_CANCELLED,
		] );

		/**
		 * Fires when an appointment is cancelled via REST API.
		 *
		 * @param int $appointment_id The cancelled appointment post ID.
		 */
		do_action( 'clisyc_appointment_cancelled', $post->ID );

		return new \WP_REST_Response( [
			'id'      => $post->ID,
			'deleted' => true,
			'status'  => Constants::STATUS_CANCELLED,
		], 200 );
	}

	// =====================================================================
	// Data Preparation
	// =====================================================================

	/**
	 * Formats an appointment post into a consistent API response shape.
	 *
	 * @param \WP_Post $post
	 * @return array
	 */
	private function prepare_appointment( \WP_Post $post ): array {
		$client = get_user_by( 'id', $post->post_author );

		$dimensions_raw = get_post_meta( $post->ID, Constants::META_SLOT_DIMENSIONS, true );
		$dimensions     = is_array( $dimensions_raw ) ? $dimensions_raw : [];

		// Resolve dimension names.
		$dimension_labels = [];
		foreach ( $dimensions as $slug => $id ) {
			$dim_post = get_post( $id );
			$dimension_labels[ $slug ] = $dim_post ? $dim_post->post_title : '#' . $id;
		}

		return [
			'id'               => $post->ID,
			'title'            => $post->post_title,
			'status'           => get_post_status( $post->ID ),
			'date_created'     => $post->post_date_gmt,
			'appointment_date' => get_post_meta( $post->ID, Constants::META_APPOINTMENT_DATE, true ),
			'time_slot_utc'    => get_post_meta( $post->ID, Constants::META_TIME_SLOT, true ),
			'booking_mode'     => get_post_meta( $post->ID, Constants::META_BOOKING_MODE, true ),
			'start_date'       => get_post_meta( $post->ID, Constants::META_START_DATE, true ),
			'end_date'         => get_post_meta( $post->ID, Constants::META_END_DATE, true ),
			'service_name'     => get_post_meta( $post->ID, Constants::META_SERVICE_NAME, true ),
			'notes'            => get_post_meta( $post->ID, Constants::META_NOTES, true ),
			'payment_status'   => get_post_meta( $post->ID, Constants::META_PAYMENT_STATUS, true ),
			'dimensions'       => $dimensions,
			'dimension_labels' => $dimension_labels,
			'client'           => $client ? [
				'id'           => $client->ID,
				'display_name' => $client->display_name,
				'email'        => $client->user_email,
			] : null,
			'_links' => [
				'self'   => [ 'href' => rest_url( $this->namespace . '/' . $this->rest_base . '/' . $post->ID ) ],
				'edit'   => [ 'href' => get_edit_post_link( $post->ID, 'raw' ) ],
			],
		];
	}
}
