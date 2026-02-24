<?php
/**
 * File: src/shared/includes/ajax/class-calendar-ajax-handler.php
 *
 * Handles calendar-related AJAX actions: frontend calendar events and admin
 * master schedule data.
 *
 * @package ClientSync
 */

namespace DependentMedia\ClientSync\Ajax;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Services\CalendarDataProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Calendar_Ajax_Handler {

	private $db;

	public function __construct( Database_Manager $db_manager ) {
		$this->db = $db_manager;
	}

	public function register_hooks() {
		add_action( 'wp_ajax_clisyc_get_calendar_events', [ $this, 'get_calendar_events' ] );
		add_action( 'wp_ajax_nopriv_clisyc_get_calendar_events', [ $this, 'get_calendar_events' ] );
		add_action( 'wp_ajax_clisyc_get_master_schedule_data', [ $this, 'ajax_get_master_schedule_data' ] );
	}

	public function get_calendar_events() {
		check_ajax_referer( 'clisyc_get_calendar_events_nonce', 'security' );

		$start_str     = isset( $_REQUEST['start'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['start'] ) ) : null;
		$end_str       = isset( $_REQUEST['end'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['end'] ) ) : null;

		// FIX: Sanitize input immediately upon retrieval.
		$hide_booked_raw = isset( $_REQUEST['hide_booked'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['hide_booked'] ) ) : false;
		$hide_booked   = rest_sanitize_boolean( $hide_booked_raw );

		$overview_mode = isset( $_REQUEST['overview_mode'] ) ? sanitize_key( wp_unslash( $_REQUEST['overview_mode'] ) ) : 'none';
		$context       = isset( $_REQUEST['context'] ) ? sanitize_key( wp_unslash( $_REQUEST['context'] ) ) : 'default';
		$view_context  = isset( $_REQUEST['view_context'] ) ? sanitize_key( wp_unslash( $_REQUEST['view_context'] ) ) : 'default';

		// --- Extract dimension filters from request (backwards compatible) ---
		// Dimensions can be passed as clisyc_service[], clisyc_practitioner[], etc.
		// If no dimensions are passed, this will be an empty array (same as before).
		$dimensions = [];
		$registry   = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );

		foreach ( $registry['dimensions'] ?? [] as $dim_slug => $dim_config ) {
			// Check for dimension filter in request (e.g., clisyc_service[] => [123])
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above with check_ajax_referer.
			if ( isset( $_REQUEST[ $dim_slug ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above, values sanitized below.
				$dim_value = wp_unslash( $_REQUEST[ $dim_slug ] );

				if ( is_array( $dim_value ) ) {
					// Array format: clisyc_service[] => [123, 456]
					$dimensions[ $dim_slug ] = array_map( 'absint', $dim_value );
				} else {
					// Single value format: clisyc_service => 123
					$dimensions[ $dim_slug ] = absint( $dim_value );
				}
			}
		}
		// --- End dimension filter extraction ---

		if ( ! $start_str || ! $end_str ) {
			wp_send_json_error( [ 'message' => __( 'Missing date range.', 'client-sync' ) ], 400 );
		}

		try {
			$fetch_info = [
				'start' => new \DateTime( $start_str ),
				'end'   => new \DateTime( $end_str ),
			];
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => __( 'Invalid date format.', 'client-sync' ) ], 400 );
		}

		$is_admin_editable_context = ( 'admin_editable' === $context && current_user_can( 'manage_options' ) );
		$user_id_to_fetch          = ( 'user_only' === $view_context && is_user_logged_in() ) ? get_current_user_id() : null;

		$provider      = new CalendarDataProvider( $this->db );
		// Pass $dimensions as the 7th parameter (defaults to [] in CalendarDataProvider if not provided).
		$calendar_data = $provider->get_calendar_data( $fetch_info, $is_admin_editable_context, $view_context, $user_id_to_fetch, $hide_booked, $overview_mode, $dimensions );

		if ( ! isset( $calendar_data['events'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Failed to retrieve calendar events.', 'client-sync' ) ], 500 );
		} else {
			wp_send_json_success( $calendar_data );
		}
	}

	/**
	 * AJAX handler to fetch layered data for the Master Schedule view.
	 */
	public function ajax_get_master_schedule_data() {
		check_ajax_referer( 'clisyc_get_master_schedule_data_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$start_str = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : null;
		$end_str   = isset( $_GET['end'] ) ? sanitize_text_field( wp_unslash( $_GET['end'] ) ) : null;

		if ( ! $start_str || ! $end_str ) {
			wp_send_json_error( [ 'message' => 'Missing date range.' ], 400 );
		}

		try {
			$fetch_info = [
				'start' => new \DateTime( $start_str ),
				'end'   => new \DateTime( $end_str ),
			];
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => 'Invalid date format.' ], 400 );
		}

		$all_events = [];

		// 1. Get all generated slots (available, blocked, booked)
		$provider      = new CalendarDataProvider( $this->db );
		$calendar_data = $provider->get_calendar_data( $fetch_info, true, 'admin_editable' );
		$all_events    = $calendar_data['events'] ?? [];

		// 2. Get all schedule templates as background events
		$registry           = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$dimensions_to_scan = [];
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['enabled'] ) && ( ! empty( $settings['primary'] ) || ! empty( $settings['is_resource'] ) ) ) {
				$dimensions_to_scan[] = $slug;
			}
		}

		if ( ! empty( $dimensions_to_scan ) ) {
			$posts = get_posts(
				[
					'post_type'      => $dimensions_to_scan,
					'posts_per_page' => 200,
					'no_found_rows'  => true,
					'post_status'    => 'publish',
				]
			);

			$period = new \DatePeriod( $fetch_info['start'], new \DateInterval( 'P1D' ), $fetch_info['end'] );

			foreach ( $posts as $post ) {
				$schedule_meta = get_post_meta( $post->ID, Constants::META_SCHEDULE, true );
				$schedule_data = json_decode( $schedule_meta, true );
				if ( json_last_error() !== JSON_ERROR_NONE || empty( $schedule_data['templates'] ) ) {
					continue;
				}

				foreach ( $period as $date ) {
					$day_of_week = (int) $date->format( 'w' );
					// For simplicity, we assume the 'A' template for all background events.
					// This can be expanded to support patterns if needed.
					$day_schedule = $schedule_data['templates']['A'][ $day_of_week ] ?? null;
					if ( empty( $day_schedule['slots'] ) ) {
						continue;
					}

					foreach ( $day_schedule['slots'] as $slot ) {
						$all_events[] = [
							'id'            => 'template-' . $post->ID . '-' . $day_of_week . '-' . $slot['start'],
							'title'         => 'Template: ' . $post->post_title,
							'start'         => $date->format( 'Y-m-d' ) . 'T' . $slot['start'],
							'end'           => $date->format( 'Y-m-d' ) . 'T' . $slot['end'],
							'display'       => 'background',
							'allDay'        => false,
							'editable'      => false,
							'extendedProps' => [
								'isTemplate' => true,
								'editUrl'    => get_edit_post_link( $post->ID, 'raw' ),
							],
						];
					}
				}
			}
		}

		wp_send_json_success( [ 'events' => $all_events ] );
	}
}
