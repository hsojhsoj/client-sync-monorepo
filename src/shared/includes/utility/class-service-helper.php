<?php
/**
 * File: src/shared/includes/utility/class-service-helper.php
 *
 * Static helpers for reading service/appointment post meta with consistent
 * defaulting. Created to fix a class of bug where the admin UI and various
 * readers disagreed about the effective value of the appointment duration
 * when the _clisyc_duration_minutes meta was unset.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Utility
 */

namespace DependentMedia\ClientSync\Utility;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Helper {

	/**
	 * Default appointment duration in minutes when a service/appointment
	 * has no explicit value stored. Matches the admin UI's displayed default.
	 */
	const DEFAULT_DURATION_MINUTES = 60;

	/**
	 * Resolve the effective appointment duration for a service or appointment
	 * post, in minutes.
	 *
	 * Historical note: prior to centralization, individual callsites used
	 * inconsistent fallbacks — some `?: 60`, one `?: 30`, others no fallback
	 * at all — and two files read the wrong meta key entirely
	 * (`_clisyc_duration` instead of `_clisyc_duration_minutes`). This helper
	 * consolidates those readers so they all see the same effective value.
	 *
	 * @param int $post_id Post ID of the service or appointment.
	 * @param int $default Fallback to return when the meta is unset or <= 0.
	 *                    Defaults to DEFAULT_DURATION_MINUTES (60).
	 *                    Pass 0 to signal "not set" to the caller (useful for
	 *                    display code that wants to hide the duration when
	 *                    missing).
	 * @return int Duration in minutes.
	 */
	public static function get_duration_minutes( int $post_id, int $default = self::DEFAULT_DURATION_MINUTES ): int {
		$raw = get_post_meta( $post_id, Constants::META_DURATION_MINUTES, true );
		if ( '' === $raw || false === $raw ) {
			return $default;
		}
		$minutes = (int) $raw;
		return $minutes > 0 ? $minutes : $default;
	}

	/**
	 * Whether the given post has an explicit positive duration stored.
	 * Useful to distinguish "defaulted" from "configured" in admin UIs.
	 *
	 * @param int $post_id Post ID of the service or appointment.
	 */
	public static function has_duration( int $post_id ): bool {
		$raw = get_post_meta( $post_id, Constants::META_DURATION_MINUTES, true );
		if ( '' === $raw || false === $raw ) {
			return false;
		}
		return (int) $raw > 0;
	}
}
