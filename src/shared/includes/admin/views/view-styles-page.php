<?php
/**
 * File: src/shared/includes/admin/views/view-styles-page.php -> client-sync/includes/admin/views/view-styles-page.php
 * View for the Styles & Behavior Settings admin page.
 *
 * This file renders the settings page where administrators can configure
 * the frontend appearance of the calendar, booking rules, and other
 * frontend-related behaviors.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<div class="wrap clisyc-styles-behavior-settings"> <?php // *** REFACTORED: CSS class *** ?>
    <h1><?php esc_html_e( 'Style & Behavior Settings', 'client-sync' ); // *** FIX: Updated Text domain *** ?></h1>

    <p>
        <?php esc_html_e( 'Configure the appearance, layout, and behavior of frontend elements like the appointment calendar, forms, and booking rules.', 'client-sync' ); // *** FIX: Updated Text domain *** ?>
    </p>

    <form method="post" action="options.php">
        <?php
        // *** REFACTORED ***: Updated settings group name
        // This WordPress function outputs the necessary hidden fields for the settings group,
        // including the nonce for security. It's essential for the Settings API to work.
        settings_fields( 'clisyc_style_settings_group' );

        // *** REFACTORED ***: Updated page slug for settings sections
        // This WordPress function renders all settings sections and fields that were
        // registered for the 'clisyc-style-settings' page.
        do_settings_sections( 'clisyc-style-settings' );

        // *** REFACTORED ***: Updated text domain
        // This renders the standard "Save Changes" button.
        submit_button( __( 'Save Style & Behavior Settings', 'client-sync' ) ); // *** FIX: Updated Text domain ***
        ?>
    </form>
</div>