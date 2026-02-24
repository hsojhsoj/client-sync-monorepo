<?php
/**
 * File: src/shared/includes/admin/views/view-hipaa-settings-page.php
 * View for the HIPAA Compliance Settings tab.
 * Provides setup guide, configuration options, and status information.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use DependentMedia\ClientSync\Constants;

// =========================================================================
// HIPAA STATUS DETECTION
// =========================================================================
$hipaa_mode_active = false;
if ( function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) ) {
    $hipaa_mode_active = \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode();
} elseif ( defined( 'CLISYC_HIPAA_MODE' ) && CLISYC_HIPAA_MODE ) {
    $hipaa_mode_active = true;
} else {
    $hipaa_mode_active = (bool) get_option( Constants::OPTION_HIPAA_MODE, false );
}

$encryption_key_set = defined( 'CLISYC_ENCRYPTION_KEY' ) && ! empty( CLISYC_ENCRYPTION_KEY );
$encryption_key_length = $encryption_key_set ? strlen( CLISYC_ENCRYPTION_KEY ) : 0;
$encryption_key_strong = $encryption_key_length >= 32;

$hipaa_mode_constant = defined( 'CLISYC_HIPAA_MODE' );
$hipaa_mode_db = (bool) get_option( Constants::OPTION_HIPAA_MODE, false );

$audit_logging_available = class_exists( '\DependentMedia\ClientSync\Services\Audit_Logger' );
$encryption_available = class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' );

$anonymize_external = (bool) get_option( 'clisyc_anonymize_external_sync', true );
$audit_retention_days = (int) get_option( Constants::OPTION_AUDIT_LOG_RETENTION, 2555 );

// Calculate compliance score
$compliance_checks = [
    'hipaa_mode' => $hipaa_mode_active,
    'encryption_key' => $encryption_key_set,
    'encryption_strong' => $encryption_key_strong,
    'audit_logging' => $audit_logging_available,
    'encryption_service' => $encryption_available,
    'anonymize_sync' => $anonymize_external,
];
$compliance_score = array_sum( $compliance_checks );
$compliance_total = count( $compliance_checks );
$compliance_percent = round( ( $compliance_score / $compliance_total ) * 100 );
?>

<div class="clisyc-hipaa-settings">

    <!-- Status Overview -->
    <div class="clisyc-hipaa-status-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">
        
        <!-- HIPAA Mode Status -->
        <div class="clisyc-status-card" style="background: <?php echo $hipaa_mode_active ? '#d4edda' : '#fff3cd'; ?>; border: 1px solid <?php echo $hipaa_mode_active ? '#c3e6cb' : '#ffc107'; ?>; border-radius: 8px; padding: 20px; text-align: center;">
            <span class="dashicons <?php echo $hipaa_mode_active ? 'dashicons-shield' : 'dashicons-warning'; ?>" style="font-size: 40px; width: 40px; height: 40px; color: <?php echo $hipaa_mode_active ? '#155724' : '#856404'; ?>;"></span>
            <h3 style="margin: 10px 0 5px; color: <?php echo $hipaa_mode_active ? '#155724' : '#856404'; ?>;">
                <?php echo $hipaa_mode_active ? esc_html__( 'HIPAA Mode Active', 'client-sync' ) : esc_html__( 'HIPAA Mode Inactive', 'client-sync' ); ?>
            </h3>
            <p style="margin: 0; font-size: 12px; color: #666;">
                <?php echo $hipaa_mode_active ? esc_html__( 'PHI protection enabled', 'client-sync' ) : esc_html__( 'Enable for PHI protection', 'client-sync' ); ?>
            </p>
        </div>

        <!-- Encryption Status -->
        <div class="clisyc-status-card" style="background: <?php echo $encryption_key_strong ? '#d4edda' : ( $encryption_key_set ? '#fff3cd' : '#f8d7da' ); ?>; border: 1px solid <?php echo $encryption_key_strong ? '#c3e6cb' : ( $encryption_key_set ? '#ffc107' : '#f5c6cb' ); ?>; border-radius: 8px; padding: 20px; text-align: center;">
            <span class="dashicons <?php echo $encryption_key_set ? 'dashicons-lock' : 'dashicons-unlock'; ?>" style="font-size: 40px; width: 40px; height: 40px; color: <?php echo $encryption_key_strong ? '#155724' : ( $encryption_key_set ? '#856404' : '#721c24' ); ?>;"></span>
            <h3 style="margin: 10px 0 5px; color: <?php echo $encryption_key_strong ? '#155724' : ( $encryption_key_set ? '#856404' : '#721c24' ); ?>;">
                <?php 
                if ( $encryption_key_strong ) {
                    esc_html_e( 'Encryption Ready', 'client-sync' );
                } elseif ( $encryption_key_set ) {
                    esc_html_e( 'Weak Key', 'client-sync' );
                } else {
                    esc_html_e( 'No Encryption Key', 'client-sync' );
                }
                ?>
            </h3>
            <p style="margin: 0; font-size: 12px; color: #666;">
                <?php 
                if ( $encryption_key_set ) {
                    /* translators: %d: Length of the encryption key in characters */
                    printf( esc_html__( 'Key length: %d chars', 'client-sync' ), $encryption_key_length );
                } else {
                    esc_html_e( 'Configure in wp-config.php', 'client-sync' );
                }
                ?>
            </p>
        </div>

        <!-- Audit Logging Status -->
        <div class="clisyc-status-card" style="background: <?php echo $audit_logging_available ? '#d4edda' : '#f8d7da'; ?>; border: 1px solid <?php echo $audit_logging_available ? '#c3e6cb' : '#f5c6cb'; ?>; border-radius: 8px; padding: 20px; text-align: center;">
            <span class="dashicons <?php echo $audit_logging_available ? 'dashicons-clipboard' : 'dashicons-dismiss'; ?>" style="font-size: 40px; width: 40px; height: 40px; color: <?php echo $audit_logging_available ? '#155724' : '#721c24'; ?>;"></span>
            <h3 style="margin: 10px 0 5px; color: <?php echo $audit_logging_available ? '#155724' : '#721c24'; ?>;">
                <?php echo $audit_logging_available ? esc_html__( 'Audit Logging Ready', 'client-sync' ) : esc_html__( 'Audit Logging Missing', 'client-sync' ); ?>
            </h3>
            <p style="margin: 0; font-size: 12px; color: #666;">
                <?php 
                if ( $audit_logging_available ) {
                    /* translators: %d: Number of days audit logs are retained */
                    printf( esc_html__( 'Retention: %d days', 'client-sync' ), $audit_retention_days );
                } else {
                    esc_html_e( 'Service not loaded', 'client-sync' );
                }
                ?>
            </p>
        </div>

        <!-- Compliance Score -->
        <div class="clisyc-status-card" style="background: <?php echo $compliance_percent >= 80 ? '#d4edda' : ( $compliance_percent >= 50 ? '#fff3cd' : '#f8d7da' ); ?>; border: 1px solid <?php echo $compliance_percent >= 80 ? '#c3e6cb' : ( $compliance_percent >= 50 ? '#ffc107' : '#f5c6cb' ); ?>; border-radius: 8px; padding: 20px; text-align: center;">
            <div style="font-size: 36px; font-weight: bold; color: <?php echo $compliance_percent >= 80 ? '#155724' : ( $compliance_percent >= 50 ? '#856404' : '#721c24' ); ?>;">
                <?php echo esc_html( $compliance_percent ); ?>%
            </div>
            <h3 style="margin: 5px 0; color: <?php echo $compliance_percent >= 80 ? '#155724' : ( $compliance_percent >= 50 ? '#856404' : '#721c24' ); ?>;">
                <?php esc_html_e( 'Compliance Score', 'client-sync' ); ?>
            </h3>
            <p style="margin: 0; font-size: 12px; color: #666;">
                <?php 
                /* translators: %1$d: Number of passed checks, %2$d: Total number of checks */
                printf( esc_html__( '%1$d of %2$d checks passed', 'client-sync' ), $compliance_score, $compliance_total ); 
                ?>
            </p>
        </div>

    </div>

    <!-- Setup Guide -->
    <div class="card" style="max-width: none; margin-bottom: 20px;">
        <h2 style="margin-top: 0;">
            <span class="dashicons dashicons-book" style="color: #0073aa;"></span>
            <?php esc_html_e( 'HIPAA Compliance Setup Guide', 'client-sync' ); ?>
        </h2>
        
        <div class="clisyc-setup-steps">
            
            <!-- Step 1: Enable HIPAA Mode -->
            <div class="clisyc-setup-step" style="background: #f9f9f9; border-left: 4px solid <?php echo $hipaa_mode_active ? '#46b450' : '#0073aa'; ?>; padding: 15px 20px; margin-bottom: 15px;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                    <?php if ( $hipaa_mode_active ) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                    <?php else : ?>
                        <span style="display: inline-block; width: 24px; height: 24px; background: #0073aa; color: #fff; border-radius: 50%; text-align: center; line-height: 24px; font-size: 14px;">1</span>
                    <?php endif; ?>
                    <?php esc_html_e( 'Enable HIPAA Mode', 'client-sync' ); ?>
                </h3>
                
                <p><?php esc_html_e( 'HIPAA mode activates all privacy protections including encryption, audit logging, and export restrictions. For maximum security, define this constant in your wp-config.php file:', 'client-sync' ); ?></p>
                
                <pre style="background: #23282d; color: #f1f1f1; padding: 15px; border-radius: 4px; overflow-x: auto; font-family: Consolas, Monaco, monospace;"><code>// Add to wp-config.php (before "That's all, stop editing!")
define( 'CLISYC_HIPAA_MODE', true );</code></pre>
                
                <p><strong><?php esc_html_e( 'Current Status:', 'client-sync' ); ?></strong></p>
                <ul style="margin-left: 20px;">
                    <li>
                        <?php if ( $hipaa_mode_constant ) : ?>
                            <span style="color: #46b450;">✓</span> <?php esc_html_e( 'CLISYC_HIPAA_MODE constant is defined in wp-config.php', 'client-sync' ); ?>
                        <?php else : ?>
                            <span style="color: #dc3232;">✗</span> <?php esc_html_e( 'CLISYC_HIPAA_MODE constant is NOT defined', 'client-sync' ); ?>
                        <?php endif; ?>
                    </li>
                    <li>
                        <?php if ( $hipaa_mode_db ) : ?>
                            <span style="color: #46b450;">✓</span> <?php esc_html_e( 'Database option is enabled', 'client-sync' ); ?>
                        <?php else : ?>
                            <span style="color: #999;">○</span> <?php esc_html_e( 'Database option is disabled (constant takes precedence)', 'client-sync' ); ?>
                        <?php endif; ?>
                    </li>
                </ul>

                <?php if ( ! $hipaa_mode_constant ) : ?>
                    <form method="post" action="options.php" style="margin-top: 15px;">
                        <?php settings_fields( 'clisyc_hipaa_settings_group' ); ?>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="clisyc_hipaa_mode_enabled" value="1" <?php checked( $hipaa_mode_db ); ?>>
                            <?php esc_html_e( 'Enable HIPAA Mode via database (less secure than wp-config.php)', 'client-sync' ); ?>
                        </label>
                        <?php submit_button( __( 'Save Setting', 'client-sync' ), 'secondary', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Step 2: Configure Encryption Key -->
            <div class="clisyc-setup-step" style="background: #f9f9f9; border-left: 4px solid <?php echo $encryption_key_strong ? '#46b450' : '#dc3232'; ?>; padding: 15px 20px; margin-bottom: 15px;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                    <?php if ( $encryption_key_strong ) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                    <?php else : ?>
                        <span style="display: inline-block; width: 24px; height: 24px; background: #dc3232; color: #fff; border-radius: 50%; text-align: center; line-height: 24px; font-size: 14px;">2</span>
                    <?php endif; ?>
                    <?php esc_html_e( 'Configure Encryption Key', 'client-sync' ); ?>
                    <?php if ( ! $encryption_key_strong ) : ?>
                        <span style="background: #dc3232; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 3px; font-weight: normal;"><?php esc_html_e( 'REQUIRED', 'client-sync' ); ?></span>
                    <?php endif; ?>
                </h3>
                
                <p><?php esc_html_e( 'A strong encryption key is required to encrypt sensitive appointment data (PHI). This key MUST be defined in wp-config.php and should be at least 32 characters long.', 'client-sync' ); ?></p>
                
                <div class="notice notice-warning inline" style="margin: 15px 0;">
                    <p><strong><?php esc_html_e( 'IMPORTANT:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Once you encrypt data with a key, you cannot change it without losing access to existing encrypted data. Store this key securely and include it in your backup procedures.', 'client-sync' ); ?></p>
                </div>
                
                <pre style="background: #23282d; color: #f1f1f1; padding: 15px; border-radius: 4px; overflow-x: auto; font-family: Consolas, Monaco, monospace;"><code>// Add to wp-config.php (before "That's all, stop editing!")
define( 'CLISYC_ENCRYPTION_KEY', '<?php echo esc_html( wp_generate_password( 64, true, true ) ); ?>' );</code></pre>
                
                <p style="font-size: 12px; color: #666;">
                    <span class="dashicons dashicons-info" style="font-size: 14px;"></span>
                    <?php esc_html_e( 'The key above is randomly generated. Copy it now or generate your own secure key.', 'client-sync' ); ?>
                </p>

                <p><strong><?php esc_html_e( 'Current Status:', 'client-sync' ); ?></strong></p>
                <ul style="margin-left: 20px;">
                    <?php if ( $encryption_key_set ) : ?>
                        <li><span style="color: #46b450;">✓</span> <?php esc_html_e( 'Encryption key is configured', 'client-sync' ); ?></li>
                        <?php if ( $encryption_key_strong ) : ?>
                            <li><span style="color: #46b450;">✓</span> <?php esc_html_e( 'Key length is sufficient (32+ characters)', 'client-sync' ); ?></li>
                        <?php else : ?>
                            <li><span style="color: #ffb900;">⚠</span> <?php esc_html_e( 'Key is too short - recommend 32+ characters for security', 'client-sync' ); ?></li>
                        <?php endif; ?>
                    <?php else : ?>
                        <li><span style="color: #dc3232;">✗</span> <?php esc_html_e( 'CLISYC_ENCRYPTION_KEY is NOT defined - encryption will not work!', 'client-sync' ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Step 3: Verify Audit Logging -->
            <div class="clisyc-setup-step" style="background: #f9f9f9; border-left: 4px solid <?php echo $audit_logging_available ? '#46b450' : '#dc3232'; ?>; padding: 15px 20px; margin-bottom: 15px;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                    <?php if ( $audit_logging_available ) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                    <?php else : ?>
                        <span style="display: inline-block; width: 24px; height: 24px; background: #dc3232; color: #fff; border-radius: 50%; text-align: center; line-height: 24px; font-size: 14px;">3</span>
                    <?php endif; ?>
                    <?php esc_html_e( 'Verify Audit Logging', 'client-sync' ); ?>
                </h3>
                
                <p><?php esc_html_e( 'HIPAA requires maintaining audit logs of all PHI access for a minimum of 6 years. The audit logging system tracks:', 'client-sync' ); ?></p>
                
                <ul style="margin-left: 20px;">
                    <li><?php esc_html_e( 'All appointment views, creates, edits, and deletes', 'client-sync' ); ?></li>
                    <li><?php esc_html_e( 'Client profile access and modifications', 'client-sync' ); ?></li>
                    <li><?php esc_html_e( 'Data exports (CSV, PDF, reports)', 'client-sync' ); ?></li>
                    <li><?php esc_html_e( 'User login/logout events for PHI-access users', 'client-sync' ); ?></li>
                    <li><?php esc_html_e( 'Settings changes affecting HIPAA compliance', 'client-sync' ); ?></li>
                </ul>

                <p><strong><?php esc_html_e( 'Current Status:', 'client-sync' ); ?></strong></p>
                <ul style="margin-left: 20px;">
                    <?php if ( $audit_logging_available ) : ?>
                        <li><span style="color: #46b450;">✓</span> <?php esc_html_e( 'Audit Logger service is loaded', 'client-sync' ); ?></li>
                        <li>
                            <span style="color: #46b450;">✓</span> 
                            <?php 
                            /* translators: %s: Number of years for audit log retention */
                            printf( esc_html__( 'Retention period: %s years', 'client-sync' ), number_format( $audit_retention_days / 365, 1 ) ); 
                            ?>
                        </li>
                        <li>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-audit-logs' ) ); ?>" class="button button-secondary" style="margin-top: 5px;">
                                <span class="dashicons dashicons-clipboard" style="vertical-align: middle;"></span>
                                <?php esc_html_e( 'View Audit Logs', 'client-sync' ); ?>
                            </a>
                        </li>
                    <?php else : ?>
                        <li><span style="color: #dc3232;">✗</span> <?php esc_html_e( 'Audit Logger service is not available - check plugin installation', 'client-sync' ); ?></li>
                    <?php endif; ?>
                </ul>

                <div style="margin-top: 15px;">
                    <label for="clisyc_audit_retention"><?php esc_html_e( 'Audit Log Retention (days):', 'client-sync' ); ?></label>
                    <p class="description"><?php esc_html_e( 'HIPAA requires minimum 6 years (2190 days). Default is 7 years (2555 days).', 'client-sync' ); ?></p>
                    <form method="post" action="options.php" style="display: flex; align-items: center; gap: 10px; margin-top: 5px;">
                        <?php settings_fields( 'clisyc_hipaa_settings_group' ); ?>
                        <input type="number" name="clisyc_audit_log_retention_days" id="clisyc_audit_retention" value="<?php echo esc_attr( $audit_retention_days ); ?>" min="2190" max="7300" style="width: 100px;">
                        <?php submit_button( __( 'Update', 'client-sync' ), 'secondary', 'submit', false ); ?>
                    </form>
                </div>
            </div>

            <!-- Step 4: Configure Data Anonymization -->
            <div class="clisyc-setup-step" style="background: #f9f9f9; border-left: 4px solid <?php echo $anonymize_external ? '#46b450' : '#ffb900'; ?>; padding: 15px 20px; margin-bottom: 15px;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                    <?php if ( $anonymize_external ) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                    <?php else : ?>
                        <span style="display: inline-block; width: 24px; height: 24px; background: #ffb900; color: #fff; border-radius: 50%; text-align: center; line-height: 24px; font-size: 14px;">4</span>
                    <?php endif; ?>
                    <?php esc_html_e( 'Configure Data Anonymization', 'client-sync' ); ?>
                </h3>
                
                <p><?php esc_html_e( 'When syncing with external calendars (Google Calendar, Outlook, etc.), PHI should be anonymized to prevent disclosure. This replaces client names and details with generic placeholders.', 'client-sync' ); ?></p>

                <form method="post" action="options.php">
                    <?php settings_fields( 'clisyc_hipaa_settings_group' ); ?>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 15px 0;">
                        <input type="checkbox" name="clisyc_anonymize_external_sync" value="1" <?php checked( $anonymize_external ); ?>>
                        <strong><?php esc_html_e( 'Anonymize data in external calendar sync', 'client-sync' ); ?></strong>
                    </label>
                    <p class="description" style="margin-left: 25px;">
                        <?php esc_html_e( 'When enabled, appointments synced to external calendars will show "Appointment" instead of client names.', 'client-sync' ); ?>
                    </p>
                    <?php submit_button( __( 'Save Setting', 'client-sync' ), 'secondary', 'submit', false ); ?>
                </form>
            </div>

        </div>
    </div>

    <!-- What HIPAA Mode Does -->
    <div class="card" style="max-width: none; margin-bottom: 20px;">
        <h2 style="margin-top: 0;">
            <span class="dashicons dashicons-info" style="color: #0073aa;"></span>
            <?php esc_html_e( 'What HIPAA Mode Enables', 'client-sync' ); ?>
        </h2>
        
        <table class="widefat striped" style="margin-top: 15px;">
            <thead>
                <tr>
                    <th style="width: 30%;"><?php esc_html_e( 'Feature', 'client-sync' ); ?></th>
                    <th style="width: 15%;"><?php esc_html_e( 'Status', 'client-sync' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'client-sync' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?php esc_html_e( 'Field-Level Encryption', 'client-sync' ); ?></strong></td>
                    <td>
                        <?php if ( $encryption_available && $encryption_key_set ) : ?>
                            <span style="color: #46b450;">● <?php esc_html_e( 'Active', 'client-sync' ); ?></span>
                        <?php else : ?>
                            <span style="color: #dc3232;">● <?php esc_html_e( 'Inactive', 'client-sync' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php esc_html_e( 'Sensitive appointment fields (marked as "sensitive" in custom fields) are encrypted at rest using AES-256-GCM.', 'client-sync' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Comprehensive Audit Logging', 'client-sync' ); ?></strong></td>
                    <td>
                        <?php if ( $audit_logging_available && $hipaa_mode_active ) : ?>
                            <span style="color: #46b450;">● <?php esc_html_e( 'Active', 'client-sync' ); ?></span>
                        <?php else : ?>
                            <span style="color: #999;">● <?php esc_html_e( 'Partial', 'client-sync' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php esc_html_e( 'All PHI access is logged with user, timestamp, IP address, and action details. Logs retained for 6+ years.', 'client-sync' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Export Restrictions', 'client-sync' ); ?></strong></td>
                    <td>
                        <?php if ( $hipaa_mode_active ) : ?>
                            <span style="color: #46b450;">● <?php esc_html_e( 'Active', 'client-sync' ); ?></span>
                        <?php else : ?>
                            <span style="color: #dc3232;">● <?php esc_html_e( 'Inactive', 'client-sync' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php esc_html_e( 'Bulk data exports (CSV, database dumps) are blocked to prevent accidental PHI disclosure.', 'client-sync' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'External Sync Anonymization', 'client-sync' ); ?></strong></td>
                    <td>
                        <?php if ( $anonymize_external ) : ?>
                            <span style="color: #46b450;">● <?php esc_html_e( 'Active', 'client-sync' ); ?></span>
                        <?php else : ?>
                            <span style="color: #ffb900;">● <?php esc_html_e( 'Disabled', 'client-sync' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php esc_html_e( 'Client names and details are replaced with generic text when syncing to external calendars.', 'client-sync' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Admin Notifications', 'client-sync' ); ?></strong></td>
                    <td>
                        <span style="color: #46b450;">● <?php esc_html_e( 'Active', 'client-sync' ); ?></span>
                    </td>
                    <td><?php esc_html_e( 'Admins receive warnings about missing encryption keys or misconfiguration.', 'client-sync' ); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Compliance Checklist -->
    <div class="card" style="max-width: none; margin-bottom: 20px;">
        <h2 style="margin-top: 0;">
            <span class="dashicons dashicons-yes" style="color: #0073aa;"></span>
            <?php esc_html_e( 'HIPAA Compliance Checklist', 'client-sync' ); ?>
        </h2>
        
        <p><?php esc_html_e( 'This plugin helps with technical safeguards, but HIPAA compliance also requires administrative and physical safeguards:', 'client-sync' ); ?></p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 15px;">
            
            <div>
                <h4 style="margin-top: 0; color: #0073aa;">
                    <span class="dashicons dashicons-admin-tools"></span>
                    <?php esc_html_e( 'Technical Safeguards (This Plugin)', 'client-sync' ); ?>
                </h4>
                <ul style="margin-left: 0; list-style: none; padding-left: 0;">
                    <li style="padding: 5px 0;"><span style="color: <?php echo $encryption_key_strong ? '#46b450' : '#dc3232'; ?>;"><?php echo $encryption_key_strong ? '☑' : '☐'; ?></span> <?php esc_html_e( 'Data encryption at rest', 'client-sync' ); ?></li>
                    <li style="padding: 5px 0;"><span style="color: <?php echo $audit_logging_available ? '#46b450' : '#dc3232'; ?>;"><?php echo $audit_logging_available ? '☑' : '☐'; ?></span> <?php esc_html_e( 'Audit logging enabled', 'client-sync' ); ?></li>
                    <li style="padding: 5px 0;"><span style="color: <?php echo $hipaa_mode_active ? '#46b450' : '#dc3232'; ?>;"><?php echo $hipaa_mode_active ? '☑' : '☐'; ?></span> <?php esc_html_e( 'Access controls (HIPAA mode)', 'client-sync' ); ?></li>
                    <li style="padding: 5px 0;"><span style="color: <?php echo $anonymize_external ? '#46b450' : '#ffb900'; ?>;"><?php echo $anonymize_external ? '☑' : '☐'; ?></span> <?php esc_html_e( 'Transmission security (anonymization)', 'client-sync' ); ?></li>
                </ul>
            </div>

            <div>
                <h4 style="margin-top: 0; color: #0073aa;">
                    <span class="dashicons dashicons-admin-users"></span>
                    <?php esc_html_e( 'Administrative Safeguards (Your Responsibility)', 'client-sync' ); ?>
                </h4>
                <ul style="margin-left: 0; list-style: none; padding-left: 0;">
                    <li style="padding: 5px 0;">☐ <?php esc_html_e( 'Security management process', 'client-sync' ); ?></li>
                    <li style="padding: 5px 0;">☐ <?php esc_html_e( 'Workforce training', 'client-sync' ); ?></li>
                    <li style="padding: 5px 0;">☐ <?php esc_html_e( 'Business Associate Agreements', 'client-sync' ); ?></li>
                    <li style="padding: 5px 0;">☐ <?php esc_html_e( 'Incident response procedures', 'client-sync' ); ?></li>
                    <li style="padding: 5px 0;">☐ <?php esc_html_e( 'Contingency/backup plans', 'client-sync' ); ?></li>
                </ul>
            </div>

            <div>
                <h4 style="margin-top: 0; color: #0073aa;">
                    <span class="dashicons dashicons-building"></span>
                    <?php esc_html_e( 'Physical Safeguards (Your Responsibility)', 'client-sync' ); ?>
                </h4>
                <ul style="margin-left: 0; list-style: none; padding-left: 0;">
                    <li style="padding: 5px 0;">☐ <?php esc_html_e( 'Facility access controls', 'client-sync' ); ?></li>
                    <li style="padding: 5px 0;">☐ <?php esc_html_e( 'Workstation security', 'client-sync' ); ?></li>
                    <li style="padding: 5px 0;">☐ <?php esc_html_e( 'Device and media controls', 'client-sync' ); ?></li>
                </ul>
            </div>

        </div>

        <div class="notice notice-info inline" style="margin-top: 20px;">
            <p>
                <strong><?php esc_html_e( 'Disclaimer:', 'client-sync' ); ?></strong>
                <?php esc_html_e( 'This plugin provides technical tools to assist with HIPAA compliance but does not guarantee compliance. Consult with a HIPAA compliance specialist to ensure your organization meets all requirements.', 'client-sync' ); ?>
            </p>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="card" style="max-width: none;">
        <h2 style="margin-top: 0;">
            <span class="dashicons dashicons-admin-links" style="color: #0073aa;"></span>
            <?php esc_html_e( 'Quick Actions', 'client-sync' ); ?>
        </h2>
        
        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px;">
            <?php if ( $audit_logging_available ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-audit-logs' ) ); ?>" class="button button-primary">
                    <span class="dashicons dashicons-clipboard" style="vertical-align: middle;"></span>
                    <?php esc_html_e( 'View Audit Logs', 'client-sync' ); ?>
                </a>
            <?php endif; ?>
            
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-custom-fields&cf_tab=appointments-cf' ) ); ?>" class="button button-secondary">
                <span class="dashicons dashicons-lock" style="vertical-align: middle;"></span>
                <?php esc_html_e( 'Configure Sensitive Fields', 'client-sync' ); ?>
            </a>
            
            <a href="https://www.hhs.gov/hipaa/for-professionals/security/laws-regulations/index.html" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                <span class="dashicons dashicons-external" style="vertical-align: middle;"></span>
                <?php esc_html_e( 'HIPAA Security Rule (HHS.gov)', 'client-sync' ); ?>
            </a>
        </div>
    </div>

</div>

<style>
.clisyc-hipaa-settings .notice.inline {
    margin: 0;
}
.clisyc-hipaa-settings .card {
    padding: 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}
.clisyc-hipaa-settings pre {
    white-space: pre-wrap;
    word-wrap: break-word;
}
@media screen and (max-width: 782px) {
    .clisyc-hipaa-status-cards {
        grid-template-columns: 1fr !important;
    }
}
</style>