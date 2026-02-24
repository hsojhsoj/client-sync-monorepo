<?php
/**
 * File: src/shared/includes/ajax/class-booking-ajax-handler.php
 *
 * Handles the booking submission AJAX action (frontend modal). Processes both
 * logged-in users and guests, validates time-slot conflicts, creates the
 * appointment post, and optionally redirects to WooCommerce checkout.
 *
 * FIXED: Added phpcs ignores for DirectDatabaseQuery warnings in _create_appointment.
 *
 * @package ClientSync
 */

namespace DependentMedia\ClientSync\Ajax;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Utility\Debug_Logger;
use DependentMedia\ClientSync\Utility\Security_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Ajax_Handler {

	private $db;

	public function __construct( Database_Manager $db_manager ) {
		$this->db = $db_manager;
	}

	public function register_hooks() {
		add_action( 'wp_ajax_clisyc_submit_booking', [ $this, 'ajax_submit_booking' ] );
		add_action( 'wp_ajax_nopriv_clisyc_submit_booking', [ $this, 'ajax_submit_booking' ] );
		add_action( 'wp_ajax_clisyc_join_waitlist', [ $this, 'ajax_join_waitlist' ] );
		add_action( 'wp_ajax_nopriv_clisyc_join_waitlist', [ $this, 'ajax_join_waitlist' ] );
	}

	/**
	 * AJAX handler for submitting a booking from the frontend modal.
	 * Processes both logged-in users and guests.
	 */
	public function ajax_submit_booking() {
		Debug_Logger::log( 'ajax_submit_booking() called', 'AJAX' );
		Debug_Logger::log_request( wp_unslash( $_POST ), 'AJAX' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Logging only, nonce verified below.

		// Rate limiting — prevent booking spam (5 bookings per 5 minutes per IP).
		$rate_check = Security_Helper::check_rate_limit( 'submit_booking', Constants::RATE_LIMIT_BOOKING_SUBMIT, Constants::RATE_LIMIT_BOOKING_WINDOW_SECS );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( [ 'message' => $rate_check->get_error_message() ], 429 );
			return;
		}

		// Verify nonce
		if ( ! check_ajax_referer( Constants::POST_TYPE_APPOINTMENT, 'clisyc_appointment_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security verification failed. Please refresh the page and try again.', 'client-sync' ) ] );
			return;
		}

		// Anti-spam checks
		// Honeypot check
		if ( get_option( Constants::OPTION_ANTISPAM_HONEYPOT, false ) && ! empty( $_POST['contact_email_hp'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Spam detected.', 'client-sync' ) ] );
			return;
		}

		// Timing check
		if ( get_option( Constants::OPTION_ANTISPAM_TIME_CHECK, false ) ) {
			$submitted_timestamp = isset( $_POST['clisyc_timestamp'] ) ? absint( $_POST['clisyc_timestamp'] ) : 0;
			$current_timestamp   = time();
			if ( $submitted_timestamp > 0 && ( $current_timestamp - $submitted_timestamp ) < 3 ) {
				wp_send_json_error( [ 'message' => __( 'Please wait a moment before submitting.', 'client-sync' ) ] );
				return;
			}
		}

		// Get booking mode
		$booking_mode = isset( $_POST['booking_mode'] ) ? sanitize_key( $_POST['booking_mode'] ) : 'slot';

		// Validate required fields based on booking mode
		$time_slot_raw = '';
		$time_slot_iso = '';
		if ( 'slot' === $booking_mode ) {
			$time_slot_raw = isset( $_POST['time_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['time_slot'] ) ) : '';
			// NEW: Also grab the ISO format as backup
			$time_slot_iso = isset( $_POST['time_slot_iso'] ) ? sanitize_text_field( wp_unslash( $_POST['time_slot_iso'] ) ) : '';

			if ( empty( $time_slot_raw ) && empty( $time_slot_iso ) ) {
				wp_send_json_error( [ 'message' => __( 'Please select a time slot.', 'client-sync' ) ] );
				return;
			}
		}

		// Handle guest vs logged-in user
		$user_id  = get_current_user_id();
		$is_guest = ( 0 === $user_id );

		if ( $is_guest ) {
			$guest_email = isset( $_POST['guest_email'] ) ? sanitize_email( wp_unslash( $_POST['guest_email'] ) ) : '';
			$guest_name  = isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '';

			if ( empty( $guest_email ) || ! is_email( $guest_email ) ) {
				wp_send_json_error( [ 'message' => __( 'Please provide a valid email address.', 'client-sync' ) ] );
				return;
			}

			if ( empty( $guest_name ) ) {
				wp_send_json_error( [ 'message' => __( 'Please provide your name.', 'client-sync' ) ] );
				return;
			}

			// Check if user exists with this email
			$existing_user = get_user_by( 'email', $guest_email );
			if ( $existing_user ) {
				$user_id = $existing_user->ID;
			} else {
				// Create a new user for the guest
				$username = sanitize_user( strtok( $guest_email, '@' ) );
				$username = $this->generate_unique_username( $username );

				$random_password = wp_generate_password( 16, true, true );
				$user_id         = wp_create_user( $username, $random_password, $guest_email );

				if ( is_wp_error( $user_id ) ) {
					wp_send_json_error( [ 'message' => __( 'Could not create account. Please try again.', 'client-sync' ) ] );
					return;
				}

				// Update display name
				wp_update_user(
					[
						'ID'           => $user_id,
						'display_name' => $guest_name,
						'first_name'   => $guest_name,
					]
				);

				// Send new user notification
				wp_new_user_notification( $user_id, null, 'user' );
			}
		}

		// Get dimension registry for dimension handling
		$registry   = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$dimensions = [];

		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $dim_slug => $dim_settings ) {
				if ( ! empty( $dim_settings['enabled'] ) && isset( $_POST[ $dim_slug ] ) ) {
					$dim_value = sanitize_text_field( wp_unslash( $_POST[ $dim_slug ] ) );
					if ( ! empty( $dim_value ) ) {
						$dimensions[ $dim_slug ] = absint( $dim_value );
					}
				}
			}
		}

		// NEW: Intelligent WooCommerce integration check
		$primary_dim_id = 0;
		$primary_dim_slug = '';
		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );

		// Find the ID of the primary item being booked
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) && isset( $dimensions[ $slug ] ) ) {
				$primary_dim_id = absint( $dimensions[ $slug ] );
				$primary_dim_slug = $slug;
				break;
			}
		}

		// Check if this specific service has a linked product
		$service_product_id = $primary_dim_id ? absint( get_post_meta( $primary_dim_id, Constants::META_WC_PRODUCT_ID, true ) ) : 0;
		$global_product_id  = absint( get_option( 'clisyc_wc_appointment_product_id', 0 ) );

		// A product exists if either the specific service has one OR a global fallback exists
		$final_product_id = ( $service_product_id > 0 ) ? $service_product_id : $global_product_id;

		$wc_enabled = class_exists( 'WooCommerce' ) &&
					  get_option( 'clisyc_wc_integration_enabled', false ) &&
					  $final_product_id > 0;

		$stripe_enabled = \DependentMedia\ClientSync\Integrations\Stripe_Integration::is_active();

		// Get the appointment date for title generation
		$appointment_date = isset( $_POST['appointment_date'] ) ? sanitize_text_field( wp_unslash( $_POST['appointment_date'] ) ) : '';

		// CRITICAL: Check for double-booking BEFORE creating appointment
		// Uses the bookings table (same source as calendar availability) for consistency
		if ( 'slot' === $booking_mode && ! empty( $time_slot_raw ) ) {
			$time_slot_to_check = $time_slot_raw;

			// If raw doesn't parse, try ISO
			if ( ! strtotime( $time_slot_to_check ) && ! empty( $time_slot_iso ) ) {
				try {
					$dt = new \DateTime( $time_slot_iso );
					$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
					$time_slot_to_check = $dt->format( 'Y-m-d H:i:s' );
				} catch ( \Exception $e ) {
					Debug_Logger::log( 'Timezone conversion failed for time slot: ' . $e->getMessage(), 'AJAX' );
				}
			}

			Debug_Logger::log( 'Starting conflict check (using bookings table)', 'Conflict' );
			Debug_Logger::log( 'time_slot_to_check: ' . $time_slot_to_check, 'Conflict' );
			Debug_Logger::log_data( 'dimensions', $dimensions, 'Conflict' );

			// Get resource dimensions from registry (these are the ones that cause conflicts)
			$resource_dims = [];
			if ( ! empty( $registry['dimensions'] ) ) {
				foreach ( $registry['dimensions'] as $dim_slug => $dim_settings ) {
					if ( ! empty( $dim_settings['is_resource'] ) && isset( $dimensions[ $dim_slug ] ) ) {
						$resource_dims[ $dim_slug ] = $dimensions[ $dim_slug ];
					}
				}
			}

			Debug_Logger::log_data( 'resource_dims', $resource_dims, 'Conflict' );

			// Use injected Database_Manager for conflict checks.
			$db_manager = $this->db;
			global $wpdb;

			$has_conflict = false;
			$conflict_reason = '';

			// Method 1: Check if the exact slot (with all dimensions) is already booked
			$slot_id = $db_manager->find_slot_id_by_start_time_and_dimensions( $time_slot_to_check, $dimensions );

			if ( $slot_id ) {
				$bookings_table = $db_manager->get_bookings_table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from internal method and escaped.
				$booking_count = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM " . esc_sql( $bookings_table ) . " WHERE slot_id = %d",
					$slot_id
				) );

				$capacity = 1;
				if ( $primary_dim_id ) {
					$capacity = (int) get_post_meta( $primary_dim_id, Constants::META_CAPACITY, true ) ?: 1;
				}

				if ( (int) $booking_count >= $capacity ) {
					$has_conflict = true;
					$conflict_reason = 'Slot ' . $slot_id . ' is at capacity (' . $booking_count . '/' . $capacity . ')';
				}

				Debug_Logger::log( 'Found slot_id: ' . $slot_id . ', bookings: ' . $booking_count . ', capacity: ' . $capacity, 'Conflict' );
			}

			// Method 2: Check for resource conflicts (same resource at same time, different practitioner/service)
			if ( ! $has_conflict && ! empty( $resource_dims ) ) {
				$slots_table = $db_manager->get_slots_table_name();
				$dims_table = $db_manager->get_dimensions_table_name();
				$bookings_table = $db_manager->get_bookings_table_name();

				foreach ( $resource_dims as $resource_key => $resource_id ) {
					// Find any slot at the same time with the same resource that has a booking
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safely constructed from internal methods and escaped.
					$conflicting_booking = $wpdb->get_var( $wpdb->prepare(
						"SELECT b.booking_id
						FROM " . esc_sql( $bookings_table ) . " b
						INNER JOIN " . esc_sql( $slots_table ) . " s ON b.slot_id = s.slot_id
						INNER JOIN " . esc_sql( $dims_table ) . " d ON s.slot_id = d.slot_id
						WHERE s.start_time = %s
						AND d.dimension_key = %s
						AND d.dimension_value = %s
						LIMIT 1",
						$time_slot_to_check,
						$resource_key,
						$resource_id
					) );

					if ( $conflicting_booking ) {
						$has_conflict = true;
						$conflict_reason = 'Resource conflict: ' . $resource_key . '=' . $resource_id . ' at ' . $time_slot_to_check;

						Debug_Logger::log( 'Resource conflict found: booking_id=' . $conflicting_booking, 'Conflict' );
						break;
					}
				}
			}

			// Method 3: Check appointment posts directly (for frontend wizard bookings)
			// This catches bookings that were made via frontend forms which create appointment posts
			// but may not insert into the bookings table.
			if ( ! $has_conflict ) {
				$existing_appointments = get_posts( [
					'post_type'      => Constants::POST_TYPE_APPOINTMENT,
					'post_status'    => [ 'publish', Constants::STATUS_CONFIRMED, 'clisyc_paid_on_day', 'wc-completed', 'wc-processing', Constants::STATUS_PENDING ],
					'posts_per_page' => 20,
					'no_found_rows'  => true,
					'meta_query'     => [
						[
							'key'     => Constants::META_TIME_SLOT,
							'value'   => $time_slot_to_check,
							'compare' => '=',
						],
					],
					'fields'         => 'ids',
				] );

				if ( ! empty( $existing_appointments ) ) {
					// Check if any of these appointments match our dimensions (resource conflict)
					foreach ( $existing_appointments as $existing_appt_id ) {
						$existing_dims = get_post_meta( $existing_appt_id, Constants::META_SLOT_DIMENSIONS, true );

						if ( ! is_array( $existing_dims ) ) {
							continue;
						}

						// Check resource dimensions for conflicts
						foreach ( $resource_dims as $resource_key => $resource_id ) {
							if ( isset( $existing_dims[ $resource_key ] ) &&
								 absint( $existing_dims[ $resource_key ] ) === absint( $resource_id ) ) {
								$has_conflict = true;
								$conflict_reason = 'Appointment post conflict: existing appointment #' . $existing_appt_id .
												   ' uses ' . $resource_key . '=' . $resource_id . ' at ' . $time_slot_to_check;

								Debug_Logger::log( $conflict_reason, 'Conflict' );
								break 2;
							}
						}

						// If no resource dims defined, check for exact dimension match (all dims must match)
						if ( empty( $resource_dims ) && $existing_dims === $dimensions ) {
							// Check capacity
							$appt_count = count( $existing_appointments );
							$capacity = $primary_dim_id ? (int) get_post_meta( $primary_dim_id, Constants::META_CAPACITY, true ) : 1;
							$capacity = $capacity ?: 1;

							if ( $appt_count >= $capacity ) {
								$has_conflict = true;
								$conflict_reason = 'Appointment post conflict: slot at capacity (' . $appt_count . '/' . $capacity . ')';

								Debug_Logger::log( $conflict_reason, 'Conflict' );
								break;
							}
						}
					}
				}
			}

				Debug_Logger::log( 'Result: ' . ( $has_conflict ? 'CONFLICT - ' . $conflict_reason : 'NO CONFLICT' ), 'Conflict' );

			if ( $has_conflict ) {
				$waitlist_available = false;
				$waitlist_position  = 0;

				if ( \DependentMedia\ClientSync\Core\Waitlist_Manager::is_enabled() && $slot_id ) {
					$waitlist_available = \DependentMedia\ClientSync\Core\Waitlist_Manager::has_waitlist_room( (int) $slot_id );
					$waitlist_position  = \DependentMedia\ClientSync\Core\Waitlist_Manager::get_waitlist_count( (int) $slot_id ) + 1;
				}

				wp_send_json_error(
					[
						'message'            => __( 'Sorry, this time slot is fully booked.', 'client-sync' ),
						'code'               => 'slot_unavailable',
						'waitlist_available'  => $waitlist_available,
						'slot_id'             => $slot_id ? (int) $slot_id : 0,
						'waitlist_position'   => $waitlist_position,
					]
				);
				return;
			}
		}

		// Prepare the appointment post data
		$appointment_title = $this->generate_appointment_title( $dimensions, $user_id, $appointment_date );
		$default_status = 'publish';

		$appointment_data = [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => $wc_enabled ? Constants::STATUS_PENDING : $default_status,
			'post_title'  => $appointment_title,
			'post_author' => $user_id,
		];

		// Prepare custom fields for later
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array is immediately sanitized in the foreach loop below.
		$custom_fields_raw = isset( $_POST['clisyc_custom_field'] ) && is_array( $_POST['clisyc_custom_field'] ) ? wp_unslash( $_POST['clisyc_custom_field'] ) : [];
		$custom_fields = [];
		if ( ! empty( $custom_fields_raw ) ) {
			foreach ( $custom_fields_raw as $key => $value ) {
				$custom_fields[ sanitize_key( $key ) ] = sanitize_textarea_field( $value );
			}
		}

		// RACE CONDITION FIX: For slot-mode bookings with a valid slot_id,
		// create the appointment post INSIDE the transactional lock to prevent
		// orphaned posts from concurrent requests.
		$appointment_id = 0;

		if ( 'slot' === $booking_mode && ! empty( $time_slot_raw ) && isset( $slot_id ) && $slot_id ) {
			$capacity = 1;
			if ( $primary_dim_id ) {
				$capacity = (int) get_post_meta( $primary_dim_id, Constants::META_CAPACITY, true ) ?: 1;
			}

			$attendees = isset( $_POST['clisyc_attendees'] ) ? max( 1, absint( wp_unslash( $_POST['clisyc_attendees'] ) ) ) : 1;

			// _reserve_slot now creates the post inside the transaction.
			$appointment_id = $this->_reserve_slot( $appointment_data, (int) $slot_id, $capacity, $attendees );

			if ( false === $appointment_id ) {
				wp_send_json_error( [
					'message' => __( 'Sorry, this time slot was just booked by someone else. Please select another time.', 'client-sync' ),
					'code'    => 'slot_unavailable',
				] );
				return;
			}

			// Save the slot identifier meta
			update_post_meta( $appointment_id, '_clisyc_slot_identifier', $time_slot_raw );
		} else {
			// For non-slot bookings or bookings without a slot_id, create post normally
			$appointment_id = wp_insert_post( $appointment_data );

			if ( is_wp_error( $appointment_id ) ) {
				wp_send_json_error( [ 'message' => __( 'Failed to create appointment. Please try again.', 'client-sync' ) ] );
				return;
			}
		}

		// Save appointment meta
		if ( 'slot' === $booking_mode ) {
			// CRITICAL FIX: Convert time slot to proper MySQL DATETIME format (Y-m-d H:i:s)
			$time_slot_mysql = $this->convert_to_mysql_datetime( $time_slot_raw );

			if ( ! $time_slot_mysql && ! empty( $time_slot_iso ) ) {
				$time_slot_mysql = $this->convert_to_mysql_datetime( $time_slot_iso );
			}

			if ( $time_slot_mysql ) {
				update_post_meta( $appointment_id, Constants::META_TIME_SLOT, $time_slot_mysql );
			} else {
				if ( ! empty( $time_slot_iso ) ) {
					try {
						$dt = new \DateTime( $time_slot_iso );
						$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
						update_post_meta( $appointment_id, Constants::META_TIME_SLOT, $dt->format( 'Y-m-d H:i:s' ) );
					} catch ( \Exception $e ) {
						update_post_meta( $appointment_id, Constants::META_TIME_SLOT, $time_slot_raw ?: $time_slot_iso );
					}
				} else {
					update_post_meta( $appointment_id, Constants::META_TIME_SLOT, $time_slot_raw );
				}
			}

			update_post_meta( $appointment_id, Constants::META_BOOKING_MODE, 'slot' );

			if ( $appointment_date ) {
				update_post_meta( $appointment_id, Constants::META_APPOINTMENT_DATE, $appointment_date );
			}

			$duration = isset( $_POST['appointment_duration_minutes'] ) ? absint( $_POST['appointment_duration_minutes'] ) : 30;
			update_post_meta( $appointment_id, Constants::META_DURATION_MINUTES, $duration );
		}

		// Save dimensions - CRITICAL: Save BOTH as array AND as individual meta keys
		if ( ! empty( $dimensions ) ) {
			update_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, $dimensions );

			foreach ( $dimensions as $dim_slug => $dim_id ) {
				update_post_meta( $appointment_id, '_' . $dim_slug, $dim_id );
				update_post_meta( $appointment_id, $dim_slug, $dim_id );
			}
		}

		// Save custom fields
		if ( ! empty( $custom_fields ) ) {
			update_post_meta( $appointment_id, '_clisyc_custom_fields', $custom_fields );
		}

		// Handle WooCommerce payment if enabled
		if ( $wc_enabled ) {
			Debug_Logger::log( 'WooCommerce is active. Building dynamic cart...', 'AJAX' );

			// --- A. CALCULATE BASE MULTIPLIERS ---
			$num_nights = 1;
			// Definition of posted_data needed here for the snippet to work
			$posted_data = wp_unslash( $_POST );

			if ( 'date_range' === $booking_mode ) {
				$raw_start = sanitize_text_field( $posted_data['start_date'] ?? '' );
				$raw_end   = sanitize_text_field( $posted_data['end_date'] ?? '' );
				// Validate date format (Y-m-d) to prevent unexpected DateTime parsing.
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_start ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_end ) ) {
					try {
						$start_dt   = new \DateTime( $raw_start );
						$end_dt     = new \DateTime( $raw_end );
						$num_nights = $start_dt->diff( $end_dt )->days;
					} catch ( \Exception $e ) { $num_nights = 1; }
				}
			}
			// If slot mode, "per_night" logic treats the appointment as 1 unit.
			$num_nights = max( 1, $num_nights );

			$duration_minutes = isset( $_POST['appointment_duration_minutes'] ) ? absint( wp_unslash( $_POST['appointment_duration_minutes'] ) ) : 0;
			$attendees        = isset( $_POST['clisyc_attendees'] ) ? absint( wp_unslash( $_POST['clisyc_attendees'] ) ) : 1;
			$attendees        = max( 1, $attendees );

			// --- B. DEFINE CALCULATION HELPER ---
			// Returns an integer quantity. For time-based pricing (per_hour, per_minute),
			// the fractional rate is pre-calculated into the product price override instead,
			// because WooCommerce truncates cart quantity to int.
			$get_calc_qty = function( $calc_type, $nights, $duration ) {
				switch ( $calc_type ) {
					case 'per_hour':
					case 'per_minute':
						// Always use qty=1; the price is multiplied by the time factor
						// in the cart item's price override to avoid WooCommerce int truncation.
						return 1;
					case 'flat':
						return 1;
					case 'per_night':
					default:
						return max( 1, (int) $nights );
				}
			};

			// Helper to compute the price multiplier for time-based pricing.
			// This factor is applied to the product price instead of the quantity.
			$get_time_price_factor = function( $calc_type, $duration ) {
				switch ( $calc_type ) {
					case 'per_hour':
						return $duration / 60; // e.g. 90 minutes = 1.5x
					case 'per_minute':
						return (float) $duration;
					default:
						return 1.0;
				}
			};

			// --- C. CLEAR CART & INITIALIZE ATOMIC UNIT ---
			WC()->cart->empty_cart();
			$base_cart_data = [ '_clisyc_appointment_id' => $appointment_id ];

			// --- D. ADD PRIMARY ITEM (The Boat/Service) ---
			// Use the product ID found earlier (specific service product or global fallback)
			$primary_calc_type = get_post_meta( $primary_dim_id, Constants::META_ADDON_CALC_TYPE, true ) ?: 'per_night';

			// For time-based pricing, qty=1 and the price factor is passed as cart item data
			// so WooCommerce doesn't truncate fractional quantities.
			$primary_qty          = $get_calc_qty( $primary_calc_type, $num_nights, $duration_minutes ) * $attendees;
			$primary_price_factor = $get_time_price_factor( $primary_calc_type, $duration_minutes );

			WC()->cart->add_to_cart( $final_product_id, $primary_qty, 0, [], array_merge( $base_cart_data, [
				'_clisyc_is_primary'      => true,
				'_clisyc_price_factor'    => $primary_price_factor,
				Constants::META_SERVICE_NAME => get_the_title( $primary_dim_id ),
			] ) );

			// --- E. ADD SELECTED DIMENSION ITEMS (The Captain, The Guide, etc.) ---
			foreach ( $dimensions as $dim_slug => $item_id ) {
				if ( $dim_slug === $primary_dim_slug ) continue; // Already added as primary

				$addon_product_id = (int) get_post_meta( $item_id, Constants::META_WC_PRODUCT_ID, true );
				if ( $addon_product_id > 0 ) {
					$addon_calc_type    = get_post_meta( $item_id, Constants::META_ADDON_CALC_TYPE, true ) ?: 'per_night';
					$addon_qty          = $get_calc_qty( $addon_calc_type, $num_nights, $duration_minutes );
					$addon_price_factor = $get_time_price_factor( $addon_calc_type, $duration_minutes );

					WC()->cart->add_to_cart( $addon_product_id, $addon_qty, 0, [], array_merge( $base_cart_data, [
						'_clisyc_is_addon'     => true,
						'_clisyc_price_factor' => $addon_price_factor,
					] ) );
				}
			}

			// --- F. ADD MANDATORY FEES (Cleaning, Insurance, etc.) ---
			$mandatory_fees = get_post_meta( $primary_dim_id, Constants::META_ADDITIONAL_PRODUCTS, true );
			if ( is_array( $mandatory_fees ) ) {
				foreach ( $mandatory_fees as $fee ) {
					$fee_product_id = absint( $fee['product_id'] );
					if ( $fee_product_id > 0 ) {
						$fee_calc_type    = $fee['calc_type'] ?? 'flat';
						$fee_qty          = $get_calc_qty( $fee_calc_type, $num_nights, $duration_minutes );
						$fee_price_factor = $get_time_price_factor( $fee_calc_type, $duration_minutes );

						WC()->cart->add_to_cart( $fee_product_id, $fee_qty, 0, [], array_merge( $base_cart_data, [
							'_clisyc_is_mandatory'  => true,
							'_clisyc_price_factor'  => $fee_price_factor,
						] ) );
					}
				}
			}

			// Send success response for redirect
			wp_send_json_success( [
				'message'        => __( 'Redirecting to payment...', 'client-sync' ),
				'redirect_url'   => wc_get_checkout_url(),
				'appointment_id' => $appointment_id,
				'payment_required' => true,
			] );
			return;
		}

		// ── Stripe Checkout (no WooCommerce required) ──
		if ( $stripe_enabled ) {
			// Calculate price from the service's stored price meta.
			$price_raw = $primary_dim_id ? get_post_meta( $primary_dim_id, Constants::META_PRICE, true ) : 0;
			$amount    = (int) round( (float) $price_raw * 100 ); // Convert to cents.

			if ( $amount > 0 ) {
				// Set appointment to pending-payment status.
				wp_update_post( [ 'ID' => $appointment_id, 'post_status' => Constants::STATUS_PENDING_PAYMENT ] );

				$stripe   = new \DependentMedia\ClientSync\Integrations\Stripe_Integration();
				$session  = $stripe->create_checkout_session(
					$appointment_id,
					$amount,
					'',
					get_the_title( $primary_dim_id ) ?: get_the_title( $appointment_id )
				);

				if ( is_wp_error( $session ) ) {
					Debug_Logger::log_critical( 'Stripe checkout session creation failed for appointment #' . $appointment_id . ': ' . $session->get_error_message(), 'Stripe' );
					wp_send_json_error( [ 'message' => __( 'An unexpected error occurred. Please try again.', 'client-sync' ) ] );
					return;
				}

				wp_send_json_success( [
					'message'          => __( 'Redirecting to payment...', 'client-sync' ),
					'redirect_url'     => $session['url'],
					'appointment_id'   => $appointment_id,
					'payment_required' => true,
				] );
				return;
			}
		}

		// Send notification emails
		do_action( 'clisyc_appointment_created', $appointment_id );

		// Get success page URL
		$success_page_id = absint( get_option( Constants::OPTION_BOOKING_SUCCESS_PAGE, 0 ) );
		$redirect_url    = $success_page_id > 0
			? add_query_arg( 'appointment_id', $appointment_id, get_permalink( $success_page_id ) )
			: home_url();

		wp_send_json_success(
			[
				'message'        => __( 'Your appointment has been booked successfully!', 'client-sync' ),
				'appointment_id' => $appointment_id,
				'redirect_url'   => $redirect_url,
			]
		);
	}

	/**
	 * AJAX handler for joining the waitlist when a slot is fully booked.
	 */
	public function ajax_join_waitlist() {
		// Rate limiting — prevent waitlist spam.
		$rate_check = Security_Helper::check_rate_limit( 'join_waitlist', Constants::RATE_LIMIT_WAITLIST_JOIN, Constants::RATE_LIMIT_WAITLIST_WINDOW_SECS );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( [ 'message' => $rate_check->get_error_message() ], 429 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below via check_ajax_referer.
		check_ajax_referer( 'clisyc_appointment', 'clisyc_appointment_nonce' );

		if ( ! \DependentMedia\ClientSync\Core\Waitlist_Manager::is_enabled() ) {
			wp_send_json_error( [ 'message' => __( 'The waitlist is not available.', 'client-sync' ) ] );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$slot_id = isset( $_POST['slot_id'] ) ? absint( wp_unslash( $_POST['slot_id'] ) ) : 0;
		if ( ! $slot_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid slot.', 'client-sync' ) ] );
			return;
		}

		if ( ! \DependentMedia\ClientSync\Core\Waitlist_Manager::has_waitlist_room( $slot_id ) ) {
			wp_send_json_error( [ 'message' => __( 'The waitlist for this slot is full.', 'client-sync' ) ] );
			return;
		}

		// Resolve user — same pattern as ajax_submit_booking.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$guest_email = isset( $_POST['guest_email'] ) ? sanitize_email( wp_unslash( $_POST['guest_email'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$guest_name  = isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '';

			if ( empty( $guest_email ) ) {
				wp_send_json_error( [ 'message' => __( 'Please provide an email address.', 'client-sync' ) ] );
				return;
			}

			$existing_user = get_user_by( 'email', $guest_email );
			if ( $existing_user ) {
				$user_id = $existing_user->ID;
			} else {
				$username = sanitize_user( current( explode( '@', $guest_email ) ), true );
				if ( username_exists( $username ) ) {
					$username = $username . '_' . wp_rand( 100, 999 );
				}
				$user_id = wp_create_user( $username, wp_generate_password(), $guest_email );
				if ( is_wp_error( $user_id ) ) {
					wp_send_json_error( [ 'message' => __( 'Could not create account. Please try again.', 'client-sync' ) ] );
					return;
				}
				if ( ! empty( $guest_name ) ) {
					$name_parts = explode( ' ', $guest_name, 2 );
					wp_update_user( [
						'ID'           => $user_id,
						'first_name'   => $name_parts[0],
						'last_name'    => $name_parts[1] ?? '',
						'display_name' => $guest_name,
					] );
				}
			}
		}

		// Gather booking data from POST.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$time_slot_raw = isset( $_POST['time_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['time_slot'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$time_slot_iso = isset( $_POST['time_slot_iso'] ) ? sanitize_text_field( wp_unslash( $_POST['time_slot_iso'] ) ) : '';

		$time_slot_mysql = $this->convert_to_mysql_datetime( $time_slot_raw );
		if ( ! $time_slot_mysql && ! empty( $time_slot_iso ) ) {
			$time_slot_mysql = $this->convert_to_mysql_datetime( $time_slot_iso );
		}
		if ( ! $time_slot_mysql ) {
			wp_send_json_error( [ 'message' => __( 'Invalid time slot.', 'client-sync' ) ] );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$appointment_date = isset( $_POST['appointment_date'] ) ? sanitize_text_field( wp_unslash( $_POST['appointment_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$duration_minutes = isset( $_POST['appointment_duration_minutes'] ) ? absint( wp_unslash( $_POST['appointment_duration_minutes'] ) ) : 0;

		// Build dimensions array from POST data.
		$dimensions = [];
		$registry   = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( isset( $_POST[ $slug ] ) ) {
					$dimensions[ $slug ] = absint( wp_unslash( $_POST[ $slug ] ) );
				}
			}
		}

		// Custom fields.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$custom_fields_raw = isset( $_POST['clisyc_custom_field'] ) && is_array( $_POST['clisyc_custom_field'] ) ? wp_unslash( $_POST['clisyc_custom_field'] ) : [];
		$custom_fields     = [];
		foreach ( $custom_fields_raw as $key => $value ) {
			$custom_fields[ sanitize_key( $key ) ] = sanitize_textarea_field( $value );
		}

		$result = \DependentMedia\ClientSync\Core\Waitlist_Manager::add_to_waitlist( [
			'user_id'          => $user_id,
			'slot_id'          => $slot_id,
			'time_slot'        => $time_slot_mysql,
			'dimensions'       => $dimensions,
			'appointment_date' => $appointment_date,
			'duration_minutes' => $duration_minutes,
			'custom_fields'    => $custom_fields,
		] );

		if ( is_wp_error( $result ) ) {
			Debug_Logger::log_critical( 'Waitlist join failed for slot #' . $slot_id . ': ' . $result->get_error_message(), 'Waitlist' );
			wp_send_json_error( [ 'message' => __( 'An unexpected error occurred. Please try again.', 'client-sync' ) ] );
			return;
		}

		$position = (int) get_post_meta( $result, Constants::META_WAITLIST_POSITION, true );

		wp_send_json_success( [
			'message'        => __( 'You have been added to the waitlist!', 'client-sync' ),
			'appointment_id' => $result,
			'position'       => $position,
		] );
	}

	/**
	 * Atomically reserve a slot and create the appointment post using transactional locking.
	 *
	 * This method prevents double-bookings by:
	 * 1. Starting a MySQL transaction
	 * 2. Locking the slot row with SELECT ... FOR UPDATE
	 * 3. Re-checking capacity inside the transaction
	 * 4. Creating the appointment post (inside the lock window)
	 * 5. Inserting a row into the bookings table
	 * 6. Incrementing the booking_count on the slot
	 * 7. Committing or rolling back (cleaning up the post on rollback)
	 *
	 * RACE CONDITION FIX: Post creation now happens INSIDE the transaction,
	 * eliminating the window where two concurrent requests could both create
	 * posts before the lock check.
	 *
	 * @param array $appointment_data The wp_insert_post data array.
	 * @param int   $slot_id          The slot ID to reserve.
	 * @param int   $capacity         The maximum allowed bookings for this slot.
	 * @param int   $attendees        The number of attendees (seats to reserve).
	 * @return int|false The appointment post ID on success, false if slot was full.
	 */
	private function _reserve_slot( array $appointment_data, int $slot_id, int $capacity, int $attendees = 1 ) {
		global $wpdb;

		$slots_table    = $this->db->get_slots_table_name();
		$bookings_table = $this->db->get_bookings_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );

		try {
			// Lock the slot row and get current booking count.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$slot_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT slot_id, booking_count FROM " . esc_sql( $slots_table ) . " WHERE slot_id = %d FOR UPDATE",
				$slot_id
			) );

			if ( ! $slot_row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );
				return false;
			}

			// Re-check capacity inside the transaction (authoritative check).
			if ( ( (int) $slot_row->booking_count + $attendees ) > $capacity ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );
				return false;
			}

			// Create the appointment post INSIDE the lock window.
			// This prevents orphaned posts from concurrent requests.
			$appointment_id = wp_insert_post( $appointment_data, true );

			if ( is_wp_error( $appointment_id ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );
				Debug_Logger::log_critical( '_reserve_slot() wp_insert_post failed: ' . $appointment_id->get_error_message(), 'Booking' );
				return false;
			}

			// Insert a booking entry (same pattern as Waitlist_Manager::_do_promote).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->insert(
				$bookings_table,
				[
					'appointment_id'   => $appointment_id,
					'slot_id'          => $slot_id,
					'booking_time_utc' => current_time( 'mysql', 1 ),
				],
				[ '%d', '%d', '%s' ]
			);

			if ( false === $inserted ) {
				// Clean up the post since booking insert failed.
				wp_delete_post( $appointment_id, true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );
				return false;
			}

			// Atomically increment the booking count on the slot.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare(
				"UPDATE " . esc_sql( $slots_table ) . " SET booking_count = booking_count + %d WHERE slot_id = %d",
				$attendees,
				$slot_id
			) );

			/**
			 * Fires inside the _reserve_slot() transaction, after the booking
			 * row is inserted but before COMMIT. Allows Pro modules (e.g. Seat
			 * Selection) to confirm seat holds atomically. Throwing an exception
			 * here rolls back the entire booking.
			 *
			 * @param int $appointment_id The newly created appointment post ID.
			 * @param int $slot_id        The time slot ID.
			 */
			do_action( 'clisyc_inside_reserve_slot_transaction', $appointment_id, $slot_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );

			// Clear cached slot data so availability queries reflect the new booking.
			$this->db->clear_all_slot_cache();

			Debug_Logger::log( "Slot {$slot_id} reserved for appointment {$appointment_id} (attendees={$attendees}, new_count=" . ( (int) $slot_row->booking_count + $attendees ) . "/{$capacity})", 'Booking' );

			return $appointment_id;

		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );

			Debug_Logger::log_critical( '_reserve_slot() exception: ' . $e->getMessage(), 'Booking' );

			return false;
		}
	}

	/**
	 * Convert various datetime formats to MySQL DATETIME format.
	 * Handles ISO 8601, Unix timestamps, and various string formats.
	 *
	 * @param string $datetime_input The datetime string to convert.
	 * @return string|false MySQL DATETIME format (Y-m-d H:i:s) in UTC, or false on failure.
	 */
	private function convert_to_mysql_datetime( $datetime_input ) {
		if ( empty( $datetime_input ) ) {
			return false;
		}

		try {
			// Check if it's already in MySQL format
			if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datetime_input ) ) {
				return $datetime_input;
			}

			// Check if it's a Unix timestamp
			if ( is_numeric( $datetime_input ) ) {
				$dt = new \DateTime( '@' . intval( $datetime_input ) );
				$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
				return $dt->format( 'Y-m-d H:i:s' );
			}

			// Try to parse ISO 8601 format (e.g., 2025-01-15T09:00:00Z or 2025-01-15T09:00:00-08:00)
			$dt = new \DateTime( $datetime_input );
			$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );

		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Generate a unique username from a base string.
	 *
	 * @param string $base_username The base username to start with.
	 * @return string A unique username.
	 */
	private function generate_unique_username( $base_username ) {
		$username = $base_username;
		$counter  = 1;

		while ( username_exists( $username ) ) {
			$username = $base_username . $counter;
			$counter++;
		}

		return $username;
	}

	/**
	 * Generate a descriptive appointment title.
	 * Matches the format used by backend bookings:
	 * "Service - Practitioner - Room - Date"
	 *
	 * @param array  $dimensions      The dimension IDs.
	 * @param int    $user_id         The user ID (not used in title, kept for compatibility).
	 * @param string $appointment_date The appointment date (Y-m-d format).
	 * @return string The appointment title.
	 */
	private function generate_appointment_title( $dimensions, $user_id, $appointment_date = '' ) {
		$parts = [];

		// Get registry for dimension info
		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );

		// Determine the order to iterate dimensions
		$dim_order = [];

		// First try filter_order from registry
		if ( ! empty( $registry['filter_order'] ) ) {
			$dim_order = $registry['filter_order'];
		} elseif ( ! empty( $registry['dimensions'] ) ) {
			// Fallback: use dimension keys in order
			$dim_order = array_keys( $registry['dimensions'] );
		}

		// Add ALL dimension names (like backend does)
		foreach ( $dim_order as $dim_slug ) {
			if ( isset( $dimensions[ $dim_slug ] ) ) {
				$title = get_the_title( $dimensions[ $dim_slug ] );
				if ( $title ) {
					// Decode any HTML entities in the title
					$parts[] = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
				}
			}
		}

		// If no dimensions found, add a generic title with username
		if ( empty( $parts ) ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				/* translators: %s: The display name of the client/user. */
				$parts[] = sprintf( __( 'Appointment for %s', 'client-sync' ), $user->display_name );
			} else {
				$parts[] = __( 'Appointment', 'client-sync' );
			}
		}

		// Add the APPOINTMENT date at the end
		if ( ! empty( $appointment_date ) ) {
			try {
				$dt = new \DateTime( $appointment_date, wp_timezone() );
				$parts[] = wp_date( get_option( 'date_format' ), $dt->getTimestamp() );
			} catch ( \Exception $e ) {
				$date_timestamp = strtotime( $appointment_date . ' 12:00:00' );
				if ( $date_timestamp ) {
					$parts[] = wp_date( get_option( 'date_format' ), $date_timestamp );
				}
			}
		} else {
			$parts[] = wp_date( get_option( 'date_format' ) );
		}

		return implode( ' - ', $parts );
	}
}
