<?php
/**
 * File: src/shared/includes/core/class-appointment-meta-saver.php
 *
 * Extracted from PostType_Manager — handles saving appointment and
 * dimension meta data from admin edit screens.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Admin\Relationship_Manager;
use DependentMedia\ClientSync\Services\Encryption_Service;
use DependentMedia\ClientSync\Utility\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Appointment_Meta_Saver {

	/**
	 * Register hooks for saving appointment and dimension meta data.
	 *
	 * @param PostType_Manager $ptm The parent PostType_Manager instance (needed
	 *                              for the save_post unhook/rehook cycle).
	 */
	public function register_hooks( PostType_Manager $ptm ): void {
		add_action( 'save_post_clisyc_appointment', [ $this, 'save_appointment_meta_data' ], 10, 2 );

		$registry           = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$enabled_dimensions = $registry['dimensions'] ?? [];

		foreach ( array_keys( $enabled_dimensions ) as $cpt_slug ) {
			add_action( 'save_post_' . $cpt_slug, [ $this, 'save_dimension_meta_data' ], 10, 2 );
		}
	}

	/**
	 * Save appointment meta data when an appointment is saved in the admin.
	 */
	public function save_appointment_meta_data( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['clisyc_appointment_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['clisyc_appointment_meta_nonce'] ) ), 'clisyc_save_appointment_meta_nonce' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$unslashed_post = wp_unslash( $_POST );

		// ── Time Slot Fields ─────────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		if ( isset( $unslashed_post['clisyc_selected_slot_identifier_hidden'] ) ) {
			update_post_meta( $post_id, Constants::META_TIME_SLOT, sanitize_text_field( $unslashed_post['clisyc_selected_slot_identifier_hidden'] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['clisyc_selected_slot_date_hidden'] ) ) {
			update_post_meta( $post_id, Constants::META_APPOINTMENT_DATE, sanitize_text_field( $unslashed_post['clisyc_selected_slot_date_hidden'] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['clisyc_selected_slot_duration_hidden'] ) ) {
			update_post_meta( $post_id, Constants::META_APPOINTMENT_DURATION, absint( $unslashed_post['clisyc_selected_slot_duration_hidden'] ) );
		}

		// ── Slot Dimensions & Auto-Title ─────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['clisyc_slot_dimensions'] ) && is_array( $unslashed_post['clisyc_slot_dimensions'] ) ) {
			$slot_dimensions = [];
			foreach ( $unslashed_post['clisyc_slot_dimensions'] as $dim_key => $dim_value ) {
				$dim_key   = sanitize_key( $dim_key );
				$dim_value = absint( $dim_value );
				if ( $dim_value > 0 ) {
					$slot_dimensions[ $dim_key ] = $dim_value;
				}
			}

			if ( ! empty( $slot_dimensions ) ) {
				update_post_meta( $post_id, Constants::META_SLOT_DIMENSIONS, $slot_dimensions );
				$this->maybe_auto_generate_title( $post_id, $post, $slot_dimensions, $unslashed_post );
			}
		}

		// ── Date Range Fields ────────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['clisyc_start_date'] ) ) {
			update_post_meta( $post_id, Constants::META_START_DATE, sanitize_text_field( $unslashed_post['clisyc_start_date'] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['clisyc_end_date'] ) ) {
			update_post_meta( $post_id, Constants::META_END_DATE, sanitize_text_field( $unslashed_post['clisyc_end_date'] ) );
		}

		// ── Client Assignment ────────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['clisyc_client_id'] ) ) {
			$client_id = absint( $unslashed_post['clisyc_client_id'] );
			if ( $client_id > 0 && get_user_by( 'ID', $client_id ) && $post->post_author != $client_id ) {
				wp_update_post( [
					'ID'          => $post_id,
					'post_author' => $client_id,
				] );
			}
		}

		// ── Custom Fields (with HIPAA encryption) ────────────────
		$submitted_data = $unslashed_post['clisyc_custom_field'] ?? [];
		$field_defs     = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		$field_order    = get_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, array_keys( $field_defs ) );

		$encryption = null;
		if ( class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
			$encryption = Encryption_Service::get_instance();
		}

		foreach ( $field_order as $field_key ) {
			if ( ! isset( $field_defs[ $field_key ] ) ) {
				continue;
			}
			$field_definition = $field_defs[ $field_key ];
			$raw_value        = $submitted_data[ $field_key ] ?? null;
			$sanitized_value  = Sanitizer::sanitize_custom_field_value( $raw_value, $field_definition, $field_key );

			if ( $encryption ) {
				$should_encrypt = $encryption->should_encrypt_field( $field_key );
				if ( ! $should_encrypt && ! empty( $field_definition['sensitive'] ) ) {
					$should_encrypt = true;
				}
				if ( $should_encrypt && ! empty( $sanitized_value ) ) {
					$sanitized_value = $encryption->maybe_encrypt( $sanitized_value, $field_key );
				}
			}

			update_post_meta( $post_id, $field_key, $sanitized_value );
		}

		// ── Inline Pricing Rules (from appointment editor) ───────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_POST['_clisyc_pricing_rules'] ) ) {
			$clisyc_pricing_rules   = wp_unslash( $_POST['_clisyc_pricing_rules'] );
			$clisyc_sanitized_rules = [];
			if ( is_array( $clisyc_pricing_rules ) ) {
				foreach ( $clisyc_pricing_rules as $clisyc_rule ) {
					if ( empty( $clisyc_rule['type'] ) ) {
						continue;
					}
					$clisyc_sane_rule = [
						'type'  => sanitize_key( $clisyc_rule['type'] ),
						'mode'  => sanitize_key( $clisyc_rule['mode'] ?? 'set' ),
						'value' => sanitize_text_field( $clisyc_rule['value'] ?? '' ),
					];
					switch ( $clisyc_sane_rule['type'] ) {
						case 'seasonal':
							$clisyc_sane_rule['start'] = sanitize_text_field( $clisyc_rule['start'] ?? '' );
							$clisyc_sane_rule['end']   = sanitize_text_field( $clisyc_rule['end'] ?? '' );
							break;
						case 'dow':
							$clisyc_sane_rule['days'] = isset( $clisyc_rule['days'] ) && is_array( $clisyc_rule['days'] ) ? array_map( 'absint', $clisyc_rule['days'] ) : [];
							break;
						case 'los':
							$clisyc_sane_rule['min_days'] = absint( $clisyc_rule['min_days'] ?? 0 );
							$clisyc_sane_rule['max_days'] = absint( $clisyc_rule['max_days'] ?? 0 );
							break;
					}
					$clisyc_sanitized_rules[] = $clisyc_sane_rule;
				}
			}
			update_post_meta( $post_id, Constants::META_PRICING_RULES, $clisyc_sanitized_rules );
		}
	}

	/**
	 * Save dimension meta data when a dimension post (service, room, etc.) is saved.
	 */
	public function save_dimension_meta_data( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$registry       = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$unslashed_post = wp_unslash( $_POST );

		// ── Relationships ────────────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['clisyc_relationships_nonce'] ) && wp_verify_nonce( sanitize_key( $unslashed_post['clisyc_relationships_nonce'] ), 'clisyc_save_relationships_' . $post_id ) ) {
			Relationship_Manager::save_relationships( $post_id, $registry );
		}

		// ── WooCommerce Integration Data ─────────────────────────
		if ( isset( $unslashed_post['clisyc_wc_meta_nonce'] ) && wp_verify_nonce( sanitize_key( $unslashed_post['clisyc_wc_meta_nonce'] ), 'clisyc_save_wc_meta_nonce_' . $post_id ) ) {
			$product_id = isset( $unslashed_post['clisyc_wc_product_id'] ) ? absint( $unslashed_post['clisyc_wc_product_id'] ) : 0;
			update_post_meta( $post_id, Constants::META_WC_PRODUCT_ID, $product_id );

			$calc_type = isset( $unslashed_post['clisyc_addon_calc_type'] ) ? sanitize_key( $unslashed_post['clisyc_addon_calc_type'] ) : 'per_night';
			update_post_meta( $post_id, Constants::META_ADDON_CALC_TYPE, $calc_type );

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_additional       = isset( $unslashed_post['_clisyc_additional_products'] ) ? $unslashed_post['_clisyc_additional_products'] : [];
			$sanitized_additional = [];
			foreach ( (array) $raw_additional as $item ) {
				if ( ! empty( $item['product_id'] ) ) {
					$sanitized_additional[] = [
						'product_id' => absint( $item['product_id'] ),
						'calc_type'  => sanitize_key( $item['calc_type'] ?? 'flat' ),
					];
				}
			}
			update_post_meta( $post_id, Constants::META_ADDITIONAL_PRODUCTS, $sanitized_additional );

			if ( isset( $unslashed_post['clisyc_price'] ) ) {
				update_post_meta( $post_id, Constants::META_PRICE, sanitize_text_field( $unslashed_post['clisyc_price'] ) );
			}
		}

		// ── Service/Primary Dimension Attributes ─────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['clisyc_service_meta_nonce'] ) && wp_verify_nonce( sanitize_key( $unslashed_post['clisyc_service_meta_nonce'] ), 'clisyc_save_service_meta_nonce_' . $post_id ) ) {
			update_post_meta( $post_id, Constants::META_CAPACITY, isset( $unslashed_post['clisyc_capacity'] ) ? max( 1, absint( $unslashed_post['clisyc_capacity'] ) ) : 1 );
			update_post_meta( $post_id, Constants::META_BUFFER_BEFORE, isset( $unslashed_post['clisyc_buffer_before'] ) ? absint( $unslashed_post['clisyc_buffer_before'] ) : 0 );
			update_post_meta( $post_id, Constants::META_BUFFER_AFTER, isset( $unslashed_post['clisyc_buffer_after'] ) ? absint( $unslashed_post['clisyc_buffer_after'] ) : 0 );

			$duration_raw = isset( $unslashed_post['clisyc_duration_minutes'] ) ? trim( $unslashed_post['clisyc_duration_minutes'] ) : '';
			$duration     = ( '' !== $duration_raw ) ? absint( $duration_raw ) : null;
			$padding      = isset( $unslashed_post['clisyc_padding_minutes'] ) ? absint( $unslashed_post['clisyc_padding_minutes'] ) : 0;

			if ( null !== $duration ) {
				update_post_meta( $post_id, Constants::META_DURATION_MINUTES, $duration );
			}
			update_post_meta( $post_id, Constants::META_PADDING_MINUTES, $padding );

			update_post_meta( $post_id, Constants::META_MIN_STAY, isset( $unslashed_post['clisyc_min_stay'] ) ? absint( $unslashed_post['clisyc_min_stay'] ) : 0 );
			update_post_meta( $post_id, Constants::META_MAX_STAY, isset( $unslashed_post['clisyc_max_stay'] ) ? absint( $unslashed_post['clisyc_max_stay'] ) : 0 );
			update_post_meta( $post_id, Constants::META_BUFFER_DAYS, isset( $unslashed_post['clisyc_buffer_days'] ) ? absint( $unslashed_post['clisyc_buffer_days'] ) : 0 );

			$booking_mode = sanitize_key( $unslashed_post['clisyc_booking_mode'] ?? 'slot' );
			if ( in_array( $booking_mode, [ 'slot', 'date_range' ], true ) ) {
				update_post_meta( $post_id, Constants::META_BOOKING_MODE, $booking_mode );
			}

			$checkin_days = isset( $unslashed_post['_clisyc_allowed_checkin_days'] ) && is_array( $unslashed_post['_clisyc_allowed_checkin_days'] )
				? array_map( 'absint', $unslashed_post['_clisyc_allowed_checkin_days'] )
				: [];
			update_post_meta( $post_id, Constants::META_ALLOWED_CHECKIN, $checkin_days );

			$checkout_days = isset( $unslashed_post['_clisyc_allowed_checkout_days'] ) && is_array( $unslashed_post['_clisyc_allowed_checkout_days'] )
				? array_map( 'absint', $unslashed_post['_clisyc_allowed_checkout_days'] )
				: [];
			update_post_meta( $post_id, Constants::META_ALLOWED_CHECKOUT, $checkout_days );

			if ( isset( $unslashed_post['_clisyc_video_conferencing'] ) ) {
				update_post_meta( $post_id, Constants::META_VIDEO_CONF_ENABLED, '1' );
			} else {
				delete_post_meta( $post_id, Constants::META_VIDEO_CONF_ENABLED );
			}
		}

		// ── Color Attribute ──────────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['clisyc_color_meta_nonce'] ) && wp_verify_nonce( sanitize_key( $unslashed_post['clisyc_color_meta_nonce'] ), 'clisyc_save_color_meta_nonce_' . $post_id ) ) {
			if ( isset( $unslashed_post['clisyc_color'] ) ) {
				update_post_meta( $post_id, Constants::META_COLOR, sanitize_hex_color( $unslashed_post['clisyc_color'] ) );
			}
		}

		// ── Employee/User Link ───────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['clisyc_employee_user_link_nonce'] ) && wp_verify_nonce( sanitize_key( $unslashed_post['clisyc_employee_user_link_nonce'] ), 'clisyc_save_employee_user_link_nonce_' . $post_id ) ) {
			$user_id_to_link = isset( $unslashed_post['_clisyc_employee_user_id'] ) ? absint( $unslashed_post['_clisyc_employee_user_id'] ) : 0;
			update_post_meta( $post_id, Constants::META_EMPLOYEE_USER_ID, $user_id_to_link );
		}

		// ── Dynamic Attributes ───────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['clisyc_dynamic_attributes_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['clisyc_dynamic_attributes_nonce'] ) ), 'clisyc_save_dynamic_attributes_nonce' ) ) {
			$dimension_fields = get_option( Constants::OPTION_DIMENSION_FIELDS, [] );
			foreach ( $dimension_fields as $key => $field ) {
				if ( in_array( $post->post_type, $field['applies_to'], true ) ) {
					$prefixed_key = '_clisyc_' . $key;
					if ( isset( $_POST[ $key ] ) ) {
						$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
						update_post_meta( $post_id, $prefixed_key, $value );
					} else {
						delete_post_meta( $post_id, $prefixed_key );
					}
				}
			}
		}

		// ── Schedule JSON ────────────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['_clisyc_schedule_json'] ) ) {
			$schedule_json = $unslashed_post['_clisyc_schedule_json'];
			$decoded       = json_decode( $schedule_json, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				update_post_meta( $post_id, Constants::META_SCHEDULE, $schedule_json );
			}
		} else {
			if ( ! get_post_meta( $post_id, Constants::META_SCHEDULE, true ) ) {
				update_post_meta( $post_id, Constants::META_SCHEDULE, '{"templates":{"A":{}}}' );
			}
		}

		// ── Schedule Overrides ───────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $unslashed_post['_clisyc_schedule_overrides'] ) ) {
			$overrides_json = sanitize_textarea_field( $unslashed_post['_clisyc_schedule_overrides'] );
			update_post_meta( $post_id, Constants::META_SCHEDULE_OVERRIDES, $overrides_json );
		}
	}

	/**
	 * Auto-generate appointment title from dimension names + date.
	 */
	private function maybe_auto_generate_title( int $post_id, \WP_Post $post, array $slot_dimensions, array $unslashed_post ): void {
		$current_title = $post->post_title;
		if ( ! empty( $current_title ) && $current_title !== __( 'Auto Draft', 'client-sync' ) && $current_title !== 'Auto Draft' ) {
			return;
		}

		$title_parts        = [];
		$registry           = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$enabled_dimensions = $registry['dimensions'] ?? [];

		// Find the primary dimension slug.
		$primary_slug = null;
		foreach ( $enabled_dimensions as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_slug = $slug;
				break;
			}
		}

		// Primary dimension first.
		if ( $primary_slug && isset( $slot_dimensions[ $primary_slug ] ) ) {
			$primary_title = get_the_title( $slot_dimensions[ $primary_slug ] );
			if ( $primary_title ) {
				$title_parts[] = $primary_title;
			}
		}

		// Other dimensions.
		foreach ( $slot_dimensions as $dim_slug => $dim_post_id ) {
			if ( $dim_slug === $primary_slug ) {
				continue;
			}
			$dim_title = get_the_title( $dim_post_id );
			if ( $dim_title ) {
				$title_parts[] = $dim_title;
			}
		}

		// Append date.
		$appt_date = isset( $unslashed_post['clisyc_selected_slot_date_hidden'] )
			? sanitize_text_field( $unslashed_post['clisyc_selected_slot_date_hidden'] )
			: get_post_meta( $post_id, Constants::META_APPOINTMENT_DATE, true );

		if ( $appt_date ) {
			try {
				$date_obj      = new \DateTime( $appt_date );
				$title_parts[] = $date_obj->format( 'M j, Y' );
			} catch ( \Exception $e ) {
				$title_parts[] = $appt_date;
			}
		}

		if ( ! empty( $title_parts ) ) {
			$new_title = implode( ' - ', $title_parts );

			// Unhook then rehook to prevent infinite loop.
			remove_action( 'save_post_clisyc_appointment', [ $this, 'save_appointment_meta_data' ], 10 );
			wp_update_post( [
				'ID'         => $post_id,
				'post_title' => $new_title,
			] );
			add_action( 'save_post_clisyc_appointment', [ $this, 'save_appointment_meta_data' ], 10, 2 );
		}
	}
}
