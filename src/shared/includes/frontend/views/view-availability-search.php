<?php
/**
 * File: src/shared/includes/frontend/views/view-availability-search.php -> client-sync/includes/frontend/views/view-availability-search.php
 * View template for the [clisyc_availability_search] shortcode.
 * This view only renders the search form. Results are displayed on a separate page.
 * REFACTORED: Prefixed all variables to comply with WordPress standards.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend/Views
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Variables passed from the shortcode handler.
 * @var string $clisyc_form_action_url
 * @var string $clisyc_checkin
 * @var string $clisyc_checkout
 * @var array  $clisyc_filterable_fields
 * @var array  $clisyc_attribute_filters
 * @var array  $clisyc_unslashed_get
 */

// Map the old variable names (passed from controller) to new prefixed ones if needed for backward compatibility
// inside this view, although it's cleaner to just use the prefixed versions passed from the updated controller.
// Assuming the controller (class-frontend.php) was updated to pass prefixed variable names or we rename them here locally.
$clisyc_form_action_url = $form_action_url ?? '';
$clisyc_checkin = $checkin ?? '';
$clisyc_checkout = $checkout ?? '';
$clisyc_filterable_fields = $filterable_fields ?? [];
$clisyc_attribute_filters = $attribute_filters ?? [];
$clisyc_unslashed_get = $unslashed_get ?? [];

?>
<div class="clisyc-availability-search-container clisyc-container">
    <div class="clisyc-form-section">
        <h3><?php esc_html_e( 'Find Your Stay', 'client-sync' ); ?></h3>
        <form method="GET" action="<?php echo esc_url( $clisyc_form_action_url ); ?>" class="clisyc-availability-search-form">
            <div class="clisyc-form-fields-wrapper" style="display: flex; flex-wrap: wrap; gap: 20px;">
                <div class="clisyc-form-field" style="flex: 1;">
                    <label for="clisyc-checkin-date"><?php esc_html_e( 'Check-in', 'client-sync' ); ?></label>
                    <input type="text" id="clisyc-checkin-date" name="checkin" value="<?php echo esc_attr( $clisyc_checkin ); ?>" placeholder="YYYY-MM-DD">
                </div>
                <div class="clisyc-form-field" style="flex: 1;">
                    <label for="clisyc-checkout-date"><?php esc_html_e( 'Check-out', 'client-sync' ); ?></label>
                    <input type="text" id="clisyc-checkout-date" name="checkout" value="<?php echo esc_attr( $clisyc_checkout ); ?>" placeholder="YYYY-MM-DD">
                </div>
                
                <?php if ( ! empty( $clisyc_filterable_fields ) ) : ?>
                    <?php foreach ( $clisyc_filterable_fields as $clisyc_key => $clisyc_field ) : ?>
                        <div class="clisyc-form-field" style="flex: 1; min-width: 150px;">
                            <label for="clisyc-attr-<?php echo esc_attr( $clisyc_key ); ?>"><?php echo esc_html( $clisyc_field['label'] ); ?></label>
                            <?php
                            $clisyc_current_value = $clisyc_attribute_filters[ $clisyc_key ] ?? '';
                            if ( 'select' === $clisyc_field['type'] ) :
                                $clisyc_options = array_map( 'trim', explode( ',', $clisyc_field['options'] ) );
                                ?>
                                <select name="clisyc_attr[<?php echo esc_attr( $clisyc_key ); ?>]" id="clisyc-attr-<?php echo esc_attr( $clisyc_key ); ?>">
                                    <option value=""><?php esc_html_e( 'Any', 'client-sync' ); ?></option>
                                    <?php foreach ( $clisyc_options as $clisyc_option ) : ?>
                                        <option value="<?php echo esc_attr( $clisyc_option ); ?>" <?php selected( $clisyc_current_value, $clisyc_option ); ?>>
                                            <?php echo esc_html( $clisyc_option ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else : /* Covers 'text' and 'number' types */ ?>
                                <input type="<?php echo esc_attr( $clisyc_field['type'] ); ?>" name="clisyc_attr[<?php echo esc_attr( $clisyc_key ); ?>]" id="clisyc-attr-<?php echo esc_attr( $clisyc_key ); ?>" value="<?php echo esc_attr( $clisyc_current_value ); ?>" placeholder="<?php esc_attr_e( 'Any', 'client-sync' ); ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="clisyc-form-field" style="flex: 1; min-width: 150px;">
                    <label for="clisyc-sort-by"><?php esc_html_e( 'Sort By', 'client-sync' ); ?></label>
                    <select name="orderby" id="clisyc-sort-by">
                        <option value=""><?php esc_html_e( 'Default Sorting', 'client-sync' ); ?></option>
                        <?php // We can't easily add price here yet, so we'll start with title and date. ?>
                        <option value="title:asc" <?php selected( $clisyc_unslashed_get['orderby'] ?? '', 'title:asc' ); ?>><?php esc_html_e( 'Title (A-Z)', 'client-sync' ); ?></option>
                        <option value="title:desc" <?php selected( $clisyc_unslashed_get['orderby'] ?? '', 'title:desc' ); ?>><?php esc_html_e( 'Title (Z-A)', 'client-sync' ); ?></option>
                        <option value="date:desc" <?php selected( $clisyc_unslashed_get['orderby'] ?? '', 'date:desc' ); ?>><?php esc_html_e( 'Newest First', 'client-sync' ); ?></option>
                        <option value="date:asc" <?php selected( $clisyc_unslashed_get['orderby'] ?? '', 'date:asc' ); ?>><?php esc_html_e( 'Oldest First', 'client-sync' ); ?></option>
                    </select>
                </div>
                
                <div class="clisyc-form-field" style="align-self: flex-end; display: flex; gap: 10px;">
                    <button type="submit" class="button button-primary clisyc-submit-button"><?php esc_html_e( 'Search', 'client-sync' ); ?></button>
                    <?php if ( ! empty( array_filter( array_merge( $clisyc_attribute_filters, [ $clisyc_checkin, $clisyc_checkout ] ) ) ) ) : ?>
                        <a href="<?php echo esc_url( get_permalink() ); ?>" class="button button-secondary clisyc-reset-filters-btn" title="<?php esc_attr_e( 'Reset Filters', 'client-sync' ); ?>">
                            <span class="dashicons dashicons-update"></span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Litepicker !== 'undefined') {
        const picker = new Litepicker({
            element: document.getElementById('clisyc-checkin-date'),
            elementEnd: document.getElementById('clisyc-checkout-date'),
            singleMode: false,
            allowRepick: true,
            minDate: new Date(),
            format: 'YYYY-MM-DD',
            // ================== START: THE FIX ==================
            numberOfMonths: window.innerWidth < 768 ? 1 : 2,
            numberOfColumns: window.innerWidth < 768 ? 1 : 2,
            // =================== END: THE FIX ===================
            tooltipText: {
                one: 'night',
                other: 'nights'
            }
        });
    }
});
</script>