<?php
/**
 * File: src/shared/includes/core/class-cron.php -> client-sync/includes/core/class-cron.php
 * Manages all WordPress Cron tasks and automation for the Client Sync plugin.
 *
 * REFACTORED: Implements Batch Processing using Generation_State_Manager to prevent timeouts.
 * Logic is broken down into smaller helper methods for maintainability.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\SlotCalculator;
use DependentMedia\ClientSync\Utility\Service_Helper;
use DependentMedia\ClientSync\Utility\Debug_Logger;
use DependentMedia\ClientSync\Core\Generation_State_Manager;
use DependentMedia\ClientSync\Repositories\Slot_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cron {

	const FREQUENT_MAINTENANCE_HOOK = 'clisyc_frequent_maintenance_tasks';
	const PAY_ON_DAY_HOOK           = 'clisyc_cron_charge_due_payments';
	const INTERVAL_KEY_15_MIN       = 'clisyc_fifteen_minutes';

	private $db;
	private $state_manager;
	private $slot_repo;

	public function __construct( Database_Manager $db_manager ) {
		$this->db = $db_manager;
		// Initialize the state manager for batched processing
		$this->state_manager = new Generation_State_Manager();
		// Initialize slot repository with the query service from the facade
		$this->slot_repo = new Slot_Repository( $db_manager->get_query_service() );
	}

	public function register_hooks() {
		add_filter( 'cron_schedules', [ $this, 'add_custom_cron_intervals' ] );
		add_action( self::FREQUENT_MAINTENANCE_HOOK, [ $this, 'run_frequent_maintenance_tasks' ] );
	}

	public function schedule_cron_jobs() {
		if ( ! wp_next_scheduled( self::FREQUENT_MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time(), self::INTERVAL_KEY_15_MIN, self::FREQUENT_MAINTENANCE_HOOK );
		}

		// Clean up the legacy pay-on-day hook (was registered but never implemented).
		wp_clear_scheduled_hook( self::PAY_ON_DAY_HOOK );
	}

	public function unschedule_cron_jobs() {
		wp_clear_scheduled_hook( self::FREQUENT_MAINTENANCE_HOOK );
		wp_clear_scheduled_hook( self::PAY_ON_DAY_HOOK );
	}

	public function add_custom_cron_intervals( $schedules ): array {
		if ( ! is_array( $schedules ) ) {
			$schedules = [];
		}

		if ( ! isset( $schedules[ self::INTERVAL_KEY_15_MIN ] ) ) {
			$schedules[ self::INTERVAL_KEY_15_MIN ] = [
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => esc_html__( 'Every Fifteen Minutes (Client Sync)', 'client-sync' ),
			];
		}
		return $schedules;
	}

	/**
	 * Main Cron Entry Point
	 * Now handles batched processing logic.
	 */
	public function run_frequent_maintenance_tasks() {
		// 1. Check for stalled processes
		if ( $this->state_manager->is_stalled() ) {
			$this->state_manager->clear_state();
		}

		$start_time = microtime( true );

		// 2. Always run lightweight cleanup
		$this->delete_past_available_slots();

		// 3. Run Generation (Batched)
		// This will return 'complete', 'partial', or error statuses
		$generation_result = $this->auto_generate_slots_batched();

		$end_time = microtime( true );
		$duration = $end_time - $start_time;

		// 4. Post-Processing
		// Only run reminders and smart cache updates if the entire batch cycle is finished.
		if ( isset( $generation_result['status'] ) && 'complete' === $generation_result['status'] ) {
			$this->send_appointment_reminders();
			$this->_update_smart_start_date_cache();
			
			// Clear state now that we are done
			$this->state_manager->clear_state();

			// Log success stats
			$stats_data = [
				'start_time'      => time(),
				'duration'        => $duration, // Note: this is duration of the *last* batch, not total
				'slots_generated' => $generation_result['stats']['inserted'] ?? 0,
				'status'          => 'success',
				'error_message'   => '',
			];
			set_transient( 'clisyc_cron_last_run_stats', $stats_data, WEEK_IN_SECONDS );
			update_option( Constants::OPTION_LAST_MAINTENANCE_TS, time(), 'no' );
			update_option( Constants::OPTION_LAST_SUCCESS_TS, time(), 'no' );
		} elseif ( isset( $generation_result['status'] ) && 'partial' === $generation_result['status'] ) {
			// Partial progress — next cron run will continue.
		} elseif ( is_wp_error( $generation_result ) || ( isset( $generation_result['status'] ) && 'failed' === $generation_result['status'] ) ) {
			// Log failure
			$error_msg = is_wp_error( $generation_result ) ? $generation_result->get_error_message() : 'Unknown error';
			$stats_data = [
				'start_time'      => time(),
				'duration'        => $duration,
				'slots_generated' => 0,
				'status'          => 'failed',
				'error_message'   => $error_msg,
			];
			set_transient( 'clisyc_cron_last_run_stats', $stats_data, WEEK_IN_SECONDS );
			$this->state_manager->clear_state(); // Clear state on fatal error to retry fresh next time
		}
	}

	private function delete_past_available_slots() {
		$this->db->delete_past_available_slots();
	}

	/**
	 * Wraps the generation logic to check options and call the limit-aware generator.
	 */
	private function auto_generate_slots_batched() {
		wp_cache_delete( Constants::OPTION_AUTO_GEN_ENABLED, 'options' );
		wp_cache_delete( Constants::OPTION_AUTO_GEN_LOOKAHEAD, 'options' );

		$is_enabled = (bool) get_option( Constants::OPTION_AUTO_GEN_ENABLED, false );
		if ( ! $is_enabled ) {
			return [ 'inserted' => 0, 'errors' => 0, 'status' => 'disabled' ];
		}

		$lookahead_days = (int) get_option( Constants::OPTION_AUTO_GEN_LOOKAHEAD, 14 );
		if ( $lookahead_days <= 0 ) {
			return [ 'inserted' => 0, 'errors' => 0, 'status' => 'no_lookahead' ];
		}

		$site_timezone   = wp_timezone();
		$today_local     = new \DateTime( 'today', $site_timezone );
		$period_end_date = ( clone $today_local )->modify( "+{$lookahead_days} days" );

		// Run with a time limit (e.g., 20 seconds to avoid timeouts)
		return $this->run_slot_generation_with_limits( $today_local, $period_end_date, 20 );
	}

	/**
	 * NEW: Public method for Action Scheduler to process a single post.
	 * 
	 * @param int $post_id The ID of the primary item (Service).
	 * @param string $end_date_ymd The target date to generate up to (Y-m-d).
	 * @return bool True on success.
	 */
	public function generate_slots_for_single_post( int $post_id, string $end_date_ymd ): bool {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}

		$site_timezone = wp_timezone();
		$utc_timezone  = new \DateTimeZone( 'UTC' );
		$start_date    = new \DateTime( 'today', $site_timezone );
		$end_date      = new \DateTime( $end_date_ymd, $site_timezone );
		
		// 1. Prepare Registry & Context
		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$service_context = $this->prepare_service_context( $post, $post->post_type, $registry );

		if ( empty( $service_context ) ) {
			return false;
		}

		// 2. Prepare Global Data (Blocks/Bookings)
		$global_data = $this->prepare_global_data( $start_date, $end_date );

		// 3. Iterate Days
		$period = new \DatePeriod( $start_date, new \DateInterval( 'P1D' ), ( clone $end_date )->modify( '+1 day' ) );
		
		foreach ( $period as $date_obj_local ) {
			$this->process_single_day( $date_obj_local, $service_context, $global_data['blocks'], $global_data['busy_intervals'], $site_timezone, $utc_timezone );
		}
		return true;
	}

	/**
	 * The core generation loop, refactored for time-bounding and resumption.
	 *
	 * @param \DateTime $start_date_local Start of the generation window (today).
	 * @param \DateTime $end_date_local   End of the generation window (today + lookahead).
	 * @param int       $max_seconds      Execution time limit in seconds.
	 *
	 * @return array|\WP_Error Result array with status and stats.
	 */
	public function run_slot_generation_with_limits( \DateTime $start_date_local, \DateTime $end_date_local, int $max_seconds = 20 ) {
		$exec_start = microtime( true );

		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		if ( empty( $registry['dimensions'] ) ) {
			return new \WP_Error( 'no_dimensions', __( 'Cannot generate slots: No dimensions are configured.', 'client-sync' ) );
		}

		$primary_slug = null;
		foreach ( $registry['dimensions'] as $slug => $settings ) {
			if ( ! empty( $settings['enabled'] ) && ! empty( $settings['primary'] ) ) {
				$primary_slug = $slug;
				break;
			}
		}

		if ( ! $primary_slug ) {
			return new \WP_Error( 'no_primary_dimension', __( 'Cannot generate slots: No primary dimension is set.', 'client-sync' ) );
		}

		// Fetch all primary posts. Order MUST be consistent for resumption.
		$primary_posts = get_posts(
			[
				'post_type'      => $primary_slug,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);

		if ( empty( $primary_posts ) ) {
			return [ 'inserted' => 0, 'errors' => 0, 'status' => 'complete' ];
		}

		// --- LOAD STATE ---
		$state              = $this->state_manager->get_state();
		$current_post_index = $state['current_post_index'];
		$resume_date_str    = $state['current_date_utc']; // Y-m-d format of the last COMPLETED day
		$accumulated_stats  = $state['stats'];

		// Initialize stats if empty
		if ( ! isset( $accumulated_stats['inserted'] ) ) {
			$accumulated_stats = [ 'inserted' => 0, 'errors' => 0 ];
		}

		// Prepare global data (Bookings, Global Blocks)
		// In a massive scale system, fetching all bookings might be optimized further, 
		// but for batching within 15-minute windows, this is generally acceptable.
		$global_data = $this->prepare_global_data( $start_date_local, $end_date_local );
		
		$site_timezone = wp_timezone();
		$utc_timezone  = new \DateTimeZone( 'UTC' );

		// --- MAIN POST LOOP ---
		for ( $i = $current_post_index; $i < count( $primary_posts ); $i ++ ) {
			$primary_post = $primary_posts[ $i ];

			// determine where to start for this specific post
			$current_period_start = clone $start_date_local;

			// If we are resuming the exact post we stopped at, adjust start date
			if ( $i === $current_post_index && ! empty( $resume_date_str ) ) {
				try {
					// Resume date is the LAST COMPLETED date. We start the day AFTER.
					$resume_date = new \DateTime( $resume_date_str, $utc_timezone ); 
					$resume_date->setTimezone( $site_timezone );
					$current_period_start = $resume_date->modify( '+1 day' );
				} catch ( \Exception $e ) {
					// Fallback to start date on error
				}
			}

			// If resume date pushed us past the end of the window, this post is done.
			if ( $current_period_start > $end_date_local ) {
				// Clear resume date for next post to start from beginning
				$resume_date_str = null; 
				continue;
			}

			// Define the period for this post
			$period = new \DatePeriod( $current_period_start, new \DateInterval( 'P1D' ), ( clone $end_date_local )->modify( '+1 day' ) );

			// Prepare Service Context (expensive operations: meta, permutations)
			$service_context = $this->prepare_service_context( $primary_post, $primary_slug, $registry );

			if ( empty( $service_context ) ) {
				continue;
			}

			// --- DATE LOOP ---
			foreach ( $period as $date_obj_local ) {
				// Check Execution Time
				if ( ( microtime( true ) - $exec_start ) > $max_seconds ) {
					// TIME LIMIT REACHED
					// We save the state based on the day BEFORE the current one (which was fully completed).
					// Or simply, we mark the previous iteration as the resume point.
					$last_completed_date = ( clone $date_obj_local )->modify( '-1 day' );
					
					$this->state_manager->save_state(
						$i, // Current Post Index
						$last_completed_date->format( 'Y-m-d' ),
						$accumulated_stats
					);

					return [ 'status' => 'partial', 'stats' => $accumulated_stats ];
				}

				// Process the specific day
				$inserted = $this->process_single_day(
					$date_obj_local,
					$service_context,
					$global_data['blocks'],
					$global_data['busy_intervals'],
					$site_timezone,
					$utc_timezone
				);

				$accumulated_stats['inserted'] += $inserted;
			}

			// Post complete, clear resume date so next post starts from start_date_local
			$resume_date_str = null;
		}

		// If we exit the loop naturally, we are done.
		return [ 'status' => 'complete', 'stats' => $accumulated_stats ];
	}

	/**
	 * Helper: Prepares global blocked periods and busy intervals for the range.
	 */
	private function prepare_global_data( \DateTime $start, \DateTime $end ) {
		$global_blocks_raw = get_option( Constants::OPTION_GLOBAL_BLOCKED_PERIODS, [] );
		$global_blocked_periods = [];
		if ( ! empty( $global_blocks_raw ) && is_array( $global_blocks_raw ) ) {
			foreach ( $global_blocks_raw as $block ) {
				if ( ! empty( $block['start_date'] ) && ! empty( $block['end_date'] ) ) {
					try {
						$global_blocked_periods[] = [
							'start' => new \DateTime( $block['start_date'] . ' 00:00:00' ),
							'end'   => new \DateTime( $block['end_date'] . ' 23:59:59' ),
							'title' => $block['title'] ?? 'Unnamed Block',
						];
					} catch ( \Exception $e ) {
						continue;
					}
				}
			}
		}

		$utc_timezone = new \DateTimeZone( 'UTC' );
		$utc_start    = ( clone $start )->setTime( 0, 0, 0 )->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );
		$utc_end      = ( clone $end )->setTime( 23, 59, 59 )->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );
		
		$all_bookings = $this->db->get_booked_appointments_for_date_range( $utc_start, $utc_end );
		$busy_intervals_utc = [];
		foreach ( $all_bookings as $booking ) {
			try {
				$busy_intervals_utc[] = [
					'start' => new \DateTime( $booking['start_time_utc'], $utc_timezone ),
					'end'   => new \DateTime( $booking['end_time_utc'], $utc_timezone ),
				];
			} catch ( \Exception $e ) {
				continue;
			}
		}

		return [
			'blocks'         => $global_blocked_periods,
			'busy_intervals' => $busy_intervals_utc,
		];
	}

	/**
	 * Helper: Prepares all necessary context for a primary post (meta, combinations, schedules).
	 */
	private function prepare_service_context( $primary_post, $primary_slug, $registry ) {
		// Force refresh of post meta
		wp_cache_delete( $primary_post->ID, 'post_meta' );

		$schedule_meta_raw = get_post_meta( $primary_post->ID, Constants::META_SCHEDULE, true );
		$schedule_data     = json_decode( $schedule_meta_raw, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $schedule_data ) ) {
			$schedule_data = [];
		}

		$overrides_json     = get_post_meta( $primary_post->ID, Constants::META_SCHEDULE_OVERRIDES, true );
		$schedule_overrides = json_decode( $overrides_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $schedule_overrides ) ) {
			$schedule_overrides = [];
		}

		if ( empty( $schedule_data['templates'] ) && empty( $schedule_overrides ) ) {
			return null;
		}

		// Pattern Logic
		$pattern_enabled    = ! empty( $schedule_data['pattern_enabled'] );
		$pattern_sequence   = [];
		$pattern_start_date = null;
		if ( $pattern_enabled ) {
			$sequence_raw     = explode( ',', $schedule_data['pattern_sequence'] ?? '' );
			$pattern_sequence = array_map( 'trim', array_filter( $sequence_raw ) );
			try {
				if ( ! empty( $schedule_data['pattern_start_date'] ) ) {
					$pattern_start_date = new \DateTime( $schedule_data['pattern_start_date'], wp_timezone() );
				}
			} catch ( \Exception $e ) {
				$pattern_enabled = false;
			}
			if ( empty( $pattern_sequence ) || ! $pattern_start_date ) {
				$pattern_enabled = false;
			}
		}

		// Combinations Logic
		$enabled_dimensions = array_filter( $registry['dimensions'] ?? [], fn( $dim ) => ! empty( $dim['enabled'] ) );
		$related_posts_map  = $this->get_direct_related_posts_map( $primary_post->ID, $primary_slug, $enabled_dimensions );
		
		$combinations = [];
		if ( empty( $related_posts_map ) ) {
			$combinations[] = [ $primary_slug => $primary_post->ID ];
		} else {
			$product = $this->cartesian_product( $related_posts_map );
			foreach ( $product as $combo_part ) {
				$combinations[] = $combo_part + [ $primary_slug => $primary_post->ID ];
			}
		}

		// Filter Combinations
		$valid_combinations = [];
		foreach ( $combinations as $combo ) {
			if ( count( $combo ) <= 1 ) {
				$valid_combinations[] = $combo;
				continue;
			}
			$is_fully_interlinked = true;
			$combo_items          = array_values( $combo );
			$combo_slugs          = array_keys( $combo );
			for ( $i = 0; $i < count( $combo_items ); $i++ ) {
				for ( $j = $i + 1; $j < count( $combo_items ); $j++ ) {
					$item1_id        = $combo_items[ $i ];
					$item1_slug      = $combo_slugs[ $i ];
					$item2_id        = $combo_items[ $j ];
					$item2_slug      = $combo_slugs[ $j ];
					$linked_to_item1 = $this->db->get_linked_child_ids( $item1_id, $item2_slug );
					if ( ! in_array( $item2_id, $linked_to_item1, true ) ) {
						$is_fully_interlinked = false;
						break 2;
					}
				}
			}
			if ( $is_fully_interlinked ) {
				$valid_combinations[] = $combo;
			}
		}

		// Resource Schedules
		$resource_schedules_cache = [];
		$resource_dimension_slugs = [];
		foreach ( $enabled_dimensions as $slug => $settings ) {
			$is_res = ! empty( $settings['is_resource'] );
			if ( $is_res ) {
				$resource_dimension_slugs[] = $slug;
			}
		}

		foreach ( $valid_combinations as $combo ) {
			foreach ( $combo as $slug => $post_id ) {
				if ( in_array( $slug, $resource_dimension_slugs, true ) && ! isset( $resource_schedules_cache[ $post_id ] ) ) {
					$schedule_meta  = get_post_meta( $post_id, Constants::META_SCHEDULE, true );
					$overrides_meta = get_post_meta( $post_id, Constants::META_SCHEDULE_OVERRIDES, true );

					$decoded_schedule = json_decode( $schedule_meta, true );
					$decoded_schedule = ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_schedule ) ) ? $decoded_schedule : [];

					$decoded_overrides = json_decode( $overrides_meta, true );
					$decoded_overrides = ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_overrides ) ) ? $decoded_overrides : [];

					$resource_schedules_cache[ $post_id ] = [
						'schedule'  => $decoded_schedule,
						'overrides' => $decoded_overrides,
					];
				}
			}
		}

		return [
			// Routed through Service_Helper so the effective duration matches what the
			// admin UI displays (default 60) when the meta is unset. Without this, the
			// SlotCalculator fallback path uses the global clisyc_calendar_slot_duration
			// option (often 15 min), silently subdividing scheduled blocks into much
			// shorter slots than the admin UI implied.
			'duration_minutes'         => Service_Helper::get_duration_minutes( $primary_post->ID ),
			'padding_minutes'          => (int) get_post_meta( $primary_post->ID, Constants::META_PADDING_MINUTES, true ),
			'buffer_before'            => (int) get_post_meta( $primary_post->ID, Constants::META_BUFFER_BEFORE, true ),
			'buffer_after'             => (int) get_post_meta( $primary_post->ID, Constants::META_BUFFER_AFTER, true ),
			'schedule_data'            => $schedule_data,
			'schedule_overrides'       => $schedule_overrides,
			'pattern_enabled'          => $pattern_enabled,
			'pattern_sequence'         => $pattern_sequence,
			'pattern_start_date'       => $pattern_start_date,
			'valid_combinations'       => $valid_combinations,
			'resource_schedules_cache' => $resource_schedules_cache,
			'resource_dimension_slugs' => $resource_dimension_slugs,
		];
	}

	/**
	 * Helper: Processes a single day for slot generation.
	 * Handles intersection, calculation, and database insertion.
	 */
	private function process_single_day( $date_obj_local, $ctx, $global_blocks, $busy_intervals, $site_timezone, $utc_timezone ) {
		$date_string = $date_obj_local->format( 'Y-m-d' );

		// Global Block Check
		foreach ( $global_blocks as $block ) {
			if ( $date_obj_local >= $block['start'] && $date_obj_local <= $block['end'] ) {
				return 0; // Day is blocked globally
			}
		}

		// Primary Template Determination
		$template_slots = null;
		if ( isset( $ctx['schedule_overrides'][ $date_string ] ) ) {
			$override = $ctx['schedule_overrides'][ $date_string ];
			if ( $override['type'] === 'closed' ) {
				return 0;
			} elseif ( $override['type'] === 'custom' && ! empty( $override['slots'] ) ) {
				$template_slots = $override['slots'];
			}
		}

		if ( null === $template_slots ) {
			$day_of_week         = (int) $date_obj_local->format( 'w' );
			$template_key_to_use = 'A';
			if ( $ctx['pattern_enabled'] ) {
				$interval         = $ctx['pattern_start_date']->diff( $date_obj_local );
				$days_since_start = (int) $interval->days;
				if ( $days_since_start >= 0 ) {
					$week_index          = floor( $days_since_start / 7 );
					$pattern_index       = $week_index % count( $ctx['pattern_sequence'] );
					$template_key_to_use = $ctx['pattern_sequence'][ $pattern_index ];
				}
			}
			if ( ! empty( $ctx['schedule_data']['templates'][ $template_key_to_use ][ $day_of_week ]['slots'] ) ) {
				$template_slots = $ctx['schedule_data']['templates'][ $template_key_to_use ][ $day_of_week ]['slots'];
			}
		}

		if ( empty( $template_slots ) ) {
			return 0;
		}

		// Resource Intersection
		// When a resource dimension (like Room) has a schedule defined, we must intersect
		// the service's template with the resource's availability for that day.
		// If the resource has NO slots for a day, it means the resource is CLOSED that day.
		$constrained_template_slots = $template_slots;
		foreach ( $ctx['valid_combinations'] as $combination ) {
			foreach ( $combination as $slug => $post_id ) {
				if ( in_array( $slug, $ctx['resource_dimension_slugs'], true ) && isset( $ctx['resource_schedules_cache'][ $post_id ] ) ) {
					$resource_schedule       = $ctx['resource_schedules_cache'][ $post_id ];
					$resource_template_slots = null;
					$resource_has_schedule   = false; // Track if resource has ANY schedule defined
					
					// Check for date-specific override first
					if ( isset( $resource_schedule['overrides'][ $date_string ] ) ) {
						$override = $resource_schedule['overrides'][ $date_string ];
						if ( $override['type'] === 'closed' ) {
							$resource_template_slots = [];
							$resource_has_schedule = true;
						} elseif ( $override['type'] === 'custom' && ! empty( $override['slots'] ) ) {
							$resource_template_slots = $override['slots'];
							$resource_has_schedule = true;
						}
					}
					
					// If no override, check the weekly schedule template
					if ( null === $resource_template_slots && isset( $resource_schedule['schedule']['templates'] ) ) {
						$resource_has_schedule = true; // Resource HAS a schedule defined
						$day_of_week = (int) $date_obj_local->format( 'w' );
						
						if ( ! empty( $resource_schedule['schedule']['templates']['A'][ $day_of_week ]['slots'] ) ) {
							// Resource has slots for this day
							$resource_template_slots = $resource_schedule['schedule']['templates']['A'][ $day_of_week ]['slots'];
						} else {
							// FIX: Resource has a schedule but NO slots for this day = CLOSED
							// This prevents generating slots on days the resource isn't available
							$resource_template_slots = [];
						}
					}

					// Only intersect if the resource has a schedule defined
					// (Resources without any schedule don't constrain availability)
					if ( $resource_has_schedule && null !== $resource_template_slots ) {
						$constrained_template_slots = $this->_intersect_slot_templates( $constrained_template_slots, $resource_template_slots );
						
						// Early exit if intersection resulted in no slots
						if ( empty( $constrained_template_slots ) ) {
							return 0;
						}
					}
				}
			}
		}

		// Calculate Slots
		$slots_for_this_day = SlotCalculator::create_specific_date_slots_from_template(
			$date_string,
			$constrained_template_slots,
			$busy_intervals,
			$site_timezone,
			$ctx['duration_minutes'],
			$ctx['padding_minutes'],
			$ctx['buffer_before'],
			$ctx['buffer_after']
		);

		if ( empty( $slots_for_this_day ) ) {
			return 0;
		}

		// --- START: FIX - Preserve slots with bookings ---
		// Get the date range for this day in UTC
		$utc_day_start = ( clone $date_obj_local )->setTime( 0, 0, 0 )->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );
		$utc_day_end   = ( clone $date_obj_local )->setTime( 23, 59, 59 )->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );
		
		// Get slot_ids that have active bookings - these should NOT be deleted
		$booked_slot_ids = $this->slot_repo->get_booked_slot_ids( $utc_day_start, $utc_day_end );
		
		// Get booked time+dimension combos to avoid creating duplicate slots
		$booked_time_slots = $this->slot_repo->get_booked_time_slots( $utc_day_start, $utc_day_end );
		
		// --- END: FIX ---

		// Prepare Insertions
		$slots_to_insert = [];
		foreach ( $ctx['valid_combinations'] as $combination ) {
			// Delete slots for this day and combination, but PRESERVE booked slots
			$this->db->delete_slots_for_date_range( $utc_day_start, $utc_day_end, $combination, false, null, $booked_slot_ids );

			foreach ( $slots_for_this_day as $new_slot ) {
				try {
					$start_dt_site = new \DateTime( $new_slot['date'] . ' ' . $new_slot['start_time'], $site_timezone );
					$start_dt_utc = ( clone $start_dt_site )->setTimezone( $utc_timezone );
					$start_utc_str = $start_dt_utc->format( 'Y-m-d H:i:s' );
					
					// --- START: FIX - Skip if this time/dimension combo already has a booking ---
					$slot_key = $this->slot_repo->make_slot_key( $start_utc_str, $combination );
					if ( isset( $booked_time_slots[ $slot_key ] ) ) {
						continue; // Don't create a duplicate slot
					}
					// --- END: FIX ---
					
					$end_dt_site = new \DateTime( $new_slot['date'] . ' ' . $new_slot['end_time'], $site_timezone );
					$slots_to_insert[] = [
						'start_time_utc' => $start_utc_str,
						'end_time_utc'   => $end_dt_site->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' ),
						'is_block'       => false,
						'dimensions'     => $combination,
					];
				} catch ( \Exception $e ) {
					continue;
				}
			}
		}

		if ( ! empty( $slots_to_insert ) ) {
			$res = $this->db->insert_slots( $slots_to_insert );
			return is_array( $res ) ? ( $res['inserted'] ?? 0 ) : 0;
		}

		return 0;
	}

	private function get_direct_related_posts_map( int $primary_post_id, string $primary_slug, array $enabled_dimensions ): array {
		global $wpdb;
		$rels_table  = $this->db->get_relationships_table_name();
		$related_map = [];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $rels_table is safely retrieved from internal method.
		$sql = "SELECT DISTINCT parent_object_id, parent_object_type, child_object_id, child_object_type
				 FROM {$rels_table}
				 WHERE (parent_object_id = %d AND parent_object_type = %s)
				    OR (child_object_id = %d AND child_object_type = %s)";

		// Bidirectional Query
		// FIX: Ensure we get distinct IDs to avoid duplication issues
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from internal method, caching not appropriate for real-time data.
		$relationships = $wpdb->get_results(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared above.
				$primary_post_id,
				$primary_slug,
				$primary_post_id,
				$primary_slug
			)
		);

		foreach ( $relationships as $rel ) {
			$other_id   = null;
			$other_slug = null;

			if ( (int) $rel->parent_object_id === $primary_post_id ) {
				$other_id   = (int) $rel->child_object_id;
				$other_slug = $rel->child_object_type;
			} else {
				$other_id   = (int) $rel->parent_object_id;
				$other_slug = $rel->parent_object_type;
			}

			if ( $other_id && $other_slug && isset( $enabled_dimensions[ $other_slug ] ) ) {
				if ( ! isset( $related_map[ $other_slug ] ) ) {
					$related_map[ $other_slug ] = [];
				}
				// REMOVED: $related_map[ $other_slug ] = []; 
				
				// Only add if not already present (to be safe)
				if ( ! in_array( $other_id, $related_map[ $other_slug ], true ) ) {
					$related_map[ $other_slug ][] = $other_id;
				}
			}
		}

		return $related_map;
	}

	private function cartesian_product( array $arrays ): array {
		$result = [ [] ];
		foreach ( $arrays as $key => $values ) {
			$append = [];
			foreach ( $result as $product ) {
				foreach ( $values as $value ) {
					$product[ $key ] = $value;
					$append[]        = $product;
				}
			}
			$result = $append;
		}
		return $result;
	}

	private function send_appointment_reminders() {
		$reminder_settings = get_option( Constants::OPTION_REMINDER_SETTINGS, [] );
		if ( empty( $reminder_settings['enabled'] ) ) {
			return;
		}

		$lead_time_value = $reminder_settings['lead_time_value'] ?? 24;
		$lead_time_unit  = $reminder_settings['lead_time_unit'] ?? 'hours';
		if ( $lead_time_value <= 0 ) {
			return;
		}

		$utc_timezone       = new \DateTimeZone( 'UTC' );
		$now_utc            = new \DateTime( 'now', $utc_timezone );
		$cron_interval_mins = 15;
		$reminder_target_time_utc = ( clone $now_utc )->modify( "+{$lead_time_value} {$lead_time_unit}" );
		$window_start_time_utc    = ( clone $reminder_target_time_utc )->modify( "-{$cron_interval_mins} minutes" );
		$window_start_utc_str = $window_start_time_utc->format( 'Y-m-d H:i:s' );
		$window_end_utc_str   = $reminder_target_time_utc->format( 'Y-m-d H:i:s' );

		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => [ 'publish', Constants::STATUS_CONFIRMED, 'clisyc_paid_on_day' ],
			'posts_per_page' => 100,
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => Constants::META_TIME_SLOT,
					'value'   => [ $window_start_utc_str, $window_end_utc_str ],
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				],
				[
					'key'     => Constants::META_REMINDER_SENT,
					'compare' => 'NOT EXISTS',
				],
			],
			'fields'         => 'ids',
		];
		$appointment_ids = get_posts( $args );

		if ( empty( $appointment_ids ) ) {
			return;
		}
		$notifications_manager = new Notifications();
		foreach ( $appointment_ids as $appointment_id ) {
			try {
				if ( $notifications_manager->send( 'appointment_reminder', $appointment_id ) ) {
					update_post_meta( $appointment_id, Constants::META_REMINDER_SENT, time() );
				}
			} catch ( \Exception $e ) {
				Debug_Logger::log( 'Reminder send failed for appointment ' . $appointment_id . ': ' . $e->getMessage(), 'Cron' );
				continue;
			}
		}
	}

	private function _update_smart_start_date_cache() {
		if ( ! get_option( Constants::OPTION_CALENDAR_SMART_START, false ) ) {
			delete_option( Constants::OPTION_CALENDAR_SMART_START_NA );
			return;
		}
		$future_slot_dates = $this->db->get_distinct_available_slot_dates();
		if ( ! empty( $future_slot_dates ) ) {
			$next_available_date = $future_slot_dates[0];
			update_option( Constants::OPTION_CALENDAR_SMART_START_NA, $next_available_date );
		} else {
			delete_option( Constants::OPTION_CALENDAR_SMART_START_NA );
		}
	}

	private function _intersect_slot_templates( array $template_a, array $template_b ): array {
		if ( empty( $template_a ) || empty( $template_b ) ) {
			return [];
		}
		$intervals_a = array_map(
			function ( $slot ) {
				return [ strtotime( $slot['start'] ), strtotime( $slot['end'] ) ];
			},
			$template_a
		);
		$intervals_b = array_map(
			function ( $slot ) {
				return [ strtotime( $slot['start'] ), strtotime( $slot['end'] ) ];
			},
			$template_b
		);
		$intersection = [];
		$i            = 0;
		$j            = 0;
		while ( $i < count( $intervals_a ) && $j < count( $intervals_b ) ) {
			$start_a = $intervals_a[ $i ][0];
			$end_a   = $intervals_a[ $i ][1];
			$start_b = $intervals_b[ $j ][0];
			$end_b   = $intervals_b[ $j ][1];
			$overlap_start = max( $start_a, $start_b );
			$overlap_end   = min( $end_a, $end_b );
			if ( $overlap_start < $overlap_end ) {
				$intersection[] = [
					'start' => gmdate( 'H:i', $overlap_start ),
					'end'   => gmdate( 'H:i', $overlap_end ),
				];
			}
			if ( $end_a < $end_b ) {
				$i++;
			} else {
				$j++;
			}
		}
		return $intersection;
	}
}