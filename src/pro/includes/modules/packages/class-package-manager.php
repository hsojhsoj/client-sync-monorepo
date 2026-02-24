<?php
/**
 * File: src/pro/includes/modules/packages/class-package-manager.php -> /client-sync-pro/includes/modules/packages/class-package-manager.php
 * Handles granting credits upon WooCommerce order completion.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Packages
 */

namespace ClientSyncPro\Modules\Packages;

use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

class Package_Manager {

    public function register_hooks() {
        add_action( 'woocommerce_order_status_completed', [ $this, 'grant_credits_on_purchase' ], 10, 1 );
    }

    public function grant_credits_on_purchase( int $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Guard against double-processing the same order.
        if ( $order->get_meta( '_clisyc_credits_granted' ) ) {
            return;
        }

        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;

        // Batch-load all packages linked to products in this order (fixes N+1).
        $product_ids = [];
        foreach ( $order->get_items() as $item ) {
            $product_ids[] = $item->get_product_id();
        }
        $product_ids = array_unique( array_filter( $product_ids ) );

        if ( empty( $product_ids ) ) {
            return;
        }

        $package_map = $this->get_packages_for_products( $product_ids );

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            if ( ! isset( $package_map[ $product_id ] ) ) {
                continue;
            }

            $rules = $package_map[ $product_id ];

            if ( ! empty( $rules['service_id'] ) && ! empty( $rules['credits'] ) ) {
                $credits_to_add = $rules['credits'] * $item->get_quantity();
                $this->add_credits_to_user( $user_id, (int) $rules['service_id'], $credits_to_add, (int) ( $rules['expires_days'] ?? 0 ) );
            }
        }

        // Mark order as processed to prevent double-granting on retries.
        $order->update_meta_data( '_clisyc_credits_granted', time() );
        $order->save();
    }

    /**
     * Batch-load packages linked to a set of WooCommerce product IDs.
     *
     * @param int[] $product_ids
     * @return array Map of product_id => package rules.
     */
    private function get_packages_for_products( array $product_ids ): array {
        $package_query = new \WP_Query( [
            'post_type'      => 'clisyc_package',
            'posts_per_page' => count( $product_ids ),
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_clisyc_linked_product_id',
                    'value'   => $product_ids,
                    'compare' => 'IN',
                ],
            ],
        ] );

        $map = [];
        foreach ( $package_query->posts as $package_id ) {
            $linked_product = get_post_meta( $package_id, '_clisyc_linked_product_id', true );
            $rules          = get_post_meta( $package_id, '_clisyc_package_rules', true );
            if ( $linked_product && is_array( $rules ) ) {
                $map[ (int) $linked_product ] = $rules;
            }
        }

        return $map;
    }

    /**
     * Atomically add credits to a user for a specific service.
     *
     * Uses a DB-level lock to prevent concurrent purchases from
     * overwriting each other's credit increments.
     */
    private function add_credits_to_user( int $user_id, int $service_id, int $credits_to_add, int $expires_days ) {
        global $wpdb;

        $meta_key    = '_clisyc_credits';
        $service_key = 'service_' . $service_id;

        // Lock the row to prevent concurrent read-modify-write.
        $wpdb->query( 'START TRANSACTION' );

        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $raw = $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s FOR UPDATE",
                $user_id,
                $meta_key
            ) );

            $user_credits = $raw ? maybe_unserialize( $raw ) : [];
            $user_credits = is_array( $user_credits ) ? $user_credits : [];

            $existing = $user_credits[ $service_key ]['credits_remaining'] ?? 0;

            $user_credits[ $service_key ] = [
                'credits_remaining' => $existing + $credits_to_add,
                'expires_at'        => ( $expires_days > 0 ) ? ( time() + ( $expires_days * DAY_IN_SECONDS ) ) : 0,
            ];

            update_user_meta( $user_id, $meta_key, $user_credits );

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            Debug_Logger::log( 'Credit grant failed for user ' . $user_id . ': ' . $e->getMessage(), 'Packages' );
        }
    }
}
