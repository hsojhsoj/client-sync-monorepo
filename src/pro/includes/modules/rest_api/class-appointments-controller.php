<?php
/**
 * File: /src/pro/includes/modules/rest_api/class-appointments-controller.php
 * Pro REST API Controller for handling appointment-related actions like series booking.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/RestAPI
 */

namespace ClientSyncPro\RestAPI;

use ClientSyncPro\Services\Series_Validator;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Appointments_Controller extends \WP_REST_Controller {

	protected $namespace = 'client-sync-pro/v1';
	protected $rest_base = 'appointments';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/series',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE, // This is a POST request
					'callback'            => [ $this, 'create_series_item' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'initial_slot_identifier' => [
							'required'    => true,
							'description' => 'The UTC datetime string (Y-m-d H:i:s) of the first appointment.',
							'type'        => 'string',
						],
						'dimensions' => [
							'required'    => true,
							'description' => 'An object mapping dimension slugs to post IDs.',
							'type'        => 'object',
						],
						'frequency' => [
							'required'    => true,
							'description' => 'The recurrence frequency.',
							'type'        => 'string',
							'enum'        => [ 'weekly', 'biweekly' ],
						],
						'count' => [
							'required'    => true,
							'description' => 'The number of appointments in the series.',
							'type'        => 'integer',
							'minimum'     => 2,
							'maximum'     => 52,
						],
						'custom_fields' => [
							'required'    => false,
							'description' => 'An object of custom field data.',
							'type'        => 'object',
						],
					],
				],
			]
		);
	}

	public function check_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Rate limit series creation to prevent abuse (5 per 5 minutes per user).
		if ( class_exists( '\DependentMedia\ClientSync\Utility\Security_Helper' ) ) {
			$rate_check = \DependentMedia\ClientSync\Utility\Security_Helper::check_rate_limit(
				'series_booking_' . get_current_user_id(),
				5,
				300
			);
			if ( is_wp_error( $rate_check ) ) {
				return $rate_check;
			}
		}

		return true;
	}

	public function create_series_item( \WP_REST_Request $request ) {
		$params = $request->get_json_params();

		// Sanitize all incoming data
		$initial_slot = sanitize_text_field( $params['initial_slot_identifier'] );
		$frequency    = sanitize_key( $params['frequency'] );
		$count        = absint( $params['count'] );
		$dimensions   = array_map( 'absint', (array) $params['dimensions'] );

		$sanitized_custom_fields = [];
		$custom_field_defs = get_option( 'clisyc_appointment_custom_fields', [] );
		if ( ! empty( $params['custom_fields'] ) && is_array( $params['custom_fields'] ) ) {
			foreach ( (array) $params['custom_fields'] as $key => $value ) {
				if ( isset( $custom_field_defs[ $key ] ) ) {
					$sanitized_custom_fields[ $key ] = Sanitizer::sanitize_custom_field_value( $value, $custom_field_defs[ $key ], $key );
				}
			}
		}

		$validator = new Series_Validator();
		$validation_result = $validator->validate_series_availability( $initial_slot, $dimensions, $frequency, $count );

		if ( is_wp_error( $validation_result ) ) {
			return $validation_result; // The REST server will format this as a proper error response
		}

		$creation_result = $validator->create_appointment_series(
			$initial_slot,
			$dimensions,
			$frequency,
			$count,
			get_current_user_id(),
			$sanitized_custom_fields
		);

		if ( is_wp_error( $creation_result ) ) {
			return $creation_result;
		}

		// ── WooCommerce checkout redirect ──────────────────────────────
		$wc_enabled = class_exists( 'WooCommerce' )
			&& get_option( Constants::OPTION_WC_ENABLED, false )
			&& absint( get_option( Constants::OPTION_WC_PRODUCT_ID, 0 ) ) > 0;

		if ( $wc_enabled && function_exists( 'WC' ) && WC()->cart ) {
			// Set all series appointments to pending payment.
			foreach ( $creation_result as $appt_id ) {
				wp_update_post( [
					'ID'          => $appt_id,
					'post_status' => Constants::STATUS_PENDING_PAYMENT,
				] );
			}

			// Clear cart and add one item per series appointment.
			WC()->cart->empty_cart();
			$product_id = absint( get_option( Constants::OPTION_WC_PRODUCT_ID, 0 ) );
			$series_id  = get_post_meta( $creation_result[0], Constants::META_SERIES_ID, true );

			foreach ( $creation_result as $appt_id ) {
				WC()->cart->add_to_cart( $product_id, 1, 0, [], [
					'_clisyc_appointment_id' => $appt_id,
					'_clisyc_is_series_item' => true,
					'_clisyc_series_id'      => $series_id,
				] );
			}

			return new \WP_REST_Response(
				[
					'success'          => true,
					'message'          => __( 'Redirecting to checkout…', 'client-sync-pro' ),
					'redirect_url'     => wc_get_checkout_url(),
					'appointment_ids'  => $creation_result,
					'payment_required' => true,
				],
				201
			);
		}

		// ── Non-WC success response ────────────────────────────────────
		return new \WP_REST_Response(
			[
				'success'         => true,
				'message'         => __( 'Your recurring appointments have been successfully booked!', 'client-sync-pro' ),
				'appointment_ids' => $creation_result,
			],
			201
		);
	}
}