<?php
/**
 * File: src/shared/includes/services/class-hipaa-migration.php
 * Handles migration of existing data to encrypted format for HIPAA compliance.
 *
 * Provides both WP-CLI commands and admin UI tools for:
 * - Encrypting existing unencrypted appointment data
 * - Testing encryption functionality
 * - Generating secure encryption keys
 *
 * @package    ClientSync
 * @subpackage ClientSync/Services
 */

namespace DependentMedia\ClientSync\Services;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class HIPAA_Migration
 *
 * Handles data migration for HIPAA encryption compliance.
 */
class HIPAA_Migration {

    /**
     * Singleton instance.
     *
     * @var HIPAA_Migration|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return HIPAA_Migration
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register hooks for admin UI and AJAX handlers.
     */
    public function register_hooks() {
        // Register WP-CLI commands if available
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'clisyc hipaa', [ $this, 'cli_command_handler' ] );
        }

        // Admin AJAX handlers
        add_action( 'wp_ajax_clisyc_test_encryption', [ $this, 'ajax_test_encryption' ] );
        add_action( 'wp_ajax_clisyc_migrate_to_encrypted', [ $this, 'ajax_migrate_to_encrypted' ] );
        add_action( 'wp_ajax_clisyc_generate_encryption_key', [ $this, 'ajax_generate_encryption_key' ] );
        add_action( 'wp_ajax_clisyc_encryption_status', [ $this, 'ajax_encryption_status' ] );
    }

    // =========================================================================
    // WP-CLI COMMANDS
    // =========================================================================

    /**
     * WP-CLI command handler for HIPAA operations.
     *
     * ## EXAMPLES
     *
     *     # Test encryption
     *     wp clisyc hipaa test
     *
     *     # Migrate existing data to encrypted format
     *     wp clisyc hipaa migrate
     *
     *     # Generate a secure encryption key
     *     wp clisyc hipaa generate-key
     *
     *     # Check encryption status
     *     wp clisyc hipaa status
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function cli_command_handler( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please specify a subcommand: test, migrate, generate-key, or status' );
            return;
        }

        $subcommand = $args[0];

        switch ( $subcommand ) {
            case 'test':
                $this->cli_test_encryption();
                break;

            case 'migrate':
                $dry_run = isset( $assoc_args['dry-run'] );
                $this->cli_migrate_to_encrypted( $dry_run );
                break;

            case 'generate-key':
                $length = isset( $assoc_args['length'] ) ? absint( $assoc_args['length'] ) : 64;
                $this->cli_generate_key( $length );
                break;

            case 'status':
                $this->cli_encryption_status();
                break;

            default:
                \WP_CLI::error( "Unknown subcommand: {$subcommand}. Use: test, migrate, generate-key, or status" );
        }
    }

    /**
     * CLI: Test encryption functionality.
     */
    private function cli_test_encryption() {
        \WP_CLI::log( 'Testing encryption service...' );

        if ( ! class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
            \WP_CLI::error( 'Encryption_Service class not found.' );
            return;
        }

        $encryption = Encryption_Service::get_instance();
        $result = $encryption->test();

        if ( $result['success'] ) {
            \WP_CLI::success( $result['message'] );
            
            if ( ! empty( $result['details'] ) ) {
                \WP_CLI::log( '' );
                \WP_CLI::log( 'Details:' );
                foreach ( $result['details'] as $key => $value ) {
                    \WP_CLI::log( "  {$key}: {$value}" );
                }
            }
        } else {
            \WP_CLI::error( $result['message'] );
            
            if ( ! empty( $result['details'] ) ) {
                \WP_CLI::log( '' );
                \WP_CLI::log( 'Diagnostic Details:' );
                foreach ( $result['details'] as $key => $value ) {
                    $display = is_bool( $value ) ? ( $value ? 'Yes' : 'No' ) : $value;
                    \WP_CLI::log( "  {$key}: {$display}" );
                }
            }
        }
    }

    /**
     * CLI: Migrate existing data to encrypted format.
     *
     * @param bool $dry_run If true, only report what would be migrated.
     */
    private function cli_migrate_to_encrypted( $dry_run = false ) {
        if ( $dry_run ) {
            \WP_CLI::log( '🔍 DRY RUN MODE - No changes will be made.' );
            \WP_CLI::log( '' );
        }

        // Check if HIPAA mode is operational
        if ( ! function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_operational' ) ) {
            \WP_CLI::error( 'HIPAA helper functions not available.' );
            return;
        }

        if ( ! clisyc_is_hipaa_operational() ) {
            \WP_CLI::error( 'HIPAA mode is not fully operational. Ensure CLISYC_HIPAA_MODE and CLISYC_ENCRYPTION_KEY are defined in wp-config.php.' );
            return;
        }

        $result = $this->migrate_to_encrypted( $dry_run );

        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
            return;
        }

        \WP_CLI::log( '' );
        \WP_CLI::log( '📊 Migration Summary:' );
        \WP_CLI::log( "   Appointments processed: {$result['appointments_processed']}" );
        \WP_CLI::log( "   Fields encrypted: {$result['fields_encrypted']}" );
        \WP_CLI::log( "   Fields skipped (already encrypted): {$result['fields_skipped']}" );
        \WP_CLI::log( "   Errors: {$result['errors']}" );

        if ( $dry_run ) {
            \WP_CLI::log( '' );
            \WP_CLI::warning( 'This was a dry run. Run without --dry-run to apply changes.' );
        } else {
            \WP_CLI::success( 'Migration completed!' );
        }
    }

    /**
     * CLI: Generate a secure encryption key.
     *
     * @param int $length Key length (default 64).
     */
    private function cli_generate_key( $length = 64 ) {
        if ( $length < 32 ) {
            \WP_CLI::warning( 'Key length should be at least 32 characters for security. Using 32.' );
            $length = 32;
        }

        $key = $this->generate_secure_key( $length );

        \WP_CLI::log( '' );
        \WP_CLI::log( '🔐 Generated Encryption Key:' );
        \WP_CLI::log( '' );
        \WP_CLI::log( $key );
        \WP_CLI::log( '' );
        \WP_CLI::log( 'Add this to your wp-config.php:' );
        \WP_CLI::log( '' );
        \WP_CLI::log( "define( 'CLISYC_ENCRYPTION_KEY', '{$key}' );" );
        \WP_CLI::log( '' );
        \WP_CLI::warning( 'IMPORTANT: Store this key securely. If lost, encrypted data cannot be recovered!' );
    }

    /**
     * CLI: Show encryption status.
     */
    private function cli_encryption_status() {
        \WP_CLI::log( '' );
        \WP_CLI::log( '🔒 HIPAA Encryption Status' );
        \WP_CLI::log( '=' . str_repeat( '=', 40 ) );

        // Check HIPAA mode
        $hipaa_mode = defined( 'CLISYC_HIPAA_MODE' ) && CLISYC_HIPAA_MODE;
        $hipaa_db = get_option( 'clisyc_hipaa_mode_enabled', false );
        $hipaa_active = $hipaa_mode || $hipaa_db;

        \WP_CLI::log( '' );
        \WP_CLI::log( 'Configuration:' );
        \WP_CLI::log( '  CLISYC_HIPAA_MODE constant: ' . ( defined( 'CLISYC_HIPAA_MODE' ) ? ( CLISYC_HIPAA_MODE ? '✅ true' : '❌ false' ) : '⚠️  not defined' ) );
        \WP_CLI::log( '  Database setting: ' . ( $hipaa_db ? '✅ enabled' : '❌ disabled' ) );
        \WP_CLI::log( '  Effective status: ' . ( $hipaa_active ? '✅ ACTIVE' : '❌ INACTIVE' ) );

        // Check encryption key
        $key_defined = defined( 'CLISYC_ENCRYPTION_KEY' ) && ! empty( CLISYC_ENCRYPTION_KEY );
        $key_length = $key_defined ? strlen( CLISYC_ENCRYPTION_KEY ) : 0;

        \WP_CLI::log( '' );
        \WP_CLI::log( 'Encryption Key:' );
        \WP_CLI::log( '  CLISYC_ENCRYPTION_KEY: ' . ( $key_defined ? '✅ defined' : '❌ not defined' ) );
        if ( $key_defined ) {
            \WP_CLI::log( '  Key length: ' . $key_length . ' characters' . ( $key_length >= 32 ? ' ✅' : ' ⚠️  (recommend 32+)' ) );
        }

        // Check OpenSSL
        $openssl = extension_loaded( 'openssl' );
        \WP_CLI::log( '' );
        \WP_CLI::log( 'System Requirements:' );
        \WP_CLI::log( '  OpenSSL extension: ' . ( $openssl ? '✅ loaded' : '❌ not loaded' ) );

        // Check operational status
        $operational = $hipaa_active && $key_defined && $key_length >= 32 && $openssl;
        \WP_CLI::log( '' );
        \WP_CLI::log( '━' . str_repeat( '━', 40 ) );
        \WP_CLI::log( 'Overall Status: ' . ( $operational ? '✅ HIPAA ENCRYPTION OPERATIONAL' : '❌ HIPAA ENCRYPTION NOT OPERATIONAL' ) );
        \WP_CLI::log( '' );

        if ( ! $operational ) {
            \WP_CLI::log( 'To enable HIPAA encryption, add to wp-config.php:' );
            \WP_CLI::log( '' );
            \WP_CLI::log( "  define( 'CLISYC_HIPAA_MODE', true );" );
            \WP_CLI::log( "  define( 'CLISYC_ENCRYPTION_KEY', 'your-secure-key-here' );" );
            \WP_CLI::log( '' );
            \WP_CLI::log( 'Generate a key with: wp clisyc hipaa generate-key' );
        }

        // Count encrypted vs unencrypted fields
        $stats = $this->get_encryption_statistics();
        \WP_CLI::log( '' );
        \WP_CLI::log( 'Data Statistics:' );
        \WP_CLI::log( "  Total appointments: {$stats['total_appointments']}" );
        \WP_CLI::log( "  Sensitive fields defined: {$stats['sensitive_fields_count']}" );
        \WP_CLI::log( "  Encrypted field values: {$stats['encrypted_values']}" );
        \WP_CLI::log( "  Unencrypted sensitive values: {$stats['unencrypted_values']}" );

        if ( $stats['unencrypted_values'] > 0 && $operational ) {
            \WP_CLI::log( '' );
            \WP_CLI::warning( "You have {$stats['unencrypted_values']} unencrypted sensitive values." );
            \WP_CLI::log( 'Run migration with: wp clisyc hipaa migrate' );
        }
    }

    // =========================================================================
    // AJAX HANDLERS (for Admin UI)
    // =========================================================================

    /**
     * AJAX: Test encryption functionality.
     */
    public function ajax_test_encryption() {
        check_ajax_referer( 'clisyc_hipaa_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
        }

        if ( ! class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
            wp_send_json_error( [ 'message' => __( 'Encryption service not available.', 'client-sync' ) ] );
        }

        $encryption = Encryption_Service::get_instance();
        $result = $encryption->test();

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * AJAX: Migrate existing data to encrypted format.
     */
    public function ajax_migrate_to_encrypted() {
        check_ajax_referer( 'clisyc_hipaa_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
        $dry_run = rest_sanitize_boolean( isset( $_POST['dry_run'] ) ? sanitize_text_field( wp_unslash( $_POST['dry_run'] ) ) : false );
        $result = $this->migrate_to_encrypted( $dry_run );

        if ( is_wp_error( $result ) ) {
            Debug_Logger::log( 'HIPAA migration failed: ' . $result->get_error_message(), 'HIPAA' );
            wp_send_json_error( [ 'message' => __( 'An error occurred while processing your request. Please check the error log for details.', 'client-sync' ) ] );
        } else {
            wp_send_json_success( $result );
        }
    }

    /**
     * AJAX: Generate a secure encryption key.
     */
    public function ajax_generate_encryption_key() {
        check_ajax_referer( 'clisyc_hipaa_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
        }

        $length = isset( $_POST['length'] ) ? absint( $_POST['length'] ) : 64;
        $length = max( Constants::ENCRYPTION_KEY_MIN_LENGTH, min( Constants::ENCRYPTION_KEY_MAX_LENGTH, $length ) ); // Clamp to allowed range

        $key = $this->generate_secure_key( $length );

        wp_send_json_success( [
            'key'     => $key,
            'length'  => strlen( $key ),
            'snippet' => "define( 'CLISYC_ENCRYPTION_KEY', '{$key}' );",
        ] );
    }

    /**
     * AJAX: Get encryption status.
     */
    public function ajax_encryption_status() {
        check_ajax_referer( 'clisyc_hipaa_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
        }

        $status = [
            'hipaa_mode_constant' => defined( 'CLISYC_HIPAA_MODE' ) && CLISYC_HIPAA_MODE,
            'hipaa_mode_database' => (bool) get_option( 'clisyc_hipaa_mode_enabled', false ),
            'hipaa_mode_active'   => function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) && clisyc_is_hipaa_mode(),
            'key_defined'         => defined( 'CLISYC_ENCRYPTION_KEY' ) && ! empty( CLISYC_ENCRYPTION_KEY ),
            'key_length'          => defined( 'CLISYC_ENCRYPTION_KEY' ) ? strlen( CLISYC_ENCRYPTION_KEY ) : 0,
            'openssl_available'   => extension_loaded( 'openssl' ),
            'operational'         => function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_operational' ) && clisyc_is_hipaa_operational(),
            'statistics'          => $this->get_encryption_statistics(),
        ];

        wp_send_json_success( $status );
    }

    // =========================================================================
    // CORE MIGRATION LOGIC
    // =========================================================================

    /**
     * Encrypt all existing sensitive appointment data.
     * Run this ONCE after enabling HIPAA mode.
     *
     * @param bool $dry_run If true, only report what would be migrated.
     * @return array|\WP_Error Migration statistics or error.
     */
    public function migrate_to_encrypted( $dry_run = false ) {
        // Check if HIPAA mode is operational
        if ( ! function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_operational' ) || ! clisyc_is_hipaa_operational() ) {
            return new \WP_Error( 'hipaa_not_ready', __( 'HIPAA mode is not fully operational. Ensure CLISYC_HIPAA_MODE and CLISYC_ENCRYPTION_KEY are defined.', 'client-sync' ) );
        }

        $encryption = Encryption_Service::get_instance();
        $field_defs = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );

        // Get all appointments
        $appointments = get_posts( [
            'post_type'      => Constants::POST_TYPE_APPOINTMENT,
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids', // Just get IDs for memory efficiency
        ] );

        $stats = [
            'appointments_processed' => 0,
            'fields_encrypted'       => 0,
            'fields_skipped'         => 0,
            'errors'                 => 0,
            'dry_run'                => $dry_run,
        ];

        foreach ( $appointments as $appointment_id ) {
            $stats['appointments_processed']++;

            // Process custom fields
            foreach ( $field_defs as $field_key => $field_def ) {
                $should_encrypt = $encryption->should_encrypt_field( $field_key ) || ! empty( $field_def['sensitive'] );

                if ( ! $should_encrypt ) {
                    continue;
                }

                $current_value = get_post_meta( $appointment_id, $field_key, true );

                // Skip if empty or already encrypted
                if ( empty( $current_value ) ) {
                    continue;
                }

                if ( $encryption->is_encrypted( $current_value ) ) {
                    $stats['fields_skipped']++;
                    continue;
                }

                // Encrypt the value
                if ( ! $dry_run ) {
                    $encrypted = $encryption->encrypt( $current_value );
                    if ( $encrypted !== $current_value ) {
                        $result = update_post_meta( $appointment_id, $field_key, $encrypted );
                        if ( $result ) {
                            $stats['fields_encrypted']++;
                        } else {
                            $stats['errors']++;
                        }
                    } else {
                        $stats['errors']++;
                    }
                } else {
                    $stats['fields_encrypted']++;
                }
            }

            // Process notes field (always sensitive)
            $notes = get_post_meta( $appointment_id, '_clisyc_notes', true );
            if ( ! empty( $notes ) && ! $encryption->is_encrypted( $notes ) ) {
                if ( ! $dry_run ) {
                    $encrypted_notes = $encryption->encrypt( $notes );
                    if ( $encrypted_notes !== $notes ) {
                        $result = update_post_meta( $appointment_id, '_clisyc_notes', $encrypted_notes );
                        if ( $result ) {
                            $stats['fields_encrypted']++;
                        } else {
                            $stats['errors']++;
                        }
                    } else {
                        $stats['errors']++;
                    }
                } else {
                    $stats['fields_encrypted']++;
                }
            } elseif ( ! empty( $notes ) && $encryption->is_encrypted( $notes ) ) {
                $stats['fields_skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Get encryption statistics for existing data.
     *
     * @return array Statistics about encrypted/unencrypted data.
     */
    public function get_encryption_statistics() {
        $stats = [
            'total_appointments'      => 0,
            'sensitive_fields_count'  => 0,
            'encrypted_values'        => 0,
            'unencrypted_values'      => 0,
        ];

        // Count appointments
        $count = wp_count_posts( Constants::POST_TYPE_APPOINTMENT );
        $stats['total_appointments'] = array_sum( (array) $count );

        // Get field definitions
        $field_defs = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );

        // Count sensitive fields
        $sensitive_fields = [];
        foreach ( $field_defs as $field_key => $field_def ) {
            if ( ! empty( $field_def['sensitive'] ) ) {
                $sensitive_fields[] = $field_key;
            }
        }

        // Add auto-detected sensitive fields
        if ( class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
            $encryption = Encryption_Service::get_instance();
            foreach ( $field_defs as $field_key => $field_def ) {
                if ( $encryption->should_encrypt_field( $field_key ) && ! in_array( $field_key, $sensitive_fields, true ) ) {
                    $sensitive_fields[] = $field_key;
                }
            }
        }

        // Always include notes
        $sensitive_fields[] = '_clisyc_notes';
        $sensitive_fields = array_unique( $sensitive_fields );

        $stats['sensitive_fields_count'] = count( $sensitive_fields );

        // Sample check for encrypted vs unencrypted (check first 100 appointments for performance)
        $appointments = get_posts( [
            'post_type'      => Constants::POST_TYPE_APPOINTMENT,
            'posts_per_page' => 100,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ] );

        if ( class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
            $encryption = Encryption_Service::get_instance();

            foreach ( $appointments as $appointment_id ) {
                foreach ( $sensitive_fields as $field_key ) {
                    $value = get_post_meta( $appointment_id, $field_key, true );
                    if ( ! empty( $value ) ) {
                        if ( $encryption->is_encrypted( $value ) ) {
                            $stats['encrypted_values']++;
                        } else {
                            $stats['unencrypted_values']++;
                        }
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Generate a cryptographically secure random key.
     *
     * @param int $length Key length.
     * @return string Generated key.
     */
    public function generate_secure_key( $length = 64 ) {
        $length = max( Constants::ENCRYPTION_KEY_MIN_LENGTH, min( Constants::ENCRYPTION_KEY_MAX_LENGTH, $length ) );

        // Use cryptographically secure random bytes
        $bytes = random_bytes( ceil( $length / 2 ) );
        $key = substr( bin2hex( $bytes ), 0, $length );

        return $key;
    }
}

// =========================================================================
// GLOBAL HELPER FUNCTIONS
// =========================================================================

/**
 * Get the HIPAA Migration instance.
 *
 * @return HIPAA_Migration
 */
function clisyc_hipaa_migration() {
    return HIPAA_Migration::get_instance();
}