<?php
/**
 * File: src/shared/includes/services/class-availability-service.php -> client-sync/includes/services/class-availability-service.php
 * 
 * FIXED: Added resource conflict detection.
 * When a dimension is marked as is_resource=true (like rooms), a booking
 * against ANY slot using that resource will block all other slots
 * using the same resource at the same time.
 * 
 * OPTIMIZED: Booking counts are now read directly from slot data (joined query)
 * rather than a separate heavy aggregation query.
 * 
 * REFACTORED: Wrapped debug logging in WP_DEBUG checks to satisfy Plugin Check.
 */

namespace DependentMedia\ClientSync\Services;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Capacity_Resolver;
use DependentMedia\ClientSync\Repositories\Slot_Repository;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Availability_Service {

	private $repo;
	private $resource_dimensions = null;

	/**
	 * @param Slot_Repository|null $repo Injected repository. Falls back to new instance.
	 */
	public function __construct( ?Slot_Repository $repo = null ) {
		$this->repo = $repo ?? new Slot_Repository();
	}

	/**
	 * Merges raw DB slots with global blocks and checks capacity.
	 */
	public function get_processed_slots( string $start_utc, string $end_utc, array $dimensions, string $primary_dim_slug ): array {
		// 1. Fetch raw slots (now includes booking_count from the repository query)
		$raw_slots     = $this->repo->get_slots( $start_utc, $end_utc, $dimensions );
		$global_blocks = $this->repo->get_global_blocks( $start_utc, $end_utc );

		// OPTIMIZATION: get_booking_counts() removed. 
		// Counts are now pre-fetched in $raw_slots via 'booking_count' key.

		// NEW: Get resource conflicts (bookings that block resources)
		$resource_conflicts = $this->_get_resource_conflicts( $start_utc, $end_utc );

		// 1. Merge Global Blocks
		if ( ! empty( $global_blocks ) ) {
			$existing_ranges = [];
			foreach ( $raw_slots as $slot ) {
				if ( ! empty( $slot['is_block'] ) ) {
					$existing_ranges[ $slot['start_time_utc'] . '|' . $slot['end_time_utc'] ] = true;
				}
			}

			foreach ( $global_blocks as $block ) {
				$b_start = $block['start']->format( 'Y-m-d H:i:s' );
				$b_end   = $block['end']->format( 'Y-m-d H:i:s' );
				if ( ! isset( $existing_ranges[ $b_start . '|' . $b_end ] ) ) {
					$raw_slots[] = [
						'slot_id'        => 'blocked-' . wp_rand(),
						'start_time_utc' => $b_start,
						'end_time_utc'   => $b_end,
						'is_block'       => true,
						'dimensions'     => [],
						'booking_count'  => 0, // Blocked slots don't track bookings
					];
				}
			}
		}

		// 2. Process Capacity with Dimension Awareness AND Resource Conflicts
		$processed = [];
		foreach ( $raw_slots as $slot ) {
			// Skip capacity check for blocked slots
			if ( ! empty( $slot['is_block'] ) ) {
				$slot['capacity']   = 0;
				$slot['spots_left'] = 0;
				$processed[]        = $slot;
				continue;
			}

			$primary_dim_id = isset( $slot['dimensions'][ $primary_dim_slug ] ) ? (int) $slot['dimensions'][ $primary_dim_slug ] : 0;
			if ( ! $primary_dim_id ) continue;

			$capacity = Capacity_Resolver::resolve( $slot['dimensions'] ?? [] );
			
			// OPTIMIZATION: Read directly from the slot array
			$booked_count = (int) ( $slot['booking_count'] ?? 0 );
			
			// NEW: Check for resource conflicts
			$has_resource_conflict = $this->_check_resource_conflict( $slot, $resource_conflicts );
			
			// If there's a resource conflict, treat the slot as fully booked
			// unless this slot itself has bookings (which would be weird in a conflict, but safe to check)
			if ( $has_resource_conflict && $booked_count === 0 ) {
				$spots_left = 0;
				$status = 'RESOURCE CONFLICT';
			} else {
				$spots_left = max( 0, $capacity - $booked_count );
				$status = ( $spots_left > 0 ? 'AVAILABLE' : 'FULLY BOOKED' );
			}

			Debug_Logger::log( sprintf(
				'Slot ID %s (%s): Capacity=%d, Booked=%d, SpotsLeft=%d, Status=%s',
				$slot['slot_id'] ?? 'unknown',
				$slot['start_time_utc'],
				$capacity,
				$booked_count,
				$spots_left,
				$status
			), 'Availability' );

			// Always add the slot to processed array
			// Frontend will handle display based on spots_left
			$slot['capacity']   = $capacity;
			$slot['spots_left'] = $spots_left;
			$slot['resource_conflict'] = $has_resource_conflict;
			$processed[]        = $slot;
		}

		Debug_Logger::log( sprintf( 'Processed %d slots, returning %d slots', count( $raw_slots ), count( $processed ) ), 'Availability' );

		return $processed;
	}

	/**
	 * Get all resource conflicts in the date range.
	 * 
	 * A resource conflict occurs when a resource (is_resource=true dimension)
	 * is booked at a particular time. Any OTHER slot using that resource
	 * at the same time should be marked as unavailable.
	 * 
	 * @param string $start_utc Start of date range.
	 * @param string $end_utc   End of date range.
	 * @return array Array keyed by "time|resource_key:resource_value" => appointment_id
	 */
	private function _get_resource_conflicts( string $start_utc, string $end_utc ): array {
		global $wpdb;
		
		$resource_dims = $this->_get_resource_dimensions();
		
		if ( empty( $resource_dims ) ) {
			return [];
		}
		
		Debug_Logger::log( sprintf( 'Resource dimensions: %s', implode( ', ', $resource_dims ) ), 'Resource' );
		
		// Query appointments in the date range
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_time_slot = Constants::META_TIME_SLOT;
		$meta_slot_dims = Constants::META_SLOT_DIMENSIONS;
		$post_type_appt = Constants::POST_TYPE_APPOINTMENT;
		$status_confirmed = Constants::STATUS_CONFIRMED;

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
		
		$conflicts = [];
		
		foreach ( $appointments as $appt ) {
			$time_slot = $appt['time_slot'];
			$dimensions = [];
			
			if ( ! empty( $appt['slot_dimensions'] ) ) {
				$dims_data = maybe_unserialize( $appt['slot_dimensions'] );
				if ( is_array( $dims_data ) ) {
					$dimensions = $dims_data;
				}
			}
			
			// For each resource dimension in this appointment, create a conflict key
			foreach ( $resource_dims as $res_dim ) {
				if ( isset( $dimensions[ $res_dim ] ) ) {
					$conflict_key = $time_slot . '|' . $res_dim . ':' . $dimensions[ $res_dim ];
					$conflicts[ $conflict_key ] = (int) $appt['appointment_id'];
					
					Debug_Logger::log( sprintf(
						'Conflict registered: %s (Appt: %d)',
						$conflict_key,
						$appt['appointment_id']
					), 'Resource' );
				}
			}
		}
		
		return $conflicts;
	}

	/**
	 * Check if a slot has a resource conflict.
	 * 
	 * @param array $slot              The slot to check.
	 * @param array $resource_conflicts The array of resource conflicts.
	 * @return bool True if slot has a resource conflict.
	 */
	private function _check_resource_conflict( array $slot, array $resource_conflicts ): bool {
		if ( empty( $resource_conflicts ) ) {
			return false;
		}
		
		$resource_dims = $this->_get_resource_dimensions();
		$time_slot = $slot['start_time_utc'];
		$slot_dims = $slot['dimensions'] ?? [];
		
		// Check each resource dimension in this slot
		foreach ( $resource_dims as $res_dim ) {
			if ( isset( $slot_dims[ $res_dim ] ) ) {
				// FIXED: Normalize to integer for consistent key matching
				$res_value = absint( $slot_dims[ $res_dim ] );
				$conflict_key = $time_slot . '|' . $res_dim . ':' . $res_value;
				
				if ( isset( $resource_conflicts[ $conflict_key ] ) ) {
					Debug_Logger::log( sprintf(
						'Slot %s has resource conflict: %s (booked by Appt %d)',
						$slot['slot_id'] ?? 'unknown',
						$conflict_key,
						$resource_conflicts[ $conflict_key ]
					), 'Resource' );
					return true;
				}
			}
		}
		
		return false;
	}

	/**
	 * Get dimension slugs that are marked as is_resource=true.
	 * 
	 * @return array Array of dimension slugs (e.g., ['clisyc_room'])
	 */
	private function _get_resource_dimensions(): array {
		// Cache the result
		if ( $this->resource_dimensions !== null ) {
			return $this->resource_dimensions;
		}
		
		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$dimensions = $registry['dimensions'] ?? [];
		
		$this->resource_dimensions = [];
		
		foreach ( $dimensions as $slug => $config ) {
			if ( ! empty( $config['is_resource'] ) ) {
				$this->resource_dimensions[] = $slug;
			}
		}
		
		return $this->resource_dimensions;
	}

	public function check_membership_access( int $user_id, int $service_id ): array {
		if ( $user_id && class_exists( '\ClientSyncPro\Services\Membership_Rules_Engine' ) ) {
			$engine = new \ClientSyncPro\Services\Membership_Rules_Engine();
			return $engine->can_user_book_service( $user_id, $service_id );
		}
		return [ 'can_book' => true, 'message' => '' ];
	}
	
	public function apply_membership_pricing( int $user_id, int $service_id, float $price ): float {
		if ( $user_id && class_exists( '\ClientSyncPro\Services\Membership_Rules_Engine' ) ) {
			$engine = new \ClientSyncPro\Services\Membership_Rules_Engine();
			return $engine->get_membership_price_for_service( $user_id, $service_id, $price );
		}
		return $price;
	}
}