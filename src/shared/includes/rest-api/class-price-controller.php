<?php
/**
 * File: client-sync/includes/rest-api/class-price-controller.php
 * Defines the REST API endpoint for dynamic price calculations.
 *
 * @package    ClientSync
 * @subpackage ClientSync/RestAPI
 */

namespace DependentMedia\ClientSync\RestAPI;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Services\Price_Calculator;
use DependentMedia\ClientSync\Utility\Debug_Logger;
use DependentMedia\ClientSync\Utility\Security_Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Price_Controller extends \WP_REST_Controller {

    protected $namespace = 'clisyc/v1';
    protected $rest_base = 'price';

    public function register_routes() {
        // Route 1: Simple Price Calculation
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_price' ],
                    'permission_callback' => '__return_true',
                    'args'                => [
                        'product_id' => [ 'required' => true, 'validate_callback' => [ $this, 'is_valid_numeric_id' ] ],
                        'start_date' => [ 'required' => true, 'validate_callback' => [ $this, 'is_valid_date' ] ],
                        'end_date'   => [ 'required' => true, 'validate_callback' => [ $this, 'is_valid_date' ] ],
                    ],
                    'schema' => [ $this, 'get_public_item_schema' ],
                ],
            ]
        );

        // Route 2: Detailed Basket Calculation
        // Handles server-side math, applying dynamic pricing rules (seasonal/LOS) and summing up atomic parts.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/calculate-basket',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'calculate_basket_total' ],
                    'permission_callback' => '__return_true',
                    'args'                => [
                        'primary_id'    => [ 'required' => true, 'validate_callback' => [ $this, 'is_valid_numeric_id' ] ],
                        'dimension_ids' => [
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                            'validate_callback' => function( $param ) {
                                if ( empty( $param ) ) {
                                    return true;
                                }
                                return (bool) preg_match( '/^[\d,]+$/', $param );
                            },
                        ],
                        'start_date'    => [ 'default' => '', 'validate_callback' => [ $this, 'is_valid_date_or_empty' ] ],
                        'end_date'      => [ 'default' => '', 'validate_callback' => [ $this, 'is_valid_date_or_empty' ] ],
                        'duration'      => [ 'default' => 60, 'sanitize_callback' => 'absint' ],
                        'attendees'     => [ 'default' => 1, 'sanitize_callback' => 'absint' ],
                        'selected_seats' => [
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );
    }

    public function get_public_item_schema() {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'clisyc_price',
            'type'       => 'object',
            'properties' => [
                'total'     => [
                    'description' => __( 'The calculated average nightly price for the given date range.', 'client-sync' ),
                    'type'        => 'number',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'formatted' => [
                    'description' => __( 'The total price formatted with the currency symbol.', 'client-sync' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
            ],
        ];
    }

    /**
     * Custom validation callback for numeric IDs.
     * WordPress REST API passes 3 arguments to validation callbacks.
     *
     * @param mixed            $param   The value of the parameter.
     * @param \WP_REST_Request $request The request object.
     * @param string           $key     The parameter key.
     * @return bool
     */
    public function is_valid_numeric_id( $param, $request, $key ) {
        return is_numeric( $param );
    }

    /**
     * Callback for simple price calculation.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_price( \WP_REST_Request $request ) {
        // 1. SECURITY: Strict Rate Limiting
        $rate_check = Security_Helper::check_rate_limit( 'calc_price', Constants::RATE_LIMIT_PRICE_CALC, Constants::RATE_LIMIT_WINDOW_SECS );
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        $product_id = (int) $request->get_param( 'product_id' );
        $start_date = sanitize_text_field( $request->get_param( 'start_date' ) );
        $end_date   = sanitize_text_field( $request->get_param( 'end_date' ) );

        if ( ! class_exists( 'WooCommerce' ) ) {
            return new \WP_Error( 'woocommerce_inactive', __( 'WooCommerce is not active.', 'client-sync' ), [ 'status' => 500 ] );
        }

        $calculator  = new Price_Calculator();
        $total_price = $calculator->calculate_price( $product_id, $start_date, $end_date );

        if ( null === $total_price ) {
            return new \WP_Error( 'calculation_error', __( 'Could not calculate the price.', 'client-sync' ), [ 'status' => 400 ] );
        }

        return new \WP_REST_Response(
            [
                'total'     => $total_price,
                'formatted' => wp_strip_all_tags( wc_price( $total_price ) ),
            ],
            200
        );
    }

    /**
     * Callback for detailed basket calculation (Primary + Fees + Add-ons).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function calculate_basket_total( \WP_REST_Request $request ) {
        // Rate Limit (sharing scope with price calc)
        $rate_check = Security_Helper::check_rate_limit( 'calc_basket', Constants::RATE_LIMIT_BASKET_CALC, Constants::RATE_LIMIT_WINDOW_SECS );
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        $primary_id    = (int) $request->get_param( 'primary_id' );
        $dim_ids_raw   = $request->get_param( 'dimension_ids' );
        $dimension_ids = ! empty( $dim_ids_raw ) ? array_map( 'intval', explode( ',', $dim_ids_raw ) ) : [];

        $start_date = $request->get_param( 'start_date' );
        $end_date   = $request->get_param( 'end_date' );
        $duration   = (int) $request->get_param( 'duration' );
        $attendees  = max( 1, (int) $request->get_param( 'attendees' ) );

        if ( ! class_exists( 'WooCommerce' ) ) {
            return new \WP_Error( 'wc_missing', 'WooCommerce is required.', [ 'status' => 500 ] );
        }

        // 1. Determine Units (Nights or Quantity)
        $num_nights = 1;
        if ( $start_date && $end_date ) {
            try {
                $d1 = new \DateTime( $start_date );
                $d2 = new \DateTime( $end_date );
                $num_nights = $d1->diff( $d2 )->days ?: 1;
            } catch ( \Exception $e ) {
                Debug_Logger::log( 'Date range calculation failed: ' . $e->getMessage(), 'Pricing' );
            }
        }

        $line_items = [];
        $total_cost = 0.0;

        // Helper to calculate quantity based on type
        $get_qty = function( $type ) use ( $num_nights, $duration ) {
            switch ( $type ) {
                case 'per_hour':   return $duration / 60;
                case 'per_minute': return $duration;
                case 'flat':       return 1;
                case 'per_night':  default: return $num_nights;
            }
        };

        // 2. Primary Item Calculation
        $primary_product_id = (int) get_post_meta( $primary_id, '_clisyc_wc_product_id', true );

        // Fallback to global product if specific one not set
        if ( ! $primary_product_id ) {
            $primary_product_id = (int) get_option( 'clisyc_wc_appointment_product_id', 0 );
        }

        if ( $primary_product_id ) {
            $product = wc_get_product( $primary_product_id );
            if ( $product ) {
                $calc_type = get_post_meta( $primary_id, '_clisyc_addon_calc_type', true ) ?: 'per_night';
                $qty = $get_qty( $calc_type ) * $attendees; // Primary item scales with attendees

                // Use Price Calculator service for dynamic rules
                $calculator = new Price_Calculator();
                $unit_price = ( $start_date && $end_date )
                    ? $calculator->calculate_price( $primary_product_id, $start_date, $end_date )
                    : (float) $product->get_price();

                if ( $unit_price === null ) {
                    $unit_price = (float) $product->get_price();
                }

                $line_total = $unit_price * $qty;
                $total_cost += $line_total;

                $line_items[] = [
                    'label' => get_the_title( $primary_id ),
                    'meta'  => $product->get_name(),
                    'price' => $line_total,
                    'type'  => 'primary'
                ];
            }
        }

        // 3. Mandatory Fees (Attached to Primary)
        $fees = get_post_meta( $primary_id, '_clisyc_additional_products', true ) ?: [];
        foreach ( $fees as $fee ) {
            $p_id = (int) ( $fee['product_id'] ?? 0 );
            $p = wc_get_product( $p_id );
            if ( $p ) {
                $fee_calc = $fee['calc_type'] ?? 'flat';
                $qty = $get_qty( $fee_calc ); // Fees usually per booking, not per attendee

                $fee_total = (float) $p->get_price() * $qty;
                $total_cost += $fee_total;

                $line_items[] = [
                    'label' => $p->get_name(),
                    'meta'  => __( 'Required Fee', 'client-sync' ),
                    'price' => $fee_total,
                    'type'  => 'fee'
                ];
            }
        }

        // 4. Dimension Add-ons (e.g. Captain, Equipment)
        foreach ( $dimension_ids as $dim_id ) {
            $p_id = (int) get_post_meta( $dim_id, '_clisyc_wc_product_id', true );
            if ( $p_id ) {
                $p = wc_get_product( $p_id );
                if ( $p ) {
                    $addon_calc = get_post_meta( $dim_id, '_clisyc_addon_calc_type', true ) ?: 'per_night';
                    $qty = $get_qty( $addon_calc );

                    $addon_total = (float) $p->get_price() * $qty;
                    $total_cost += $addon_total;

                    $line_items[] = [
                        'label' => get_the_title( $dim_id ),
                        'meta'  => $p->get_name(),
                        'price' => $addon_total,
                        'type'  => 'addon'
                    ];
                }
            }
        }

        /**
         * Filters basket line items before the response is returned.
         *
         * Pro modules (e.g. Seat Selection) can append additional line items
         * such as per-seat pricing.
         *
         * @param array              $line_items Current line items.
         * @param float              $total_cost Current total cost.
         * @param \WP_REST_Request   $request    The original request.
         */
        $line_items = apply_filters( 'clisyc_basket_line_items', $line_items, $total_cost, $request );

        // Recalculate total from line items (in case filters added items).
        $total_cost = array_reduce( $line_items, function ( $carry, $item ) {
            return $carry + ( $item['price'] ?? 0 );
        }, 0.0 );

        return new \WP_REST_Response([
            'success'         => true,
            'total'           => $total_cost,
            'formatted_total' => html_entity_decode( wp_strip_all_tags( wc_price( $total_cost ) ) ),
            'line_items'      => $line_items,
            'currency'        => get_woocommerce_currency_symbol()
        ], 200);
    }

    /**
     * Validates that a parameter is a proper Y-m-d date string.
     * Rejects relative strings like "next week" or "+1 year".
     */
    public function is_valid_date( $param, $request, $key ) {
        $d = \DateTime::createFromFormat( 'Y-m-d', $param );
        return $d && $d->format( 'Y-m-d' ) === $param;
    }

    /**
     * Validates that a parameter is either empty or a proper Y-m-d date.
     */
    public function is_valid_date_or_empty( $param, $request, $key ) {
        if ( empty( $param ) ) {
            return true;
        }
        return $this->is_valid_date( $param, $request, $key );
    }
}
