<?php
/**
 * File: src/shared/includes/core/class-capacity-resolver.php -> client-sync/includes/core/class-capacity-resolver.php
 *
 * Resolves the effective capacity for a dimension combination.
 *
 * Instead of reading capacity from only the primary dimension,
 * this utility takes the minimum capacity across ALL dimensions
 * that have a capacity value set. This enables per-combination
 * capacity tracking without schema changes.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capacity_Resolver {

	/**
	 * Resolve the effective capacity for a dimension combination.
	 *
	 * Strategy: take the minimum `_clisyc_capacity` value across all
	 * dimensions in the combination. If no dimension has capacity set,
	 * return 1 (single-booking default).
	 *
	 * This is backward compatible: single-dimension setups where only
	 * the primary dimension has capacity will behave identically to the
	 * previous hard-coded primary-only lookup.
	 *
	 * @param array $dimensions Dimension slug => post ID pairs.
	 *                          Example: [ 'clisyc_service' => 42, 'clisyc_room' => 7 ]
	 * @return int Effective capacity (minimum 1).
	 */
	public static function resolve( array $dimensions ): int {
		if ( empty( $dimensions ) ) {
			return 1;
		}

		$min_capacity = PHP_INT_MAX;

		foreach ( $dimensions as $dim_slug => $dim_id ) {
			$dim_id = (int) $dim_id;
			if ( $dim_id <= 0 ) {
				continue;
			}

			$cap = (int) get_post_meta( $dim_id, Constants::META_CAPACITY, true );
			if ( $cap > 0 ) {
				$min_capacity = min( $min_capacity, $cap );
			}
		}

		return $min_capacity === PHP_INT_MAX ? 1 : $min_capacity;
	}
}
