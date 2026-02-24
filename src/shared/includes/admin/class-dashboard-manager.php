<?php
/**
 * File: src/shared/includes/admin/class-dashboard-manager.php
 * Handles dashboard page rendering, stats aggregation, setup milestones, and system status checks.
 *
 * Extracted from class-admin.php to follow the Single Responsibility Principle.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Database_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dashboard_Manager {

	/**
	 * @var Database_Manager
	 */
	private $db_manager;

	/**
	 * @var Reporting_Engine
	 */
	private $reporting_engine;

	/**
	 * @param Database_Manager $db_manager       Database manager instance.
	 * @param Reporting_Engine $reporting_engine  Reporting engine instance (for shared report data methods).
	 */
	public function __construct( Database_Manager $db_manager, Reporting_Engine $reporting_engine ) {
		$this->db_manager       = $db_manager;
		$this->reporting_engine = $reporting_engine;
	}

	/**
	 * Renders the main Dashboard admin page.
	 */
	public function render_dashboard_page() {
		$setup_milestones = $this->get_setup_milestones_status();

		$dashboard_stats = [
			'todays_appointments'     => $this->get_appointments_count_for_period( 'today' ),
			'appointments_this_month' => $this->get_appointments_count_for_period( 'this_month' ),
			'income_this_month'       => $this->get_income_for_period( 'this_month' ),
			'upcoming_appointments'   => $this->get_appointments_count_for_period( 'upcoming' ),
			'total_clients'           => $this->get_total_clients(),
			'busiest_day'             => $this->get_busiest_day_of_week(),
		];

		$todays_appointments_widget_data   = $this->get_todays_appointments( 10 );
		$upcoming_appointments_widget_data = $this->get_upcoming_appointments( 5 );
		$bookings_this_week_chart_data     = $this->get_appointments_this_week_by_day();
		$busiest_services_chart_data       = $this->get_busiest_services_data( 5 );

		wp_localize_script(
			'clisyc-admin-dashboard-charts',
			'clisycDashboardData',
			[
				'bookingsThisWeek' => $bookings_this_week_chart_data,
				'busiestServices'  => $busiest_services_chart_data,
				'l10n'             => [
					'bookings' => __( 'Bookings', 'client-sync' ),
				],
			]
		);

		$system_status_checks = $this->get_system_status_checks();

		require_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-dashboard-page.php';
	}

	/**
	 * Renders the Getting Started / Setup Checklist page.
	 */
	public function render_getting_started_page() {
		$setup_milestones = $this->get_setup_milestones_status();
		require_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-getting-started-page.php';
	}

	/**
	 * Renders the standalone Guide admin page.
	 * Combines the setup checklist with educational content.
	 */
	public function render_guide_page() {
		$setup_milestones = $this->get_setup_milestones_status();
		require_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-guide-page.php';
	}

	// =========================================================================
	// Dashboard Stats Helpers
	// =========================================================================

	/**
	 * Retrieves appointment counts for a given time period.
	 *
	 * @param string $period One of 'today', 'this_month', 'upcoming'.
	 * @return int
	 */
	private function get_appointments_count_for_period( string $period ): int {
		$date_query_args = [];
		$current_time    = current_time( 'timestamp' );

		if ( 'this_month' === $period ) {
			$date_query_args = [ 'key' => 'clisyc_appointment_date', 'value' => [ wp_date( 'Y-m-01', $current_time ), wp_date( 'Y-m-t', $current_time ) ], 'compare' => 'BETWEEN', 'type' => 'DATE' ];
		} elseif ( 'upcoming' === $period ) {
			$date_query_args = [ 'key' => 'clisyc_appointment_date', 'value' => wp_date( 'Y-m-d', $current_time ), 'compare' => '>=', 'type' => 'DATE' ];
		} elseif ( 'today' === $period ) {
			$date_query_args = [ 'key' => 'clisyc_appointment_date', 'value' => wp_date( 'Y-m-d', $current_time ), 'compare' => '=', 'type' => 'DATE' ];
		} else {
			return 0;
		}

		$args = [
			'post_type'              => Constants::POST_TYPE_APPOINTMENT,
			'post_status'            => [ 'publish', Constants::STATUS_CONFIRMED, 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ],
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'meta_query'             => [ $date_query_args ],
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		$query = new \WP_Query( $args );
		return $query->post_count;
	}

	/**
	 * Retrieves income for a given period (WooCommerce integration).
	 *
	 * @param string $period Currently only 'this_month' is supported.
	 * @return string Formatted price or 'N/A'.
	 */
	private function get_income_for_period( string $period ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return 'N/A';
		}

		global $wpdb;
		$start_date_str = '';
		$end_date_str   = '';
		if ( 'this_month' === $period ) {
			$start_date_str = wp_date( 'Y-m-01 00:00:00' );
			$end_date_str   = wp_date( 'Y-m-t 23:59:59' );
		} else {
			return wc_price( 0 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complex aggregate query for dashboard reporting.
		$total_income = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(pm.meta_value) FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'shop_order'
			AND p.post_status IN ('wc-processing', 'wc-completed')
			AND pm.meta_key = '_order_total'
			AND EXISTS (
				SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
				WHERE oi.order_id = p.ID AND oim.meta_key = '_clisyc_appointment_id' AND oim.meta_value != '' AND oim.meta_value IS NOT NULL
			)
			AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s",
			gmdate( 'Y-m-d H:i:s', strtotime( $start_date_str ) ),
			gmdate( 'Y-m-d H:i:s', strtotime( $end_date_str ) )
		) );
		return wc_price( $total_income );
	}

	/**
	 * Retrieves the total number of users with client roles.
	 *
	 * @return int
	 */
	private function get_total_clients(): int {
		$client_roles  = apply_filters( 'clisyc_client_user_roles', [ 'subscriber', 'customer' ] );
		$user_count    = count_users();
		$total_clients = 0;
		foreach ( $client_roles as $role ) {
			if ( isset( $user_count['avail_roles'][ $role ] ) ) {
				$total_clients += $user_count['avail_roles'][ $role ];
			}
		}
		return $total_clients;
	}

	/**
	 * Retrieves the busiest day of the week based on all-time appointment data.
	 *
	 * @return string
	 */
	private function get_busiest_day_of_week(): string {
		$popular_days_raw = $this->reporting_engine->get_popular_days_data( null, null );
		if ( empty( $popular_days_raw['data'] ) ) {
			return __( 'N/A', 'client-sync' );
		}
		$max_value = max( $popular_days_raw['data'] );
		$day_index = array_search( $max_value, $popular_days_raw['data'], true );

		return $popular_days_raw['labels'][ $day_index ] ?? __( 'N/A', 'client-sync' );
	}

	/**
	 * Retrieves upcoming appointments for the dashboard widget.
	 *
	 * @param int $limit The number of appointments to retrieve.
	 * @return array
	 */
	private function get_upcoming_appointments( int $limit = 5 ): array {
		$upcoming = [];
		$now_utc  = current_time( 'mysql', 1 );

		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'posts_per_page' => $limit,
			'post_status'    => [ 'publish', Constants::STATUS_CONFIRMED, 'clisyc_paid_on_day', 'wc-processing', 'wc-completed' ],
			'meta_key'       => Constants::META_TIME_SLOT,
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => Constants::META_TIME_SLOT,
					'value'   => $now_utc,
					'compare' => '>=',
					'type'    => 'DATETIME',
				],
			],
		];

		$query = new \WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id    = get_the_ID();
				$client     = get_user_by( 'id', get_post_field( 'post_author' ) );
				$time_slot  = get_post_meta( $post_id, Constants::META_TIME_SLOT, true );
				$start_time = strtotime( $time_slot );

				$upcoming[] = [
					'id'            => $post_id,
					'title'         => get_the_title(),
					'client_name'   => $client ? $client->display_name : __( 'Unknown Client', 'client-sync' ),
					'date_time'     => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start_time ),
					'relative_time' => sprintf( '%s ago', human_time_diff( $start_time, current_time( 'timestamp' ) ) ),
					'edit_link'     => get_edit_post_link( $post_id ),
				];
			}
		}
		wp_reset_postdata();

		return $upcoming;
	}

	/**
	 * Retrieves today's appointments for the dashboard widget.
	 *
	 * @param int $limit Maximum number of appointments to return.
	 * @return array
	 */
	private function get_todays_appointments( int $limit = 10 ): array {
		$appointments = [];
		$today        = wp_date( 'Y-m-d' );

		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'posts_per_page' => $limit,
			'post_status'    => [ 'publish', Constants::STATUS_CONFIRMED, 'clisyc_paid_on_day', 'wc-processing', 'wc-completed', Constants::STATUS_PENDING, Constants::STATUS_PENDING_PAYMENT ],
			'meta_key'       => Constants::META_TIME_SLOT,
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => 'clisyc_appointment_date',
					'value'   => $today,
					'compare' => '=',
					'type'    => 'DATE',
				],
			],
		];

		$query = new \WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id      = get_the_ID();
				$client       = get_user_by( 'id', get_post_field( 'post_author' ) );
				$time_slot    = get_post_meta( $post_id, Constants::META_TIME_SLOT, true );
				$start_time   = $time_slot ? strtotime( $time_slot ) : false;
				$status_obj   = get_post_status_object( get_post_status( $post_id ) );
				$status_label = $status_obj ? $status_obj->label : ucfirst( get_post_status( $post_id ) );

				$appointments[] = [
					'id'           => $post_id,
					'title'        => get_the_title(),
					'client_name'  => $client ? $client->display_name : __( 'Unknown Client', 'client-sync' ),
					'time'         => $start_time ? wp_date( get_option( 'time_format' ), $start_time ) : __( 'Not set', 'client-sync' ),
					'status_label' => $status_label,
					'status'       => get_post_status( $post_id ),
					'edit_link'    => get_edit_post_link( $post_id ),
				];
			}
		}
		wp_reset_postdata();

		return $appointments;
	}

	/**
	 * Retrieves appointment counts for each of the last 7 days for a chart.
	 *
	 * @return array
	 */
	private function get_appointments_this_week_by_day(): array {
		global $wpdb;
		$labels = [];
		$data   = [];

		for ( $i = 6; $i >= 0; $i-- ) {
			$date           = new \DateTime( "-$i days", wp_timezone() );
			$labels[]       = $date->format( 'D, M j' );
			$date_key       = $date->format( 'Y-m-d' );
			$data[ $date_key ] = 0;
		}

		$start_date_filter = ( new \DateTime( '-6 days', wp_timezone() ) )->format( 'Y-m-d' );
		$end_date_filter   = ( new \DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d' );

		$post_type = Constants::POST_TYPE_APPOINTMENT;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard widget requires fresh appointment data.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value as appt_date, COUNT(p.ID) as count
				FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND p.post_status IN ('publish', 'confirmed', 'clisyc_paid_on_day', 'wc-completed', 'wc-processing')
				AND pm.meta_key = 'clisyc_appointment_date'
				AND pm.meta_value >= %s AND pm.meta_value <= %s
				GROUP BY pm.meta_value",
				$post_type,
				$start_date_filter,
				$end_date_filter
			)
		);

		if ( $results ) {
			foreach ( $results as $row ) {
				if ( isset( $data[ $row->appt_date ] ) ) {
					$data[ $row->appt_date ] = (int) $row->count;
				}
			}
		}

		return [
			'labels' => $labels,
			'data'   => array_values( $data ),
		];
	}

	/**
	 * Retrieves the busiest services (primary dimension items) for a chart.
	 *
	 * @param int $limit The number of services to show.
	 * @return array
	 */
	private function get_busiest_services_data( int $limit = 5 ): array {
		global $wpdb;
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

		if ( ! $primary_dim_slug ) {
			return [ 'labels' => [], 'data' => [] ];
		}

		$post_type = Constants::POST_TYPE_APPOINTMENT;
		$meta_key  = Constants::META_SLOT_DIMENSIONS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complex query for dashboard chart.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				AND post_id IN (
					SELECT ID FROM {$wpdb->posts}
					WHERE post_type = %s
					AND post_status IN ('publish', 'confirmed', 'clisyc_paid_on_day', 'wc-completed', 'wc-processing')
				)
				AND meta_value LIKE %s",
				$meta_key,
				$post_type,
				'%"' . $wpdb->esc_like( $primary_dim_slug ) . '"%'
			)
		);

		$service_counts = [];
		if ( $results ) {
			foreach ( $results as $row ) {
				$dimensions = maybe_unserialize( $row->meta_value );
				if ( is_array( $dimensions ) && isset( $dimensions[ $primary_dim_slug ] ) ) {
					$service_id = (int) $dimensions[ $primary_dim_slug ];
					if ( $service_id > 0 ) {
						$service_counts[ $service_id ] = ( $service_counts[ $service_id ] ?? 0 ) + 1;
					}
				}
			}
		}

		arsort( $service_counts );
		$top_services = array_slice( $service_counts, 0, $limit, true );

		$labels = [];
		$data   = [];
		foreach ( $top_services as $service_id => $count ) {
			$labels[] = get_the_title( $service_id ) ?: "Service #{$service_id}";
			$data[]   = $count;
		}

		return [ 'labels' => $labels, 'data' => $data ];
	}

	// =========================================================================
	// Setup Milestones
	// =========================================================================

	/**
	 * Builds and returns the setup milestones array with completion status.
	 *
	 * @return array
	 */
	public function get_setup_milestones_status(): array {
		$completed_milestones_option = get_option( Constants::OPTION_SETUP_MILESTONES, [] );
		if ( ! is_array( $completed_milestones_option ) ) {
			$completed_milestones_option = [];
		}

		$registry            = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$custom_types        = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
		$enabled_dimensions  = array_filter( $registry['dimensions'] ?? [], fn( $dim ) => ! empty( $dim['enabled'] ) );

		$milestones = [];

		// --- Core Milestones (Always present) ---
		$milestones['timezone_configured'] = [
			'label'           => __( 'Confirm Website Timezone', 'client-sync' ),
			'is_complete'     => ! empty( get_option( 'timezone_string' ) ),
			'next_step_label' => __( 'Set Timezone', 'client-sync' ),
			'next_step_url'   => admin_url( 'options-general.php#timezone_string' ),
			'settings_url'    => admin_url( 'options-general.php#timezone_string' ),
			'description'     => __( 'A city-based timezone is required for accurate scheduling.', 'client-sync' ),
		];
		$milestones['pages_created'] = [
			'label'           => __( 'Create Essential Pages', 'client-sync' ),
			'is_complete'     => $this->check_essential_pages_exist(),
			'next_step_label' => __( 'Create Pages (Wizard)', 'client-sync' ),
			'next_step_url'   => admin_url( 'admin.php?page=clisyc-setup&step=pages' ),
			'settings_url'    => admin_url( 'edit.php?post_type=page' ),
			'description'     => __( 'The booking calendar and client account pages are needed.', 'client-sync' ),
		];
		$milestones['frontend_pages_set'] = [
			'label'           => __( 'Configure Frontend Pages', 'client-sync' ),
			'is_complete'     => $this->check_frontend_pages_set(),
			'next_step_label' => __( 'Configure Pages', 'client-sync' ),
			'next_step_url'   => admin_url( 'admin.php?page=clisyc-settings&tab=behavior#clisyc_frontend_links_section' ),
			'settings_url'    => admin_url( 'admin.php?page=clisyc-settings&tab=behavior#clisyc_frontend_links_section' ),
			'description'     => __( 'Select the pages containing your booking, account, and appointment detail shortcodes in the settings.', 'client-sync' ),
		];

		// --- DYNAMICALLY GENERATED MILESTONES ---
		if ( ! empty( $enabled_dimensions ) ) {
			$primary_dimension_slug = null;
			foreach ( $enabled_dimensions as $slug => $settings ) {
				if ( ! empty( $settings['primary'] ) ) {
					$primary_dimension_slug = $slug;
					break;
				}
			}

			// 1. Build milestones for CREATING items in each enabled dimension.
			foreach ( $enabled_dimensions as $slug => $settings ) {
				$cpt_obj = get_post_type_object( $slug );
				if ( ! $cpt_obj ) {
					continue;
				}

				$singular_name = $custom_types[ $slug ]['singular'] ?? $cpt_obj->labels->singular_name;
				$plural_name   = $custom_types[ $slug ]['plural'] ?? $cpt_obj->labels->name;

				$milestones[ "create_{$slug}" ] = [
					/* translators: %s: The singular name of a content type, like "Service" or "Practitioner". */
					'label'           => sprintf( __( 'Create your first %s', 'client-sync' ), $singular_name ),
					'is_complete'     => (int) wp_count_posts( $slug )->publish > 0,
					/* translators: %s: The singular name of a content type, like "Service" or "Practitioner". */
					'next_step_label' => sprintf( __( 'Add New %s', 'client-sync' ), $singular_name ),
					'next_step_url'   => admin_url( 'post-new.php?post_type=' . $slug ),
					'settings_url'    => admin_url( 'edit.php?post_type=' . $slug ),
					/* translators: %s: The plural name of a content type, like "Services". */
					'description'     => sprintf( __( 'Create at least one item for your "%s" dimension.', 'client-sync' ), $plural_name ),
				];
			}

			// 2. Add the availability milestone for the PRIMARY dimension.
			if ( $primary_dimension_slug && ( $cpt_obj = get_post_type_object( $primary_dimension_slug ) ) ) {
				$primary_singular_name = $custom_types[ $primary_dimension_slug ]['singular'] ?? $cpt_obj->labels->singular_name;

				$milestones['availability_set'] = [
					/* translators: %s: The singular name of the primary content type, like "Service". */
					'label'           => sprintf( __( 'Set Availability for a %s', 'client-sync' ), $primary_singular_name ),
					'is_complete'     => $this->check_standard_availability_set( $primary_dimension_slug ),
					'next_step_label' => __( 'Set Availability', 'client-sync' ),
					'next_step_url'   => admin_url( 'edit.php?post_type=' . $primary_dimension_slug ),
					'settings_url'    => admin_url( 'edit.php?post_type=' . $primary_dimension_slug ),
					'description'     => __( 'Define your typical weekly working hours for at least one of your primary dimension items.', 'client-sync' ),
				];
			}

			// 3. Build milestones for CREATING RELATIONSHIPS.
			$relationships = $registry['relationships'] ?? [];
			foreach ( $relationships as $parent_slug => $child_slugs ) {
				if ( empty( $enabled_dimensions[ $parent_slug ] ) ) {
					continue;
				}

				$parent_cpt = get_post_type_object( $parent_slug );
				if ( ! $parent_cpt ) {
					continue;
				}
				$parent_plural_name = $custom_types[ $parent_slug ]['plural'] ?? $parent_cpt->labels->name;

				foreach ( (array) $child_slugs as $child_slug ) {
					if ( empty( $enabled_dimensions[ $child_slug ] ) ) {
						continue;
					}

					$child_cpt = get_post_type_object( $child_slug );
					if ( ! $child_cpt ) {
						continue;
					}
					$child_plural_name = $custom_types[ $child_slug ]['plural'] ?? $child_cpt->labels->name;

					$milestones[ "link_{$parent_slug}_to_{$child_slug}" ] = [
						/* translators: 1: The plural name of a parent content type (e.g., "Practitioners"). 2: The plural name of a child content type (e.g., "Services"). */
						'label'           => sprintf( __( 'Link %1$s to %2$s', 'client-sync' ), $parent_plural_name, $child_plural_name ),
						'is_complete'     => $this->check_relationship_exists( $parent_slug, $child_slug ),
						/* translators: %s: The plural name of a content type, like "Practitioners". */
						'next_step_label' => sprintf( __( 'Edit %s', 'client-sync' ), $parent_plural_name ),
						'next_step_url'   => admin_url( 'edit.php?post_type=' . $parent_slug ),
						'settings_url'    => admin_url( 'edit.php?post_type=' . $parent_slug ),
						/* translators: 1: The lowercase plural name of a parent content type (e.g., "practitioners"). 2: The lowercase plural name of a child content type (e.g., "services"). */
						'description'     => sprintf( __( 'You must link your %1$s to the %2$s they are associated with.', 'client-sync' ), strtolower( $parent_plural_name ), strtolower( $child_plural_name ) ),
					];
				}
			}
		}

		// --- Optional / Manual Milestones ---
		$last_run_timestamp       = get_option( Constants::OPTION_LAST_MAINTENANCE_TS );
		$is_cron_running_recently = false;
		$cron_status_text         = '<em>' . __( 'The automation task has not run yet.', 'client-sync' ) . '</em>';

		if ( $last_run_timestamp ) {
			$current_utc_time    = current_time( 'timestamp', true );
			$time_since_last_run = $current_utc_time - $last_run_timestamp;

			if ( $time_since_last_run <= ( 20 * MINUTE_IN_SECONDS ) ) {
				$is_cron_running_recently = true;
			}

			/* translators: %s: Human-readable time difference like "5 minutes". */
			$cron_status_text = sprintf( esc_html__( 'Last run: %s ago.', 'client-sync' ), esc_html( human_time_diff( $last_run_timestamp, $current_utc_time ) ) );
		}

		$milestones['auto_generation_enabled'] = [
			'label'           => __( 'Enable Automatic Slot Generation', 'client-sync' ),
			'is_complete'     => (bool) get_option( Constants::OPTION_AUTO_GEN_ENABLED, false ),
			'is_optional'     => true,
			'next_step_label' => __( 'Configure Automation', 'client-sync' ),
			'next_step_url'   => admin_url( 'admin.php?page=clisyc-settings&tab=automation' ),
			'settings_url'    => admin_url( 'admin.php?page=clisyc-settings&tab=automation' ),
			'description'     => __( 'Allow the plugin to automatically create future time slots based on your schedules.', 'client-sync' ),
		];
		$milestones['woocommerce_setup'] = [
			'label'           => __( 'Configure Payments (Optional)', 'client-sync' ),
			'is_complete'     => ! ( $wc_enabled = get_option( Constants::OPTION_WC_ENABLED, false ) ) || ( $wc_enabled && absint( get_option( Constants::OPTION_WC_PRODUCT_ID, 0 ) ) > 0 ),
			'is_optional'     => true,
			'next_step_label' => __( 'Set Up Payments (Wizard)', 'client-sync' ),
			'next_step_url'   => admin_url( 'admin.php?page=clisyc-setup&step=payment' ),
			'settings_url'    => admin_url( 'admin.php?page=clisyc-settings&tab=payments' ),
			'description'     => __( 'Integrate with WooCommerce to charge for appointments.', 'client-sync' ),
		];
		$milestones['notifications_reviewed'] = [
			'label'                => __( 'Review Notifications', 'client-sync' ),
			'is_complete'          => ! empty( $completed_milestones_option['notifications_reviewed'] ),
			'is_manual_completion' => true,
			'next_step_label'      => __( 'Review Emails', 'client-sync' ),
			'next_step_url'        => admin_url( 'admin.php?page=clisyc-settings&tab=notifications' ),
			'settings_url'         => admin_url( 'admin.php?page=clisyc-settings&tab=notifications' ),
			'description'          => __( 'Check the default email templates for booking confirmations and reminders.', 'client-sync' ),
		];
		$milestones['server_cron_recommended'] = [
			'label'                => __( 'Optimize Automation (Recommended)', 'client-sync' ),
			'is_complete'          => ! empty( $completed_milestones_option['server_cron_recommended'] ) || $is_cron_running_recently,
			'is_manual_completion' => true,
			'is_optional'          => true,
			'next_step_label'      => __( 'Learn about Server Cron', 'client-sync' ),
			'next_step_url'        => admin_url( 'admin.php?page=clisyc-settings&tab=automation' ),
			'settings_url'         => admin_url( 'admin.php?page=clisyc-settings&tab=automation' ),
			'description'          => __( 'For best reliability, set up a server-level cron job.', 'client-sync' ) . ' ' . $cron_status_text,
		];

		// Recalculate completion status.
		$new_completed_status = $completed_milestones_option;
		foreach ( $milestones as $key => $details ) {
			if ( ! ( $details['is_manual_completion'] ?? false ) ) {
				$new_completed_status[ $key ] = $details['is_complete'];
			}
		}
		if ( $new_completed_status !== $completed_milestones_option ) {
			update_option( Constants::OPTION_SETUP_MILESTONES, $new_completed_status );
		}

		return $milestones;
	}

	// =========================================================================
	// Milestone Check Helpers
	// =========================================================================

	/**
	 * Checks whether the essential pages (booking, account, details) exist.
	 *
	 * @return bool
	 */
	private function check_essential_pages_exist(): bool {
		$booking_page_slug = 'booking-calendar';
		$account_page_slug = 'my-account';
		$details_page_slug = get_option( Constants::OPTION_APPOINTMENT_VIEW_SLUG, 'appointment-details' );

		$booking_page = get_page_by_path( $booking_page_slug );
		$account_page = get_page_by_path( $account_page_slug );
		$details_page = get_page_by_path( $details_page_slug );

		return ( $booking_page && $account_page && $details_page );
	}

	/**
	 * Checks whether the required frontend page IDs are configured.
	 *
	 * @return bool
	 */
	private function check_frontend_pages_set(): bool {
		$booking_page_id = (int) get_option( Constants::OPTION_BOOKING_PAGE_ID, 0 );
		$details_page_id = (int) get_option( Constants::OPTION_APPOINTMENT_VIEW_PAGE, 0 );
		$search_page_id  = (int) get_option( Constants::OPTION_SEARCH_RESULTS_PAGE, 0 );

		return $details_page_id > 0 && ( $booking_page_id > 0 || $search_page_id > 0 );
	}

	/**
	 * Checks whether at least one primary dimension item has a schedule configured.
	 *
	 * @param string $cpt_slug The post type slug.
	 * @return bool
	 */
	private function check_standard_availability_set( string $cpt_slug ): bool {
		$args = [
			'post_type'      => $cpt_slug,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => Constants::META_SCHEDULE,
					'compare' => 'EXISTS',
				],
				[
					'key'     => Constants::META_SCHEDULE,
					'compare' => '!=',
					'value'   => '',
				],
			],
			'no_found_rows' => true,
			'cache_results' => false,
		];

		$found_posts = get_posts( $args );

		if ( empty( $found_posts ) ) {
			return false;
		}

		$schedule_json = get_post_meta( $found_posts[0], Constants::META_SCHEDULE, true );
		if ( empty( $schedule_json ) ) {
			return false;
		}

		$schedule_data = json_decode( $schedule_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $schedule_data ) || empty( $schedule_data['templates'] ) ) {
			return false;
		}

		foreach ( $schedule_data['templates'] as $template ) {
			if ( is_array( $template ) ) {
				foreach ( $template as $day_data ) {
					if ( ! empty( $day_data['slots'] ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Checks whether at least one relationship exists between two dimension types.
	 *
	 * @param string $parent_slug Parent post type slug.
	 * @param string $child_slug  Child post type slug.
	 * @return bool
	 */
	private function check_relationship_exists( string $parent_slug, string $child_slug ): bool {
		global $wpdb;
		$rels_table = $this->db_manager->get_relationships_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT 1 FROM {$rels_table} WHERE parent_object_type = %s AND child_object_type = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->get_var( $wpdb->prepare( $sql, $parent_slug, $child_slug ) );

		return ! empty( $result );
	}

	// =========================================================================
	// System Status Checks
	// =========================================================================

	/**
	 * Builds the system status checks array for the dashboard.
	 *
	 * @return array
	 */
	public function get_system_status_checks(): array {
		$status_checks = [];

		// Timezone check.
		if ( empty( get_option( 'timezone_string' ) ) ) {
			$status_checks['timezone'] = [
				'status'      => 'critical',
				'message'     => __( 'Your website timezone is set to a manual UTC offset. This can cause scheduling errors. Please set a city-based timezone for accurate booking.', 'client-sync' ),
				'action_url'  => admin_url( 'options-general.php#timezone_string' ),
				'action_text' => __( 'Set Timezone', 'client-sync' ),
			];
		}

		// --- DYNAMIC PRIMARY DIMENSION CHECK ---
		$registry          = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug  = null;
		$primary_dim_label = __( 'Primary Dimension', 'client-sync' );

		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				if ( ! empty( $settings['enabled'] ) && ! empty( $settings['primary'] ) ) {
					$primary_dim_slug = $slug;
					$cpt_object       = get_post_type_object( $slug );
					if ( $cpt_object ) {
						$primary_dim_label = $cpt_object->labels->singular_name;
					}
					break;
				}
			}
		}

		if ( ! $primary_dim_slug ) {
			$status_checks['no_primary_dimension'] = [
				'status'      => 'critical',
				'message'     => __( 'No "Primary Dimension" is set. The system does not know which schedule to use. Please enable a dimension and set it as primary.', 'client-sync' ),
				'action_url'  => admin_url( 'admin.php?page=clisyc-dimensions&tab=setup' ),
				'action_text' => __( 'Configure Dimensions', 'client-sync' ),
			];
		} elseif ( post_type_exists( $primary_dim_slug ) ) {
			$item_count_obj = wp_count_posts( $primary_dim_slug );
			$item_count     = is_object( $item_count_obj ) ? ( $item_count_obj->publish ?? 0 ) : 0;

			if ( 0 === $item_count ) {
				$status_checks['no_primary_items'] = [
					'status'      => 'warning',
					/* translators: %s: The singular name of the primary dimension (e.g., "Service"). */
					'message'     => sprintf( __( 'No "%s" items have been created yet. You must have at least one for clients to book.', 'client-sync' ), $primary_dim_label ),
					'action_url'  => admin_url( 'post-new.php?post_type=' . $primary_dim_slug ),
					/* translators: %s: The singular name of the primary dimension (e.g., "Service"). */
					'action_text' => sprintf( __( 'Create First %s', 'client-sync' ), $primary_dim_label ),
				];
			} else {
				$db_manager        = new Database_Manager();
				$future_slot_dates = $db_manager->get_distinct_available_slot_dates();

				if ( empty( $future_slot_dates ) ) {
					$status_checks['no_availability'] = [
						'status'      => 'notice',
						'message'     => __( 'You have items to book, but no future availability has been generated. Clients will not be able to book any appointments.', 'client-sync' ),
						'action_url'  => admin_url( 'admin.php?page=clisyc-calendars&tab=time-slots' ),
						'action_text' => __( 'Generate Time Slots', 'client-sync' ),
					];
				}
			}
		}

		// --- WOOCOMMERCE CHECK ---
		if ( get_option( Constants::OPTION_WC_ENABLED, false ) && class_exists( 'WooCommerce' ) && $primary_dim_slug ) {
			$primary_items = get_posts(
				[
					'post_type'      => $primary_dim_slug,
					'posts_per_page' => -1,
					'post_status'    => 'publish',
					'fields'         => 'ids',
				]
			);

			$unlinked_item_count = 0;
			foreach ( $primary_items as $item_id ) {
				$product_id = get_post_meta( $item_id, Constants::META_WC_PRODUCT_ID, true );
				if ( empty( $product_id ) || ! is_numeric( $product_id ) || $product_id <= 0 ) {
					$unlinked_item_count++;
				}
			}

			if ( $unlinked_item_count > 0 ) {
				$cpt_object   = get_post_type_object( $primary_dim_slug );
				$plural_label = $cpt_object ? $cpt_object->labels->name : __( 'Primary Items', 'client-sync' );

				/* translators: 1: Number of unlinked items. 2: Plural name of the primary dimension (e.g., "Room Types"). */
				$message_format = _n(
					'WooCommerce payments are enabled, but %1$d %2$s is not linked to a product and cannot be booked with a payment.',
					'WooCommerce payments are enabled, but %1$d %2$s are not linked to a product and cannot be booked with a payment.',
					$unlinked_item_count,
					'client-sync'
				);

				/* translators: %s: Plural name of the primary dimension (e.g., "Room Types"). */
				$link_text = sprintf( __( 'Link %s', 'client-sync' ), $plural_label );

				$status_checks['wc_product_linking'] = [
					'status'      => 'notice',
					'message'     => sprintf(
						$message_format,
						$unlinked_item_count,
						strtolower( $plural_label )
					),
					'action_url'  => admin_url( 'edit.php?post_type=' . $primary_dim_slug ),
					'action_text' => $link_text,
				];
			}
		}

		return apply_filters( 'clisyc_dashboard_status_checks', $status_checks );
	}
}
