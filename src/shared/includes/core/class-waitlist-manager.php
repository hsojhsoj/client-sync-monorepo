<?php
/**
 * File: src/shared/includes/core/class-waitlist-manager.php
 * Manages the waitlist system for fully-booked time slots.
 *
 * When a slot reaches capacity, clients can join a waitlist. When a
 * cancellation frees a spot the next waitlisted person is automatically
 * promoted and notified.
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

class Waitlist_Manager {

	// ── Option Helpers ─────────────────────────────────────────────────

	public static function is_enabled(): bool {
		return (bool) get_option( Constants::OPTION_WAITLIST_ENABLED, false );
	}

	public static function get_max_size(): int {
		return (int) get_option( Constants::OPTION_WAITLIST_MAX_SIZE, 0 );
	}

	public static function is_auto_promote(): bool {
		return (bool) get_option( Constants::OPTION_WAITLIST_AUTO_PROMOTE, true );
	}

	// ── Hooks ──────────────────────────────────────────────────────────

	/**
	 * Register WordPress hooks for automatic waitlist promotion.
	 */
	public static function register_hooks(): void {
		add_action( 'transition_post_status', [ __CLASS__, 'maybe_promote_on_status_change' ], 20, 3 );
	}

	/**
	 * When an active appointment transitions to cancelled/trash, promote the
	 * next waitlisted person for that slot.
	 */
	public static function maybe_promote_on_status_change( $new_status, $old_status, $post ): void {
		if ( Constants::POST_TYPE_APPOINTMENT !== ( $post->post_type ?? '' ) ) {
			return;
		}
		if ( $new_status === $old_status ) {
			return;
		}
		if ( ! self::is_enabled() || ! self::is_auto_promote() ) {
			return;
		}

		$active_statuses = [ 'publish', Constants::STATUS_CONFIRMED, Constants::STATUS_PAID_ON_DAY ];

		// Build cancelled statuses dynamically to stay in sync with the
		// filterable cancellation status used by Cancellation_Manager.
		$cancelled_statuses = apply_filters( 'clisyc_promotion_trigger_statuses', array_unique( [
			'trash',
			Constants::STATUS_CANCELLED,
			apply_filters( 'clisyc_self_service_cancelled_status', 'trash' ),
		] ) );

		if ( ! in_array( $old_status, $active_statuses, true ) || ! in_array( $new_status, $cancelled_statuses, true ) ) {
			return;
		}

		$time_slot  = get_post_meta( $post->ID, Constants::META_TIME_SLOT, true );
		$dimensions = get_post_meta( $post->ID, Constants::META_SLOT_DIMENSIONS, true );

		if ( empty( $time_slot ) ) {
			return;
		}

		$db_manager = new Database_Manager();
		$slot_id    = $db_manager->find_slot_id_by_start_time_and_dimensions( $time_slot, is_array( $dimensions ) ? $dimensions : [] );

		self::promote_next( (int) ( $slot_id ?: 0 ), $time_slot, is_array( $dimensions ) ? $dimensions : [] );
	}

	// ── Query Helpers ──────────────────────────────────────────────────

	/**
	 * Get all waitlisted appointments for a given slot, ordered by position.
	 *
	 * @param int    $slot_id    The slot_id from wp_clisyc_time_slots.
	 * @param string $time_slot  UTC datetime string (fallback matching).
	 * @param array  $dimensions Dimension slug => ID pairs.
	 * @return \WP_Post[]
	 */
	public static function get_waitlist_for_slot( int $slot_id, string $time_slot = '', array $dimensions = [] ): array {
		$meta_query = [ 'relation' => 'AND' ];

		if ( $slot_id > 0 ) {
			$meta_query[] = [
				'key'   => Constants::META_WAITLIST_SLOT_ID,
				'value' => $slot_id,
				'type'  => 'NUMERIC',
			];
		} elseif ( ! empty( $time_slot ) ) {
			// Fallback: match by time slot + dimensions when slot_id is unknown
			// (e.g. slot was deleted and recreated after cancellation).
			$meta_query[] = [
				'key'   => Constants::META_TIME_SLOT,
				'value' => $time_slot,
			];
		} else {
			return [];
		}

		// Only future slots.
		$meta_query[] = [
			'key'     => Constants::META_TIME_SLOT,
			'value'   => gmdate( 'Y-m-d H:i:s' ),
			'compare' => '>=',
			'type'    => 'DATETIME',
		];

		$meta_query['position_clause'] = [
			'key'  => Constants::META_WAITLIST_POSITION,
			'type' => 'NUMERIC',
		];

		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => Constants::STATUS_WAITLISTED,
			'posts_per_page' => -1,
			'meta_query'     => $meta_query,
			'orderby'        => [ 'position_clause' => 'ASC' ],
			'no_found_rows'  => true,
		];

		return get_posts( $args );
	}

	/**
	 * Count how many people are currently on the waitlist for a slot.
	 */
	public static function get_waitlist_count( int $slot_id ): int {
		return count( self::get_waitlist_for_slot( $slot_id ) );
	}

	/**
	 * Check whether the waitlist for this slot has room.
	 */
	public static function has_waitlist_room( int $slot_id ): bool {
		$max = self::get_max_size();
		if ( 0 === $max ) {
			return true; // Unlimited.
		}
		return self::get_waitlist_count( $slot_id ) < $max;
	}

	// ── Mutating Methods ───────────────────────────────────────────────

	/**
	 * Add a client to the waitlist for a given slot.
	 *
	 * Creates a clisyc_appointment post with status clisyc_waitlisted.
	 * Does NOT create a row in wp_clisyc_bookings or increment booking_count.
	 *
	 * @param array $data {
	 *     @type int    $user_id
	 *     @type int    $slot_id
	 *     @type string $time_slot         UTC datetime string.
	 *     @type array  $dimensions        Dimension slug => ID pairs.
	 *     @type string $appointment_date  Y-m-d date string.
	 *     @type int    $duration_minutes
	 *     @type array  $custom_fields     Optional.
	 * }
	 * @return int|\WP_Error Appointment ID on success.
	 */
	public static function add_to_waitlist( array $data ) {
		$user_id   = $data['user_id'] ?? 0;
		$slot_id   = $data['slot_id'] ?? 0;
		$time_slot = $data['time_slot'] ?? '';

		if ( ! $user_id || ! $slot_id || empty( $time_slot ) ) {
			return new \WP_Error( 'missing_data', __( 'Missing required data to join the waitlist.', 'client-sync' ) );
		}

		// Prevent double-waitlisting.
		$existing = get_posts( [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => Constants::STATUS_WAITLISTED,
			'author'         => $user_id,
			'meta_query'     => [
				[ 'key' => Constants::META_WAITLIST_SLOT_ID, 'value' => $slot_id, 'type' => 'NUMERIC' ],
			],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		if ( ! empty( $existing ) ) {
			return new \WP_Error( 'already_waitlisted', __( 'You are already on the waitlist for this slot.', 'client-sync' ) );
		}

		// Calculate position.
		$position = self::get_waitlist_count( $slot_id ) + 1;

		// Build title.
		$dimensions    = $data['dimensions'] ?? [];
		$primary_label = '';
		if ( ! empty( $dimensions ) ) {
			$first_dim_id = reset( $dimensions );
			$dim_post     = get_post( $first_dim_id );
			if ( $dim_post ) {
				$primary_label = $dim_post->post_title;
			}
		}
		$user         = get_userdata( $user_id );
		$display_name = $user ? $user->display_name : __( 'Guest', 'client-sync' );
		$title        = $primary_label ? $primary_label . ' - ' . $display_name : $display_name;

		// Create the appointment post.
		$appointment_id = wp_insert_post( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => Constants::STATUS_WAITLISTED,
			'post_title'  => $title,
			'post_author' => $user_id,
		], true );

		if ( is_wp_error( $appointment_id ) ) {
			return $appointment_id;
		}

		// Save standard meta (same keys as a normal booking, minus the booking table entry).
		update_post_meta( $appointment_id, Constants::META_TIME_SLOT, $time_slot );
		update_post_meta( $appointment_id, Constants::META_APPOINTMENT_DATE, $data['appointment_date'] ?? '' );
		update_post_meta( $appointment_id, Constants::META_BOOKING_MODE, 'slot' );
		update_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, $dimensions );
		update_post_meta( $appointment_id, Constants::META_CLIENT_ID, $user_id );

		if ( ! empty( $data['duration_minutes'] ) ) {
			update_post_meta( $appointment_id, Constants::META_DURATION_MINUTES, (int) $data['duration_minutes'] );
			update_post_meta( $appointment_id, Constants::META_APPOINTMENT_DURATION, (int) $data['duration_minutes'] );
		}

		// Save dimension meta individually (matches booking handler pattern).
		foreach ( $dimensions as $dim_key => $dim_value ) {
			update_post_meta( $appointment_id, $dim_key, $dim_value );
			update_post_meta( $appointment_id, '_clisyc_' . $dim_key, $dim_value );
		}

		// Save custom fields.
		if ( ! empty( $data['custom_fields'] ) && is_array( $data['custom_fields'] ) ) {
			update_post_meta( $appointment_id, '_clisyc_custom_fields', $data['custom_fields'] );
		}

		// Save waitlist-specific meta.
		update_post_meta( $appointment_id, Constants::META_WAITLIST_POSITION, $position );
		update_post_meta( $appointment_id, Constants::META_WAITLIST_JOINED_AT, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $appointment_id, Constants::META_WAITLIST_SLOT_ID, $slot_id );

		// Fire notification.
		if ( class_exists( '\DependentMedia\ClientSync\Core\Notifications' ) ) {
			( new Notifications() )->send( 'waitlist_joined', $appointment_id );
		}

		return $appointment_id;
	}

	/**
	 * Promote the next waitlisted person for a slot.
	 *
	 * Changes their status to 'publish', creates the booking entry,
	 * increments booking_count, cleans up waitlist meta, and notifies.
	 *
	 * @param int    $slot_id    The slot_id that now has capacity.
	 * @param string $time_slot  UTC datetime.
	 * @param array  $dimensions Slot dimensions.
	 * @return int|false Promoted appointment ID, or false if nobody waiting.
	 */
	public static function promote_next( int $slot_id, string $time_slot = '', array $dimensions = [] ) {
		$waitlist = self::get_waitlist_for_slot( $slot_id, $time_slot, $dimensions );
		if ( empty( $waitlist ) ) {
			return false;
		}

		$next = $waitlist[0];
		return self::_do_promote( $next->ID, $slot_id, $time_slot, $dimensions );
	}

	/**
	 * Admin-initiated promotion of a specific waitlisted appointment.
	 *
	 * @param int $appointment_id
	 * @return array{success: bool, message: string}
	 */
	public static function admin_promote( int $appointment_id ): array {
		$post = get_post( $appointment_id );
		if ( ! $post || Constants::STATUS_WAITLISTED !== $post->post_status ) {
			return [ 'success' => false, 'message' => __( 'This appointment is not on the waitlist.', 'client-sync' ) ];
		}

		$slot_id    = (int) get_post_meta( $appointment_id, Constants::META_WAITLIST_SLOT_ID, true );
		$time_slot  = get_post_meta( $appointment_id, Constants::META_TIME_SLOT, true );
		$dimensions = get_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, true );

		$promoted_id = self::_do_promote( $appointment_id, $slot_id, $time_slot, is_array( $dimensions ) ? $dimensions : [] );

		if ( $promoted_id ) {
			return [ 'success' => true, 'message' => __( 'Appointment promoted from waitlist.', 'client-sync' ) ];
		}

		return [ 'success' => false, 'message' => __( 'Could not promote this appointment.', 'client-sync' ) ];
	}

	/**
	 * Remove an appointment from the waitlist (client leaves voluntarily).
	 *
	 * @param int $appointment_id
	 * @return array{success: bool, message: string}
	 */
	public static function leave_waitlist( int $appointment_id ): array {
		$post = get_post( $appointment_id );
		if ( ! $post || Constants::STATUS_WAITLISTED !== $post->post_status ) {
			return [ 'success' => false, 'message' => __( 'This appointment is not on the waitlist.', 'client-sync' ) ];
		}

		$slot_id = (int) get_post_meta( $appointment_id, Constants::META_WAITLIST_SLOT_ID, true );

		wp_trash_post( $appointment_id );

		if ( $slot_id > 0 ) {
			self::recalculate_positions( $slot_id );
		}

		return [ 'success' => true, 'message' => __( 'You have been removed from the waitlist.', 'client-sync' ) ];
	}

	// ── Internal ───────────────────────────────────────────────────────

	/**
	 * Perform the actual promotion of a waitlisted appointment.
	 *
	 * @param int    $appointment_id The waitlisted appointment to promote.
	 * @param int    $slot_id        The slot_id.
	 * @param string $time_slot      UTC datetime.
	 * @param array  $dimensions     Slot dimensions.
	 * @return int|false Promoted appointment ID, or false on failure.
	 */
	private static function _do_promote( int $appointment_id, int $slot_id, string $time_slot, array $dimensions ) {
		global $wpdb;

		$db_manager = new Database_Manager();

		// Resolve the slot_id: if the slot was deleted on cancellation and recreated,
		// the original slot_id stored on the waitlisted appointment may be stale.
		$resolved_slot_id = $slot_id;
		if ( $slot_id > 0 ) {
			$slots_table = $db_manager->get_slots_table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name safe.
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT slot_id FROM {$slots_table} WHERE slot_id = %d", $slot_id ) );
			if ( ! $exists && ! empty( $time_slot ) ) {
				$resolved_slot_id = $db_manager->find_slot_id_by_start_time_and_dimensions( $time_slot, $dimensions ) ?: 0;
			}
		} elseif ( ! empty( $time_slot ) ) {
			$resolved_slot_id = $db_manager->find_slot_id_by_start_time_and_dimensions( $time_slot, $dimensions ) ?: 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		$wpdb->query( 'START TRANSACTION' );

		try {
			// 1. Change status to publish.
			$result = wp_update_post( [
				'ID'          => $appointment_id,
				'post_status' => 'publish',
			], true );

			if ( is_wp_error( $result ) ) {
				throw new \Exception( $result->get_error_message() );
			}

			// 2. Create booking entry (if we have a valid slot).
			if ( $resolved_slot_id > 0 ) {
				$bookings_table = $db_manager->get_bookings_table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert required within transaction.
				$wpdb->insert(
					$bookings_table,
					[
						'appointment_id'   => $appointment_id,
						'slot_id'          => $resolved_slot_id,
						'booking_time_utc' => current_time( 'mysql', 1 ),
					],
					[ '%d', '%d', '%s' ]
				);

				// 3. Increment booking_count.
				$slots_table = $db_manager->get_slots_table_name();
				$attendees   = (int) get_post_meta( $appointment_id, '_clisyc_attendee_count', true ) ?: 1;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name safe.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$slots_table} SET booking_count = booking_count + %d WHERE slot_id = %d",
						$attendees,
						$resolved_slot_id
					)
				);
			}

			// 4. Clean up waitlist meta.
			delete_post_meta( $appointment_id, Constants::META_WAITLIST_POSITION );
			delete_post_meta( $appointment_id, Constants::META_WAITLIST_JOINED_AT );
			delete_post_meta( $appointment_id, Constants::META_WAITLIST_SLOT_ID );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'COMMIT' );

			// 5. Recalculate positions for remaining waitlisted.
			if ( $slot_id > 0 ) {
				self::recalculate_positions( $slot_id );
			}

			// 6. Clear slot cache.
			$db_manager->clear_all_slot_cache();

			// 7. Fire notification.
			if ( class_exists( '\DependentMedia\ClientSync\Core\Notifications' ) ) {
				( new Notifications() )->send( 'waitlist_promoted', $appointment_id );
			}

			/**
			 * Fires after a waitlisted appointment has been promoted.
			 *
			 * @param int $appointment_id The promoted appointment ID.
			 */
			do_action( 'clisyc_waitlist_promoted', $appointment_id );

			return $appointment_id;

		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'ROLLBACK' );

			Debug_Logger::log( 'Promotion failed for appointment #' . $appointment_id . ': ' . $e->getMessage(), 'Waitlist' );

			return false;
		}
	}

	/**
	 * Recalculate sequential 1-based positions for all waitlisted appointments
	 * belonging to a given slot.
	 *
	 * @param int $slot_id
	 */
	private static function recalculate_positions( int $slot_id ): void {
		$waitlist = self::get_waitlist_for_slot( $slot_id );
		$position = 1;
		foreach ( $waitlist as $post ) {
			update_post_meta( $post->ID, Constants::META_WAITLIST_POSITION, $position );
			$position++;
		}
	}
}
