<?php
/**
 * File: src/pro/includes/services/class-membership-rules-engine.php -> client-sync-pro/includes/services/class-membership-rules-engine.php
 * A service class for validating membership rules and applying benefits.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Services
 */

namespace ClientSyncPro\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

class Membership_Rules_Engine {

    private static $rule_cache = [];

    /**
     * Gets the active membership plan ID for a given user.
     * @param int $user_id
     * @return int The post ID of the clisyc_member_plan, or 0 if none.
     */
    public function get_user_active_membership_plan_id( int $user_id ): int {
        if ( ! $user_id ) return 0;
        
        $plan_id = get_user_meta( $user_id, '_clisyc_active_membership_plan_id', true );

        // FIX: Check if the function returned an error before casting.
        if ( is_wp_error( $plan_id ) ) {
            return 0;
        }

        return (int) $plan_id;
    }

    /**
     * Gets the access rules for a specific membership plan.
     * @param int $plan_id
     * @return array The array of rules.
     */
    public function get_plan_rules( int $plan_id ): array {
        if ( ! $plan_id ) return [];
        if ( isset( self::$rule_cache[ $plan_id ] ) ) {
            return self::$rule_cache[ $plan_id ];
        }

        $rules = get_post_meta( $plan_id, '_clisyc_member_plan_access_rules', true );
        self::$rule_cache[ $plan_id ] = is_array( $rules ) ? $rules : [];

        return self::$rule_cache[ $plan_id ];
    }

    /**
     * Finds the most specific rule that applies to a given service within a set of plan rules.
     * @param array $plan_rules
     * @param int $service_id
     * @return array|null The specific rule, or null if none apply.
     */
    public function get_applicable_rule_for_service( array $plan_rules, int $service_id ): ?array {
        $specific_rule = null;
        $all_services_rule = null;

        foreach ( $plan_rules as $rule ) {
            $rule_service_id = (int) ( $rule['service_id'] ?? 0 );
            
            // FIX: Explicitly cast both sides to (int) for a reliable comparison.
            if ( (int) $rule_service_id === (int) $service_id ) {
                $specific_rule = $rule;
                break; // A direct match is the highest priority
            }
            if ( $rule_service_id === 0 ) { // 0 can represent "all services"
                $all_services_rule = $rule;
            }
        }

        return $specific_rule ?? $all_services_rule;
    }

    /**
     * Checks if a user can book a specific service based on their membership limits.
     * @return array [ 'can_book' => bool, 'message' => string ]
     */
    public function can_user_book_service( int $user_id, int $service_id ): array {
        $plan_id = $this->get_user_active_membership_plan_id( $user_id );
        if ( ! $plan_id ) return [ 'can_book' => true, 'message' => '' ]; // Not a member, standard booking applies

        $rules = $this->get_plan_rules( $plan_id );
        $rule = $this->get_applicable_rule_for_service( $rules, $service_id );

        if ( ! $rule || 'booking_limit' !== ( $rule['rule_type'] ?? '' ) ) {
            return [ 'can_book' => true, 'message' => '' ]; // No limit rule applies
        }

        if ( 'unlimited' === ( $rule['limit_period'] ?? '' ) ) {
            return [ 'can_book' => true, 'message' => __( 'Included in your membership', 'client-sync-pro' ) ];
        }

        $usage = $this->_get_user_usage_for_period( $user_id, $service_id, $rule['limit_period'] );
        $limit = (int) ( $rule['limit_value'] ?? 0 );

        if ( $usage >= $limit ) {
            return [ 'can_book' => false, 'message' => __( 'You have reached your booking limit for this period.', 'client-sync-pro' ) ];
        }

        /* translators: 1: number of used bookings, 2: total booking limit. */
        $message = sprintf( __( 'Included in your membership (%1$d of %2$d used)', 'client-sync-pro' ), $usage, $limit );

        return [ 'can_book' => true, 'message' => $message ];
    }

    /**
     * Applies any membership discounts to a base price.
     * @return float The adjusted price.
     */
    public function get_membership_price_for_service( int $user_id, int $service_id, float $base_price ): float {
        $plan_id = $this->get_user_active_membership_plan_id( $user_id );
        if ( ! $plan_id ) return $base_price;

        $rules = $this->get_plan_rules( $plan_id );
        $rule = $this->get_applicable_rule_for_service( $rules, $service_id );

        if ( ! $rule ) return $base_price;

        $discount_value = (float) ( $rule['discount_value'] ?? 0 );
        switch ( $rule['rule_type'] ?? '' ) {
            case 'discount_percent':
                return $base_price * ( 1 - ( $discount_value / 100 ) );
            case 'discount_amount':
                return max( 0, $base_price - $discount_value );
            case 'booking_limit':
                // Check usage before applying a free price
                $check = $this->can_user_book_service( $user_id, $service_id );
                return $check['can_book'] ? 0.00 : $base_price;
        }

        return $base_price;
    }

    /**
     * Gathers all relevant details about a user's active membership for display.
     *
     * @param int $user_id The ID of the user.
     * @return array|null An array of details, or null if no active membership.
     */
    public function get_user_active_membership_details( int $user_id ): ?array {
        $plan_id = $this->get_user_active_membership_plan_id( $user_id );
        if ( ! $plan_id ) {
            return null;
        }

        $plan_post = get_post( $plan_id );
        if ( ! $plan_post ) {
            return null;
        }

        $details = [
            'plan_name'      => $plan_post->post_title,
            'status'         => __( 'Active (Manually Assigned)', 'client-sync-pro' ),
            'next_payment'   => null,
            'manage_url'     => null,
            'usage'          => [],
        ];

        // --- WooCommerce Subscriptions Integration ---
        if ( class_exists( 'WC_Subscriptions' ) ) {
            $linked_product_id = (int) get_post_meta( $plan_id, '_clisyc_linked_subscription_product_id', true );
            $subscriptions = wcs_get_users_subscriptions( $user_id );

            foreach ( $subscriptions as $subscription ) {
                if ( $subscription->has_product( $linked_product_id ) && $subscription->has_status( 'active' ) ) {
                    $details['status'] = $subscription->get_status_to_display();
                    $details['next_payment'] = $subscription->get_date_to_display( 'next_payment' );
                    $details['manage_url'] = wc_get_account_endpoint_url( 'subscriptions' );
                    break;
                }
            }
        }

        // --- Stripe Subscriptions Integration ---
        $stripe_sub_id = get_user_meta( $user_id, \DependentMedia\ClientSync\Constants::META_STRIPE_SUBSCRIPTION_ID, true );
        if ( ! empty( $stripe_sub_id ) ) {
            $stripe_status = get_user_meta( $user_id, \DependentMedia\ClientSync\Constants::META_STRIPE_SUB_STATUS, true );
            $period_end    = (int) get_user_meta( $user_id, \DependentMedia\ClientSync\Constants::META_SUBSCRIPTION_PERIOD_END, true );

            $status_labels = [
                'active'   => __( 'Active', 'client-sync-pro' ),
                'trialing' => __( 'Trial', 'client-sync-pro' ),
                'past_due' => __( 'Past Due', 'client-sync-pro' ),
                'canceled' => __( 'Canceled', 'client-sync-pro' ),
            ];
            $details['status'] = $status_labels[ $stripe_status ] ?? ucfirst( $stripe_status ?: 'active' );

            if ( $period_end > 0 ) {
                $details['next_payment'] = wp_date( get_option( 'date_format' ), $period_end );
            }

            // Billing Portal URL for subscription management.
            if ( \DependentMedia\ClientSync\Integrations\Stripe_Integration::is_active() ) {
                $stripe             = new \DependentMedia\ClientSync\Integrations\Stripe_Integration();
                $portal_result      = $stripe->create_billing_portal_session( $user_id );
                if ( ! is_wp_error( $portal_result ) ) {
                    $details['manage_url'] = $portal_result['url'];
                }
            }
        }

        // --- Usage Tracking ---
        $rules = $this->get_plan_rules( $plan_id );
        foreach ( $rules as $rule ) {
            if ( 'booking_limit' === ( $rule['rule_type'] ?? '' ) ) {
                $service_id = (int) ( $rule['service_id'] ?? 0 );
                if ( ! $service_id ) continue; // Skip "All Services" for usage display for now

                $service_name = get_the_title( $service_id );
                $usage = $this->_get_user_usage_for_period( $user_id, $service_id, $rule['limit_period'] );
                $limit = ( 'unlimited' === $rule['limit_period'] ) ? '∞' : (int) ( $rule['limit_value'] ?? 0 );
                
                $period_labels = [
                    'per_week' => __( 'this week', 'client-sync-pro' ),
                    'per_month' => __( 'this month', 'client-sync-pro' ),
                    'per_year' => __( 'this year', 'client-sync-pro' ),
                    'unlimited' => __( 'overall', 'client-sync-pro' ),
                ];
                
                $details['usage'][] = [
                    'service_name' => $service_name,
                    'used'         => $usage,
                    'limit'        => $limit,
                    'period'       => $period_labels[ $rule['limit_period'] ] ?? '',
                ];
            }
        }

        return $details;
    }

    /**
     * Counts a user's past bookings for a specific service within a given period.
     * @return int
     */
    private function _get_user_usage_for_period( int $user_id, int $service_id, string $period ): int {
        $date_query = [];
        $now = new \DateTime( 'now', wp_timezone() );

        switch ( $period ) {
            case 'per_week':
                $start_of_week = (int) get_option( 'start_of_week', 1 );
                $start_day_str = $start_of_week === 1 ? 'Monday' : 'Sunday';
                $start_date = (clone $now)->modify( "last {$start_day_str}" )->format('Y-m-d');
                $date_query = [ 'after' => $start_date, 'inclusive' => true ];
                break;
            case 'per_month':
                $date_query = [ 'year' => $now->format('Y'), 'month' => $now->format('m') ];
                break;
            default:
                return 0; // Invalid period
        }

        $args = [
            'post_type' => 'clisyc_appointment',
            'post_status' => [ 'publish', 'confirmed', 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ],
            'author' => $user_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'date_query' => [ $date_query ],
            'meta_query' => [
                [
                    'key' => '_clisyc_slot_dimensions',
                    // FIX: The value for a LIKE query on a serialized array must match the PHP serialization format.
                    'value'   => '"clisyc_service";i:' . $service_id . ';',
                    'compare' => 'LIKE'
                ]
            ]
        ];

        $query = new \WP_Query( $args );
        return $query->post_count;
    }
}