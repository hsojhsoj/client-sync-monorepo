<?php
/**
 * File: class-timeline-controller.php
 * REST API Controller for Timeline Appointments
 * Location: src/shared/includes/rest-api/class-timeline-controller.php
 *
 * FIXED: dimension_value is VARCHAR - must query as strings, not integers
 * FIXED: Added is_schedule flag for Open Hours blocks
 * FIXED: Properly detect "Always Available" based on dimension registry settings
 * FIXED: Primary dimensions now properly check for schedules (was ignoring service schedules)
 *
 * @package    ClientSync
 * @subpackage ClientSync/RestAPI
 */

namespace DependentMedia\ClientSync\RestAPI;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;
use DependentMedia\ClientSync\Utility\Security_Helper;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Timeline_Controller extends WP_REST_Controller {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->namespace = 'clisyc/v1';
		$this->rest_base = 'timeline-appointments';
	}

	/**
	 * Register routes
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_timeline_appointments' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
			]
		);
	}

	/**
	 * Check permissions for getting items.
	 *
	 * Requires 'edit_posts' so that only editors/admins can view the
	 * timeline, which may expose client names and appointment data.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'You do not have permission to view timeline appointments.', 'client-sync' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Get timeline appointments
	 */
	public function get_timeline_appointments( $request ) {
		$rate_check = Security_Helper::check_rate_limit( 'timeline', Constants::RATE_LIMIT_TIMELINE, Constants::RATE_LIMIT_WINDOW_SECS );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$start_date = $request->get_param( 'start' );
		$end_date   = $request->get_param( 'end' );
		$resources  = $request->get_param( 'resources' );
		$page       = max( 1, (int) $request->get_param( 'page' ) );
		$per_page   = min( 500, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 200 ) ) );

		if ( empty( $start_date ) || empty( $end_date ) ) {
			return new WP_Error(
				'missing_params',
				esc_html__( 'Start and end dates are required.', 'client-sync' ),
				[ 'status' => 400 ]
			);
		}

		$resource_ids = [];
		if ( ! empty( $resources ) ) {
			$resource_ids = array_map( 'intval', explode( ',', $resources ) );
		}

		$appointments    = $this->fetch_appointments( $start_date, $end_date, $resource_ids );
		$dimension_types = $this->get_dimension_types_summary( $resource_ids );
		$total           = count( $appointments );
		$total_pages     = max( 1, (int) ceil( $total / $per_page ) );

		// Paginate the results.
		$offset       = ( $page - 1 ) * $per_page;
		$paged_items  = array_slice( $appointments, $offset, $per_page );

		$response = new WP_REST_Response(
			[
				'appointments'    => $paged_items,
				'dimension_types' => $dimension_types,
				'meta'            => [
					'total'       => $total,
					'total_pages' => $total_pages,
					'page'        => $page,
					'per_page'    => $per_page,
					'start'       => $start_date,
					'end'         => $end_date,
					'resources'   => $resource_ids,
				],
			],
			200
		);

		// Standard WP REST pagination headers.
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $total_pages );

		// Admin-only endpoint — short cache to reduce repeated fetches during navigation.
		$response->header( 'Cache-Control', 'private, max-age=30' );

		return $response;
	}

	/**
	 * Get dimension types summary for the legend
	 */
	private function get_dimension_types_summary( $resource_ids ) {
		$dimension_types = [];
		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$dimensions_config = $registry['dimensions'] ?? [];
		$custom_types = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );

		foreach ( $resource_ids as $resource_id ) {
			$post = get_post( $resource_id );
			if ( ! $post ) continue;

			$post_type = $post->post_type;
			if ( isset( $dimension_types[ $post_type ] ) ) continue;

			$custom_type = $custom_types[ $post_type ] ?? [];
			$cpt_object = get_post_type_object( $post_type );
			
			$cpt_label = $custom_type['singular'] ?? ( $cpt_object ? $cpt_object->labels->singular_name : ucfirst( str_replace( 'clisyc_', '', $post_type ) ) );
			$cpt_label_plural = $custom_type['plural'] ?? ( $cpt_object ? $cpt_object->labels->name : $cpt_label . 's' );

			$dim_config = $dimensions_config[ $post_type ] ?? [];
			$is_resource = ! empty( $dim_config['is_resource'] ) || ! empty( $dim_config['has_schedule'] );
			$is_primary = ! empty( $dim_config['primary'] );

			$dimension_types[ $post_type ] = [
				'slug'          => $post_type,
				'label'         => $cpt_label,
				'label_plural'  => $cpt_label_plural,
				'is_resource'   => $is_resource,
				'is_primary'    => $is_primary,
				'icon'          => $custom_type['icon'] ?? 'dashicons-calendar-alt',
			];
		}

		return array_values( $dimension_types );
	}

	/**
	 * Check if a dimension type supports scheduling
	 * 
	 * FIXED: Now also returns true for PRIMARY dimensions, not just resources.
	 * Primary dimensions (like Services) can have schedules that define when bookings are possible.
	 * 
	 * @param string $dimension_type The dimension type (post type) to check
	 * @return bool True if scheduling is supported for this dimension type
	 */
	private function dimension_type_has_scheduling( $dimension_type ) {
		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$dim_config = $registry['dimensions'][ $dimension_type ] ?? [];
		
		// Dimension has scheduling if:
		// 1. It's marked as a resource (is_resource)
		// 2. It has explicit has_schedule flag
		// 3. It's the PRIMARY dimension (Services typically) - these define the core booking availability
		return ! empty( $dim_config['is_resource'] ) 
			|| ! empty( $dim_config['has_schedule'] ) 
			|| ! empty( $dim_config['primary'] );
	}

	/**
	 * Check if a schedule has any actual time slots defined
	 * 
	 * @param array $schedule_data Decoded schedule JSON data
	 * @return bool True if schedule has at least one time slot
	 */
	private function schedule_has_slots( $schedule_data ) {
		if ( empty( $schedule_data ) || ! is_array( $schedule_data ) ) {
			return false;
		}

		$templates = $schedule_data['templates'] ?? [];
		if ( empty( $templates ) ) {
			return false;
		}

		// Check all templates for any defined slots
		foreach ( $templates as $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}
			foreach ( $template as $day_config ) {
				if ( ! empty( $day_config['slots'] ) && is_array( $day_config['slots'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Fetch all appointment data from database
	 */
	private function fetch_appointments( $start_date, $end_date, $resource_ids = [] ) {
		$appointments = [];

		// 1. Resource Schedule blocks (Open Hours from _clisyc_schedule meta)
		// Also includes "Always Available" for resources without schedules
		$schedule_blocks = $this->get_resource_schedule_blocks( $start_date, $end_date, $resource_ids );
		$appointments = array_merge( $appointments, $schedule_blocks );

		// 2. Available Slots (from clisyc_time_slots database table)
		$available_slots = $this->get_available_slots( $start_date, $end_date, $resource_ids );
		$appointments = array_merge( $appointments, $available_slots );

		// 3. Booked Appointments
		$booked_appointments = $this->get_booked_appointments( $start_date, $end_date, $resource_ids );
		$appointments = array_merge( $appointments, $booked_appointments );

		// 4. Blocked periods
		$blocked_periods = $this->get_blocked_periods( $start_date, $end_date, $resource_ids );
		$appointments = array_merge( $appointments, $blocked_periods );

		return $appointments;
	}

	/**
	 * Get Resource Schedule blocks (Open Hours)
	 * These show when each individual resource is available based on their weekly schedule template
	 * Resources without a schedule (or dimension types without scheduling) are marked as "Always Available"
	 * 
	 * FIXED: Now properly checks dimension registry including PRIMARY flag for Services
	 * FIXED: Individual post schedule meta is now checked even for dimension types that support scheduling
	 */
	private function get_resource_schedule_blocks( $start_date, $end_date, $resource_ids ) {
		$blocks = [];

		$wp_tz = new \DateTimeZone( wp_timezone_string() );

		$period_start = new \DateTime( $start_date, $wp_tz );
		$period_end   = new \DateTime( $end_date, $wp_tz );
		$period_end->modify( '+1 day' );
		$period = new \DatePeriod( $period_start, new \DateInterval('P1D'), $period_end );

		// PERFORMANCE FIX: Prime post and meta caches for all resources before the loop.
		if ( $resource_ids ) {
			_prime_post_caches( array_map( 'intval', $resource_ids ), true, true );
		}

		foreach ( $resource_ids as $resource_id ) {
			$post = get_post( $resource_id );
			if ( ! $post ) {
				continue;
			}

			$resource_color = get_post_meta( $resource_id, Constants::META_COLOR, true ) ?: '#3b82f6';
			$dimension_type = $post->post_type;

			// Check if this dimension TYPE supports scheduling at all
			$type_has_scheduling = $this->dimension_type_has_scheduling( $dimension_type );

			// Get this specific resource's schedule (regardless of dimension type settings)
			// This allows individual items to have schedules even if the type doesn't require them
			$schedule_json = get_post_meta( $resource_id, Constants::META_SCHEDULE, true );
			$schedule_data = null;
			if ( ! empty( $schedule_json ) ) {
				$schedule_data = json_decode( $schedule_json, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					$schedule_data = null;
				}
			}
			$has_actual_slots = $this->schedule_has_slots( $schedule_data );
			
			// If dimension type doesn't support scheduling AND this specific item has no schedule,
			// mark as "Always Available"
			if ( ! $type_has_scheduling && ! $has_actual_slots ) {
				foreach ( $period as $date ) {
					// Create a full-day "Always Available" block for each day
					$start_dt = new \DateTime( $date->format('Y-m-d') . ' 00:00', $wp_tz );
					$end_dt   = new \DateTime( $date->format('Y-m-d') . ' 23:59', $wp_tz );
					
					$blocks[] = [
						'id'                 => 'always-' . $resource_id . '-' . $start_dt->getTimestamp(),
						'title'              => __( 'Always Available', 'client-sync' ),
						'resource_id'        => $resource_id,
						'resource_title'     => $post->post_title,
						'start_time'         => $start_dt->format( 'c' ),
						'end_time'           => $end_dt->format( 'c' ),
						'is_schedule'        => false,
						'is_availability'    => false,
						'is_booked'          => false,
						'is_blocked'         => false,
						'is_always_available' => true,
						'dimension_type'     => $dimension_type,
						'dimension_color'    => $resource_color,
						'time_label'         => __( 'All Day', 'client-sync' ),
						'duration'           => 1440 // 24 hours in minutes
					];
				}
				continue;
			}
			
			// If this specific resource has no schedule slots defined but the type supports scheduling,
			// mark as "Always Available" (the resource hasn't been configured yet)
			if ( ! $has_actual_slots ) {
				foreach ( $period as $date ) {
					$start_dt = new \DateTime( $date->format('Y-m-d') . ' 00:00', $wp_tz );
					$end_dt   = new \DateTime( $date->format('Y-m-d') . ' 23:59', $wp_tz );
					
					$blocks[] = [
						'id'                 => 'always-' . $resource_id . '-' . $start_dt->getTimestamp(),
						'title'              => __( 'Always Available', 'client-sync' ),
						'resource_id'        => $resource_id,
						'resource_title'     => $post->post_title,
						'start_time'         => $start_dt->format( 'c' ),
						'end_time'           => $end_dt->format( 'c' ),
						'is_schedule'        => false,
						'is_availability'    => false,
						'is_booked'          => false,
						'is_blocked'         => false,
						'is_always_available' => true,
						'dimension_type'     => $dimension_type,
						'dimension_color'    => $resource_color,
						'time_label'         => __( 'All Day', 'client-sync' ),
						'duration'           => 1440
					];
				}
				continue;
			}

			// Resource has actual schedule slots - render them as "Open Hours"
			// Get template A (standard schedule) - could be extended to support rotation
			$template = $schedule_data['templates']['A'] ?? [];
			if ( empty( $template ) ) {
				continue;
			}

			foreach ( $period as $date ) {
				$day_index = $date->format( 'w' ); // 0 (Sun) - 6 (Sat)
				
				if ( ! empty( $template[ $day_index ]['slots'] ) ) {
					foreach ( $template[ $day_index ]['slots'] as $slot ) {
						$start_dt = new \DateTime( $date->format('Y-m-d') . ' ' . $slot['start'], $wp_tz );
						$end_dt   = new \DateTime( $date->format('Y-m-d') . ' ' . $slot['end'], $wp_tz );
						
						$duration = ( $end_dt->getTimestamp() - $start_dt->getTimestamp() ) / 60;

						$blocks[] = [
							'id'                 => 'schedule-' . $resource_id . '-' . $start_dt->getTimestamp(),
							'title'              => __( 'Open', 'client-sync' ),
							'resource_id'        => $resource_id,
							'resource_title'     => $post->post_title,
							'start_time'         => $start_dt->format( 'c' ),
							'end_time'           => $end_dt->format( 'c' ),
							'is_schedule'        => true,  // Flag for schedule blocks (Open Hours)
							'is_availability'    => false,
							'is_booked'          => false,
							'is_blocked'         => false,
							'is_always_available' => false,
							'dimension_type'     => $dimension_type,
							'dimension_color'    => $resource_color,
							'time_label'         => $start_dt->format( 'g:i a' ) . ' - ' . $end_dt->format( 'g:i a' ),
							'duration'           => $duration
						];
					}
				}
			}
		}
		
		return $blocks;
	}

	/**
	 * Get Available Slots from custom database tables
	 * These are the actual bookable time slots generated by the cron
	 * 
	 * FIXED: dimension_value is VARCHAR - must use string comparison
	 * FIXED: Table existence check now uses case-insensitive comparison
	 * FIXED: Only select columns that exist in the base schema (slot_id, start_time, end_time, is_block)
	 * FIXED: Added debug logging to help diagnose slot visibility issues
	 */
	private function get_available_slots( $start_date, $end_date, $resource_ids ) {
		global $wpdb;
		$slots = [];

		$table_name = $wpdb->prefix . 'clisyc_time_slots';
		$dimensions_table = $wpdb->prefix . 'clisyc_slot_dimensions';
		$bookings_table = $wpdb->prefix . 'clisyc_bookings';
		
		// Check if tables exist using prepared statement
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$slots_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) );
		if ( empty( $slots_table_exists ) ) {
			Debug_Logger::log( 'Slots table not found: ' . $table_name, 'Timeline' );
			return $slots;
		}
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dims_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $dimensions_table ) );
		if ( empty( $dims_table_exists ) ) {
			Debug_Logger::log( 'Dimensions table not found: ' . $dimensions_table, 'Timeline' );
			return $slots;
		}

		// Check if bookings table exists for availability checking
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bookings_table_exists = ! empty( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $bookings_table ) ) );

		$booked_slots = $this->get_booked_time_slots( $start_date, $end_date );

		$start_mysql = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) );
		$end_mysql = gmdate( 'Y-m-d H:i:s', strtotime( $end_date ) );

		Debug_Logger::log( sprintf( 'Querying slots from %s to %s for resources: %s', $start_mysql, $end_mysql, implode( ',', $resource_ids ) ), 'Timeline' );

		if ( ! empty( $resource_ids ) ) {
			// Convert to strings for VARCHAR comparison
			$resource_ids_str = array_map( 'strval', $resource_ids );
			$placeholders = implode( ',', array_fill( 0, count( $resource_ids_str ), '%s' ) );
			
			// Get slots that match ANY of the resource IDs in their dimensions
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is safely constructed from $wpdb->prefix and escaped. Dynamic placeholders are safely generated.
			$matching_slot_ids = $wpdb->get_col( 
				$wpdb->prepare(
					"SELECT DISTINCT slot_id FROM " . esc_sql( $dimensions_table ) . " WHERE dimension_value IN ($placeholders)",
					...$resource_ids_str
				)
			);

			Debug_Logger::log( sprintf( 'Found %d matching slot IDs from dimensions', count( $matching_slot_ids ) ), 'Timeline' );

			if ( empty( $matching_slot_ids ) ) {
				return $slots;
			}

			$slot_placeholders = implode( ',', array_map( 'intval', $matching_slot_ids ) );
			
			// Query only the base columns that are guaranteed to exist
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely constructed from $wpdb->prefix and escaped. Slot IDs are sanitized with intval().
			$rows = $wpdb->get_results( 
				$wpdb->prepare(
					"SELECT slot_id, start_time, end_time, is_block 
					FROM " . esc_sql( $table_name ) . " 
					WHERE slot_id IN ($slot_placeholders) 
					AND start_time >= %s 
					AND start_time < %s 
					AND is_block = 0
					ORDER BY start_time ASC",
					$start_mysql,
					$end_mysql
				),
				ARRAY_A 
			);
		} else {
			// No resource filter - get all slots in date range
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix and escaped.
			$rows = $wpdb->get_results( 
				$wpdb->prepare(
					"SELECT slot_id, start_time, end_time, is_block 
					FROM " . esc_sql( $table_name ) . " 
					WHERE start_time >= %s 
					AND start_time < %s 
					AND is_block = 0
					ORDER BY start_time ASC",
					$start_mysql,
					$end_mysql
				),
				ARRAY_A 
			);
		}

		Debug_Logger::log( sprintf( 'Found %d slot rows in date range', count( $rows ) ), 'Timeline' );

		if ( empty( $rows ) ) {
			return $slots;
		}

		// Get booked slot IDs from bookings table if it exists
		$booked_slot_ids = [];
		if ( $bookings_table_exists && ! empty( $rows ) ) {
			$slot_ids_to_check = array_column( $rows, 'slot_id' );
			$slot_ids_placeholders = implode( ',', array_map( 'intval', $slot_ids_to_check ) );
			
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely constructed from $wpdb->prefix and escaped. Slot IDs are sanitized with intval().
			$booked_slot_ids = $wpdb->get_col(
				"SELECT DISTINCT slot_id FROM " . esc_sql( $bookings_table ) . " WHERE slot_id IN ($slot_ids_placeholders)"
			);
			$booked_slot_ids = array_map( 'intval', $booked_slot_ids );
			
			Debug_Logger::log( sprintf( 'Found %d booked slot IDs from bookings table', count( $booked_slot_ids ) ), 'Timeline' );
		}

		foreach ( $rows as $row ) {
			$slot_id = (int) $row['slot_id'];
			
			// Get dimensions for this slot
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix and escaped.
			$dimensions_rows = $wpdb->get_results( 
				$wpdb->prepare(
					"SELECT dimension_key, dimension_value FROM " . esc_sql( $dimensions_table ) . " WHERE slot_id = %d",
					$slot_id
				),
				ARRAY_A 
			);
			
			$dimensions = [];
			foreach ( $dimensions_rows as $dim_row ) {
				$dimensions[ $dim_row['dimension_key'] ] = $dim_row['dimension_value'];
			}

			// Check if slot is booked via bookings table
			$is_booked_in_table = in_array( $slot_id, $booked_slot_ids, true );
			
			// Check if slot is booked via appointment meta (legacy check)
			$start_slot_utc = $row['start_time'];
			$is_booked_by_appointment = in_array( $start_slot_utc, $booked_slots, true );
			
			if ( $is_booked_in_table || $is_booked_by_appointment ) {
				Debug_Logger::log( sprintf( 'Slot %d skipped: booked_in_table=%s, booked_by_appt=%s', $slot_id, $is_booked_in_table ? 'yes' : 'no', $is_booked_by_appointment ? 'yes' : 'no' ), 'Timeline' );
				continue;
			}

			$start_time = new \DateTime( $row['start_time'], new \DateTimeZone( 'UTC' ) );
			$end_time   = new \DateTime( $row['end_time'], new \DateTimeZone( 'UTC' ) );
			
			$wp_tz = new \DateTimeZone( wp_timezone_string() );
			$start_time->setTimezone( $wp_tz );
			$end_time->setTimezone( $wp_tz );

			$time_label = $start_time->format( 'g:i a' ) . ' - ' . $end_time->format( 'g:i a' );
			$duration   = ( $end_time->getTimestamp() - $start_time->getTimestamp() ) / 60;

			$dimension_names = [];
			foreach ( $dimensions as $dim_type => $dim_id ) {
				$dim_post = get_post( $dim_id );
				if ( $dim_post ) {
					$dimension_names[ $dim_type ] = $dim_post->post_title;
				}
			}

			// Get matching resources - use NON-STRICT comparison for flexibility
			$matching_resources = $this->get_all_matching_resources( $dimensions, $resource_ids );
			
			if ( empty( $matching_resources ) ) {
				Debug_Logger::log( sprintf( 'Slot %d has no matching resources. Dimensions: %s, Resource IDs: %s', $slot_id, wp_json_encode( $dimensions ), implode( ',', $resource_ids ) ), 'Timeline' );
			}
			
			foreach ( $matching_resources as $res_id ) {
				$resource_post = get_post( $res_id );
				$dimension_type = $resource_post ? $resource_post->post_type : '';
				$dimension_color = get_post_meta( $res_id, Constants::META_COLOR, true ) ?: '#10b981';
				
				$slot_title = __( 'Available', 'client-sync' );

				$slots[] = [
					'id'              => 'slot-' . $slot_id . '-' . $res_id,
					'slot_id'         => $slot_id,
					'title'           => $slot_title,
					'start_time'      => $start_time->format( 'c' ),
					'end_time'        => $end_time->format( 'c' ),
					'resource_id'     => (int) $res_id,
					'is_schedule'     => false,
					'is_availability' => true,
					'is_booked'       => false,
					'is_blocked'      => false,
					'is_always_available' => false,
					'dimension_type'  => $dimension_type,
					'dimension_color' => $dimension_color,
					'capacity'        => 1, // Default capacity
					'booked_count'    => 0, // Default booked count
					'time_label'      => $time_label,
					'duration'        => $duration,
					'dimensions'      => $dimension_names,
				];
			}
		}

		Debug_Logger::log( sprintf( 'Returning %d available slot entries', count( $slots ) ), 'Timeline' );

		return $slots;
	}

	/**
	 * Get Booked Appointments from clisyc_appointment posts
	 */
	private function get_booked_appointments( $start_date, $end_date, $resource_ids ) {
		$appointments = [];

		$start_utc = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) );
		$end_utc   = gmdate( 'Y-m-d H:i:s', strtotime( $end_date ) );

		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => [ 'publish', Constants::STATUS_PENDING, Constants::STATUS_CONFIRMED, 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ],
			'posts_per_page' => 500,
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'     => Constants::META_TIME_SLOT,
					'value'   => [ $start_utc, $end_utc ],
					'type'    => 'DATETIME',
					'compare' => 'BETWEEN',
				],
			],
		];

		$query = new \WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return $appointments;
		}

		// PERFORMANCE FIX: Prime meta cache, user cache, and dimension post cache
		// to prevent N+1 queries in the loop below.
		$post_ids = wp_list_pluck( $query->posts, 'ID' );
		update_postmeta_cache( $post_ids );

		// Collect all client IDs and dimension post IDs for batch priming.
		$client_ids_to_prime    = [];
		$dimension_ids_to_prime = [];
		foreach ( $query->posts as $post ) {
			$client_id = get_post_meta( $post->ID, Constants::META_CLIENT_ID, true );
			if ( $client_id ) {
				$client_ids_to_prime[] = (int) $client_id;
			}
			$dims = get_post_meta( $post->ID, Constants::META_SLOT_DIMENSIONS, true );
			if ( is_array( $dims ) ) {
				foreach ( $dims as $dim_id ) {
					$dimension_ids_to_prime[] = (int) $dim_id;
				}
			}
		}

		// Prime user cache for all clients.
		$client_ids_to_prime = array_unique( array_filter( $client_ids_to_prime ) );
		if ( $client_ids_to_prime ) {
			// Populates WP's internal user cache so get_userdata() won't query.
			$user_query = new \WP_User_Query( [
				'include'     => $client_ids_to_prime,
				'fields'      => 'all',
				'count_total' => false,
			] );
			$user_query->get_results();
		}

		// Prime dimension post cache (also primes resource_ids post cache).
		$all_post_ids_to_prime = array_unique( array_merge( $dimension_ids_to_prime, array_map( 'intval', $resource_ids ) ) );
		if ( $all_post_ids_to_prime ) {
			_prime_post_caches( $all_post_ids_to_prime, true, true );
		}

		foreach ( $query->posts as $post ) {
			$time_slot  = get_post_meta( $post->ID, Constants::META_TIME_SLOT, true );
			$duration   = (int) get_post_meta( $post->ID, '_clisyc_duration', true ) ?: 60;
			$client_id  = get_post_meta( $post->ID, Constants::META_CLIENT_ID, true );
			$dimensions = get_post_meta( $post->ID, Constants::META_SLOT_DIMENSIONS, true ) ?: [];

			$client_name = '';
			if ( $client_id ) {
				$client = get_userdata( $client_id );
				if ( $client ) {
					$client_name = $client->display_name;
				}
			}

			$start_time = new \DateTime( $time_slot, new \DateTimeZone( 'UTC' ) );
			$end_time   = clone $start_time;
			$end_time->modify( "+{$duration} minutes" );

			$dimension_names = [];
			foreach ( $dimensions as $dim_type => $dim_id ) {
				$dim_post = get_post( $dim_id );
				if ( $dim_post ) {
					$dimension_names[ $dim_type ] = $dim_post->post_title;
				}
			}

			$matching_resources = $this->get_all_matching_resources( $dimensions, $resource_ids );

			foreach ( $matching_resources as $resource_id ) {
				$resource_post = get_post( $resource_id );
				$dimension_type = $resource_post ? $resource_post->post_type : '';
				$dimension_color = get_post_meta( $resource_id, Constants::META_COLOR, true ) ?: '#3b82f6';

				$appointments[] = [
					'id'              => $post->ID . '-' . $resource_id,
					'appointment_id'  => $post->ID,
					'title'           => $client_name ?: $post->post_title,
					'start_time'      => $start_time->format( 'c' ),
					'end_time'        => $end_time->format( 'c' ),
					'resource_id'     => $resource_id,
					'client_id'       => $client_id,
					'client_name'     => $client_name,
					'is_schedule'     => false,
					'is_availability' => false,
					'is_booked'       => true,
					'is_blocked'      => false,
					'dimension_type'  => $dimension_type,
					'dimension_color' => $dimension_color,
					'status'          => $post->post_status,
					'duration'        => $duration,
					'time_label'      => $start_time->format( 'g:i a' ) . ' - ' . $end_time->format( 'g:i a' ),
					'dimensions'      => $dimension_names,
					'edit_link'       => get_edit_post_link( $post->ID, 'raw' ),
				];
			}
		}

		return $appointments;
	}

	/**
	 * Get blocked periods
	 * 
	 * Handles two types of blocks:
	 * 1. Global blocks (no dimensions) - apply to ALL resources
	 * 2. Resource-specific blocks (with dimensions) - apply to matching resources only
	 * 
	 * FIXED: Table existence check now uses prepared statement
	 */
	private function get_blocked_periods( $start_date, $end_date, $resource_ids ) {
		global $wpdb;
		$blocks = [];

		$table_name = $wpdb->prefix . 'clisyc_time_slots';
		$dimensions_table = $wpdb->prefix . 'clisyc_slot_dimensions';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$slots_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) );
		if ( empty( $slots_table_exists ) ) {
			return $blocks;
		}

		$start_mysql = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) );
		$end_mysql = gmdate( 'Y-m-d H:i:s', strtotime( $end_date ) );

		// Get ALL blocked slots in the date range (regardless of dimensions)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix and escaped.
		$all_blocks = $wpdb->get_results( 
			$wpdb->prepare(
				"SELECT slot_id, start_time, end_time, is_block FROM " . esc_sql( $table_name ) . " WHERE start_time >= %s AND start_time < %s AND is_block = 1 ORDER BY start_time ASC",
				$start_mysql,
				$end_mysql
			),
			ARRAY_A 
		);

		if ( empty( $all_blocks ) ) {
			return $blocks;
		}

		// Check dimensions table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dimensions_table_exists = ! empty( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $dimensions_table ) ) );

		// PERFORMANCE FIX: Batch-load all dimensions for all blocked slots in ONE query
		// instead of querying per slot_id inside the loop.
		$all_slot_ids       = wp_list_pluck( $all_blocks, 'slot_id' );
		$slot_dimensions    = []; // [ slot_id => [ dim_key => dim_value, ... ] ]
		$dimension_post_ids = [];

		if ( $dimensions_table_exists && ! empty( $all_slot_ids ) ) {
			$id_placeholders = implode( ',', array_fill( 0, count( $all_slot_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$dim_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT slot_id, dimension_key, dimension_value FROM " . esc_sql( $dimensions_table ) . " WHERE slot_id IN ($id_placeholders)",
					...$all_slot_ids
				),
				ARRAY_A
			);

			foreach ( $dim_rows as $dim_row ) {
				$slot_dimensions[ $dim_row['slot_id'] ][ $dim_row['dimension_key'] ] = $dim_row['dimension_value'];
				$dimension_post_ids[] = (int) $dim_row['dimension_value'];
			}

			// Prime post cache for all dimension posts at once.
			$dimension_post_ids = array_unique( array_filter( $dimension_post_ids ) );
			if ( $dimension_post_ids ) {
				_prime_post_caches( $dimension_post_ids, false, false );
			}
		}

		foreach ( $all_blocks as $row ) {
			$dimensions      = $slot_dimensions[ $row['slot_id'] ] ?? [];
			$dimension_names = [];

			foreach ( $dimensions as $dim_type => $dim_id ) {
				$dim_post = get_post( $dim_id );
				if ( $dim_post ) {
					$dimension_names[ $dim_type ] = $dim_post->post_title;
				}
			}

			$start_time = new \DateTime( $row['start_time'], new \DateTimeZone( 'UTC' ) );
			$end_time   = new \DateTime( $row['end_time'], new \DateTimeZone( 'UTC' ) );
			
			$wp_tz = new \DateTimeZone( wp_timezone_string() );
			$start_time->setTimezone( $wp_tz );
			$end_time->setTimezone( $wp_tz );

			$time_label = $start_time->format( 'g:i a' ) . ' - ' . $end_time->format( 'g:i a' );
			$duration   = ( $end_time->getTimestamp() - $start_time->getTimestamp() ) / 60;

			// If block has NO dimensions, it's a GLOBAL block - show on ALL resources
			if ( empty( $dimensions ) ) {
				foreach ( $resource_ids as $resource_id ) {
					$resource_post = get_post( $resource_id );
					$blocks[] = [
						'id'              => 'block-' . $row['slot_id'] . '-' . $resource_id,
						'title'           => __( 'Blocked', 'client-sync' ),
						'resource_id'     => $resource_id,
						'resource_title'  => $resource_post ? $resource_post->post_title : '',
						'start_time'      => $start_time->format( 'c' ),
						'end_time'        => $end_time->format( 'c' ),
						'is_schedule'     => false,
						'is_availability' => false,
						'is_booked'       => false,
						'is_blocked'      => true,
						'is_always_available' => false,
						'dimension_type'  => $resource_post ? $resource_post->post_type : '',
						'dimension_color' => '#ef4444',
						'time_label'      => $time_label,
						'duration'        => $duration,
						'dimensions'      => $dimension_names,
					];
				}
			} else {
				// Block has dimensions - only show on matching resources
				$matching_resources = $this->get_all_matching_resources( $dimensions, $resource_ids );
				
				foreach ( $matching_resources as $resource_id ) {
					$resource_post = get_post( $resource_id );
					$blocks[] = [
						'id'              => 'block-' . $row['slot_id'] . '-' . $resource_id,
						'title'           => __( 'Blocked', 'client-sync' ),
						'resource_id'     => $resource_id,
						'resource_title'  => $resource_post ? $resource_post->post_title : '',
						'start_time'      => $start_time->format( 'c' ),
						'end_time'        => $end_time->format( 'c' ),
						'is_schedule'     => false,
						'is_availability' => false,
						'is_booked'       => false,
						'is_blocked'      => true,
						'is_always_available' => false,
						'dimension_type'  => $resource_post ? $resource_post->post_type : '',
						'dimension_color' => '#ef4444',
						'time_label'      => $time_label,
						'duration'        => $duration,
						'dimensions'      => $dimension_names,
					];
				}
			}
		}
		
		return $blocks;
	}

	/**
	 * Get booked time slots for filtering
	 */
	private function get_booked_time_slots( $start_date, $end_date ) {
		global $wpdb;
		
		$start_utc = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) );
		$end_utc   = gmdate( 'Y-m-d H:i:s', strtotime( $end_date ) );
		
		$meta_ts = Constants::META_TIME_SLOT;
		$pt_appt = Constants::POST_TYPE_APPOINTMENT;
		$st_pend = Constants::STATUS_PENDING;
		$st_conf = Constants::STATUS_CONFIRMED;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE pm.meta_key = %s
			AND p.post_type = %s
			AND p.post_status IN ('publish', %s, %s, 'clisyc_paid_on_day', 'wc-completed', 'wc-processing')
			AND pm.meta_value >= %s
			AND pm.meta_value < %s",
			$meta_ts,
			$pt_appt,
			$st_pend,
			$st_conf,
			$start_utc,
			$end_utc
		) );
		
		return $results ?: [];
	}

	/**
	 * Get ALL matching resource IDs from dimensions
	 * 
	 * FIXED: Use loose comparison to handle string/int type mismatches
	 * The dimension_value column is VARCHAR, so values come as strings
	 * But resource_ids might be integers from the API parsing
	 */
	private function get_all_matching_resources( $dimensions, $resource_ids ) {
		$matching = [];
		
		if ( empty( $resource_ids ) || empty( $dimensions ) ) {
			return $matching;
		}

		// Convert resource_ids to both int and string for flexible matching
		$resource_ids_int = array_map( 'intval', $resource_ids );

		foreach ( $dimensions as $dim_type => $dim_id ) {
			// Handle both string and int dimension values
			$dim_id_int = (int) $dim_id;
			
			// Use non-strict comparison (==) to handle "3413" == 3413
			if ( in_array( $dim_id_int, $resource_ids_int, false ) ) {
				$matching[] = $dim_id_int;
			}
		}

		return array_unique( $matching );
	}

	/**
	 * Override collection params with proper sanitization and validation.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return [
			'start' => [
				'required'          => true,
				'type'              => 'string',
				'description'       => __( 'Start date in Y-m-d or ISO 8601 format.', 'client-sync' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $value ) {
					return (bool) strtotime( $value );
				},
			],
			'end' => [
				'required'          => true,
				'type'              => 'string',
				'description'       => __( 'End date in Y-m-d or ISO 8601 format.', 'client-sync' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $value ) {
					return (bool) strtotime( $value );
				},
			],
			'resources' => [
				'required'          => false,
				'type'              => 'string',
				'description'       => __( 'Comma-separated list of resource (post) IDs.', 'client-sync' ),
				'sanitize_callback' => function ( $value ) {
					// Keep only digits and commas.
					return preg_replace( '/[^0-9,]/', '', $value );
				},
				'validate_callback' => function ( $value ) {
					// Allow empty or comma-separated integers.
					return empty( $value ) || preg_match( '/^[0-9]+(,[0-9]+)*$/', $value );
				},
			],
			'page' => [
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'description'       => __( 'Current page of results.', 'client-sync' ),
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'required'          => false,
				'type'              => 'integer',
				'default'           => 200,
				'minimum'           => 1,
				'maximum'           => 500,
				'description'       => __( 'Maximum number of items per page.', 'client-sync' ),
				'sanitize_callback' => 'absint',
			],
		];
	}
}