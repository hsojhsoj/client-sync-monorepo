<?php
/**
 * File: src/shared/includes/core/class-async-tasks.php
 * Handles background processing using Action Scheduler.
 * This replaces the manual batching logic in Cron.php with a robust queue system.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Services\Availability_Service;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Async_Tasks {

	const GENERATE_SLOTS_HOOK = 'clisyc_generate_slots_for_post';

	private $db_manager;

	public function __construct( Database_Manager $db_manager ) {
		$this->db_manager = $db_manager;
	}

	const WP_CRON_FALLBACK_HOOK = 'clisyc_fallback_slot_generation';

	public function register_hooks() {
		// Hook the worker logic to the action name.
		add_action( self::GENERATE_SLOTS_HOOK, [ $this, 'process_slot_generation' ], 10, 3 );

		// Hook the dispatcher to the main 15-minute cron event.
		add_action( 'clisyc_frequent_maintenance_tasks', [ $this, 'dispatch_generation_tasks' ] );

		// Register wp-cron fallback hook for environments without Action Scheduler.
		add_action( self::WP_CRON_FALLBACK_HOOK, [ $this, 'process_slot_generation' ], 10, 2 );
	}

	/**
	 * Dispatcher: Schedules a unique background job for every Primary Dimension item.
	 * This runs quickly and hands off the heavy lifting to the queue.
	 */
	public function dispatch_generation_tasks() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			Debug_Logger::log( 'Action Scheduler not available. Falling back to wp-cron for slot generation.', 'AsyncTasks' );
			$this->dispatch_via_wp_cron();
			return;
		}

		// 1. Check if Auto-Gen is enabled.
		if ( ! get_option( Constants::OPTION_AUTO_GEN_ENABLED, false ) ) {
			return;
		}

		// 2. Find Primary Dimension.
		$registry     = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_slug = null;

		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) && ! empty( $settings['enabled'] ) ) {
				$primary_slug = $slug;
				break;
			}
		}

		if ( ! $primary_slug ) {
			return;
		}

		// 3. Get all published items for the Primary Dimension.
		$items = get_posts(
			[
				'post_type'      => $primary_slug,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'publish',
			]
		);

		$lookahead_days = (int) get_option( Constants::OPTION_AUTO_GEN_LOOKAHEAD, 14 );
		// Calculate target date: Today + Lookahead.
		// FIX: Use gmdate() instead of date() to avoid timezone warnings.
		$target_date = gmdate( 'Y-m-d', strtotime( "+$lookahead_days days" ) );

		// 4. Queue a job for each item.
		foreach ( $items as $post_id ) {
			$args = [
				'post_id'     => $post_id,
				'target_date' => $target_date,
			];

			// Only schedule if there isn't already a pending action for this specific post/date.
			if ( ! as_has_scheduled_action( self::GENERATE_SLOTS_HOOK, $args ) ) {
				as_schedule_single_action( time(), self::GENERATE_SLOTS_HOOK, $args, 'client-sync' );
			}
		}
	}

	/**
	 * Worker: Processes ONE single item.
	 * If this crashes or times out, Action Scheduler will automatically retry it later
	 * without stopping the rest of the queue.
	 *
	 * @param int    $post_id     The ID of the primary dimension item (e.g., Service ID).
	 * @param string $target_date The Y-m-d date to generate slots up to.
	 */
	public function process_slot_generation( $post_id, $target_date ) {
		// Instantiate Cron to access the generation logic.
		// In the future, this logic should be moved to a dedicated Service class,
		// but for now, we reuse the robust logic already present in Cron.
		$cron_manager = new Cron( $this->db_manager );

		// Call the new single-post generation method.
		if ( method_exists( $cron_manager, 'generate_slots_for_single_post' ) ) {
			$cron_manager->generate_slots_for_single_post( $post_id, $target_date );
		} else {
			Debug_Logger::log( 'Async Task Error: generate_slots_for_single_post method missing on Cron class.', 'AsyncTasks' );
		}
	}

	/**
	 * Degraded fallback: schedule slot generation via wp-cron when Action Scheduler
	 * is not available (e.g. WooCommerce is deactivated).
	 *
	 * Each item is scheduled with a 10-second offset to stagger execution and
	 * reduce the chance of overlapping cron runs.
	 */
	private function dispatch_via_wp_cron(): void {
		if ( ! get_option( Constants::OPTION_AUTO_GEN_ENABLED, false ) ) {
			return;
		}

		$registry     = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_slug = null;

		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) && ! empty( $settings['enabled'] ) ) {
				$primary_slug = $slug;
				break;
			}
		}

		if ( ! $primary_slug ) {
			return;
		}

		$items = get_posts( [
			'post_type'      => $primary_slug,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_status'    => 'publish',
		] );

		$lookahead_days = (int) get_option( Constants::OPTION_AUTO_GEN_LOOKAHEAD, 14 );
		$target_date    = gmdate( 'Y-m-d', strtotime( "+{$lookahead_days} days" ) );

		$offset = 0;
		foreach ( $items as $post_id ) {
			$args = [ $post_id, $target_date ];
			if ( ! wp_next_scheduled( self::WP_CRON_FALLBACK_HOOK, $args ) ) {
				wp_schedule_single_event( time() + 10 + $offset, self::WP_CRON_FALLBACK_HOOK, $args );
				$offset += 10;
			}
		}
	}
}