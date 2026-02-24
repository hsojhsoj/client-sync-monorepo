<?php
/**
 * File: src/shared/includes/frontend/class-form-handler.php
 * Handles processing of all frontend form submissions for the plugin.
 *
 * UPDATED: Added HIPAA compliance features:
 * - Audit logging for manager edit actions
 * - Encryption support for sensitive fields on save
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend
 */

namespace DependentMedia\ClientSync\Frontend;

use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Utility\Sanitizer;
use DependentMedia\ClientSync\Utility\Debug_Logger;
use DependentMedia\ClientSync\Services\FormRenderer;
use DependentMedia\ClientSync\Services\Encryption_Service;
use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Form_Handler {

	private $validation_errors = [];

	public function register_hooks() {
		add_action( 'template_redirect', [ $this, 'process_user_account_update' ], 4 );
		add_action( 'template_redirect', [ $this, 'process_appointment_submission' ], 5 );
		add_action( 'admin_post_clisyc_manager_edit_appointment_submit', [ $this, 'handle_manager_edit_appointment' ] );
	}

	/**
	 * Processes the user account update form submission from [clisyc_user_account].
	 *
	 * Handles saving standard user fields (name, email), phone number for SMS
	 * notifications, SMS opt-out preference, and custom profile fields.
	 *
	 * @return void
	 */
	public function process_user_account_update() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Checking for method existence only.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['clisyc_update_account_action'] ) ) {
			return;
		}

		if ( ! isset( $_POST['clisyc_update_account_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['clisyc_update_account_nonce'] ) ), 'clisyc_update_user_account_nonce' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		// --- Standard User Fields ---
		$first_name = isset( $_POST['clisyc_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['clisyc_first_name'] ) ) : '';
		$last_name  = isset( $_POST['clisyc_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['clisyc_last_name'] ) ) : '';
		$email      = isset( $_POST['clisyc_email'] ) ? sanitize_email( wp_unslash( $_POST['clisyc_email'] ) ) : '';

		if ( ! empty( $email ) && is_email( $email ) ) {
			wp_update_user( [
				'ID'           => $user_id,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'user_email'   => $email,
				'display_name' => trim( $first_name . ' ' . $last_name ) ?: get_userdata( $user_id )->display_name,
			] );
		}

		// --- Phone Number (for SMS notifications) ---
		if ( isset( $_POST['clisyc_phone_number'] ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['clisyc_phone_number'] ) );

			// Normalize to E.164 if the Pro phone normalizer is available.
			if ( class_exists( '\ClientSyncPro\Utilities\Phone_Normalizer' ) && ! empty( $phone ) ) {
				$phone = \ClientSyncPro\Utilities\Phone_Normalizer::to_e164( $phone );
			}

			if ( ! empty( $phone ) ) {
				update_user_meta( $user_id, 'phone_number', $phone );
			} else {
				delete_user_meta( $user_id, 'phone_number' );
			}
		}

		// --- SMS Opt-Out Preference ---
		if ( isset( $_POST['clisyc_sms_opt_out'] ) ) {
			update_user_meta( $user_id, 'clisyc_sms_opt_out', '1' );
		} else {
			delete_user_meta( $user_id, 'clisyc_sms_opt_out' );
		}

		// --- Custom Profile Fields ---
		$custom_fields = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array values are sanitized individually below.
		$submitted_custom = isset( $_POST['clisyc_custom_field'] ) && is_array( $_POST['clisyc_custom_field'] ) ? wp_unslash( $_POST['clisyc_custom_field'] ) : [];

		if ( ! empty( $submitted_custom ) && ! empty( $custom_fields ) ) {
			$form_renderer = new FormRenderer();
			foreach ( $submitted_custom as $field_key => $value ) {
				if ( isset( $custom_fields[ $field_key ] ) ) {
					$sanitized = $form_renderer->sanitize_custom_field_value( $value, $custom_fields[ $field_key ], $field_key );
					update_user_meta( $user_id, $field_key, $sanitized );
				}
			}
		}

		// Redirect back with success message.
		$redirect_url = remove_query_arg( [ 'clisyc_message_type', 'clisyc_message_text' ] );
		wp_safe_redirect( add_query_arg( [
			'clisyc_message_type' => 'success',
			'clisyc_message_text' => rawurlencode( __( 'Account updated successfully.', 'client-sync' ) ),
		], $redirect_url ) );
		exit;
	}

	public function handle_manager_edit_appointment() {
		$appointment_id = isset( $_POST['appointment_id'] ) ? absint( wp_unslash( $_POST['appointment_id'] ) ) : 0;

		// Security First: Nonce and Capability Checks
		if ( ! isset( $_POST['clisyc_edit_appointment_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['clisyc_edit_appointment_nonce'] ) ), 'clisyc_edit_appointment_' . $appointment_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}

		if ( ! current_user_can( apply_filters( 'clisyc_manager_edit_capability', 'edit_others_posts' ) ) || ! current_user_can( 'edit_post', $appointment_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this appointment.', 'client-sync' ) );
		}

		// Process and Sanitize Data
		$errors_during_save = [];
		$update_successful  = false;
		$form_renderer      = new FormRenderer();
		$custom_fields      = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- We need the raw array to iterate; values are sanitized in the loop.
		$submitted_meta = isset( $_POST['clisyc_custom_field'] ) && is_array( $_POST['clisyc_custom_field'] ) ? wp_unslash( $_POST['clisyc_custom_field'] ) : [];

		// =========================================================================
		// HIPAA COMPLIANCE: Get encryption service for sensitive field encryption
		// =========================================================================
		$encryption = null;
		if ( class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
			$encryption = \DependentMedia\ClientSync\Services\Encryption_Service::get_instance();
		}

		foreach ( $custom_fields as $field_key => $field_definition ) {
			if ( 'custom_html' === ( $field_definition['type'] ?? 'text' ) ) {
				continue;
			}

			$raw_value = $submitted_meta[ $field_key ] ?? null;
			if ( 'multi_checkbox' === ( $field_definition['type'] ?? '' ) && is_null( $raw_value ) ) {
				$raw_value = [];
			}
			if ( is_null( $raw_value ) ) {
				continue;
			}

			$sanitized_value = $form_renderer->sanitize_custom_field_value( $raw_value, $field_definition, $field_key );

			if ( ! empty( $field_definition['required'] ) && empty( $sanitized_value ) && '0' !== $sanitized_value ) {
				/* translators: %s: The label of the required field. */
				$errors_during_save[] = sprintf( esc_html__( 'Field "%s" is required.', 'client-sync' ), esc_html( $field_definition['label'] ?? $field_key ) );
				continue;
			}

			// =========================================================================
			// HIPAA COMPLIANCE: Encrypt sensitive fields if HIPAA mode is active
			// =========================================================================
			if ( $encryption ) {
				$should_encrypt = $encryption->should_encrypt_field( $field_key );
				if ( ! $should_encrypt && ! empty( $field_definition['sensitive'] ) ) {
					$should_encrypt = true;
				}
				if ( $should_encrypt && ! empty( $sanitized_value ) ) {
					$sanitized_value = $encryption->maybe_encrypt( $sanitized_value, $field_key );
				}
			}

			$meta_update_result = update_post_meta( $appointment_id, $field_key, $sanitized_value );
			if ( $meta_update_result ) {
				$update_successful = true;
			}
		}

		// Set Feedback Transients and Redirect
		if ( ! empty( $errors_during_save ) ) {
			set_transient( 'clisyc_manager_edit_feedback', [ 'type' => 'error', 'text' => __( 'Errors occurred while saving:', 'client-sync' ) . '<br>' . implode( '<br>', $errors_during_save ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		} elseif ( $update_successful ) {
			set_transient( 'clisyc_manager_edit_feedback', [ 'type' => 'success', 'text' => __( 'Appointment details updated successfully.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
			
			// =========================================================================
			// HIPAA COMPLIANCE: Log the manager edit action
			// =========================================================================
			/**
			 * Fires after a manager successfully edits an appointment via the dashboard.
			 * The Audit_Logger class listens for this hook to log the edit action.
			 *
			 * @param int   $appointment_id  The appointment ID that was edited.
			 * @param array $submitted_meta  The custom field data that was submitted.
			 */
			do_action( 'clisyc_after_manager_edit_appointment', $appointment_id, $submitted_meta );
		} else {
			set_transient( 'clisyc_manager_edit_feedback', [ 'type' => 'info', 'text' => __( 'No changes were detected.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		}

		$redirect_url = wp_get_referer();
		if ( ! $redirect_url ) {
			$redirect_url = home_url();
		}
		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function process_appointment_submission() {
		Debug_Logger::log( 'process_appointment_submission() initiated.', 'Form' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Checking for method existence only.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['_clisyc_frontend_booking_submit'] ) ) {
			return;
		}

		Debug_Logger::log_request( wp_unslash( $_POST ), 'Form' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Logging only, nonce verified below.

		if ( ! isset( $_POST['clisyc_appointment_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['clisyc_appointment_nonce'] ) ), Constants::POST_TYPE_APPOINTMENT ) ) {
			$this->_redirect_with_error( __( 'Security check failed. Please refresh the page and try again.', 'client-sync' ) );
		}

		$posted_data  = $this->_get_sanitized_posted_data();
		$booking_mode = isset( $_POST['booking_mode'] ) ? sanitize_key( wp_unslash( $_POST['booking_mode'] ) ) : 'slot';

		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				if ( ! empty( $settings['primary'] ) ) {
					$primary_dim_slug = $slug;
					break;
				}
			}
		}

		Debug_Logger::log_request( $posted_data, 'Form' );
		Debug_Logger::log( "Booking mode detected: {$booking_mode}", 'Form' );

		if ( empty( $primary_dim_slug ) || empty( $posted_data['dimensions'][ $primary_dim_slug ] ) ) {
			$this->validation_errors[] = __( 'Please select a valid primary booking item.', 'client-sync' );
		}
		if ( 'date_range' === $booking_mode ) {
			if ( empty( $posted_data['start_date'] ) || empty( $posted_data['end_date'] ) ) {
				$this->validation_errors[] = __( 'Please select a start and end date.', 'client-sync' );
			} elseif ( strtotime( $posted_data['end_date'] ) <= strtotime( $posted_data['start_date'] ) ) {
				$this->validation_errors[] = __( 'The end date must be after the start date.', 'client-sync' );
			} else {
				// ================== START: ADD THIS VALIDATION BLOCK ==================
				$db_manager     = new \DependentMedia\ClientSync\Core\Database_Manager();
				$primary_dim_id = $posted_data['dimensions'][ $primary_dim_slug ] ?? 0;

				// Get all bookings for this specific item.
				$existing_bookings = $db_manager->get_booked_appointments_for_date_range( '2000-01-01', '9999-12-31', $primary_dim_id );
				$is_conflicting    = false;

				try {
					$user_start_date = new \DateTime( $posted_data['start_date'] );
					// For overlap check, the user's end date should be inclusive.
					$user_end_date = new \DateTime( $posted_data['end_date'] );

					foreach ( $existing_bookings as $booking ) {
						$booking_start_date = new \DateTime( $booking['start_time_utc'] );
						$booking_end_date   = new \DateTime( $booking['end_time_utc'] );

						// Check for overlap: (StartA <= EndB) and (EndA >= StartB)
						if ( $user_start_date <= $booking_end_date && $user_end_date >= $booking_start_date ) {
							$is_conflicting = true;
							break;
						}
					}
				} catch ( \Exception $e ) {
					// Date parsing failed, let other validation handle it.
				}

				if ( $is_conflicting ) {
					$this->validation_errors[] = __( 'Sorry, some of those dates are no longer available. Please select a different date range.', 'client-sync' );
				}
				// =================== END: ADD THIS VALIDATION BLOCK ===================

				if ( $primary_dim_id ) {
					$min_stay = (int) get_post_meta( $primary_dim_id, Constants::META_MIN_STAY, true );
					$max_stay = (int) get_post_meta( $primary_dim_id, Constants::META_MAX_STAY, true );

					try {
						$start    = new \DateTime( $posted_data['start_date'] );
						$end      = new \DateTime( $posted_data['end_date'] );
						$interval = $start->diff( $end );
						$num_days = $interval->days;

						if ( $min_stay > 1 && $num_days < $min_stay ) {
							/* translators: %d: Minimum number of nights. */
							$this->validation_errors[] = sprintf( __( 'The minimum stay is %d nights.', 'client-sync' ), $min_stay );
						}
						if ( $max_stay > 0 && $num_days > $max_stay ) {
							/* translators: %d: Maximum number of nights. */
							$this->validation_errors[] = sprintf( __( 'The maximum stay is %d nights.', 'client-sync' ), $max_stay );
						}
					} catch ( \Exception $e ) {
						$this->validation_errors[] = __( 'Invalid date format provided.', 'client-sync' );
					}
				}
			}
		} else { // 'slot' mode
			if ( empty( $posted_data['time_slot'] ) ) {
				$this->validation_errors[] = __( 'Please select an available time slot.', 'client-sync' );
			}
		}

		$appointment_field_defs = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		if ( ! empty( $appointment_field_defs ) ) {
			foreach ( $appointment_field_defs as $key => $def ) {
				if ( ! empty( $def['required'] ) ) {
					$submitted_val = $posted_data['custom_fields'][ $key ] ?? null;
					if ( is_array( $submitted_val ) ? empty( $submitted_val ) : ( '' === $submitted_val || null === $submitted_val ) ) {
						/* translators: %s: The label of the required field. */
						$this->validation_errors[] = sprintf( __( 'Field "%s" is required.', 'client-sync' ), $def['label'] );
					}
				}
			}
		}

		if ( is_user_logged_in() && class_exists( '\ClientSyncPro\Services\Membership_Rules_Engine' ) ) {
			$engine         = new \ClientSyncPro\Services\Membership_Rules_Engine();
			$primary_dim_id = $posted_data['service_id'] ?? 0;
			$check          = $engine->can_user_book_service( get_current_user_id(), $primary_dim_id );
			if ( ! $check['can_book'] ) {
				$this->validation_errors[] = $check['message'];
			}
		}

		Debug_Logger::log( 'Validation check. Error count: ' . count( $this->validation_errors ), 'Form' );

		if ( ! empty( $this->validation_errors ) ) {
			$this->_redirect_with_error( implode( '<br>', $this->validation_errors ) );
		}

		// NOTE: The pre-transaction capacity check has been removed to prevent race conditions.
		// Capacity is now checked atomically within _create_appointment().

		$use_credit = isset( $_POST['use_credit'] ) && '1' === $_POST['use_credit'];

		if ( $use_credit && is_user_logged_in() ) {
			$user_id      = get_current_user_id();
			$service_id   = $posted_data['service_id'] ?? 0;
			$user_credits = get_user_meta( $user_id, '_clisyc_credits', true );
			$user_credits = is_array( $user_credits ) ? $user_credits : [];
			$service_key  = 'service_' . $service_id;

			if ( isset( $user_credits[ $service_key ] ) && $user_credits[ $service_key ]['credits_remaining'] > 0 ) {
				// User has credits, proceed with credit redemption
				$user_credits[ $service_key ]['credits_remaining']--;
				update_user_meta( $user_id, '_clisyc_credits', $user_credits );

				$appointment_id = $this->_create_appointment( $user_id, $posted_data, $booking_mode );
				if ( is_wp_error( $appointment_id ) ) {
					$this->_redirect_with_error( $appointment_id->get_error_message() );
				}

				// Mark as paid with credit
				update_post_meta( $appointment_id, Constants::META_PAYMENT_STATUS, 'paid_with_credit' );

				// Trigger the free booking flow (notifications, success redirect)
				$this->_handle_free_booking_flow( $appointment_id );
				exit; // IMPORTANT: Stop execution before it reaches the WC part.
			} else {
				// This is a security check in case the form was manipulated.
				$this->_redirect_with_error( __( 'You do not have enough credits for this booking.', 'client-sync' ) );
			}
		}

		$user_id_for_booking = 0;

		if ( ! is_user_logged_in() ) {
			// --- START: NEW GUEST REGISTRATION LOGIC ---
			if ( ! get_option( 'users_can_register' ) ) {
				$this->_redirect_with_error( __( 'Sorry, user registration is not currently allowed.', 'client-sync' ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at start of function.
			$first_name = sanitize_text_field( $posted_data['clisyc_reg_first_name'] ?? '' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at start of function.
			$last_name  = sanitize_text_field( $posted_data['clisyc_reg_last_name'] ?? '' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at start of function.
			$email      = sanitize_email( $posted_data['clisyc_reg_email'] ?? '' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified. Password requires raw input.
			$password   = $posted_data['clisyc_reg_password'] ?? '';

			if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) || empty( $password ) ) {
				$this->validation_errors[] = __( 'All registration fields (First Name, Last Name, Email, Password) are required.', 'client-sync' );
			}
			if ( ! is_email( $email ) ) {
				$this->validation_errors[] = __( 'Please provide a valid email address.', 'client-sync' );
			}
			if ( email_exists( $email ) ) {
				$this->validation_errors[] = __( 'This email address is already registered. Please log in to book.', 'client-sync' );
			}
			if ( strlen( $password ) < 8 ) {
				$this->validation_errors[] = __( 'Password must be at least 8 characters long.', 'client-sync' );
			}

			if ( ! empty( $this->validation_errors ) ) {
				$this->_redirect_with_error( implode( '<br>', $this->validation_errors ) );
			}

			$new_user_id = wp_insert_user(
				[
					'user_login'   => $email,
					'user_email'   => $email,
					'user_pass'    => $password,
					'first_name'   => $first_name,
					'last_name'    => $last_name,
					'display_name' => $first_name . ' ' . $last_name,
					'role'         => 'subscriber',
				]
			);

			if ( is_wp_error( $new_user_id ) ) {
				$this->_redirect_with_error( $new_user_id->get_error_message() );
			}

			$user_id_for_booking = $new_user_id;
			// --- END: NEW GUEST REGISTRATION LOGIC ---
		} else {
			$user_id_for_booking = get_current_user_id();
		}

		// Create appointment with transactional safety
		$appointment_id = $this->_create_appointment( $user_id_for_booking, $posted_data, $booking_mode );

		if ( is_wp_error( $appointment_id ) || 0 === $appointment_id ) {
			// This handles capacity errors thrown from the transaction
			$this->_redirect_with_error( $appointment_id->get_error_message() );
		}

		$primary_dim_id    = $posted_data['dimensions'][ $primary_dim_slug ] ?? 0;
		$product_id_to_add = (int) get_post_meta( $primary_dim_id, Constants::META_WC_PRODUCT_ID, true );

		if ( class_exists( 'WooCommerce' ) && get_option( Constants::OPTION_WC_ENABLED ) && $product_id_to_add > 0 && 'product' === get_post_type( $product_id_to_add ) ) {

			Debug_Logger::log( "WooCommerce is active. Redirecting to checkout for Appointment ID: {$appointment_id}.", 'Form' );

			wp_update_post( [ 'ID' => $appointment_id, 'post_status' => Constants::STATUS_PENDING_PAYMENT ] );

			$product         = wc_get_product( $product_id_to_add );
			$cart_item_data  = [
				'_clisyc_appointment_id' => $appointment_id,
				'_clisyc_service_name'   => $product->get_name(),
			];
			$quantity_to_add = 1;

			if ( 'date_range' === $booking_mode ) {
				try {
					$start_date = new \DateTime( $posted_data['start_date'] );
					$end_date   = new \DateTime( $posted_data['end_date'] );
					$interval   = $start_date->diff( $end_date );
					$num_days   = $interval->days;

					if ( $num_days > 0 ) {
						// FINAL FIX: Use the number of nights as the quantity.
						$quantity_to_add                                       = $num_days;
						$cart_item_data['_clisyc_start_date']                  = $posted_data['start_date'];
						$cart_item_data['_clisyc_end_date']                    = $posted_data['end_date'];
						$cart_item_data['_clisyc_booking_days']                = $num_days;
						$cart_item_data['_clisyc_appointment_details_display'] = $start_date->format( get_option( 'date_format' ) ) . ' - ' . $end_date->format( get_option( 'date_format' ) );
					}
				} catch ( \Exception $e ) { /* Fallback */ }
			} else {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at start of function.
				$attendees       = isset( $_POST['clisyc_attendees'] ) ? absint( wp_unslash( $_POST['clisyc_attendees'] ) ) : 1;
				$quantity_to_add = $attendees > 0 ? $attendees : 1;
				$cart_item_data['_clisyc_appointment_details_display'] = wp_date( get_option( 'date_format' ), strtotime( $posted_data['time_slot'] ) ) . ' @ ' . wp_date( get_option( 'time_format' ), strtotime( $posted_data['time_slot'] ) );
			}

			WC()->cart->empty_cart();
			WC()->cart->add_to_cart( $product_id_to_add, $quantity_to_add, 0, [], $cart_item_data );

			wp_safe_redirect( wc_get_checkout_url() );
			exit;

		} elseif ( isset( $new_user_id ) && $new_user_id ) {
			// If a new user was created for a free booking, log them in.
			wp_set_current_user( $new_user_id );
			wp_set_auth_cookie( $new_user_id, true );
			$this->_handle_free_booking_flow( $appointment_id );

		} else {
			Debug_Logger::log( "This is a FREE booking. Handling free flow for Appointment ID: {$appointment_id}.", 'Form' );
			$this->_handle_free_booking_flow( $appointment_id );
		}
	}

	private function _get_sanitized_posted_data(): array {
		$sanitized_data = [
			'service_id'       => 0,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in caller.
			'time_slot'        => isset( $_POST['time_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['time_slot'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in caller.
			'duration_minutes' => isset( $_POST['appointment_duration_minutes'] ) ? absint( wp_unslash( $_POST['appointment_duration_minutes'] ) ) : 0,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in caller.
			'start_date'       => isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in caller.
			'end_date'         => isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '',
			'dimensions'       => [],
			// --- START: NEW GUEST FIELDS ---
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in caller.
			'clisyc_reg_first_name' => isset( $_POST['clisyc_reg_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['clisyc_reg_first_name'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in caller.
			'clisyc_reg_last_name'  => isset( $_POST['clisyc_reg_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['clisyc_reg_last_name'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in caller.
			'clisyc_reg_email'      => isset( $_POST['clisyc_reg_email'] ) ? sanitize_email( wp_unslash( $_POST['clisyc_reg_email'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Password field.
			'clisyc_reg_password'   => isset( $_POST['clisyc_reg_password'] ) ? $_POST['clisyc_reg_password'] : '',
			// --- END: NEW GUEST FIELDS ---
			'custom_fields'    => [],
		];

		$registry       = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$dimension_keys = array_keys( $registry['dimensions'] ?? [] );

		foreach ( $dimension_keys as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in caller.
			if ( isset( $_POST[ $key ] ) && ! empty( $_POST[ $key ] ) ) { $sanitized_data['dimensions'][ $key ] = absint( wp_unslash( $_POST[ $key ] ) );}
		}

		// Populate legacy service_id using the DYNAMIC primary dimension key.
		$primary_dim_slug = null;
		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				if ( ! empty( $settings['primary'] ) ) {
					$primary_dim_slug = $slug;
					break;
				}
			}
		}
		if ( $primary_dim_slug && isset( $sanitized_data['dimensions'][ $primary_dim_slug ] ) ) {
			$sanitized_data['service_id'] = $sanitized_data['dimensions'][ $primary_dim_slug ];
		}

		$appointment_field_defs  = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in caller; input array is sanitized in loop.
		$submitted_custom_fields = isset( $_POST['clisyc_custom_field'] ) && is_array( $_POST['clisyc_custom_field'] ) ? wp_unslash( $_POST['clisyc_custom_field'] ) : [];

		if ( ! empty( $submitted_custom_fields ) ) {
			foreach ( $submitted_custom_fields as $key => $value ) {
				if ( isset( $appointment_field_defs[ $key ] ) ) {
					$sanitized_data['custom_fields'][ $key ] = Sanitizer::sanitize_custom_field_value( $value, $appointment_field_defs[ $key ], $key );
				}
			}
		}

		return $sanitized_data;
	}


	/**
	 * Checks if a slot is at capacity.
	 *
	 * @deprecated 1.2.0 Use transactional locking in _create_appointment instead.
	 */
	private function _is_slot_at_capacity( string $time_slot_utc_str, int $primary_dim_id, string $primary_dim_key, int $quantity = 1 ): bool {
		global $wpdb;
		$capacity = (int) get_post_meta( $primary_dim_id, Constants::META_CAPACITY, true ) ?: 1;

		$db_manager = new \DependentMedia\ClientSync\Core\Database_Manager();
		// START: FIX - Use the dynamic key instead of the hardcoded 'clisyc_service'
		$slot_id = $db_manager->find_slot_id_by_start_time_and_dimensions( $time_slot_utc_str, [ $primary_dim_key => $primary_dim_id ] );
		// END: FIX

		if ( ! $slot_id ) {
			// If the slot doesn't even exist, it's definitely "at capacity" in the sense that it can't be booked.
			return true;
		}

		$bookings_table = $wpdb->prefix . 'clisyc_bookings';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely constructed. Real-time capacity check requires fresh data.
		$booked_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(booking_id) FROM {$bookings_table} WHERE slot_id = %d", $slot_id ) );

		return ( (int) $booked_count + $quantity ) > $capacity;
	}

	private function _create_appointment( int $user_id, array $data, string $booking_mode ): int|\WP_Error {
		global $wpdb;

		// 1. Basic Validation
		$primary_dim_id    = $data['service_id'] ?? 0;
		$primary_item_post = get_post( $primary_dim_id );
		if ( ! $primary_item_post ) {
			return new \WP_Error( 'invalid_service', 'The selected booking item does not exist.' );
		}

		// 2. Prepare Data
		$client_user       = get_userdata( $user_id );
		$appointment_title = $primary_item_post->post_title;
		if ( $client_user ) {
			$appointment_title .= ' - ' . $client_user->display_name;
		}

		$appointment_data = [
			'post_title'  => $appointment_title,
			'post_status' => 'publish',
			'post_author' => $user_id,
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
		];

		// 3. Start Transaction - CRITICAL FOR RACE CONDITIONS
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control required.
		$wpdb->query( 'START TRANSACTION' );

		try {
			// Insert the post first (we can rollback if the slot is full)
			$appointment_id = wp_insert_post( $appointment_data, true );
			if ( is_wp_error( $appointment_id ) ) {
				throw new \Exception( $appointment_id->get_error_message() );
			}

			$db_manager          = new \DependentMedia\ClientSync\Core\Database_Manager();
			$slot_id_for_booking = null;

			// --- HANDLE DATE RANGE ---
			if ( 'date_range' === $booking_mode ) {
				update_post_meta( $appointment_id, Constants::META_BOOKING_MODE, 'date_range' );
				update_post_meta( $appointment_id, Constants::META_START_DATE, $data['start_date'] );
				update_post_meta( $appointment_id, Constants::META_END_DATE, $data['end_date'] );

				// Create the container slot
				$utc_timezone = new \DateTimeZone( 'UTC' );
				$start_utc    = ( new \DateTime( $data['start_date'] . ' 00:00:00', wp_timezone() ) )->setTimezone( $utc_timezone );
				$end_utc      = ( new \DateTime( $data['end_date'] . ' 23:59:59', wp_timezone() ) )->setTimezone( $utc_timezone );

				$slot_to_insert = [
					'start_time_utc' => $start_utc->format( 'Y-m-d H:i:s' ),
					'end_time_utc'   => $end_utc->format( 'Y-m-d H:i:s' ),
					'is_block'       => false,
					'dimensions'     => $data['dimensions'],
				];

				$insert_result = $db_manager->insert_slots( [ $slot_to_insert ] );
				if ( $insert_result['inserted'] > 0 ) {
					 $slot_id_for_booking = $db_manager->find_slot_id_by_start_time_and_dimensions(
						 $slot_to_insert['start_time_utc'],
						 $slot_to_insert['dimensions']
					 );
				}
			} else {
				// --- HANDLE TIME SLOTS ---
				update_post_meta( $appointment_id, Constants::META_BOOKING_MODE, 'slot' );
				update_post_meta( $appointment_id, Constants::META_TIME_SLOT, $data['time_slot'] );
				update_post_meta( $appointment_id, Constants::META_APPOINTMENT_DATE, ( new \DateTime( $data['time_slot'] ) )->format( 'Y-m-d' ) );
				update_post_meta( $appointment_id, Constants::META_APPOINTMENT_DURATION, $data['duration_minutes'] ?: 30 );

				// 4. Find Slot & Lock It
				Debug_Logger::log( 'Searching for slot with time_slot: ' . $data['time_slot'], 'Booking' );
				Debug_Logger::log_data( 'Dimensions passed', $data['dimensions'], 'Booking' );

				$slot_id_for_booking = $db_manager->find_slot_id_by_start_time_and_dimensions( $data['time_slot'], $data['dimensions'] );

				Debug_Logger::log( 'Slot ID found: ' . ( $slot_id_for_booking ?: 'null' ), 'Booking' );

				if ( ! $slot_id_for_booking ) {
					Debug_Logger::log( 'ERROR: No slot found for given time and dimensions!', 'Booking' );
					throw new \Exception( __( 'The selected slot is no longer valid.', 'client-sync' ) );
				}

				$bookings_table = $db_manager->get_bookings_table_name();

				// ATOMIC LOCK: Count existing bookings for this slot and lock the rows
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely constructed from internal method. Locking required.
				$booked_count = $wpdb->get_var(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from internal method.
					$wpdb->prepare( "SELECT COUNT(booking_id) FROM {$bookings_table} WHERE slot_id = %d FOR UPDATE", $slot_id_for_booking )
				);

				$capacity  = \DependentMedia\ClientSync\Core\Capacity_Resolver::resolve( $data['dimensions'] );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in caller.
				$attendees = isset( $_POST['clisyc_attendees'] ) ? absint( wp_unslash( $_POST['clisyc_attendees'] ) ) : 1;
				if ( $attendees < 1 ) {
					$attendees = 1;
				}

					Debug_Logger::log( 'Slot ID: ' . $slot_id_for_booking . ', Booked: ' . $booked_count . ', Capacity: ' . $capacity . ', Attendees: ' . $attendees, 'Booking' );
				Debug_Logger::log( 'Capacity check: (' . $booked_count . ' + ' . $attendees . ') > ' . $capacity . ' = ' . ( ( (int) $booked_count + $attendees ) > $capacity ? 'FAIL' : 'PASS' ), 'Booking' );

				// 5. Verify Capacity
				if ( ( (int) $booked_count + $attendees ) > $capacity ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// Get more info about what's blocking this slot
						$slots_table = $db_manager->get_slots_table_name();
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely constructed from internal method and escaped.
						$slot_info = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . esc_sql( $slots_table ) . " WHERE slot_id = %d", $slot_id_for_booking ), ARRAY_A );
						Debug_Logger::log_data( 'Slot info from DB', $slot_info, 'Booking' );

						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely constructed from internal method and escaped.
						$existing_bookings = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . esc_sql( $bookings_table ) . " WHERE slot_id = %d", $slot_id_for_booking ), ARRAY_A );
						Debug_Logger::log_data( 'Existing bookings for this slot', $existing_bookings, 'Booking' );
					}
					throw new \Exception( __( 'Sorry, this slot was just booked by someone else.', 'client-sync' ) );
				}
			}

			// 6. Save Metadata
			update_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, $data['dimensions'] );
			
			// =========================================================================
			// HIPAA COMPLIANCE: Get encryption service for sensitive field encryption
			// =========================================================================
			$encryption = null;
			if ( class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
				$encryption = \DependentMedia\ClientSync\Services\Encryption_Service::get_instance();
			}
			$field_defs = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
			
			if ( ! empty( $data['custom_fields'] ) && is_array( $data['custom_fields'] ) ) {
				foreach ( $data['custom_fields'] as $key => $value ) {
					// =========================================================================
					// HIPAA COMPLIANCE: Encrypt sensitive fields if HIPAA mode is active
					// =========================================================================
					if ( $encryption ) {
						$should_encrypt = $encryption->should_encrypt_field( $key );
						if ( ! $should_encrypt && isset( $field_defs[ $key ] ) && ! empty( $field_defs[ $key ]['sensitive'] ) ) {
							$should_encrypt = true;
						}
						if ( $should_encrypt && ! empty( $value ) ) {
							$value = $encryption->maybe_encrypt( $value, $key );
						}
					}
					update_post_meta( $appointment_id, $key, $value );
				}
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in caller.
			$attendees = isset( $_POST['clisyc_attendees'] ) ? absint( wp_unslash( $_POST['clisyc_attendees'] ) ) : 1;
			update_post_meta( $appointment_id, '_clisyc_attendee_count', $attendees );

			// 7. Create Booking Record
			if ( $slot_id_for_booking ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert required for custom bookings table within transaction.
				$wpdb->insert(
					$db_manager->get_bookings_table_name(),
					[
						'appointment_id'   => $appointment_id,
						'slot_id'          => $slot_id_for_booking,
						'booking_time_utc' => current_time( 'mysql', 1 ),
					],
					[ '%d', '%d', '%s' ]
				);

				// --- OPTIMIZATION: Increment the cached counter ---
				$slots_table = $db_manager->get_slots_table_name();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely constructed from internal method.
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from internal method.
						"UPDATE {$slots_table} SET booking_count = booking_count + %d WHERE slot_id = %d",
						$attendees, // How many spots taken
						$slot_id_for_booking
					)
				);

				// Cleanup for single capacity (Optional, but keeps DB clean)
				// Note: We only do this if we are sure no race condition occurred above
				$capacity = (int) get_post_meta( $primary_dim_id, Constants::META_CAPACITY, true ) ?: 1;
				if ( 'slot' === $booking_mode && $capacity <= 1 ) {
					// Only delete if we are absolutely sure it's 1 capacity
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct delete required within transaction.
					$wpdb->delete(
						$db_manager->get_slots_table_name(),
						[ 'slot_id' => $slot_id_for_booking ],
						[ '%d' ]
					);
				}
			}

			// 8. Commit Transaction
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'COMMIT' );

			return $appointment_id;

		} catch ( \Exception $e ) {
			// 9. Rollback on Failure
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'ROLLBACK' );

			// If we created a post ID but failed later, strictly we should clean it up,
			// though WP Transactions don't apply to tables like wp_posts if they are MyISAM.
			// Assuming InnoDB (standard for WP now), the post insertion rolls back too.

			return new \WP_Error( 'booking_failed', $e->getMessage() );
		}
	}

	private function _handle_free_booking_flow( int $appointment_id ): void {
		if ( class_exists( 'DependentMedia\ClientSync\Core\Notifications' ) ) {
			$notifications = new \DependentMedia\ClientSync\Core\Notifications();
			$notifications->send( 'new_appointment_client', $appointment_id );
			$notifications->send( 'new_appointment_admin', $appointment_id );
		}


		Debug_Logger::log( "Firing 'clisyc_appointment_confirmed' action and calling redirect function.", 'Form' );
		do_action( 'clisyc_appointment_confirmed', $appointment_id );

		$this->_redirect_on_success(
			$appointment_id,
			__( 'Your appointment has been successfully scheduled!', 'client-sync' ),
			'success_free'
		);
		// --- START: THE FIX ---
		exit; // Ensure the script stops after the redirect.
	}

	private function _redirect_on_success( int $appointment_id, string $message, string $status_key ): void {
		set_transient( 'clisyc_booking_feedback_success', $message, Constants::TRANSIENT_BOOKING_FEEDBACK_TTL );

		$success_page_id = (int) get_option( Constants::OPTION_BOOKING_SUCCESS_PAGE, 0 );
		$detail_page_id  = (int) get_option( Constants::OPTION_APPOINTMENT_VIEW_PAGE, 0 );

		$redirect_url_base = null;
		$query_args        = [ 'clisyc_booking_status' => $status_key ];

		if ( $success_page_id > 0 ) {
			$redirect_url_base     = get_permalink( $success_page_id );
			$query_args['appt_id'] = $appointment_id;
		} elseif ( $detail_page_id > 0 ) {
			$redirect_url_base     = get_permalink( $detail_page_id );
			$query_args['view_id'] = $appointment_id;
		} else {
			$redirect_url_base     = get_permalink();
			$query_args['appt_id'] = $appointment_id;
		}

		if ( ! $redirect_url_base ) {
			$redirect_url_base = get_permalink();
		}

		$redirect_url = add_query_arg( $query_args, $redirect_url_base );
		Debug_Logger::log( "Redirecting to success URL: {$redirect_url}", 'Form' );
		wp_safe_redirect( $redirect_url );
		// Note: The exit call is now in the calling function (_handle_free_booking_flow)
		// to ensure it's always executed after this method is called.
	}

	private function _redirect_with_error( string $message, string $status_key = 'error' ): void {
		set_transient( 'clisyc_booking_feedback_error', $message, Constants::TRANSIENT_BOOKING_FEEDBACK_TTL );
		Debug_Logger::log( "ERROR: Redirecting with error message: {$message}", 'Form' );
		wp_safe_redirect( add_query_arg( [ 'clisyc_booking_status' => $status_key ], get_permalink() ) );
		exit;
	}
}