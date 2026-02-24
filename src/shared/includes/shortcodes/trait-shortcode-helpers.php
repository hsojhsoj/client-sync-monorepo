<?php
/**
 * Shared helper methods for shortcode classes.
 *
 * Provides date/time formatting utilities used across multiple shortcode
 * classes and the Manager_Shortcodes class.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Shortcode_Helpers {

	/**
	 * Parse a UTC time_slot string into a localized date/time array.
	 *
	 * @param string|null $time_slot_id UTC datetime string (e.g. '2026-02-18 19:00:00').
	 * @return array { date: string, time: string, sort_date: string, sort_time: string }
	 */
	public function format_datetime_from_slot_id( ?string $time_slot_id ): array {
		$defaults = [
			'date'      => __( 'N/A', 'client-sync' ),
			'time'      => __( 'N/A', 'client-sync' ),
			'sort_date' => '',
			'sort_time' => '',
		];

		if ( empty( $time_slot_id ) ) {
			return $defaults;
		}

		try {
			$datetime_local = new \DateTime( $time_slot_id, new \DateTimeZone( 'UTC' ) );
			$datetime_local->setTimezone( wp_timezone() );

			return [
				'date'      => wp_date( get_option( 'date_format' ), $datetime_local->getTimestamp() ),
				'time'      => wp_date( get_option( 'time_format' ), $datetime_local->getTimestamp() ),
				'sort_date' => $datetime_local->format( 'Y-m-d' ),
				'sort_time' => $datetime_local->format( 'H:i' ),
			];

		} catch ( \Exception $e ) {
			return $defaults;
		}
	}

	/**
	 * Format a date range for display.
	 *
	 * @param string $start_date Start date string.
	 * @param string $end_date   End date string.
	 * @return string Formatted date range.
	 */
	public function format_date_range( string $start_date, string $end_date ): string {
		$date_format = get_option( 'date_format' );
		try {
			$start_dt = new \DateTime( $start_date, wp_timezone() );
			$end_dt   = new \DateTime( $end_date, wp_timezone() );
			$formatted_start = wp_date( $date_format, $start_dt->getTimestamp() );
			$formatted_end   = wp_date( $date_format, $end_dt->getTimestamp() );
			if ( $start_date === $end_date ) {
				return $formatted_start;
			}
			if ( $start_dt->format( 'Y-m' ) === $end_dt->format( 'Y-m' ) ) {
				return wp_date( 'F j', $start_dt->getTimestamp() ) . ' - ' . wp_date( 'j, Y', $end_dt->getTimestamp() );
			}
			return $formatted_start . ' - ' . $formatted_end;
		} catch ( \Exception $e ) {
			return $start_date . ' - ' . $end_date;
		}
	}
}
