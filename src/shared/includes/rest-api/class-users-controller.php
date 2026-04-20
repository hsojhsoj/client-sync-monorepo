<?php
/**
 * File: class-users-controller.php
 * REST API Controller for User Search
 * Location: src/shared/includes/rest-api/class-users-controller.php
 *
 * Provides a lightweight endpoint for searching WordPress users
 * Used by the client autocomplete on appointment edit screens.
 *
 * @package    ClientSync
 * @subpackage ClientSync/RestAPI
 */

namespace DependentMedia\ClientSync\RestAPI;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Security_Helper;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Users_Controller extends WP_REST_Controller {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->namespace = 'clisyc/v1';
		$this->rest_base = 'users';
	}

	/**
	 * Register routes
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/search',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'search_users' ],
					'permission_callback' => [ $this, 'search_users_permissions_check' ],
					'args'                => [
						'q' => [
							'required'          => true,
							'type'              => 'string',
							'description'       => 'Search query (name or email)',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function( $param ) {
								return is_string( $param ) && strlen( $param ) >= 2;
							},
						],
						'limit' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 10,
							'sanitize_callback' => 'absint',
							'validate_callback' => function( $param ) {
								return is_numeric( $param ) && $param > 0 && $param <= 50;
							},
						],
						'exclude' => [
							'required'          => false,
							'type'              => 'string',
							'description'       => 'Comma-separated user IDs to exclude',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		// Get single user by ID (for populating existing selections)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_user' ],
					'permission_callback' => [ $this, 'search_users_permissions_check' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	/**
	 * Check permissions for searching users.
	 *
	 * Gated on the manager-view capability (default `edit_others_posts`),
	 * not `edit_posts`. The response includes user email addresses, so a
	 * Contributor-level account must not be able to use this endpoint as a
	 * user-enumeration primitive.
	 */
	public function search_users_permissions_check( $request ) {
		$cap = apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' );
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'You do not have permission to search users.', 'client-sync' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Search users by name or email
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search_users( $request ) {
		$rate_check = Security_Helper::check_rate_limit( 'user_search', Constants::RATE_LIMIT_USER_SEARCH, Constants::RATE_LIMIT_WINDOW_SECS );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$search_query = $request->get_param( 'q' );
		$limit        = $request->get_param( 'limit' ) ?: 10;
		$exclude_str  = $request->get_param( 'exclude' );
		
		$exclude_ids = [];
		if ( ! empty( $exclude_str ) ) {
			$exclude_ids = array_map( 'absint', explode( ',', $exclude_str ) );
		}

		// Search by display_name, user_login, user_email, first_name, last_name
		// WP_User_Query's search only searches certain fields by default,
		// so we'll do a custom search using meta_query for names
		
		$search_term = '*' . $search_query . '*';
		
		// First query: Search user fields (login, email, nicename, display_name)
		$user_query_args = [
			'search'         => $search_term,
			'search_columns' => [ 'user_login', 'user_email', 'user_nicename', 'display_name' ],
			'number'         => $limit,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
			'exclude'        => $exclude_ids,
		];

		$user_query = new WP_User_Query( $user_query_args );
		$users_from_main = $user_query->get_results();
		
		// Second query: Search in user meta (first_name, last_name)
		$meta_query_args = [
			'number'     => $limit,
			'orderby'    => 'display_name',
			'order'      => 'ASC',
			'exclude'    => $exclude_ids,
			'meta_query' => [
				'relation' => 'OR',
				[
					'key'     => 'first_name',
					'value'   => $search_query,
					'compare' => 'LIKE',
				],
				[
					'key'     => 'last_name',
					'value'   => $search_query,
					'compare' => 'LIKE',
				],
			],
		];

		$meta_user_query = new WP_User_Query( $meta_query_args );
		$users_from_meta = $meta_user_query->get_results();

		// Merge and deduplicate results
		$all_users = array_merge( $users_from_main, $users_from_meta );
		$unique_users = [];
		$seen_ids = [];

		foreach ( $all_users as $user ) {
			if ( ! in_array( $user->ID, $seen_ids, true ) ) {
				$seen_ids[] = $user->ID;
				$unique_users[] = $user;
			}
		}

		// Limit total results
		$unique_users = array_slice( $unique_users, 0, $limit );

		// Format results
		$results = [];
		foreach ( $unique_users as $user ) {
			$results[] = $this->format_user_for_response( $user );
		}

		return new WP_REST_Response( [
			'results' => $results,
			'total'   => count( $results ),
			'query'   => $search_query,
		], 200 );
	}

	/**
	 * Get a single user by ID
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_user( $request ) {
		$user_id = $request->get_param( 'id' );
		$user = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return new WP_Error(
				'user_not_found',
				esc_html__( 'User not found.', 'client-sync' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $this->format_user_for_response( $user ), 200 );
	}

	/**
	 * Format a user object for API response
	 *
	 * @param WP_User $user The user object.
	 * @return array Formatted user data.
	 */
	private function format_user_for_response( $user ) {
		$first_name = get_user_meta( $user->ID, 'first_name', true );
		$last_name  = get_user_meta( $user->ID, 'last_name', true );
		
		// Build a display string: "Display Name (email@example.com)"
		$display_string = $user->display_name;
		if ( $user->user_email && $user->user_email !== $user->display_name ) {
			$display_string .= ' (' . $user->user_email . ')';
		}

		// Build full name if available
		$full_name = trim( $first_name . ' ' . $last_name );

		return [
			'id'             => $user->ID,
			'display_name'   => $user->display_name,
			'email'          => $user->user_email,
			'first_name'     => $first_name,
			'last_name'      => $last_name,
			'full_name'      => $full_name ?: $user->display_name,
			'display_string' => $display_string,
			'avatar_url'     => get_avatar_url( $user->ID, [ 'size' => 32 ] ),
			'roles'          => $user->roles,
			'edit_link'      => get_edit_user_link( $user->ID ),
		];
	}
}