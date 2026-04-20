<?php
/**
 * File: src/pro/includes/modules/seat-selection/class-venue-rest-controller.php
 * REST endpoints for seat selection: layout, availability, hold/release/refresh.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Seat_Selection
 */

namespace ClientSyncPro\Modules\Seat_Selection;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Security_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Venue_REST_Controller extends \WP_REST_Controller {

	protected $namespace = 'clisyc/v1';
	protected $rest_base = 'seats';

	/**
	 * @var Seat_Hold_Manager
	 */
	private $hold_manager;

	public function __construct( Seat_Hold_Manager $hold_manager = null ) {
		$this->hold_manager = $hold_manager ?: new Seat_Hold_Manager();
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		// GET /seats/layout/{venue_id} — public, returns parsed layout JSON.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/layout/(?P<venue_id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_layout' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'venue_id' => [
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
						'sanitize_callback' => 'absint',
					],
				],
			],
		] );

		// GET /seats/availability/{venue_id}/{slot_id} — public, returns held/booked seats.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/availability/(?P<venue_id>\d+)/(?P<slot_id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_availability' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'venue_id' => [
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'slot_id' => [
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'session_token' => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
		] );

		// POST /seats/hold — hold a seat. Requires a valid wp_rest nonce so
		// an anonymous bot can't script fanout holds across randomized
		// session_token values (ticketing DoS).
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/hold', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'hold_seat' ],
				'permission_callback' => [ $this, 'check_rest_nonce' ],
				'args'                => [
					'venue_id'      => [ 'required' => true, 'sanitize_callback' => 'absint' ],
					'slot_id'       => [ 'required' => true, 'sanitize_callback' => 'absint' ],
					'seat_id'       => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					'session_token' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );

		// DELETE /seats/hold — release a seat. Nonce-gated for consistency.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/hold', [
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'release_seat' ],
				'permission_callback' => [ $this, 'check_rest_nonce' ],
				'args'                => [
					'venue_id'      => [ 'required' => true, 'sanitize_callback' => 'absint' ],
					'slot_id'       => [ 'required' => true, 'sanitize_callback' => 'absint' ],
					'seat_id'       => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					'session_token' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );

		// GET /seats/resolve/{slot_id} — resolve venue ID from a slot's primary dimension.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/resolve/(?P<slot_id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'resolve_venue' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'slot_id' => [
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			],
		] );

		// POST /seats/refresh — extend hold TTL for all session seats.
		// Nonce-gated: refreshing is cheap and the route is the easiest way
		// for an attacker to keep forged holds alive past the TTL.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/refresh', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'refresh_holds' ],
				'permission_callback' => [ $this, 'check_rest_nonce' ],
				'args'                => [
					'venue_id'      => [ 'required' => true, 'sanitize_callback' => 'absint' ],
					'slot_id'       => [ 'required' => true, 'sanitize_callback' => 'absint' ],
					'session_token' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );
	}

	/**
	 * Permission callback for the seat-write endpoints.
	 *
	 * Accepts requests from anonymous users (seat selection must work
	 * without login) but requires a valid `wp_rest` nonce, which binds the
	 * request to a browser session that actually loaded a booking page on
	 * this site. Without this, a scripted attacker could occupy every seat
	 * of a venue using randomized session_token values and block legitimate
	 * customers from reserving — a pure ticketing DoS.
	 *
	 * Note: `wp_verify_nonce` works for anonymous users too — it binds to
	 * the session cookie WordPress sets on every visitor.
	 */
	public function check_rest_nonce( \WP_REST_Request $request ) {
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( ! $nonce && isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) );
		}
		// Also accept `_wpnonce` as a query arg or body param for flexibility.
		if ( ! $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Your session token is invalid or expired. Please refresh the page and try again.', 'client-sync-pro' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * GET /seats/layout/{venue_id}
	 *
	 * Returns the parsed venue layout JSON and SVG string.
	 */
	public function get_layout( \WP_REST_Request $request ): \WP_REST_Response {
		$venue_id = $request->get_param( 'venue_id' );

		$post = get_post( $venue_id );
		if ( ! $post || Constants::POST_TYPE_VENUE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new \WP_REST_Response( [ 'code' => 'not_found', 'message' => __( 'Venue not found.', 'client-sync-pro' ) ], 404 );
		}

		$layout       = get_post_meta( $venue_id, Constants::META_VENUE_LAYOUT, true );
		$svg          = get_post_meta( $venue_id, Constants::META_VENUE_SVG, true );
		$overview_svg = get_post_meta( $venue_id, Constants::META_VENUE_OVERVIEW_SVG, true );

		if ( empty( $layout ) || ! is_array( $layout ) ) {
			return new \WP_REST_Response( [ 'code' => 'no_layout', 'message' => __( 'Venue has no parsed layout.', 'client-sync-pro' ) ], 404 );
		}

		$pricing_tiers = get_post_meta( $venue_id, Constants::META_SEAT_PRICING_TIERS, true );

		return new \WP_REST_Response( [
			'venue_id'      => $venue_id,
			'name'          => $post->post_title,
			'layout'        => $layout,
			'svg'           => $svg ?: '',
			'overview_svg'  => $overview_svg ?: '',
			'pricing_tiers' => is_array( $pricing_tiers ) ? $pricing_tiers : [],
			'hold_ttl'      => $this->hold_manager->get_ttl(),
		], 200 );
	}

	/**
	 * GET /seats/availability/{venue_id}/{slot_id}
	 *
	 * Returns which seats are held, booked, or held by the current session.
	 */
	public function get_availability( \WP_REST_Request $request ): \WP_REST_Response {
		$venue_id      = $request->get_param( 'venue_id' );
		$slot_id       = $request->get_param( 'slot_id' );
		$session_token = $request->get_param( 'session_token' ) ?: '';

		$availability = $this->hold_manager->get_seat_availability( $venue_id, $slot_id, $session_token );

		return new \WP_REST_Response( $availability, 200 );
	}

	/**
	 * POST /seats/hold
	 *
	 * Hold a single seat for the session.
	 */
	public function hold_seat( \WP_REST_Request $request ): \WP_REST_Response {
		$rate_check = Security_Helper::check_rate_limit(
			'seat_hold',
			Constants::RATE_LIMIT_SEAT_HOLD,
			Constants::RATE_LIMIT_WINDOW_SECS
		);
		if ( is_wp_error( $rate_check ) ) {
			return new \WP_REST_Response( [ 'code' => 'rate_limited', 'message' => $rate_check->get_error_message() ], 429 );
		}

		$venue_id      = $request->get_param( 'venue_id' );
		$slot_id       = $request->get_param( 'slot_id' );
		$seat_id       = $request->get_param( 'seat_id' );
		$session_token = $request->get_param( 'session_token' );
		$user_id       = get_current_user_id();

		// Validate venue exists.
		$post = get_post( $venue_id );
		if ( ! $post || Constants::POST_TYPE_VENUE !== $post->post_type ) {
			return new \WP_REST_Response( [ 'code' => 'not_found', 'message' => __( 'Venue not found.', 'client-sync-pro' ) ], 404 );
		}

		// Validate seat exists in layout.
		$layout = get_post_meta( $venue_id, Constants::META_VENUE_LAYOUT, true );
		if ( ! $this->seat_exists_in_layout( $layout, $seat_id ) ) {
			return new \WP_REST_Response( [ 'code' => 'invalid_seat', 'message' => __( 'Seat not found in venue layout.', 'client-sync-pro' ) ], 400 );
		}

		$result = $this->hold_manager->hold_seat( $venue_id, $slot_id, $seat_id, $session_token, $user_id );

		if ( is_wp_error( $result ) ) {
			$status = $result->get_error_data()['status'] ?? 409;
			return new \WP_REST_Response( [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ], $status );
		}

		$ttl = $this->hold_manager->get_ttl();

		return new \WP_REST_Response( [
			'held'       => true,
			'seat_id'    => $seat_id,
			'expires_in' => $ttl,
		], 200 );
	}

	/**
	 * DELETE /seats/hold
	 *
	 * Release a held seat.
	 */
	public function release_seat( \WP_REST_Request $request ): \WP_REST_Response {
		$venue_id      = $request->get_param( 'venue_id' );
		$slot_id       = $request->get_param( 'slot_id' );
		$seat_id       = $request->get_param( 'seat_id' );
		$session_token = $request->get_param( 'session_token' );

		$released = $this->hold_manager->release_seat( $venue_id, $slot_id, $seat_id, $session_token );

		return new \WP_REST_Response( [ 'released' => $released ], 200 );
	}

	/**
	 * POST /seats/refresh
	 *
	 * Extend hold TTL for all seats held by this session.
	 */
	public function refresh_holds( \WP_REST_Request $request ): \WP_REST_Response {
		$venue_id      = $request->get_param( 'venue_id' );
		$slot_id       = $request->get_param( 'slot_id' );
		$session_token = $request->get_param( 'session_token' );

		$refreshed = $this->hold_manager->refresh_holds( $venue_id, $slot_id, $session_token );
		$ttl       = $this->hold_manager->get_ttl();

		return new \WP_REST_Response( [
			'refreshed'  => $refreshed,
			'expires_in' => $ttl,
		], 200 );
	}

	/**
	 * GET /seats/resolve/{slot_id}
	 *
	 * Resolve a venue ID from a slot's primary dimension item.
	 * Returns 0 if no venue is linked.
	 */
	public function resolve_venue( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$slot_id = $request->get_param( 'slot_id' );

		$schema    = new \DependentMedia\ClientSync\Core\Table_Schema_Manager();
		$dims_table = $schema->get_dimensions_table_name();

		// Get the primary service dimension for this slot.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$service_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT dimension_value FROM {$dims_table} WHERE slot_id = %d AND dimension_key = 'clisyc_service' LIMIT 1",
				$slot_id
			)
		);

		if ( ! $service_id ) {
			// Try the configured primary dimension key.
			$primary_key = get_option( Constants::OPTION_PRIMARY_SERVICE_DIM, '' );
			if ( $primary_key ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$service_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT dimension_value FROM {$dims_table} WHERE slot_id = %d AND dimension_key = %s LIMIT 1",
						$slot_id,
						$primary_key
					)
				);
			}
		}

		if ( ! $service_id ) {
			return new \WP_REST_Response( [ 'venue_id' => 0 ], 200 );
		}

		$venue_id = absint( get_post_meta( (int) $service_id, Constants::META_LINKED_VENUE_ID, true ) );

		return new \WP_REST_Response( [ 'venue_id' => $venue_id ], 200 );
	}

	/**
	 * Check if a seat ID exists in a venue layout.
	 *
	 * @param array|mixed $layout
	 * @param string      $seat_id
	 * @return bool
	 */
	private function seat_exists_in_layout( $layout, string $seat_id ): bool {
		if ( ! is_array( $layout ) || empty( $layout['sections'] ) ) {
			return false;
		}

		foreach ( $layout['sections'] as $section ) {
			foreach ( $section['rows'] ?? [] as $row ) {
				foreach ( $row['seats'] ?? [] as $seat ) {
					if ( ( $seat['id'] ?? '' ) === $seat_id ) {
						return true;
					}
				}
			}
		}

		return false;
	}
}
