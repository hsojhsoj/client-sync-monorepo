<?php
/**
 * File: src/shared/includes/services/class-dimension-query-service.php
 * Dimension relationship queries and validation.
 *
 * Extracted from Database_Manager. Handles dimension relationship lookups,
 * Cartesian product generation, and relationship data repair.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Services
 */

namespace DependentMedia\ClientSync\Services;

use DependentMedia\ClientSync\Core\Table_Schema_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dimension_Query_Service {

	/**
	 * @var Table_Schema_Manager
	 */
	private $schema;

	/**
	 * Per-request memoization cache for get_linked_child_ids().
	 * Avoids redundant queries in the O(n²) loop of get_valid_dimension_combinations().
	 *
	 * @var array<string, int[]>
	 */
	private static $linked_ids_cache = [];

	public function __construct( Table_Schema_Manager $schema ) {
		$this->schema = $schema;
	}

	/**
	 * Get child/parent IDs linked to a given object via the relationships table.
	 * Results are memoized per-request to avoid redundant queries.
	 *
	 * @param int    $parent_id      The object ID to look up.
	 * @param string $child_cpt_slug The CPT slug of the related dimension.
	 * @return int[] Array of related object IDs.
	 */
	public function get_linked_child_ids( int $parent_id, string $child_cpt_slug ): array {
		$cache_key = $parent_id . ':' . $child_cpt_slug;
		if ( isset( self::$linked_ids_cache[ $cache_key ] ) ) {
			return self::$linked_ids_cache[ $cache_key ];
		}

		global $wpdb;
		$table_name = $this->schema->get_relationships_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$query = "(SELECT child_object_id FROM {$table_name} WHERE parent_object_id = %d AND child_object_type = %s)
			 UNION
			 (SELECT parent_object_id FROM {$table_name} WHERE child_object_id = %d AND parent_object_type = %s)";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_col(
			$wpdb->prepare(
				$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$parent_id,
				$child_cpt_slug,
				$parent_id,
				$child_cpt_slug
			)
		);

		$mapped = array_map( 'absint', $results );
		self::$linked_ids_cache[ $cache_key ] = $mapped;
		return $mapped;
	}

	/**
	 * Generate all valid combinations of selected dimensions based on their relationships.
	 *
	 * @param array $dimensions Selected dimensions, e.g., ['service' => [1,2], 'practitioner' => [3,4]].
	 * @return array An array of valid combinations, e.g., [['service' => 1, 'practitioner' => 3]].
	 */
	public function get_valid_dimension_combinations( array $dimensions ): array {
		$cartesian_product  = $this->cartesian_product( $dimensions );
		$valid_combinations = [];

		foreach ( $cartesian_product as $combo ) {
			if ( count( $combo ) <= 1 ) {
				$valid_combinations[] = $combo;
				continue;
			}

			$is_fully_interlinked = true;
			$combo_items          = array_values( $combo );
			$combo_slugs          = array_keys( $combo );

			for ( $i = 0; $i < count( $combo_items ); $i++ ) {
				for ( $j = $i + 1; $j < count( $combo_items ); $j++ ) {
					$item1_id   = $combo_items[ $i ];
					$item2_slug = $combo_slugs[ $j ];
					$item2_id   = $combo_items[ $j ];

					$linked_to_item1 = $this->get_linked_child_ids( $item1_id, $item2_slug );
					if ( ! in_array( $item2_id, $linked_to_item1, true ) ) {
						$is_fully_interlinked = false;
						break 2;
					}
				}
			}

			if ( $is_fully_interlinked ) {
				$valid_combinations[] = $combo;
			}
		}

		return $valid_combinations;
	}

	/**
	 * Repair relationships with missing parent/child object types.
	 *
	 * @return array{repaired: int, unrepaired: int}
	 */
	public function repair_missing_relationship_types(): array {
		global $wpdb;
		$rels_table = $this->schema->get_relationships_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$corrupt_rows = $wpdb->get_results( "SELECT * FROM {$rels_table} WHERE parent_object_type = '' OR child_object_type = ''" );

		if ( empty( $corrupt_rows ) ) {
			return [ 'repaired' => 0, 'unrepaired' => 0 ];
		}

		$repaired_count   = 0;
		$unrepaired_count = 0;
		$post_type_cache  = [];

		foreach ( $corrupt_rows as $row ) {
			$parent_type = $row->parent_object_type;
			$child_type  = $row->child_object_type;
			$update_data = [];

			if ( empty( $parent_type ) ) {
				if ( ! isset( $post_type_cache[ $row->parent_object_id ] ) ) {
					$post_type_cache[ $row->parent_object_id ] = get_post_type( $row->parent_object_id );
				}
				$found_parent_type = $post_type_cache[ $row->parent_object_id ];
				if ( $found_parent_type ) {
					$update_data['parent_object_type'] = $found_parent_type;
				}
			}

			if ( empty( $child_type ) ) {
				if ( ! isset( $post_type_cache[ $row->child_object_id ] ) ) {
					$post_type_cache[ $row->child_object_id ] = get_post_type( $row->child_object_id );
				}
				$found_child_type = $post_type_cache[ $row->child_object_id ];
				if ( $found_child_type ) {
					$update_data['child_object_type'] = $found_child_type;
				}
			}

			if ( ! empty( $update_data ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $rels_table, $update_data, [ 'relationship_id' => $row->relationship_id ] );
				$repaired_count++;
			} else {
				$unrepaired_count++;
			}
		}

		return [ 'repaired' => $repaired_count, 'unrepaired' => $unrepaired_count ];
	}

	// =========================================================================
	// PRIVATE HELPERS
	// =========================================================================

	/**
	 * Computes the Cartesian product of an associative array of arrays.
	 * Includes a safety circuit breaker to prevent memory exhaustion.
	 *
	 * @param array $arrays Input arrays, e.g., ['color' => ['red', 'blue'], 'size' => ['s', 'm']].
	 * @return array The Cartesian product, e.g., [['color' => 'red', 'size' => 's'], ...].
	 */
	private function cartesian_product( array $arrays ): array {
		if ( empty( $arrays ) ) {
			return [];
		}

		$estimated_count = 1;
		foreach ( $arrays as $arr ) {
			$estimated_count *= count( $arr );
		}

		if ( $estimated_count > 2000 ) {
			return [];
		}

		$result = [ [] ];
		foreach ( $arrays as $key => $values ) {
			$temp = [];
			foreach ( $result as $product ) {
				foreach ( $values as $value ) {
					$product[ $key ] = $value;
					$temp[]          = $product;
				}
			}
			if ( ! empty( $temp ) ) {
				$result = $temp;
			}
		}
		return $result;
	}
}
