<?php
/**
 * File: src/shared/includes/ajax/class-slots-ajax-handler.php
 *
 * Handles slot CRUD AJAX actions: fetching and saving available slots.
 *
 * @package ClientSync
 */

namespace DependentMedia\ClientSync\Ajax;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Capacity_Resolver;
use DependentMedia\ClientSync\Core\Database_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slots_Ajax_Handler {

	private $db;

	public function __construct( Database_Manager $db_manager ) {
		$this->db = $db_manager;
	}

	public function register_hooks() {
		add_action( 'wp_ajax_clisyc_get_available_slots', [ $this, 'get_available_slots' ] );
		add_action( 'wp_ajax_clisyc_save_available_slots', [ $this, 'save_available_slots' ] );
	}

	public function get_available_slots() {
		_deprecated_function( __METHOD__, '3.5.0', 'REST API endpoint /clisyc/v1/slots' );
		check_ajax_referer( 'clisyc_get_available_slots_nonce', 'security' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ], 403 );
		}

		$selected_date_str = isset( $_POST['selected_date'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_date'] ) ) : '';
		$post_id           = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( empty( $selected_date_str ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $selected_date_str ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid date provided.', 'client-sync' ) ], 400 );
		}

		try {
			$site_timezone  = wp_timezone();
			$utc_timezone   = new \DateTimeZone( 'UTC' );
			$start_dt_local = new \DateTime( $selected_date_str . ' 00:00:00', $site_timezone );
			$end_dt_local   = new \DateTime( $selected_date_str . ' 23:59:59', $site_timezone );
			$start_utc_str  = $start_dt_local->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );
			$end_utc_str    = $end_dt_local->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => 'Error processing dates.' ], 500 );
			return;
		}

		$current_slot_utc_str = $post_id ? get_post_meta( $post_id, Constants::META_TIME_SLOT, true ) : null;
		$all_available_slots  = $this->db->get_slots_for_date_range( $start_utc_str, $end_utc_str, [], false );
		$booked_appointments  = $this->db->get_booked_appointments_for_date_range( $start_utc_str, $end_utc_str );

		// Build per-combination booking counts for accurate capacity tracking.
		$booked_combo_counts = [];
		foreach ( $booked_appointments as $appt ) {
			$appt_dims = get_post_meta( $appt['appointment_id'], Constants::META_SLOT_DIMENSIONS, true );
			$combo_key = $this->_build_combo_key( $appt['start_time_utc'], $appt_dims );
			$booked_combo_counts[ $combo_key ] = ( $booked_combo_counts[ $combo_key ] ?? 0 ) + 1;
		}

		// Get dimension labels for display
		$custom_types = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
		$registry     = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );

		// Find primary dimension
		$primary_slug = null;
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_slug = $slug;
				break;
			}
		}

		// Pre-cache post titles for all dimension IDs in slots
		$all_dim_ids = [];
		foreach ( $all_available_slots as $slot ) {
			if ( ! empty( $slot['dimensions'] ) && is_array( $slot['dimensions'] ) ) {
				foreach ( $slot['dimensions'] as $dim_id ) {
					if ( is_numeric( $dim_id ) ) {
						$all_dim_ids[] = (int) $dim_id;
					}
				}
			}
		}

		$title_cache = [];
		if ( ! empty( $all_dim_ids ) ) {
			$all_dim_ids = array_unique( $all_dim_ids );
			$dim_posts = get_posts( [
				'post__in'       => $all_dim_ids,
				'post_type'      => 'any',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'post_status'    => 'publish',
			] );
			foreach ( $dim_posts as $p ) {
				$title_cache[ $p->ID ] = $p->post_title;
			}
		}

		$slots_for_js = [];
		foreach ( $all_available_slots as $slot ) {
			$slot_utc_str = $slot['start_time_utc'];

			// Per-combination capacity check: only skip if THIS specific
			// dimension combination has reached its capacity limit.
			$slot_combo_key = $this->_build_combo_key( $slot_utc_str, $slot['dimensions'] ?? [] );
			$combo_booked   = $booked_combo_counts[ $slot_combo_key ] ?? 0;

			if ( $combo_booked > 0 && $slot_utc_str !== $current_slot_utc_str ) {
				$combo_capacity = Capacity_Resolver::resolve( $slot['dimensions'] ?? [] );
				if ( $combo_booked >= $combo_capacity ) {
					continue;
				}
			}

			try {
				$start_dt = new \DateTime( $slot['start_time_utc'], $utc_timezone );
				$end_dt   = new \DateTime( $slot['end_time_utc'], $utc_timezone );
				$start_dt->setTimezone( $site_timezone );
				$end_dt->setTimezone( $site_timezone );
				$duration = round( ( $end_dt->getTimestamp() - $start_dt->getTimestamp() ) / 60 );

				// Build dimension info for this slot
				$dimensions      = $slot['dimensions'] ?? [];
				$dimension_labels = [];
				$dimension_data   = [];

				if ( is_array( $dimensions ) && ! empty( $dimensions ) ) {
					foreach ( $dimensions as $dim_slug => $dim_id ) {
						$dim_id = (int) $dim_id;
						if ( $dim_id > 0 ) {
							$dim_title = $title_cache[ $dim_id ] ?? get_the_title( $dim_id );
							$dim_label = $custom_types[ $dim_slug ]['singular'] ?? ucfirst( str_replace( [ 'clisyc_', '_' ], [ '', ' ' ], $dim_slug ) );

							$dimension_labels[] = $dim_title;
							$dimension_data[ $dim_slug ] = [
								'id'    => $dim_id,
								'title' => $dim_title,
								'label' => $dim_label,
							];
						}
					}
				}

				// Create a unique identifier that includes dimensions
				// This helps distinguish slots at the same time with different practitioners
				$unique_id = $slot_utc_str;
				if ( ! empty( $dimensions ) ) {
					$dim_suffix = implode( '-', array_values( array_map( 'absint', $dimensions ) ) );
					$unique_id .= '|' . $dim_suffix;
				}

				$slots_for_js[] = [
					'start'           => $start_dt->format( 'H:i' ),
					'end'             => $end_dt->format( 'H:i' ),
					'identifier'      => $slot_utc_str, // Keep original for booking
					'unique_id'       => $unique_id,    // For UI differentiation
					'duration'        => $duration,
					'dimensions'      => $dimension_data,
					'dimension_label' => implode( ' / ', $dimension_labels ), // For display
				];
			} catch ( \Exception $e ) {
				continue;
			}
		}

		// Sort slots by time, then by dimension label for consistent display
		usort( $slots_for_js, function( $a, $b ) {
			$time_cmp = strcmp( $a['start'], $b['start'] );
			if ( $time_cmp !== 0 ) {
				return $time_cmp;
			}
			return strcmp( $a['dimension_label'] ?? '', $b['dimension_label'] ?? '' );
		} );

		if ( empty( $slots_for_js ) ) {
			wp_send_json_success(
				[
					'slots'   => [],
					'message' => __( 'No available slots found for this date.', 'client-sync' ),
				]
			);
		}
		wp_send_json_success( [ 'slots' => $slots_for_js ] );
	}

	public function save_available_slots() {
		_deprecated_function( __METHOD__, '3.5.0', 'REST API endpoint /clisyc/v1/slots' );
		check_ajax_referer( 'clisyc_save_available_slots_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ], 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array processed and sanitized in loop.
		$raw_slots  = isset( $_POST['slots'] ) && is_array( $_POST['slots'] ) ? wp_unslash( $_POST['slots'] ) : [];

		$view_start = isset( $_POST['viewStart'] ) ? sanitize_text_field( wp_unslash( $_POST['viewStart'] ) ) : null;
		$view_end   = isset( $_POST['viewEnd'] ) ? sanitize_text_field( wp_unslash( $_POST['viewEnd'] ) ) : null;

		if ( ! $view_start || ! $view_end ) {
			wp_send_json_error( [ 'message' => __( 'Missing calendar view range.', 'client-sync' ) ], 400 );
		}

		$slots_to_insert = [];
		foreach ( $raw_slots as $slot ) {
			$is_block_slot = filter_var( $slot['is_block'] ?? false, FILTER_VALIDATE_BOOLEAN );

			$dimensions = $slot['dimensions'] ?? [];
			$sanitized_dims = [];
			if ( is_array( $dimensions ) ) {
				foreach ( $dimensions as $key => $value ) {
					// Only sanitize/add if the value is not empty
					if ( ! empty( $value ) ) {
						$sanitized_dims[ sanitize_key( $key ) ] = sanitize_text_field( $value );
					}
				}
			}
			if ( ! empty( $slot['service_id'] ) ) {
				$sanitized_dims[ Constants::POST_TYPE_SERVICE ] = absint( $slot['service_id'] );
			}

			// If the slot is a block, we forcibly discard any dimensions sent with it.
			if ( $is_block_slot ) {
				$sanitized_dims = [];
			}

			$slots_to_insert[] = [
				'start_time_utc' => sanitize_text_field( $slot['start'] ?? '' ),
				'end_time_utc'   => sanitize_text_field( $slot['end'] ?? '' ),
				'is_block'       => $is_block_slot,
				'dimensions'     => $sanitized_dims,
			];
		}

		$result = $this->db->replace_editable_slots_in_range( $slots_to_insert, $view_start, $view_end );

		if ( $result['errors'] > 0 ) {
			wp_send_json_error( [ 'message' => __( 'An error occurred and some slots could not be saved.', 'client-sync' ) ], 500 );
		}

		$message = sprintf(
			/* translators: 1: Number of slots saved/updated. 2: Number of old slots cleared. */
			__( 'Successfully saved %1$d slots. (Cleared %2$d old slots in this view).', 'client-sync' ),
			$result['inserted'],
			$result['deleted']
		);

		wp_send_json_success( [ 'message' => $message ] );
	}

	/**
	 * Build a unique key combining time + sorted dimensions.
	 *
	 * Used to track bookings per dimension combination rather than just per time.
	 * Keys are deterministic: dimensions are sorted by slug for consistent matching.
	 *
	 * @param string       $time_utc   UTC timestamp string (Y-m-d H:i:s).
	 * @param array|mixed  $dimensions Dimension slug => ID pairs, or null/non-array.
	 * @return string Combo key, e.g. "2026-08-15 10:00:00|clisyc_room:7,clisyc_service:42"
	 */
	private function _build_combo_key( string $time_utc, $dimensions ): string {
		$key = $time_utc;
		if ( is_array( $dimensions ) && ! empty( $dimensions ) ) {
			$dims = array_map( 'absint', $dimensions );
			ksort( $dims );
			$parts = [];
			foreach ( $dims as $k => $v ) {
				$parts[] = "{$k}:{$v}";
			}
			$key .= '|' . implode( ',', $parts );
		}
		return $key;
	}
}
