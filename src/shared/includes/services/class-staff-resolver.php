<?php
/**
 * File: src/shared/includes/services/class-staff-resolver.php
 * Maps a WordPress user to their personnel dimension post.
 *
 * Used by the Staff Dashboard to determine which appointments
 * belong to the currently logged-in staff member.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Services
 */

namespace DependentMedia\ClientSync\Services;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Staff_Resolver {

	/**
	 * Get the personnel dimension post ID for a given WordPress user.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return int Personnel dimension post ID, or 0 if not found.
	 */
	public static function get_staff_post_id( ?int $user_id = null ): int {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return 0;
		}

		// Check object cache first.
		$cache_key = 'staff_post_id_' . $user_id;
		$cached    = wp_cache_get( $cache_key, 'clisyc_staff' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$personnel_slug = get_option( Constants::OPTION_PERSONNEL_DIM_SLUG, '' );
		if ( empty( $personnel_slug ) ) {
			wp_cache_set( $cache_key, 0, 'clisyc_staff' );
			return 0;
		}

		$query = new \WP_Query( [
			'post_type'      => $personnel_slug,
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'meta_query'     => [
				[
					'key'   => Constants::META_EMPLOYEE_USER_ID,
					'value' => $user_id,
					'type'  => 'NUMERIC',
				],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$staff_post_id = ! empty( $query->posts ) ? (int) $query->posts[0] : 0;

		wp_cache_set( $cache_key, $staff_post_id, 'clisyc_staff' );

		return $staff_post_id;
	}

	/**
	 * Get the display name for a staff member.
	 *
	 * @param int $staff_post_id Personnel dimension post ID.
	 * @return string Staff display name.
	 */
	public static function get_staff_display_name( int $staff_post_id ): string {
		if ( ! $staff_post_id ) {
			return '';
		}
		return get_the_title( $staff_post_id ) ?: '';
	}

	/**
	 * Check whether a user is linked to a personnel dimension post.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return bool True if the user is a staff member.
	 */
	public static function is_staff( ?int $user_id = null ): bool {
		return self::get_staff_post_id( $user_id ) > 0;
	}

	/**
	 * Verify that a given staff_post_id actually belongs to the given user.
	 *
	 * @param int $staff_post_id The staff post ID to verify.
	 * @param int|null $user_id  WordPress user ID. Defaults to current user.
	 * @return bool True if the staff post belongs to the user.
	 */
	public static function verify_ownership( int $staff_post_id, ?int $user_id = null ): bool {
		return self::get_staff_post_id( $user_id ) === $staff_post_id;
	}

	/**
	 * Check whether a staff member owns a specific appointment.
	 *
	 * Looks at the appointment's dimension meta to see if the personnel
	 * dimension matches the given staff_post_id.
	 *
	 * @param int $appointment_id The appointment post ID.
	 * @param int $staff_post_id  The staff personnel dimension post ID.
	 * @return bool True if the appointment belongs to the staff member.
	 */
	public static function owns_appointment( int $appointment_id, int $staff_post_id ): bool {
		if ( ! $appointment_id || ! $staff_post_id ) {
			return false;
		}

		$personnel_slug = get_option( Constants::OPTION_PERSONNEL_DIM_SLUG, '' );
		if ( empty( $personnel_slug ) ) {
			return false;
		}

		$dimensions = get_post_meta( $appointment_id, 'clisyc_slot_dimensions', true );
		if ( ! is_array( $dimensions ) ) {
			return false;
		}

		return isset( $dimensions[ $personnel_slug ] ) && (int) $dimensions[ $personnel_slug ] === $staff_post_id;
	}
}
