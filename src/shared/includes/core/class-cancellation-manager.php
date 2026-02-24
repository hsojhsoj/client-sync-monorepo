<?php
/**
 * File: src/shared/includes/core/class-cancellation-manager.php -> client-sync/includes/core/class-cancellation-manager.php
 * Manages the logic for cancelling or rescheduling an appointment.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Capacity_Resolver;
use DependentMedia\ClientSync\Core\Table_Schema_Manager;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cancellation_Manager {

	private $appointment_id;
	private $appointment;
	private $schema;

	public function __construct( int $appointment_id, ?Table_Schema_Manager $schema = null ) {
		$this->appointment_id = $appointment_id;
		$this->appointment    = get_post( $this->appointment_id );
		$this->schema         = $schema ?? new Table_Schema_Manager();
	}

	public function process_cancellation( bool $is_for_reschedule = false ): array {
		if ( ! $this->can_be_managed() ) {
			// *** REFACTORED ***: Updated text domain
			return [ 'success' => false, 'message' => __( 'This appointment cannot be managed at this time. It may be too close to the appointment time or has already been completed.', 'client-sync' ) ];
		}

		// *** REFACTORED ***: Updated filter name
		$new_status = apply_filters( 'clisyc_self_service_cancelled_status', 'trash' );
		if ( 'trash' === $new_status ) {
			if ( ! wp_trash_post( $this->appointment_id ) ) {
				// *** REFACTORED ***: Updated text domain
				return [ 'success' => false, 'message' => __( 'Could not cancel the appointment. Please contact support.', 'client-sync' ) ];
			}
		} else {
			wp_update_post( [ 'ID' => $this->appointment_id, 'post_status' => $new_status ] );
		}

		$this->remove_booking_entry();

		// For group bookings, the slot is shared and should not be recreated upon a single cancellation.
		$dimensions = get_post_meta( $this->appointment_id, Constants::META_SLOT_DIMENSIONS, true );
		$capacity   = Capacity_Resolver::resolve( is_array( $dimensions ) ? $dimensions : [] );

		if ( $capacity <= 1 ) {
			$this->recreate_slot();
		}

		$this->process_refunds();

		// *** REFACTORED ***: Updated class instantiation to use new namespace
		if ( ! $is_for_reschedule && class_exists( '\DependentMedia\ClientSync\Core\Notifications' ) ) {
			$notifications = new Notifications();
			$notifications->send( 'appointment_cancelled_by_client', $this->appointment_id );
		}

		// *** REFACTORED ***: Updated text domain
		return [ 'success' => true, 'message' => __( 'Your appointment has been successfully cancelled.', 'client-sync' ) ];
	}

	private function remove_booking_entry() {
		global $wpdb;
		$bookings_table = $this->schema->get_bookings_table_name();
		$slots_table    = $this->schema->get_slots_table_name();

		// 1. Get the slot ID associated with this appointment before deleting.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely constructed from $wpdb->prefix.
		$sql_get_slot = "SELECT slot_id FROM {$bookings_table} WHERE appointment_id = %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query string is prepared above.
		$slot_id = $wpdb->get_var( $wpdb->prepare( $sql_get_slot, $this->appointment_id ) );

		if ( $slot_id ) {
			$attendees = (int) get_post_meta( $this->appointment_id, '_clisyc_attendee_count', true ) ?: 1;

			// Wrap delete + decrement in a transaction for atomicity.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'START TRANSACTION' );

			try {
				// 2. Delete the booking entry.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $bookings_table, [ 'appointment_id' => $this->appointment_id ], [ '%d' ] );

				// 3. Atomically decrement the booking counter.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix.
				$sql_update_counter = "UPDATE {$slots_table} SET booking_count = GREATEST(0, booking_count - %d) WHERE slot_id = %d";

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query string is prepared above.
				$wpdb->query( $wpdb->prepare( $sql_update_counter, $attendees, $slot_id ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'COMMIT' );

			} catch ( \Exception $e ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );

				Debug_Logger::log( 'remove_booking_entry() exception: ' . $e->getMessage(), 'Cancellation' );
				return;
			}

			// Clear cached slot data so availability queries reflect the freed slot.
			if ( class_exists( '\DependentMedia\ClientSync\Core\Database_Manager' ) ) {
				$db_manager = new Database_Manager();
				$db_manager->clear_all_slot_cache();
			}

			// Fire action so other systems (e.g., waitlist) can react to the freed slot.
			do_action( 'clisyc_slot_freed', (int) $slot_id, $this->appointment_id );
		}
	}

	public function can_be_managed(): bool {
		// *** REFACTORED ***: Updated option key and CPT name
		if ( ! get_option( Constants::OPTION_ENABLE_SELF_SERVICE, false ) || ! $this->appointment || Constants::POST_TYPE_APPOINTMENT !== $this->appointment->post_type ) {
			return false;
		}

		// *** REFACTORED ***: Updated filter name and custom status prefix
		$cancelable_statuses = apply_filters( 'clisyc_self_service_cancelable_statuses', [ 'publish', Constants::STATUS_CONFIRMED, Constants::STATUS_PENDING_PAYMENT ] );
		if ( ! in_array( $this->appointment->post_status, $cancelable_statuses, true ) ) {
			return false;
		}

		// *** REFACTORED ***: Updated constant name for meta key
		$time_slot_utc_str = get_post_meta( $this->appointment_id, Constants::META_TIME_SLOT, true );
		if ( ! $time_slot_utc_str ) {
			return false;
		}

		try {
			// *** REFACTORED ***: Updated option keys
			$cutoff_value = get_option( Constants::OPTION_CANCEL_CUTOFF_VALUE, 24 );
			$cutoff_unit  = get_option( Constants::OPTION_CANCEL_CUTOFF_UNIT, 'hours' );

			$utc_tz  = new \DateTimeZone('UTC');
			$now_dt  = new \DateTime( 'now', $utc_tz );
			$appt_dt = new \DateTime( $time_slot_utc_str, $utc_tz );

			if ( $appt_dt <= $now_dt ) {
				return false;
			}

			if ( $cutoff_value > 0 ) {
				$cutoff_dt = ( clone $now_dt )->modify( "+{$cutoff_value} {$cutoff_unit}" );
				if ( $appt_dt <= $cutoff_dt ) {
					return false;
				}
			}
		} catch ( \Exception $e ) {
			Debug_Logger::log( 'Exception while checking cancellation cutoff: ' . $e->getMessage(), 'Cancellation' );
			return false;
		}

		return true;
	}

	private function recreate_slot() {
		// *** REFACTORED ***: Updated constant and meta key names
		$time_slot_utc_str = get_post_meta( $this->appointment_id, Constants::META_TIME_SLOT, true );
		$duration_minutes  = get_post_meta( $this->appointment_id, Constants::META_APPOINTMENT_DURATION, true );
		$dimensions        = get_post_meta( $this->appointment_id, Constants::META_SLOT_DIMENSIONS, true );

		if ( empty( $time_slot_utc_str ) || empty( $duration_minutes ) || ! is_numeric( $duration_minutes ) || empty($dimensions) || !is_array($dimensions) ) {
			return;
		}

		// *** REFACTORED ***: Updated class instantiation to use new namespace
		if ( class_exists( '\DependentMedia\ClientSync\Core\Database_Manager' ) ) {
			$db_manager = new Database_Manager();
			try {
				$utc_tz       = new \DateTimeZone( 'UTC' );
				$start_dt_utc = new \DateTime( $time_slot_utc_str, $utc_tz );
				$end_dt_utc   = ( clone $start_dt_utc )->modify( "+{$duration_minutes} minutes" );

				$slot_to_insert = [
					'start_time_utc' => $start_dt_utc->format( 'Y-m-d H:i:s' ),
					'end_time_utc'   => $end_dt_utc->format( 'Y-m-d H:i:s' ),
					'is_block'       => false,
					'dimensions'     => $dimensions,
				];

				$db_manager->insert_slots( [ $slot_to_insert ] );

			} catch ( \Exception $e ) {
				Debug_Logger::log( 'Failed to re-insert slot after cancellation: ' . $e->getMessage(), 'Cancellation' );
			}
		}
	}

	/**
	 * Get all appointment IDs that share the same series ID.
	 *
	 * @param int $appointment_id The appointment to look up the series for.
	 * @return int[] Array of appointment post IDs in the series, or empty array if not a series.
	 */
	public static function get_series_appointment_ids( int $appointment_id ): array {
		$series_id = get_post_meta( $appointment_id, Constants::META_SERIES_ID, true );
		if ( empty( $series_id ) ) {
			return [];
		}

		return get_posts( [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'   => Constants::META_SERIES_ID,
					'value' => $series_id,
				],
			],
		] );
	}

	/**
	 * Cancel all future appointments in a recurring series.
	 *
	 * Iterates through all appointments sharing the same series ID and
	 * cancels those that haven't occurred yet. Past appointments are
	 * left untouched.
	 *
	 * @param bool $future_only Whether to skip past appointments (default: true).
	 * @return array Keyed by appointment_id => process_cancellation() result.
	 */
	public function cancel_series( bool $future_only = true ): array {
		$series_ids = self::get_series_appointment_ids( $this->appointment_id );
		if ( empty( $series_ids ) ) {
			// Not a series; cancel just this one appointment.
			return [ $this->appointment_id => $this->process_cancellation() ];
		}

		$results = [];
		$utc     = new \DateTimeZone( 'UTC' );
		$now     = new \DateTime( 'now', $utc );

		foreach ( $series_ids as $appt_id ) {
			if ( $future_only ) {
				$slot_time = get_post_meta( $appt_id, Constants::META_TIME_SLOT, true );
				if ( $slot_time ) {
					try {
						$appt_dt = new \DateTime( $slot_time, $utc );
						if ( $appt_dt <= $now ) {
							continue; // Skip past appointments.
						}
					} catch ( \Exception $e ) {
						continue;
					}
				}
			}
			$cm                  = new self( $appt_id, $this->schema );
			$results[ $appt_id ] = $cm->process_cancellation();
		}
		return $results;
	}

	private function process_refunds() {
		// *** REFACTORED ***: Updated option key
		$refund_policy = get_option( Constants::OPTION_CANCEL_REFUND_POLICY, 'none' );
		if ( 'none' === $refund_policy || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// *** REFACTORED ***: Updated constant for meta key
		$order_id = get_post_meta( $this->appointment_id, Constants::META_WC_ORDER_ID, true );
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->is_paid() ) {
			try {
				$refund = wc_create_refund( [
					'amount'         => $order->get_total(),
					// *** REFACTORED ***: Updated text domain
					'reason'         => __( 'Appointment cancelled by client.', 'client-sync' ),
					'order_id'       => $order_id,
					'refund_payment' => true,
				] );

				if ( is_wp_error( $refund ) ) {
					/* translators: 1: The appointment ID (number). 2: The error message from the payment gateway (string). */
					$note_text_fail = __( 'Client Sync: Client cancelled appointment #%1$d. Automatic refund failed. Reason: %2$s', 'client-sync' );
					$order->add_order_note(
						sprintf(
							$note_text_fail,
							$this->appointment_id,
							$refund->get_error_message()
						)
					);
				} else {
					/* translators: 1: The appointment ID (number). 2: The refund ID from WooCommerce (string or number). */
					$note_text_success = __( 'Client Sync: Client cancelled appointment #%1$d. Full refund processed successfully. Refund ID: %2$s', 'client-sync' );
					$order->add_order_note(
						sprintf(
							$note_text_success,
							$this->appointment_id,
							$refund->get_id()
						)
					);
				}
			} catch ( \Exception $e ) {
				/* translators: 1: The appointment ID (number). 2: The exception message (string). */
				$note_text_exception = __( 'Client Sync: Client cancelled appointment #%1$d. An exception occurred during automatic refund processing: %2$s', 'client-sync' );
				$order->add_order_note(
					sprintf(
						$note_text_exception,
						$this->appointment_id,
						$e->getMessage()
					)
				);
			}
		}
	}
}