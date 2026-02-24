<?php
/**
 * File: src/shared/includes/services/class-slot-query-service.php
 * Read-only slot queries, availability checks, and cache management.
 *
 * Extracted from Database_Manager. All slot retrieval logic lives here,
 * including capacity checks, resource conflict detection, and caching.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Services
 */

namespace DependentMedia\ClientSync\Services;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Table_Schema_Manager;
use DependentMedia\ClientSync\Traits\Dimension_Sql_Builder;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slot_Query_Service {

	use Dimension_Sql_Builder;

	const CACHE_GROUP = 'clisyc_slots';

	/**
	 * @var Table_Schema_Manager
	 */
	private $schema;

	/**
	 * @var Dimension_Query_Service
	 */
	private $dimension_service;

	public function __construct( Table_Schema_Manager $schema, Dimension_Query_Service $dimension_service ) {
		$this->schema            = $schema;
		$this->dimension_service = $dimension_service;
	}

	// =========================================================================
	// SLOT QUERIES
	// =========================================================================

	public function query_slots( string $start_date_utc, string $end_date_utc, array $dimensions = [] ): array {
		global $wpdb;

		$dimensions_cache_part = ! empty( $dimensions ) ? http_build_query( $dimensions ) : '';
		$cache_key             = 'query_' . md5( $start_date_utc . $end_date_utc . $dimensions_cache_part );
		$cached_data           = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached_data ) {
			return $cached_data;
		}

		$slots_table      = $this->schema->get_slots_table_name();
		$dims_table       = $this->schema->get_dimensions_table_name();
		$final_sql_params = [ $start_date_utc, $end_date_utc ];
		$where_clauses    = [ 's.start_time >= %s', 's.start_time < %s' ];

		if ( ! empty( $dimensions ) ) {
			$dim_match_data = $this->build_exact_dimension_match_sql( $dimensions, 's' );
			if ( ! empty( $dim_match_data['sql'] ) ) {
				$where_clauses[]  = $dim_match_data['sql'];
				$final_sql_params = array_merge( $final_sql_params, $dim_match_data['params'] );
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_clauses are built safely with placeholders.
		$sql = "SELECT s.slot_id, s.start_time, s.end_time, s.is_block, s.booking_count,
					   GROUP_CONCAT(DISTINCT CONCAT(d.dimension_key, ':::', d.dimension_value) SEPARATOR '|||') AS dimensions_concat
				FROM {$slots_table} s
				LEFT JOIN {$dims_table} d ON s.slot_id = d.slot_id
				WHERE " . implode( ' AND ', $where_clauses ) . '
				GROUP BY s.slot_id ORDER BY s.start_time ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$final_sql_params ), ARRAY_A );

		if ( empty( $results ) ) {
			wp_cache_set( $cache_key, [], self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
			return [];
		}

		$processed_slots = $this->parse_slot_results( $results );
		wp_cache_set( $cache_key, $processed_slots, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $processed_slots;
	}

	public function query_slots_with_combinations( string $start_date_utc, string $end_date_utc, array $dimensions ): array {
		global $wpdb;
		$slots_table = $this->schema->get_slots_table_name();
		$dims_table  = $this->schema->get_dimensions_table_name();

		if ( empty( $dimensions ) ) {
			return $this->query_slots( $start_date_utc, $end_date_utc, [] );
		}

		$valid_combinations = $this->dimension_service->get_valid_dimension_combinations( $dimensions );
		if ( empty( $valid_combinations ) ) {
			return [];
		}

		$overall_where_clauses = [];
		$overall_params        = [];

		foreach ( $valid_combinations as $combo ) {
			if ( empty( $combo ) ) {
				continue;
			}

			$combo_match_data = $this->build_exact_dimension_match_sql( $combo, 's' );
			if ( ! empty( $combo_match_data['sql'] ) ) {
				$overall_where_clauses[] = "( {$combo_match_data['sql']} )";
				$overall_params          = array_merge( $overall_params, $combo_match_data['params'] );
			}
		}

		if ( empty( $overall_where_clauses ) ) {
			return [];
		}

		$final_sql_params = [ $start_date_utc, $end_date_utc ];
		$final_sql_params = array_merge( $final_sql_params, $overall_params );

		$where_sql = 's.start_time >= %s AND s.start_time < %s AND (' . implode( ' OR ', $overall_where_clauses ) . ')';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = "SELECT s.slot_id, s.start_time, s.end_time, s.is_block, s.booking_count,
					   GROUP_CONCAT(DISTINCT CONCAT(d.dimension_key, ':::', d.dimension_value) SEPARATOR '|||') AS dimensions_concat
				FROM {$slots_table} s
				LEFT JOIN {$dims_table} d ON s.slot_id = d.slot_id
				WHERE {$where_sql}
				GROUP BY s.slot_id ORDER BY s.start_time ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$final_sql_params ), ARRAY_A );

		if ( empty( $results ) ) {
			return [];
		}

		return $this->parse_slot_results( $results );
	}

	public function get_slots_for_date_range( string $start_utc, string $end_utc, array $dimensions = [], bool $is_block = false ): array {
		global $wpdb;
		$wpdb->flush();
		$slots_table      = $this->schema->get_slots_table_name();
		$dims_table       = $this->schema->get_dimensions_table_name();
		$final_sql_params = [ $start_utc, $end_utc, (int) $is_block ];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = "SELECT s.slot_id, s.start_time, s.end_time, s.is_block, GROUP_CONCAT(DISTINCT CONCAT(d.dimension_key, ':::', d.dimension_value) SEPARATOR '|||') AS dimensions_concat FROM {$slots_table} s LEFT JOIN {$dims_table} d ON s.slot_id = d.slot_id WHERE s.start_time >= %s AND s.start_time < %s AND s.is_block = %d";

		$dim_match_data = $this->build_exact_dimension_match_sql( $dimensions, 's' );
		if ( ! empty( trim( $dim_match_data['sql'] ) ) ) {
			$sql             .= ' AND ' . $dim_match_data['sql'];
			$final_sql_params = array_merge( $final_sql_params, $dim_match_data['params'] );
		}
		$sql .= ' GROUP BY s.slot_id ORDER BY s.start_time ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$final_sql_params ), ARRAY_A );

		$processed_slots = [];
		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$dimensions_array = $this->parse_dimensions_concat( $row['dimensions_concat'] ?? '' );
				$processed_slots[] = [
					'start_time_utc' => $row['start_time'],
					'end_time_utc'   => $row['end_time'],
					'dimensions'     => $dimensions_array,
				];
			}
		}
		return $processed_slots;
	}

	public function get_all_blocked_slots_in_range( string $start_utc, string $end_utc ): array {
		global $wpdb;
		$slots_table = $this->schema->get_slots_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = "SELECT start_time, end_time FROM {$slots_table} WHERE is_block = 1 AND start_time < %s AND end_time > %s";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results(
			$wpdb->prepare( $sql, $end_utc, $start_utc ),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return [];
		}

		$processed_slots = [];
		$utc_timezone    = new \DateTimeZone( 'UTC' );
		foreach ( $results as $row ) {
			try {
				$processed_slots[] = [
					'start' => new \DateTime( $row['start_time'], $utc_timezone ),
					'end'   => new \DateTime( $row['end_time'], $utc_timezone ),
				];
			} catch ( \Exception $e ) {
				continue;
			}
		}
		return $processed_slots;
	}

	public function get_latest_slot_date_for_dimensions( array $dimensions ): ?string {
		global $wpdb;
		$wpdb->flush();
		$slots_table    = $this->schema->get_slots_table_name();
		$dim_match_data = $this->build_exact_dimension_match_sql( $dimensions, 's' );
		$dimension_sql  = $dim_match_data['sql'];
		$final_params   = $dim_match_data['params'];

		if ( empty( $dimension_sql ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = "SELECT MAX(s.start_time) FROM {$slots_table} s WHERE s.is_block = 0 AND {$dimension_sql}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$latest_datetime = $wpdb->get_var( $wpdb->prepare( $sql, ...$final_params ) );

		if ( $latest_datetime ) {
			try {
				return ( new \DateTime( $latest_datetime, new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' );
			} catch ( \Exception $e ) {
				return null;
			}
		}
		return null;
	}

	public function get_booked_appointments_for_date_range( string $start_utc, string $end_utc, ?int $primary_dim_id = null ): array {
		global $wpdb;
		$bookings_table = $this->schema->get_bookings_table_name();
		$slots_table    = $this->schema->get_slots_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$query = "SELECT b.appointment_id, s.start_time, s.end_time FROM {$bookings_table} b JOIN {$slots_table} s ON b.slot_id = s.slot_id WHERE s.start_time >= %s AND s.start_time < %s";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results(
			$wpdb->prepare( $query, $start_utc, $end_utc ),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return [];
		}

		$appointments = [];
		foreach ( $results as $row ) {
			$appointments[] = [
				'appointment_id' => (int) $row['appointment_id'],
				'start_time_utc' => $row['start_time'],
				'end_time_utc'   => $row['end_time'],
			];
		}
		return $appointments;
	}

	public function find_slot_id_by_start_time_and_dimensions( string $start_time_utc, array $dimensions ): ?int {
		global $wpdb;
		$slots_table = $this->schema->get_slots_table_name();

		if ( empty( $dimensions ) ) {
			Debug_Logger::log( 'find_slot_id_by_start_time_and_dimensions: Empty dimensions passed!', 'DB' );
			return null;
		}

		Debug_Logger::log( 'find_slot_id_by_start_time_and_dimensions called:', 'DB' );
		Debug_Logger::log( '- start_time_utc: ' . $start_time_utc, 'DB' );
		Debug_Logger::log_data( '- dimensions', $dimensions, 'DB' );

		$dim_match_data = $this->build_exact_dimension_match_sql( $dimensions, 's' );
		if ( empty( $dim_match_data['sql'] ) ) {
			Debug_Logger::log( '- dimension match SQL is empty, returning null', 'DB' );
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql    = "SELECT s.slot_id FROM {$slots_table} s WHERE s.start_time = %s AND " . $dim_match_data['sql'];
		$params = array_merge( [ $start_time_utc ], $dim_match_data['params'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$prepared_sql = $wpdb->prepare( $sql, ...$params );

		Debug_Logger::log( '- Prepared SQL: ' . $prepared_sql, 'DB' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$slot_id = $wpdb->get_var( $prepared_sql );

		Debug_Logger::log( '- Result slot_id: ' . ( $slot_id ?? 'null' ), 'DB' );

		return $slot_id ? (int) $slot_id : null;
	}

	// =========================================================================
	// CAPACITY & AVAILABILITY QUERIES
	// =========================================================================

	public function count_future_slots_for_dimensions( array $dimensions ): int {
		global $wpdb;

		$slots_table    = $this->schema->get_slots_table_name();
		$dims_table     = $this->schema->get_dimensions_table_name();
		$bookings_table = $this->schema->get_bookings_table_name();
		$postmeta_table = $wpdb->postmeta;

		$params = [ gmdate( 'Y-m-d H:i:s' ) ];

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
			return 0;
		}

		$dim_match_data = $this->build_exact_dimension_match_sql( $dimensions, 's' );
		if ( empty( $dim_match_data['sql'] ) ) {
			return 0;
		}

		$params = array_merge( $params, $dim_match_data['params'] );

		$meta_capacity = Constants::META_CAPACITY;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql_count = "
			SELECT COUNT(t.slot_id)
			FROM (
				SELECT
					s.slot_id,
					(SELECT COUNT(*) FROM {$bookings_table} b WHERE b.slot_id = s.slot_id) as booked_count,
					COALESCE(pm.meta_value, 1) as capacity
				FROM
					{$slots_table} s
				JOIN {$dims_table} d_primary ON s.slot_id = d_primary.slot_id AND d_primary.dimension_key = %s
				LEFT JOIN {$postmeta_table} pm ON d_primary.dimension_value = pm.post_id AND pm.meta_key = %s
				WHERE
					s.start_time >= %s
					AND s.is_block = 0
					AND {$dim_match_data['sql']}
			) AS t
			WHERE
				t.booked_count < t.capacity
		";

		array_unshift( $params, $primary_dim_slug, $meta_capacity );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = $wpdb->get_var( $wpdb->prepare( $sql_count, ...$params ) );

		return (int) $count;
	}

	public function has_slots_for_service_in_range( string $start_utc, string $end_utc, int $service_id ): bool {
		global $wpdb;
		$slots_table = $this->schema->get_slots_table_name();
		$dims_table  = $this->schema->get_dimensions_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$slots_table} s JOIN {$dims_table} d ON s.slot_id = d.slot_id WHERE d.dimension_key = 'service_id' AND d.dimension_value = %d AND s.start_time >= %s AND s.start_time < %s LIMIT 1", $service_id, $start_utc, $end_utc ) );
	}

	public function get_available_dimension_items_for_range( string $start_date_str, string $end_date_str, array $attribute_filters = [] ): array {
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
			return [];
		}

		$meta_query = [
			'relation' => 'AND',
			[
				'key'   => Constants::META_BOOKING_MODE,
				'value' => 'date_range',
			],
		];

		if ( ! empty( $attribute_filters ) ) {
			foreach ( $attribute_filters as $key => $value ) {
				if ( ! empty( $value ) ) {
					$prefixed_key = '_clisyc_' . sanitize_key( $key );
					$meta_query[] = [
						'key'     => $prefixed_key,
						'value'   => $value,
						'compare' => '=',
					];
				}
			}
		}

		$all_items = get_posts(
			[
				'post_type'      => $primary_dim_slug,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_query'     => $meta_query,
			]
		);

		if ( empty( $all_items ) ) {
			return [];
		}

		$available_item_ids = [];

		foreach ( $all_items as $item ) {
			$capacity = (int) get_post_meta( $item->ID, Constants::META_CAPACITY, true ) ?: 1;

			$args = [
				'post_type'      => Constants::POST_TYPE_APPOINTMENT,
				'post_status'    => [ 'publish', Constants::STATUS_CONFIRMED, 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => Constants::META_SLOT_DIMENSIONS,
						'value'   => '"' . $primary_dim_slug . '";i:' . $item->ID . ';',
						'compare' => 'LIKE',
					],
					[
						'key'     => Constants::META_END_DATE,
						'value'   => $start_date_str,
						'compare' => '>',
						'type'    => 'DATE',
					],
					[
						'key'     => Constants::META_START_DATE,
						'value'   => $end_date_str,
						'compare' => '<',
						'type'    => 'DATE',
					],
				],
			];

			$conflicting_bookings_query = new \WP_Query( $args );
			$conflicting_count          = $conflicting_bookings_query->post_count;

			if ( $conflicting_count < $capacity ) {
				$available_item_ids[] = $item->ID;
			}
		}

		if ( empty( $available_item_ids ) ) {
			return [];
		}

		return get_posts(
			[
				'post_type'      => $primary_dim_slug,
				'posts_per_page' => -1,
				'post__in'       => $available_item_ids,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
	}

	// =========================================================================
	// DISTINCT DATES (with capacity + resource conflict awareness)
	// =========================================================================

	public function get_distinct_available_slot_dates(): array {
		$cache_key   = self::CACHE_GROUP . '_distinct_dates';
		$cached_data = get_transient( $cache_key );

		if ( false !== $cached_data ) {
			return $cached_data;
		}

		global $wpdb;
		$slots_table        = $this->schema->get_slots_table_name();
		$bookings_table     = $this->schema->get_bookings_table_name();
		$dims_table         = $this->schema->get_dimensions_table_name();
		$postmeta_table     = $wpdb->postmeta;
		$site_timezone_name = wp_timezone_string();

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
			return $this->_get_distinct_dates_simple();
		}

		$use_mysql_method = false;
		if ( strpos( $site_timezone_name, '/' ) !== false ) {
			$override_setting  = get_option( Constants::OPTION_MYSQL_CONVERT_TZ_OR, 'auto_detect' );
			$convert_tz_status = get_option( Constants::OPTION_MYSQL_CONVERT_TZ, 'unknown' );

			if ( 'force_mysql' === $override_setting ) {
				$use_mysql_method = true;
			} elseif ( 'auto_detect' === $override_setting && 'fails' !== $convert_tz_status ) {
				$use_mysql_method = true;
			}
		}

		$results = [];
		$current_utc_datetime_str = gmdate( 'Y-m-d H:i:s' );

		$meta_capacity = Constants::META_CAPACITY;

		if ( $use_mysql_method ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$test_result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT CONVERT_TZ(%s, '+00:00', %s)",
					'2025-01-01 12:00:00',
					$site_timezone_name
				)
			);

			if ( null === $test_result ) {
				$use_mysql_method = false;
				if ( 'auto_detect' === get_option( Constants::OPTION_MYSQL_CONVERT_TZ_OR, 'auto_detect' ) ) {
					update_option( Constants::OPTION_MYSQL_CONVERT_TZ, 'fails' );
				}
			} else {
				if ( 'auto_detect' === get_option( Constants::OPTION_MYSQL_CONVERT_TZ_OR, 'auto_detect' ) ) {
					update_option( Constants::OPTION_MYSQL_CONVERT_TZ, 'works' );
				}
			}
		}

		// If resource dimensions exist, use PHP fallback for proper conflict checking
		$registry_check = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$has_resource_dims = false;
		foreach ( $registry_check['dimensions'] ?? [] as $slug => $config ) {
			if ( ! empty( $config['is_resource'] ) ) {
				$has_resource_dims = true;
				break;
			}
		}

		if ( $has_resource_dims ) {
			$use_mysql_method = false;
		}

		if ( $use_mysql_method ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$sql = "
				SELECT DISTINCT DATE(CONVERT_TZ(s.start_time, '+00:00', %s)) as slot_date
				FROM {$slots_table} s
				JOIN {$dims_table} d_primary
					ON s.slot_id = d_primary.slot_id
					AND d_primary.dimension_key = %s
				LEFT JOIN {$postmeta_table} pm
					ON d_primary.dimension_value = pm.post_id
					AND pm.meta_key = %s
				WHERE s.start_time >= %s
				AND s.is_block = 0
				AND (
					SELECT COUNT(*) FROM {$bookings_table} b WHERE b.slot_id = s.slot_id
				) < COALESCE(pm.meta_value, 1)
				AND CONVERT_TZ(s.start_time, '+00:00', %s) IS NOT NULL
				ORDER BY slot_date ASC
			";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$prepared_sql = $wpdb->prepare( $sql, $site_timezone_name, $primary_dim_slug, $meta_capacity, $current_utc_datetime_str, $site_timezone_name );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$results = $wpdb->get_col( $prepared_sql );

			if ( $wpdb->last_error ) {
				$use_mysql_method = false;
			} else {
				$results = $results ?: [];
			}
		}

		if ( ! $use_mysql_method ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$sql_php_fallback = "
				SELECT s.start_time
				FROM {$slots_table} s
				JOIN {$dims_table} d_primary
					ON s.slot_id = d_primary.slot_id
					AND d_primary.dimension_key = %s
				LEFT JOIN {$postmeta_table} pm
					ON d_primary.dimension_value = pm.post_id
					AND pm.meta_key = %s
				WHERE s.start_time >= %s
				AND s.is_block = 0
				AND (
					SELECT COUNT(*) FROM {$bookings_table} b WHERE b.slot_id = s.slot_id
				) < COALESCE(pm.meta_value, 1)
				ORDER BY s.start_time ASC
			";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$prepared_sql = $wpdb->prepare( $sql_php_fallback, $primary_dim_slug, $meta_capacity, $current_utc_datetime_str );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$utc_start_times_from_db = $wpdb->get_col( $prepared_sql );

			if ( ! empty( $utc_start_times_from_db ) ) {
				$slots_table_inner = $this->schema->get_slots_table_name();
				$dims_table_inner  = $this->schema->get_dimensions_table_name();

				$placeholders = implode( ',', array_fill( 0, count( $utc_start_times_from_db ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$slots_sql = "
					SELECT s.start_time,
						   GROUP_CONCAT(DISTINCT CONCAT(d.dimension_key, ':::', d.dimension_value) SEPARATOR '|||') as dims
					FROM {$slots_table_inner} s
					LEFT JOIN {$dims_table_inner} d ON s.slot_id = d.slot_id
					WHERE s.start_time IN ($placeholders)
					AND s.is_block = 0
					GROUP BY s.slot_id, s.start_time
				";
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$slots_with_dims = $wpdb->get_results( $wpdb->prepare( $slots_sql, ...$utc_start_times_from_db ), ARRAY_A );

				$first_time = min( $utc_start_times_from_db );
				$last_time  = max( $utc_start_times_from_db );
				$end_buffer = ( new \DateTime( $last_time ) )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
				$resource_conflicts = $this->_get_resource_conflicts_for_range( $first_time, $end_buffer );

				$site_slot_dates   = [];
				$site_timezone_obj = wp_timezone();

				foreach ( $slots_with_dims as $slot_row ) {
					$utc_time_str    = $slot_row['start_time'];
					$slot_dimensions = $this->parse_dimensions_concat( $slot_row['dims'] ?? '' );

					if ( $this->_has_resource_conflict( $utc_time_str, $slot_dimensions, $resource_conflicts ) ) {
						Debug_Logger::log( 'Skipping slot at ' . $utc_time_str . ' due to resource conflict', 'Smart-Start' );
						continue;
					}

					try {
						$utc_dt  = new \DateTime( $utc_time_str, new \DateTimeZone( 'UTC' ) );
						$site_dt = new \DateTime( 'now', $site_timezone_obj );
						$site_dt->setTimestamp( $utc_dt->getTimestamp() );
						$site_slot_dates[ $site_dt->format( 'Y-m-d' ) ] = true;
					} catch ( \Exception $e ) {
						// Fall through
					}
				}
				$results = array_keys( $site_slot_dates );
				sort( $results );
			}
		}

		set_transient( $cache_key, $results, 5 * MINUTE_IN_SECONDS );
		return $results;
	}

	// =========================================================================
	// CACHE MANAGEMENT
	// =========================================================================

	public function clear_all_slot_cache(): void {
		global $wpdb;

		// Flush object cache group (WP 6.1+ with supporting backend).
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		} else {
			// Fallback: wp_cache_delete is not practical without tracking keys,
			// but short TTL (5 min) ensures stale data expires quickly.
			wp_cache_delete( 'query_', self::CACHE_GROUP );
		}

		// Clear persistent transients used by get_distinct_available_slot_dates().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_GROUP ) . '%'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE_GROUP ) . '%'
			)
		);
	}

	// =========================================================================
	// PRIVATE HELPERS
	// =========================================================================

	private function _get_distinct_dates_simple(): array {
		global $wpdb;
		$slots_table              = $this->schema->get_slots_table_name();
		$current_utc_datetime_str = gmdate( 'Y-m-d H:i:s' );
		$site_timezone_obj        = wp_timezone();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = "SELECT DISTINCT s.start_time
				FROM {$slots_table} s
				WHERE s.is_block = 0 AND s.start_time >= %s
				ORDER BY s.start_time ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$utc_start_times = $wpdb->get_col( $wpdb->prepare( $sql, $current_utc_datetime_str ) );

		$site_slot_dates = [];
		foreach ( $utc_start_times as $utc_time_str ) {
			try {
				$utc_dt  = new \DateTime( $utc_time_str, new \DateTimeZone( 'UTC' ) );
				$site_dt = new \DateTime( 'now', $site_timezone_obj );
				$site_dt->setTimestamp( $utc_dt->getTimestamp() );
				$site_slot_dates[ $site_dt->format( 'Y-m-d' ) ] = true;
			} catch ( \Exception $e ) {
				// Skip invalid dates
			}
		}

		$results = array_keys( $site_slot_dates );
		sort( $results );

		set_transient( self::CACHE_GROUP . '_distinct_dates', $results, 5 * MINUTE_IN_SECONDS );
		return $results;
	}

	private function _has_resource_conflict( string $start_time_utc, array $slot_dimensions, array $resource_conflicts ): bool {
		if ( empty( $resource_conflicts ) || empty( $slot_dimensions ) ) {
			return false;
		}

		$registry      = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$resource_dims = [];
		foreach ( $registry['dimensions'] ?? [] as $slug => $config ) {
			if ( ! empty( $config['is_resource'] ) ) {
				$resource_dims[] = $slug;
			}
		}

		if ( empty( $resource_dims ) ) {
			return false;
		}

		foreach ( $resource_dims as $res_dim ) {
			if ( isset( $slot_dimensions[ $res_dim ] ) ) {
				$res_value    = (int) $slot_dimensions[ $res_dim ];
				$conflict_key = $start_time_utc . '|' . $res_dim . ':' . $res_value;
				if ( isset( $resource_conflicts[ $conflict_key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function _get_resource_conflicts_for_range( string $start_utc, string $end_utc ): array {
		global $wpdb;

		$registry      = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$resource_dims = [];
		foreach ( $registry['dimensions'] ?? [] as $slug => $config ) {
			if ( ! empty( $config['is_resource'] ) ) {
				$resource_dims[] = $slug;
			}
		}

		if ( empty( $resource_dims ) ) {
			return [];
		}

		$post_type            = Constants::POST_TYPE_APPOINTMENT;
		$meta_time_slot       = Constants::META_TIME_SLOT;
		$meta_slot_dimensions = Constants::META_SLOT_DIMENSIONS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$appointments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.ID as appointment_id,
					pm_time.meta_value as time_slot,
					pm_dims.meta_value as slot_dimensions
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_time ON p.ID = pm_time.post_id AND pm_time.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_dims ON p.ID = pm_dims.post_id AND pm_dims.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_status IN ('publish', 'confirmed', 'clisyc_pending_pay', 'clisyc_paid_on_day', 'wc-processing', 'wc-completed')
				AND pm_time.meta_value >= %s
				AND pm_time.meta_value < %s",
				$meta_time_slot,
				$meta_slot_dimensions,
				$post_type,
				$start_utc,
				$end_utc
			),
			ARRAY_A
		);

		$conflicts = [];

		foreach ( $appointments as $appt ) {
			$time_slot  = $appt['time_slot'];
			$dimensions = [];

			if ( ! empty( $appt['slot_dimensions'] ) ) {
				$dims_data = maybe_unserialize( $appt['slot_dimensions'] );
				if ( is_array( $dims_data ) ) {
					$dimensions = $dims_data;
				}
			}

			foreach ( $resource_dims as $res_dim ) {
				if ( isset( $dimensions[ $res_dim ] ) ) {
					$conflict_key               = $time_slot . '|' . $res_dim . ':' . (int) $dimensions[ $res_dim ];
					$conflicts[ $conflict_key ] = (int) $appt['appointment_id'];
				}
			}
		}

		return $conflicts;
	}

	/**
	 * Parse a GROUP_CONCAT dimensions string into an associative array.
	 */
	private function parse_dimensions_concat( string $concat ): array {
		$dimensions_array = [];
		if ( ! empty( $concat ) ) {
			$pairs = explode( '|||', $concat );
			foreach ( $pairs as $pair ) {
				$parts = explode( ':::', $pair, 2 );
				if ( count( $parts ) === 2 ) {
					$dimensions_array[ $parts[0] ] = $parts[1];
				}
			}
		}
		return $dimensions_array;
	}

	/**
	 * Parse raw DB results into the standard slot array format.
	 */
	private function parse_slot_results( array $results ): array {
		$processed_slots = [];
		foreach ( $results as $row ) {
			$processed_slots[] = [
				'slot_id'        => $row['slot_id'],
				'start_time_utc' => $row['start_time'],
				'end_time_utc'   => $row['end_time'],
				'is_block'       => (bool) $row['is_block'],
				'booking_count'  => (int) $row['booking_count'],
				'dimensions'     => $this->parse_dimensions_concat( $row['dimensions_concat'] ?? '' ),
			];
		}
		return $processed_slots;
	}
}
