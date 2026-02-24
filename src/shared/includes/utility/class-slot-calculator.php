<?php
/**
 * File: src/shared/includes/utility/class-slot-calculator.php -> client-sync/includes/utility/class-slot-calculator.php
 * A collection of static methods for calculating and manipulating time slot arrays.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Utility
 */

namespace DependentMedia\ClientSync\Utility;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SlotCalculator {

    public static function calculate_slots( string $workday_start, string $workday_end, int $duration, string $break_start = '', string $break_end = '', int $buffer_before = 0, int $buffer_after = 0, int $padding = 0 ): array {
        $slots = [];
        if ( $duration <= 0 || ! preg_match( '/^\d{2}:\d{2}$/', $workday_start ) || ! preg_match( '/^\d{2}:\d{2}$/', $workday_end ) ) {
            return [];
        }
        $buffer_before = max( 0, intval( $buffer_before ) );
        $buffer_after  = max( 0, intval( $buffer_after ) );
        $padding       = max( 0, intval( $padding ) );

        try {
            $timezone   = wp_timezone();
            $dummy_date = '1970-01-01';
            $start      = new \DateTime( $dummy_date . ' ' . $workday_start, $timezone );

            if ( '24:00' === $workday_end ) {
                $end = new \DateTime( $dummy_date . ' 00:00', $timezone );
                $end->modify( '+1 day' );
            } else {
                $end = new \DateTime( $dummy_date . ' ' . $workday_end, $timezone );
            }

            if ( $start >= $end ) {
				return [];
			}

            $service_duration_interval = new \DateInterval( 'PT' . intval( $duration ) . 'M' );
            $current_potential_start = clone $start;
            $break_start_dt          = null;
            $break_end_dt            = null;

            if ( ! empty( $break_start ) && ! empty( $break_end ) && preg_match( '/^\d{2}:\d{2}$/', $break_start ) && preg_match( '/^\d{2}:\d{2}$/', $break_end ) ) {
                $bs = new \DateTime( $dummy_date . ' ' . $break_start, $timezone );
                $be = new \DateTime( $dummy_date . ' ' . $break_end, $timezone );
                if ( $be > $bs ) {
					$break_start_dt = $bs;
					$break_end_dt   = $be;
				}
            }

            while ( true ) {
                $actual_slot_start = ( clone $current_potential_start )->modify( '+ ' . $buffer_before . ' minutes' );
                $service_end_time    = ( clone $actual_slot_start )->add( $service_duration_interval );

                $total_gap_after           = $buffer_after + $padding;
                $next_potential_slot_start = ( clone $service_end_time )->modify( '+ ' . $total_gap_after . ' minutes' );

                if ( $service_end_time > $end ) {
					break;
				}

                $slot_start_formatted = $actual_slot_start->format( 'H:i' );
                $slot_end_formatted   = $service_end_time->format( 'H:i' );

                $overlaps_break = false;
                if ( $break_start_dt && $break_end_dt && ( $actual_slot_start < $break_end_dt && $service_end_time > $break_start_dt ) ) {
                    $overlaps_break = true;
                }

                if ( ! $overlaps_break ) {
                    $slots[] = [ 'start' => $slot_start_formatted, 'end' => $slot_end_formatted ];
                }

                $current_potential_start = $next_potential_slot_start;
            }
        } catch ( \Exception $e ) {
			return [];
		}
        return $slots;
    }

    public static function create_specific_date_slots_from_template( string $date_string, array $template_blocks, array $busy_intervals, \DateTimeZone $timezone, int $duration, int $padding, int $buffer_before, int $buffer_after ): array {
        Debug_Logger::log( ">> Running create_specific_date_slots_from_template for {$date_string}", 'SlotCalc' );
        Debug_Logger::log( '   - Received ' . count( $template_blocks ) . ' template blocks.', 'SlotCalc' );
        Debug_Logger::log( "   - Duration: {$duration}, Padding: {$padding}, Buffer Before: {$buffer_before}, Buffer After: {$buffer_after}", 'SlotCalc' );

        $new_slots_for_date = [];
        if ( empty( $template_blocks ) ) {
            Debug_Logger::log( '   - EXIT: No template blocks provided.', 'SlotCalc' );
            return [];
        }

        $all_potential_slots = [];
        foreach ( $template_blocks as $block ) {
            if ( empty( $block['start'] ) || empty( $block['end'] ) ) {
                continue;
            }

            // If no valid duration is provided, fall back to the global slot duration setting.
            if ( $duration <= 0 ) {
                $global_slot_dur = get_option( Constants::OPTION_CALENDAR_SLOT_DURATION, '01:00:00' );
                if ( preg_match( '/^(\d{2}):(\d{2})/', $global_slot_dur, $m ) ) {
                    $duration = ( (int) $m[1] * 60 ) + (int) $m[2];
                } else {
                    $duration = 60;
                }
                $duration = max( 15, $duration ); // Floor at 15 min to prevent runaway subdivision.
                Debug_Logger::log( "   - Duration was 0; using fallback: {$duration} min.", 'SlotCalc' );
            }

            $subdivided = self::calculate_slots( $block['start'], $block['end'], $duration, '', '', $buffer_before, $buffer_after, $padding );
            Debug_Logger::log( "   - Subdividing block {$block['start']}-{$block['end']} into " . count( $subdivided ) . ' slots.', 'SlotCalc' );
            $all_potential_slots = array_merge( $all_potential_slots, $subdivided );
        }
        Debug_Logger::log( '   - Total potential slots after subdivision: ' . count( $all_potential_slots ), 'SlotCalc' );

        $utc_timezone = new \DateTimeZone( 'UTC' );

        foreach ( $all_potential_slots as $potential_slot ) {
            try {
                $slot_start_site = new \DateTime( $date_string . ' ' . $potential_slot['start'], $timezone );
                $slot_end_site   = new \DateTime( $date_string . ' ' . $potential_slot['end'], $timezone );

                if ( $slot_start_site >= $slot_end_site ) {
                    continue; // Invalid slot
                }

                $is_conflicting = false;
                if ( ! empty( $busy_intervals ) ) {
                    $slot_start_utc = ( clone $slot_start_site )->setTimezone( $utc_timezone );
                    $slot_end_utc   = ( clone $slot_end_site )->setTimezone( $utc_timezone );

                    foreach ( $busy_intervals as $busy ) {
                        if ( $slot_start_utc < $busy['end'] && $slot_end_utc > $busy['start'] ) {
                            $is_conflicting = true;
                            Debug_Logger::log( "   - CONFLICT FOUND: Slot {$potential_slot['start']}-{$potential_slot['end']} conflicts with a busy interval.", 'SlotCalc' );
                            break;
                        }
                    }
                }

                if ( ! $is_conflicting ) {
                    $new_slots_for_date[] = [
                        'date'       => $date_string,
                        'start_time' => $potential_slot['start'],
                        'end_time'   => $potential_slot['end'],
                    ];
                }
            } catch ( \Exception $e ) {
                continue;
            }
        }
        Debug_Logger::log( '<< Finished. Returning ' . count( $new_slots_for_date ) . ' valid, non-conflicting slots.', 'SlotCalc' );
        return $new_slots_for_date;
    }

    public static function sort_slots_callback( array $a, array $b ): int {
        $date_a = $a['date'] ?? '9999-99-99';
        $date_b = $b['date'] ?? '9999-99-99';
        $time_a = $a['start_time'] ?? '99:99';
        $time_b = $b['start_time'] ?? '99:99';
        $date_cmp = strcmp( $date_a, $date_b );
        if ( 0 !== $date_cmp ) return $date_cmp;
        return strcmp( $time_a, $time_b );
    }

    public static function deduplicate_slots( array $slots ): array {
        $unique_slots = [];
        $seen_keys = [];
        foreach ( $slots as $slot ) {
            if ( is_array( $slot ) && isset( $slot['date'], $slot['start_time'] ) ) {
                $key_parts = [ $slot['date'], $slot['start_time'] ];
                if ( isset( $slot['dimensions'] ) && is_array( $slot['dimensions'] ) && ! empty( $slot['dimensions'] ) ) {
                    $sorted_dimensions = $slot['dimensions'];
                    ksort( $sorted_dimensions );
                    foreach ( $sorted_dimensions as $dim_key => $dim_value ) {
                        $key_parts[] = $dim_key; $key_parts[] = $dim_value;
                    }
                }
                $key_parts[] = ( isset( $slot['is_block'] ) && true === $slot['is_block'] ) ? 'blocked' : 'available';
                $unique_key_string = implode( '_', $key_parts );
                if ( ! isset( $seen_keys[ $unique_key_string ] ) ) {
                    $unique_slots[] = $slot;
                    $seen_keys[ $unique_key_string ] = true;
                }
            }
        }
        return $unique_slots;
    }
}