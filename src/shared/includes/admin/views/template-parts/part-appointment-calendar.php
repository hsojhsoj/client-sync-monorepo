<?php
/**
 * File: src/shared/includes/admin/views/template-parts/part-appointment-calendar.php -> client-sync/includes/admin/views/template-parts/part-appointment-calendar.php
 * Template part for displaying the main Admin Appointment Calendar.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views/Template-Parts
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// *** REFACTORED ***: Updated CPT name from 'cs_service' to 'clisyc_service'
$clisyc_services = get_posts([
    'post_type' => 'clisyc_service',
    'posts_per_page' => -1,
    'post_status' => 'publish',
]);

$clisyc_services_for_js = array_map(function($service_post) {
    return [
        'id'    => $service_post->ID,
        'title' => $service_post->post_title,
    ];
}, $clisyc_services);

?>
<div class="clisyc-section postbox"> <?php // *** REFACTORED: CSS class *** ?>
    <h2 class="hndle"><span><?php esc_html_e( 'Appointment Calendar', 'client-sync' ); // FIXED ?></span></h2>
    <div class="inside">
        <div class="clisyc-admin-instructions">
            <p><strong><?php esc_html_e( 'What is this?', 'client-sync' ); ?></strong> <?php esc_html_e( 'This is your main dashboard for all scheduled activity. It provides a read-only "at a glance" view of your entire calendar.', 'client-sync' ); ?></p>
            <p><strong><?php esc_html_e( 'What can I do here?', 'client-sync' ); ?></strong></p>
            <ul class="clisyc-legend">
                <li><span class="clisyc-legend-color clisyc-booked-event"></span> <strong><?php esc_html_e( 'Booked Appointments:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Click to view or edit the appointment details.', 'client-sync' ); ?></li>
                <li><span class="clisyc-legend-color clisyc-available-slot"></span> <strong><?php esc_html_e( 'Available Slots:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Click to create a new appointment for this time.', 'client-sync' ); ?></li>
                <li><span class="clisyc-legend-color clisyc-blocked-slot"></span> <strong><?php esc_html_e( 'Blocked Time:', 'client-sync' ); ?></strong> <?php esc_html_e( 'These times are manually marked as unavailable.', 'client-sync' ); ?></li>
            </ul>
        </div>
        <div id="clisyc-admin-calendar-container" style="margin-top: 1em;"> <?php // *** REFACTORED: Element ID *** ?>
            <div id="clisyc-admin-appointment-calendar" style="min-height: 500px;"> <?php // *** REFACTORED: Element ID *** ?>
                <p style="text-align:center; padding:50px;"><?php esc_html_e( 'Loading Calendar...', 'client-sync' ); // FIXED ?></p>
            </div>
        </div>
    </div>
</div>