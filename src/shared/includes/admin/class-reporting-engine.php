<?php
/**
 * File: src/shared/includes/admin/class-reporting-engine.php
 * Handles all reporting data aggregation, chart rendering, and CSV export.
 *
 * Extracted from class-admin.php to follow the Single Responsibility Principle.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reporting_Engine {

	/**
	 * Register hooks for the reporting engine.
	 */
	public function register_hooks() {
		add_action( 'admin_post_clisyc_export_report', [ $this, 'handle_export_report_to_csv' ] );
	}

	/**
	 * Renders the Reports admin page.
	 *
	 * Gathers all report datasets, localizes them for Chart.js, and includes the view.
	 */
	public function render_reports_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading for non-state-changing report filtering.
		$unslashed_get = wp_unslash( $_GET );

		$today         = wp_date( 'Y-m-d' );
		$start_of_year = wp_date( 'Y-01-01' );

		$start_date_filter = isset( $unslashed_get['start_date'] ) && $unslashed_get['start_date'] ? sanitize_text_field( $unslashed_get['start_date'] ) : $start_of_year;
		$end_date_filter   = isset( $unslashed_get['end_date'] ) && $unslashed_get['end_date'] ? sanitize_text_field( $unslashed_get['end_date'] ) : $today;

		if ( strtotime( $end_date_filter ) < strtotime( $start_date_filter ) ) {
			$end_date_filter = $start_date_filter;
		}

		$appointments_per_month_raw  = $this->get_appointments_per_month_data( $start_date_filter, $end_date_filter );
		$appointments_by_status_raw  = $this->get_appointments_by_status_data( $start_date_filter, $end_date_filter );
		$popular_times_raw           = $this->get_popular_times_data( $start_date_filter, $end_date_filter );
		$popular_days_raw            = $this->get_popular_days_data( $start_date_filter, $end_date_filter );
		$kpi_summary                 = $this->get_kpi_summary( $start_date_filter, $end_date_filter );
		$revenue_per_month_raw       = $this->get_revenue_per_month_data( $start_date_filter, $end_date_filter );
		$appointments_by_service_raw = $this->get_appointments_by_service_data( $start_date_filter, $end_date_filter );
		$client_retention_raw        = $this->get_client_retention_data( $start_date_filter, $end_date_filter );
		$cancellation_trends_raw     = $this->get_cancellation_trends_data( $start_date_filter, $end_date_filter );

		$appointments_per_month = [];
		foreach ( $appointments_per_month_raw['labels'] as $i => $label ) {
			$appointments_per_month[ $label ] = $appointments_per_month_raw['data'][ $i ];
		}
		$appointments_by_status = [];
		foreach ( $appointments_by_status_raw['labels'] as $i => $label ) {
			$appointments_by_status[ $label ] = $appointments_by_status_raw['data'][ $i ];
		}
		$popular_times = [];
		foreach ( $popular_times_raw['labels'] as $i => $label ) {
			$popular_times[ $label ] = $popular_times_raw['data'][ $i ];
		}
		$popular_days = [];
		foreach ( $popular_days_raw['labels'] as $i => $label ) {
			$popular_days[ $label ] = $popular_days_raw['data'][ $i ];
		}
		$revenue_per_month = [];
		foreach ( $revenue_per_month_raw['labels'] as $i => $label ) {
			$revenue_per_month[ $label ] = $revenue_per_month_raw['data'][ $i ];
		}
		$appointments_by_service = [];
		foreach ( $appointments_by_service_raw['labels'] as $i => $label ) {
			$appointments_by_service[ $label ] = $appointments_by_service_raw['data'][ $i ];
		}
		$client_retention = [];
		foreach ( $client_retention_raw['labels'] as $i => $label ) {
			$client_retention[ $label ] = [ 'new' => $client_retention_raw['new'][ $i ], 'returning' => $client_retention_raw['returning'][ $i ] ];
		}
		$cancellation_trends = [];
		foreach ( $cancellation_trends_raw['labels'] as $i => $label ) {
			$cancellation_trends[ $label ] = [ 'cancelled' => $cancellation_trends_raw['cancelled'][ $i ], 'noShow' => $cancellation_trends_raw['noShow'][ $i ] ];
		}

		wp_localize_script( 'clisyc-admin-reports', 'clisycReportsData', [
			'appointmentsPerMonth'  => $appointments_per_month_raw,
			'appointmentsByStatus'  => $appointments_by_status_raw,
			'popularTimes'          => $popular_times_raw,
			'popularDays'           => $popular_days_raw,
			'revenuePerMonth'       => $revenue_per_month_raw,
			'appointmentsByService' => $appointments_by_service_raw,
			'clientRetention'       => $client_retention_raw,
			'cancellationTrends'    => $cancellation_trends_raw,
			'l10n'                  => [
				'numberOfAppointments'      => __( 'Number of Appointments', 'client-sync' ),
				'appointmentsPerMonthTitle' => __( 'Appointments per Month', 'client-sync' ),
				'appointmentsByStatusTitle' => __( 'Appointments by Status', 'client-sync' ),
				'popularTimesTitle'         => __( 'Popular Appointment Times', 'client-sync' ),
				'popularDaysTitle'          => __( 'Popular Days of the Week', 'client-sync' ),
				'revenue'                   => __( 'Revenue', 'client-sync' ),
				'revenuePerMonthTitle'      => __( 'Revenue by Month', 'client-sync' ),
				'appointmentsByServiceTitle' => __( 'Appointments by Service', 'client-sync' ),
				'clientRetentionTitle'      => __( 'Client Retention', 'client-sync' ),
				'newClients'                => __( 'New Clients', 'client-sync' ),
				'returningClients'          => __( 'Returning Clients', 'client-sync' ),
				'cancellationTrendsTitle'   => __( 'Cancellation & No-Show Trends', 'client-sync' ),
				'cancelled'                 => __( 'Cancelled', 'client-sync' ),
				'noShow'                    => __( 'No-Show', 'client-sync' ),
			],
		] );
		require_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-reports-page.php';
	}

	/**
	 * Handles CSV export of a specific report.
	 */
	public function handle_export_report_to_csv() {
		if ( ! isset( $_POST['clisyc_export_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clisyc_export_nonce'] ) ), 'clisyc_export_report_nonce' ) ) {
			wp_die( 'Security check failed.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}

		$post_data  = wp_unslash( $_POST );
		$report_id  = sanitize_key( $post_data['report_id'] ?? '' );
		$start_date = isset( $post_data['start_date'] ) && $post_data['start_date'] ? sanitize_text_field( $post_data['start_date'] ) : null;
		$end_date   = isset( $post_data['end_date'] ) && $post_data['end_date'] ? sanitize_text_field( $post_data['end_date'] ) : null;

		$data     = [];
		$headers  = [];
		$filename = 'clisyc-report-' . $report_id . '-' . wp_date( 'Y-m-d' ) . '.csv';

		switch ( $report_id ) {
			case 'appointments_per_month':
				$headers  = [ 'Month', 'Count' ];
				$raw_data = $this->get_appointments_per_month_data( $start_date, $end_date );
				$data     = array_map( null, $raw_data['labels'], $raw_data['data'] );
				break;
			case 'appointments_by_status':
				$headers  = [ 'Status', 'Count' ];
				$raw_data = $this->get_appointments_by_status_data( $start_date, $end_date );
				$data     = array_map( null, $raw_data['labels'], $raw_data['data'] );
				break;
			case 'popular_times':
				$headers  = [ 'Time Slot', 'Count' ];
				$raw_data = $this->get_popular_times_data( $start_date, $end_date );
				$data     = array_map( null, $raw_data['labels'], $raw_data['data'] );
				break;
			case 'popular_days':
				$headers  = [ 'Day of Week', 'Count' ];
				$raw_data = $this->get_popular_days_data( $start_date, $end_date );
				$data     = array_map( null, $raw_data['labels'], $raw_data['data'] );
				break;
			case 'kpi_summary':
				$headers  = [ 'Metric', 'Value' ];
				$raw_data = $this->get_kpi_summary( $start_date, $end_date );
				$data     = [
					[ 'Total Appointments', $raw_data['total_appointments'] ],
					[ 'Revenue', wp_strip_all_tags( $raw_data['revenue'] ) ],
					[ 'Unique Clients', $raw_data['unique_clients'] ],
					[ 'Cancellation Rate (%)', $raw_data['cancellation_rate'] ],
					[ 'Avg Bookings/Day', $raw_data['avg_per_day'] ],
				];
				break;
			case 'revenue_per_month':
				$headers  = [ 'Month', 'Revenue' ];
				$raw_data = $this->get_revenue_per_month_data( $start_date, $end_date );
				$data     = array_map( null, $raw_data['labels'], $raw_data['data'] );
				break;
			case 'appointments_by_service':
				$headers  = [ 'Service', 'Count' ];
				$raw_data = $this->get_appointments_by_service_data( $start_date, $end_date );
				$data     = array_map( null, $raw_data['labels'], $raw_data['data'] );
				break;
			case 'client_retention':
				$headers  = [ 'Month', 'New Clients', 'Returning Clients' ];
				$raw_data = $this->get_client_retention_data( $start_date, $end_date );
				$data     = array_map( null, $raw_data['labels'], $raw_data['new'], $raw_data['returning'] );
				break;
			case 'cancellation_trends':
				$headers  = [ 'Month', 'Cancelled', 'No-Show' ];
				$raw_data = $this->get_cancellation_trends_data( $start_date, $end_date );
				$data     = array_map( null, $raw_data['labels'], $raw_data['cancelled'], $raw_data['noShow'] );
				break;
			default:
				wp_die( 'Invalid report ID.' );
		}

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, $headers );
		foreach ( $data as $row ) {
			fputcsv( $output, $row );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Writing to php://output stream, not a file.
		fclose( $output );
		exit;
	}

	// =========================================================================
	// Report Data Methods
	// =========================================================================

	/**
	 * Appointments per month aggregation.
	 *
	 * @param string|null $start_date Start date filter (Y-m-d).
	 * @param string|null $end_date   End date filter (Y-m-d).
	 * @return array { labels: string[], data: int[] }
	 */
	public function get_appointments_per_month_data( ?string $start_date = null, ?string $end_date = null ): array {
		global $wpdb;
		$relevant_statuses   = [ 'publish', 'confirmed', 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ];
		$status_placeholders = implode( ', ', array_fill( 0, count( $relevant_statuses ), '%s' ) );

		$post_type = Constants::POST_TYPE_APPOINTMENT;

		$date_where   = '';
		$prepare_args = [ '%Y-%m-%d', '%Y-%m', $post_type ];
		$prepare_args = array_merge( $prepare_args, $relevant_statuses );

		if ( $start_date && $end_date ) {
			$date_where     = 'AND pm.meta_value BETWEEN %s AND %s';
			$prepare_args[] = $start_date;
			$prepare_args[] = $end_date;
		}
		$sql = "
			SELECT DATE_FORMAT(STR_TO_DATE(pm.meta_value, %s), %s) as month_year, COUNT(p.ID) as count
			FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
			AND p.post_status IN ({$status_placeholders})
			AND pm.meta_key = 'clisyc_appointment_date'
			{$date_where}
			AND STR_TO_DATE(pm.meta_value, %s) IS NOT NULL
			GROUP BY month_year
			ORDER BY STR_TO_DATE(pm.meta_value, %s) ASC
		";

		$prepare_args[] = '%Y-%m-%d';
		$prepare_args[] = '%Y-%m-%d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic SQL for reporting; query is prepared.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ), ARRAY_A );

		$labels = [];
		$data   = [];
		if ( $results ) {
			foreach ( $results as $row ) {
				try {
					$date_obj = \DateTime::createFromFormat( 'Y-m', $row['month_year'] );
					$labels[] = $date_obj ? $date_obj->format( 'M Y' ) : $row['month_year'];
				} catch ( \Exception $e ) {
					$labels[] = $row['month_year'];
				}
				$data[] = (int) $row['count'];
			}
		}
		return [ 'labels' => $labels, 'data' => $data ];
	}

	/**
	 * Appointments by status aggregation.
	 *
	 * @param string|null $start_date Start date filter (Y-m-d).
	 * @param string|null $end_date   End date filter (Y-m-d).
	 * @return array { labels: string[], data: int[] }
	 */
	public function get_appointments_by_status_data( ?string $start_date = null, ?string $end_date = null ): array {
		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'posts_per_page' => -1,
			'fields'         => 'post_status',
		];

		if ( $start_date && $end_date ) {
			$args['meta_query'] = [ [
				'key'     => 'clisyc_appointment_date',
				'value'   => [ $start_date, $end_date ],
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			] ];
		}

		$query         = new \WP_Query( $args );
		$status_counts = array_count_values( $query->posts );

		$labels = [];
		$data   = [];

		if ( $status_counts ) {
			$all_statuses_obj = get_post_stati( [ 'show_in_admin_all_list' => true ], 'objects' );
			$custom_statuses  = [ Constants::STATUS_PENDING_PAYMENT, Constants::STATUS_PAID_ON_DAY, Constants::STATUS_FAILED_ON_DAY ];

			foreach ( $custom_statuses as $key ) {
				if ( ! isset( $all_statuses_obj[ $key ] ) ) {
					$s = get_post_status_object( $key );
					if ( $s ) {
						$all_statuses_obj[ $key ] = $s;
					}
				}
			}

			foreach ( $status_counts as $key => $count ) {
				if ( $count > 0 ) {
					$labels[] = isset( $all_statuses_obj[ $key ] )
						? $all_statuses_obj[ $key ]->label
						: ucfirst( str_replace( 'clisyc_', '', $key ) );
					$data[]   = (int) $count;
				}
			}
		}

		return [ 'labels' => $labels, 'data' => $data ];
	}

	/**
	 * Popular appointment times aggregation.
	 *
	 * @param string|null $start_date Start date filter (Y-m-d).
	 * @param string|null $end_date   End date filter (Y-m-d).
	 * @return array { labels: string[], data: int[] }
	 */
	public function get_popular_times_data( ?string $start_date = null, ?string $end_date = null ): array {
		global $wpdb;
		$post_type     = Constants::POST_TYPE_APPOINTMENT;
		$time_slot_key = Constants::META_TIME_SLOT;
		$date_key      = 'clisyc_appointment_date';

		$date_join  = '';
		$date_where = '';
		$params     = [];

		if ( $start_date && $end_date ) {
			$date_join  = "JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = %s";
			$date_where = 'AND pm_date.meta_value BETWEEN %s AND %s';
			$params[]   = $date_key;
			$params[]   = $start_date;
			$params[]   = $end_date;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table/column names.
		$sql = "SELECT SUBSTRING(pm_time.meta_value, 12, 5) AS time_hour, COUNT(*) AS cnt
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm_time ON p.ID = pm_time.post_id AND pm_time.meta_key = %s
			{$date_join}
			WHERE p.post_type = %s AND p.post_status != 'auto-draft'
			AND pm_time.meta_value LIKE '%%T%%'
			{$date_where}
			GROUP BY time_hour
			ORDER BY cnt DESC
			LIMIT 15";

		$all_params = array_merge( [ $time_slot_key ], $params, [ $post_type ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregation report query.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$all_params ) );

		$labels = [];
		$data   = [];
		if ( $results ) {
			foreach ( $results as $row ) {
				$labels[] = $row->time_hour;
				$data[]   = (int) $row->cnt;
			}
		}

		return [ 'labels' => $labels, 'data' => $data ];
	}

	/**
	 * Popular days of the week aggregation.
	 *
	 * @param string|null $start_date Start date filter (Y-m-d).
	 * @param string|null $end_date   End date filter (Y-m-d).
	 * @return array { labels: string[], data: int[] }
	 */
	public function get_popular_days_data( ?string $start_date = null, ?string $end_date = null ): array {
		global $wpdb;
		$post_type = Constants::POST_TYPE_APPOINTMENT;
		$date_key  = 'clisyc_appointment_date';

		$date_where = '';
		$params     = [ $date_key, $post_type ];

		if ( $start_date && $end_date ) {
			$date_where = 'AND pm.meta_value BETWEEN %s AND %s';
			$params[]   = $start_date;
			$params[]   = $end_date;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table/column names.
		$sql = "SELECT DAYOFWEEK(pm.meta_value) AS dow, COUNT(*) AS cnt
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = %s AND p.post_status != 'auto-draft'
			AND pm.meta_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
			{$date_where}
			GROUP BY dow";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregation report query.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

		$days   = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];
		$counts = array_fill_keys( $days, 0 );
		if ( $results ) {
			foreach ( $results as $row ) {
				$day_index = (int) $row->dow - 1;
				if ( isset( $days[ $day_index ] ) ) {
					$counts[ $days[ $day_index ] ] = (int) $row->cnt;
				}
			}
		}

		$ordered_labels = [];
		$ordered_data   = [];
		$start_week     = (int) get_option( 'start_of_week', 0 );
		for ( $i = 0; $i < 7; $i++ ) {
			$day_name         = $days[ ( $start_week + $i ) % 7 ];
			$ordered_labels[] = $day_name;
			$ordered_data[]   = $counts[ $day_name ];
		}

		return [ 'labels' => $ordered_labels, 'data' => $ordered_data ];
	}

	/**
	 * KPI summary aggregation.
	 *
	 * @param string|null $start_date Start date filter (Y-m-d).
	 * @param string|null $end_date   End date filter (Y-m-d).
	 * @return array
	 */
	public function get_kpi_summary( ?string $start_date = null, ?string $end_date = null ): array {
		global $wpdb;
		$post_type          = Constants::POST_TYPE_APPOINTMENT;
		$relevant_statuses  = [ 'publish', 'confirmed', 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ];
		$cancelled_statuses = [ Constants::STATUS_CANCELLED, Constants::STATUS_NO_SHOW, 'trash' ];

		$date_where   = '';
		$prepare_args = [ $post_type ];
		if ( $start_date && $end_date ) {
			$date_where     = 'AND pm.meta_value BETWEEN %s AND %s';
			$prepare_args[] = $start_date;
			$prepare_args[] = $end_date;
		}

		$all_statuses        = array_merge( $relevant_statuses, $cancelled_statuses );
		$status_placeholders = implode( ', ', array_fill( 0, count( $all_statuses ), '%s' ) );
		$prepare_args        = array_merge( $prepare_args, $all_statuses );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic SQL for reporting.
		$sql = "SELECT p.post_status, p.post_author
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = %s
			AND pm.meta_key = 'clisyc_appointment_date'
			{$date_where}
			AND p.post_status IN ({$status_placeholders})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ), ARRAY_A );

		$total           = 0;
		$cancelled_count = 0;
		$unique_clients  = [];
		foreach ( $rows as $row ) {
			$total++;
			if ( in_array( $row['post_status'], $cancelled_statuses, true ) ) {
				$cancelled_count++;
			}
			if ( ! empty( $row['post_author'] ) ) {
				$unique_clients[ $row['post_author'] ] = true;
			}
		}

		$active_total    = $total - $cancelled_count;
		$cancellation_rate = $total > 0 ? round( ( $cancelled_count / $total ) * 100, 1 ) : 0;

		$days_in_period = 1;
		if ( $start_date && $end_date ) {
			try {
				$d1             = new \DateTime( $start_date );
				$d2             = new \DateTime( $end_date );
				$days_in_period = max( 1, $d2->diff( $d1 )->days + 1 );
			} catch ( \Exception $e ) { /* use default */ }
		}
		$avg_per_day = round( $active_total / $days_in_period, 1 );

		$wc_active = class_exists( 'WooCommerce' );
		$revenue   = 'N/A';
		if ( $wc_active && $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Revenue aggregation query.
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
				gmdate( 'Y-m-d H:i:s', strtotime( $start_date . ' 00:00:00' ) ),
				gmdate( 'Y-m-d H:i:s', strtotime( $end_date . ' 23:59:59' ) )
			) );
			$revenue = wc_price( $total_income );
		}

		return [
			'total_appointments' => $active_total,
			'revenue'            => $revenue,
			'unique_clients'     => count( $unique_clients ),
			'cancellation_rate'  => $cancellation_rate,
			'avg_per_day'        => $avg_per_day,
			'wc_active'          => $wc_active,
		];
	}

	/**
	 * Revenue per month aggregation.
	 *
	 * @param string|null $start_date Start date filter (Y-m-d).
	 * @param string|null $end_date   End date filter (Y-m-d).
	 * @return array { labels: string[], data: float[] }
	 */
	public function get_revenue_per_month_data( ?string $start_date = null, ?string $end_date = null ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [ 'labels' => [], 'data' => [] ];
		}

		global $wpdb;

		$date_where   = '';
		$prepare_args = [];
		if ( $start_date && $end_date ) {
			$date_where     = 'AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s';
			$prepare_args[] = gmdate( 'Y-m-d H:i:s', strtotime( $start_date . ' 00:00:00' ) );
			$prepare_args[] = gmdate( 'Y-m-d H:i:s', strtotime( $end_date . ' 23:59:59' ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Revenue aggregation for reporting.
		$sql = "SELECT DATE_FORMAT(p.post_date_gmt, '%%Y-%%m') as month_year, SUM(pm.meta_value) as total
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'shop_order'
			AND p.post_status IN ('wc-processing', 'wc-completed')
			AND pm.meta_key = '_order_total'
			AND EXISTS (
				SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
				WHERE oi.order_id = p.ID AND oim.meta_key = '_clisyc_appointment_id' AND oim.meta_value != '' AND oim.meta_value IS NOT NULL
			)
			{$date_where}
			GROUP BY month_year
			ORDER BY month_year ASC";

		if ( ! empty( $prepare_args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared above.
			$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No user input in this fallback.
			$results = $wpdb->get_results( $sql, ARRAY_A );
		}

		$labels = [];
		$data   = [];
		if ( $results ) {
			foreach ( $results as $row ) {
				try {
					$date_obj = \DateTime::createFromFormat( 'Y-m', $row['month_year'] );
					$labels[] = $date_obj ? $date_obj->format( 'M Y' ) : $row['month_year'];
				} catch ( \Exception $e ) {
					$labels[] = $row['month_year'];
				}
				$data[] = round( (float) $row['total'], 2 );
			}
		}
		return [ 'labels' => $labels, 'data' => $data ];
	}

	/**
	 * Appointments by service (primary dimension) aggregation.
	 *
	 * @param string|null $start_date Start date filter (Y-m-d).
	 * @param string|null $end_date   End date filter (Y-m-d).
	 * @return array { labels: string[], data: int[] }
	 */
	public function get_appointments_by_service_data( ?string $start_date = null, ?string $end_date = null ): array {
		global $wpdb;
		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_dim_slug = $slug;
				break;
			}
		}
		if ( ! $primary_dim_slug ) {
			return [ 'labels' => [], 'data' => [] ];
		}

		$post_type           = Constants::POST_TYPE_APPOINTMENT;
		$meta_key            = Constants::META_SLOT_DIMENSIONS;
		$relevant_statuses   = [ 'publish', 'confirmed', 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ];
		$status_placeholders = implode( ', ', array_fill( 0, count( $relevant_statuses ), '%s' ) );

		$date_where   = '';
		$prepare_args = [ $post_type, $meta_key ];
		$prepare_args[] = '%"' . $wpdb->esc_like( $primary_dim_slug ) . '"%';
		$prepare_args = array_merge( $prepare_args, $relevant_statuses );

		if ( $start_date && $end_date ) {
			$date_where = "AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_date
				WHERE pm_date.post_id = p.ID AND pm_date.meta_key = 'clisyc_appointment_date'
				AND pm_date.meta_value BETWEEN %s AND %s
			)";
			$prepare_args[] = $start_date;
			$prepare_args[] = $end_date;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic SQL for reporting.
		$sql = "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE p.post_type = %s
			AND pm.meta_key = %s
			AND pm.meta_value LIKE %s
			AND p.post_status IN ({$status_placeholders})
			{$date_where}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared above.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ) );

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
		$labels = [];
		$data   = [];
		foreach ( $service_counts as $service_id => $count ) {
			$labels[] = get_the_title( $service_id ) ?: "#{$service_id}";
			$data[]   = $count;
		}
		return [ 'labels' => $labels, 'data' => $data ];
	}

	/**
	 * Client retention (new vs returning) aggregation.
	 *
	 * @param string|null $start_date Start date filter (Y-m-d).
	 * @param string|null $end_date   End date filter (Y-m-d).
	 * @return array { labels: string[], new: int[], returning: int[] }
	 */
	public function get_client_retention_data( ?string $start_date = null, ?string $end_date = null ): array {
		global $wpdb;
		$post_type           = Constants::POST_TYPE_APPOINTMENT;
		$relevant_statuses   = [ 'publish', 'confirmed', 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ];
		$status_placeholders = implode( ', ', array_fill( 0, count( $relevant_statuses ), '%s' ) );

		$date_where   = '';
		$prepare_args = [ '%Y-%m', $post_type ];
		$prepare_args = array_merge( $prepare_args, $relevant_statuses );
		if ( $start_date && $end_date ) {
			$date_where     = 'AND pm.meta_value BETWEEN %s AND %s';
			$prepare_args[] = $start_date;
			$prepare_args[] = $end_date;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reporting query.
		$sql = "SELECT p.post_author, DATE_FORMAT(STR_TO_DATE(pm.meta_value, '%%Y-%%m-%%d'), %s) as month_year
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = %s
			AND pm.meta_key = 'clisyc_appointment_date'
			AND p.post_status IN ({$status_placeholders})
			{$date_where}
			AND p.post_author > 0";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ), ARRAY_A );

		// Get each client's first-ever appointment month.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reporting query.
		$first_months = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.post_author, MIN(DATE_FORMAT(STR_TO_DATE(pm.meta_value, '%%Y-%%m-%%d'), %s)) as first_month
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = %s
			AND pm.meta_key = 'clisyc_appointment_date'
			AND p.post_status IN ({$status_placeholders})
			AND p.post_author > 0
			GROUP BY p.post_author",
			'%Y-%m',
			$post_type,
			...$relevant_statuses
		), ARRAY_A );

		$client_first_month = [];
		foreach ( $first_months as $row ) {
			$client_first_month[ $row['post_author'] ] = $row['first_month'];
		}

		$month_data = [];
		$seen       = [];
		foreach ( $rows as $row ) {
			$month    = $row['month_year'];
			$author   = $row['post_author'];
			$seen_key = $month . '|' . $author;
			if ( isset( $seen[ $seen_key ] ) ) {
				continue;
			}
			$seen[ $seen_key ] = true;

			if ( ! isset( $month_data[ $month ] ) ) {
				$month_data[ $month ] = [ 'new' => 0, 'returning' => 0 ];
			}

			$first = $client_first_month[ $author ] ?? $month;
			if ( $first === $month ) {
				$month_data[ $month ]['new']++;
			} else {
				$month_data[ $month ]['returning']++;
			}
		}

		ksort( $month_data );
		$labels         = [];
		$new_data       = [];
		$returning_data = [];
		foreach ( $month_data as $month => $counts ) {
			try {
				$date_obj = \DateTime::createFromFormat( 'Y-m', $month );
				$labels[] = $date_obj ? $date_obj->format( 'M Y' ) : $month;
			} catch ( \Exception $e ) {
				$labels[] = $month;
			}
			$new_data[]       = $counts['new'];
			$returning_data[] = $counts['returning'];
		}
		return [ 'labels' => $labels, 'new' => $new_data, 'returning' => $returning_data ];
	}

	/**
	 * Cancellation and no-show trends aggregation.
	 *
	 * @param string|null $start_date Start date filter (Y-m-d).
	 * @param string|null $end_date   End date filter (Y-m-d).
	 * @return array { labels: string[], cancelled: int[], noShow: int[] }
	 */
	public function get_cancellation_trends_data( ?string $start_date = null, ?string $end_date = null ): array {
		global $wpdb;
		$post_type        = Constants::POST_TYPE_APPOINTMENT;
		$cancelled_status = Constants::STATUS_CANCELLED;
		$noshow_status    = Constants::STATUS_NO_SHOW;

		$date_where   = '';
		$prepare_args = [ '%Y-%m', $cancelled_status, $noshow_status, $post_type, $cancelled_status, $noshow_status ];
		if ( $start_date && $end_date ) {
			$date_where     = 'AND pm.meta_value BETWEEN %s AND %s';
			$prepare_args[] = $start_date;
			$prepare_args[] = $end_date;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reporting query.
		$sql = "SELECT
			DATE_FORMAT(STR_TO_DATE(pm.meta_value, '%%Y-%%m-%%d'), %s) as month_year,
			SUM(CASE WHEN p.post_status IN (%s, 'trash') THEN 1 ELSE 0 END) as cancelled,
			SUM(CASE WHEN p.post_status = %s THEN 1 ELSE 0 END) as no_show
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = %s
			AND pm.meta_key = 'clisyc_appointment_date'
			AND p.post_status IN (%s, %s, 'trash')
			{$date_where}
			GROUP BY month_year
			ORDER BY month_year ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared above.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ), ARRAY_A );

		$labels         = [];
		$cancelled_data = [];
		$noshow_data    = [];
		if ( $results ) {
			foreach ( $results as $row ) {
				try {
					$date_obj = \DateTime::createFromFormat( 'Y-m', $row['month_year'] );
					$labels[] = $date_obj ? $date_obj->format( 'M Y' ) : $row['month_year'];
				} catch ( \Exception $e ) {
					$labels[] = $row['month_year'];
				}
				$cancelled_data[] = (int) $row['cancelled'];
				$noshow_data[]    = (int) $row['no_show'];
			}
		}
		return [ 'labels' => $labels, 'cancelled' => $cancelled_data, 'noShow' => $noshow_data ];
	}
}
