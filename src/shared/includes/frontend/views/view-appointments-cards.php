<?php
/**
 * File: src/shared/includes/frontend/views/view-appointments-cards.php
 * View template for the [clisyc_appointments_cards] shortcode.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend/Views
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Variables passed from the shortcode handler:
 * @var array $atts Shortcode attributes.
 */
$clisyc_title       = $atts['title'];
$clisyc_show_search = filter_var($atts['show_search'], FILTER_VALIDATE_BOOLEAN);
$clisyc_show_filter = filter_var($atts['show_filter'], FILTER_VALIDATE_BOOLEAN);
?>
<div id="clisyc-appointments-cards-dashboard" data-layout="card-layout">
    <h2><?php echo esc_html($clisyc_title); ?></h2>

    <?php if ($clisyc_show_search || $clisyc_show_filter) : ?>
        <div class="clisyc-cards-filter-controls">
            <?php if ($clisyc_show_search) : ?>
                <div class="clisyc-filter-group">
                    <label for="clisyc-cards-search"><?php esc_html_e('Search', 'client-sync'); ?></label>
                    <input type="text" id="clisyc-cards-search" placeholder="<?php esc_attr_e('Search by title...', 'client-sync'); ?>" aria-label="<?php esc_attr_e('Search Appointments', 'client-sync'); ?>">
                </div>
            <?php endif; ?>
            <?php if ($clisyc_show_filter) : ?>
                <div class="clisyc-filter-group">
                    <label for="clisyc-cards-status-filter"><?php esc_html_e('Show', 'client-sync'); ?></label>
                    <select id="clisyc-cards-status-filter" aria-label="<?php esc_attr_e('Filter Appointments by Status', 'client-sync'); ?>">
                        <option value="upcoming" selected><?php esc_html_e('Upcoming', 'client-sync'); ?></option>
                        <option value="past"><?php esc_html_e('Past', 'client-sync'); ?></option>
                        <option value="all"><?php esc_html_e('All', 'client-sync'); ?></option>
                    </select>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="clisyc-cards-state-container">
        <div class="clisyc-cards-loading-overlay" role="status">
            <div class="clisyc-cards-spinner"></div>
        </div>

        <div class="clisyc-appointments-cards-grid">
            <!-- Appointments will be loaded here by JavaScript -->
        </div>

        <div class="clisyc-cards-empty-state" role="status">
            <p><?php esc_html_e('No appointments found matching your criteria.', 'client-sync'); ?></p>
        </div>

        <div class="clisyc-cards-error-message" role="alert">
            <!-- Error messages will be populated by JavaScript -->
        </div>
    </div>

    <nav class="clisyc-cards-pagination" role="navigation" aria-label="<?php esc_attr_e('Appointments navigation', 'client-sync'); ?>">
        <!-- Pagination links will be populated by JavaScript -->
    </nav>
</div>