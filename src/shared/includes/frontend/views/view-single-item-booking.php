<?php
/**
 * File: src/shared/includes/frontend/views/view-single-item-booking.php -> client-sync/includes/frontend/views/view-single-item-booking.php
 * View template for the new [clisyc_single_item_booking] shortcode.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend/Views
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use DependentMedia\ClientSync\Constants;

/**
 * @var array $appointment_custom_fields
 * @var array $appointment_field_order
 * @var \DependentMedia\ClientSync\Services\FormRenderer $form_renderer
 */
?>
<div class="clisyc-date-range-booking-form">
    <?php
    // Render any feedback messages passed via transients
    $clisyc_output_buffer  = '';
    $clisyc_feedback_types = [ 'notice', 'success', 'error' ];
    foreach ( $clisyc_feedback_types as $clisyc_type ) {
        $clisyc_message = get_transient( "clisyc_booking_feedback_{$clisyc_type}" );
        if ( $clisyc_message ) {
            $clisyc_icon           = ( 'error' === $clisyc_type ) ? 'warning' : 'info-outline';
            $clisyc_output_buffer .= '<div class="clisyc-registration-message clisyc-' . $clisyc_type . '-message"><span class="dashicons dashicons-' . $clisyc_icon . '"></span><p>' . wp_kses_post( $clisyc_message ) . '</p></div>';
            delete_transient( "clisyc_booking_feedback_{$clisyc_type}" );
        }
    }
    echo wp_kses_post( $clisyc_output_buffer );
    ?>

    <form method="post" id="clisyc_appointment_form" action="<?php echo esc_url(get_permalink()); ?>">
        <input type="hidden" name="_clisyc_frontend_booking_submit" value="1">
        <input type="hidden" name="booking_mode" value="date_range">
        <input type="hidden" name="start_date" id="clisyc_start_date_hidden">
        <input type="hidden" name="end_date" id="clisyc_end_date_hidden">
        <div id="clisyc-hidden-inputs-container"></div>
        <?php wp_nonce_field( Constants::POST_TYPE_APPOINTMENT, 'clisyc_appointment_nonce' ); ?>

        <div id="clisyc-availability-filters" style="display:none;"></div>

        <?php // The redundant details container has been removed from here. ?>

        <div id="clisyc-date-range-picker-container" class="clisyc-form-section">
            <h3><?php esc_html_e('1. Select Your Dates', 'client-sync'); ?></h3>
            <div id="clisyc-inline-datepicker"><p><em><?php esc_html_e('Loading availability...', 'client-sync'); ?></em></p></div>
            <div id="clisyc-date-range-summary" style="margin-top: 1em; padding: 10px; background: #f0f8ff; border: 1px solid #d1e9ff; border-radius: 4px; display:none;">
                <p style="margin:0;">
                    <strong><?php esc_html_e('Check-in:', 'client-sync'); ?></strong> <span id="clisyc-summary-start-date"></span><br>
                    <strong><?php esc_html_e('Check-out:', 'client-sync'); ?></strong> <span id="clisyc-summary-end-date"></span><br>
                    <strong><?php esc_html_e('Total Nights:', 'client-sync'); ?></strong> <span id="clisyc-summary-nights"></span>
                </p>
                <div id="clisyc-dynamic-price-container" class="clisyc-service-price" style="margin-top: 5px;"></div>
            </div>
        </div>
        
        <div class="clisyc-form-section" id="clisyc-appointment-details-section">
            <h3><?php esc_html_e('2. Additional Details', 'client-sync'); ?></h3>
            <?php if (!empty($appointment_field_order) && !empty($appointment_custom_fields)) : ?>
                <?php
                foreach ($appointment_field_order as $clisyc_field_key) {
                    if (!isset($appointment_custom_fields[$clisyc_field_key])) continue;
                    $form_renderer->render_frontend_custom_field($clisyc_field_key, $appointment_custom_fields[$clisyc_field_key], null, 'appointment');
                }
                ?>
            <?php else: ?>
                <p><?php esc_html_e('No additional details are required.', 'client-sync'); ?></p>
            <?php endif; ?>
        </div>

        <p style="margin-top: 1.5em;">
            <button type="submit" id="schedule_button" class="clisyc-submit-button button button-primary" disabled>
                <?php esc_html_e('Select Dates to Continue', 'client-sync'); ?>
            </button>
        </p>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Litepicker !== 'undefined') {
        const picker = new Litepicker({
            element: document.getElementById('clisyc-inline-datepicker'),
            // ... other options
            numberOfMonths: 2,
            numberOfColumns: 2,
            // ... other options
        });
    }
});
</script>