<?php
/**
 * File: src/shared/includes/services/class-price-calculator.php -> client-sync/includes/services/class-price-calculator.php
 * A service class to handle dynamic price calculations for multi-day bookings.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Services
 */

namespace DependentMedia\ClientSync\Services;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Price_Calculator {

    public function calculate_price( int $product_id, string $start_date_str, string $end_date_str ): ?float {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return null;
        }

        $base_price = (float) $product->get_price( 'edit' );
        $rules = get_post_meta( $product_id, Constants::META_PRICING_RULES, true );

        try {
            $start_date = new \DateTime( $start_date_str );
            $end_date   = new \DateTime( $end_date_str );
            $interval   = new \DateInterval( 'P1D' );
            $period     = new \DatePeriod( $start_date, $interval, $end_date );
            $num_nights = iterator_count( $period );

            if ( empty( $rules ) || ! is_array( $rules ) ) {
                return $base_price;
            }

            $seasonal_rules = array_filter( $rules, fn($r) => 'seasonal' === ( $r['type'] ?? '' ) );
            $dow_rules      = array_filter( $rules, fn($r) => 'dow' === ( $r['type'] ?? '' ) );
            $los_rules      = array_filter( $rules, fn($r) => 'los' === ( $r['type'] ?? '' ) );

            $daily_total = 0;

            foreach ( $period as $day ) {
                $daily_price = $base_price;
                $day_of_week_str = (string) $day->format( 'w' ); // Use string for reliable in_array checks

                // --- START: CORRECTED LOGIC ---
                // Apply seasonal rules first
                foreach ( $seasonal_rules as $rule ) {
                    if ( ! empty( $rule['start'] ) && ! empty( $rule['end'] ) ) {
                        $rule_start = new \DateTime( $rule['start'] );
                        $rule_end   = new \DateTime( $rule['end'] );
                        if ( $day >= $rule_start && $day <= $rule_end ) {
                            $daily_price = $this->apply_rule_adjustment( $daily_price, $rule['mode'], $rule['value'] );
                            // If a 'set' rule matches, we break out of the seasonal loop for this day.
                            if ( 'set' === $rule['mode'] ) {
                                break;
                            }
                        }
                    }
                }

                // THEN, apply day-of-week rules to the (potentially modified) price
                foreach ( $dow_rules as $rule ) {
                    if ( ! empty( $rule['days'] ) && is_array( $rule['days'] ) && in_array( $day_of_week_str, $rule['days'], true ) ) {
                        $daily_price = $this->apply_rule_adjustment( $daily_price, $rule['mode'], $rule['value'] );
                    }
                }
                // --- END: CORRECTED LOGIC ---

                $daily_total += $daily_price;
            }

            // Finally, apply length-of-stay rules to the grand total
            foreach ( $los_rules as $rule ) {
                $min_days = (int) ( $rule['min_days'] ?? 0 );
                $max_days = (int) ( $rule['max_days'] ?? 0 );

                if ( $num_nights >= $min_days && ( $max_days === 0 || $num_nights <= $max_days ) ) {
                    $daily_total = $this->apply_rule_adjustment( $daily_total, $rule['mode'], $rule['value'], true );
                }
            }

            if ( $num_nights > 0 ) {
                return $daily_total / $num_nights;
            }

            return $base_price; // Fallback

        } catch ( \Exception $e ) {
            return null;
        }
    }

    private function apply_rule_adjustment( float $price, string $mode, $value, bool $is_total_adjustment = false ): float {
        $value = (float) $value;
        switch ( $mode ) {
            case 'set':
                return $value; // 'set' on total isn't logical for LOS, but handled. Sets daily price.
            case 'add':
                return $price + $value;
            case 'subtract':
                return max( 0, $price - $value );
            case 'add_percent':
                return $price * ( 1 + ( $value / 100 ) );
            case 'subtract_percent':
                return $price * ( 1 - ( $value / 100 ) );
        }
        return $price;
    }
}