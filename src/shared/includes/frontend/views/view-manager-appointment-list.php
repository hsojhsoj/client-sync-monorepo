<?php
/**
 * File: src/shared/includes/frontend/views/view-manager-appointment-list.php
 * View template for the [clisyc_manager_appointments] shortcode dashboard.
 * UPGRADED: Refined structure for improved styling.
 * FIXED: colspan="6" to match all 6 columns (Client, Service, Date, Time, Status, Actions)
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend/Views
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use DependentMedia\ClientSync\Constants;

/**
 * @var array $atts
 */

$clisyc_primary_service_key = get_option( Constants::OPTION_PRIMARY_SERVICE_DIM, '' );
$clisyc_service_filter_enabled = $clisyc_primary_service_key && isset(get_option(Constants::OPTION_APPOINTMENT_FIELDS, [])[$clisyc_primary_service_key]);
$clisyc_service_field = $clisyc_service_filter_enabled ? get_option(Constants::OPTION_APPOINTMENT_FIELDS)[$clisyc_primary_service_key] : null;

?>
<div id="clisyc-manager-appointments-dashboard" class="clisyc-container">
    <h2><?php esc_html_e( 'Manage Appointments', 'client-sync' ); ?></h2>
    
    <?php if ( 'true' === $atts['filters'] || 'true' === $atts['search'] || 'true' === $atts['export'] ) : ?>
        <div class="clisyc-manager-list-controls">

            <?php if ( 'true' === $atts['search'] ) : ?>
                <div class="clisyc-filter-group clisyc-filter-group--search">
                    <label for="clisyc-manager-appointment-search"><?php esc_html_e('Search', 'client-sync'); ?></label>
                    <input type="text" id="clisyc-manager-appointment-search" placeholder="<?php esc_attr_e('Search by client name...', 'client-sync'); ?>">
                </div>
            <?php endif; ?>

            <?php if ( 'true' === $atts['filters'] ) : ?>
                <div class="clisyc-filter-group clisyc-filter-group--status">
                    <label for="clisyc-manager-appointment-status-filter"><?php esc_html_e('Status', 'client-sync'); ?></label>
                    <select id="clisyc-manager-appointment-status-filter">
                        <option value="upcoming" selected><?php esc_html_e('Upcoming', 'client-sync'); ?></option>
                        <option value="past"><?php esc_html_e('Past', 'client-sync'); ?></option>
                        <option value="all"><?php esc_html_e('All', 'client-sync'); ?></option>
                    </select>
                </div>
                <?php if ($clisyc_service_filter_enabled && !empty($clisyc_service_field['options'])): ?>
                    <div class="clisyc-filter-group clisyc-filter-group--service">
                        <label for="clisyc-manager-service-filter"><?php echo esc_html($clisyc_service_field['label']); ?></label>
                        <select id="clisyc-manager-service-filter">
                            <option value="all"><?php esc_html_e('All Services', 'client-sync'); ?></option>
                            <?php foreach ($clisyc_service_field['options'] as $clisyc_option_item): ?>
                                <option value="<?php echo esc_attr($clisyc_option_item['value']); ?>"><?php echo esc_html($clisyc_option_item['value']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ( 'true' === $atts['export'] ) : ?>
                <form class="clisyc-export-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="clisyc_export_manager_appointments">
                    <?php wp_nonce_field( 'clisyc_export_manager_appointments_action', 'clisyc_export_nonce' ); ?>
                    <input type="hidden" name="query_args" id="clisyc-export-query-args" value="">
                    <button type="submit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="7 10 12 15 17 10"></polyline>
                            <line x1="12" y1="15" x2="12" y2="3"></line>
                        </svg>
                        <?php esc_html_e( 'Export CSV', 'client-sync' ); ?>
                    </button>
                </form>
            <?php endif; ?>

        </div>
    <?php endif; ?>

    <div class="clisyc-table-container">
        <div class="clisyc-loading-overlay" style="display: none;">
            <div class="clisyc-spinner"></div>
        </div>
        <table class="clisyc-appointment-list-table striped" id="clisyc-manager-appointments-table">
            <thead>
                <!-- Table headers populated by JavaScript (6 columns) -->
            </thead>
            <tbody>
                <tr>
                    <!-- FIXED: colspan="6" to match all columns: Client, Service, Date, Time, Status, Actions -->
                    <td colspan="6"><?php esc_html_e('Loading appointments...', 'client-sync'); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="clisyc-pagination-container">
        <!-- Pagination populated by JavaScript -->
    </div>
</div>