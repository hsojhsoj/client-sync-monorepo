<?php
/**
 * File: src/shared/includes/admin/views/template-parts/part-hipaa-tools.php
 * Template part for HIPAA compliance tools in the admin settings.
 *
 * Include this in your Behavior settings page after the HIPAA mode toggle.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views/Template-Parts
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get current status
$clisyc_hipaa_mode_active = function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) 
    && \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode();
$clisyc_hipaa_operational = function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_operational' ) 
    && \DependentMedia\ClientSync\Services\clisyc_is_hipaa_operational();
$clisyc_key_defined = defined( 'CLISYC_ENCRYPTION_KEY' ) && ! empty( CLISYC_ENCRYPTION_KEY );
$clisyc_key_length = $clisyc_key_defined ? strlen( CLISYC_ENCRYPTION_KEY ) : 0;
$clisyc_openssl = extension_loaded( 'openssl' );
?>

<div class="clisyc-hipaa-tools-section" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
    
    <h3 style="margin-top: 0;">
        <span class="dashicons dashicons-shield" style="color: #0073aa;"></span>
        <?php esc_html_e( 'HIPAA Encryption Tools', 'client-sync' ); ?>
    </h3>

    <!-- Status Overview -->
    <div class="clisyc-hipaa-status" style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #e1e1e1; border-radius: 4px;">
        <h4 style="margin-top: 0;"><?php esc_html_e( 'Status Overview', 'client-sync' ); ?></h4>
        
        <table class="widefat" style="max-width: 500px;">
            <tbody>
                <tr>
                    <td><strong><?php esc_html_e( 'HIPAA Mode', 'client-sync' ); ?></strong></td>
                    <td>
                        <?php if ( $clisyc_hipaa_mode_active ) : ?>
                            <span style="color: #46b450;">✅ <?php esc_html_e( 'Active', 'client-sync' ); ?></span>
                        <?php else : ?>
                            <span style="color: #dc3232;">❌ <?php esc_html_e( 'Inactive', 'client-sync' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Encryption Key', 'client-sync' ); ?></strong></td>
                    <td>
                        <?php if ( $clisyc_key_defined ) : ?>
                            <span style="color: #46b450;">✅ <?php printf( esc_html__( 'Defined (%d characters)', 'client-sync' ), $clisyc_key_length ); ?></span>
                            <?php if ( $clisyc_key_length < 32 ) : ?>
                                <br><span style="color: #ffb900;">⚠️ <?php esc_html_e( 'Key should be at least 32 characters', 'client-sync' ); ?></span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span style="color: #dc3232;">❌ <?php esc_html_e( 'Not Defined', 'client-sync' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'OpenSSL', 'client-sync' ); ?></strong></td>
                    <td>
                        <?php if ( $clisyc_openssl ) : ?>
                            <span style="color: #46b450;">✅ <?php esc_html_e( 'Available', 'client-sync' ); ?></span>
                        <?php else : ?>
                            <span style="color: #dc3232;">❌ <?php esc_html_e( 'Not Available', 'client-sync' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr style="background: #f0f0f0;">
                    <td><strong><?php esc_html_e( 'Overall Status', 'client-sync' ); ?></strong></td>
                    <td>
                        <?php if ( $clisyc_hipaa_operational ) : ?>
                            <span style="color: #46b450; font-weight: bold;">✅ <?php esc_html_e( 'ENCRYPTION OPERATIONAL', 'client-sync' ); ?></span>
                        <?php else : ?>
                            <span style="color: #dc3232; font-weight: bold;">❌ <?php esc_html_e( 'ENCRYPTION NOT OPERATIONAL', 'client-sync' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <div id="clisyc-hipaa-data-stats" style="margin-top: 15px; display: none;">
            <h5><?php esc_html_e( 'Data Statistics', 'client-sync' ); ?></h5>
            <p>
                <span id="clisyc-stats-appointments"></span> |
                <span id="clisyc-stats-encrypted"></span> |
                <span id="clisyc-stats-unencrypted"></span>
            </p>
        </div>
    </div>

    <?php if ( ! $clisyc_key_defined ) : ?>
    <!-- Key Generation Tool -->
    <div class="clisyc-hipaa-tool" style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #e1e1e1; border-radius: 4px;">
        <h4 style="margin-top: 0;">
            <span class="dashicons dashicons-admin-network"></span>
            <?php esc_html_e( 'Generate Encryption Key', 'client-sync' ); ?>
        </h4>
        <p class="description"><?php esc_html_e( 'Generate a cryptographically secure encryption key. You\'ll need to add this to your wp-config.php file.', 'client-sync' ); ?></p>
        
        <p>
            <button type="button" id="clisyc-generate-key-btn" class="button button-secondary">
                <span class="dashicons dashicons-randomize" style="vertical-align: text-bottom;"></span>
                <?php esc_html_e( 'Generate Key', 'client-sync' ); ?>
            </button>
        </p>
        
        <div id="clisyc-generated-key-output" style="display: none; margin-top: 15px;">
            <label for="clisyc-generated-key"><strong><?php esc_html_e( 'Generated Key:', 'client-sync' ); ?></strong></label>
            <textarea id="clisyc-generated-key" rows="2" class="large-text code" readonly style="font-family: monospace; background: #f6f6f6;"></textarea>
            
            <p style="margin-top: 10px;">
                <strong><?php esc_html_e( 'Add this to wp-config.php:', 'client-sync' ); ?></strong>
            </p>
            <textarea id="clisyc-generated-key-snippet" rows="3" class="large-text code" readonly style="font-family: monospace; background: #fffbcc;"></textarea>
            
            <p>
                <button type="button" class="button clisyc-copy-to-clipboard" data-target="#clisyc-generated-key-snippet">
                    <span class="dashicons dashicons-clipboard" style="vertical-align: text-bottom;"></span>
                    <?php esc_html_e( 'Copy to Clipboard', 'client-sync' ); ?>
                </button>
            </p>
            
            <div class="notice notice-warning inline" style="margin: 15px 0;">
                <p>
                    <strong><?php esc_html_e( '⚠️ IMPORTANT:', 'client-sync' ); ?></strong>
                    <?php esc_html_e( 'Store this key securely. If lost, encrypted data cannot be recovered! Back up this key in a secure location.', 'client-sync' ); ?>
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Test Encryption Tool -->
    <div class="clisyc-hipaa-tool" style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #e1e1e1; border-radius: 4px;">
        <h4 style="margin-top: 0;">
            <span class="dashicons dashicons-yes-alt"></span>
            <?php esc_html_e( 'Test Encryption', 'client-sync' ); ?>
        </h4>
        <p class="description"><?php esc_html_e( 'Verify that encryption is working correctly with your current configuration.', 'client-sync' ); ?></p>
        
        <p>
            <button type="button" id="clisyc-test-encryption-btn" class="button button-secondary" <?php disabled( ! $clisyc_hipaa_operational ); ?>>
                <span class="dashicons dashicons-hammer" style="vertical-align: text-bottom;"></span>
                <?php esc_html_e( 'Run Encryption Test', 'client-sync' ); ?>
            </button>
            <?php if ( ! $clisyc_hipaa_operational ) : ?>
                <span class="description" style="margin-left: 10px; color: #666;"><?php esc_html_e( 'Enable HIPAA mode and configure encryption key first.', 'client-sync' ); ?></span>
            <?php endif; ?>
        </p>
        
        <div id="clisyc-test-encryption-result" style="display: none; margin-top: 15px;"></div>
    </div>

    <!-- Migration Tool -->
    <div class="clisyc-hipaa-tool" style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #e1e1e1; border-radius: 4px;">
        <h4 style="margin-top: 0;">
            <span class="dashicons dashicons-database-import"></span>
            <?php esc_html_e( 'Migrate Existing Data', 'client-sync' ); ?>
        </h4>
        <p class="description"><?php esc_html_e( 'Encrypt existing appointment data that was saved before HIPAA mode was enabled. This only needs to be run once.', 'client-sync' ); ?></p>
        
        <p>
            <button type="button" id="clisyc-migrate-dry-run-btn" class="button button-secondary" <?php disabled( ! $clisyc_hipaa_operational ); ?>>
                <span class="dashicons dashicons-visibility" style="vertical-align: text-bottom;"></span>
                <?php esc_html_e( 'Preview Migration (Dry Run)', 'client-sync' ); ?>
            </button>
            
            <button type="button" id="clisyc-migrate-btn" class="button button-primary" <?php disabled( ! $clisyc_hipaa_operational ); ?> style="margin-left: 10px;">
                <span class="dashicons dashicons-lock" style="vertical-align: text-bottom;"></span>
                <?php esc_html_e( 'Encrypt Existing Data', 'client-sync' ); ?>
            </button>
            
            <?php if ( ! $clisyc_hipaa_operational ) : ?>
                <span class="description" style="margin-left: 10px; color: #666;"><?php esc_html_e( 'Enable HIPAA mode and configure encryption key first.', 'client-sync' ); ?></span>
            <?php endif; ?>
        </p>
        
        <div id="clisyc-migration-result" style="display: none; margin-top: 15px;"></div>
    </div>

    <!-- WP-CLI Info -->
    <div class="clisyc-hipaa-tool" style="padding: 15px; background: #fff; border: 1px solid #e1e1e1; border-radius: 4px;">
        <h4 style="margin-top: 0;">
            <span class="dashicons dashicons-editor-code"></span>
            <?php esc_html_e( 'WP-CLI Commands', 'client-sync' ); ?>
        </h4>
        <p class="description"><?php esc_html_e( 'For advanced users, these operations are also available via WP-CLI:', 'client-sync' ); ?></p>
        
        <pre style="background: #23282d; color: #eee; padding: 15px; border-radius: 4px; overflow-x: auto;">
<span style="color: #9cdcfe;"># Check encryption status</span>
wp clisyc hipaa status

<span style="color: #9cdcfe;"># Test encryption</span>
wp clisyc hipaa test

<span style="color: #9cdcfe;"># Generate a secure key</span>
wp clisyc hipaa generate-key

<span style="color: #9cdcfe;"># Preview migration (dry run)</span>
wp clisyc hipaa migrate --dry-run

<span style="color: #9cdcfe;"># Run migration</span>
wp clisyc hipaa migrate</pre>
    </div>

</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    var nonce = '<?php echo esc_js( wp_create_nonce( 'clisyc_hipaa_admin_nonce' ) ); ?>';
    
    // Load initial stats
    loadEncryptionStatus();
    
    function loadEncryptionStatus() {
        $.post(ajaxurl, {
            action: 'clisyc_encryption_status',
            nonce: nonce
        }, function(response) {
            if (response.success && response.data.statistics) {
                var stats = response.data.statistics;
                $('#clisyc-stats-appointments').text('<?php esc_html_e( 'Appointments:', 'client-sync' ); ?> ' + stats.total_appointments);
                $('#clisyc-stats-encrypted').text('<?php esc_html_e( 'Encrypted values:', 'client-sync' ); ?> ' + stats.encrypted_values);
                $('#clisyc-stats-unencrypted').text('<?php esc_html_e( 'Unencrypted sensitive:', 'client-sync' ); ?> ' + stats.unencrypted_values);
                $('#clisyc-hipaa-data-stats').show();
            }
        });
    }
    
    // Generate Key
    $('#clisyc-generate-key-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php esc_html_e( 'Generating...', 'client-sync' ); ?>');
        
        $.post(ajaxurl, {
            action: 'clisyc_generate_encryption_key',
            nonce: nonce,
            length: 64
        }, function(response) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-randomize" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Generate Key', 'client-sync' ); ?>');
            
            if (response.success) {
                $('#clisyc-generated-key').val(response.data.key);
                $('#clisyc-generated-key-snippet').val(response.data.snippet);
                $('#clisyc-generated-key-output').slideDown();
            } else {
                alert('<?php esc_html_e( 'Error generating key.', 'client-sync' ); ?>');
            }
        });
    });
    
    // Copy to Clipboard
    $('.clisyc-copy-to-clipboard').on('click', function() {
        var target = $($(this).data('target'));
        target.select();
        document.execCommand('copy');
        
        var $btn = $(this);
        var originalText = $btn.html();
        $btn.html('<span class="dashicons dashicons-yes" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Copied!', 'client-sync' ); ?>');
        setTimeout(function() {
            $btn.html(originalText);
        }, 2000);
    });
    
    // Test Encryption
    $('#clisyc-test-encryption-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#clisyc-test-encryption-result');
        
        $btn.prop('disabled', true).text('<?php esc_html_e( 'Testing...', 'client-sync' ); ?>');
        $result.hide();
        
        $.post(ajaxurl, {
            action: 'clisyc_test_encryption',
            nonce: nonce
        }, function(response) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-hammer" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Run Encryption Test', 'client-sync' ); ?>');
            
            var html = '';
            if (response.success) {
                html = '<div class="notice notice-success inline"><p><strong>✅ ' + response.data.message + '</strong></p>';
                if (response.data.details) {
                    html += '<ul style="margin-left: 20px;">';
                    for (var key in response.data.details) {
                        html += '<li><strong>' + key + ':</strong> ' + response.data.details[key] + '</li>';
                    }
                    html += '</ul>';
                }
                html += '</div>';
            } else {
                html = '<div class="notice notice-error inline"><p><strong>❌ ' + (response.data.message || '<?php esc_html_e( 'Test failed', 'client-sync' ); ?>') + '</strong></p>';
                if (response.data && response.data.details) {
                    html += '<ul style="margin-left: 20px;">';
                    for (var key in response.data.details) {
                        html += '<li><strong>' + key + ':</strong> ' + response.data.details[key] + '</li>';
                    }
                    html += '</ul>';
                }
                html += '</div>';
            }
            
            $result.html(html).slideDown();
        });
    });
    
    // Migration Dry Run
    $('#clisyc-migrate-dry-run-btn').on('click', function() {
        runMigration(true);
    });
    
    // Migration
    $('#clisyc-migrate-btn').on('click', function() {
        if (!confirm('<?php esc_html_e( 'This will encrypt all existing sensitive data. This cannot be undone without the encryption key. Are you sure?', 'client-sync' ); ?>')) {
            return;
        }
        runMigration(false);
    });
    
    function runMigration(dryRun) {
        var $result = $('#clisyc-migration-result');
        var $dryBtn = $('#clisyc-migrate-dry-run-btn');
        var $migrateBtn = $('#clisyc-migrate-btn');
        
        $dryBtn.prop('disabled', true);
        $migrateBtn.prop('disabled', true);
        $result.html('<p><span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> <?php esc_html_e( 'Processing...', 'client-sync' ); ?></p>').show();
        
        $.post(ajaxurl, {
            action: 'clisyc_migrate_to_encrypted',
            nonce: nonce,
            dry_run: dryRun ? 'true' : 'false'
        }, function(response) {
            $dryBtn.prop('disabled', false);
            $migrateBtn.prop('disabled', false);
            
            var html = '';
            if (response.success) {
                var noticeClass = dryRun ? 'notice-info' : 'notice-success';
                var icon = dryRun ? '🔍' : '✅';
                var title = dryRun ? '<?php esc_html_e( 'Dry Run Results (no changes made)', 'client-sync' ); ?>' : '<?php esc_html_e( 'Migration Complete', 'client-sync' ); ?>';
                
                html = '<div class="notice ' + noticeClass + ' inline"><p><strong>' + icon + ' ' + title + '</strong></p>';
                html += '<ul style="margin-left: 20px;">';
                html += '<li><?php esc_html_e( 'Appointments processed:', 'client-sync' ); ?> <strong>' + response.data.appointments_processed + '</strong></li>';
                html += '<li><?php esc_html_e( 'Fields encrypted:', 'client-sync' ); ?> <strong>' + response.data.fields_encrypted + '</strong></li>';
                html += '<li><?php esc_html_e( 'Fields skipped (already encrypted):', 'client-sync' ); ?> <strong>' + response.data.fields_skipped + '</strong></li>';
                html += '<li><?php esc_html_e( 'Errors:', 'client-sync' ); ?> <strong>' + response.data.errors + '</strong></li>';
                html += '</ul>';
                
                if (dryRun && response.data.fields_encrypted > 0) {
                    html += '<p><em><?php esc_html_e( 'Click "Encrypt Existing Data" to apply these changes.', 'client-sync' ); ?></em></p>';
                }
                
                html += '</div>';
                
                // Refresh stats
                loadEncryptionStatus();
            } else {
                html = '<div class="notice notice-error inline"><p><strong>❌ ' + (response.data.message || '<?php esc_html_e( 'Migration failed', 'client-sync' ); ?>') + '</strong></p></div>';
            }
            
            $result.html(html);
        });
    }
});
</script>