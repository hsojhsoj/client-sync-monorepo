<?php
/**
 * File: src/shared/includes/ajax/class-admin-ajax-handler.php
 *
 * Handles admin-only AJAX actions: dimension toggling, reordering, schedule
 * saving, WooCommerce product helpers, slot visibility audit, and page creation.
 *
 * @package ClientSync
 */

namespace DependentMedia\ClientSync\Ajax;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Utility\Debug_Logger;
use DependentMedia\ClientSync\Utility\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Ajax_Handler {

	private $db;

	public function __construct( Database_Manager $db_manager ) {
		$this->db = $db_manager;
	}

	public function register_hooks() {
		add_action( 'wp_ajax_clisyc_toggle_dimension_enabled', [ $this, 'ajax_toggle_dimension_enabled' ] );
		add_action( 'wp_ajax_clisyc_save_post_schedule', [ $this, 'save_post_schedule' ] );
		add_action( 'wp_ajax_clisyc_reorder_dimensions', [ $this, 'ajax_reorder_dimensions' ] );
		add_action( 'wp_ajax_clisyc_create_wc_product', [ $this, 'ajax_create_and_link_product' ] );
		add_action( 'wp_ajax_clisyc_get_wc_product_price', [ $this, 'ajax_get_wc_product_price' ] );
		add_action( 'wp_ajax_clisyc_audit_slot_visibility', [ $this, 'ajax_audit_slot_visibility' ] );
		add_action( 'wp_ajax_clisyc_create_page_for_setting', [ $this, 'ajax_create_page_for_setting' ] );
		add_action( 'wp_ajax_clisyc_send_test_sms', [ $this, 'ajax_send_test_sms' ] );
	}

	public function ajax_toggle_dimension_enabled() {
		check_ajax_referer( 'clisyc_toggle_dimension_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ], 403 );
		}

		// FIX: Sanitize input immediately upon retrieval.
		$slug         = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$enabled_raw  = isset( $_POST['enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '0';
		$is_enabled   = ( '1' === $enabled_raw );

		if ( empty( $slug ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid dimension slug.', 'client-sync' ) ], 400 );
		}

		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );

		if ( ! isset( $registry['dimensions'][ $slug ] ) ) {
			$registry['dimensions'][ $slug ] = [
				'primary'  => false,
				'position' => [
					'x' => 0,
					'y' => 0,
				],
			];
		}

		$was_primary                     = ! empty( $registry['dimensions'][ $slug ]['primary'] );
		$registry['dimensions'][ $slug ]['enabled'] = $is_enabled;

		if ( ! $is_enabled && $was_primary ) {
			$registry['dimensions'][ $slug ]['primary'] = false;
			foreach ( $registry['dimensions'] as $other_slug => &$settings ) {
				if ( ! empty( $settings['enabled'] ) ) {
					$settings['primary'] = true;
					break;
				}
			}
			unset( $settings );
		}

		update_option( Constants::OPTION_DIMENSION_REGISTRY, $registry );
		wp_send_json_success( [ 'message' => __( 'Status updated.', 'client-sync' ) ] );
	}

	public function save_post_schedule() {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		check_ajax_referer( 'clisyc_save_post_schedule_' . $post_id, 'security' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'client-sync' ) ], 403 );
		}

		// FIX: Sanitize input immediately upon retrieval.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array structure sanitized in loop below.
		$schedule_data_from_post = isset( $_POST['schedule_data'] ) && is_array( $_POST['schedule_data'] ) ? wp_unslash( $_POST['schedule_data'] ) : [];
		$raw_schedule_data = $schedule_data_from_post;

		$sanitized_schedule = [
			'pattern_enabled'    => ! empty( $raw_schedule_data['pattern_enabled'] ),
			'pattern_start_date' => sanitize_text_field( $raw_schedule_data['pattern_start_date'] ?? '' ),
			'pattern_sequence'   => sanitize_text_field( $raw_schedule_data['pattern_sequence'] ?? '' ),
			'templates'          => [],
		];

		if ( isset( $raw_schedule_data['templates'] ) && is_array( $raw_schedule_data['templates'] ) ) {
			foreach ( $raw_schedule_data['templates'] as $template_key => $template_data ) {
				$sane_template_key                                  = sanitize_key( $template_key );
				$sanitized_schedule['templates'][ $sane_template_key ] = [];

				if ( is_array( $template_data ) ) {
					foreach ( $template_data as $day_index => $day_data ) {
						$day_index = (int) $day_index;
						if ( $day_index < 0 || $day_index > 6 || ! is_array( $day_data ) || empty( $day_data['slots'] ) ) {
							continue;
						}

						$sanitized_slots = [];
						foreach ( $day_data['slots'] as $slot ) {
							$start_time = isset( $slot['start'] ) ? Sanitizer::sanitize_time_format( $slot['start'] ) : null;
							$end_time   = isset( $slot['end'] ) ? Sanitizer::sanitize_time_format( $slot['end'] ) : null;
							if ( $start_time && $end_time && $start_time < $end_time ) {
								$sanitized_slots[] = [
									'start' => $start_time,
									'end'   => $end_time,
								];
							}
						}

						if ( ! empty( $sanitized_slots ) ) {
							usort( $sanitized_slots, fn( $a, $b ) => strcmp( $a['start'], $b['start'] ) );
							$sanitized_schedule['templates'][ $sane_template_key ][ $day_index ] = [ 'slots' => $sanitized_slots ];
						}
					}
				}
			}
		}

		update_post_meta( $post_id, Constants::META_SCHEDULE, wp_json_encode( $sanitized_schedule ) );

		wp_send_json_success( [ 'message' => __( 'Weekly schedule saved successfully.', 'client-sync' ) ] );
	}

	public function ajax_reorder_dimensions() {
		check_ajax_referer( 'clisyc_reorder_dimensions_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ], 403 );
		}

		$new_order = isset( $_POST['order'] ) && is_array( $_POST['order'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['order'] ) )
			: [];

		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$registry['filter_order'] = $new_order;
		update_option( Constants::OPTION_DIMENSION_REGISTRY, $registry );

		wp_send_json_success( [ 'message' => __( 'Filter order updated.', 'client-sync' ) ] );
	}

	/**
	 * AJAX handler to create a WooCommerce product and link it to a service.
	 */
	public function ajax_create_and_link_product() {
		check_ajax_referer( 'clisyc_wc_product_helper_nonce', 'security' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to create products.', 'client-sync' ) ], 403 );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'WooCommerce is not active.', 'client-sync' ) ], 500 );
		}

		$post_title = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : '';
		if ( empty( $post_title ) ) {
			wp_send_json_error( [ 'message' => __( 'Product title cannot be empty.', 'client-sync' ) ], 400 );
		}

		$product = new \WC_Product_Simple();
		$product->set_name( $post_title );
		$product->set_status( 'publish' );
		$product->set_virtual( true );
		$product->set_regular_price( '0.00' ); // Default price, user can edit.
		$product->set_sku( 'clisyc-' . sanitize_title( $post_title ) . '-' . wp_rand( 100, 999 ) );
		$product->set_description(
			sprintf(
				/* translators: %s: The name of the service/booking item. */
				esc_html__( 'This product is used for booking the "%s" service via Client Sync.', 'client-sync' ),
				$post_title
			)
		);

		$new_product_id = $product->save();

		if ( is_wp_error( $new_product_id ) ) {
			Debug_Logger::log( 'WooCommerce product creation failed: ' . $new_product_id->get_error_message(), 'AdminAjax' );
			wp_send_json_error( [ 'message' => __( 'An error occurred while creating the product. Please try again.', 'client-sync' ) ], 500 );
		}
		if ( 0 === $new_product_id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to create WooCommerce product.', 'client-sync' ) ], 500 );
		}

		// The product was created, now send back the data to update the UI.
		wp_send_json_success(
			[
				'message'          => __( 'Product created and linked successfully.', 'client-sync' ),
				'new_product_id'   => $new_product_id,
				'formatted_name'   => $product->get_formatted_name(),
			]
		);
	}

	/**
	 * AJAX handler to fetch the regular price of a WooCommerce product.
	 */
	public function ajax_get_wc_product_price() {
		// This matches the renamed nonce in class-asset-manager.php
		check_ajax_referer( 'clisyc_wc_product_helper_nonce', 'security' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = ( function_exists( 'wc_get_product' ) ) ? wc_get_product( $product_id ) : null;

		if ( ! $product ) {
			wp_send_json_error( [ 'message' => 'Product not found.' ] );
		}

		wp_send_json_success( [
			'price' => $product->get_regular_price(), // Fetches the 10.50 value
		] );
	}

	/**
	 * AJAX handler for performing a slot visibility audit in settings.
	 */
	public function ajax_audit_slot_visibility() {
		check_ajax_referer( 'clisyc_settings_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ] );
		}

		$start_time_setting = get_option( Constants::OPTION_CALENDAR_START_TIME, '08:00' );
		$end_time_setting   = get_option( Constants::OPTION_CALENDAR_END_TIME, '18:00' );

		// Parse settings into minutes from midnight
		list( $s_h, $s_m ) = explode( ':', $start_time_setting );
		list( $e_h, $e_m ) = explode( ':', $end_time_setting );
		$cal_start_mins = ( $s_h * 60 ) + $s_m;
		$cal_end_mins   = ( $e_h * 60 ) + $e_m;

		$now_utc = gmdate( 'Y-m-d H:i:s' );
		$future_utc = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) );

		// Get raw available slots
		$slots = $this->db->get_slots_for_date_range( $now_utc, $future_utc, [], false );

		$hidden_count = 0;
		$site_tz = wp_timezone();

		foreach ( $slots as $slot ) {
			$dt = new \DateTime( $slot['start_time_utc'], new \DateTimeZone( 'UTC' ) );
			$dt->setTimezone( $site_tz );

			$slot_mins = ( $dt->format( 'H' ) * 60 ) + $dt->format( 'i' );

			if ( $slot_mins < $cal_start_mins || $slot_mins >= $cal_end_mins ) {
				$hidden_count++;
			}
		}

		if ( $hidden_count > 0 ) {
			wp_send_json_success( [
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: 1: Count of hidden slots, 2: Start time, 3: End time */
					__( 'Audit Complete: Found %1$d available slot(s) that fall outside your visible range (%2$s - %3$s).', 'client-sync' ),
					$hidden_count,
					$start_time_setting,
					$end_time_setting
				)
			] );
		} else {
			wp_send_json_success( [
				'status'  => 'success',
				'message' => __( 'Audit Complete: All future available slots are within your visible calendar hours.', 'client-sync' )
			] );
		}
	}

	/**
	 * AJAX: Create a single page for a "Frontend Links & Pages" setting and auto-assign it.
	 *
	 * Expects POST data:
	 *   - security:   nonce (clisyc_settings_nonce)
	 *   - option_key: the WP option name (e.g. 'clisyc_booking_page_id')
	 *
	 * @return void  Sends JSON response.
	 */
	public function ajax_create_page_for_setting() {
		check_ajax_referer( 'clisyc_settings_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
		}

		$option_key = isset( $_POST['option_key'] ) ? sanitize_key( $_POST['option_key'] ) : '';

		if ( empty( $option_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Missing option key.', 'client-sync' ) ] );
		}

		// Look up the page definition that matches this option_key.
		$page_definitions = \DependentMedia\ClientSync\Admin\Onboarding_Wizard::get_page_definitions();
		$target_slug      = '';
		$target_def       = null;

		foreach ( $page_definitions as $slug => $def ) {
			if ( ( $def['option_key'] ?? '' ) === $option_key ) {
				$target_slug = $slug;
				$target_def  = $def;
				break;
			}
		}

		if ( ! $target_def ) {
			wp_send_json_error( [ 'message' => __( 'Unknown setting.', 'client-sync' ) ] );
		}

		// Check if the page already exists by slug.
		$existing_page = get_page_by_path( $target_slug );
		if ( $existing_page ) {
			// Page exists — just wire it up.
			update_option( $option_key, $existing_page->ID );
			wp_send_json_success( [
				'page_id'    => $existing_page->ID,
				'page_title' => get_the_title( $existing_page->ID ),
			] );
		}

		// Create the page.
		$page_id = wp_insert_post( [
			'post_title'   => $target_def['title'],
			'post_content' => $target_def['content'],
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_name'    => $target_slug,
		] );

		if ( is_wp_error( $page_id ) ) {
			Debug_Logger::log( 'Page creation failed for setting "' . $option_key . '": ' . $page_id->get_error_message(), 'AdminAjax' );
			wp_send_json_error( [ 'message' => __( 'An error occurred while creating the page. Please try again.', 'client-sync' ) ] );
		}

		// Wire the option.
		update_option( $option_key, $page_id );

		// Special handling for appointment details slug option.
		if ( 'appointment-details' === $target_slug ) {
			update_option( clisyc_APPOINTMENT_VIEW_PAGE_SLUG_OPTION, $target_slug );
		}

		wp_send_json_success( [
			'page_id'    => $page_id,
			'page_title' => get_the_title( $page_id ),
		] );
	}

	/**
	 * AJAX handler: Send a test SMS to verify Twilio credentials.
	 *
	 * Requires: Pro license active, manage_options capability, valid E.164 phone.
	 *
	 * @return void Sends JSON response and exits.
	 */
	public function ajax_send_test_sms() {
		check_ajax_referer( 'clisyc_send_test_sms', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'client-sync' ) ] );
			return;
		}

		if ( ! function_exists( 'clisyc_pro_is_license_active' ) || ! clisyc_pro_is_license_active() ) {
			wp_send_json_error( [ 'message' => __( 'Pro license required for SMS.', 'client-sync' ) ] );
			return;
		}

		$to = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		if ( empty( $to ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a phone number.', 'client-sync' ) ] );
			return;
		}

		// Load and use the phone normalizer.
		$pro_plugin_dir = WP_PLUGIN_DIR . '/client-sync-pro/includes/';
		if ( file_exists( $pro_plugin_dir . 'utilities/class-phone-normalizer.php' ) ) {
			require_once $pro_plugin_dir . 'utilities/class-phone-normalizer.php';
			$to = \ClientSyncPro\Utilities\Phone_Normalizer::to_e164( $to );
			if ( ! \ClientSyncPro\Utilities\Phone_Normalizer::is_valid_e164( $to ) ) {
				wp_send_json_error( [ 'message' => __( 'Invalid phone format. Use E.164 format (e.g., +15551234567).', 'client-sync' ) ] );
				return;
			}
		}

		// Load SMS service and send.
		if ( ! file_exists( $pro_plugin_dir . 'services/class-sms-service.php' ) ) {
			wp_send_json_error( [ 'message' => __( 'SMS Service not found.', 'client-sync' ) ] );
			return;
		}
		require_once $pro_plugin_dir . 'services/class-sms-service.php';

		$site_name = get_bloginfo( 'name' );
		/* translators: %s: Site name. */
		$message = sprintf( __( 'Test SMS from %s — Your Twilio integration is working!', 'client-sync' ), $site_name );

		$result = \ClientSyncPro\Services\Sms_Service::send_sms( $to, $message, 'test' );

		if ( $result ) {
			wp_send_json_success( [ 'message' => __( 'Test SMS sent successfully!', 'client-sync' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to send. Check Twilio credentials and the WP debug log for details.', 'client-sync' ) ] );
		}
	}
}
