<?php
/**
 * File: src/shared/includes/admin/views/view-available-slots-list-page.php -> client-sync/includes/admin/views/view-available-slots-list-page.php
 * View for the Available & Blocked Time Slots List admin page.
 *
 * This file renders the list table for managing individual time slots.
 * It instantiates and uses the refactored Available_Slots_List_Table class,
 * which queries the custom database tables for data.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// The custom list table class must be loaded before this view is rendered.
// This is handled by the Admin class that includes this view file.

// *** REFACTORED ***: Instantiate the List Table from its new namespace.
$clisyc_list_table = new \DependentMedia\ClientSync\Admin\ListTables\Available_Slots_List_Table();
$clisyc_list_table->prepare_items();

?>
<div class="wrap">
    <h1><?php esc_html_e( 'Available & Blocked Time Slots', 'client-sync' ); ?></h1>

    <?php
    $clisyc_pagination = $clisyc_list_table->get_pagination_arg( 'total_items' );
    $clisyc_total_pages = $clisyc_list_table->get_pagination_arg( 'total_pages' );
    $clisyc_current_page = $clisyc_list_table->get_pagenum();
    ?>
    <p>
        <?php
        printf(
            /* translators: 1: total slot count, 2: current page, 3: total pages */
            esc_html__( '%1$s total time slots (page %2$d of %3$d)', 'client-sync' ),
            '<strong>' . number_format_i18n( $clisyc_pagination ) . '</strong>',
            $clisyc_current_page,
            max( 1, $clisyc_total_pages )
        );
        ?>
    </p>

    <?php
    // *** REFACTORED ***: Updated transient name for feedback messages.
    $clisyc_feedback = get_transient( 'clisyc_manage_slots_feedback' );
    if ( $clisyc_feedback ) {
        $clisyc_notice_class = ! empty( $clisyc_feedback['error'] ) ? 'notice-error' : 'notice-success';
        $clisyc_notice_message = $clisyc_feedback['error'] ?? ( $clisyc_feedback['message'] ?? '' );
        if ($clisyc_notice_message) {
            echo '<div class="notice ' . esc_attr( $clisyc_notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $clisyc_notice_message ) . '</p></div>';
        }
        delete_transient( 'clisyc_manage_slots_feedback' );
    }
    ?>

    <div class="cs-list-table-controls" style="margin-bottom: 15px;">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to permanently remove all *available* (not blocked) time slots from before today? This cannot be undone.', 'client-sync' ); ?>');" style="display: inline-block;">
            <?php // *** REFACTORED ***: Updated nonce name and action. ?>
            <?php wp_nonce_field( 'clisyc_remove_past_slots_action', 'clisyc_remove_past_nonce' ); ?>
            <input type="hidden" name="action" value="clisyc_handle_remove_past_slots">
            <button type="submit" name="clisyc_remove_past_submit" class="button button-secondary">
                <span class="dashicons dashicons-trash" style="vertical-align: text-bottom; margin-right: 3px;"></span>
                <?php esc_html_e( 'Cleanup Past Available Slots', 'client-sync' ); ?>
            </button>
            <p class="description" style="display: inline-block; margin-left: 10px;">
                <?php esc_html_e( 'This action runs an efficient database query to remove past "Available" slots. It does not affect "Blocked" slots.', 'client-sync' ); ?>
            </p>
        </form>
    </div>

    <!-- The main form for the list table, which handles bulk actions AND filtering -->
    <form method="get" id="clisyc-available-slots-list-form">
        <?php // *** REFACTORED ***: Updated page slug value. ?>
        <input type="hidden" name="page" value="<?php echo isset( $_REQUEST['page'] ) ? esc_attr( sanitize_key( wp_unslash( $_REQUEST['page'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" />
        
        <?php
        // The WP_List_Table class will add a hidden nonce field for bulk actions automatically.
        // The `extra_tablenav` method (called by `display()`) will now render our custom filter controls.

        // Render the list table. The `display()` method handles the entire table output,
        // including the search box, bulk actions dropdowns, filter controls, table rows, and pagination.
        $clisyc_list_table->display();
        ?>
    </form>
</div>