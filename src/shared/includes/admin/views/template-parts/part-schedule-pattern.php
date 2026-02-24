<?php
/**
 * File: src/shared/includes/admin/views/template-parts/part-schedule-pattern.php -> client-sync/includes/admin/views/template-parts/part-schedule-pattern.php
 * View for the Schedule Pattern meta box.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views/Template-Parts
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="clisyc-schedule-pattern-settings">
    <p class="clisyc-pattern-setting" style="display: none;">
        <label for="clisyc-schedule-pattern-start"><strong><?php esc_html_e( 'Pattern Start Date', 'client-sync' ); ?></strong></label>
        <input type="date" id="clisyc-schedule-pattern-start" name="clisyc_pattern_start_date" class="widefat">
        <em class="description"><?php esc_html_e( 'Date when "Week A" begins.', 'client-sync' ); ?></em>
    </p>
    <p class="clisyc-pattern-setting" style="display: none;">
        <label for="clisyc-schedule-pattern-sequence"><strong><?php esc_html_e( 'Pattern Sequence', 'client-sync' ); ?></strong></label>
        <input type="text" id="clisyc-schedule-pattern-sequence" name="clisyc_pattern_sequence" placeholder="A, B" class="widefat">
        <em class="description"><?php esc_html_e( 'e.g., A, B or A, B, A, C', 'client-sync' ); ?></em>
    </p>
    <p class="description">
        <?php esc_html_e( 'To enable a rotating schedule, add more than one weekly template in the "Base Weekly Schedule" editor above.', 'client-sync' ); ?>
    </p>
</div>