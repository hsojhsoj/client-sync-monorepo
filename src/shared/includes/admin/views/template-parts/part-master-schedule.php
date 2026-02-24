<?php
/**
 * File: src/shared/includes/admin/views/template-parts/part-master-schedule.php
 * View for the new Master Schedule tab.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views/Template-Parts
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="clisyc-section postbox">
    <h2 class="hndle"><span><?php esc_html_e( 'Master Availability Schedule', 'client-sync' ); ?></span></h2>
    <div class="inside">
        <div class="clisyc-admin-instructions">
            <p><strong><?php esc_html_e( 'What is this?', 'client-sync' ); ?></strong> <?php esc_html_e( 'This is a powerful diagnostic tool that shows all scheduling layers at once. It helps you understand why a time slot is or isn\'t available.', 'client-sync' ); ?></p>
            <p><strong><?php esc_html_e( 'How to read it:', 'client-sync' ); ?></strong></p>
            <ul class="clisyc-legend">
                <li><span class="clisyc-legend-color clisyc-schedule-template"></span> <strong><?php esc_html_e( 'Schedule Templates (Background):', 'client-sync' ); ?></strong> <?php esc_html_e( 'Striped areas show potential availability from your Base Weekly Schedules. Click a striped area to edit its source schedule.', 'client-sync' ); ?></li>
                <li><span class="clisyc-legend-color clisyc-available-slot"></span> <strong><?php esc_html_e( 'Generated Available Slots:', 'client-sync' ); ?></strong> <?php esc_html_e( 'These are the actual, bookable slots created from the intersection of all schedules.', 'client-sync' ); ?></li>
                <li><span class="clisyc-legend-color clisyc-booked-event"></span> <strong><?php esc_html_e( 'Booked & Blocked Time:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Solid blocks that take precedence over available slots.', 'client-sync' ); ?></li>
            </ul>
            <p><em><strong><?php esc_html_e( 'Tip:', 'client-sync' ); ?></strong> <?php esc_html_e( 'If you see a striped template area but no green "Available" slot on top of it, it means something is preventing the slot from being generated (e.g., a conflict with a linked resource\'s schedule, an existing booking, or a global block).', 'client-sync' ); ?></em></p>
        </div>
        <div id="clisyc-master-schedule-calendar-container" style="margin-top: 1em;">
            <div id="clisyc-master-schedule-calendar" style="min-height: 600px;">
                <p style="text-align:center; padding:50px;"><?php esc_html_e( 'Loading Master Schedule...', 'client-sync' ); ?></p>
            </div>
        </div>
    </div>
</div>