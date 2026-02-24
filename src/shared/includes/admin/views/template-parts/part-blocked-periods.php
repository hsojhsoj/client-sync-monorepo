<?php
/**
 * File: src/shared/includes/admin/views/template-parts/part-blocked-periods.php -> client-sync/includes/admin/views/template-parts/part-blocked-periods.php
 * View for the Global Blocked Periods admin page.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views/Template-Parts
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// *** REFACTORED ***: Updated option name from 'cs_global_blocked_periods'
$clisyc_blocked_periods = get_option( \DependentMedia\ClientSync\Constants::OPTION_GLOBAL_BLOCKED_PERIODS, [] );

?>
<div class="wrap clisyc-blocked-periods-page">
    <h1><?php esc_html_e( 'Global Blocked Periods', 'client-sync' ); ?></h1>
    <div class="clisyc-admin-instructions">
        <p><strong><?php esc_html_e( 'What is this?', 'client-sync' ); ?></strong> <?php esc_html_e( 'This tool allows you to block out multi-day periods where no bookings can be made under any circumstances, such as for holidays, company-wide shutdowns, or maintenance periods.', 'client-sync' ); ?></p>
        <p><strong><?php esc_html_e( 'How it works:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Use the form on the left to add a new blocked period. All existing periods are listed in the table on the right. These rules will override all other schedules (weekly templates, resource availability, etc.) for the dates you specify.', 'client-sync' ); ?></p>
    </div>
    
    <?php
    // *** REFACTORED ***: Updated transient name from 'cs_blocked_periods_feedback'
    $clisyc_feedback = get_transient( 'clisyc_blocked_periods_feedback' );
    if ( $clisyc_feedback ) {
        $clisyc_notice_class = ! empty( $clisyc_feedback['error'] ) ? 'notice-error' : 'notice-success';
        $clisyc_message      = $clisyc_feedback['error'] ?? ( $clisyc_feedback['message'] ?? '' );
        if ( $clisyc_message ) {
            echo '<div class="notice ' . esc_attr( $clisyc_notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $clisyc_message ) . '</p></div>';
        }
        delete_transient( 'clisyc_blocked_periods_feedback' );
    }
    ?>

    <div id="col-container" class="wp-clearfix">
        <div id="col-left">
            <div class="col-wrap">
                <div class="form-wrap">
                    <h2><?php esc_html_e( 'Add New Blocked Period', 'client-sync' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php // *** REFACTORED ***: Updated action hook and nonce names ?>
                        <input type="hidden" name="action" value="clisyc_save_blocked_period">
                        <?php wp_nonce_field( 'clisyc_save_blocked_period_action', 'clisyc_blocked_period_nonce' ); ?>
                        
                        <div class="form-field">
                            <label for="blocked_period_title"><?php esc_html_e( 'Title', 'client-sync' ); ?></label>
                            <input name="blocked_period_title" id="blocked_period_title" type="text" required>
                            <p><?php esc_html_e( 'A name for this period, e.g., "Christmas Break".', 'client-sync' ); ?></p>
                        </div>
                        <div class="form-field">
                            <label for="blocked_period_start_date"><?php esc_html_e( 'Start Date', 'client-sync' ); ?></label>
                            <input name="blocked_period_start_date" id="blocked_period_start_date" type="date" required>
                        </div>
                        <div class="form-field">
                            <label for="blocked_period_end_date"><?php esc_html_e( 'End Date', 'client-sync' ); ?></label>
                            <input name="blocked_period_end_date" id="blocked_period_end_date" type="date" required>
                        </div>
                        
                        <?php submit_button( __( 'Add Blocked Period', 'client-sync' ) ); ?>
                    </form>
                </div>
            </div>
        </div>
        <div id="col-right">
            <div class="col-wrap">
                <h2><?php esc_html_e( 'Existing Blocked Periods', 'client-sync' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Title', 'client-sync' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Start Date', 'client-sync' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'End Date', 'client-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $clisyc_blocked_periods ) ) : ?>
                            <?php foreach ( $clisyc_blocked_periods as $clisyc_index => $clisyc_period ) : ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $clisyc_period['title'] ); ?></strong>
                                        <div class="row-actions">
                                            <span class="delete">
                                                <?php
                                                // *** REFACTORED ***: Updated action hook and nonce name for the delete link
                                                $clisyc_delete_url = wp_nonce_url(
                                                    admin_url( 'admin-post.php?action=clisyc_delete_blocked_period&period_index=' . $clisyc_index ),
                                                    'clisyc_delete_blocked_period_' . $clisyc_index
                                                );
                                                ?>
                                                <a href="<?php echo esc_url( $clisyc_delete_url ); ?>" class="delete-tag" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this blocked period?', 'client-sync' ); ?>');">
                                                    <?php esc_html_e( 'Delete', 'client-sync' ); ?>
                                                </a>
                                            </span>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html( wp_date( get_option('date_format'), strtotime( $clisyc_period['start_date'] ) ) ); ?></td>
                                    <td><?php echo esc_html( wp_date( get_option('date_format'), strtotime( $clisyc_period['end_date'] ) ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="3"><?php esc_html_e( 'No global blocked periods have been created yet.', 'client-sync' ); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>