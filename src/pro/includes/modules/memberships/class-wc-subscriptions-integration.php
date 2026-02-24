<?php
/**
 * File: src/pro/includes/modules/memberships/class-wc-subscriptions-integration.php -> client-sync-pro/includes/modules/memberships/class-wc-subscriptions-integration.php
 * Handles the integration with WooCommerce Subscriptions for automated membership management.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Memberships
 */

namespace ClientSyncPro\Modules\Memberships;

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Subscriptions_Integration {

    public function register_hooks() {
        // Hooks for when a subscription status changes.
        add_action( 'woocommerce_subscription_status_active', [ $this, 'handle_subscription_activated' ] );
        add_action( 'woocommerce_subscription_status_on-hold', [ $this, 'handle_subscription_deactivated' ] );
        add_action( 'woocommerce_subscription_status_cancelled', [ $this, 'handle_subscription_deactivated' ] );
        add_action( 'woocommerce_subscription_status_expired', [ $this, 'handle_subscription_deactivated' ] );
    }

    /**
     * Called when a subscription becomes active.
     * Assigns the corresponding membership plan to the user.
     *
     * @param \WC_Subscription $subscription
     */
    public function handle_subscription_activated( \WC_Subscription $subscription ) {
        $user_id = $subscription->get_user_id();
        if ( ! $user_id ) return;

        $plan_id = $this->find_plan_id_from_subscription( $subscription );
        if ( $plan_id ) {
            // Update the user's active membership plan ID
            update_user_meta( $user_id, '_clisyc_active_membership_plan_id', $plan_id );
            
            // Optional: Store expiry date for more advanced checks later
            $end_date = $subscription->get_date( 'end' );
            if ( $end_date ) {
                update_user_meta( $user_id, '_clisyc_membership_expiry_date', $end_date );
            }
        }
    }

    /**
     * Called when a subscription becomes inactive (cancelled, expired, etc.).
     * Removes the membership plan from the user.
     *
     * @param \WC_Subscription $subscription
     */
    public function handle_subscription_deactivated( \WC_Subscription $subscription ) {
        $user_id = $subscription->get_user_id();
        if ( ! $user_id ) return;

        $plan_id = $this->find_plan_id_from_subscription( $subscription );
        $current_user_plan_id = (int) get_user_meta( $user_id, '_clisyc_active_membership_plan_id', true );

        // Only remove the plan if it matches the one from this subscription.
        // This prevents a cancellation from removing a different, valid membership.
        if ( $plan_id && $plan_id === $current_user_plan_id ) {
            delete_user_meta( $user_id, '_clisyc_active_membership_plan_id' );
            delete_user_meta( $user_id, '_clisyc_membership_expiry_date' );
            delete_user_meta( $user_id, '_clisyc_membership_usage_counts' ); // Clear usage
        }
    }

    /**
     * Finds the Membership Plan ID linked to the product in a subscription.
     *
     * @param \WC_Subscription $subscription
     * @return int The Membership Plan post ID, or 0 if not found.
     */
    private function find_plan_id_from_subscription( \WC_Subscription $subscription ): int {
        foreach ( $subscription->get_items() as $item ) {
            $product_id = $item->get_product_id();
            
            $query_args = [
                'post_type' => 'clisyc_member_plan',
                'posts_per_page' => 1,
                'post_status' => 'publish',
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => '_clisyc_linked_subscription_product_id',
                        'value' => $product_id,
                    ]
                ]
            ];
            $plan_query = new \WP_Query( $query_args );

            if ( $plan_query->have_posts() ) {
                return (int) $plan_query->posts[0];
            }
        }
        return 0;
    }
}