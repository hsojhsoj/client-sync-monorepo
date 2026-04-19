<?php
/**
 * File: src/pro/includes/services/class-series-validator.php -> client-sync-pro/includes/services/class-series-validator.php
 * A Pro service class for validating and creating recurring appointment series.
 *
 * FIXED: Now creates wp_clisyc_bookings rows and increments booking_count for each
 * occurrence. Uses database transactions with FOR UPDATE locking to prevent race
 * conditions and ensure atomic series creation.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Services
 */

namespace ClientSyncPro\Services;

use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Service_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Series_Validator {

	/**
	 * A reference to the database manager.
	 *
	 * @var \DependentMedia\ClientSync\Core\Database_Manager
	 */
	private $db_manager;

	public function __construct() {
		$this->db_manager = new Database_Manager();
	}

	/**
	 * Validates the availability of an entire series of appointments.
	 *
	 * When called outside a transaction, performs a non-locking check (for preview).
	 * When called inside create_appointment_series(), uses FOR UPDATE locking.
	 *
	 * @param string $initial_slot_utc The starting slot identifier (Y-m-d H:i:s).
	 * @param array  $dimensions       The dimensions for the booking.
	 * @param string $frequency        'weekly' or 'biweekly'.
	 * @param int    $count            The number of occurrences.
	 * @param bool   $use_locking      Whether to use FOR UPDATE locking (requires active transaction).
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function validate_series_availability( string $initial_slot_utc, array $dimensions, string $frequency, int $count, bool $use_locking = false ) {
		$occurrences = $this->calculate_occurrence_dates( $initial_slot_utc, $frequency, $count );
		if ( is_wp_error( $occurrences ) ) {
			return $occurrences;
		}

		$primary_dim_slug = $this->get_primary_dimension_slug();
		if ( ! $primary_dim_slug || ! isset( $dimensions[ $primary_dim_slug ] ) ) {
			return new \WP_Error( 'invalid_data', __( 'Primary dimension is missing from the request.', 'client-sync-pro' ) );
		}
		$primary_dim_id = $dimensions[ $primary_dim_slug ];

		foreach ( $occurrences as $occurrence_utc ) {
			$slot_id = $this->db_manager->find_slot_id_by_start_time_and_dimensions( $occurrence_utc->format( 'Y-m-d H:i:s' ), $dimensions );

			if ( ! $slot_id ) {
				$site_timezone   = wp_timezone();
				$occurrence_site = ( clone $occurrence_utc )->setTimezone( $site_timezone );
				$formatted_date  = $occurrence_site->format( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ) );

				/* translators: %s: The formatted date and time of the unavailable slot. */
				return new \WP_Error( 'slot_unavailable', sprintf( __( 'The slot on %1$s is not available.', 'client-sync-pro' ), $formatted_date ) );
			}

			// Check capacity for this specific slot.
			if ( $this->is_slot_at_capacity( $slot_id, $primary_dim_id, $use_locking ) ) {
				$site_timezone   = wp_timezone();
				$occurrence_site = ( clone $occurrence_utc )->setTimezone( $site_timezone );
				$formatted_date  = $occurrence_site->format( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ) );

				/* translators: %s: The formatted date and time of the full slot. */
				return new \WP_Error( 'slot_full', sprintf( __( 'The slot on %1$s is full.', 'client-sync-pro' ), $formatted_date ) );
			}
		}

		return true;
	}

	/**
	 * Creates all appointments for a recurring series with full booking integrity.
	 *
	 * Uses a database transaction to atomically:
	 * 1. Validate all slot availability with FOR UPDATE locking.
	 * 2. Create appointment posts with series meta.
	 * 3. Insert wp_clisyc_bookings rows for each occurrence.
	 * 4. Increment booking_count on each slot.
	 * 5. Fire the appointment_created notification.
	 *
	 * On any failure, rolls back all changes (trashes posts, removes booking rows).
	 *
	 * @param string $initial_slot_utc The starting slot identifier.
	 * @param array  $dimensions       The dimensions for the booking.
	 * @param string $frequency        'weekly' or 'biweekly'.
	 * @param int    $count            The number of occurrences.
	 * @param int    $user_id          The ID of the user booking the series.
	 * @param array  $custom_fields    Sanitized custom field data.
	 * @return array|\WP_Error An array of new appointment IDs, or a WP_Error.
	 */
	public function create_appointment_series( string $initial_slot_utc, array $dimensions, string $frequency, int $count, int $user_id, array $custom_fields ): array|\WP_Error {
		global $wpdb;

		$occurrences = $this->calculate_occurrence_dates( $initial_slot_utc, $frequency, $count );
		if ( is_wp_error( $occurrences ) ) {
			return $occurrences;
		}

		$primary_dim_slug  = $this->get_primary_dimension_slug();
		$primary_dim_id    = $dimensions[ $primary_dim_slug ] ?? 0;
		$primary_item_post = get_post( $primary_dim_id );

		if ( ! $primary_item_post ) {
			return new \WP_Error( 'invalid_primary_item', __( 'The primary booking item does not exist.', 'client-sync-pro' ) );
		}

		$bookings_table = $this->db_manager->get_bookings_table_name();
		$slots_table    = $this->db_manager->get_slots_table_name();
		$series_id      = time() . '-' . wp_rand( 100, 999 );
		$new_post_ids   = [];
		// Preserving the historical 30-min fallback for series validation specifically.
		// This differs from the UI default (60) — if a service has no duration meta,
		// the series validator previously assumed 30 min. Flagged for follow-up: the
		// more principled fix is to make this 60 too, but that's a behavior change
		// that needs separate verification.
		$duration       = Service_Helper::get_duration_minutes( (int) $primary_dim_id, 30 );

		// ── Start Transaction ──────────────────────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control required for atomic series creation.
		$wpdb->query( 'START TRANSACTION' );

		// Re-validate with FOR UPDATE locking inside the transaction to prevent race conditions.
		$validation = $this->validate_series_availability( $initial_slot_utc, $dimensions, $frequency, $count, true );
		if ( is_wp_error( $validation ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			return $validation;
		}

		foreach ( $occurrences as $index => $occurrence_utc ) {
			$slot_utc_str      = $occurrence_utc->format( 'Y-m-d H:i:s' );
			$appointment_title = sprintf(
				'%s (Booking %d of %d)',
				$primary_item_post->post_title,
				$index + 1,
				$count
			);

			$appointment_data = [
				'post_title'  => $appointment_title,
				'post_status' => 'publish',
				'post_author' => $user_id,
				'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			];

			$appointment_id = wp_insert_post( $appointment_data, true );

			if ( is_wp_error( $appointment_id ) ) {
				$this->rollback_series( $new_post_ids, $bookings_table );
				return $appointment_id;
			}

			// ── Save appointment meta ──────────────────────────────────
			update_post_meta( $appointment_id, Constants::META_SERIES_ID, $series_id );
			update_post_meta( $appointment_id, Constants::META_TIME_SLOT, $slot_utc_str );
			update_post_meta( $appointment_id, Constants::META_APPOINTMENT_DATE, $occurrence_utc->format( 'Y-m-d' ) );
			update_post_meta( $appointment_id, Constants::META_DURATION_MINUTES, $duration );
			update_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, $dimensions );
			update_post_meta( $appointment_id, Constants::META_BOOKING_MODE, 'slot' );
			update_post_meta( $appointment_id, Constants::META_SERIES_INDEX, $index + 1 );
			update_post_meta( $appointment_id, Constants::META_SERIES_TOTAL, $count );
			update_post_meta( $appointment_id, Constants::META_SERIES_FREQUENCY, $frequency );

			// Save individual dimension meta keys (same pattern as Booking_Ajax_Handler).
			foreach ( $dimensions as $dim_slug => $dim_id ) {
				update_post_meta( $appointment_id, '_' . $dim_slug, $dim_id );
				update_post_meta( $appointment_id, $dim_slug, $dim_id );
			}

			if ( ! empty( $custom_fields ) ) {
				$sanitized_cf = [];
				foreach ( $custom_fields as $key => $value ) {
					$sanitized_cf[ sanitize_key( $key ) ] = sanitize_textarea_field( $value );
					update_post_meta( $appointment_id, sanitize_key( $key ), sanitize_textarea_field( $value ) );
				}
				update_post_meta( $appointment_id, '_clisyc_custom_fields', $sanitized_cf );
			}

			// ── Create booking row & update slot count ─────────────────
			$slot_id = $this->db_manager->find_slot_id_by_start_time_and_dimensions( $slot_utc_str, $dimensions );

			if ( $slot_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct insert required inside transaction for booking integrity.
				$insert_result = $wpdb->insert(
					$bookings_table,
					[
						'slot_id'          => $slot_id,
						'appointment_id'   => $appointment_id,
						'booking_time_utc' => current_time( 'mysql', true ),
					],
					[ '%d', '%d', '%s' ]
				);

				if ( false === $insert_result ) {
					$this->rollback_series( $new_post_ids, $bookings_table );
					return new \WP_Error(
						'booking_row_failed',
						/* translators: %d: The 1-based index of the occurrence that failed. */
						sprintf( __( 'Failed to reserve slot for occurrence %d.', 'client-sync-pro' ), $index + 1 )
					);
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from Database_Manager. Direct query required inside transaction.
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$slots_table} SET booking_count = booking_count + 1 WHERE slot_id = %d",
					$slot_id
				) );
			}

			$new_post_ids[] = $appointment_id;
		}

		// ── Commit Transaction ─────────────────────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'COMMIT' );

		// ── Fire notification for the series (using the first appointment) ──
		if ( ! empty( $new_post_ids ) ) {
			do_action( 'clisyc_appointment_created', $new_post_ids[0] );
		}

		return $new_post_ids;
	}

	/**
	 * Rolls back a partially created series by trashing posts and removing booking rows.
	 *
	 * @param array  $post_ids       The appointment post IDs to clean up.
	 * @param string $bookings_table The bookings table name.
	 */
	private function rollback_series( array $post_ids, string $bookings_table ): void {
		global $wpdb;

		foreach ( $post_ids as $pid ) {
			wp_trash_post( $pid );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup inside rollback.
			$wpdb->delete( $bookings_table, [ 'appointment_id' => $pid ], [ '%d' ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Calculates an array of DateTime objects for each occurrence in a series.
	 *
	 * @param string $initial_slot_utc The starting slot identifier (Y-m-d H:i:s).
	 * @param string $frequency        'weekly' or 'biweekly'.
	 * @param int    $count            The number of occurrences.
	 * @return array|\WP_Error An array of DateTime objects in UTC, or a WP_Error.
	 */
	private function calculate_occurrence_dates( string $initial_slot_utc, string $frequency, int $count ) {
		if ( $count < 2 || $count > 52 ) {
			return new \WP_Error( 'invalid_count', __( 'Recurring count must be between 2 and 52.', 'client-sync-pro' ) );
		}

		$utc_timezone = new \DateTimeZone( 'UTC' );
		try {
			$start_date = new \DateTime( $initial_slot_utc, $utc_timezone );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'invalid_date', __( 'Invalid start date provided for series.', 'client-sync-pro' ) );
		}

		$occurrences     = [ $start_date ];
		$interval_string = ( 'biweekly' === $frequency ) ? '+2 weeks' : '+1 week';

		for ( $i = 1; $i < $count; $i++ ) {
			$next_date     = ( clone $occurrences[ $i - 1 ] )->modify( $interval_string );
			$occurrences[] = $next_date;
		}

		return $occurrences;
	}

	/**
	 * Checks if a specific slot ID is at full capacity.
	 *
	 * @param int  $slot_id        The ID of the slot to check.
	 * @param int  $primary_dim_id The ID of the primary dimension item (e.g., service).
	 * @param bool $use_locking    Whether to use FOR UPDATE locking (requires active transaction).
	 * @return bool True if full, false otherwise.
	 */
	private function is_slot_at_capacity( int $slot_id, int $primary_dim_id, bool $use_locking = false ): bool {
		global $wpdb;
		$capacity = (int) get_post_meta( $primary_dim_id, Constants::META_CAPACITY, true ) ?: 1;

		$bookings_table = $this->db_manager->get_bookings_table_name();
		$lock_clause    = $use_locking ? ' FOR UPDATE' : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely constructed from internal Database_Manager. Direct query and no-caching required for real-time capacity validation. Lock clause is a static string.
		$booked_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(booking_id) FROM {$bookings_table} WHERE slot_id = %d{$lock_clause}", $slot_id ) );

		return ( (int) $booked_count >= $capacity );
	}

	/**
	 * Gets the slug of the currently configured primary dimension.
	 *
	 * @return string|null The slug or null if not found.
	 */
	private function get_primary_dimension_slug(): ?string {
		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				if ( ! empty( $settings['primary'] ) ) {
					return $slug;
				}
			}
		}
		return null;
	}
}
