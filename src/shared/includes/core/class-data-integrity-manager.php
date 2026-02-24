<?php
/**
 * File: src/shared/includes/core/class-data-integrity-manager.php
 * Handles data integrity cleanup routines that run on a daily WP Cron schedule.
 *
 * Detects and repairs data inconsistencies in the plugin's custom database
 * tables and post meta. Each cleanup method targets a specific table and
 * removes orphaned rows or corrects stale counts.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Data_Integrity_Manager {

	/**
	 * The WP-Cron hook name for the daily integrity check.
	 *
	 * @var string
	 */
	const DAILY_HOOK = 'clisyc_daily_integrity_check';

	/**
	 * Maximum rows to delete per batch to avoid long-running queries.
	 *
	 * @var int
	 */
	const BATCH_LIMIT = 1000;

	/**
	 * Database_Manager instance used to resolve table names.
	 *
	 * @var Database_Manager
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param Database_Manager $db_manager The database manager facade.
	 */
	public function __construct( Database_Manager $db_manager ) {
		$this->db = $db_manager;
	}

	// =========================================================================
	// Hook registration & cron scheduling
	// =========================================================================

	/**
	 * Register WordPress hooks for the daily integrity check.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( self::DAILY_HOOK, [ $this, 'run_integrity_checks' ] );
	}

	/**
	 * Schedule the daily integrity check cron event if not already scheduled.
	 *
	 * The event is scheduled for 3 AM local time to run during off-peak hours.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			// Schedule for 3 AM local time (off-peak).
			$next_3am = strtotime( 'tomorrow 3:00am' );
			wp_schedule_event( $next_3am, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Remove the daily integrity check cron event.
	 *
	 * @return void
	 */
	public function unschedule(): void {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	// =========================================================================
	// Main orchestration
	// =========================================================================

	/**
	 * Run all data integrity checks sequentially.
	 *
	 * Acquires a transient-based lock to prevent concurrent execution.
	 * Each cleanup method is only invoked if its target table exists.
	 * Results are logged and persisted as an option for diagnostic review.
	 *
	 * @return void
	 */
	public function run_integrity_checks(): void {
		$start = microtime( true );

		// Guard: only one instance at a time.
		$lock_key = 'clisyc_integrity_lock';
		if ( get_transient( $lock_key ) ) {
			Debug_Logger::log( 'Data integrity check skipped — already running.', 'DataIntegrity' );
			return;
		}
		set_transient( $lock_key, true, 15 * MINUTE_IN_SECONDS );

		$results = [];

		// Check each table exists before running cleanup against it.
		$results['orphaned_bookings']        = $this->table_exists( $this->db->get_bookings_table_name() ) ? $this->clean_orphaned_bookings() : 0;
		$results['orphaned_slot_dimensions'] = $this->table_exists( $this->db->get_dimensions_table_name() ) ? $this->clean_orphaned_slot_dimensions() : 0;
		$results['orphaned_relationships']   = $this->table_exists( $this->db->get_relationships_table_name() ) ? $this->clean_orphaned_relationships() : 0;
		$results['stale_graph_nodes']        = $this->table_exists( $this->db->get_graph_nodes_table_name() ) ? $this->clean_stale_graph_nodes() : 0;
		$results['booking_count_repairs']    = ( $this->table_exists( $this->db->get_bookings_table_name() ) && $this->table_exists( $this->db->get_slots_table_name() ) ) ? $this->repair_booking_counts() : 0;
		$results['expired_audit_logs']       = $this->table_exists( $this->db->get_audit_logs_table_name() ) ? $this->clean_expired_audit_logs() : 0;

		$duration = round( microtime( true ) - $start, 3 );
		$total    = array_sum( $results );

		if ( $total > 0 ) {
			Debug_Logger::log(
				sprintf( 'Data integrity check completed in %ss. Cleaned: %s', $duration, wp_json_encode( $results ) ),
				'DataIntegrity'
			);
		}

		delete_transient( $lock_key );

		// Store last run timestamp.
		update_option( 'clisyc_last_integrity_check', [
			'timestamp' => time(),
			'duration'  => $duration,
			'results'   => $results,
		], false );
	}

	// =========================================================================
	// Cleanup methods
	// =========================================================================

	/**
	 * Remove orphaned booking rows.
	 *
	 * Finds rows in the bookings table where:
	 * - The referenced slot_id no longer exists in the time_slots table, OR
	 * - The referenced appointment_id no longer exists in the posts table
	 *   (or the appointment post has been permanently deleted).
	 *
	 * Deletes are batched to avoid long-running queries.
	 *
	 * @return int Number of deleted rows.
	 */
	public function clean_orphaned_bookings(): int {
		global $wpdb;

		$bookings_table = esc_sql( $this->db->get_bookings_table_name() );
		$slots_table    = esc_sql( $this->db->get_slots_table_name() );
		$posts_table    = esc_sql( $wpdb->posts );
		$total_deleted  = 0;

		// 1. Bookings referencing non-existent slots.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE b FROM `{$bookings_table}` AS b
					LEFT JOIN `{$slots_table}` AS s ON b.slot_id = s.slot_id
					WHERE s.slot_id IS NULL
					LIMIT %d",
					self::BATCH_LIMIT
				)
			);
			$total_deleted += $deleted;
		} while ( $deleted >= self::BATCH_LIMIT );

		// 2. Bookings referencing non-existent appointments.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE b FROM `{$bookings_table}` AS b
					LEFT JOIN `{$posts_table}` AS p ON b.appointment_id = p.ID
					WHERE p.ID IS NULL
					LIMIT %d",
					self::BATCH_LIMIT
				)
			);
			$total_deleted += $deleted;
		} while ( $deleted >= self::BATCH_LIMIT );

		return $total_deleted;
	}

	/**
	 * Remove orphaned slot dimension rows.
	 *
	 * Finds rows in the slot_dimensions table where the referenced slot_id
	 * no longer exists in the time_slots table.
	 *
	 * Deletes are batched to avoid long-running queries.
	 *
	 * @return int Number of deleted rows.
	 */
	public function clean_orphaned_slot_dimensions(): int {
		global $wpdb;

		$dims_table    = esc_sql( $this->db->get_dimensions_table_name() );
		$slots_table   = esc_sql( $this->db->get_slots_table_name() );
		$total_deleted = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE d FROM `{$dims_table}` AS d
					LEFT JOIN `{$slots_table}` AS s ON d.slot_id = s.slot_id
					WHERE s.slot_id IS NULL
					LIMIT %d",
					self::BATCH_LIMIT
				)
			);
			$total_deleted += $deleted;
		} while ( $deleted >= self::BATCH_LIMIT );

		return $total_deleted;
	}

	/**
	 * Remove orphaned relationship rows.
	 *
	 * Finds rows in the relationships table where:
	 * - The parent_object_id (when parent_object_type is a post type) no longer
	 *   exists in the posts table, OR
	 * - The child_object_id (when child_object_type is a post type) no longer
	 *   exists in the posts table.
	 *
	 * Only cleans post-type references; user-type references are left intact.
	 * Deletes are batched to avoid long-running queries.
	 *
	 * @return int Number of deleted rows.
	 */
	public function clean_orphaned_relationships(): int {
		global $wpdb;

		$rel_table     = esc_sql( $this->db->get_relationships_table_name() );
		$posts_table   = esc_sql( $wpdb->posts );
		$total_deleted = 0;

		// 1. Parent object is a post type but the post no longer exists.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE r FROM `{$rel_table}` AS r
					LEFT JOIN `{$posts_table}` AS p ON r.parent_object_id = p.ID
					WHERE r.parent_object_type != 'user'
					AND p.ID IS NULL
					LIMIT %d",
					self::BATCH_LIMIT
				)
			);
			$total_deleted += $deleted;
		} while ( $deleted >= self::BATCH_LIMIT );

		// 2. Child object is a post type but the post no longer exists.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE r FROM `{$rel_table}` AS r
					LEFT JOIN `{$posts_table}` AS p ON r.child_object_id = p.ID
					WHERE r.child_object_type != 'user'
					AND p.ID IS NULL
					LIMIT %d",
					self::BATCH_LIMIT
				)
			);
			$total_deleted += $deleted;
		} while ( $deleted >= self::BATCH_LIMIT );

		return $total_deleted;
	}

	/**
	 * Remove stale graph node rows.
	 *
	 * Finds rows in the graph_nodes table where wp_post_id references a post
	 * that no longer exists in the posts table (wp_post_id > 0 only).
	 *
	 * Deletes are batched to avoid long-running queries.
	 *
	 * @return int Number of deleted rows.
	 */
	public function clean_stale_graph_nodes(): int {
		global $wpdb;

		$graph_table   = esc_sql( $this->db->get_graph_nodes_table_name() );
		$posts_table   = esc_sql( $wpdb->posts );
		$total_deleted = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE g FROM `{$graph_table}` AS g
					LEFT JOIN `{$posts_table}` AS p ON g.wp_post_id = p.ID
					WHERE g.wp_post_id > 0
					AND p.ID IS NULL
					LIMIT %d",
					self::BATCH_LIMIT
				)
			);
			$total_deleted += $deleted;
		} while ( $deleted >= self::BATCH_LIMIT );

		return $total_deleted;
	}

	/**
	 * Repair incorrect booking counts on time slots.
	 *
	 * For each slot in the time_slots table, recalculates booking_count by
	 * counting actual rows in the bookings table. Only updates rows where
	 * the stored count does not match the actual count.
	 *
	 * @return int Number of corrected rows.
	 */
	public function repair_booking_counts(): int {
		global $wpdb;

		$slots_table    = esc_sql( $this->db->get_slots_table_name() );
		$bookings_table = esc_sql( $this->db->get_bookings_table_name() );

		// Find all slots where booking_count differs from the actual count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$mismatched = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.slot_id, s.booking_count AS stored_count, COUNT( b.booking_id ) AS actual_count
				FROM `{$slots_table}` AS s
				LEFT JOIN `{$bookings_table}` AS b ON s.slot_id = b.slot_id
				GROUP BY s.slot_id, s.booking_count
				HAVING s.booking_count != COUNT( b.booking_id )
				LIMIT %d",
				10000
			)
		);

		if ( empty( $mismatched ) ) {
			return 0;
		}

		$corrected = 0;

		foreach ( $mismatched as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$this->db->get_slots_table_name(),
				[ 'booking_count' => (int) $row->actual_count ],
				[ 'slot_id' => (int) $row->slot_id ],
				[ '%d' ],
				[ '%d' ]
			);

			if ( false !== $updated && $updated > 0 ) {
				++$corrected;
			}
		}

		return $corrected;
	}

	/**
	 * Delete expired audit log entries.
	 *
	 * Reads the configured retention period from the plugin options and
	 * removes all audit log rows older than that threshold.
	 *
	 * Deletes are batched to avoid long-running queries.
	 *
	 * @return int Number of deleted rows.
	 */
	public function clean_expired_audit_logs(): int {
		global $wpdb;

		$audit_table   = esc_sql( $this->db->get_audit_logs_table_name() );
		$retention     = (int) get_option( Constants::OPTION_AUDIT_LOG_RETENTION, 2555 );
		$cutoff        = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention} days" ) );
		$total_deleted = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$audit_table}`
					WHERE created_at < %s
					LIMIT %d",
					$cutoff,
					self::BATCH_LIMIT
				)
			);
			$total_deleted += $deleted;
		} while ( $deleted >= self::BATCH_LIMIT );

		return $total_deleted;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table_name Fully qualified table name.
	 * @return bool True if the table exists.
	 */
	private function table_exists( string $table_name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return ! empty( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) );
	}
}
