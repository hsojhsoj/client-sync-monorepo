<?php
/**
 * File: src/shared/includes/traits/trait-dimension-sql-builder.php
 * Shared SQL builder for exact dimension matching queries.
 *
 * This trait provides the dimension matching subquery used by multiple
 * services to filter slots by their associated dimensions.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Traits
 */

namespace DependentMedia\ClientSync\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Dimension_Sql_Builder {

	/**
	 * Build a SQL condition and parameter array for exact dimension matching.
	 *
	 * Returns a subquery that finds slot_ids matching ALL provided dimensions.
	 *
	 * @param array  $dimensions Associative array of dimension_key => dimension_value.
	 * @param string $alias      The table alias for the slots table (e.g. 's').
	 * @return array{sql: string, params: array} The SQL fragment and its bound parameters.
	 */
	protected function build_exact_dimension_match_sql( array $dimensions, string $alias ): array {
		if ( empty( $dimensions ) ) {
			return [ 'sql' => '', 'params' => [] ];
		}

		global $wpdb;
		$dims_table = $wpdb->prefix . 'clisyc_slot_dimensions';
		$params     = [];
		$dim_count  = count( $dimensions );

		$subquery_parts = [];
		foreach ( $dimensions as $key => $value ) {
			$subquery_parts[] = "(d.dimension_key = %s AND d.dimension_value = %s)";
			$params[]         = sanitize_key( $key );
			$params[]         = sanitize_text_field( $value );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
		$sql      = "{$alias}.slot_id IN (
            SELECT d.slot_id FROM {$dims_table} d
            WHERE " . implode( ' OR ', $subquery_parts ) . "
            GROUP BY d.slot_id
            HAVING COUNT(DISTINCT d.dimension_key) = %d
        )";
		$params[] = $dim_count;

		return [ 'sql' => $sql, 'params' => $params ];
	}
}
