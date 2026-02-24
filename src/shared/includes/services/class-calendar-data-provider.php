<?php
/**
 * File: src/shared/includes/services/class-calendar-data-provider.php
 * Formats data for FullCalendar using the Repository and Service classes.
 * 
 * FIXES IN THIS VERSION:
 * 1. Removed Multi-Select override that forced hide_booked to true.
 * 2. Booked events use proper booked colors (backgroundColor/borderColor).
 * 3. Available slots use proper available colors with dark text.
 * 4. Booked slots are detected by appointment time/dimensions.
 */

namespace DependentMedia\ClientSync\Services;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Repositories\Slot_Repository;
use DependentMedia\ClientSync\Services\Availability_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CalendarDataProvider {

	private $repo;
	private $service;
	private $post_data_cache = [];
	private $color_settings_cache = null;

	public function __construct( Database_Manager $db_manager, ?Slot_Repository $repo = null, ?Availability_Service $service = null ) {
		$this->repo    = $repo ?? new Slot_Repository();
		$this->service = $service ?? new Availability_Service();
	}

	public function get_calendar_data( array $fetch_info, bool $is_admin_editable_context = false, string $view_context = 'default', ?int $user_id = null, bool $hide_booked = false, string $overview_mode = 'none', array $dimensions = [] ): array {

		$env = $this->_prepare_environment();
		$start_utc = $fetch_info['start']->format( 'Y-m-d H:i:s' );
		$end_utc   = $fetch_info['end']->format( 'Y-m-d H:i:s' );

		// 1. Handle User-Specific View (My Appointments)
		if ( 'user_only' === $view_context && $user_id ) {
			$booked_ids = $this->repo->get_booked_appointment_ids( $start_utc, $end_utc, $user_id );
			$events = $this->_format_booked_events( $booked_ids, $env );
			return [ 'events' => $events, 'timeZone' => $env['site_timezone_string'] ];
		}

		// 2. [REMOVED] Multi-select override logic. 
		// We no longer force hide_booked to true here so that the toggle/shortcode parameter is respected.

		// 3. Get Primary Dimension
		$primary_dim_slug = $this->_get_primary_dimension_slug();
		if ( ! $primary_dim_slug ) {
			return [];
		}

		// 4. Get Available & Blocked Slots (via Service)
		$slots = $this->service->get_processed_slots( $start_utc, $end_utc, $dimensions, $primary_dim_slug );
		
		// Cache post titles/colors for performance
		$this->_populate_post_data_cache( $slots );

		// 5. Get booked appointment times to filter out from available slots
		$booked_times = $this->_get_booked_times( $start_utc, $end_utc );

		// 6. Format Available/Blocked Events
		$events = [];
		foreach ( $slots as $slot ) {
			// Check if this slot time/dimension combo is already booked
			$slot_key = $this->_make_slot_key( $slot );
			$is_fully_booked = isset( $booked_times[ $slot_key ] ) && $booked_times[ $slot_key ]['count'] >= ( $slot['capacity'] ?? 1 );
			
			// ALSO check if Availability_Service marked this as unavailable (spots_left=0)
			$has_no_spots = isset( $slot['spots_left'] ) && $slot['spots_left'] <= 0;
			
			// Skip fully-booked slots OR slots with resource conflicts
			if ( empty( $slot['is_block'] ) && ( $is_fully_booked || $has_no_spots ) ) {
				continue;
			}
			
			// Adjust spots_left based on actual bookings (if not already set by Availability_Service)
			if ( isset( $booked_times[ $slot_key ] ) && ! isset( $slot['spots_left'] ) ) {
				$slot['spots_left'] = max( 0, ( $slot['capacity'] ?? 1 ) - $booked_times[ $slot_key ]['count'] );
			}
			
			$events[] = $this->_format_slot_event( $slot, $env, $primary_dim_slug, $is_admin_editable_context, $overview_mode );
		}

		// 7. Merge Booked Appointments (if needed)
		if ( ! $hide_booked ) {
			$booked_ids = $this->repo->get_booked_appointment_ids( $start_utc, $end_utc );
			$booked_events = $this->_format_booked_events( $booked_ids, $env, $slots );
			$events = array_merge( $events, $booked_events );
		}

		// 8. Add Context Data
		$primary_dim_id = isset( $dimensions[ $primary_dim_slug ] ) ? ( is_array( $dimensions[ $primary_dim_slug ] ) ? $dimensions[ $primary_dim_slug ][0] : $dimensions[ $primary_dim_slug ] ) : 0;
		
		return [
			'events' => $events,
			'timeZone' => $env['site_timezone_string'],
			'selectedServiceDetails' => $this->_get_post_attributes( $primary_dim_id ),
		];
	}

	// --- Helpers ---

	private function _prepare_environment(): array {
		$site_tz = wp_timezone();
		$utc_tz  = new \DateTimeZone( 'UTC' );
		$min_notice = (int) get_option( Constants::OPTION_MIN_BOOKING_NOTICE, 60 );
		
		return [
			'site_timezone_object' => $site_tz,
			'site_timezone_string' => $site_tz->getName(),
			'utc_timezone_object'  => $utc_tz,
			'cutoff_time'          => ( new \DateTime( 'now', $site_tz ) )->modify( "+{$min_notice} minutes" ),
		];
	}

	/**
	 * Get booked times from appointments by their time_slot meta and dimensions.
	 *
	 * Uses a single SQL query with JOINs instead of get_posts() + per-post
	 * get_post_meta() calls, avoiding the N+1 query overhead for large calendars.
	 */
	private function _get_booked_times( string $start_utc, string $end_utc ): array {
		global $wpdb;

		$statuses = [ 'publish', Constants::STATUS_CONFIRMED, Constants::STATUS_PAID_ON_DAY, 'wc-completed', 'wc-processing' ];
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// Single query: JOIN time_slot meta (required) and dimensions meta (optional).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, ts.meta_value AS time_slot, dm.meta_value AS dimensions
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} ts ON p.ID = ts.post_id AND ts.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} dm ON p.ID = dm.post_id AND dm.meta_key = %s
				WHERE p.post_type = %s
				  AND p.post_status IN ({$placeholders})
				  AND ts.meta_value >= %s
				  AND ts.meta_value <= %s",
				array_merge(
					[ Constants::META_TIME_SLOT, Constants::META_SLOT_DIMENSIONS, Constants::POST_TYPE_APPOINTMENT ],
					$statuses,
					[ $start_utc, $end_utc ]
				)
			)
		);

		$booked_times = [];

		foreach ( $rows as $row ) {
			if ( empty( $row->time_slot ) ) {
				continue;
			}

			$key = $row->time_slot;
			$dimensions = maybe_unserialize( $row->dimensions );
			if ( is_array( $dimensions ) ) {
				$dims = array_map( 'absint', $dimensions );
				ksort( $dims );
				$key .= '|' . implode( ',', array_map( fn( $k, $v ) => "{$k}:{$v}", array_keys( $dims ), $dims ) );
			}

			if ( ! isset( $booked_times[ $key ] ) ) {
				$booked_times[ $key ] = [ 'count' => 0, 'appointment_ids' => [] ];
			}
			$booked_times[ $key ]['count']++;
			$booked_times[ $key ]['appointment_ids'][] = (int) $row->ID;
		}

		return $booked_times;
	}

	private function _make_slot_key( array $slot ): string {
		$key = $slot['start_time_utc'];
		if ( ! empty( $slot['dimensions'] ) && is_array( $slot['dimensions'] ) ) {
			$dims = array_map( 'absint', $slot['dimensions'] );
			ksort( $dims );
			$key .= '|' . implode( ',', array_map( fn( $k, $v ) => "{$k}:{$v}", array_keys( $dims ), $dims ) );
		}
		return $key;
	}

	private function _get_color_settings(): array {
		if ( null !== $this->color_settings_cache ) {
			return $this->color_settings_cache;
		}
		$saved = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
		$this->color_settings_cache = wp_parse_args( $saved, Constants::COLOR_DEFAULTS );
		return $this->color_settings_cache;
	}

	private function _format_slot_event( array $slot, array $env, string $primary_slug, bool $is_admin, string $overview_mode ): array {
		$start_dt = new \DateTime( $slot['start_time_utc'], $env['utc_timezone_object'] );
		$end_dt   = new \DateTime( $slot['end_time_utc'], $env['utc_timezone_object'] );
		$start_local = ( clone $start_dt )->setTimezone( $env['site_timezone_object'] );
		
		$is_past = ( $start_local < $env['cutoff_time'] );
		$is_block = ! empty( $slot['is_block'] );

		$colors = $this->_get_color_settings();

		$details = [];
		$primary_id = (int) ( $slot['dimensions'][ $primary_slug ] ?? 0 );

		foreach ( $slot['dimensions'] ?? [] as $key => $id ) {
			$id = (int) $id; // FIX: Ensure integer for consistent cache lookup
			if ( isset( $this->post_data_cache[ $id ] ) ) {
				$clean_key = str_replace( 'clisyc_', '', $key );
				$details[ $clean_key . '_id' ] = $id; // FIX: Include ID for frontend filtering
				$details[ $clean_key . '_name' ] = $this->post_data_cache[ $id ]['title'];
				$details[ $clean_key . '_color' ] = $this->post_data_cache[ $id ]['color'];
			}
		}

		$membership = $this->service->check_membership_access( get_current_user_id(), $primary_id );
		
		if ( $primary_id && isset( $this->post_data_cache[ $primary_id ] ) ) {
			$base_price = (float) get_post_meta( $primary_id, Constants::META_PRICE, true );
			$details['price'] = $this->service->apply_membership_pricing( get_current_user_id(), $primary_id, $base_price );
			$details['duration'] = ( $end_dt->getTimestamp() - $start_dt->getTimestamp() ) / 60;
			$details['title'] = $this->post_data_cache[ $primary_id ]['title'];
		}

		if ( $is_block ) {
			$bg_color = $colors['blocked_bg'];
			$text_color = $colors['blocked_text'];
		} else {
			$bg_color = $colors['available_bg'];
			$text_color = $colors['available_text'];
		}

		return [
			'start' => $start_dt->format( \DateTime::RFC3339 ),
			'end'   => $end_dt->format( \DateTime::RFC3339 ),
			'title' => $is_block ? __( 'Blocked', 'client-sync' ) : ( $details['title'] ?? __( 'Available', 'client-sync' ) ),
			'editable' => $is_admin,
			'classNames' => [ $is_block ? 'clisyc-blocked-slot' : 'clisyc-available-slot' ],
			'backgroundColor' => $bg_color,
			'borderColor' => $bg_color,
			'textColor' => $text_color,
			'extendedProps' => [
				'isBooked' => false,
				'is_block' => $is_block,
				'isPastOrTooSoon' => $is_past,
				'isBookable' => ! $is_past && ! $is_block,
				'isBookableForUser' => $membership['can_book'],
				'bookingRestrictionMessage' => $membership['message'],
				'serviceDetails' => $details,
				'slotIdentifier' => $start_dt->format( 'Y-m-d H:i:s' ),
				'durationMinutes' => $details['duration'] ?? 0,
				'capacity' => $slot['capacity'] ?? 1,
				'spotsLeft' => $slot['spots_left'] ?? 0,
				'dimensions' => $slot['dimensions'] ?? [],
				'slot_id' => (int) ( $slot['slot_id'] ?? 0 ),
			]
		];
	}

	private function _format_booked_events( array $ids, array $env, array $slots = [] ): array {
		$events = [];
		if ( empty( $ids ) ) return [];

		$posts = get_posts( [ 'post_type' => Constants::POST_TYPE_APPOINTMENT, 'post__in' => $ids, 'posts_per_page' => -1 ] );

		// PERFORMANCE FIX: Prime meta cache for all booked appointment posts
		// to prevent N+1 get_post_meta() queries (5 per post) in the loop below.
		if ( $posts ) {
			update_postmeta_cache( wp_list_pluck( $posts, 'ID' ) );
		}

		$details_page_id = (int) get_option( Constants::OPTION_APPOINTMENT_VIEW_PAGE, 0 );
		$colors = $this->_get_color_settings();

		foreach ( $posts as $post ) {
			$booking_mode = get_post_meta( $post->ID, Constants::META_BOOKING_MODE, true ) ?: 'slot';
			
			$start_iso = '';
			$end_iso   = '';
			$all_day   = false;
			$duration  = 0;

			if ( 'date_range' === $booking_mode ) {
				$start_date = get_post_meta( $post->ID, Constants::META_START_DATE, true );
				$end_date   = get_post_meta( $post->ID, Constants::META_END_DATE, true );
				
				if ( ! $start_date || ! $end_date ) continue;

				$dt_end = new \DateTime( $end_date );
				$dt_end->modify( '+1 day' );

				$start_iso = $start_date;
				$end_iso   = $dt_end->format('Y-m-d'); 
				$all_day   = true;
				$duration = 1440; 
			} else {
				$utc_slot = get_post_meta( $post->ID, Constants::META_TIME_SLOT, true );
				if ( ! $utc_slot ) continue;

				$duration = (int) get_post_meta( $post->ID, Constants::META_APPOINTMENT_DURATION, true );
				if ( $duration <= 0 ) $duration = 60;

				$start = new \DateTime( $utc_slot, $env['utc_timezone_object'] );
				$end = ( clone $start )->modify( "+{$duration} minutes" );
				
				$start_iso = $start->format( \DateTime::RFC3339 );
				$end_iso   = $end->format( \DateTime::RFC3339 );
				$all_day   = false;
			}

			$service_details = [];
			$appt_dimensions = get_post_meta( $post->ID, Constants::META_SLOT_DIMENSIONS, true );
			if ( is_array( $appt_dimensions ) ) {
				foreach ( $appt_dimensions as $key => $id ) {
					$id = (int) $id; // FIX: Ensure integer for consistent cache lookup
					if ( isset( $this->post_data_cache[ $id ] ) ) {
						$clean_key = str_replace( 'clisyc_', '', $key );
						$service_details[ $clean_key . '_name' ] = $this->post_data_cache[ $id ]['title'];
						$service_details[ $clean_key . '_color' ] = $this->post_data_cache[ $id ]['color'];
					}
				}
			}
			
			$title = ! empty( $service_details['service_name'] ) ? $service_details['service_name'] : __( 'Booked', 'client-sync' );
			$view_url = $details_page_id ? add_query_arg( 'view_id', $post->ID, get_permalink( $details_page_id ) ) : '';
			
			$events[] = [
				'start' => $start_iso, 
				'end'   => $end_iso,
				'title' => $title,
				'allDay' => $all_day,
				'classNames' => [ 'clisyc-booked-event' ],
				'editable' => false,
				'backgroundColor' => $colors['booked_bg'],
				'borderColor' => $colors['booked_bg'],
				'textColor' => $colors['booked_text'],
				'extendedProps' => [
					'isBooked' => true,
					'appointmentId' => $post->ID,
					'bookingMode' => $booking_mode,
					'serviceDetails' => $service_details,
					'durationMinutes' => $duration,
					'viewUrl' => $view_url,
				]
			];
		}
		return $events;
	}

	private function _populate_post_data_cache( array $slots ) {
		$ids = [];
		foreach ( $slots as $slot ) {
			foreach ( $slot['dimensions'] ?? [] as $id ) {
				$ids[] = (int) $id; // FIX: Cast to int for consistent keys
			}
		}
		if ( empty( $ids ) ) return;
		
		$posts = get_posts( [
			'post_type' => 'any',
			'post__in' => array_unique( $ids ),
			'posts_per_page' => -1
		] );

		foreach ( $posts as $p ) {
			$this->post_data_cache[ (int) $p->ID ] = [ // FIX: Ensure integer key
				'title' => $p->post_title,
				'color' => get_post_meta( $p->ID, Constants::META_COLOR, true ),
			];
		}
	}

	private function _get_primary_dimension_slug(): ?string {
		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) return $slug;
		}
		return null;
	}

	private function _get_post_attributes( int $post_id ): array {
		if ( ! $post_id ) return [];
		$post = get_post( $post_id );
		if ( ! $post ) return [];

		return [
			'id' => $post_id,
			'title' => $post->post_title,
			'description' => $post->post_content,
			'price' => get_post_meta( $post_id, Constants::META_PRICE, true ),
		];
	}
}