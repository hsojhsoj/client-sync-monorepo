<?php
/**
 * File: src/shared/includes/admin/views/view-appearance-page.php
 * View for the Appearance Settings admin page.
 *
 * This file renders the settings page where administrators can configure
 * the visual appearance of calendars, colors, and display options.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<div class="wrap clisyc-appearance-settings">
    <h1><?php esc_html_e( 'Appearance Settings', 'client-sync' ); ?></h1>

    <p class="description">
        <?php esc_html_e( 'Configure the visual appearance of calendars, event colors, and display options for your booking interface.', 'client-sync' ); ?>
    </p>

    <form method="post" action="options.php">
        <?php
        // Output security fields for the registered setting group.
        settings_fields( 'clisyc_appearance_settings_group' );

        // Output setting sections and their fields for the appearance page.
        do_settings_sections( 'clisyc-appearance-settings' );

        // Render the submit button.
        submit_button( __( 'Save Appearance Settings', 'client-sync' ) );
        ?>
    </form>

    <hr style="margin: 40px 0;">

    <div class="clisyc-color-preview">
        <h2><?php esc_html_e( 'Color Preview', 'client-sync' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Preview of how your calendar events will appear with the current color settings.', 'client-sync' ); ?></p>

        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
            <!-- Available Slot Preview -->
            <div class="clisyc-preview-card" style="padding: 15px; border-radius: 6px; min-width: 150px; background: var(--clisyc-available-bg, #d1fae5); color: var(--clisyc-available-text, #065f46); border: 1px solid var(--clisyc-available-border, #d1fae5);">
                <strong><?php esc_html_e( 'Available', 'client-sync' ); ?></strong>
                <div style="font-size: 0.9em; margin-top: 5px;">10:00 AM - 11:00 AM</div>
            </div>

            <!-- Booked Slot Preview -->
            <div class="clisyc-preview-card" style="padding: 15px; border-radius: 6px; min-width: 150px; background: var(--clisyc-booked-bg, #fee2e2); color: var(--clisyc-booked-text, #991b1b); border: 1px solid var(--clisyc-booked-border, #fee2e2);">
                <strong><?php esc_html_e( 'Booked', 'client-sync' ); ?></strong>
                <div style="font-size: 0.9em; margin-top: 5px;">2:00 PM - 3:00 PM</div>
            </div>

            <!-- Blocked Slot Preview -->
            <div class="clisyc-preview-card" style="padding: 15px; border-radius: 6px; min-width: 150px; background: var(--clisyc-blocked-bg, #e5e7eb); color: var(--clisyc-blocked-text, #374151); border: 1px solid var(--clisyc-blocked-border, #e5e7eb);">
                <strong><?php esc_html_e( 'Blocked', 'client-sync' ); ?></strong>
                <div style="font-size: 0.9em; margin-top: 5px;">12:00 PM - 1:00 PM</div>
            </div>

            <!-- Accent Button Preview -->
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <button type="button" class="clisyc-preview-button" style="padding: 10px 20px; border-radius: 4px; border: none; cursor: pointer; background: var(--clisyc-accent-bg, #3b82f6); color: var(--clisyc-accent-text, #ffffff);">
                    <?php esc_html_e( 'Book Now', 'client-sync' ); ?>
                </button>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: var(--clisyc-icon-bg, #f3f4f6); color: var(--clisyc-icon-text, #4b5563);">
                        <span class="dashicons dashicons-calendar-alt"></span>
                    </span>
                    <span style="font-size: 0.9em; color: #666;"><?php esc_html_e( 'Icon', 'client-sync' ); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Live preview CSS variables - these will be overridden by wp_add_inline_style on frontend */
.clisyc-appearance-settings {
    --clisyc-available-bg: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['available_bg'] ); ?>;
    --clisyc-available-text: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['available_text'] ); ?>;
    --clisyc-available-border: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['available_bg'] ); ?>;
    --clisyc-booked-bg: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['booked_bg'] ); ?>;
    --clisyc-booked-text: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['booked_text'] ); ?>;
    --clisyc-booked-border: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['booked_bg'] ); ?>;
    --clisyc-blocked-bg: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['blocked_bg'] ); ?>;
    --clisyc-blocked-text: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['blocked_text'] ); ?>;
    --clisyc-blocked-border: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['blocked_bg'] ); ?>;
    --clisyc-accent-bg: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['accent_normal_bg'] ); ?>;
    --clisyc-accent-text: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['accent_normal_text'] ); ?>;
    --clisyc-icon-bg: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['icon_bg'] ); ?>;
    --clisyc-icon-text: <?php echo esc_attr( \DependentMedia\ClientSync\Admin\Settings_Manager::get_color_settings()['icon_text'] ); ?>;
}

.clisyc-color-preview {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.clisyc-preview-button:hover {
    filter: brightness(0.9);
}
</style>