<?php
/**
 * File: src/shared/includes/ajax/class-appointments-ajax-handler.php
 *
 * Handles appointment-listing AJAX actions: appointment cards, manager list,
 * status updates, and user appointment table data.
 *
 * @package ClientSync
 */

namespace DependentMedia\ClientSync\Ajax;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Cancellation_Manager;
use DependentMedia\ClientSync\Services\Staff_Resolver;
use DependentMedia\ClientSync\Traits\Datetime_Formatting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Appointments_Ajax_Handler {

	use Datetime_Formatting;

	public function register_hooks() {
		add_action( 'wp_ajax_clisyc_get_appointments_cards', [ $this, 'get_appointments_cards' ] );
		add_action( 'wp_ajax_clisyc_update_appointment_status', [ $this, 'ajax_update_appointment_status' ] );
		add_action( 'wp_ajax_clisyc_get_manager_appointments_list', [ $this, 'ajax_get_manager_appointments_list' ] );
		add_action( 'wp_ajax_clisyc_get_user_appointments', [ $this, 'get_user_appointments' ] );
		add_action( 'wp_ajax_clisyc_staff_update_notes', [ $this, 'ajax_staff_update_notes' ] );
		add_action( 'wp_ajax_clisyc_staff_update_status', [ $this, 'ajax_staff_update_appointment_status' ] );
	}

	/**
	 * IMPROVED get_appointments_cards AJAX handler
	 */
	public function get_appointments_cards() {
		// 1. Verify nonce
		if ( ! check_ajax_referer( 'clisyc_appointments_cards_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed.' ] );
		}

		// 2. Check login
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'You must be logged in.' ] );
		}

		// 3. Get and sanitize parameters
		$user_id  = get_current_user_id();
		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$status   = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'upcoming';
		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page = 9;

		// 4. Build WP_Query args - Start with broad query
		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => 'any', // Start broad, filter later
			'author'         => $user_id,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => ( 'past' === $status ) ? 'DESC' : 'ASC',
		];

		// 5. Add search
		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		// 6. Build status-specific query
		$current_time_utc = current_time( 'mysql', 1 ); // UTC
		$current_date     = current_time( 'Y-m-d' );

		// Allowed post statuses (exclude trash unless specifically looking for "all")
		$allowed_statuses = [ 'publish', 'confirmed', 'clisyc_paid_on_day', 'wc-completed', 'wc-processing', 'pending', 'draft', 'clisyc_waitlisted' ];

		if ( 'upcoming' === $status ) {
			// For upcoming: Include appointments where time slot >= now OR end_date >= today
			// Use a more permissive approach - include appointments that MIGHT be upcoming
			$args['post_status'] = $allowed_statuses;

			// IMPROVED: More flexible meta query
			// Include appointments that have a time slot (we'll filter by date in PHP for more flexibility)
			// OR have an end_date (for date ranges)
			$args['meta_query'] = [
				'relation' => 'OR',
				[
					'key'     => Constants::META_TIME_SLOT,
					'compare' => 'EXISTS',
				],
				[
					'key'     => Constants::META_END_DATE,
					'compare' => 'EXISTS',
				],
				[
					'key'     => Constants::META_START_DATE,
					'compare' => 'EXISTS',
				],
			];

		} elseif ( 'past' === $status ) {
			$args['post_status'] = array_merge( $allowed_statuses, [ 'trash', 'cancelled' ] );

			// Same approach - get all with relevant meta, filter in PHP
			$args['meta_query'] = [
				'relation' => 'OR',
				[
					'key'     => Constants::META_TIME_SLOT,
					'compare' => 'EXISTS',
				],
				[
					'key'     => Constants::META_END_DATE,
					'compare' => 'EXISTS',
				],
				[
					'key'     => Constants::META_START_DATE,
					'compare' => 'EXISTS',
				],
			];

		} else {
			// "all" - include everything
			$args['post_status'] = array_merge( $allowed_statuses, [ 'trash', 'cancelled' ] );

			// Don't require any specific meta for "all"
			// But still filter for appointments that have SOME date info
			$args['meta_query'] = [
				'relation' => 'OR',
				[ 'key' => Constants::META_TIME_SLOT, 'compare' => 'EXISTS' ],
				[ 'key' => Constants::META_START_DATE, 'compare' => 'EXISTS' ],
				[ 'key' => Constants::META_END_DATE, 'compare' => 'EXISTS' ],
				[ 'key' => Constants::META_APPOINTMENT_DATE, 'compare' => 'EXISTS' ],
			];
		}

		// 7. Execute query
		$query = new \WP_Query( $args );
		$appointments = [];
		$skipped = [];

		// 8. Build appointments array
		if ( $query->have_posts() ) {
			$cancellation_manager_class = 'DependentMedia\ClientSync\Core\Cancellation_Manager';
			$details_page_id = (int) get_option( Constants::OPTION_APPOINTMENT_VIEW_PAGE, 0 );
			$current_timestamp = time();

			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();

				// Get booking mode and datetime info
				$booking_mode = get_post_meta( $post_id, Constants::META_BOOKING_MODE, true ) ?: 'slot';
				$display_date = '';
				$display_time = '';
				$timestamp    = 0;
				$is_upcoming  = false;

				if ( 'date_range' === $booking_mode ) {
					// Handle Date Range Display
					$start_date = get_post_meta( $post_id, Constants::META_START_DATE, true );
					$end_date   = get_post_meta( $post_id, Constants::META_END_DATE, true );

					if ( $start_date && $end_date ) {
						$display_date = wp_date( get_option( 'date_format' ), strtotime( $start_date ) ) . ' - ' . wp_date( get_option( 'date_format' ), strtotime( $end_date ) );
						$display_time = __( 'All Day', 'client-sync' );
						$timestamp    = strtotime( $start_date );

						// Check if upcoming based on end date
						$is_upcoming = strtotime( $end_date ) >= strtotime( $current_date );
					} elseif ( $start_date ) {
						$display_date = wp_date( get_option( 'date_format' ), strtotime( $start_date ) );
						$display_time = __( 'All Day', 'client-sync' );
						$timestamp    = strtotime( $start_date );
						$is_upcoming  = $timestamp >= strtotime( $current_date );
					}
				} else {
					// Handle Time Slot Display
					$time_slot_raw = get_post_meta( $post_id, Constants::META_TIME_SLOT, true );

					if ( $time_slot_raw ) {
						// Try multiple parsing approaches
						$parsed_timestamp = $this->parse_datetime_to_timestamp( $time_slot_raw );

						if ( $parsed_timestamp ) {
							$timestamp = $parsed_timestamp;
							$display_date = wp_date( get_option( 'date_format' ), $timestamp );
							$display_time = wp_date( get_option( 'time_format' ), $timestamp );
							$is_upcoming = $timestamp >= $current_timestamp;
						} else {
							// Fallback: try to display raw value
							$display_date = $time_slot_raw;
							$display_time = '';
							$timestamp = 1; // Set a dummy timestamp so we don't skip
							$is_upcoming = true; // Assume upcoming if we can't parse
						}
					} else {
						// Try fallback to appointment_date meta
						$appt_date = get_post_meta( $post_id, Constants::META_APPOINTMENT_DATE, true );
						if ( $appt_date ) {
							$timestamp = strtotime( $appt_date );
							$display_date = wp_date( get_option( 'date_format' ), $timestamp );
							$display_time = __( 'Time not set', 'client-sync' );
							$is_upcoming = $timestamp >= strtotime( $current_date );
						}
					}
				}

				// Apply upcoming/past filter in PHP for more flexibility
				if ( 'upcoming' === $status && ! $is_upcoming ) {
					$skipped[] = [ 'id' => $post_id, 'reason' => 'not_upcoming' ];
					continue;
				}
				if ( 'past' === $status && $is_upcoming ) {
					$skipped[] = [ 'id' => $post_id, 'reason' => 'not_past' ];
					continue;
				}

				// CHANGED: Don't skip appointments without timestamps - show them with "Date not set"
				if ( ! $timestamp && 'all' !== $status ) {
					// For upcoming/past, we need some date to filter by
					// But for debugging, let's include them anyway in the results
					$display_date = $display_date ?: __( 'Date not set', 'client-sync' );
					$display_time = $display_time ?: __( 'Time not set', 'client-sync' );
				}

				// Cancellation check
				$can_cancel = false;
				if ( class_exists( $cancellation_manager_class ) ) {
					$cancellation_manager = new $cancellation_manager_class( $post_id );
					$can_cancel = $cancellation_manager->can_be_managed();
				}

				// Build dimension details for the card
				$dim_details = [];
				$slot_dims   = get_post_meta( $post_id, Constants::META_SLOT_DIMENSIONS, true );
				if ( is_array( $slot_dims ) && ! empty( $slot_dims ) ) {
					$dimension_types = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
					foreach ( $slot_dims as $dim_slug => $dim_id ) {
						$dim_title = get_the_title( $dim_id );
						if ( ! $dim_title ) {
							continue;
						}
						$dim_type  = $dimension_types[ $dim_slug ] ?? [];
						$label     = $dim_type['singular'] ?? ucfirst( str_replace( [ 'clisyc_', '_' ], [ '', ' ' ], $dim_slug ) );
						$raw_icon  = $dim_type['icon'] ?? 'dashicons-admin-generic';

						if ( 0 === strpos( $raw_icon, 'dashicons-' ) ) {
							$icon_classes = 'dashicons ' . $raw_icon;
						} else {
							$icon_classes = $raw_icon;
						}

						$dim_details[] = [
							'label'        => $label,
							'value'        => $dim_title,
							'icon_classes'  => $icon_classes,
						];
					}
				}

				$appointments[] = [
					'id'         => $post_id,
					'title'      => html_entity_decode( get_the_title() ?: __( 'Untitled Appointment', 'client-sync' ), ENT_QUOTES, 'UTF-8' ),
					'date'       => $display_date ?: __( 'Date not set', 'client-sync' ),
					'time'       => $display_time ?: __( 'Time not set', 'client-sync' ),
					'status'     => get_post_status(),
					'dimensions' => $dim_details,
					'view_url'   => $details_page_id ? esc_url( add_query_arg( 'view_id', $post_id, get_permalink( $details_page_id ) ) ) : '#',
					'can_cancel' => $can_cancel,
					'cancel_url' => $can_cancel ? esc_url( wp_nonce_url( add_query_arg( [ 'clisyc_self_service_action' => 'cancel', 'appointment_id' => $post_id ], get_permalink() ), 'clisyc_cancel_appt_' . $post_id ) ) : '#',
				];
			}
			wp_reset_postdata();
		}

		// 9. Build pagination
		$pagination = paginate_links(
			[
				'base'      => '#%#%',
				'format'    => '',
				'current'   => $page,
				'total'     => $query->max_num_pages,
				'prev_text' => '‹',
				'next_text' => '›',
				'type'      => 'plain',
			]
		);

		$response_data = [
			'appointments' => $appointments,
			'pagination'   => $pagination ?: '',
		];

		wp_send_json_success( $response_data );
	}

	/**
	 * AJAX handler for manager quick actions to update appointment status.
	 */
	public function ajax_update_appointment_status() {
		check_ajax_referer( 'clisyc_update_appointment_status_nonce', 'security' );

		$appointment_id = isset( $_POST['appointment_id'] ) ? absint( $_POST['appointment_id'] ) : 0;
		$new_status_key = isset( $_POST['new_status'] ) ? sanitize_key( $_POST['new_status'] ) : '';

		if ( ! $appointment_id || ! $new_status_key ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'client-sync' ) ] );
		}

		if ( ! current_user_can( 'edit_post', $appointment_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to modify this appointment.', 'client-sync' ) ] );
		}

		$allowed_statuses = [
			'checked_in' => Constants::STATUS_CHECKED_IN,
			'completed'  => 'draft',   // Using 'draft' as a proxy for completed
			'no_show'    => 'trash',
		];

		if ( ! array_key_exists( $new_status_key, $allowed_statuses ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid status action.', 'client-sync' ) ] );
		}

		$target_status = $allowed_statuses[ $new_status_key ];
		$result        = false;

		if ( 'trash' === $target_status ) {
			$result = wp_trash_post( $appointment_id );
		} else {
			$update_result = wp_update_post(
				[
					'ID'          => $appointment_id,
					'post_status' => $target_status,
				]
			);
			$result        = ! is_wp_error( $update_result );
		}

		if ( $result ) {
			$status_obj       = get_post_status_object( $target_status );
			$new_status_label = $status_obj ? $status_obj->label : $target_status;
			wp_send_json_success(
				[
					'message'        => __( 'Appointment status updated.', 'client-sync' ),
					'newStatusLabel' => $new_status_label,
				]
			);
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to update appointment status.', 'client-sync' ) ] );
		}
	}

	public function ajax_get_manager_appointments_list() {
		check_ajax_referer( 'clisyc_get_manager_appointments_list_nonce', 'security' );

		// ── Staff Dashboard mode ──────────────────────────────────────────
		// When staff_id is provided, this is a staff dashboard request.
		// Only require 'read' capability, but verify ownership server-side.
		$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;

		if ( $staff_id ) {
			// Verify the requesting user actually owns this staff post.
			if ( ! Staff_Resolver::verify_ownership( $staff_id, get_current_user_id() ) ) {
				wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
			}
		} else {
			// Standard manager mode — requires edit_others_posts.
			if ( ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
				wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
			}
		}

		$paged      = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$status     = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'upcoming';
		$service_id = isset( $_POST['service'] ) ? sanitize_text_field( wp_unslash( $_POST['service'] ) ) : 'all';

		// Check if we are sorting by time slot; if so, use the correct underscored constant
		$posted_orderby = isset( $_POST['orderby'] ) ? sanitize_key( wp_unslash( $_POST['orderby'] ) ) : 'clisyc_time_slot';
		$orderby        = ( 'clisyc_time_slot' === $posted_orderby ) ? Constants::META_TIME_SLOT : $posted_orderby;

		// Unslash and sanitize sort order
		$order_raw  = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : 'ASC';
		$order      = in_array( strtoupper( $order_raw ), [ 'ASC', 'DESC' ], true ) ? strtoupper( $order_raw ) : 'ASC';

		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'posts_per_page' => 20,
			'paged'          => $paged,
			'orderby'        => 'meta_value',
			'meta_key'       => $orderby,
			'order'          => $order,
		];

		$meta_query = [ 'relation' => 'AND' ];
		$now_utc    = current_time( 'mysql', 1 );

		if ( 'today' === $status ) {
			// ── "Today" filter — appointments whose time slot falls on today (site tz) ──
			$site_tz    = wp_timezone();
			$today_start = ( new \DateTime( 'today', $site_tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$today_end   = ( new \DateTime( 'tomorrow', $site_tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$meta_query[] = [
				'key'     => Constants::META_TIME_SLOT,
				'value'   => [ $today_start, $today_end ],
				'compare' => 'BETWEEN',
				'type'    => 'DATETIME',
			];
			$args['post_status'] = [ 'publish', 'confirmed', Constants::STATUS_PENDING_PAYMENT ];
		} elseif ( 'upcoming' === $status ) {
			$meta_query[]        = [
				'key'     => Constants::META_TIME_SLOT,
				'value'   => $now_utc,
				'compare' => '>=',
				'type'    => 'DATETIME',
			];
			$args['post_status'] = [ 'publish', 'confirmed', Constants::STATUS_PENDING_PAYMENT ];
		} elseif ( 'past' === $status ) {
			$meta_query[] = [
				'key'     => Constants::META_TIME_SLOT,
				'value'   => $now_utc,
				'compare' => '<',
				'type'    => 'DATETIME',
			];
		}

		// ── Staff-scoped dimension filter ─────────────────────────────────
		if ( $staff_id ) {
			$personnel_slug = get_option( Constants::OPTION_PERSONNEL_DIM_SLUG, '' );
			if ( $personnel_slug ) {
				global $wpdb;
				// Serialized array stores e.g. s:11:"clisyc_staff";i:42;
				$meta_query[] = [
					'key'     => 'clisyc_slot_dimensions',
					'value'   => $wpdb->esc_like( '"' . $personnel_slug . '";i:' . $staff_id ),
					'compare' => 'LIKE',
				];
			}
		}

		if ( 'all' !== $service_id ) {
			global $wpdb;
			$meta_query[] = [
				'key'     => 'clisyc_slot_dimensions',
				'value'   => '"' . $wpdb->esc_like( $service_id ) . '"',
				'compare' => 'LIKE',
			];
		}

		if ( count( $meta_query ) > 1 ) {
			$args['meta_query'] = $meta_query;
		}

		if ( ! empty( $search ) ) {
			$user_query = new \WP_User_Query(
				[
					'search'         => '*' . esc_attr( $search ) . '*',
					'search_columns' => [ 'display_name' ],
					'fields'         => 'ID',
				]
			);
			$user_ids   = $user_query->get_results();
			if ( ! empty( $user_ids ) ) {
				$args['author__in'] = $user_ids;
			} else {
				$args['author__in'] = [ 0 ]; // No users found, so return no appointments
			}
		}

		$query        = new \WP_Query( $args );
		$appointments = [];

		// Get the manager edit page URL (find page with [clisyc_manager_edit_appointment] shortcode)
		$manager_edit_page_url = $this->get_manager_edit_appointment_page_url();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id    = get_the_ID();
				$client     = get_user_by( 'id', get_post_field( 'post_author' ) );
				$time_info  = $this->_get_formatted_datetime_from_slot_id( get_post_meta( $post_id, Constants::META_TIME_SLOT, true ) );
				$status_obj = get_post_status_object( get_post_status() );

				$service     = '';
				$registry    = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
				$primary_key = null;
				foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
					if ( ! empty( $settings['primary'] ) ) {
						$primary_key = $slug;
						break;
					}
				}
				if ( $primary_key ) {
					$dimensions = get_post_meta( $post_id, 'clisyc_slot_dimensions', true );
					$service_id = is_array( $dimensions ) && isset( $dimensions[ $primary_key ] ) ? $dimensions[ $primary_key ] : 0;
					if ( $service_id ) {
						$service = get_the_title( $service_id );
					}
				}

				// Build the edit details URL - use manager edit page if available, otherwise admin edit
				$edit_details_url = null;
				if ( current_user_can( 'edit_post', $post_id ) ) {
					if ( $manager_edit_page_url ) {
						$edit_details_url = add_query_arg( 'appointment_id', $post_id, $manager_edit_page_url );
					} else {
						// Fallback to admin edit link
						$edit_details_url = get_edit_post_link( $post_id, 'raw' );
					}
				}

				$appointment_data = [
					'clientName' => $client ? $client->display_name : __( 'N/A', 'client-sync' ),
					'service'    => $service,
					'date'       => $time_info['date'],
					'time'       => $time_info['time'],
					'status'     => $status_obj ? $status_obj->label : get_post_status(),
					'id'         => $post_id,
					'actions'    => [
						'editDetails' => $edit_details_url,
						'editNotes'   => $edit_details_url,
						'editAdmin'   => current_user_can( 'edit_post', $post_id ) ? get_edit_post_link( $post_id, 'raw' ) : null,
					],
				];

				// Include notes for staff dashboard requests.
				if ( $staff_id ) {
					$post_obj = get_post( $post_id );
					$appointment_data['notes'] = $post_obj ? wp_kses_post( $post_obj->post_content ) : '';
				}

				$appointments[] = $appointment_data;
			}
		}
		wp_reset_postdata();

		$pagination_links = paginate_links(
			[
				'base'      => '%_%',
				'format'    => '?paged=%#%',
				'current'   => $paged,
				'total'     => $query->max_num_pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'type'      => 'plain',
			]
		);

		wp_send_json_success(
			[
				'appointments' => $appointments,
				'pagination'   => [ 'links' => $pagination_links ],
				'queryArgs'    => wp_json_encode( $query->query_vars ),
			]
		);
	}

	public function get_user_appointments() {
		check_ajax_referer( 'clisyc_get_user_appointments_nonce', 'security' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'User not logged in' ], 403 );
		}

		$page          = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;
		$status_filter = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'all';
		$search_query  = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$per_page      = isset( $_POST['per_page'] ) ? absint( wp_unslash( $_POST['per_page'] ) ) : 10;

		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'author'         => get_current_user_id(),
			'orderby'        => 'date', // Fallback to creation date
			'order'          => 'DESC',
		];

		if ( ! empty( $search_query ) ) {
			$args['s'] = $search_query;
		}

		// Update Meta Query to support both time slots and date ranges
		$meta_query  = [ 'relation' => 'OR' ];
		$now_utc_str = current_time( 'mysql', 1 );
		$current_date = current_time( 'Y-m-d' );

		if ( 'upcoming' === $status_filter ) {
			$meta_query[] = [
				'key'     => Constants::META_TIME_SLOT,
				'value'   => $now_utc_str,
				'compare' => '>=',
				'type'    => 'DATETIME',
			];
			$meta_query[] = [
				'key'     => Constants::META_END_DATE,
				'value'   => $current_date,
				'compare' => '>=',
				'type'    => 'DATE',
			];
			$upcoming_statuses = array_diff( get_post_stati(), [ 'trash', 'cancelled' ] );
			$upcoming_statuses[] = Constants::STATUS_WAITLISTED;
			$args['post_status'] = array_unique( $upcoming_statuses );
		} elseif ( 'past' === $status_filter ) {
			$meta_query[] = [
				'key'     => Constants::META_TIME_SLOT,
				'value'   => $now_utc_str,
				'compare' => '<',
				'type'    => 'DATETIME',
			];
			$meta_query[] = [
				'key'     => Constants::META_END_DATE,
				'value'   => $current_date,
				'compare' => '<',
				'type'    => 'DATE',
			];
			$args['post_status'] = array_diff( get_post_stati(), [ 'trash', 'cancelled' ] );
		} else {
			$args['post_status'] = 'any';
			$meta_query[] = [ 'key' => Constants::META_TIME_SLOT, 'compare' => 'EXISTS' ];
			$meta_query[] = [ 'key' => Constants::META_START_DATE, 'compare' => 'EXISTS' ];
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		$query = new \WP_Query( $args );
		$appointments = [];
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id           = get_the_ID();
				$appointment_title = get_the_title( $post_id );

				// Determine Display Data based on Mode
				$booking_mode = get_post_meta( $post_id, Constants::META_BOOKING_MODE, true ) ?: 'slot';
				$display_data = [
					'date'      => __( 'N/A', 'client-sync' ),
					'time'      => __( 'N/A', 'client-sync' ),
					'sort_date' => '',
					'sort_time' => '',
				];
				$found_valid_date = false;

				if ( 'date_range' === $booking_mode ) {
					$start_date = get_post_meta( $post_id, Constants::META_START_DATE, true );
					$end_date   = get_post_meta( $post_id, Constants::META_END_DATE, true );
					if ( $start_date && $end_date ) {
						$display_data = [
							'date'      => wp_date( get_option( 'date_format' ), strtotime( $start_date ) ) . ' - ' . wp_date( get_option( 'date_format' ), strtotime( $end_date ) ),
							'time'      => __( 'All Day', 'client-sync' ),
							'sort_date' => $start_date,
							'sort_time' => '00:00',
						];
						$found_valid_date = true;
					}
				} else {
					$time_slot_id_utc = get_post_meta( $post_id, Constants::META_TIME_SLOT, true );
					if ( $time_slot_id_utc ) {
						$display_data     = $this->_get_formatted_datetime_from_slot_id( $time_slot_id_utc );
						$found_valid_date = true;
					}
				}

				if ( ! $found_valid_date ) {
					continue;
				}

				$dimensions        = get_post_meta( $post_id, 'clisyc_slot_dimensions', true );
				$primary_dim_key   = get_option( Constants::OPTION_PRIMARY_SERVICE_DIM, Constants::POST_TYPE_SERVICE );
				if ( ! empty( $dimensions[ $primary_dim_key ] ) ) {
					$primary_item_title = get_the_title( $dimensions[ $primary_dim_key ] );
					if ( $primary_item_title ) {
						$appointment_title = $primary_item_title;
					}
				}
				$status_obj           = get_post_status_object( get_post_status( $post_id ) );
				$status_label         = $status_obj ? $status_obj->label : ucfirst( str_replace( 'clisyc_', '', get_post_status( $post_id ) ) );

				// --- START MODIFICATION ---
				$cancellation_manager = new Cancellation_Manager( $post_id );
				$can_manage           = $cancellation_manager->can_be_managed();

				$appointments[]       = [
					'title'          => esc_html( $appointment_title ),
					'date'           => $display_data['date'],
					'time'           => $display_data['time'],
					'sort_date'      => $display_data['sort_date'],
					'sort_time'      => $display_data['sort_time'],
					'status'         => $status_label,
					'can_view'       => true,
					'view_url'       => esc_url( add_query_arg( 'view_id', $post_id, get_permalink( get_option( Constants::OPTION_APPOINTMENT_VIEW_PAGE ) ) ) ),
					'can_cancel'     => $can_manage,
					'can_reschedule' => $can_manage,
					'cancel_url'     => esc_url( wp_nonce_url( add_query_arg( [ 'clisyc_self_service_action' => 'cancel', 'appointment_id' => $post_id ] ), 'clisyc_cancel_appt_' . $post_id ) ),
					'reschedule_url' => esc_url( wp_nonce_url( add_query_arg( [ 'clisyc_self_service_action' => 'reschedule_initiate', 'appointment_id' => $post_id ] ), 'clisyc_reschedule_initiate_appt_' . $post_id ) ),
				];
				// --- END MODIFICATION ---
			}
		}
		wp_reset_postdata();
		wp_send_json_success(
			[
				'appointments' => $appointments,
				'total_pages'  => $query->max_num_pages,
			]
		);
	}

	/**
	 * Get the URL of the page containing [clisyc_manager_edit_appointment] shortcode.
	 * Uses the configured setting from Behavior > Frontend Links & Pages.
	 *
	 * @return string|null The page URL or null if not configured.
	 */
	private function get_manager_edit_appointment_page_url() {
		// Get the configured page ID from settings
		$page_id = (int) get_option( 'clisyc_manager_edit_page_id', 0 );

		if ( $page_id > 0 ) {
			$page = get_post( $page_id );
			if ( $page && 'publish' === $page->post_status ) {
				return get_permalink( $page_id );
			}
		}

		return null;
	}

	/**
	 * Parse various datetime formats to a Unix timestamp.
	 *
	 * @param string $datetime_string The datetime string to parse.
	 * @return int|false Unix timestamp or false on failure.
	 */
	private function parse_datetime_to_timestamp( $datetime_string ) {
		if ( empty( $datetime_string ) ) {
			return false;
		}

		// Try direct strtotime first (handles most formats)
		$timestamp = strtotime( $datetime_string );
		if ( $timestamp !== false && $timestamp > 0 ) {
			return $timestamp;
		}

		// Try DateTime parsing
		try {
			$dt = new \DateTime( $datetime_string );
			return $dt->getTimestamp();
		} catch ( \Exception $e ) {
			// Continue to other methods
		}

		// Try parsing ISO 8601 with timezone
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/', $datetime_string, $matches ) ) {
			try {
				$dt = new \DateTime( $datetime_string );
				return $dt->getTimestamp();
			} catch ( \Exception $e ) {
				// Fall through
			}
		}

		// Try MySQL datetime format
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $datetime_string ) ) {
			try {
				$dt = new \DateTime( $datetime_string, new \DateTimeZone( 'UTC' ) );
				return $dt->getTimestamp();
			} catch ( \Exception $e ) {
				// Fall through
			}
		}

		return false;
	}

	// =====================================================================
	// STAFF DASHBOARD — Notes & Status handlers
	// =====================================================================

	/**
	 * AJAX handler for staff members to update appointment notes.
	 *
	 * Security: verifies the requesting staff member owns the appointment
	 * via dimension meta, not just WordPress post author.
	 */
	public function ajax_staff_update_notes() {
		check_ajax_referer( 'clisyc_staff_notes_nonce', 'security' );

		$appointment_id = isset( $_POST['appointment_id'] ) ? absint( $_POST['appointment_id'] ) : 0;
		$notes          = isset( $_POST['notes'] ) ? wp_kses_post( wp_unslash( $_POST['notes'] ) ) : '';

		if ( ! $appointment_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'client-sync' ) ] );
		}

		// Resolve the current user's staff post and verify they own this appointment.
		$staff_post_id = Staff_Resolver::get_staff_post_id( get_current_user_id() );
		if ( ! $staff_post_id || ! Staff_Resolver::owns_appointment( $appointment_id, $staff_post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
		}

		$result = wp_update_post( [
			'ID'           => $appointment_id,
			'post_content' => $notes,
		] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => __( 'Failed to save notes.', 'client-sync' ) ] );
		}

		wp_send_json_success( [
			'message' => __( 'Notes saved.', 'client-sync' ),
			'notes'   => wp_kses_post( $notes ),
		] );
	}

	/**
	 * AJAX handler for staff members to update appointment status.
	 *
	 * Like the manager version but with staff ownership verification.
	 * Only allows: check-in (→ publish), completed (→ draft), no-show (→ trash).
	 */
	public function ajax_staff_update_appointment_status() {
		check_ajax_referer( 'clisyc_staff_update_status_nonce', 'security' );

		$appointment_id = isset( $_POST['appointment_id'] ) ? absint( $_POST['appointment_id'] ) : 0;
		$new_status_key = isset( $_POST['new_status'] ) ? sanitize_key( $_POST['new_status'] ) : '';

		if ( ! $appointment_id || ! $new_status_key ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'client-sync' ) ] );
		}

		// Verify staff ownership.
		$staff_post_id = Staff_Resolver::get_staff_post_id( get_current_user_id() );
		if ( ! $staff_post_id || ! Staff_Resolver::owns_appointment( $appointment_id, $staff_post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
		}

		$allowed_statuses = [
			'checked_in' => Constants::STATUS_CHECKED_IN,
			'completed'  => 'draft',
			'no_show'    => 'trash',
		];

		if ( ! array_key_exists( $new_status_key, $allowed_statuses ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid status action.', 'client-sync' ) ] );
		}

		$target_status = $allowed_statuses[ $new_status_key ];
		$result        = false;

		if ( 'trash' === $target_status ) {
			$result = wp_trash_post( $appointment_id );
		} else {
			$update_result = wp_update_post( [
				'ID'          => $appointment_id,
				'post_status' => $target_status,
			] );
			$result = ! is_wp_error( $update_result );
		}

		if ( $result ) {
			$status_obj       = get_post_status_object( $target_status );
			$new_status_label = $status_obj ? $status_obj->label : $target_status;
			wp_send_json_success( [
				'message'        => __( 'Appointment status updated.', 'client-sync' ),
				'newStatusLabel' => $new_status_label,
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to update appointment status.', 'client-sync' ) ] );
		}
	}
}
