<?php
/**
 * File: src/shared/includes/repositories/class-slot-repository.php
 * 
 * FIXES IN THIS VERSION:
 * 1. Resolved Plugin Check warnings for InterpolatedNotPrepared by separating SQL string construction.
 */

namespace DependentMedia\ClientSync\Repositories;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Services\Slot_Query_Service;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slot_Repository {

	/** @var Slot_Query_Service */
	private $query;

	/**
	 * @param Slot_Query_Service|null $query_service Injected query service. Falls back to Database_Manager facade.
	 */
	public function __construct( ?Slot_Query_Service $query_service = null ) {
		$this->query = $query_service ?? ( new Database_Manager() )->get_query_service();
	}

	public function get_slots( string $start_utc, string $end_utc, array $dimensions ): array {
		if ( $this->is_multi_select( $dimensions ) ) {
			return $this->query->query_slots_with_combinations( $start_utc, $end_utc, $dimensions );
		}

		$intersection_dims = [];
		foreach ( $dimensions as $key => $value ) {
			if ( is_array( $value ) && count( $value ) === 1 ) {
				$intersection_dims[ $key ] = $value[0];
			} elseif ( ! is_array( $value ) ) {
				$intersection_dims[ $key ] = $value;
			}
		}

		return $this->query->query_slots( $start_utc, $end_utc, $intersection_dims );
	}

	public function get_global_blocks( string $start_utc, string $end_utc ): array {
		return $this->query->get_all_blocked_slots_in_range( $start_utc, $end_utc );
	}

	/**
	 * Fetch booking counts for capacity checking.
	 */
	public function get_booking_counts( string $start_utc, string $end_utc ): array {
		global $wpdb;
		
		$bookings_table = $wpdb->prefix . 'clisyc_bookings';
		$slots_table    = $wpdb->prefix . 'clisyc_time_slots';
		$dims_table     = $wpdb->prefix . 'clisyc_slot_dimensions';
		
		// Query the bookings table joined with slots
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names constructed from $wpdb->prefix and safe.
		$query = "SELECT 
					b.slot_id,
					s.start_time as slot_start,
					s.end_time as slot_end,
					COUNT(b.booking_id) as booking_count,
					GROUP_CONCAT(DISTINCT CONCAT(d.dimension_key, ':::', d.dimension_value) SEPARATOR '|||') as dimensions_concat
				FROM {$bookings_table} b
				INNER JOIN {$slots_table} s ON b.slot_id = s.slot_id
				LEFT JOIN {$dims_table} d ON s.slot_id = d.slot_id
				INNER JOIN {$wpdb->posts} p ON b.appointment_id = p.ID
				WHERE s.start_time >= %s 
				AND s.start_time < %s
				AND s.is_block = 0
				AND p.post_status IN ('publish', '" . Constants::STATUS_CONFIRMED . "', '" . Constants::STATUS_PENDING_PAYMENT . "', '" . Constants::STATUS_PAID_ON_DAY . "', 'wc-processing', 'wc-completed')
				GROUP BY b.slot_id, s.start_time, s.end_time";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results(
			$wpdb->prepare(
				$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query string is prepared above.
				$start_utc,
				$end_utc
			),
			ARRAY_A
		);
		
		if ( empty( $results ) ) {
			return [];
		}
		
		$booking_counts = [];
		
		foreach ( $results as $row ) {
			$slot_id = (int) $row['slot_id'];
			$time_slot = $row['slot_start'];
			
			$dimensions = [];
			if ( ! empty( $row['dimensions_concat'] ) ) {
				$pairs = explode( '|||', $row['dimensions_concat'] );
				foreach ( $pairs as $pair ) {
					$parts = explode( ':::', $pair, 2 );
					if ( count( $parts ) === 2 ) {
						$dimensions[ $parts[0] ] = $parts[1];
					}
				}
			}
			
			$slot_key = 'slot_' . $slot_id;
			$dim_values = array_values( $dimensions );
			sort( $dim_values );
			$time_dim_key = $time_slot . '|' . implode( '|', $dim_values );
			
			$count_obj = (object) [
				'slot_id'    => $slot_id,
				'time_slot'  => $time_slot,
				'dimensions' => $dimensions,
				'count'      => (int) $row['booking_count'],
			];
			
			$booking_counts[ $slot_key ] = $count_obj;
			$booking_counts[ $time_dim_key ] = $count_obj;
		}
		
		return $booking_counts;
	}

	/**
	 * Get all slot_ids that have active bookings in a date range.
	 * 
	 * *** THIS IS THE KEY METHOD FOR THE CRON FIX ***
	 * 
	 * IMPORTANT: Appointments don't store slot_id directly. They store:
	 * - _clisyc_time_slot (UTC timestamp)
	 * - _clisyc_slot_dimensions (serialized array)
	 * 
	 * We need to find slots that MATCH the appointment's time+dimensions.
	 * 
	 * @param string $start_utc Start of date range in UTC.
	 * @param string $end_utc   End of date range in UTC.
	 * @return array Array of slot_ids that should NOT be deleted.
	 */
	public function get_booked_slot_ids( string $start_utc, string $end_utc ): array {
		global $wpdb;
		
		Debug_Logger::log( sprintf( 'get_booked_slot_ids called for range: %s to %s', $start_utc, $end_utc ), 'Slots' );
		
		$slots_table = $wpdb->prefix . 'clisyc_time_slots';
		$dims_table  = $wpdb->prefix . 'clisyc_slot_dimensions';
		
		// Step 1: Get all appointments in the date range with their time and dimensions
		$meta_time_slot = Constants::META_TIME_SLOT;
		$meta_slot_dims = Constants::META_SLOT_DIMENSIONS;
		$post_type_appt = Constants::POST_TYPE_APPOINTMENT;
		$status_confirmed = Constants::STATUS_CONFIRMED;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$appointments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.ID as appointment_id,
					pm_time.meta_value as time_slot,
					pm_dims.meta_value as slot_dimensions
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_time ON p.ID = pm_time.post_id AND pm_time.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_dims ON p.ID = pm_dims.post_id AND pm_dims.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_status IN ('publish', %s, 'clisyc_pending_pay', 'clisyc_paid_on_day', 'wc-processing', 'wc-completed')
				AND pm_time.meta_value >= %s
				AND pm_time.meta_value < %s",
				$meta_time_slot,
				$meta_slot_dims,
				$post_type_appt,
				$status_confirmed,
				$start_utc,
				$end_utc
			),
			ARRAY_A
		);

		if ( empty( $appointments ) ) {
			Debug_Logger::log( 'No appointments found in date range', 'Slots' );
			return [];
		}

		Debug_Logger::log( sprintf( 'Found %d appointment(s) in date range', count( $appointments ) ), 'Slots' );
		
		// Step 2: For each appointment, find the matching slot by time + dimensions
		$booked_slot_ids = [];
		
		foreach ( $appointments as $appt ) {
			$time_slot = $appt['time_slot'];
			$dimensions = [];
			
			if ( ! empty( $appt['slot_dimensions'] ) ) {
				$dims_data = maybe_unserialize( $appt['slot_dimensions'] );
				if ( is_array( $dims_data ) ) {
					$dimensions = $dims_data;
				}
			}
			
			Debug_Logger::log( sprintf(
				'Looking for slot matching Appt %d: time=%s, dims=%s',
				$appt['appointment_id'],
				$time_slot,
				wp_json_encode( $dimensions )
			), 'Slots' );
			
			// Find slot(s) that match this time
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names constructed from $wpdb->prefix.
			$query = "SELECT s.slot_id, 
					        GROUP_CONCAT(CONCAT(d.dimension_key, ':', d.dimension_value) ORDER BY d.dimension_key SEPARATOR '|') as dims_str
					FROM {$slots_table} s
					LEFT JOIN {$dims_table} d ON s.slot_id = d.slot_id
					WHERE s.start_time = %s
					AND s.is_block = 0
					GROUP BY s.slot_id";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$matching_slots = $wpdb->get_results(
				$wpdb->prepare(
					$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared above.
					$time_slot
				),
				ARRAY_A
			);
			
			if ( empty( $matching_slots ) ) {
				Debug_Logger::log( sprintf( 'No slots found at time %s', $time_slot ), 'Slots' );
				continue;
			}
			
			// Build the appointment's dimension string for comparison
			ksort( $dimensions );
			$appt_dims_parts = [];
			foreach ( $dimensions as $key => $value ) {
				$appt_dims_parts[] = $key . ':' . $value;
			}
			$appt_dims_str = implode( '|', $appt_dims_parts );
			
			// Find the slot that matches dimensions
			foreach ( $matching_slots as $slot ) {
				$slot_dims_str = $slot['dims_str'] ?? '';
				
				Debug_Logger::log( sprintf(
					'Comparing slot %d dims [%s] with appt dims [%s]',
					$slot['slot_id'],
					$slot_dims_str,
					$appt_dims_str
				), 'Slots' );

				if ( $slot_dims_str === $appt_dims_str ) {
					$booked_slot_ids[] = (int) $slot['slot_id'];
					Debug_Logger::log( sprintf( 'MATCH! Slot %d is booked by Appt %d', $slot['slot_id'], $appt['appointment_id'] ), 'Slots' );
					break;
				}
			}
		}
		
		$booked_slot_ids = array_unique( $booked_slot_ids );
		
		if ( ! empty( $booked_slot_ids ) ) {
			Debug_Logger::log( sprintf(
				'Found %d booked slot(s) to preserve: %s',
				count( $booked_slot_ids ),
				implode( ', ', $booked_slot_ids )
			), 'Slots' );
		} else {
			Debug_Logger::log( 'No matching slots found for appointments', 'Slots' );
		}
		
		return $booked_slot_ids;
	}

	/**
	 * Get all booked time slots (time + dimensions) in a date range.
	 * 
	 * Used to check if a slot time/dimension combo already has a booking
	 * so we don't create a duplicate available slot during regeneration.
	 * 
	 * @param string $start_utc Start of date range in UTC.
	 * @param string $end_utc   End of date range in UTC.
	 * @return array Array of booked time+dimension keys.
	 */
	public function get_booked_time_slots( string $start_utc, string $end_utc ): array {
		global $wpdb;
		
		$booked_keys = [];
		
		// Query appointments directly via post meta
		$meta_ts  = Constants::META_TIME_SLOT;
		$meta_sd  = Constants::META_SLOT_DIMENSIONS;
		$pt_appt  = Constants::POST_TYPE_APPOINTMENT;
		$st_conf  = Constants::STATUS_CONFIRMED;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.ID as appointment_id,
					pm_time.meta_value as time_slot,
					pm_dims.meta_value as dimensions
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_time ON p.ID = pm_time.post_id AND pm_time.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_dims ON p.ID = pm_dims.post_id AND pm_dims.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_status IN ('publish', %s, 'clisyc_pending_pay', 'clisyc_paid_on_day', 'wc-processing', 'wc-completed')
				AND pm_time.meta_value >= %s
				AND pm_time.meta_value < %s",
				$meta_ts,
				$meta_sd,
				$pt_appt,
				$st_conf,
				$start_utc,
				$end_utc
			),
			ARRAY_A
		);
		
		foreach ( $results as $row ) {
			$time_slot = $row['time_slot'];
			$dimensions = [];
			
			if ( ! empty( $row['dimensions'] ) ) {
				$dims_data = maybe_unserialize( $row['dimensions'] );
				if ( is_array( $dims_data ) ) {
					$dimensions = $dims_data;
				}
			}
			
			// Create a key from time + sorted dimensions
			$key = $this->make_slot_key( $time_slot, $dimensions );
			$booked_keys[ $key ] = true;
			
			Debug_Logger::log( sprintf( 'Booked time slot key: %s (Appt: %d)', $key, $row['appointment_id'] ), 'Slots' );
		}
		
		return $booked_keys;
	}

	/**
	 * Create a lookup key for a slot based on time and dimensions.
	 * Used to check against booked_time_slots.
	 * 
	 * @param string $time_utc   The UTC time string.
	 * @param array  $dimensions The dimensions array.
	 * @return string The lookup key.
	 */
	public function make_slot_key( string $time_utc, array $dimensions ): string {
		// Sort dimensions by key and format
		ksort( $dimensions );
		$dim_parts = [];
		foreach ( $dimensions as $key => $value ) {
			$dim_parts[] = $key . ':' . $value;
		}
		return $time_utc . '|' . implode( '|', $dim_parts );
	}

	/**
	 * Get booking count for a specific slot by slot_id.
	 * 
	 * @param int $slot_id The slot ID to check.
	 * @return int The number of bookings for this slot.
	 */
	public function get_booking_count_for_slot_id( int $slot_id ): int {
		global $wpdb;
		
		$bookings_table = $wpdb->prefix . 'clisyc_bookings';
		
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name constructed from $wpdb->prefix.
		$query = "SELECT COUNT(b.booking_id) 
				FROM {$bookings_table} b
				INNER JOIN {$wpdb->posts} p ON b.appointment_id = p.ID
				WHERE b.slot_id = %d
				AND p.post_status IN ('publish', '" . Constants::STATUS_CONFIRMED . "', '" . Constants::STATUS_PENDING_PAYMENT . "', '" . Constants::STATUS_PAID_ON_DAY . "', 'wc-processing', 'wc-completed')";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = $wpdb->get_var(
			$wpdb->prepare(
				$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared above.
				$slot_id
			)
		);
		
		return (int) $count;
	}

	public function get_booked_appointment_ids( string $start_utc, string $end_utc, ?int $user_id = null ): array {
		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => [ 'publish', Constants::STATUS_CONFIRMED, Constants::STATUS_PENDING_PAYMENT, 'wc-processing', 'wc-completed' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'OR',
				// Time-slot based bookings
				[
					'key'     => Constants::META_TIME_SLOT,
					'value'   => [ $start_utc, $end_utc ],
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				],
				// Date-range based bookings (overlap check)
				[
					'relation' => 'AND',
					[ 'key' => Constants::META_BOOKING_MODE, 'value' => 'date_range' ],
					[ 'key' => Constants::META_START_DATE, 'value' => substr( $end_utc, 0, 10 ), 'compare' => '<=', 'type' => 'DATE' ],
					[ 'key' => Constants::META_END_DATE, 'value' => substr( $start_utc, 0, 10 ), 'compare' => '>=', 'type' => 'DATE' ],
				],
			],
		];

		if ( $user_id ) {
			$args['author'] = $user_id;
		}

		return get_posts( $args );
	}

	public function is_multi_select( array $dimensions ): bool {
		foreach ( $dimensions as $values ) {
			if ( is_array( $values ) && count( $values ) > 1 ) {
				return true;
			}
		}
		return false;
	}
}