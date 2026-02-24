<?php
/**
 * File: src/pro/includes/modules/packages/class-packages-cpt.php
 * Registers the 'clisyc_package' CPT and its meta boxes.
 * MODIFIED: Simplified menu registration.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Packages
 */

namespace ClientSyncPro\Modules\Packages;

if ( ! defined( 'ABSPATH' ) ) exit;

class Packages_CPT {

    public function register_hooks() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes_clisyc_package', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_clisyc_package', [ $this, 'save_meta_data' ] );
    }

    public function register_cpt() {
        $labels = [
            'name'                  => _x( 'Packages', 'Post type general name', 'client-sync-pro' ),
            'singular_name'         => _x( 'Package', 'Post type singular name', 'client-sync-pro' ),
            'menu_name'             => _x( 'Packages', 'Admin Menu text', 'client-sync-pro' ),
            'add_new_item'          => __( 'Add New Package', 'client-sync-pro' ),
            'edit_item'             => __( 'Edit Package', 'client-sync-pro' ),
            'all_items'             => __( 'All Packages', 'client-sync-pro' ),
        ];
        $args = [
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'client-sync',
            'capability_type'     => 'post',
            'supports'            => [ 'title' ],
            'menu_icon'           => 'dashicons-products',
        ];
        register_post_type( 'clisyc_package', $args );
    }

    public function add_meta_boxes() {
        add_meta_box( 'clisyc-package-rules', __( 'Package Credit Rules', 'client-sync-pro' ), [ $this, 'render_rules_meta_box' ], 'clisyc_package', 'normal', 'high' );
        if ( class_exists( 'WooCommerce' ) ) {
            add_meta_box( 'clisyc-package-wc-link', __( 'WooCommerce Product Link', 'client-sync-pro' ), [ $this, 'render_wc_link_meta_box' ], 'clisyc_package', 'side', 'default' );
        }
    }

    public function render_wc_link_meta_box( \WP_Post $post ) {
        wp_nonce_field( 'clisyc_package_wc_link_save', 'clisyc_package_wc_link_nonce' );
        $product_id = get_post_meta( $post->ID, '_clisyc_linked_product_id', true );
        ?>
        <p><?php esc_html_e( 'Link this package to a "Simple" and "Virtual" WooCommerce product.', 'client-sync-pro' ); ?></p>
        <select id="clisyc_linked_product_id" name="_clisyc_linked_product_id" class="wc-product-search" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'client-sync-pro' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true" style="width: 100%;">
            <?php if ( $product_id && ( $product = wc_get_product( $product_id ) ) ) : ?>
                <option value="<?php echo esc_attr( $product_id ); ?>" selected="selected"><?php echo wp_kses_post( $product->get_formatted_name() ); ?></option>
            <?php endif; ?>
        </select>
        <?php
    }

    public function render_rules_meta_box( \WP_Post $post ) {
        wp_nonce_field( 'clisyc_package_rules_save', 'clisyc_package_rules_nonce' );
        $rules = get_post_meta( $post->ID, '_clisyc_package_rules', true );
        $rules = is_array($rules) ? $rules : [];

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
        <p><?php esc_html_e( 'Define which services this package grants credits for.', 'client-sync-pro' ); ?></p>
        <table class="form-table">
            <tr>
                <th><label for="package_service_id"><?php esc_html_e( 'Service Credit', 'client-sync-pro' ); ?></label></th>
                <td>
                    <select name="_clisyc_package_rules[service_id]" id="package_service_id">
                        <option value="0"><?php esc_html_e( '-- Select a Service --', 'client-sync-pro' ); ?></option>
                        <?php foreach ( $services as $service ) : ?>
                            <option value="<?php echo esc_attr( $service->ID ); ?>" <?php selected( $rules['service_id'] ?? 0, $service->ID ); ?>><?php echo esc_html( $service->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
             <tr>
                <th><label for="package_credits"><?php esc_html_e( 'Number of Credits', 'client-sync-pro' ); ?></label></th>
                <td>
                    <input type="number" name="_clisyc_package_rules[credits]" id="package_credits" value="<?php echo esc_attr( $rules['credits'] ?? 10 ); ?>" min="1" class="small-text">
                </td>
            </tr>
            <tr>
                <th><label for="package_expires_days"><?php esc_html_e( 'Credits Expire After', 'client-sync-pro' ); ?></label></th>
                <td>
                    <input type="number" name="_clisyc_package_rules[expires_days]" id="package_expires_days" value="<?php echo esc_attr( $rules['expires_days'] ?? 0 ); ?>" min="0" class="small-text">
                    <p class="description"><?php esc_html_e( 'Days until credits expire. Leave as 0 for no expiration.', 'client-sync-pro' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_meta_data( int $post_id ) {
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        if ( isset( $_POST['clisyc_package_wc_link_nonce'] ) && wp_verify_nonce( sanitize_key($_POST['clisyc_package_wc_link_nonce']), 'clisyc_package_wc_link_save' ) ) {
            $product_id = isset( $_POST['_clisyc_linked_product_id'] ) ? absint( $_POST['_clisyc_linked_product_id'] ) : 0;
            update_post_meta( $post_id, '_clisyc_linked_product_id', $product_id );
        }

        if ( isset( $_POST['clisyc_package_rules_nonce'] ) && wp_verify_nonce( sanitize_key($_POST['clisyc_package_rules_nonce']), 'clisyc_package_rules_save' ) ) {
            
            // 1. Unslash the array to remove WP's automatic magic quotes.
            // 2. Wrap in an ignore comment to tell the scanner we are handling the internal values manually.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array structure is processed and sanitized in the loop below.
            $raw_rules = isset($_POST['_clisyc_package_rules']) ? wp_unslash($_POST['_clisyc_package_rules']) : [];
            
            // 3. Manually map and sanitize each specific field.
            $sanitized_rules = [
                'service_id'   => isset($raw_rules['service_id']) ? absint($raw_rules['service_id']) : 0,
                'credits'      => isset($raw_rules['credits']) ? absint($raw_rules['credits']) : 0,
                'expires_days' => isset($raw_rules['expires_days']) ? absint($raw_rules['expires_days']) : 0,
            ];
            
            update_post_meta( $post_id, '_clisyc_package_rules', $sanitized_rules );
        }
    }
}