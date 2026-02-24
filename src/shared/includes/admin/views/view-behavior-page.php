<?php
/**
 * File: src/shared/includes/admin/views/view-behavior-page.php
 * View for the Behavior Settings admin page.
 *
 * This file renders the settings page where administrators can configure
 * booking rules, frontend links, self-service options, spam protection,
 * and HIPAA compliance settings.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get HIPAA status for conditional display
$hipaa_helper    = \DependentMedia\ClientSync\Services\HIPAA_Helper::get_instance();
$hipaa_status    = $hipaa_helper->get_status();
$is_hipaa_active = $hipaa_status['enabled'] && $hipaa_status['operational'];

?>
<div class="wrap clisyc-behavior-settings">
    <h1><?php esc_html_e( 'Behavior Settings', 'client-sync' ); ?></h1>

    <p class="description">
        <?php esc_html_e( 'Configure booking rules, frontend page links, client self-service options, spam protection, and HIPAA compliance.', 'client-sync' ); ?>
    </p>

    <?php if ( $is_hipaa_active ) : ?>
    <div class="notice notice-info inline" style="margin: 15px 0;">
        <p>
            <span class="dashicons dashicons-shield-alt" style="color: #00a32a;"></span>
            <strong><?php esc_html_e( 'HIPAA Mode is Active', 'client-sync' ); ?></strong> —
            <?php esc_html_e( 'Sensitive data is being encrypted. Audit logging is enabled.', 'client-sync' ); ?>
        </p>
    </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php
        // Output security fields for the registered setting group.
        settings_fields( 'clisyc_behavior_settings_group' );

        // Output setting sections and their fields for the behavior page.
        do_settings_sections( 'clisyc-behavior-settings' );

        // Render the submit button.
        submit_button( __( 'Save Behavior Settings', 'client-sync' ) );
        ?>
    </form>
</div>

<style>
/* HIPAA Section Styles */
.clisyc-hipaa-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 12px;
    border-radius: 4px;
    font-weight: 500;
    margin: 10px 0;
}

.clisyc-hipaa-status--active {
    background: #d1fae5;
    color: #065f46;
}

.clisyc-hipaa-status--warning {
    background: #fef3c7;
    color: #92400e;
}

.clisyc-hipaa-locked {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 8px;
    padding: 8px 12px;
    background: #f0f0f1;
    border-left: 4px solid #2271b1;
    color: #1d2327;
}

.clisyc-hipaa-locked .dashicons {
    color: #2271b1;
}
</style>