<?php
/**
 * File: src/shared/includes/rest-api/class-slots-controller.php
 * Defines the REST API endpoints for fetching calendar/slot data. 
 *
 * @package    ClientSync
 * @subpackage ClientSync/RestAPI
 */

namespace DependentMedia\ClientSync\RestAPI;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Services\CalendarDataProvider;
use DependentMedia\ClientSync\Utility\Debug_Logger;
use DependentMedia\ClientSync\Utility\Security_Helper;
use DependentMedia\ClientSync\Utility\Service_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slots_Controller extends \WP_REST_Controller {

	protected $namespace = 'clisyc/v1';
	protected $rest_base = 'slots';

	/**
	 * Registers the routes for the objects of the controller.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => '__return_true', // Public endpoint for booking form
					'args'                => [
						'start'          => [
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => [ $this, 'is_valid_iso8601_date' ],
							'description'       => 'Start date for the event query in ISO 8601 format.',
						],
						'end'            => [
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => [ $this, 'is_valid_iso8601_date' ],
							'description'       => 'End date for the event range, in ISO 8601 format.',
						],
						'hide_booked'    => [ 'sanitize_callback' => 'rest_sanitize_boolean' ],
						'include_booked' => [ 'sanitize_callback' => 'rest_sanitize_boolean' ],
						'context'        => [ 'sanitize_callback' => 'sanitize_key' ],
						'view_context'   => [ 'sanitize_callback' => 'sanitize_key' ],
						'overview_mode'  => [ 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/booked-dates',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_booked_dates' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'start'        => [
							'required'          => true,
							'validate_callback' => [ $this, 'is_valid_iso8601_date' ],
						],
						'end'          => [
							'required'          => true,
							'validate_callback' => [ $this, 'is_valid_iso8601_date' ],
						],
						'dimension_id' => [
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	/**
	 * A custom validation callback to check if a string can be parsed as a valid date.
	 */
	public function is_valid_iso8601_date( $param, $request, $key ) {
		if ( ! is_string( $param ) ) {
			/* translators: %s: The name of the invalid parameter key (e.g., 'start' or 'end'). */
			return new \WP_Error( 'rest_invalid_param', sprintf( esc_html__( '%s is not a string.', 'client-sync' ), $key ), [ 'status' => 400 ] );
		}

		// Strict format check: must start with YYYY-MM-DD pattern.
		// Prevents permissive DateTime parsing of strings like "next friday" or "tomorrow".
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $param ) ) {
			/* translators: %s: The name of the invalid parameter key (e.g., 'start' or 'end'). */
			return new \WP_Error( 'rest_invalid_date', sprintf( esc_html__( '%s is not a valid ISO 8601 date.', 'client-sync' ), $key ), [ 'status' => 400 ] );
		}

		try {
			new \DateTime( $param );
		} catch ( \Exception $e ) {
			/* translators: %s: The name of the invalid parameter key (e.g., 'start' or 'end'). */
			return new \WP_Error( 'rest_invalid_date', sprintf( esc_html__( '%s is not a valid date.', 'client-sync' ), $key ), [ 'status' => 400 ] );
		}
		return true;
	}

	public function get_items( $request ) {
		Debug_Logger::log_data( 'get_items called with params', $request->get_params(), 'REST' );

		// 1. SECURITY: Rate Limiting
		$rate_check = Security_Helper::check_rate_limit( 'fetch_slots', Constants::RATE_LIMIT_SLOT_FETCH, Constants::RATE_LIMIT_WINDOW_SECS );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$params = $request->get_params();

		try {
			$fetch_info = [
				'start' => new \DateTime( $params['start'] ),
				'end'   => new \DateTime( $params['end'] ),
			];

			// 2. SECURITY: Max Date Range Clamp
			$diff = $fetch_info['start']->diff( $fetch_info['end'] );
			if ( $diff->days > 90 && ! current_user_can( 'edit_posts' ) ) {
				return new \WP_Error( 'range_too_large', __( 'Date range too large. Maximum 90 days allowed.', 'client-sync' ), [ 'status' => 400 ] );
			}
		} catch ( \Exception $e ) {
			return new \WP_Error( 'invalid_date_format', __( 'Invalid date format.', 'client-sync' ), [ 'status' => 400 ] );
		}

		$registry     = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$filter_order = $registry['filter_order'] ?? [];
		$dimensions   = [];

		// Handle array parameters for faceted search
		foreach ( $filter_order as $slug ) {
			$param_val = $request->get_param( $slug );

			if ( is_array( $param_val ) && ! empty( $param_val ) ) {
				$dimensions[ $slug ] = array_map( 'absint', $param_val );
			} elseif ( ! is_array( $param_val ) && ! empty( $param_val ) ) {
				$dimensions[ $slug ] = [ absint( $param_val ) ];
			}
		}

		$hide_booked    = ! empty( $params['hide_booked'] );
		$include_booked = ! empty( $params['include_booked'] );
		$overview_mode  = $params['overview_mode'] ?? 'none';
		$context_param  = $params['context'] ?? 'default';
		$is_admin_editable_context = false;

		if ( 'admin_editable' === $context_param ) {
			if ( Security_Helper::validate_admin_context( 'admin_editable' ) ) {
				$is_admin_editable_context = true;
			} else {
				return new \WP_Error( 'forbidden_context', __( 'Insufficient permissions for admin context.', 'client-sync' ), [ 'status' => 403 ] );
			}
		}

		$view_context     = $params['view_context'] ?? 'default';
		$user_id_to_fetch = ( 'user_only' === $view_context && is_user_logged_in() ) ? get_current_user_id() : null;

		$db_manager = new Database_Manager();
		$provider   = new CalendarDataProvider( $db_manager );

		$calendar_data = $provider->get_calendar_data(
			$fetch_info,
			$is_admin_editable_context,
			$view_context,
			$user_id_to_fetch,
			$hide_booked,
			$overview_mode,
			$dimensions
		);

		if ( is_wp_error( $calendar_data ) ) {
			return $calendar_data;
		}

		// Filter returned slots to only include those matching selected dimensions
		if ( ! empty( $dimensions ) && is_array( $calendar_data ) ) {
			$calendar_data = $this->filter_slots_by_dimensions( $calendar_data, $dimensions, $registry );
		}

		// If include_booked is true, fetch and append booked appointments
		if ( $include_booked ) {
			$booked_events = $this->get_booked_appointments_as_events( $fetch_info, $dimensions, $registry );
			
			if ( ! empty( $booked_events ) ) {
				if ( isset( $calendar_data['events'] ) && is_array( $calendar_data['events'] ) ) {
					$calendar_data['events'] = array_merge( $calendar_data['events'], $booked_events );
				} elseif ( is_array( $calendar_data ) && isset( $calendar_data[0] ) ) {
					$calendar_data = array_merge( $calendar_data, $booked_events );
				} else {
					// Create events array if it doesn't exist
					if ( ! isset( $calendar_data['events'] ) ) {
						$calendar_data['events'] = [];
					}
					$calendar_data['events'] = array_merge( $calendar_data['events'], $booked_events );
				}
				
				Debug_Logger::log( 'Added ' . count( $booked_events ) . ' booked appointments to response', 'REST' );
			}
		}

		$response = new \WP_REST_Response( $calendar_data, 200 );

		// Add total count header for clients that want to know result size.
		$event_count = 0;
		if ( is_array( $calendar_data ) ) {
			if ( isset( $calendar_data['events'] ) && is_array( $calendar_data['events'] ) ) {
				$event_count = count( $calendar_data['events'] );
			} elseif ( isset( $calendar_data[0] ) ) {
				$event_count = count( $calendar_data );
			}
		}
		$response->header( 'X-WP-Total', $event_count );

		// Cache public responses for 60 seconds to reduce server load.
		if ( ! $is_admin_editable_context ) {
			$response->header( 'Cache-Control', 'public, max-age=60' );
		} else {
			$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
		}

		return $response;
	}

	/**
	 * Fetch booked appointments and format them as calendar events.
	 *
	 * @param array $fetch_info Array with 'start' and 'end' DateTime objects.
	 * @param array $dimensions Selected dimension filters.
	 * @param array $registry   Dimension registry.
	 * @return array Array of event objects representing booked appointments.
	 */
	private function get_booked_appointments_as_events( $fetch_info, $dimensions, $registry ) {
		$events = [];

		$start_date = $fetch_info['start']->format( 'Y-m-d' );
		$end_date   = $fetch_info['end']->format( 'Y-m-d' );

		Debug_Logger::log( 'Searching for booked appointments between ' . $start_date . ' and ' . $end_date, 'REST' );

		// Query appointments within the requested date range at the DB level
		// to avoid loading ALL appointments into memory.
		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => [ 'publish', Constants::STATUS_CONFIRMED, 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ],
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to filter appointments by time slot within date range.
			'meta_query'     => [
				[
					'key'     => Constants::META_TIME_SLOT,
					'value'   => [ $start_date . 'T00:00:00', $end_date . 'T23:59:59' ],
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				],
			],
		];

		$all_appointments = get_posts( $args );

		Debug_Logger::log( 'Total appointments with _clisyc_time_slot: ' . count( $all_appointments ), 'REST' );

		// PERFORMANCE FIX: Batch-load all post meta to prevent N+1 queries.
		// Without this, each get_post_meta() call below triggers a separate DB query.
		if ( ! empty( $all_appointments ) ) {
			$appointment_ids = wp_list_pluck( $all_appointments, 'ID' );
			update_meta_cache( 'post', $appointment_ids );

			// PERFORMANCE FIX: Also prime dimension post cache to prevent N+1
			// get_post() calls when resolving dimension names inside the loop.
			$dim_post_ids = [];
			foreach ( $all_appointments as $appt ) {
				$dims = get_post_meta( $appt->ID, Constants::META_SLOT_DIMENSIONS, true );
				if ( is_array( $dims ) ) {
					foreach ( $dims as $dim_id ) {
						$dim_post_ids[] = (int) $dim_id;
					}
				}
			}
			$dim_post_ids = array_unique( array_filter( $dim_post_ids ) );
			if ( $dim_post_ids ) {
				_prime_post_caches( $dim_post_ids, true, false );
			}
		}

		// Filter appointments by date range in PHP for better compatibility
		$appointments = [];
		$start_ts = strtotime( $start_date . ' 00:00:00' );
		$end_ts   = strtotime( $end_date . ' 23:59:59' );

		foreach ( $all_appointments as $appt ) {
			$time_slot = get_post_meta( $appt->ID, Constants::META_TIME_SLOT, true );
			if ( empty( $time_slot ) ) {
				continue;
			}

			$slot_ts = strtotime( $time_slot );
			if ( $slot_ts >= $start_ts && $slot_ts <= $end_ts ) {
				$appointments[] = $appt;
			}
		}

		Debug_Logger::log( 'Found ' . count( $appointments ) . ' booked appointments in date range', 'REST' );

		foreach ( $appointments as $appointment ) {
			$time_slot = get_post_meta( $appointment->ID, Constants::META_TIME_SLOT, true );
			$duration  = Service_Helper::get_duration_minutes( (int) $appointment->ID );
			$slot_dims = get_post_meta( $appointment->ID, Constants::META_SLOT_DIMENSIONS, true );

			if ( empty( $time_slot ) ) {
				continue;
			}

			// Filter by selected dimensions if any
			if ( ! empty( $dimensions ) && ! empty( $slot_dims ) && is_array( $slot_dims ) ) {
				$matches = true;
				foreach ( $dimensions as $dim_slug => $selected_ids ) {
					if ( empty( $selected_ids ) ) {
						continue;
					}
					$appt_dim_id = isset( $slot_dims[ $dim_slug ] ) ? absint( $slot_dims[ $dim_slug ] ) : null;
					if ( $appt_dim_id === null || ! in_array( $appt_dim_id, $selected_ids, true ) ) {
						$matches = false;
						break;
					}
				}
				if ( ! $matches ) {
					continue;
				}
			}

			try {
				$start_dt = new \DateTime( $time_slot );
				$end_dt   = clone $start_dt;
				// $duration already defaulted via Service_Helper above.
				$end_dt->modify( '+' . $duration . ' minutes' );

				// Build service details from slot dimensions
				$service_details = [];
				if ( ! empty( $slot_dims ) && is_array( $slot_dims ) ) {
					foreach ( $slot_dims as $dim_slug => $dim_id ) {
						$js_key     = str_replace( 'clisyc_', '', $dim_slug );
						$dim_post   = get_post( $dim_id );
						$dim_color  = get_post_meta( $dim_id, Constants::META_COLOR, true );
						
						$service_details[ $js_key . '_id' ]    = absint( $dim_id );
						$service_details[ $js_key . '_name' ]  = $dim_post ? $dim_post->post_title : '';
						$service_details[ $js_key . '_color' ] = $dim_color ?: '#6b7280';
					}
				}

				// Create a display title (e.g., "Booked: Dr. Carter - Initial Consultation")
				$title_parts = [];
				$filter_order = $registry['filter_order'] ?? [];
				foreach ( $filter_order as $slug ) {
					$js_key = str_replace( 'clisyc_', '', $slug );
					if ( ! empty( $service_details[ $js_key . '_name' ] ) ) {
						$title_parts[] = $service_details[ $js_key . '_name' ];
					}
				}
				$display_title = ! empty( $title_parts ) ? implode( ' - ', $title_parts ) : __( 'Booked', 'client-sync' );

				$events[] = [
					'id'            => 'booked-' . $appointment->ID,
					'title'         => $display_title,
					'start'         => $start_dt->format( 'c' ),
					'end'           => $end_dt->format( 'c' ),
					'extendedProps' => [
						'isBooked'        => true,
						'appointmentId'   => $appointment->ID,
						'durationMinutes' => $duration,
						'serviceDetails'  => $service_details,
						'dimensions'      => $slot_dims,
					],
				];

			} catch ( \Exception $e ) {
				Debug_Logger::log( 'Error parsing appointment ' . $appointment->ID . ': ' . $e->getMessage(), 'REST' );
				continue;
			}
		}

		return $events;
	}

	/**
	 * Filter slots to only include those matching the selected dimensions.
	 */
	private function filter_slots_by_dimensions( $slots, $dimensions, $registry ) {
		if ( empty( $slots ) || empty( $dimensions ) ) {
			return $slots;
		}

		Debug_Logger::log( 'Data type: ' . gettype( $slots ), 'Slots Filter' );

		// Handle structure variations
		if ( isset( $slots[0] ) ) {
			return $this->do_filter_slots( $slots, $dimensions );
		} elseif ( is_array( $slots ) ) {
			foreach ( [ 'events', 'slots', 'data' ] as $key ) {
				if ( isset( $slots[ $key ] ) && is_array( $slots[ $key ] ) ) {
					Debug_Logger::log( "Found slots in '$key' key", 'Slots Filter' );
					$slots[ $key ] = $this->do_filter_slots( $slots[ $key ], $dimensions );
					return $slots;
				}
			}
			Debug_Logger::log( 'WARNING: Could not find slots array to filter', 'Slots Filter' );
			return $slots;
		}

		return $slots;
	}

	/**
	 * Actually perform the filtering on a slots array.
	 */
	private function do_filter_slots( $slots, $dimensions ) {
		if ( empty( $slots ) ) {
			return $slots;
		}

		$filtered = [];

		if ( ! empty( $slots[0] ) ) {
			Debug_Logger::log( 'First slot keys: ' . wp_json_encode( array_keys( $slots[0] ) ), 'Slots Filter' );
			Debug_Logger::log( 'extendedProps: ' . wp_json_encode( $slots[0]['extendedProps'] ?? 'none' ), 'Slots Filter' );
		}

		foreach ( $slots as $slot ) {
			$matches_all     = true;
			$ext_props       = $slot['extendedProps'] ?? [];
			$service_details = $ext_props['serviceDetails'] ?? [];

			foreach ( $dimensions as $dim_slug => $selected_ids ) {
				if ( empty( $selected_ids ) ) {
					continue;
				}

				$js_key    = str_replace( 'clisyc_', '', $dim_slug );
				$detail_id = null;

				if ( isset( $service_details[ $js_key . '_id' ] ) ) {
					$detail_id = $service_details[ $js_key . '_id' ];
				} elseif ( isset( $service_details[ $js_key ] ) ) {
					$detail_id = $service_details[ $js_key ];
				} elseif ( isset( $ext_props[ $dim_slug ] ) ) {
					$detail_id = $ext_props[ $dim_slug ];
				}

				if ( $detail_id !== null ) {
					$detail_id = absint( $detail_id );
					if ( ! in_array( $detail_id, $selected_ids, true ) ) {
						$matches_all = false;
						break;
					}
				}
			}

			if ( $matches_all ) {
				$filtered[] = $slot;
			}
		}

		Debug_Logger::log(
			sprintf(
				'Filtered %d slots down to %d. Dimensions: %s',
				count( $slots ),
				count( $filtered ),
				wp_json_encode( $dimensions )
			),
			'Slots Filter'
		);

		return $filtered;
	}

	/**
	 * Retrieves an array of booked dates for a specific dimension item.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_booked_dates( $request ) {
		$params       = $request->get_params();
		$dimension_id = absint( $params['dimension_id'] );

		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				if ( ! empty( $settings['primary'] ) ) {
					$primary_dim_slug = $slug;
					break;
				}
			}
		}

		if ( ! $primary_dim_slug || get_post_type( $dimension_id ) !== $primary_dim_slug ) {
			return new \WP_Error( 'invalid_dimension', __( 'Invalid primary dimension ID provided.', 'client-sync' ), [ 'status' => 400 ] );
		}

		$booked_dates = [];
		$buffer_days  = (int) get_post_meta( $dimension_id, Constants::META_BUFFER_DAYS, true );
		$capacity     = (int) get_post_meta( $dimension_id, Constants::META_CAPACITY, true ) ?: 1;

		$start_date_param = $params['start'];
		$end_date_param   = $params['end'];

		// ================== APPROACH 1: Query appointments directly ==================
		$appointments = get_posts(
			[
				'post_type'      => Constants::POST_TYPE_APPOINTMENT,
				'post_status'    => [ 'publish', Constants::STATUS_CONFIRMED, 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ],
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to query appointments by dimension. Essential for availability checking.
				'meta_query'     => [
					[
						'key'     => Constants::META_SLOT_DIMENSIONS,
						'value'   => '"' . $primary_dim_slug . '";i:' . $dimension_id . ';',
						'compare' => 'LIKE',
					],
				],
			]
		);

		$bookings_per_date = [];

		// PERFORMANCE FIX: Prime meta cache for all appointments to prevent N+1 queries.
		if ( $appointments ) {
			update_postmeta_cache( wp_list_pluck( $appointments, 'ID' ) );
		}

		foreach ( $appointments as $appointment ) {
			$start_date = get_post_meta( $appointment->ID, Constants::META_START_DATE, true );
			$end_date   = get_post_meta( $appointment->ID, Constants::META_END_DATE, true );

			if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
				try {
					$start = new \DateTime( $start_date );
					$end   = new \DateTime( $end_date );
					$current = clone $start;
					while ( $current < $end ) {
						$date_str = $current->format( 'Y-m-d' );
						if ( ! isset( $bookings_per_date[ $date_str ] ) ) {
							$bookings_per_date[ $date_str ] = 0;
						}
						$bookings_per_date[ $date_str ]++;
						$current->modify( '+1 day' );
					}
					if ( $buffer_days > 0 ) {
						$buffer_start = clone $end;
						for ( $i = 0; $i < $buffer_days; $i++ ) {
							$date_str = $buffer_start->format( 'Y-m-d' );
							if ( ! isset( $bookings_per_date[ $date_str ] ) ) {
								$bookings_per_date[ $date_str ] = 0;
							}
							$bookings_per_date[ $date_str ]++;
							$buffer_start->modify( '+1 day' );
						}
					}
				} catch ( \Exception $e ) {
					continue;
				}
			} else {
				$time_slot = get_post_meta( $appointment->ID, Constants::META_TIME_SLOT, true );
				if ( ! empty( $time_slot ) ) {
					try {
						$slot_date = new \DateTime( $time_slot );
						$date_str  = $slot_date->format( 'Y-m-d' );
						if ( ! isset( $bookings_per_date[ $date_str ] ) ) {
							$bookings_per_date[ $date_str ] = 0;
						}
						$bookings_per_date[ $date_str ]++;
					} catch ( \Exception $e ) {
						continue;
					}
				}
			}
		}

		// ================== APPROACH 2: Check bookings table directly ==================
		global $wpdb;
		$db_manager     = new Database_Manager();
		$bookings_table = $db_manager->get_bookings_table_name();
		$slots_table    = $db_manager->get_slots_table_name();
		$dims_table     = $db_manager->get_dimensions_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from safely prefixed internal methods.
		$sql = "SELECT s.start_time, s.end_time 
                FROM {$bookings_table} b 
                INNER JOIN {$slots_table} s ON b.slot_id = s.slot_id 
                INNER JOIN {$dims_table} d ON s.slot_id = d.slot_id 
                WHERE d.dimension_key = %s AND d.dimension_value = %d AND s.is_block = 0";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$slot_bookings = $wpdb->get_results( $wpdb->prepare( $sql, $primary_dim_slug, $dimension_id ), ARRAY_A );

		if ( ! empty( $slot_bookings ) ) {
			foreach ( $slot_bookings as $booking ) {
				try {
					$start   = new \DateTime( $booking['start_time'] );
					$end     = new \DateTime( $booking['end_time'] );
					$current = clone $start;
					while ( $current < $end ) {
						$date_str = $current->format( 'Y-m-d' );
						if ( ! isset( $bookings_per_date[ $date_str ] ) ) {
							$bookings_per_date[ $date_str ] = 0;
						}
						if ( $bookings_per_date[ $date_str ] < $capacity ) {
							$bookings_per_date[ $date_str ]++;
						}
						$current->modify( '+1 day' );
					}
					if ( $buffer_days > 0 ) {
						$buffer_start = clone $end;
						for ( $i = 0; $i < $buffer_days; $i++ ) {
							$date_str = $buffer_start->format( 'Y-m-d' );
							if ( ! isset( $bookings_per_date[ $date_str ] ) ) {
								$bookings_per_date[ $date_str ] = 0;
							}
							$bookings_per_date[ $date_str ]++;
							$buffer_start->modify( '+1 day' );
						}
					}
				} catch ( \Exception $e ) {
					continue;
				}
			}
		}

		foreach ( $bookings_per_date as $date_str => $count ) {
			if ( $count >= $capacity ) {
				$booked_dates[] = $date_str;
			}
		}

		$response = new \WP_REST_Response( array_values( array_unique( $booked_dates ) ), 200 );
		$response->header( 'Cache-Control', 'public, max-age=60' );

		return $response;
	}
}