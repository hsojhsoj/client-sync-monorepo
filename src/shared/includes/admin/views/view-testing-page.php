<?php
/**
 * File: src/shared/includes/admin/views/view-testing-page.php -> client-sync/includes/admin/views/view-testing-page.php
 * View for the Testing & Development Tools admin page.
 * MODIFIED: Reorganized into a two-column widget layout for better clarity and usability.
 * UPDATED: Added HIPAA compliance - disables export buttons when HIPAA mode is active.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =========================================================================
// HIPAA COMPLIANCE: Check if HIPAA mode is active
// =========================================================================
$clisyc_hipaa_mode_active = false;
if ( function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) ) {
    $clisyc_hipaa_mode_active = \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode();
} elseif ( defined( 'CLISYC_HIPAA_MODE' ) && CLISYC_HIPAA_MODE ) {
    $clisyc_hipaa_mode_active = true;
} else {
    $clisyc_hipaa_mode_active = (bool) get_option( 'clisyc_hipaa_mode_enabled', false );
}


// =========================================================================
// PLUGIN VERSION INFO: Gather data for the info widget
// =========================================================================
$clisyc_free_plugin_file = defined( 'clisyc_PLUGIN_DIR' ) ? clisyc_PLUGIN_DIR . 'client-sync.php' : '';
$clisyc_free_data        = [];
if ( $clisyc_free_plugin_file && file_exists( $clisyc_free_plugin_file ) ) {
    if ( ! function_exists( 'get_plugin_data' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $clisyc_free_data = get_plugin_data( $clisyc_free_plugin_file, false, false );
}

$clisyc_pro_active = false;
$clisyc_pro_data   = [];

// Detect Pro plugin — check multiple methods since CLISYC_PRO_DIR may not be defined.
if ( ! function_exists( 'is_plugin_active' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// Try known Pro plugin paths (built plugin vs monorepo layout).
$clisyc_pro_candidates = [
    WP_PLUGIN_DIR . '/client-sync-pro/client-sync-pro.php',
];
if ( defined( 'CLISYC_PRO_DIR' ) ) {
    array_unshift( $clisyc_pro_candidates, CLISYC_PRO_DIR . 'client-sync-pro.php' );
}

foreach ( $clisyc_pro_candidates as $clisyc_pro_candidate ) {
    if ( file_exists( $clisyc_pro_candidate ) ) {
        $clisyc_pro_data = get_plugin_data( $clisyc_pro_candidate, false, false );
        // Check if the plugin is actually active.
        $clisyc_pro_basename = plugin_basename( $clisyc_pro_candidate );
        $clisyc_pro_active   = is_plugin_active( $clisyc_pro_basename );
        break;
    }
}

// Determine last update time from the main plugin file's modified timestamp.
$clisyc_last_modified = $clisyc_free_plugin_file && file_exists( $clisyc_free_plugin_file )
    ? filemtime( $clisyc_free_plugin_file )
    : false;
?>
<div class="wrap clisyc-testing-page">
    <h1><?php esc_html_e( 'Client Sync Testing & Development Tools', 'client-sync' ); ?></h1>

    <div class="notice notice-error notice-alt">
        <h2 style="color: #d63638; margin-top: 0;"><?php esc_html_e( 'WARNING: Development Tools', 'client-sync' ); ?></h2>
        <p><strong><?php esc_html_e( 'The tools on this page are for development and testing purposes ONLY. They can permanently delete data.', 'client-sync' ); ?></strong></p>
        <p><?php esc_html_e( 'Do NOT use these tools on a live production website. There is no undo. Use with extreme caution.', 'client-sync' ); ?></p>
    </div>

    <?php // ── Plugin Information Widget ──────────────────────────────────── ?>
    <div class="postbox" style="margin-bottom: 20px;">
        <h2 class="hndle"><span><?php esc_html_e( 'Plugin Information', 'client-sync' ); ?></span></h2>
        <div class="inside">
            <table class="form-table" style="margin-top: 0;">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Client Sync (Free)', 'client-sync' ); ?></th>
                        <td>
                            <code><?php echo esc_html( ! empty( $clisyc_free_data['Version'] ) ? 'v' . $clisyc_free_data['Version'] : __( 'Unknown', 'client-sync' ) ); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Client Sync Pro', 'client-sync' ); ?></th>
                        <td>
                            <?php if ( ! empty( $clisyc_pro_data['Version'] ) ) : ?>
                                <code><?php echo esc_html( 'v' . $clisyc_pro_data['Version'] ); ?></code>
                                <?php if ( $clisyc_pro_active ) : ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: #00a32a; vertical-align: middle;"></span>
                                    <span style="color: #00a32a;"><?php esc_html_e( 'Active', 'client-sync' ); ?></span>
                                <?php else : ?>
                                    <span style="color: #996800;"><?php esc_html_e( 'Installed but inactive', 'client-sync' ); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span style="color: #996800;"><?php esc_html_e( 'Not installed', 'client-sync' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( $clisyc_last_modified ) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Last Updated', 'client-sync' ); ?></th>
                        <td>
                            <code><?php echo esc_html( wp_date( 'F j, Y \a\t g:i a', $clisyc_last_modified ) ); ?></code>
                            <span class="description">(<?php echo esc_html( human_time_diff( $clisyc_last_modified, time() ) . ' ' . __( 'ago', 'client-sync' ) ); ?>)</span>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'WordPress', 'client-sync' ); ?></th>
                        <td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'PHP', 'client-sync' ); ?></th>
                        <td><code><?php echo esc_html( PHP_VERSION ); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Database', 'client-sync' ); ?></th>
                        <td>
                            <code><?php global $wpdb; echo esc_html( $wpdb->db_server_info() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'HIPAA Mode', 'client-sync' ); ?></th>
                        <td>
                            <?php if ( $clisyc_hipaa_mode_active ) : ?>
                                <span class="dashicons dashicons-shield" style="color: #00a32a; vertical-align: middle;"></span>
                                <strong style="color: #00a32a;"><?php esc_html_e( 'Enabled', 'client-sync' ); ?></strong>
                            <?php else : ?>
                                <span style="color: #996800;"><?php esc_html_e( 'Disabled', 'client-sync' ); ?></span>
                            <?php endif; ?>
                            &nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=hipaa' ) ); ?>"><?php esc_html_e( 'Manage', 'client-sync' ); ?> &rarr;</a>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Encryption', 'client-sync' ); ?></th>
                        <td>
                            <?php
                            $clisyc_enc_available = false;
                            if ( class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
                                $clisyc_enc_available = \DependentMedia\ClientSync\Services\Encryption_Service::get_instance()->is_available();
                            }
                            if ( $clisyc_enc_available ) :
                            ?>
                                <span class="dashicons dashicons-lock" style="color: #00a32a; vertical-align: middle;"></span>
                                <span style="color: #00a32a;"><?php esc_html_e( 'AES-256-GCM ready', 'client-sync' ); ?></span>
                            <?php else : ?>
                                <span style="color: #996800;"><?php esc_html_e( 'Not configured', 'client-sync' ); ?></span>
                            <?php endif; ?>
                            &nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=hipaa' ) ); ?>"><?php esc_html_e( 'Manage', 'client-sync' ); ?> &rarr;</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    $clisyc_feedback = get_transient( 'clisyc_testing_feedback' );
    if ( $clisyc_feedback ) {
        $clisyc_notice_class = isset( $clisyc_feedback['error'] ) ? 'notice-error' : (isset($clisyc_feedback['warning']) ? 'notice-warning' : 'notice-success');
        $clisyc_message      = $clisyc_feedback['error'] ?? ($clisyc_feedback['warning'] ?? ($clisyc_feedback['message'] ?? ''));
        
        if (!empty($clisyc_message)) {
            echo '<div class="notice ' . esc_attr( $clisyc_notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $clisyc_message ) . '</p></div>';
        }
        delete_transient( 'clisyc_testing_feedback' );
    }
    ?>

    <div id="dashboard-widgets-wrap">
        <div id="dashboard-widgets" class="metabox-holder">
            <div id="postbox-container-1" class="postbox-container">
                <div class="meta-box-sortables">

                    <div class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e( 'Time & Timezone Information', 'client-sync' ); ?></span></h2>
                        <div class="inside">
                            <p><?php esc_html_e( 'Use this information to diagnose timezone-related issues between the server, database, and browser.', 'client-sync' ); ?></p>
                            <table class="form-table">
                                <tbody>
                                    <tr><th scope="row"><?php esc_html_e( 'PHP Default Timezone', 'client-sync' ); ?></th><td><code><?php echo esc_html( date_default_timezone_get() ); ?></code></td></tr>
                                    <tr><th scope="row"><?php esc_html_e( 'PHP `date()` (Server Time)', 'client-sync' ); ?></th><td><code><?php echo esc_html( date( 'Y-m-d H:i:s T' ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date ?></code></td></tr>
                                    <tr><th scope="row"><?php esc_html_e( 'WordPress Timezone', 'client-sync' ); ?></th><td><code><?php echo esc_html( wp_timezone_string() ); ?></code> (GMT <?php echo esc_html( get_option('gmt_offset', '0') ); ?>)</td></tr>
                                    <tr><th scope="row"><?php esc_html_e( 'WordPress `wp_date()` (Site Time)', 'client-sync' ); ?></th><td><code><?php echo esc_html( wp_date('Y-m-d H:i:s T') ); ?></code></td></tr>
                                    <tr><th scope="row"><?php esc_html_e( 'Current UTC Time (from PHP)', 'client-sync' ); ?></th><td><code><?php echo esc_html( gmdate('Y-m-d H:i:s') ); ?></code></td></tr>
                                    <tr><th scope="row"><?php esc_html_e( 'Database `NOW()`', 'client-sync' ); ?></th><td><code><?php global $wpdb; echo esc_html( $wpdb->get_var("SELECT NOW()") ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching ?></code></td></tr>
                                    <tr><th scope="row"><?php esc_html_e( 'Database `UTC_TIMESTAMP()`', 'client-sync' ); ?></th><td><code><?php echo esc_html( $wpdb->get_var("SELECT UTC_TIMESTAMP()") ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching ?></code></td></tr>
                                    <tr><th scope="row"><?php esc_html_e( 'User Browser Time', 'client-sync' ); ?></th><td><code id="clisyc-browser-time-display"><?php esc_html_e('Loading...', 'client-sync'); ?></code></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e( 'Debugging & Export Tools', 'client-sync' ); ?></span></h2>
                        <div class="inside">
                            <h4><?php esc_html_e( 'Generate File & Folder Tree', 'client-sync' ); ?></h4>
                            <p><?php esc_html_e( 'Scans the plugin directory and generates a text file showing the complete file structure.', 'client-sync' ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clisyc-tool-form">
                                <?php wp_nonce_field( 'clisyc_testing_generate_file_tree', 'clisyc_testing_file_tree_nonce' ); ?>
                                <input type="hidden" name="action" value="clisyc_handle_testing_actions">
                                <button type="submit" name="clisyc_test_action" value="generate_file_tree" class="button button-secondary"><span class="dashicons dashicons-media-text"></span> <?php esc_html_e( 'Download File Tree (.txt)', 'client-sync' ); ?></button>
                            </form>
                            <hr>
                            <h4><?php esc_html_e( 'Generate Site Root File & Folder Tree', 'client-sync' ); ?></h4>
                            <p><?php esc_html_e( 'Scans the entire WordPress installation root (httpdocs) and generates a text file of the complete file structure. Warning: This file may be very large and contain sensitive information.', 'client-sync' ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clisyc-tool-form">
                                <?php wp_nonce_field( 'clisyc_testing_generate_site_file_tree', 'clisyc_testing_site_file_tree_nonce' ); ?>
                                <input type="hidden" name="action" value="clisyc_handle_testing_actions">
                                <button type="submit" name="clisyc_test_action" value="generate_site_file_tree" class="button button-secondary">
                                    <span class="dashicons dashicons-admin-site-alt3"></span> <?php esc_html_e( 'Download Site Root File Tree (.txt)', 'client-sync' ); ?>
                                </button>
                            </form>
                            <hr>
                            <h4><?php esc_html_e( 'Cleanup macOS Files', 'client-sync' ); ?></h4>
                            <p><?php esc_html_e( 'Scans and removes `.DS_Store` files. Useful before packaging the plugin for distribution.', 'client-sync' ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clisyc-tool-form">
                                <?php wp_nonce_field( 'clisyc_testing_remove_ds_store', 'clisyc_testing_remove_ds_store_nonce' ); ?>
                                <input type="hidden" name="action" value="clisyc_handle_testing_actions">
                                <button type="submit" name="clisyc_test_action" value="remove_ds_store" class="button button-secondary"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Remove .DS_Store Files', 'client-sync' ); ?></button>
                            </form>
                             <hr>
                            <h4><?php esc_html_e( 'Export Database Tables', 'client-sync' ); ?></h4>
                            <p><?php esc_html_e( 'Exports raw data from all custom tables and all plugin options to a single JSON file for debugging.', 'client-sync' ); ?></p>
                            <?php if ( $clisyc_hipaa_mode_active ) : ?>
                                <div class="notice notice-warning inline" style="margin: 0 0 10px 0; padding: 8px 12px;">
                                    <p style="margin: 0;">
                                        <span class="dashicons dashicons-shield" style="color: #dba617;"></span>
                                        <strong><?php esc_html_e( 'HIPAA Mode Active', 'client-sync' ); ?></strong><br>
                                        <?php esc_html_e( 'Database export is disabled to prevent accidental PHI disclosure. Disable HIPAA mode temporarily if you need to export data for migration purposes.', 'client-sync' ); ?>
                                    </p>
                                </div>
                                <button type="button" class="button button-secondary" disabled style="opacity: 0.6; cursor: not-allowed;">
                                    <span class="dashicons dashicons-database-export"></span> <?php esc_html_e( 'Export All Plugin Data', 'client-sync' ); ?>
                                </button>
                            <?php else : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clisyc-tool-form">
                                <?php wp_nonce_field( 'clisyc_export_db_action', 'clisyc_export_db_nonce' ); ?>
                                <input type="hidden" name="action" value="clisyc_export_database_data">
                                <button type="submit" class="button button-secondary"><span class="dashicons dashicons-database-export"></span> <?php esc_html_e( 'Export All Plugin Data', 'client-sync' ); ?></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
            <div id="postbox-container-2" class="postbox-container">
                <div class="meta-box-sortables">

                    <div class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e( 'Data & Cron Management', 'client-sync' ); ?></span></h2>
                        <div class="inside">
                            <h4><?php esc_html_e( 'Force Frequent Maintenance Tasks', 'client-sync' ); ?></h4>
                            <p class="description"><?php esc_html_e( 'Manually executes all frequent tasks, including slot generation, reminder checks, and updating the "Smart Start Date" cache. Check debug.log for output.', 'client-sync' ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clisyc-tool-form">
                                <?php wp_nonce_field( 'clisyc_testing_run_frequent_cron', 'clisyc_testing_run_frequent_cron_nonce' ); ?>
                                <input type="hidden" name="action" value="clisyc_handle_testing_actions">
                                <button type="submit" name="clisyc_test_action" value="force_run_frequent_maintenance" class="button button-secondary"><span class="dashicons dashicons-controls-repeat"></span> <?php esc_html_e( 'Force Run Maintenance Now', 'client-sync' ); ?></button>
                            </form>
                            <hr>
                             <h4><?php esc_html_e( 'Cache Management', 'client-sync' ); ?></h4>
                            <p><?php esc_html_e( 'Clears all cached data (transients) used by the plugin. Use this if your calendar is not displaying the latest changes.', 'client-sync' ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clisyc-tool-form">
                                <?php wp_nonce_field( 'clisyc_testing_clear_transients', 'clisyc_testing_clear_transients_nonce' ); ?>
                                <input type="hidden" name="action" value="clisyc_handle_testing_actions">
                                <button type="submit" name="clisyc_test_action" value="clear_transients" class="button button-secondary"><span class="dashicons dashicons-update-alt"></span> <?php esc_html_e( 'Clear All Plugin Caches', 'client-sync' ); ?></button>
                            </form>
                            <hr>
                            <h4><?php esc_html_e( 'Graph Index Management', 'client-sync' ); ?></h4>
                            <p><?php esc_html_e( 'Rebuilds the database index for the Dimension Relationship Graph. Use this if the graph appears out of sync.', 'client-sync' ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clisyc-tool-form">
                                <?php wp_nonce_field( 'clisyc_rebuild_graph_index_action', 'clisyc_rebuild_index_nonce' ); ?>
                                <input type="hidden" name="action" value="clisyc_handle_testing_actions">
                                <button type="submit" name="clisyc_test_action" value="rebuild_graph_index" class="button button-secondary"><span class="dashicons dashicons-networking"></span> <?php esc_html_e( 'Rebuild Graph Index', 'client-sync' ); ?></button>
                            </form>
                            <hr>
                            <h4><?php esc_html_e( 'Repair Relationship Data', 'client-sync' ); ?></h4>
                            <p><?php esc_html_e( 'Scans the database for corrupted relationship entries and attempts to fix them.', 'client-sync' ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clisyc-tool-form">
                                <?php wp_nonce_field( 'clisyc_testing_repair_rels', 'clisyc_testing_repair_rels_nonce' ); ?>
                                <input type="hidden" name="action" value="clisyc_handle_testing_actions">
                                <button type="submit" name="clisyc_test_action" value="repair_relationships" class="button button-secondary"><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Repair Relationships', 'client-sync' ); ?></button>
                            </form>
                            <hr>
                            <h4><?php esc_html_e( 'Fix Dimension Registry', 'client-sync' ); ?></h4>
                            <p><?php esc_html_e( 'Forces "Practitioners" to be a Resource in the database. Use this to fix the configuration mismatch.', 'client-sync' ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clisyc-tool-form">
                                <?php wp_nonce_field( 'clisyc_testing_fix_registry', 'clisyc_testing_fix_registry_nonce' ); ?>
                                <input type="hidden" name="action" value="clisyc_handle_testing_actions">
                                <button type="submit" name="clisyc_test_action" value="fix_dimension_registry" class="button button-secondary">
                                    <span class="dashicons dashicons-hammer"></span> <?php esc_html_e( 'Fix Registry', 'client-sync' ); ?>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="postbox clisyc-danger-zone">
                        <h2 class="hndle"><span><?php esc_html_e( 'Reset Plugin Data', 'client-sync' ); ?></span></h2>
                        <div class="inside">
                            <p><?php esc_html_e( 'This action will permanently delete all appointments, slots, and dimensions, and reset all Client Sync settings to their default values.', 'client-sync' ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'ARE YOU ABSOLUTELY SURE you want to reset all plugin data? This cannot be undone.', 'client-sync' ) ); ?>');" class="clisyc-tool-form">
                                <?php wp_nonce_field( 'clisyc_testing_reset', 'clisyc_testing_reset_nonce' ); ?>
                                <input type="hidden" name="action" value="clisyc_handle_testing_actions">
                                <button type="submit" name="clisyc_test_action" value="reset_plugin" class="button button-danger"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Reset All Plugin Data', 'client-sync' ); ?></button>
                            </form>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

</div><!-- .wrap -->