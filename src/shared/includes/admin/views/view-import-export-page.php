<?php
/**
 * File: src/shared/includes/admin/views/view-import-export-page.php -> client-sync/includes/admin/views/view-import-export-page.php
 * View for the Import / Export Settings tab.
 *
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 * MODIFIED: Reorganized layout for better usability.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="clisyc-import-export-settings">

    <!-- ═══════════════════════════════════════════════════════════════════
         SECTION 1: Export Appointments (CSV / ICS)
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="postbox">
        <h2 class="hndle"><span><?php esc_html_e( 'Export Appointments', 'client-sync' ); ?></span></h2>
        <div class="inside">
            <p><?php esc_html_e( 'Download your appointments as a spreadsheet (CSV) or calendar file (ICS) that can be imported into Google Calendar, Outlook, or Apple Calendar.', 'client-sync' ); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="clisyc_export_appt_start"><?php esc_html_e( 'Start Date', 'client-sync' ); ?></label></th>
                    <td><input type="date" id="clisyc_export_appt_start" form="clisyc-export-appt-csv" name="start_date" value="<?php echo esc_attr( wp_date( 'Y-01-01' ) ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="clisyc_export_appt_end"><?php esc_html_e( 'End Date', 'client-sync' ); ?></label></th>
                    <td><input type="date" id="clisyc_export_appt_end" form="clisyc-export-appt-csv" name="end_date" value="<?php echo esc_attr( wp_date( 'Y-12-31' ) ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="clisyc_export_appt_status"><?php esc_html_e( 'Status', 'client-sync' ); ?></label></th>
                    <td>
                        <select id="clisyc_export_appt_status" form="clisyc-export-appt-csv" name="export_status">
                            <option value="any"><?php esc_html_e( 'All Statuses', 'client-sync' ); ?></option>
                            <option value="publish"><?php esc_html_e( 'Confirmed', 'client-sync' ); ?></option>
                            <option value="pending"><?php esc_html_e( 'Pending', 'client-sync' ); ?></option>
                            <option value="clisyc_pending_pay"><?php esc_html_e( 'Awaiting Payment', 'client-sync' ); ?></option>
                            <option value="clisyc_paid_on_day"><?php esc_html_e( 'Paid on Day', 'client-sync' ); ?></option>
                            <option value="cancelled"><?php esc_html_e( 'Cancelled', 'client-sync' ); ?></option>
                            <option value="wc-processing"><?php esc_html_e( 'WC Processing', 'client-sync' ); ?></option>
                            <option value="wc-completed"><?php esc_html_e( 'WC Completed', 'client-sync' ); ?></option>
                        </select>
                    </td>
                </tr>
            </table>

            <p class="submit" style="display: flex; gap: 8px; padding-top: 0;">
                <!-- CSV form -->
                <form id="clisyc-export-appt-csv" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'clisyc_export_appointments', 'clisyc_export_appt_nonce' ); ?>
                    <input type="hidden" name="action" value="clisyc_export_appointments_csv">
                    <button type="submit" class="button button-secondary">
                        <span class="dashicons dashicons-media-spreadsheet" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e( 'Export CSV', 'client-sync' ); ?>
                    </button>
                </form>

                <!-- ICS form (shares the same filter fields via the form= attribute above) -->
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'clisyc_export_appointments', 'clisyc_export_appt_nonce' ); ?>
                    <input type="hidden" name="action" value="clisyc_export_appointments_ics">
                    <input type="hidden" name="start_date" value="">
                    <input type="hidden" name="end_date" value="">
                    <input type="hidden" name="export_status" value="">
                    <button type="submit" class="button button-secondary" onclick="this.form.start_date.value=document.getElementById('clisyc_export_appt_start').value;this.form.end_date.value=document.getElementById('clisyc_export_appt_end').value;this.form.export_status.value=document.getElementById('clisyc_export_appt_status').value;">
                        <span class="dashicons dashicons-calendar-alt" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e( 'Export ICS (Calendar)', 'client-sync' ); ?>
                    </button>
                </form>
            </p>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         SECTION 2: Import / Export Configuration (existing)
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="postbox">
        <h2 class="hndle"><span><?php esc_html_e( 'Import / Export Configuration', 'client-sync' ); ?></span></h2>
        <div class="inside">
            <?php
            $clisyc_feedback = get_transient( 'clisyc_import_export_feedback' );
            if ( $clisyc_feedback ) {
                $clisyc_notice_class = ! empty( $clisyc_feedback['error'] ) ? 'notice-error' : 'notice-success';
                $clisyc_message      = $clisyc_feedback['error'] ?? ( $clisyc_feedback['message'] ?? '' );
                if ( $clisyc_message ) {
                    echo '<div class="notice ' . esc_attr( $clisyc_notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $clisyc_message ) . '</p></div>';
                }
                delete_transient( 'clisyc_import_export_feedback' );
            }
            ?>
            <p><?php esc_html_e( 'Export all plugin settings, dimension data, and relationship graph state to a JSON file. You can then import this file on another site to replicate the entire configuration.', 'client-sync' ); ?></p>
            <p><strong><?php esc_html_e( 'Warning:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Importing a file will overwrite your existing Client Sync settings and all associated dimension posts.', 'client-sync' ); ?></p>

            <div class="clisyc-import-export-forms" style="display: flex; gap: 40px; margin-top: 1em;">
                <!-- START MODIFICATION: Left column now contains Export AND Import from File -->
                <div class="clisyc-export-form-wrapper" style="flex: 1;">
                    <h3><?php esc_html_e( 'Export', 'client-sync' ); ?></h3>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'clisyc_export_settings_action', 'clisyc_export_settings_nonce' ); ?>
                        <input type="hidden" name="action" value="clisyc_export_settings">
                        
                        <p>
                            <label for="clisyc_export_note"><?php esc_html_e( 'Export Note (Optional)', 'client-sync' ); ?></label><br>
                            <textarea name="clisyc_export_note" id="clisyc_export_note" class="widefat" rows="3" placeholder="<?php esc_attr_e( 'e.g., Configuration for the downtown salon with new spa services.', 'client-sync' ); ?>"></textarea>
                            <span class="description"><?php esc_html_e( 'This note will be included in the export file for reference.', 'client-sync' ); ?></span>
                        </p>
                        
                        <button type="submit" class="button button-secondary">
                            <?php esc_html_e( 'Export Settings to JSON File', 'client-sync' ); ?>
                        </button>
                    </form>

                    <hr style="margin: 2em 0;">

                    <h3><?php esc_html_e( 'Import from File', 'client-sync' ); ?></h3>
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure? This will overwrite your current configuration.', 'client-sync' ) ); ?>');">
                        <?php wp_nonce_field( 'clisyc_import_settings_action', 'clisyc_import_settings_nonce' ); ?>
                        <input type="hidden" name="action" value="clisyc_import_settings">
                        <p>
                            <label for="clisyc_import_file"><?php esc_html_e( 'Select JSON file to import:', 'client-sync' ); ?></label><br>
                            <input type="file" name="clisyc_import_file" id="clisyc_import_file" accept=".json,application/json">
                        </p>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Import from File', 'client-sync' ); ?>
                        </button>
                    </form>
                </div>
                <!-- END MODIFICATION -->

                <!-- START MODIFICATION: Right column now ONLY contains Import from Template -->
                <div class="clisyc-import-form-wrapper" style="flex: 1;">
                    <h3><?php esc_html_e( 'Import from a Template', 'client-sync' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Install a pre-configured setup. This will overwrite your current dimension configuration.', 'client-sync' ); ?></p>
                    <div class="clisyc-template-cards">
                        <?php
                        $clisyc_templates_dir = clisyc_PLUGIN_DIR . 'setup-templates/';
                        if ( file_exists( $clisyc_templates_dir ) ) {
                            $clisyc_template_files = glob( $clisyc_templates_dir . '*.json' );
                            foreach ( $clisyc_template_files as $clisyc_file_path ) {
                                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                                $clisyc_json_content = file_get_contents( $clisyc_file_path );
                                $clisyc_template_data = json_decode( $clisyc_json_content, true );
                                if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $clisyc_template_data ) ) {
                                    continue;
                                }

                                $clisyc_template_info = $clisyc_template_data[0] ?? []; 
                                $clisyc_slug = basename( $clisyc_file_path, '.json' );
                                $clisyc_title = $clisyc_template_info['template_meta']['title'] ?? ucwords( str_replace( ['-', '_'], ' ', $clisyc_slug ) );
                                $clisyc_description = $clisyc_template_info['template_meta']['description'] ?? '';
                                ?>
                                <div class="clisyc-template-card">
                                    <h4><?php echo esc_html( $clisyc_title ); ?></h4>
                                    <p><?php echo esc_html( $clisyc_description ); ?></p>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure? This will overwrite your current configuration.', 'client-sync' ) ); ?>');">
                                        <input type="hidden" name="action" value="clisyc_import_settings">
                                        <?php wp_nonce_field( 'clisyc_import_settings_action', 'clisyc_import_settings_nonce' ); ?>
                                        <input type="hidden" name="import_template_slug" value="<?php echo esc_attr( $clisyc_slug ); ?>">
                                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Install Template', 'client-sync' ); ?></button>
                                    </form>
                                </div>
                                <?php
                            }
                        }
                        ?>
                    </div>
                    <!-- The "From a File" section has been moved out of this column -->
                </div>
                <!-- END MODIFICATION -->
            </div>
        </div>
    </div>
</div>