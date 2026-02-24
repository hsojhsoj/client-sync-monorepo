<?php
/**
 * File: src/pro/includes/modules/memberships/class-membership-cpt.php -> client-sync-pro/includes/modules/memberships/class-membership-cpt.php
 * Registers the 'clisyc_member_plan' CPT and its meta boxes.
 * MODIFIED: Added full CPT registration logic.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Memberships
 */

namespace ClientSyncPro\Modules\Memberships;

if ( ! defined( 'ABSPATH' ) ) exit;

class Membership_CPT {

    public function register_hooks() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes_clisyc_member_plan', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_clisyc_member_plan', [ $this, 'save_meta_data' ] );
    }

    public function register_cpt() {
        $labels = [
            'name'                  => _x( 'Membership Plans', 'Post type general name', 'client-sync-pro' ),
            'singular_name'         => _x( 'Membership Plan', 'Post type singular name', 'client-sync-pro' ),
            'menu_name'             => _x( 'Memberships', 'Admin Menu text', 'client-sync-pro' ),
            'add_new_item'          => __( 'Add New Plan', 'client-sync-pro' ),
            'edit_item'             => __( 'Edit Plan', 'client-sync-pro' ),
            'all_items'             => __( 'All Plans', 'client-sync-pro' ),
        ];
        $args = [
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'client-sync',
            'capability_type'     => 'post',
            'supports'            => [ 'title' ],
            'menu_icon'           => 'dashicons-awards',
        ];
        register_post_type( 'clisyc_member_plan', $args );
    }

    public function add_meta_boxes() {
        add_meta_box(
            'clisyc-membership-plan-rules',
            __( 'Membership Access Rules', 'client-sync-pro' ),
            [ $this, 'render_rules_meta_box' ],
            'clisyc_member_plan',
            'normal',
            'high'
        );
        // START: Add this new meta box
        if ( class_exists( 'WooCommerce' ) && class_exists( 'WC_Subscriptions' ) ) {
            add_meta_box(
                'clisyc-membership-wc-link',
                __( 'WooCommerce Subscription Link', 'client-sync-pro' ),
                [ $this, 'render_wc_link_meta_box' ],
                'clisyc_member_plan',
                'side',
                'default'
            );
        }
        // END: Add this new meta box

        // Plan Display Settings — used by the [clisyc_membership_plans] frontend shortcode.
        add_meta_box(
            'clisyc-membership-plan-display',
            __( 'Plan Display Settings (Frontend)', 'client-sync-pro' ),
            [ $this, 'render_display_meta_box' ],
            'clisyc_member_plan',
            'normal',
            'default'
        );
    }

    public function render_rules_meta_box( \WP_Post $post ) {
        wp_nonce_field( 'clisyc_membership_rules_save', 'clisyc_membership_rules_nonce' );
        $rules = get_post_meta( $post->ID, '_clisyc_member_plan_access_rules', true );
        $rules = is_array( $rules ) ? $rules : [];

        echo '<div id="clisyc-membership-rules-container">';
        if ( ! empty( $rules ) ) {
            foreach ( $rules as $index => $rule ) {
                $this->render_rule_row( $index, $rule );
            }
        } else {
            echo '<p id="clisyc-no-rules-message">' . esc_html__( 'No rules defined yet. Click "Add Rule" to get started.', 'client-sync-pro' ) . '</p>';
        }
        echo '</div>';
        ?>
        <p style="margin-top: 15px;">
            <button type="button" class="button button-secondary" id="clisyc-add-rule-btn"><?php esc_html_e( 'Add Rule', 'client-sync-pro' ); ?></button>
        </p>
    
        <!-- Hidden HTML template for new rows -->
        <script type="text/html" id="clisyc-rule-template">
            <?php $this->render_rule_row( '__INDEX__' ); ?>
        </script>
        <?php
    }

    private function render_rule_row( $index, $rule = [] ) {
        $rule_type = $rule['rule_type'] ?? 'booking_limit';
        $service_id = $rule['service_id'] ?? 0;
        $limit_value = $rule['limit_value'] ?? 5;
        $limit_period = $rule['limit_period'] ?? 'per_month';
        $discount_value = $rule['discount_value'] ?? '';
    
        // Get all primary dimension items (e.g., Services) for the dropdown
        $primary_dim_slug = null;
        $registry = get_option( 'clisyc_dimension_registry', [] );
        foreach ($registry['dimensions'] ?? [] as $slug => $settings) {
            if (!empty($settings['primary'])) {
                $primary_dim_slug = $slug;
                break;
            }
        }
        $services = $primary_dim_slug ? get_posts(['post_type' => $primary_dim_slug, 'posts_per_page' => -1, 'post_status' => 'publish']) : [];
        ?>
        <div class="clisyc-membership-rule-row" data-index="<?php echo esc_attr( $index ); ?>">
            <h4 class="clisyc-rule-header">
                <span><?php esc_html_e( 'Rule', 'client-sync-pro' ); ?></span>
                <button type="button" class="button-link clisyc-remove-rule-btn"><span class="dashicons dashicons-trash"></span></button>
            </h4>
            <div class="clisyc-rule-body">
                <div class="clisyc-rule-field">
                    <label><?php esc_html_e( 'Apply To Service', 'client-sync-pro' ); ?></label>
                    <select name="_clisyc_member_plan_access_rules[<?php echo esc_attr( $index ); ?>][service_id]">
                        <option value="0"><?php esc_html_e( 'All Services', 'client-sync-pro' ); ?></option>
                        <?php foreach ( $services as $service ) : ?>
                            <option value="<?php echo esc_attr( $service->ID ); ?>" <?php selected( $service_id, $service->ID ); ?>><?php echo esc_html( $service->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="clisyc-rule-field">
                    <label><?php esc_html_e( 'Rule Type', 'client-sync-pro' ); ?></label>
                    <select name="_clisyc_member_plan_access_rules[<?php echo esc_attr( $index ); ?>][rule_type]" class="clisyc-rule-type-select">
                        <option value="booking_limit" <?php selected( $rule_type, 'booking_limit' ); ?>><?php esc_html_e( 'Booking Limit', 'client-sync-pro' ); ?></option>
                        <option value="discount_percent" <?php selected( $rule_type, 'discount_percent' ); ?>><?php esc_html_e( 'Discount (%)', 'client-sync-pro' ); ?></option>
                        <option value="discount_amount" <?php selected( $rule_type, 'discount_amount' ); ?>><?php esc_html_e( 'Discount (Fixed Amount)', 'client-sync-pro' ); ?></option>
                    </select>
                </div>
                
                <div class="clisyc-rule-options-wrapper">
                    <div class="clisyc-rule-option" data-rule-type="booking_limit">
                        <input type="number" name="_clisyc_member_plan_access_rules[<?php echo esc_attr( $index ); ?>][limit_value]" value="<?php echo esc_attr( $limit_value ); ?>" min="1" class="small-text">
                        <select name="_clisyc_member_plan_access_rules[<?php echo esc_attr( $index ); ?>][limit_period]">
                            <option value="per_week" <?php selected( $limit_period, 'per_week' ); ?>><?php esc_html_e( 'Bookings per Week', 'client-sync-pro' ); ?></option>
                            <option value="per_month" <?php selected( $limit_period, 'per_month' ); ?>><?php esc_html_e( 'Bookings per Month', 'client-sync-pro' ); ?></option>
                            <option value="per_year" <?php selected( $limit_period, 'per_year' ); ?>><?php esc_html_e( 'Bookings per Year', 'client-sync-pro' ); ?></option>
                            <option value="unlimited" <?php selected( $limit_period, 'unlimited' ); ?>><?php esc_html_e( 'Unlimited Bookings', 'client-sync-pro' ); ?></option>
                        </select>
                    </div>
                    <div class="clisyc-rule-option" data-rule-type="discount_percent">
                        <input type="number" name="_clisyc_member_plan_access_rules[<?php echo esc_attr( $index ); ?>][discount_value]" value="<?php echo esc_attr( $discount_value ); ?>" min="0" max="100" class="small-text"> %
                    </div>
                    <div class="clisyc-rule-option" data-rule-type="discount_amount">
                        <input type="number" name="_clisyc_member_plan_access_rules[<?php echo esc_attr( $index ); ?>][discount_value]" value="<?php echo esc_attr( $discount_value ); ?>" min="0" step="0.01" class="small-text">
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renders the Plan Display Settings meta box.
     * These fields control how the plan appears on the frontend [clisyc_membership_plans] shortcode.
     */
    public function render_display_meta_box( \WP_Post $post ) {
        $price_display = get_post_meta( $post->ID, '_clisyc_plan_price_display', true );
        $description   = get_post_meta( $post->ID, '_clisyc_plan_description', true );
        ?>
        <p class="description" style="margin-bottom: 12px;">
            <?php esc_html_e( 'These fields control how this plan appears on the frontend pricing page (via the [clisyc_membership_plans] shortcode).', 'client-sync-pro' ); ?>
        </p>
        <div class="clisyc-rule-field" style="margin-bottom: 12px;">
            <label for="clisyc_plan_price_display"><strong><?php esc_html_e( 'Price Display', 'client-sync-pro' ); ?></strong></label>
            <input type="text" id="clisyc_plan_price_display" name="_clisyc_plan_price_display" value="<?php echo esc_attr( $price_display ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. $29/month, $499/year', 'client-sync-pro' ); ?>">
            <p class="description"><?php esc_html_e( 'A display-only label for the pricing card. This is not connected to Stripe pricing.', 'client-sync-pro' ); ?></p>
        </div>
        <div class="clisyc-rule-field">
            <label for="clisyc_plan_description"><strong><?php esc_html_e( 'Short Description', 'client-sync-pro' ); ?></strong></label>
            <input type="text" id="clisyc_plan_description" name="_clisyc_plan_description" value="<?php echo esc_attr( $description ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Perfect for regular visitors', 'client-sync-pro' ); ?>">
            <p class="description"><?php esc_html_e( 'A short tagline shown below the plan name on the pricing card.', 'client-sync-pro' ); ?></p>
        </div>
        <?php
    }

    public function render_wc_link_meta_box( \WP_Post $post ) {
        wp_nonce_field( 'clisyc_membership_wc_link_save', 'clisyc_membership_wc_link_nonce' );
        $product_id = get_post_meta( $post->ID, '_clisyc_linked_subscription_product_id', true );
        ?>
        <p><?php esc_html_e( 'Link this plan to a WooCommerce "Simple subscription" product to enable automatic recurring payments.', 'client-sync-pro' ); ?></p>
        <select id="clisyc_linked_subscription_product_id" name="_clisyc_linked_subscription_product_id" class="wc-product-search" data-placeholder="<?php esc_attr_e( 'Search for a subscription product…', 'client-sync-pro' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true" data-exclude_type="variable-subscription" style="width: 100%;">
            <?php if ( $product_id && ( $product = wc_get_product( $product_id ) ) ) : ?>
                <option value="<?php echo esc_attr( $product_id ); ?>" selected="selected"><?php echo wp_kses_post( $product->get_formatted_name() ); ?></option>
            <?php endif; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Only "Simple subscription" products will appear in this search.', 'client-sync-pro' ); ?></p>
        <?php
    }

    public function save_meta_data( int $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
    
        // Save access rules
        if ( isset( $_POST['clisyc_membership_rules_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['clisyc_membership_rules_nonce'] ), 'clisyc_membership_rules_save' ) ) {
            
            if ( isset( $_POST['_clisyc_member_plan_access_rules'] ) && is_array( $_POST['_clisyc_member_plan_access_rules'] ) ) {
                
                // --- START: THE FIX ---
                // 1. Unslash the array.
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array is unslashed and then each internal value is sanitized in the loop below.
                $raw_rules = wp_unslash( $_POST['_clisyc_member_plan_access_rules'] );
                
                $sanitized_rules = [];
                foreach ( $raw_rules as $rule ) {
                    // Basic validation to ensure the rule has a type
                    if ( empty( $rule['rule_type'] ) ) continue;
                    
                    // 2. Sanitize each field based on its expected data type
                    $sanitized_rules[] = [
                        'rule_type'      => sanitize_key( $rule['rule_type'] ),
                        'service_id'     => absint( $rule['service_id'] ?? 0 ),
                        'limit_value'    => absint( $rule['limit_value'] ?? 1 ),
                        'limit_period'   => sanitize_key( $rule['limit_period'] ?? 'per_month' ),
                        'discount_value' => floatval( $rule['discount_value'] ?? 0 ),
                    ];
                }
                
                update_post_meta( $post_id, '_clisyc_member_plan_access_rules', $sanitized_rules );
                // --- END: THE FIX ---

            } else {
                delete_post_meta( $post_id, '_clisyc_member_plan_access_rules' );
            }

            // Save display settings (uses same nonce as rules)
            if ( isset( $_POST['_clisyc_plan_price_display'] ) ) {
                update_post_meta( $post_id, '_clisyc_plan_price_display', sanitize_text_field( wp_unslash( $_POST['_clisyc_plan_price_display'] ) ) );
            }
            if ( isset( $_POST['_clisyc_plan_description'] ) ) {
                update_post_meta( $post_id, '_clisyc_plan_description', sanitize_text_field( wp_unslash( $_POST['_clisyc_plan_description'] ) ) );
            }
        }

        // Save WooCommerce subscription link
        if ( isset( $_POST['clisyc_membership_wc_link_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['clisyc_membership_wc_link_nonce'] ), 'clisyc_membership_wc_link_save' ) ) {
            if ( isset( $_POST['_clisyc_linked_subscription_product_id'] ) ) {
                $product_id = absint( $_POST['_clisyc_linked_subscription_product_id'] );
                update_post_meta( $post_id, '_clisyc_linked_subscription_product_id', $product_id );
            }
        }
    }
}