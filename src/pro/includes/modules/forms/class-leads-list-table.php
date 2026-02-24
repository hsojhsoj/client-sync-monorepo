<?php
/**
 * File: src/pro/includes/modules/forms/class-leads-list-table.php
 * Defines the WP_List_Table for displaying form leads.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Forms
 */

namespace ClientSyncPro\Modules\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Leads_List_Table extends \WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => __( 'Lead', 'client-sync-pro' ),
            'plural'   => __( 'Leads', 'client-sync-pro' ),
            'ajax'     => false,
        ]);
    }

    public function get_columns() {
        return [
            'cb'          => '<input type="checkbox" />',
            'email'       => __( 'Email', 'client-sync-pro' ),
            'form_name'   => __( 'Submitted To', 'client-sync-pro' ),
            'date'        => __( 'Date', 'client-sync-pro' ),
            'submission'  => __( 'Submission Data', 'client-sync-pro' ),
        ];
    }

    protected function get_sortable_columns() {
        return [
            'email' => [ 'user_email', false ],
            'date'  => [ 'registered', true ],
        ];
    }

    protected function get_bulk_actions() {
        return [
            'bulk_delete' => __( 'Delete', 'client-sync-pro' ),
        ];
    }

    /**
     * Process bulk actions before preparing items.
     */
    public function process_bulk_action() {
        if ( 'bulk_delete' !== $this->current_action() ) {
            return;
        }

        check_admin_referer( 'bulk-' . $this->_args['plural'] );

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array of IDs, each cast to int below.
        $user_ids = isset( $_REQUEST['users'] ) ? array_map( 'absint', (array) $_REQUEST['users'] ) : [];

        if ( empty( $user_ids ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        $deleted = 0;
        foreach ( $user_ids as $user_id ) {
            $user = get_userdata( $user_id );
            if ( $user && in_array( 'clisyc_lead', $user->roles, true ) ) {
                wp_delete_user( $user_id );
                $deleted++;
            }
        }

        if ( $deleted > 0 ) {
            add_action( 'admin_notices', function () use ( $deleted ) {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    esc_html( sprintf(
                        /* translators: %d: number of deleted leads */
                        _n( '%d lead deleted.', '%d leads deleted.', $deleted, 'client-sync-pro' ),
                        $deleted
                    ) )
                );
            } );
        }
    }

    public function prepare_items() {
        $this->process_bulk_action();

        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [ $this->get_columns(), [], $sortable ];

        $per_page = 20;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search/sort params.
        $search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'registered';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order   = isset( $_REQUEST['order'] ) && 'asc' === strtolower( $_REQUEST['order'] ) ? 'ASC' : 'DESC';

        $allowed_orderby = [ 'user_email', 'registered' ];
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
            $orderby = 'registered';
        }

        $args = [
            'role'    => 'clisyc_lead',
            'number'  => $per_page,
            'paged'   => $this->get_pagenum(),
            'orderby' => $orderby,
            'order'   => $order,
        ];

        if ( ! empty( $search ) ) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = [ 'user_email', 'user_login', 'display_name' ];
        }

        $user_query = new \WP_User_Query( $args );
        $this->items = $user_query->get_results();

        $this->set_pagination_args([
            'total_items' => $user_query->get_total(),
            'per_page'    => $per_page,
        ]);
    }

    protected function column_default( $item, $column_name ) {
        return '—';
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="users[]" value="%s" />', $item->ID );
    }

    protected function column_email( $item ) {
        $actions = [
            'delete' => sprintf(
                '<a href="%s" class="delete-tag">%s</a>',
                wp_nonce_url( "users.php?action=delete&user={$item->ID}", 'delete-user_' . $item->ID ),
                __( 'Delete', 'client-sync-pro' )
            ),
        ];
        return '<strong>' . esc_html( $item->user_email ) . '</strong>' . $this->row_actions( $actions );
    }

    protected function column_date( $item ) {
        return wp_date( get_option( 'date_format' ), strtotime( $item->user_registered ) );
    }

    protected function column_form_name( $item ) {
        $submission_data = get_user_meta( $item->ID, '_clisyc_form_submission_data', true );
        return esc_html( $submission_data['form_name'] ?? 'N/A' );
    }

    protected function column_submission( $item ) {
        $submission_data = get_user_meta( $item->ID, '_clisyc_form_submission_data', true );
        $data_to_display = $submission_data['fields'] ?? [];

        if ( empty( $data_to_display ) ) {
            return '—';
        }

        $output = '<ul style="margin: 0;">';
        foreach ( $data_to_display as $label => $value ) {
            $output .= '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</li>';
        }
        $output .= '</ul>';
        return $output;
    }
}
