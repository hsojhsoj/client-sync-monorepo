<?php
/**
 * File: src/shared/includes/core/class-placeholder-data-builder.php
 * Handles building placeholder data arrays for notification templates
 * and HIPAA-compliant anonymization of PHI in external communications.
 *
 * Extracted from class-notifications.php to follow the Single Responsibility Principle.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Placeholder_Data_Builder
 *
 * Builds placeholder data arrays for notification templates and handles
 * HIPAA-compliant anonymization of Protected Health Information (PHI)
 * in external communications.
 */
class Placeholder_Data_Builder {

	/**
	 * Prepares the data array for placeholder replacement.
	 *
	 * @param string $event_type The notification event type.
	 * @param int    $object_id  The related object ID (appointment, user, etc.).
	 * @param array  $data       Additional data passed with the event.
	 *
	 * @return array|null The placeholder data array, or null if the appointment is invalid.
	 */
	public function prepare_placeholder_data( $event_type, $object_id, $data ) {
		$placeholder_data = [
			'{site_name}'                  => get_bloginfo( 'name' ),
			'{site_url}'                   => home_url(),
			'{appointment_id}'             => '',
			'{appointment_date}'           => '',
			'{appointment_date_formatted}' => '',
			'{appointment_time}'           => '',
			'{appointment_time_slot}'      => '',
			'{appointment_duration}'       => '',
			'{appointment_notes}'          => '',
			'{appointment_edit_link_admin}' => '',
			'{appointment_view_link}'      => '',
			'{client_id}'                  => '',
			'{client_name}'                => '',
			'{client_email}'               => '',
			'{phone_number}'               => '',
			'{client_username}'            => '',
			'{client_first_name}'          => '',
			'{client_last_name}'           => '',
			'{client_display_name}'        => '',
			'{order_id}'                   => '',
			'{order_total}'                => '',
			'{order_link_admin}'           => '',
			'{order_payment_method_title}' => '',
			'{failure_reason}'             => '',
			'{waitlist_position}'          => '',
		];

		$appointment = null;
		$client_user = null;

		if ( in_array(
			$event_type,
			[
				'new_appointment_client',
				'new_appointment_admin',
				'appointment_updated_admin',
				'appointment_cancelled_admin',
				'appointment_cancelled_by_client',
				'appointment_reminder',
				'payment_successful_client',
				'payment_successful_admin',
				'scheduled_payment_failure_client',
				'scheduled_payment_failure_admin',
				'waitlist_joined',
				'waitlist_promoted',
			]
		) ) {
			$appointment = get_post( $object_id );
			// *** REFACTORED ***: Updated CPT name
			if ( $appointment && Constants::POST_TYPE_APPOINTMENT === $appointment->post_type ) {
				$placeholder_data['{appointment_id}'] = $appointment->ID;
				// *** REFACTORED ***: Updated meta key
				$appt_date_str                       = get_post_meta( $appointment->ID, Constants::META_APPOINTMENT_DATE, true );
				$placeholder_data['{appointment_date}'] = $appt_date_str;
				// *** REFACTORED ***: Updated constant name
				$appt_slot_str                           = get_post_meta( $appointment->ID, Constants::META_TIME_SLOT, true );
				$placeholder_data['{appointment_time_slot}'] = $appt_slot_str;

				if ( $appt_date_str && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $appt_date_str ) ) {
					$placeholder_data['{appointment_date_formatted}'] = wp_date( get_option( 'date_format' ), strtotime( $appt_date_str ) );
				}
				if ( $appt_slot_str ) {
					try {
						$dt_utc = new \DateTime($appt_slot_str, new \DateTimeZone('UTC'));
						$dt_site = $dt_utc->setTimezone(wp_timezone());
						$placeholder_data['{appointment_time}'] = $dt_site->format(get_option('time_format'));
					} catch (\Exception $e) {
						$placeholder_data['{appointment_time}'] = '';
					}
				}

				// *** REFACTORED ***: Updated meta key
				$placeholder_data['{appointment_duration}']      = get_post_meta( $appointment->ID, Constants::META_APPOINTMENT_DURATION, true );
				$placeholder_data['{appointment_notes}']         = wp_strip_all_tags( $appointment->post_content );
				$placeholder_data['{appointment_edit_link_admin}'] = get_edit_post_link( $appointment->ID, '' );
				$view_page_id                                    = get_option( Constants::OPTION_APPOINTMENT_VIEW_PAGE, 0 );
				if ( $view_page_id ) {
					$placeholder_data['{appointment_view_link}'] = add_query_arg( 'view_id', $appointment->ID, get_permalink( $view_page_id ) );
				}

				if ( $appointment->post_author > 0 ) {
					$client_user = get_userdata( $appointment->post_author );
				}
			} else {
				return null;
			}
		}

		if ( in_array( $event_type, [ 'new_client_registration_client', 'new_client_registration_admin' ] ) && ! $client_user ) {
			$client_user = get_userdata( $object_id );
		}

		if ( $client_user instanceof \WP_User ) {
			$placeholder_data['{client_id}']           = $client_user->ID;
			$placeholder_data['{client_email}']        = $client_user->user_email;
			$placeholder_data['{client_username}']     = $client_user->user_login;
			$placeholder_data['{client_first_name}']   = $client_user->first_name;
			$placeholder_data['{client_last_name}']    = $client_user->last_name;
			$placeholder_data['{client_display_name}'] = $client_user->display_name;
			$placeholder_data['{client_name}']         = ! empty( $client_user->first_name ) ? $client_user->first_name : $client_user->display_name;
			$placeholder_data['{phone_number}']        = get_user_meta( $client_user->ID, 'phone_number', true );
		} elseif ( empty( $placeholder_data['{client_name}'] ) ) {
			$placeholder_data['{client_name}'] = __( 'Valued Client', 'client-sync' ); // FIXED
		}

		$wc_order = null;
		if ( in_array( $event_type, [ 'payment_successful_client', 'payment_successful_admin', 'scheduled_payment_failure_client', 'scheduled_payment_failure_admin' ] ) && class_exists( 'WooCommerce' ) ) {
			if ( isset( $data['wc_order'] ) && $data['wc_order'] instanceof \WC_Order ) {
				$wc_order                       = $data['wc_order'];
				$placeholder_data['{order_id}'] = $wc_order->get_id();
			} elseif ( isset( $data['wc_order_id'] ) && is_numeric( $data['wc_order_id'] ) ) {
				$placeholder_data['{order_id}'] = $data['wc_order_id'];
				$wc_order                       = wc_get_order( $placeholder_data['{order_id}'] );
			} elseif ( $appointment ) {
				// *** REFACTORED ***: Updated constant name
				$order_id_from_meta = get_post_meta( $appointment->ID, Constants::META_WC_ORDER_ID, true );
				if ( $order_id_from_meta ) {
					$placeholder_data['{order_id}'] = $order_id_from_meta;
					$wc_order                       = wc_get_order( $order_id_from_meta );
				}
			}

			if ( $wc_order instanceof \WC_Order ) {
				$placeholder_data['{order_total}']                  = $wc_order->get_formatted_order_total();
				$placeholder_data['{order_link_admin}']             = esc_url( $wc_order->get_edit_order_url() );
				$placeholder_data['{order_payment_method_title}'] = $wc_order->get_payment_method_title();
			}
		}

		if ( $appointment ) {
			// *** REFACTORED ***: Updated option name
			$appointment_fields = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
			foreach ( $appointment_fields as $field_key => $field_config ) {
				$meta_key        = $field_key;
				$placeholder_key = '{appointment_field_' . $field_key . '}';
				$meta_value      = get_post_meta( $appointment->ID, $meta_key, true );
				if ( is_array( $meta_value ) ) {
					$placeholder_data[ $placeholder_key ] = implode( ', ', $meta_value );
				} elseif ( isset( $field_config['type'] ) && $field_config['type'] === 'image_map' && is_string( $meta_value ) ) {
					$placeholder_data[ $placeholder_key ] = __( '[Image Map Data]', 'client-sync' ); // FIXED
				} else {
					$placeholder_data[ $placeholder_key ] = $meta_value;
				}
			}
		}

		if ( in_array( $event_type, [ 'scheduled_payment_failure_client', 'scheduled_payment_failure_admin' ] ) && isset( $data['failure_reason'] ) ) {
			$placeholder_data['{failure_reason}'] = sanitize_text_field( $data['failure_reason'] );
		} elseif ( isset( $placeholder_data['{failure_reason}'] ) && empty( $placeholder_data['{failure_reason}'] ) ) {
			$placeholder_data['{failure_reason}'] = __( 'Unknown reason', 'client-sync' ); // FIXED
		}

		// Waitlist position placeholder.
		if ( in_array( $event_type, [ 'waitlist_joined', 'waitlist_promoted' ], true ) && $appointment ) {
			$placeholder_data['{waitlist_position}'] = get_post_meta( $appointment->ID, Constants::META_WAITLIST_POSITION, true ) ?: '';
		}

		$placeholder_data = array_merge( $placeholder_data, $data );

		// =========================================================================
		// HIPAA COMPLIANCE: Sanitize PHI placeholders for external notifications
		// =========================================================================
		$placeholder_data = $this->maybe_anonymize_placeholders_for_hipaa( $placeholder_data, $event_type );

		// *** REFACTORED ***: Updated filter name
		return apply_filters( 'clisyc_notification_placeholder_data', $placeholder_data, $event_type, $object_id, $data );
	}

	/**
	 * Anonymizes PHI placeholders for HIPAA compliance in external notifications.
	 *
	 * @param array  $placeholder_data The placeholder data array.
	 * @param string $event_type       The notification event type.
	 *
	 * @return array The sanitized placeholder data array.
	 */
	private function maybe_anonymize_placeholders_for_hipaa( array $placeholder_data, string $event_type ): array {
		// Check if HIPAA anonymization is required
		if ( ! $this->should_anonymize_for_hipaa() ) {
			return $placeholder_data;
		}

		// Define which placeholders contain PHI and should be anonymized
		$phi_placeholders = [
			// Client identifying information
			'{client_name}'         => __( 'Valued Client', 'client-sync' ),
			'{client_first_name}'   => __( 'Client', 'client-sync' ),
			'{client_last_name}'    => '',
			'{client_display_name}' => __( 'Client', 'client-sync' ),
			'{client_username}'     => __( '[Protected]', 'client-sync' ),
			'{phone_number}'        => __( '[Login to view]', 'client-sync' ),

			// Sensitive content
			'{appointment_notes}'   => __( '[Protected Content - Login to view]', 'client-sync' ),
		];

		// Apply anonymization to PHI placeholders
		foreach ( $phi_placeholders as $placeholder => $replacement ) {
			if ( isset( $placeholder_data[ $placeholder ] ) && ! empty( $placeholder_data[ $placeholder ] ) ) {
				$placeholder_data[ $placeholder ] = $replacement;
			}
		}

		// Anonymize custom field placeholders that may contain PHI
		// Check each appointment_field_ placeholder
		foreach ( $placeholder_data as $key => $value ) {
			if ( strpos( $key, '{appointment_field_' ) === 0 && ! empty( $value ) ) {
				// Check if this field is marked as sensitive
				$field_key = str_replace( [ '{appointment_field_', '}' ], '', $key );
				if ( $this->is_sensitive_custom_field( $field_key ) ) {
					$placeholder_data[ $key ] = __( '[Protected Content]', 'client-sync' );
				}
			}
		}

		// Note: We intentionally keep {client_email} intact for admin notifications
		// so admins can still contact clients. The email is only visible to admins
		// in the email body, not exposed to third parties.

		// For SMS specifically, we may want stricter controls
		// This is handled by marking the placeholder data with a flag
		$placeholder_data['_hipaa_anonymized'] = true;

		return $placeholder_data;
	}

	/**
	 * Determines whether HIPAA anonymization should be applied.
	 *
	 * @return bool True if placeholders should be anonymized for HIPAA compliance.
	 */
	private function should_anonymize_for_hipaa(): bool {
		// Check if the HIPAA helper function exists
		if ( ! function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) ) {
			return false;
		}

		// Check if HIPAA mode is active
		if ( ! \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode() ) {
			return false;
		}

		// Check if external notification anonymization is enabled
		// Default is true when HIPAA mode is on
		$anonymize_notifications = get_option( Constants::OPTION_ANONYMIZE_EXTERNAL_SYNC, true );

		return (bool) $anonymize_notifications;
	}

	/**
	 * Checks whether a custom appointment field is considered sensitive.
	 *
	 * @param string $field_key The field key to check.
	 *
	 * @return bool True if the field is sensitive and should be anonymized.
	 */
	private function is_sensitive_custom_field( string $field_key ): bool {
		$field_defs = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );

		// Check if field is explicitly marked as sensitive
		if ( isset( $field_defs[ $field_key ]['sensitive'] ) && $field_defs[ $field_key ]['sensitive'] ) {
			return true;
		}

		// Check if Encryption_Service considers this field sensitive
		if ( class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
			$encryption = \DependentMedia\ClientSync\Services\Encryption_Service::get_instance();
			if ( $encryption->should_encrypt_field( $field_key ) ) {
				return true;
			}
		}

		return false;
	}
}
