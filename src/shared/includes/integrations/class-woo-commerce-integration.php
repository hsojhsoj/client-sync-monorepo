<?php
/**
 * File: src/shared/includes/integrations/class-woo-commerce-integration.php
 * Handles all interactions with the WooCommerce plugin.
 *
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 * MODIFIED: Price calculation updated to support multi-day bookings.
 * FIXED: Hook registration now uses woocommerce_loaded to ensure WooCommerce is available.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Integrations
 */

namespace DependentMedia\ClientSync\Integrations;

use DependentMedia\ClientSync\Services\Price_Calculator;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Cancellation_Manager;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'DependentMedia\ClientSync\Integrations\WooCommerce_Integration' ) ) {

    class WooCommerce_Integration {

        /**
         * Register hooks - but wait for WooCommerce to load first.
         * 
         * FIX: Instead of checking class_exists('WooCommerce') immediately (which fails
         * because Client Sync loads before WooCommerce), we hook into 'woocommerce_loaded'
         * which fires after WooCommerce is fully initialized.
         */
        public function register_hooks() {
            // Check if WC integration is enabled at all
            if ( ! get_option( Constants::OPTION_WC_ENABLED, false ) ) {
                return;
            }

            // If WooCommerce is already loaded, register immediately
            if ( class_exists( 'WooCommerce' ) ) {
                $this->register_wc_hooks();
            } else {
                // Wait for WooCommerce to load
                add_action( 'woocommerce_loaded', [ $this, 'register_wc_hooks' ] );
            }
        }

        /**
         * Actually register all WooCommerce hooks.
         * Called either immediately (if WC already loaded) or on woocommerce_loaded action.
         */
        public function register_wc_hooks() {
            Debug_Logger::log( 'register_wc_hooks() called - WooCommerce is now available', 'WooCommerce' );

            add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_update' ], 10, 4 );
            add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_appointment_and_service_data_to_cart_item' ], 10, 3 );
            add_filter( 'woocommerce_get_item_data', [ $this, 'display_appointment_and_service_details_in_cart' ], 10, 2 );
            add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_service_price_to_cart_item' ], 20 );
            add_filter( 'woocommerce_is_purchasable', [ $this, 'prevent_direct_purchase_of_appointment_product' ], 10, 2 );
            add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'clear_cart_before_adding_appointment_product' ], 20, 3 );
            add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_appointment_data_to_order_item_meta' ], 10, 4 );
            add_action( 'woocommerce_thankyou', [ $this, 'display_confirmation_on_thank_you_page' ], 1 );

            // Atomic Cart Integrity: Remove all booking items if any component is deleted
            add_action( 'woocommerce_cart_item_removed', [ $this, 'enforce_atomic_cart' ], 10, 2 );

            // Atomic Cart Integrity: Add confirmation dialog before removal (works with both classic and Blocks cart)
            add_filter( 'woocommerce_cart_item_class', [ $this, 'add_booking_item_class' ], 10, 3 );
            add_action( 'wp_footer', [ $this, 'render_atomic_cart_script' ], 99 );
            
            // AJAX endpoint for getting booking cart data (used by Blocks cart)
            add_action( 'wp_ajax_clisyc_get_booking_cart_data', [ $this, 'ajax_get_booking_cart_data' ] );
            add_action( 'wp_ajax_nopriv_clisyc_get_booking_cart_data', [ $this, 'ajax_get_booking_cart_data' ] );
        }

        public function handle_order_status_update( int $order_id, string $old_status, string $new_status, \WC_Order $order ) {
            // Support multiple appointments per order (recurring series).
            $appointment_ids = $this->get_all_appointment_ids_from_order( $order );

            if ( empty( $appointment_ids ) ) {
                return;
            }

            // *** REFACTORED: Updated filter names and custom status names ***
            $paid_statuses = apply_filters( 'clisyc_wc_paid_order_statuses', [ 'processing', 'completed' ] );
            $fail_statuses = apply_filters( 'clisyc_wc_failed_order_statuses', [ 'failed', 'cancelled' ] );

            foreach ( $appointment_ids as $appointment_id ) {
                if ( in_array( $new_status, $paid_statuses, true ) && ! in_array( $old_status, $paid_statuses, true ) ) {
                    $this->confirm_appointment_on_payment( $appointment_id, $order );
                }

                if ( in_array( $new_status, $fail_statuses, true ) && $new_status !== $old_status ) {
                    $this->cancel_appointment_on_failure( $appointment_id, $order );
                }

                // Handle refunds explicitly — these need to cancel confirmed appointments too.
                if ( 'refunded' === $new_status && $new_status !== $old_status ) {
                    $this->cancel_appointment_on_refund( $appointment_id, $order );
                }
            }
        }

        private function confirm_appointment_on_payment( int $appointment_id, \WC_Order $order ) {
            $appointment = get_post( $appointment_id );
            if ( ! $appointment || Constants::POST_TYPE_APPOINTMENT !== $appointment->post_type ) {
                return;
            }

            // *** REFACTORED: Updated filter name and custom status names ***
            $processed_statuses = apply_filters( 'clisyc_wc_appointment_already_processed_statuses', [ 'publish', 'clisyc_paid_on_day', 'confirmed' ] );
            if ( in_array( $appointment->post_status, $processed_statuses, true ) ) {
                // FIXED: Added translators comment and changed text domain.
                /* translators: %d: The numeric ID of the appointment. */
                $note_text_already_processed = __( 'Client Sync: Appointment #%d was already confirmed. No status change made.', 'client-sync' );
                $order->add_order_note( sprintf( $note_text_already_processed, $appointment_id ) );
                return;
            }

            $target_status = apply_filters( 'clisyc_wc_checkout_paid_appointment_status', 'publish' );
            wp_update_post( [ 'ID' => $appointment_id, 'post_status' => $target_status ] );

            // *** REFACTORED: Updated meta key constants ***
            update_post_meta( $appointment_id, Constants::META_WC_ORDER_ID, $order->get_id() );
            update_post_meta( $appointment_id, Constants::META_WC_ORDER_LINK, $order->get_edit_order_url() );
            update_post_meta( $appointment_id, Constants::META_PAYMENT_STATUS, 'paid_via_checkout' );

			do_action( 'clisyc_appointment_confirmed', $appointment_id );

            // FIXED: Added translators comment and changed text domain.
            /* translators: 1: The numeric ID of the appointment. 2: The new post status name (e.g., "publish"). */
            $note_text_confirmed = __( 'Client Sync: Confirmed appointment #%1$d. Status set to %2$s.', 'client-sync' );
            $order->add_order_note( sprintf( $note_text_confirmed, $appointment_id, $target_status ) );

            // Clean up the pending appointment transient (keyed per user/session).
            $user_id = get_current_user_id();
            if ( $user_id ) {
                delete_transient( 'clisyc_pending_appt_' . $user_id );
            }
        }

        private function cancel_appointment_on_failure( int $appointment_id, \WC_Order $order ) {
            $appointment = get_post( $appointment_id );
            if ( ! $appointment || Constants::POST_TYPE_APPOINTMENT !== $appointment->post_type ) {
                return;
            }

            // *** REFACTORED: Updated filter name and custom status name ***
            $cancelable_statuses = apply_filters( 'clisyc_wc_cancelable_appointment_statuses_on_order_fail', [ Constants::STATUS_PENDING_PAYMENT ] );
            if ( in_array( $appointment->post_status, $cancelable_statuses, true ) ) {
                $target_status = apply_filters( 'clisyc_wc_failed_order_appointment_status', 'trash' );
                
                if ( $target_status === 'trash' ) {
                    wp_trash_post( $appointment_id );
                } else {
                    wp_update_post( [ 'ID' => $appointment_id, 'post_status' => $target_status ] );
                }

                // FIXED: Added translators comment and changed text domain.
                /* translators: 1: The numeric ID of the appointment. 2: The new post status name (e.g., "trash"). */
                $note_text_cancelled = __( 'Client Sync: Automatically updated appointment #%1$d to "%2$s" due to order status change.', 'client-sync' );
                $order->add_order_note( sprintf( $note_text_cancelled, $appointment_id, $target_status ) );
            }
        }

        /**
         * Cancel an appointment when its WooCommerce order is refunded.
         *
         * Unlike cancel_appointment_on_failure() which only handles pending-payment
         * appointments, this handles already-confirmed/published appointments via
         * the Cancellation_Manager to properly free slots and send notifications.
         *
         * @param int       $appointment_id The appointment post ID.
         * @param \WC_Order $order          The WooCommerce order.
         */
        private function cancel_appointment_on_refund( int $appointment_id, \WC_Order $order ) {
            $appointment = get_post( $appointment_id );
            if ( ! $appointment || Constants::POST_TYPE_APPOINTMENT !== $appointment->post_type ) {
                return;
            }

            // Already cancelled/trashed — nothing to do.
            if ( in_array( $appointment->post_status, [ 'trash', Constants::STATUS_CANCELLED ?? 'clisyc_cancelled' ], true ) ) {
                /* translators: %d: The numeric ID of the appointment. */
                $order->add_order_note( sprintf(
                    __( 'Client Sync: Appointment #%d was already cancelled. No further action taken on refund.', 'client-sync' ),
                    $appointment_id
                ) );
                return;
            }

            $refund_cancelable = apply_filters( 'clisyc_wc_cancelable_appointment_statuses_on_refund', [
                'publish',
                'confirmed',
                Constants::STATUS_PENDING_PAYMENT,
                'clisyc_paid_on_day',
            ] );

            if ( ! in_array( $appointment->post_status, $refund_cancelable, true ) ) {
                /* translators: 1: The numeric ID of the appointment. 2: The current post status. */
                $order->add_order_note( sprintf(
                    __( 'Client Sync: Appointment #%1$d has status "%2$s" which is not cancelable on refund.', 'client-sync' ),
                    $appointment_id,
                    $appointment->post_status
                ) );
                return;
            }

            // Use Cancellation_Manager for proper slot cleanup and notifications.
            $cancellation_manager = new Cancellation_Manager( $appointment_id );
            $result = $cancellation_manager->process_cancellation( true );

            update_post_meta( $appointment_id, Constants::META_PAYMENT_STATUS, 'refunded' );

            if ( $result['success'] ) {
                /* translators: %d: The numeric ID of the appointment. */
                $order->add_order_note( sprintf(
                    __( 'Client Sync: Appointment #%d cancelled due to order refund. Time slot freed.', 'client-sync' ),
                    $appointment_id
                ) );
            } else {
                // Fallback: force-trash if Cancellation_Manager couldn't process.
                wp_trash_post( $appointment_id );
                /* translators: %d: The numeric ID of the appointment. */
                $order->add_order_note( sprintf(
                    __( 'Client Sync: Appointment #%d forcibly trashed due to order refund.', 'client-sync' ),
                    $appointment_id
                ) );
            }
        }

        public function add_appointment_and_service_data_to_cart_item( array $cart_item_data, int $product_id, int $variation_id ): array {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $unslashed_post = wp_unslash( $_POST );

            // FIX: Check both cart_item_data (from AJAX handler) and $_POST for appointment ID
            // Support both with and without underscore prefix for backwards compatibility
            if ( isset( $cart_item_data['_clisyc_appointment_id'] ) ) {
                // Already set from AJAX handler, keep it
            } elseif ( isset( $cart_item_data['clisyc_appointment_id'] ) ) {
                $cart_item_data['_clisyc_appointment_id'] = absint( $cart_item_data['clisyc_appointment_id'] );
            } elseif ( isset( $unslashed_post['_clisyc_appointment_id'] ) ) {
                $cart_item_data['_clisyc_appointment_id'] = absint( $unslashed_post['_clisyc_appointment_id'] );
            } elseif ( isset( $unslashed_post['clisyc_appointment_id'] ) ) {
                $cart_item_data['_clisyc_appointment_id'] = absint( $unslashed_post['clisyc_appointment_id'] );
            }

            if ( isset( $unslashed_post['_clisyc_service_name'] ) ) {
                $cart_item_data['_clisyc_service_name'] = sanitize_text_field( $unslashed_post['_clisyc_service_name'] );
            }
            if ( isset( $unslashed_post['_clisyc_appointment_details_display'] ) ) {
                $cart_item_data['_clisyc_appointment_details_display'] = sanitize_text_field( $unslashed_post['_clisyc_appointment_details_display'] );
            }
            if ( isset( $unslashed_post['_clisyc_booking_days'] ) ) {
                $cart_item_data['_clisyc_booking_days'] = absint( $unslashed_post['_clisyc_booking_days'] );
            }

            if ( isset( $cart_item_data['_clisyc_appointment_id'] ) ) {
                Debug_Logger::log( 'Added appointment ID ' . $cart_item_data['_clisyc_appointment_id'] . ' to cart item data', 'WooCommerce' );
            }

            return $cart_item_data;
        }

        public function display_appointment_and_service_details_in_cart( array $item_data, array $cart_item ): array {
            // *** REFACTORED: Updated all meta key names ***
            if ( isset( $cart_item['_clisyc_appointment_details_display'] ) ) {
                $item_data[] = [
                    'key'     => __( 'Appointment', 'client-sync' ), // FIXED
                    'value'   => $cart_item['_clisyc_appointment_details_display'],
                    'display' => '',
                ];
            }
            if ( isset( $cart_item['_clisyc_service_name'] ) ) {
                $item_data[] = [
                    'key'     => __( 'Service', 'client-sync' ), // FIXED
                    'value'   => $cart_item['_clisyc_service_name'],
                    'display' => '',
                ];
            }
            return $item_data;
        }

        public function apply_service_price_to_cart_item( \WC_Cart $cart ) {
            if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
            if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 && $cart === WC()->cart ) return;

            foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                // Check if this cart item is a multi-day booking with dates
                if ( isset( $cart_item[ Constants::META_START_DATE ], $cart_item[ Constants::META_END_DATE ] ) ) {
                    $product_id = $cart_item['product_id'];
                    $start_date = $cart_item[ Constants::META_START_DATE ];
                    $end_date   = $cart_item[ Constants::META_END_DATE ];
                    
                    $calculator  = new Price_Calculator();
                    $final_price = $calculator->calculate_price( $product_id, $start_date, $end_date );
                    
                    if ( null !== $final_price ) {
                        // Dynamically set the calculated price for this cart item
                        $cart_item['data']->set_price( $final_price );
                    }
                }
            }
        }

        public function prevent_direct_purchase_of_appointment_product( bool $is_purchasable, \WC_Product $product ): bool {
            // *** REFACTORED: Updated constant and form field names ***
            $appt_product_id = (int) get_option( Constants::OPTION_WC_PRODUCT_ID, 0 );
            if ( $product->get_id() === $appt_product_id ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $unslashed_post = wp_unslash( $_POST );
                if ( ! isset( $unslashed_post['_clisyc_frontend_booking_submit'] ) && ! ( defined('DOING_AJAX') && DOING_AJAX ) ) {
                    if (is_product()) {
                        return false;
                    }
                }
            }
            return $is_purchasable;
        }

        public function clear_cart_before_adding_appointment_product( bool $passed, int $product_id, int $quantity ): bool {
            // *** REFACTORED: Updated constant and form field names ***
            $appt_product_id = (int) get_option( Constants::OPTION_WC_PRODUCT_ID, 0 );

            if ( $product_id === $appt_product_id ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $unslashed_post = wp_unslash( $_POST );
                if ( ! isset( $unslashed_post['_clisyc_appointment_id'] ) ) {
                    wc_add_notice( __( 'Appointments can only be booked through the scheduling calendar.', 'client-sync' ), 'error' ); // FIXED
                    return false;
                }
                WC()->cart->empty_cart();
            }
            return $passed;
        }

        public function save_appointment_data_to_order_item_meta( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ) {
            Debug_Logger::log( 'save_appointment_data_to_order_item_meta called', 'WooCommerce' );
            Debug_Logger::log( 'Cart item keys: ' . implode( ', ', array_keys( $values ) ), 'WooCommerce' );

            // *** REFACTORED: Updated all meta key names ***
            if ( isset( $values['_clisyc_appointment_id'] ) ) {
                $item->add_meta_data( '_clisyc_appointment_id', $values['_clisyc_appointment_id'], true );
                Debug_Logger::log( 'Saved _clisyc_appointment_id: ' . $values['_clisyc_appointment_id'], 'WooCommerce' );
            }
            if ( isset( $values['_clisyc_service_name'] ) ) {
                $item->add_meta_data( '_clisyc_service_name', $values['_clisyc_service_name'], true );
            }
            if ( isset( $values['_clisyc_appointment_details_display'] ) ) {
                $item->add_meta_data( '_clisyc_appointment_details_display', $values['_clisyc_appointment_details_display'], true );
            }
            if ( isset( $values['_clisyc_booking_days'] ) ) {
                $item->add_meta_data( '_clisyc_booking_days', $values['_clisyc_booking_days'], true );
            }
            if ( isset( $values[ Constants::META_START_DATE ] ) ) {
                $item->add_meta_data( Constants::META_START_DATE, $values[ Constants::META_START_DATE ], true );
            }
            if ( isset( $values[ Constants::META_END_DATE ] ) ) {
                $item->add_meta_data( Constants::META_END_DATE, $values[ Constants::META_END_DATE ], true );
            }
        }

        private function get_appointment_id_from_order( \WC_Order $order ): int {
            foreach ( $order->get_items() as $item ) {
                // *** REFACTORED: Updated meta key name ***
                $appointment_id = $item->get_meta( '_clisyc_appointment_id', true );
                if ( $appointment_id ) {
                    return absint( $appointment_id );
                }
            }
            return 0;
        }

        /**
         * Get ALL appointment IDs from an order (supports recurring series).
         *
         * Unlike get_appointment_id_from_order() which returns only the first,
         * this method returns all appointment IDs linked to the order items.
         *
         * @param \WC_Order $order The WooCommerce order.
         * @return int[] Array of appointment post IDs.
         */
        private function get_all_appointment_ids_from_order( \WC_Order $order ): array {
            $ids = [];
            foreach ( $order->get_items() as $item ) {
                $appointment_id = $item->get_meta( '_clisyc_appointment_id', true );
                if ( $appointment_id ) {
                    $ids[] = absint( $appointment_id );
                }
            }
            return array_unique( $ids );
        }

        /**
         * Injects the booking confirmation shortcode at the top of the WooCommerce
         * "Order Received" (Thank You) page.
         *
         * @param int $order_id The ID of the order just completed.
         */
        public function display_confirmation_on_thank_you_page( $order_id ) {
            Debug_Logger::log( 'Hook fired for order: ' . $order_id, 'WooCommerce' );

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                Debug_Logger::log( 'Could not get order', 'WooCommerce' );
                return;
            }

            $appointment_id = 0;
            foreach ( $order->get_items() as $item_id => $item ) {
                $all_meta = $item->get_meta_data();
                $meta_keys = array_map( function( $m ) { return $m->key; }, $all_meta );
                Debug_Logger::log( 'Item ' . $item_id . ' meta keys: ' . ( empty( $meta_keys ) ? '(none)' : implode( ', ', $meta_keys ) ), 'WooCommerce' );

                // FIX: Ensure we use true for the second parameter to get the single value string
                $appt_id = $item->get_meta( '_clisyc_appointment_id', true );
                if ( $appt_id ) {
                    $appointment_id = absint( $appt_id );
                    break;
                }
            }

            // Only show if we found an appointment linked to this order
            if ( $appointment_id ) {
                Debug_Logger::log( 'Found appointment ID: ' . $appointment_id . ', rendering shortcode', 'WooCommerce' );

                // Verify appointment exists
                $appointment = get_post( $appointment_id );
                if ( ! $appointment || Constants::POST_TYPE_APPOINTMENT !== $appointment->post_type ) {
                    Debug_Logger::log( 'Appointment not found or wrong type', 'WooCommerce' );
                    return;
                }

                // We pass the appointment_id directly. This tells the shortcode exactly what to show.
                $shortcode = '[clisyc_booking_confirmation style="minimal" appointment_id="' . $appointment_id . '" show_confetti="true"]';
                $output = do_shortcode( $shortcode );

                Debug_Logger::log( 'Shortcode output length: ' . strlen( $output ), 'WooCommerce' );

                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is already escaped by the shortcode handler.
                echo $output;
            } else {
                Debug_Logger::log( 'No appointment ID found in order items', 'WooCommerce' );
            }
        }

        /**
         * Enforces Atomic Cart Integrity for booking-related cart items.
         *
         * When any cart item that belongs to a Client Sync booking is removed,
         * this method automatically removes ALL other items belonging to the same
         * appointment. This prevents customers from removing mandatory fees
         * (like cleaning fees) while keeping the primary booking item.
         *
         * @param string   $cart_item_key The unique key of the item that was just removed.
         * @param \WC_Cart $cart          The WooCommerce cart instance.
         */
        public function enforce_atomic_cart( string $cart_item_key, \WC_Cart $cart ) {
            // 1. Get the data of the item that was just removed
            //    WooCommerce stores removed items in removed_cart_contents
            if ( ! isset( $cart->removed_cart_contents[ $cart_item_key ] ) ) {
                return;
            }

            $removed_item = $cart->removed_cart_contents[ $cart_item_key ];

            // 2. Check if the removed item was part of a Client Sync booking
            if ( ! isset( $removed_item['_clisyc_appointment_id'] ) ) {
                return; // Not a Client Sync booking item, nothing to do
            }

            $target_appt_id = absint( $removed_item['_clisyc_appointment_id'] );

            Debug_Logger::log( 'Cart item removed belongs to appointment #' . $target_appt_id, 'WooCommerce' );

            // 3. Collect keys of remaining items that belong to the same appointment
            //    We collect first to avoid modifying the array while iterating
            $keys_to_remove = [];

            foreach ( $cart->get_cart() as $key => $item ) {
                if ( isset( $item['_clisyc_appointment_id'] ) &&
                     absint( $item['_clisyc_appointment_id'] ) === $target_appt_id ) {
                    $keys_to_remove[] = $key;
                }
            }

            // 4. Remove all related items
            $items_removed_count = 0;
            foreach ( $keys_to_remove as $key ) {
                // Use remove_cart_item to properly trigger WooCommerce's removal process
                // Pass false for the second param to prevent triggering this hook recursively
                $cart->remove_cart_item( $key );
                $items_removed_count++;

                Debug_Logger::log( 'Removed related cart item: ' . $key, 'WooCommerce' );
            }

            // 5. User Feedback & Cleanup
            if ( $items_removed_count > 0 ) {
                wc_add_notice(
                    __( 'The entire booking was removed because a required component was deleted. Please start a new booking if you wish to continue.', 'client-sync' ),
                    'notice'
                );

                // Clean up the draft appointment post since the booking was abandoned
                $appointment = get_post( $target_appt_id );
                if ( $appointment && Constants::POST_TYPE_APPOINTMENT === $appointment->post_type ) {
                    // Only trash if it's still a pending/draft appointment
                    $trashable_statuses = [ 'draft', Constants::STATUS_PENDING_PAYMENT, 'pending' ];
                    if ( in_array( $appointment->post_status, $trashable_statuses, true ) ) {
                        wp_trash_post( $target_appt_id );

                        Debug_Logger::log( 'Trashed appointment #' . $target_appt_id, 'WooCommerce' );
                    }
                }

                Debug_Logger::log( 'Total related items removed: ' . $items_removed_count, 'WooCommerce' );
            }
        }

        /**
         * Adds a CSS class to cart items that belong to a Client Sync booking.
         * This allows JavaScript to identify which items need the confirmation dialog.
         * Note: This only works for the classic shortcode-based cart, not Blocks cart.
         *
         * @param string $class         Existing CSS classes for the cart item row.
         * @param array  $cart_item     The cart item data.
         * @param string $cart_item_key The unique key for this cart item.
         * @return string Modified CSS classes.
         */
        public function add_booking_item_class( string $class, array $cart_item, string $cart_item_key ): string {
            if ( isset( $cart_item['_clisyc_appointment_id'] ) ) {
                $class .= ' clisyc-booking-item';
                $class .= ' clisyc-appt-' . absint( $cart_item['_clisyc_appointment_id'] );
            }
            return $class;
        }

        /**
         * AJAX handler to return booking cart data.
         * Used by the frontend JavaScript to identify which cart items belong to bookings.
         */
        public function ajax_get_booking_cart_data() {
            check_ajax_referer( 'clisyc_booking_cart_nonce', 'security' );

            $booking_data = [];
            
            if ( WC()->cart ) {
                $index = 0;
                foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                    if ( isset( $cart_item['_clisyc_appointment_id'] ) ) {
                        $appt_id = absint( $cart_item['_clisyc_appointment_id'] );
                        $product_id = $cart_item['product_id'];
                        $product_name = $cart_item['data']->get_name();
                        
                        $booking_data[] = [
                            'index'          => $index,
                            'cart_item_key'  => $cart_item_key,
                            'product_id'     => $product_id,
                            'product_name'   => $product_name,
                            'appointment_id' => $appt_id,
                        ];
                    }
                    $index++;
                }
            }
            
            // Count items per appointment
            $appointment_counts = [];
            foreach ( $booking_data as $item ) {
                $appt_id = $item['appointment_id'];
                if ( ! isset( $appointment_counts[ $appt_id ] ) ) {
                    $appointment_counts[ $appt_id ] = 0;
                }
                $appointment_counts[ $appt_id ]++;
            }
            
            // Add count to each item
            foreach ( $booking_data as &$item ) {
                $item['total_items_in_booking'] = $appointment_counts[ $item['appointment_id'] ];
            }
            
            wp_send_json_success( [
                'items'              => $booking_data,
                'appointment_counts' => $appointment_counts,
            ] );
        }

        /**
         * Renders the atomic cart confirmation script in the footer.
         * Works with both classic [woocommerce_cart] shortcode and WooCommerce Blocks cart.
         */
        public function render_atomic_cart_script() {
            // Only load on pages that might have a cart
            if ( ! is_cart() && ! has_block( 'woocommerce/cart' ) ) {
                return;
            }

            // Check if there are any booking items in the cart
            $has_booking_items = false;
            $booking_items_data = [];
            $appointment_counts = [];
            
            if ( WC()->cart ) {
                $index = 0;
                foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                    if ( isset( $cart_item['_clisyc_appointment_id'] ) ) {
                        $has_booking_items = true;
                        $appt_id = absint( $cart_item['_clisyc_appointment_id'] );
                        $product_name = $cart_item['data']->get_name();
                        
                        $booking_items_data[] = [
                            'index'          => $index,
                            'cart_item_key'  => $cart_item_key,
                            'product_name'   => $product_name,
                            'appointment_id' => $appt_id,
                        ];
                        
                        if ( ! isset( $appointment_counts[ $appt_id ] ) ) {
                            $appointment_counts[ $appt_id ] = 0;
                        }
                        $appointment_counts[ $appt_id ]++;
                    }
                    $index++;
                }
            }

            if ( ! $has_booking_items ) {
                return;
            }
            ?>
            <script type="text/javascript">
            (function() {
                'use strict';
                
                // Booking data from PHP
                var bookingItemsData = <?php echo wp_json_encode( $booking_items_data ); ?>;
                var appointmentCounts = <?php echo wp_json_encode( $appointment_counts ); ?>;
                var clisycBookingCartNonce = <?php echo wp_json_encode( wp_create_nonce( 'clisyc_booking_cart_nonce' ) ); ?>;
                
                // Build a map of product names to appointment data for easy lookup
                var productNameToApptMap = {};
                bookingItemsData.forEach(function(item) {
                    productNameToApptMap[item.product_name.toLowerCase().trim()] = {
                        appointmentId: item.appointment_id,
                        totalItems: appointmentCounts[item.appointment_id] || 1
                    };
                });
                
                // Build a map of row index to appointment data
                var rowIndexToApptMap = {};
                bookingItemsData.forEach(function(item) {
                    rowIndexToApptMap[item.index] = {
                        appointmentId: item.appointment_id,
                        totalItems: appointmentCounts[item.appointment_id] || 1
                    };
                });
                
                function showConfirmation(totalItems) {
                    if (totalItems <= 1) {
                        return true; // No confirmation needed for single-item bookings
                    }
                    
                    var message = '<?php echo esc_js( __( 'This item is part of a booking package.', 'client-sync' ) ); ?>\n\n' +
                        '<?php echo esc_js( __( 'Removing it will also remove all other items in this booking ({count} items total).', 'client-sync' ) ); ?>'.replace('{count}', totalItems) + '\n\n' +
                        '<?php echo esc_js( __( 'Are you sure you want to remove the entire booking?', 'client-sync' ) ); ?>';
                    
                    return confirm(message);
                }
                
                function getApptDataFromRow(row) {
                    // Try to get appointment data from row
                    
                    // Method 1: Check for clisyc-appt-* class (classic cart)
                    var classList = row.className || '';
                    var classMatch = classList.match(/clisyc-appt-(\d+)/);
                    if (classMatch) {
                        var apptId = classMatch[1];
                        if (appointmentCounts[apptId]) {
                            return {
                                appointmentId: apptId,
                                totalItems: appointmentCounts[apptId]
                            };
                        }
                    }
                    
                    // Method 2: Find product name in the row and look it up
                    var productNameEl = row.querySelector('.wc-block-components-product-name, .product-name a, td.product-name a');
                    if (productNameEl) {
                        var productName = (productNameEl.textContent || productNameEl.innerText || '').toLowerCase().trim();
                        if (productNameToApptMap[productName]) {
                            return productNameToApptMap[productName];
                        }
                    }
                    
                    // Method 3: Use row index (for Blocks cart)
                    var table = row.closest('table');
                    if (table) {
                        var rows = table.querySelectorAll('tbody tr');
                        for (var i = 0; i < rows.length; i++) {
                            if (rows[i] === row && rowIndexToApptMap[i]) {
                                return rowIndexToApptMap[i];
                            }
                        }
                    }
                    
                    return null;
                }
                
                function attachHandlers() {
                    // === BLOCKS CART: button.wc-block-cart-item__remove-link ===
                    var blocksRemoveButtons = document.querySelectorAll('button.wc-block-cart-item__remove-link');
                    blocksRemoveButtons.forEach(function(btn) {
                        if (btn.dataset.clisycBound) return;
                        btn.dataset.clisycBound = 'true';
                        
                        btn.addEventListener('click', function(e) {
                            var row = btn.closest('tr.wc-block-cart-items__row');
                            if (!row) return;
                            
                            var apptData = getApptDataFromRow(row);
                            if (apptData && apptData.totalItems > 1) {
                                if (!showConfirmation(apptData.totalItems)) {
                                    e.preventDefault();
                                    e.stopImmediatePropagation();
                                    return false;
                                }
                            }
                        }, true); // Use capture phase to run before React
                    });
                    
                    // === CLASSIC CART: a.remove in .clisyc-booking-item rows ===
                    var classicRemoveLinks = document.querySelectorAll('.clisyc-booking-item a.remove, .clisyc-booking-item .product-remove a');
                    classicRemoveLinks.forEach(function(link) {
                        if (link.dataset.clisycBound) return;
                        link.dataset.clisycBound = 'true';
                        
                        link.addEventListener('click', function(e) {
                            var row = link.closest('tr');
                            if (!row) return;
                            
                            var apptData = getApptDataFromRow(row);
                            if (apptData && apptData.totalItems > 1) {
                                if (!showConfirmation(apptData.totalItems)) {
                                    e.preventDefault();
                                    e.stopImmediatePropagation();
                                    return false;
                                }
                            }
                        }, true);
                    });
                }
                
                // Initial attachment
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', attachHandlers);
                } else {
                    attachHandlers();
                }
                
                // Re-attach after AJAX updates (classic cart)
                if (typeof jQuery !== 'undefined') {
                    jQuery(document.body).on('updated_wc_div updated_cart_totals', function() {
                        setTimeout(attachHandlers, 100);
                    });
                }
                
                // Re-attach after React updates (Blocks cart) using MutationObserver
                var cartContainer = document.querySelector('.wc-block-cart, .woocommerce-cart-form');
                if (cartContainer) {
                    var observer = new MutationObserver(function(mutations) {
                        var shouldReattach = mutations.some(function(m) {
                            return m.addedNodes.length > 0 || m.removedNodes.length > 0;
                        });
                        if (shouldReattach) {
                            setTimeout(attachHandlers, 100);
                        }
                    });
                    observer.observe(cartContainer, { childList: true, subtree: true });
                }
                
                // Also observe the entire document body for dynamically loaded carts
                var bodyObserver = new MutationObserver(function(mutations) {
                    mutations.forEach(function(m) {
                        m.addedNodes.forEach(function(node) {
                            if (node.nodeType === 1) {
                                if (node.classList && (node.classList.contains('wc-block-cart') || node.classList.contains('woocommerce-cart-form'))) {
                                    setTimeout(attachHandlers, 100);
                                }
                                if (node.querySelector && node.querySelector('.wc-block-cart, .woocommerce-cart-form')) {
                                    setTimeout(attachHandlers, 100);
                                }
                            }
                        });
                    });
                });
                bodyObserver.observe(document.body, { childList: true, subtree: true });
                
            })();
            </script>
            <?php
        }

    }
}