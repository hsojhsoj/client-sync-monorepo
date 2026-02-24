<?php
/**
 * MODIFIED: src/shared/includes/frontend/views/view-appointment-list-shortcode.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

?>
<?php // *** REFACTORED: Updated all element IDs and CSS classes *** ?>
<div id="clisyc-user-appointments-dashboard" class="clisyc-container">
    <h2><?php esc_html_e('Your Appointments', 'client-sync'); ?></h2>
    
    <div class="clisyc-appointment-list-controls">
        <div class="clisyc-filter-group">
            <label for="clisyc-appointment-search"><?php esc_html_e('Search:', 'client-sync'); ?></label>
            <input type="text" id="clisyc-appointment-search" placeholder="<?php esc_attr_e('Search by title...', 'client-sync'); ?>">
        </div>
        <div class="clisyc-filter-group">
            <label for="clisyc-appointment-status-filter"><?php esc_html_e('Show:', 'client-sync'); ?></label>
            <select id="clisyc-appointment-status-filter">
                <option value="upcoming" selected><?php esc_html_e('Upcoming', 'client-sync'); ?></option>
                <option value="past"><?php esc_html_e('Past', 'client-sync'); ?></option>
                <option value="all"><?php esc_html_e('All', 'client-sync'); ?></option>
            </select>
        </div>
    </div>

    <div class="clisyc-table-container">
        <div class="clisyc-loading-overlay" style="display: none;">
            <div class="clisyc-spinner"></div>
        </div>
        <table class="clisyc-appointment-list-table wp-list-table widefat striped fixed" id="clisyc-user-appointments-table">
            <thead>
                <?php // --- START MODIFICATION --- ?>
                <tr>
                    <th scope="col" class="manage-column clisyc-sortable-column" data-orderby="title"><?php esc_html_e('Appointment', 'client-sync'); ?></th>
                    <th scope="col" class="manage-column clisyc-sortable-column sorted asc" data-orderby="clisyc_time_slot"><?php esc_html_e('Date', 'client-sync'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Time', 'client-sync'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Status', 'client-sync'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Actions', 'client-sync'); ?></th>
                </tr>
                <?php // --- END MODIFICATION --- ?>
            </thead>
            <tbody>
                <tr><td colspan="5"><?php esc_html_e('Loading appointments...', 'client-sync'); ?></td></tr>
            </tbody>
        </table>
        <div class="clisyc-empty-message" style="display: none; text-align: center; padding: 20px;">
            <p><?php esc_html_e('No appointments found.', 'client-sync'); ?></p>
        </div>
        <div class="clisyc-error-message" style="display: none; text-align: center; padding: 20px; color: red;">
            
        </div>
    </div>

    <div class="clisyc-pagination-container tablenav-pages">
        <!-- Pagination will be populated by AJAX -->
    </div>
</div>