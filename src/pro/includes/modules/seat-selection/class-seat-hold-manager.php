<?php
/**
 * File: src/pro/includes/modules/seat-selection/class-seat-hold-manager.php
 * Manages temporary seat holds during checkout.
 *
 * Uses INSERT + UNIQUE KEY (venue_id, slot_id, seat_id) for race condition
 * protection — two concurrent hold attempts for the same seat result in one
 * success and one duplicate key error.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Seat_Selection
 */

namespace ClientSyncPro\Modules\Seat_Selection;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Table_Schema_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seat_Hold_Manager {

	/**
	 * Default hold TTL in seconds.
	 */
	const DEFAULT_TTL = 300;

	/**
	 * @var Table_Schema_Manager
	 */
	private $schema;

	public function __construct( Table_Schema_Manager $schema = null ) {
		$this->schema = $schema ?: new Table_Schema_Manager();
	}

	/**
	 * Get the configured hold TTL in seconds.
	 *
	 * @return int
	 */
	public function get_ttl(): int {
		$ttl = absint( get_option( Constants::OPTION_SEAT_HOLD_TTL, self::DEFAULT_TTL ) );
		return max( 60, $ttl ); // Minimum 60 seconds.
	}

	/**
	 * Attempt to hold a seat.
	 *
	 * Cleans expired holds for this seat first, then attempts an INSERT.
	 * The UNIQUE KEY on (venue_id, slot_id, seat_id) prevents double-holding.
	 *
	 * @param int    $venue_id      Venue post ID.
	 * @param int    $slot_id       Time slot ID.
	 * @param string $seat_id       Seat identifier from layout.
	 * @param string $session_token Browser session token.
	 * @param int    $user_id       WordPress user ID (0 for guests).
	 * @return true|\WP_Error True on success, WP_Error if seat is already held/booked.
	 */
	public function hold_seat( int $venue_id, int $slot_id, string $seat_id, string $session_token, int $user_id = 0 ) {
		global $wpdb;

		$table = $this->schema->get_seat_holds_table_name();
		$now   = current_time( 'mysql', true );
		$ttl   = $this->get_ttl();

		// 1. Clean expired holds for this specific seat.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE venue_id = %d AND slot_id = %d AND seat_id = %s AND hold_expires_at <= %s",
				$venue_id,
				$slot_id,
				$seat_id,
				$now
			)
		);

		// 2. Check if seat is already confirmed (booked).
		if ( $this->is_seat_booked( $venue_id, $slot_id, $seat_id ) ) {
			return new \WP_Error( 'seat_booked', __( 'This seat is already booked.', 'client-sync-pro' ), [ 'status' => 409 ] );
		}

		// 3. Attempt INSERT — UNIQUE KEY handles race conditions.
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'venue_id'        => $venue_id,
				'slot_id'         => $slot_id,
				'seat_id'         => $seat_id,
				'session_token'   => $session_token,
				'user_id'         => $user_id,
				'hold_expires_at' => $expires_at,
				'created_at'      => $now,
			],
			[ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			// Duplicate key = seat already held by another session.
			return new \WP_Error( 'seat_held', __( 'This seat is currently held by another user.', 'client-sync-pro' ), [ 'status' => 409 ] );
		}

		return true;
	}

	/**
	 * Release a specific seat hold.
	 *
	 * @param int    $venue_id
	 * @param int    $slot_id
	 * @param string $seat_id
	 * @param string $session_token Only the holder can release.
	 * @return bool True if released, false if not found.
	 */
	public function release_seat( int $venue_id, int $slot_id, string $seat_id, string $session_token ): bool {
		global $wpdb;

		$table = $this->schema->get_seat_holds_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$table,
			[
				'venue_id'      => $venue_id,
				'slot_id'       => $slot_id,
				'seat_id'       => $seat_id,
				'session_token' => $session_token,
			],
			[ '%d', '%d', '%s', '%s' ]
		);

		return $deleted > 0;
	}

	/**
	 * Refresh (extend) all holds for a session.
	 *
	 * @param int    $venue_id
	 * @param int    $slot_id
	 * @param string $session_token
	 * @return int Number of holds refreshed.
	 */
	public function refresh_holds( int $venue_id, int $slot_id, string $session_token ): int {
		global $wpdb;

		$table      = $this->schema->get_seat_holds_table_name();
		$ttl        = $this->get_ttl();
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET hold_expires_at = %s WHERE venue_id = %d AND slot_id = %d AND session_token = %s",
				$expires_at,
				$venue_id,
				$slot_id,
				$session_token
			)
		);

		return (int) $updated;
	}

	/**
	 * Get all active holds for a session.
	 *
	 * @param int    $venue_id
	 * @param int    $slot_id
	 * @param string $session_token
	 * @return array[] Array of hold rows.
	 */
	public function get_session_holds( int $venue_id, int $slot_id, string $session_token ): array {
		global $wpdb;

		$table = $this->schema->get_seat_holds_table_name();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT seat_id, hold_expires_at FROM {$table}
				 WHERE venue_id = %d AND slot_id = %d AND session_token = %s AND hold_expires_at > %s",
				$venue_id,
				$slot_id,
				$session_token,
				$now
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get all held and booked seat IDs for a venue + slot combination.
	 *
	 * Used by the frontend to display seat availability.
	 *
	 * @param int    $venue_id
	 * @param int    $slot_id
	 * @param string $session_token Current user's session (to mark their own holds differently).
	 * @return array{ held: string[], booked: string[], mine: string[] }
	 */
	public function get_seat_availability( int $venue_id, int $slot_id, string $session_token = '' ): array {
		global $wpdb;

		$holds_table    = $this->schema->get_seat_holds_table_name();
		$bookings_table = $this->schema->get_seat_bookings_table_name();
		$now            = current_time( 'mysql', true );

		// Active holds (not expired).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$holds = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT seat_id, session_token FROM {$holds_table}
				 WHERE venue_id = %d AND slot_id = %d AND hold_expires_at > %s",
				$venue_id,
				$slot_id,
				$now
			),
			ARRAY_A
		);

		// Confirmed bookings.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$booked = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT seat_id FROM {$bookings_table} WHERE venue_id = %d AND slot_id = %d",
				$venue_id,
				$slot_id
			)
		);

		$held_by_others = [];
		$mine           = [];

		foreach ( $holds as $hold ) {
			if ( ! empty( $session_token ) && $hold['session_token'] === $session_token ) {
				$mine[] = $hold['seat_id'];
			} else {
				$held_by_others[] = $hold['seat_id'];
			}
		}

		return [
			'held'   => array_values( array_unique( $held_by_others ) ),
			'booked' => array_values( array_unique( $booked ?: [] ) ),
			'mine'   => array_values( array_unique( $mine ) ),
		];
	}

	/**
	 * Check if a seat is confirmed (booked).
	 *
	 * @param int    $venue_id
	 * @param int    $slot_id
	 * @param string $seat_id
	 * @return bool
	 */
	public function is_seat_booked( int $venue_id, int $slot_id, string $seat_id ): bool {
		global $wpdb;

		$table = $this->schema->get_seat_bookings_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE venue_id = %d AND slot_id = %d AND seat_id = %s",
				$venue_id,
				$slot_id,
				$seat_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Clean up all expired holds.
	 *
	 * Called by cron (ACTION_FREQUENT_MAINTENANCE) every 15 minutes.
	 *
	 * @return int Number of holds purged.
	 */
	public function purge_expired_holds(): int {
		global $wpdb;

		$table = $this->schema->get_seat_holds_table_name();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE hold_expires_at <= %s",
				$now
			)
		);

		return (int) $deleted;
	}

	/**
	 * Release all holds for a given session.
	 *
	 * @param string $session_token
	 * @return int Number of holds released.
	 */
	public function release_session_holds( string $session_token ): int {
		global $wpdb;

		$table = $this->schema->get_seat_holds_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE session_token = %s",
				$session_token
			)
		);

		return (int) $deleted;
	}
}
