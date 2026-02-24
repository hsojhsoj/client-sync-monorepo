<?php
/**
 * File: src/shared/includes/services/class-slot-mutation-service.php
 * Write operations for slots: insert, delete, replace, and block.
 *
 * Extracted from Database_Manager. All slot mutation logic lives here.
 * After every successful mutation, the slot cache is invalidated.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Services
 */

namespace DependentMedia\ClientSync\Services;

use DependentMedia\ClientSync\Core\Table_Schema_Manager;
use DependentMedia\ClientSync\Traits\Dimension_Sql_Builder;
use DependentMedia\ClientSync\Utility\Transaction_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slot_Mutation_Service {

	use Dimension_Sql_Builder;

	/**
	 * @var Table_Schema_Manager
	 */
	private $schema;

	/**
	 * @var Slot_Query_Service
	 */
	private $query_service;

	public function __construct( Table_Schema_Manager $schema, Slot_Query_Service $query_service ) {
		$this->schema        = $schema;
		$this->query_service = $query_service;
	}

	// =========================================================================
	// INSERT
	// =========================================================================

	public function insert_slots( array $slots ): array {
		global $wpdb;

		if ( empty( $slots ) ) {
			return [ 'inserted' => 0, 'errors' => 0 ];
		}

		$stats       = [ 'inserted' => 0, 'errors' => 0 ];
		$slots_table = $this->schema->get_slots_table_name();
		$dims_table  = $this->schema->get_dimensions_table_name();

		try {
			Transaction_Manager::execute( function () use ( $wpdb, $slots, &$stats, $slots_table, $dims_table ) {
				foreach ( $slots as $slot ) {
					if ( empty( $slot['start_time_utc'] ) || empty( $slot['end_time_utc'] ) ) {
						$stats['errors']++;
						continue;
					}

					$insert_data = [
						'start_time' => $slot['start_time_utc'],
						'end_time'   => $slot['end_time_utc'],
						'is_block'   => isset( $slot['is_block'] ) && $slot['is_block'] ? 1 : 0,
					];

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( false === $wpdb->insert( $slots_table, $insert_data, [ '%s', '%s', '%d' ] ) ) {
						throw new \Exception( 'Failed to insert into slots table. DB Error: ' . $wpdb->last_error );
					}

					$new_slot_id = $wpdb->insert_id;

					if ( ! empty( $slot['dimensions'] ) && is_array( $slot['dimensions'] ) ) {
						foreach ( $slot['dimensions'] as $key => $value ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
							if ( false === $wpdb->insert(
								$dims_table,
								[
									'slot_id'         => $new_slot_id,
									'dimension_key'   => sanitize_key( $key ),
									'dimension_value' => sanitize_text_field( $value ),
								],
								[ '%d', '%s', '%s' ]
							) ) {
								throw new \Exception( 'Failed to insert into dimensions table. DB Error: ' . $wpdb->last_error );
							}
						}
					}
					$stats['inserted']++;
				}
			} );

			if ( $stats['inserted'] > 0 ) {
				$this->query_service->clear_all_slot_cache();
			}
		} catch ( \Exception $e ) {
			$stats['errors']   = count( $slots );
			$stats['inserted'] = 0;
		}

		return $stats;
	}

	// =========================================================================
	// REPLACE
	// =========================================================================

	public function replace_editable_slots_in_range( array $slots_to_insert, string $start_utc, string $end_utc ): array {
		global $wpdb;
		$stats       = [
			'inserted' => 0,
			'deleted'  => 0,
			'errors'   => 0,
		];
		$slots_table = $this->schema->get_slots_table_name();
		$dims_table  = $this->schema->get_dimensions_table_name();

		try {
			Transaction_Manager::execute( function () use ( $wpdb, $slots_to_insert, $start_utc, $end_utc, &$stats, $slots_table, $dims_table ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$query = "SELECT slot_id FROM {$slots_table} WHERE start_time >= %s AND start_time < %s";

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$slot_ids_to_delete = $wpdb->get_col(
					$wpdb->prepare( $query, $start_utc, $end_utc )
				);

				if ( ! empty( $slot_ids_to_delete ) ) {
					$ids_placeholder = implode( ',', array_fill( 0, count( $slot_ids_to_delete ), '%d' ) );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$dims_table} WHERE slot_id IN ($ids_placeholder)", $slot_ids_to_delete ) );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$deleted_count    = $wpdb->query( $wpdb->prepare( "DELETE FROM {$slots_table} WHERE slot_id IN ($ids_placeholder)", $slot_ids_to_delete ) );
					$stats['deleted'] = ( false === $deleted_count ) ? 0 : $deleted_count;

					if ( false === $deleted_count ) {
						throw new \Exception( 'Failed to delete old slots.' );
					}
				}

				foreach ( $slots_to_insert as $slot ) {
					$insert_data = [
						'start_time' => $slot['start_time_utc'],
						'end_time'   => $slot['end_time_utc'],
						'is_block'   => filter_var( $slot['is_block'] ?? false, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0,
					];

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$insert_result = $wpdb->insert( $slots_table, $insert_data, [ '%s', '%s', '%d' ] );
					if ( $insert_result ) {
						$stats['inserted']++;
						$new_slot_id = $wpdb->insert_id;
						$dimensions  = $slot['dimensions'] ?? [];
						if ( ! empty( $dimensions ) && is_array( $dimensions ) ) {
							foreach ( $dimensions as $key => $value ) {
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
								$wpdb->insert(
									$dims_table,
									[
										'slot_id'         => $new_slot_id,
										'dimension_key'   => sanitize_key( $key ),
										'dimension_value' => sanitize_text_field( $value ),
									],
									[ '%d', '%s', '%s' ]
								);
							}
						}
					} else {
						$stats['errors']++;
					}
				}

				if ( $stats['errors'] > 0 ) {
					throw new \Exception( 'Errors occurred while inserting new slots.' );
				}
			} );

			$this->query_service->clear_all_slot_cache();
		} catch ( \Exception $e ) {
			$stats['errors']++;
		}

		return $stats;
	}

	public function replace_slots_in_range_with_dimensions( array $slots_to_insert, string $start_utc, string $end_utc, array $dimensions ): array {
		$stats = [
			'inserted' => 0,
			'deleted'  => 0,
			'errors'   => 0,
		];

		try {
			Transaction_Manager::execute( function () use ( $slots_to_insert, $start_utc, $end_utc, $dimensions, &$stats ) {
				$deleted_count = $this->delete_slots_for_date_range( $start_utc, $end_utc, $dimensions );
				if ( false === $deleted_count ) {
					throw new \Exception( 'Error deleting existing slots for specified dimensions.' );
				}
				$stats['deleted'] = $deleted_count;
				if ( ! empty( $slots_to_insert ) ) {
					$insert_stats = $this->insert_slots( $slots_to_insert );
					if ( $insert_stats['errors'] > 0 ) {
						throw new \Exception( 'Errors occurred during slot insertion.' );
					}
					$stats['inserted'] = $insert_stats['inserted'];
				}
			} );

			$this->query_service->clear_all_slot_cache();
		} catch ( \Exception $e ) {
			$stats['errors']++;
		}

		return $stats;
	}

	// =========================================================================
	// DELETE
	// =========================================================================

	public function delete_slots_for_date_range( string $start_utc, string $end_utc, array $dimensions = [], ?bool $is_block_filter = null, ?array $days_of_week = null, array $exclude_slot_ids = [] ): int {
		global $wpdb;
		$wpdb->flush();
		$slots_table = $this->schema->get_slots_table_name();
		$dims_table  = $this->schema->get_dimensions_table_name();

		$where_clauses = [];
		$params        = [];

		$where_clauses[] = 's.start_time >= %s';
		$params[]        = $start_utc;
		$where_clauses[] = 's.start_time < %s';
		$params[]        = $end_utc;
		if ( null !== $is_block_filter ) {
			$where_clauses[] = 's.is_block = %d';
			$params[]        = $is_block_filter ? 1 : 0;
		}
		if ( ! empty( $dimensions ) ) {
			$dim_match_data = $this->build_exact_dimension_match_sql( $dimensions, 's' );
			if ( ! empty( $dim_match_data['sql'] ) ) {
				$where_clauses[] = $dim_match_data['sql'];
				$params          = array_merge( $params, $dim_match_data['params'] );
			}
		}
		if ( ! empty( $days_of_week ) && is_array( $days_of_week ) ) {
			$valid_days = array_filter( array_map( 'intval', $days_of_week ), fn( $day ) => $day >= 0 && $day <= 6 );
			if ( ! empty( $valid_days ) ) {
				$mysql_days       = array_map( fn( $php_day ) => $php_day + 1, $valid_days );
				$day_placeholders = implode( ',', array_fill( 0, count( $mysql_days ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where_clauses[] = "DAYOFWEEK(s.start_time) IN ({$day_placeholders})";
				$params          = array_merge( $params, $mysql_days );
			}
		}

		if ( ! empty( $exclude_slot_ids ) ) {
			$exclude_slot_ids     = array_map( 'intval', $exclude_slot_ids );
			$exclude_placeholders = implode( ',', array_fill( 0, count( $exclude_slot_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where_clauses[] = "s.slot_id NOT IN ({$exclude_placeholders})";
			$params          = array_merge( $params, $exclude_slot_ids );
		}

		$where_sql = implode( ' AND ', $where_clauses );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql_select_ids = "SELECT s.slot_id FROM {$slots_table} s WHERE {$where_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$slot_ids_to_delete = $wpdb->get_col( $wpdb->prepare( $sql_select_ids, ...$params ) );

		if ( empty( $slot_ids_to_delete ) ) {
			return 0;
		}

		$ids_placeholder = implode( ',', array_fill( 0, count( $slot_ids_to_delete ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$dims_table} WHERE slot_id IN ($ids_placeholder)", $slot_ids_to_delete ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$deleted_count_main_table = $wpdb->query( $wpdb->prepare( "DELETE FROM {$slots_table} WHERE slot_id IN ($ids_placeholder)", $slot_ids_to_delete ) );

		if ( $deleted_count_main_table > 0 ) {
			$this->query_service->clear_all_slot_cache();
		}
		return (int) $deleted_count_main_table;
	}

	public function delete_past_available_slots(): int {
		$utc_now = gmdate( 'Y-m-d H:i:s' );
		return $this->delete_slots_for_date_range( '1970-01-01 00:00:00', $utc_now, [], false, null );
	}

	public function delete_all_future_available_slots(): int {
		$utc_now = gmdate( 'Y-m-d H:i:s' );
		return $this->delete_slots_for_date_range( $utc_now, '9999-12-31 23:59:59', [], false, null );
	}

	public function clear_unrepresented_slots_in_range( string $start_utc, string $end_utc, array $represented_dimension_groups ): void {
		global $wpdb;
		$slots_table = $this->schema->get_slots_table_name();
		$dims_table  = $this->schema->get_dimensions_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql_get_existing_groups = "
            SELECT s.slot_id, GROUP_CONCAT(CONCAT(d.dimension_key, ':::', d.dimension_value) ORDER BY d.dimension_key SEPARATOR '|||') AS dimensions_concat
            FROM {$slots_table} s
            LEFT JOIN {$dims_table} d ON s.slot_id = d.slot_id
            WHERE s.start_time >= %s AND s.start_time < %s
            GROUP BY s.slot_id";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$existing_slots_in_range = $wpdb->get_results(
			$wpdb->prepare( $sql_get_existing_groups, $start_utc, $end_utc ),
			OBJECT_K
		);

		if ( empty( $existing_slots_in_range ) ) {
			return;
		}

		$represented_hashes = [];
		foreach ( $represented_dimension_groups as $dim_group ) {
			ksort( $dim_group );
			$represented_hashes[ http_build_query( $dim_group ) ] = true;
		}

		$ids_to_delete = [];
		foreach ( $existing_slots_in_range as $slot_id => $slot_data ) {
			$existing_dims_array = [];
			if ( ! empty( $slot_data->dimensions_concat ) ) {
				$pairs = explode( '|||', $slot_data->dimensions_concat );
				foreach ( $pairs as $pair ) {
					$parts = explode( ':::', $pair, 2 );
					if ( 2 === count( $parts ) ) {
						$existing_dims_array[ $parts[0] ] = $parts[1];
					}
				}
			}
			ksort( $existing_dims_array );
			$existing_hash = http_build_query( $existing_dims_array );

			if ( ! isset( $represented_hashes[ $existing_hash ] ) ) {
				$ids_to_delete[] = (int) $slot_id;
			}
		}

		if ( ! empty( $ids_to_delete ) ) {
			$ids_placeholder = implode( ',', array_fill( 0, count( $ids_to_delete ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$sql_delete_dims = sprintf( "DELETE FROM %s WHERE slot_id IN (%s)", $dims_table, $ids_placeholder );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( $wpdb->prepare( $sql_delete_dims, $ids_to_delete ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$sql_delete_slots = sprintf( "DELETE FROM %s WHERE slot_id IN (%s)", $slots_table, $ids_placeholder );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( $wpdb->prepare( $sql_delete_slots, $ids_to_delete ) );

			$this->query_service->clear_all_slot_cache();
		}
	}

	// =========================================================================
	// BLOCKING (two-way sync)
	// =========================================================================

	public function block_overlapping_slots( string $start_utc_str, string $end_utc_str, array $dimensions ) {
		global $wpdb;

		$slots_table   = $this->schema->get_slots_table_name();
		$where_clauses = [
			's.is_block = 0',
			's.start_time < %s',
			's.end_time > %s',
		];
		$params        = [ $end_utc_str, $start_utc_str ];

		$dim_match_data = $this->build_exact_dimension_match_sql( $dimensions, 's' );
		if ( ! empty( $dim_match_data['sql'] ) ) {
			$where_clauses[] = $dim_match_data['sql'];
			$params          = array_merge( $params, $dim_match_data['params'] );
		} else {
			return 0;
		}

		$where_sql = implode( ' AND ', $where_clauses );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql_select_ids = "SELECT s.slot_id FROM {$slots_table} s WHERE {$where_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$slot_ids_to_block = $wpdb->get_col( $wpdb->prepare( $sql_select_ids, ...$params ) );

		if ( empty( $slot_ids_to_block ) ) {
			return 0;
		}

		$ids_placeholder = implode( ',', array_fill( 0, count( $slot_ids_to_block ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$query = "UPDATE {$slots_table} SET is_block = 1 WHERE slot_id IN ($ids_placeholder)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$update_result = $wpdb->query( $wpdb->prepare( $query, ...$slot_ids_to_block ) );

		if ( $update_result ) {
			$this->query_service->clear_all_slot_cache();
		}
		return $update_result;
	}
}
