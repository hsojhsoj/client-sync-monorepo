<?php
namespace DependentMedia\ClientSync\Traits;

/**
 * Shared helper for converting a UTC time-slot ID to localised date/time strings.
 *
 * Used by Appointments_Ajax_Handler (and can be adopted by Shortcodes).
 */
trait Datetime_Formatting {

	/**
	 * Convert a UTC datetime string (slot ID) into localised display values.
	 *
	 * @param string|null $time_slot_id UTC datetime string (e.g. '2025-03-15 14:00:00').
	 * @return array{ date: string, time: string, sort_date: string, sort_time: string }
	 */
	private function _get_formatted_datetime_from_slot_id( ?string $time_slot_id ): array {
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
}
