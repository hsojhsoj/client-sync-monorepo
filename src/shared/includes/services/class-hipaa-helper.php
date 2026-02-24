<?php
/**
 * File: src/shared/includes/services/class-hipaa-helper.php
 * Central helper for HIPAA compliance mode detection and validation.
 *
 * This class provides:
 * - Unified detection of HIPAA mode (constant override + database setting)
 * - Encryption key validation
 * - Admin notices for configuration issues
 * - Helper methods for other HIPAA features (audit log, anonymization)
 *
 * @package    ClientSync
 * @subpackage ClientSync/Services
 * @since      3.3.0
 */

namespace DependentMedia\ClientSync\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HIPAA_Helper {

    /**
     * Option name for the database-stored HIPAA mode toggle.
     */
    const OPTION_HIPAA_MODE = 'clisyc_hipaa_mode_enabled';

    /**
     * Transient name for suppressing repeated admin notices.
     */
    const NOTICE_TRANSIENT = 'clisyc_hipaa_notice_dismissed';

    /**
     * Singleton instance.
     *
     * @var HIPAA_Helper|null
     */
    private static $instance = null;

    /**
     * Cached HIPAA mode status (to avoid repeated checks).
     *
     * @var bool|null
     */
    private $cached_status = null;

    /**
     * Get singleton instance.
     *
     * @return HIPAA_Helper
     */
    public static function get_instance(): HIPAA_Helper {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor (singleton pattern).
     */
    private function __construct() {}

    /**
     * Register hooks for admin notices.
     *
     * @return void
     */
    public function register_hooks(): void {
        add_action( 'admin_notices', [ $this, 'render_hipaa_admin_notices' ] );
        add_action( 'wp_ajax_clisyc_dismiss_hipaa_notice', [ $this, 'ajax_dismiss_notice' ] );
    }

    /**
     * Check if HIPAA mode is enabled.
     *
     * Priority order:
     * 1. CLISYC_HIPAA_MODE constant (if defined) - hard override
     * 2. Database option (admin toggle)
     *
     * @return bool True if HIPAA mode is enabled.
     */
    public function is_hipaa_mode(): bool {
        // Return cached value if already computed this request.
        if ( null !== $this->cached_status ) {
            return $this->cached_status;
        }

        // Priority 1: Check for constant override (wp-config.php)
        if ( defined( 'CLISYC_HIPAA_MODE' ) ) {
            $this->cached_status = (bool) CLISYC_HIPAA_MODE;
            return $this->cached_status;
        }

        // Priority 2: Check database option
        $this->cached_status = (bool) get_option( self::OPTION_HIPAA_MODE, false );
        return $this->cached_status;
    }

    /**
     * Check if HIPAA mode is locked by a constant (cannot be changed via UI).
     *
     * @return bool True if the CLISYC_HIPAA_MODE constant is defined.
     */
    public function is_hipaa_mode_locked(): bool {
        return defined( 'CLISYC_HIPAA_MODE' );
    }

    /**
     * Get the source of the current HIPAA mode setting.
     *
     * @return string 'constant', 'database', or 'default'
     */
    public function get_hipaa_mode_source(): string {
        if ( defined( 'CLISYC_HIPAA_MODE' ) ) {
            return 'constant';
        }

        if ( get_option( self::OPTION_HIPAA_MODE ) !== false ) {
            return 'database';
        }

        return 'default';
    }

    /**
     * Check if encryption key is configured.
     *
     * @return bool True if CLISYC_ENCRYPTION_KEY constant is defined and not empty.
     */
    public function has_encryption_key(): bool {
        return defined( 'CLISYC_ENCRYPTION_KEY' ) && ! empty( CLISYC_ENCRYPTION_KEY );
    }

    /**
     * Validate encryption key strength.
     *
     * @return array{valid: bool, message: string} Validation result.
     */
    public function validate_encryption_key(): array {
        if ( ! $this->has_encryption_key() ) {
            return [
                'valid'   => false,
                'message' => __( 'Encryption key is not defined.', 'client-sync' ),
            ];
        }

        $key = CLISYC_ENCRYPTION_KEY;
        $key_length = strlen( $key );

        // Minimum 32 characters (256 bits) for AES-256
        if ( $key_length < 32 ) {
            return [
                'valid'   => false,
                'message' => sprintf(
                    /* translators: %d: current key length */
                    __( 'Encryption key is too short (%d characters). Minimum 32 characters required for AES-256.', 'client-sync' ),
                    $key_length
                ),
            ];
        }

        // Warn if key looks like a placeholder
        $placeholder_patterns = [
            'your-secret-key',
            'change-this',
            'replace-me',
            'xxxxxxxx',
            '12345678',
        ];
        foreach ( $placeholder_patterns as $pattern ) {
            if ( stripos( $key, $pattern ) !== false ) {
                return [
                    'valid'   => false,
                    'message' => __( 'Encryption key appears to be a placeholder. Please generate a secure random key.', 'client-sync' ),
                ];
            }
        }

        return [
            'valid'   => true,
            'message' => __( 'Encryption key is configured and meets minimum requirements.', 'client-sync' ),
        ];
    }

    /**
     * Check if HIPAA mode is fully operational (enabled + valid encryption).
     *
     * @return bool True if HIPAA mode is enabled AND encryption is properly configured.
     */
    public function is_hipaa_fully_operational(): bool {
        if ( ! $this->is_hipaa_mode() ) {
            return false;
        }

        $key_validation = $this->validate_encryption_key();
        return $key_validation['valid'];
    }

    /**
     * Get HIPAA configuration status for display.
     *
     * @return array{enabled: bool, locked: bool, source: string, encryption: array, operational: bool}
     */
    public function get_status(): array {
        return [
            'enabled'     => $this->is_hipaa_mode(),
            'locked'      => $this->is_hipaa_mode_locked(),
            'source'      => $this->get_hipaa_mode_source(),
            'encryption'  => $this->validate_encryption_key(),
            'operational' => $this->is_hipaa_fully_operational(),
        ];
    }

    /**
     * Render admin notices for HIPAA configuration issues.
     *
     * @return void
     */
    public function render_hipaa_admin_notices(): void {
        // Only show to admins on relevant pages
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Don't show if notice was recently dismissed
        if ( get_transient( self::NOTICE_TRANSIENT ) ) {
            return;
        }

        // Only show notices if HIPAA mode is enabled
        if ( ! $this->is_hipaa_mode() ) {
            return;
        }

        // Check for missing/invalid encryption key
        $key_validation = $this->validate_encryption_key();
        if ( ! $key_validation['valid'] ) {
            $this->render_encryption_key_notice( $key_validation['message'] );
        }
    }

    /**
     * Render the critical notice for missing/invalid encryption key.
     *
     * @param string $message The validation error message.
     * @return void
     */
    private function render_encryption_key_notice( string $message ): void {
        $docs_url = 'https://dependentmedia.com/client-sync/docs/hipaa-setup/';
        ?>
        <div class="notice notice-error clisyc-hipaa-notice" data-notice-id="encryption_key">
            <p>
                <strong><?php esc_html_e( '⚠️ Client Sync HIPAA Mode - Configuration Required', 'client-sync' ); ?></strong>
            </p>
            <p>
                <?php echo esc_html( $message ); ?>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s: documentation URL */
                    wp_kses(
                        __( 'HIPAA mode is enabled but encryption is not properly configured. Patient data will <strong>not</strong> be encrypted until this is fixed. <a href="%s" target="_blank">View setup documentation →</a>', 'client-sync' ),
                        [ 'strong' => [], 'a' => [ 'href' => [], 'target' => [] ] ]
                    ),
                    esc_url( $docs_url )
                );
                ?>
            </p>
            <p>
                <code style="display: block; padding: 10px; background: #f6f7f7; margin: 10px 0;">
                    // Add to wp-config.php:<br>
                    define( 'CLISYC_ENCRYPTION_KEY', '<?php echo esc_html( $this->generate_example_key() ); ?>' );
                </code>
            </p>
            <p>
                <button type="button" class="button clisyc-dismiss-hipaa-notice">
                    <?php esc_html_e( 'Dismiss for 24 hours', 'client-sync' ); ?>
                </button>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('.clisyc-dismiss-hipaa-notice').on('click', function() {
                var $notice = $(this).closest('.clisyc-hipaa-notice');
                $.post(ajaxurl, {
                    action: 'clisyc_dismiss_hipaa_notice',
                    notice_id: $notice.data('notice-id'),
                    _wpnonce: '<?php echo esc_js( wp_create_nonce( 'clisyc_dismiss_hipaa_notice' ) ); ?>'
                });
                $notice.fadeOut();
            });
        });
        </script>
        <?php
    }

    /**
     * Generate an example encryption key for display.
     *
     * @return string A sample key (NOT for actual use).
     */
    private function generate_example_key(): string {
        // Generate a readable example - users should generate their own
        return 'your-unique-' . wp_generate_password( 32, false, false );
    }

    /**
     * AJAX handler for dismissing notices.
     *
     * @return void
     */
    public function ajax_dismiss_notice(): void {
        check_ajax_referer( 'clisyc_dismiss_hipaa_notice' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
        }

        // Set transient for 24 hours.
        set_transient( self::NOTICE_TRANSIENT, true, DAY_IN_SECONDS );

        wp_send_json_success();
    }

    /**
     * Clear the cached HIPAA status (useful after option updates).
     *
     * @return void
     */
    public function clear_cache(): void {
        $this->cached_status = null;
    }
}

// =============================================================================
// GLOBAL HELPER FUNCTION
// =============================================================================

/**
 * Check if HIPAA mode is enabled.
 *
 * This is the primary function other parts of the plugin should use.
 *
 * @since 3.3.0
 * @return bool True if HIPAA mode is enabled.
 */
function clisyc_is_hipaa_mode(): bool {
    return HIPAA_Helper::get_instance()->is_hipaa_mode();
}

/**
 * Check if HIPAA mode is fully operational (enabled + encryption configured).
 *
 * Use this when you need to ensure encryption is available before processing PHI.
 *
 * @since 3.3.0
 * @return bool True if HIPAA mode is enabled AND encryption is properly configured.
 */
function clisyc_is_hipaa_operational(): bool {
    return HIPAA_Helper::get_instance()->is_hipaa_fully_operational();
}

/**
 * Get the HIPAA helper instance for advanced usage.
 *
 * @since 3.3.0
 * @return HIPAA_Helper
 */
function clisyc_hipaa(): HIPAA_Helper {
    return HIPAA_Helper::get_instance();
}