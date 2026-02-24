<?php
/**
 * File: src/shared/includes/services/class-encryption-service.php
 * Encryption service for HIPAA-compliant PHI protection.
 *
 * This service provides:
 * - AES-256-GCM authenticated encryption (v2, current)
 * - Legacy AES-256-CBC decryption support (v1, read-only)
 * - Automatic key derivation from CLISYC_ENCRYPTION_KEY constant via HKDF
 * - Wrapper methods that respect HIPAA mode setting
 * - Support for both string and array data
 *
 * SECURITY NOTES:
 * - Encryption key MUST be defined in wp-config.php (not in database)
 * - Key should be at least 32 characters for AES-256
 * - Each encrypted value includes a unique IV and GCM auth tag
 * - Encrypted data is prefixed with a marker for identification
 * - v2 (GCM) provides both confidentiality AND integrity protection
 *
 * @package    ClientSync
 * @subpackage ClientSync/Services
 * @since      3.3.0
 */

namespace DependentMedia\ClientSync\Services;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Encryption_Service {

    /**
     * Legacy encryption algorithm (v1) - kept for decrypting existing data.
     */
    const CIPHER_ALGO = 'aes-256-cbc';

    /**
     * Current encryption algorithm (v2) - AES-256-GCM provides authenticated encryption.
     */
    const CIPHER_ALGO_GCM = 'aes-256-gcm';

    /**
     * GCM IV length in bytes (NIST recommendation).
     */
    const GCM_IV_LENGTH = 12;

    /**
     * GCM authentication tag length in bytes.
     */
    const GCM_TAG_LENGTH = 16;

    /**
     * Prefix marker to identify encrypted values in the database.
     * This allows us to distinguish encrypted data from plain text.
     */
    const ENCRYPTED_PREFIX = '$CLISYC_ENC$';

    /**
     * Legacy version marker (AES-256-CBC without HMAC).
     */
    const ENCRYPTION_VERSION = '1';

    /**
     * Current version marker (AES-256-GCM with authenticated encryption).
     */
    const ENCRYPTION_VERSION_2 = '2';

    /**
     * Singleton instance.
     *
     * @var Encryption_Service|null
     */
    private static $instance = null;

    /**
     * Derived encryption key for v2 (HKDF-based).
     *
     * @var string|null
     */
    private $derived_key = null;

    /**
     * Legacy derived key for v1 decryption (SHA-256-based).
     *
     * @var string|null
     */
    private $derived_key_v1 = null;

    /**
     * Tracks decrypt audit events already logged this request to avoid duplicates.
     *
     * @var array<string, true>
     */
    private static $logged_this_request = [];

    /**
     * Get singleton instance.
     *
     * @return Encryption_Service
     */
    public static function get_instance(): Encryption_Service {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor (singleton pattern).
     */
    private function __construct() {
        $this->derived_key_v1 = $this->derive_key_v1();
        $this->derived_key    = $this->derive_key();
    }

    /**
     * Check if encryption is available.
     *
     * @return bool True if encryption can be performed.
     */
    public function is_available(): bool {
        // Check if OpenSSL is available.
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return false;
        }

        // Check if GCM algorithm is supported.
        if ( ! in_array( self::CIPHER_ALGO_GCM, openssl_get_cipher_methods(), true ) ) {
            return false;
        }

        // Check if key is configured.
        if ( empty( $this->derived_key ) ) {
            return false;
        }

        return true;
    }

    /**
     * Derive a 256-bit key using HKDF for v2 encryption.
     *
     * Uses HKDF (RFC 5869) for proper key derivation with domain separation.
     *
     * @return string|null The derived key or null if not configured.
     */
    private function derive_key(): ?string {
        if ( ! defined( 'CLISYC_ENCRYPTION_KEY' ) || empty( CLISYC_ENCRYPTION_KEY ) ) {
            return null;
        }

        // Use HKDF for proper key derivation with domain separation.
        // The 'info' parameter provides context binding so the same master key
        // produces different derived keys for different purposes.
        return hash_hkdf( 'sha256', CLISYC_ENCRYPTION_KEY, 32, 'clisyc-encryption-v2' );
    }

    /**
     * Derive key using legacy method (v1) for decrypting old data.
     *
     * @return string|null The derived key or null if not configured.
     */
    private function derive_key_v1(): ?string {
        if ( ! defined( 'CLISYC_ENCRYPTION_KEY' ) || empty( CLISYC_ENCRYPTION_KEY ) ) {
            return null;
        }

        return hash( 'sha256', CLISYC_ENCRYPTION_KEY, true );
    }

    /**
     * Get the IV length for the legacy CBC cipher algorithm.
     *
     * @return int The IV length in bytes.
     */
    private function get_iv_length(): int {
        return openssl_cipher_iv_length( self::CIPHER_ALGO );
    }

    /**
     * Encrypt a string value using AES-256-GCM (authenticated encryption).
     *
     * Format: $CLISYC_ENC$2:base64(iv):base64(ciphertext):base64(tag)
     *
     * @param string $plaintext The value to encrypt.
     * @return string|false The encrypted value or false on failure.
     */
    public function encrypt( string $plaintext ): string|false {
        if ( ! $this->is_available() ) {
            Debug_Logger::log_critical( 'Encryption not available. Data will NOT be stored.', 'Encryption' );
            return false;
        }

        // Don't double-encrypt.
        if ( $this->is_encrypted( $plaintext ) ) {
            return $plaintext;
        }

        // Generate a random 12-byte IV for GCM (NIST recommended).
        $iv = openssl_random_pseudo_bytes( self::GCM_IV_LENGTH );

        if ( false === $iv ) {
            return false;
        }

        // Encrypt with AES-256-GCM (provides both confidentiality and integrity).
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_ALGO_GCM,
            $this->derived_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // No additional authenticated data (AAD).
            self::GCM_TAG_LENGTH
        );

        if ( false === $ciphertext ) {
            return false;
        }

        // Format: prefix + version : base64(iv) : base64(ciphertext) : base64(tag)
        return self::ENCRYPTED_PREFIX . self::ENCRYPTION_VERSION_2 . ':'
            . base64_encode( $iv ) . ':'
            . base64_encode( $ciphertext ) . ':'
            . base64_encode( $tag );
    }

    /**
     * Decrypt an encrypted string value.
     *
     * Supports both v2 (AES-256-GCM) and v1 (legacy AES-256-CBC) formats.
     *
     * @param string $encrypted The encrypted value.
     * @return string|false The decrypted value or false on failure.
     */
    public function decrypt( string $encrypted ): string|false {
        // Check if the value is actually encrypted.
        if ( ! $this->is_encrypted( $encrypted ) ) {
            // Return as-is if not encrypted (backwards compatibility).
            return $encrypted;
        }

        // Remove prefix and parse components.
        $data = substr( $encrypted, strlen( self::ENCRYPTED_PREFIX ) );
        $parts = explode( ':', $data );
        $version = $parts[0] ?? '';

        if ( self::ENCRYPTION_VERSION_2 === $version ) {
            return $this->decrypt_v2( $parts );
        }

        if ( self::ENCRYPTION_VERSION === $version ) {
            return $this->decrypt_v1( $parts );
        }

            Debug_Logger::log_critical( "Unsupported encryption version: {$version}", 'Encryption' );
        return false;
    }

    /**
     * Decrypt a v2 (AES-256-GCM) encrypted value.
     *
     * @param array $parts The exploded encrypted string parts [version, iv_b64, ciphertext_b64, tag_b64].
     * @return string|false The decrypted value or false on failure.
     */
    private function decrypt_v2( array $parts ): string|false {
        if ( count( $parts ) !== 4 ) {
            return false;
        }

        if ( empty( $this->derived_key ) ) {
            return false;
        }

        $iv         = base64_decode( $parts[1], true );
        $ciphertext = base64_decode( $parts[2], true );
        $tag        = base64_decode( $parts[3], true );

        if ( false === $iv || false === $ciphertext || false === $tag ) {
            return false;
        }

        // GCM tags must be exactly 16 bytes; a tampered base64 string can
        // produce the wrong length, which causes openssl_decrypt() to emit
        // a warning before returning false.
        if ( strlen( $tag ) !== 16 ) {
            return false;
        }

        // Decrypt with GCM — this also verifies the authentication tag.
        // If the ciphertext or tag has been tampered with, this returns false.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- openssl may warn on corrupted input.
        $plaintext = @openssl_decrypt(
            $ciphertext,
            self::CIPHER_ALGO_GCM,
            $this->derived_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext;
    }

    /**
     * Decrypt a v1 (legacy AES-256-CBC) encrypted value.
     *
     * This method exists solely for backward compatibility with data encrypted
     * before the v2 upgrade. New encryptions always use v2 (GCM).
     *
     * @param array $parts The exploded encrypted string parts [version, iv_b64, ciphertext_b64].
     * @return string|false The decrypted value or false on failure.
     */
    private function decrypt_v1( array $parts ): string|false {
        if ( count( $parts ) !== 3 ) {
            return false;
        }

        if ( empty( $this->derived_key_v1 ) ) {
            return false;
        }

        $iv         = base64_decode( $parts[1], true );
        $ciphertext = base64_decode( $parts[2], true );

        if ( false === $iv || false === $ciphertext ) {
            return false;
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER_ALGO,
            $this->derived_key_v1,
            OPENSSL_RAW_DATA,
            $iv
        );

        return $plaintext;
    }

    /**
     * Check if a value appears to be encrypted.
     *
     * @param mixed $value The value to check.
     * @return bool True if the value appears to be encrypted.
     */
    public function is_encrypted( $value ): bool {
        if ( ! is_string( $value ) ) {
            return false;
        }

        return str_starts_with( $value, self::ENCRYPTED_PREFIX );
    }

    // =========================================================================
    // HIPAA-AWARE WRAPPER METHODS
    // =========================================================================

    /**
     * Encrypt a value only if HIPAA mode is enabled.
     *
     * This is the primary method to use when saving sensitive data.
     * It automatically checks HIPAA mode and handles arrays.
     *
     * SECURITY: If encryption fails, this method returns false rather than
     * falling back to storing plaintext. Callers MUST handle the false case
     * to prevent silent PHI exposure.
     *
     * @param mixed  $value      The value to potentially encrypt.
     * @param string $field_key  The field key (for logging purposes).
     * @return mixed The encrypted value (if HIPAA mode), original value (if HIPAA disabled), or false on encryption failure.
     */
    public function maybe_encrypt( $value, string $field_key = '' ) {
        // Check if HIPAA mode is enabled and operational.
        if ( ! function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_operational' ) ) {
            return $value;
        }

        if ( ! \DependentMedia\ClientSync\Services\clisyc_is_hipaa_operational() ) {
            return $value;
        }

        // Handle empty values.
        if ( empty( $value ) && $value !== '0' && $value !== 0 ) {
            return $value;
        }

        // Handle arrays (serialize first, then encrypt).
        if ( is_array( $value ) ) {
            $serialized = maybe_serialize( $value );
            $encrypted  = $this->encrypt( $serialized );
            if ( false === $encrypted ) {
                Debug_Logger::log_critical( "Failed to encrypt array field '{$field_key}'. Data will NOT be stored to prevent PHI exposure.", 'Encryption' );
                return false;
            }
            return $encrypted;
        }

        // Handle strings.
        if ( is_string( $value ) ) {
            $encrypted = $this->encrypt( $value );
            if ( false === $encrypted ) {
                Debug_Logger::log_critical( "Failed to encrypt string field '{$field_key}'. Data will NOT be stored to prevent PHI exposure.", 'Encryption' );
                return false;
            }
            return $encrypted;
        }

        // Other scalar types - convert to string first.
        if ( is_scalar( $value ) ) {
            $encrypted = $this->encrypt( (string) $value );
            if ( false === $encrypted ) {
                Debug_Logger::log_critical( "Failed to encrypt scalar field '{$field_key}'. Data will NOT be stored to prevent PHI exposure.", 'Encryption' );
                return false;
            }
            return $encrypted;
        }

        // Return original value for unsupported types.
        return $value;
    }

    /**
     * Decrypt a value only if it appears to be encrypted.
     *
     * This is the primary method to use when retrieving sensitive data.
     * It automatically handles encrypted arrays and backwards compatibility.
     * Logs a HIPAA audit event on successful decryption (deduplicated per request).
     *
     * @param mixed  $value      The value to potentially decrypt.
     * @param string $field_key  The field key (for logging/audit purposes).
     * @param int    $object_id  The object ID (e.g., appointment post ID) for audit logging.
     * @return mixed The decrypted value or original value.
     */
    public function maybe_decrypt( $value, string $field_key = '', int $object_id = 0 ) {
        // Handle empty values.
        if ( empty( $value ) && $value !== '0' ) {
            return $value;
        }

        // Only process strings.
        if ( ! is_string( $value ) ) {
            return $value;
        }

        // Check if encrypted.
        if ( ! $this->is_encrypted( $value ) ) {
            return $value;
        }

        // Decrypt.
        $decrypted = $this->decrypt( $value );

        if ( false === $decrypted ) {
            Debug_Logger::log_critical( "Failed to decrypt field: {$field_key}", 'Encryption' );
            return '';
        }

        // Log HIPAA audit event for PHI access (deduplicated per request).
        $this->maybe_log_decrypt_audit( $field_key, $object_id );

        // Check if the decrypted value was a serialized array.
        $unserialized = maybe_unserialize( $decrypted );

        return $unserialized;
    }

    /**
     * Log a decrypt audit event if HIPAA mode is active.
     *
     * Deduplicates by user_id:object_id:field_key to avoid flooding the
     * audit log when the same field is accessed multiple times in one request.
     *
     * @param string $field_key The field key that was decrypted.
     * @param int    $object_id The object ID (e.g., appointment post ID).
     */
    private function maybe_log_decrypt_audit( string $field_key, int $object_id ): void {
        if ( ! function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_operational' ) ) {
            return;
        }

        if ( ! \DependentMedia\ClientSync\Services\clisyc_is_hipaa_operational() ) {
            return;
        }

        if ( ! function_exists( '\DependentMedia\ClientSync\Services\clisyc_audit_log' ) ) {
            return;
        }

        $user_id   = get_current_user_id();
        $dedup_key = "{$user_id}:{$object_id}:{$field_key}";

        if ( isset( self::$logged_this_request[ $dedup_key ] ) ) {
            return;
        }

        self::$logged_this_request[ $dedup_key ] = true;

        \DependentMedia\ClientSync\Services\clisyc_audit_log(
            'decrypt',
            'appointment',
            $object_id,
            [
                'field'   => $field_key,
                'context' => 'phi_access',
            ]
        );
    }

    /**
     * Check if a field should be encrypted based on its key.
     *
     * This can be extended to add more sensitive field patterns.
     *
     * @param string $field_key The meta key of the field.
     * @return bool True if the field should be encrypted.
     */
    public function should_encrypt_field( string $field_key ): bool {
        // List of field patterns that should always be encrypted
        $sensitive_patterns = [
            Constants::META_NOTES,     // Appointment notes
            '_clisyc_medical_',        // Any medical-prefixed fields
            '_clisyc_health_',         // Health information fields
            '_clisyc_diagnosis_',      // Diagnosis fields
            '_clisyc_treatment_',      // Treatment fields
            '_clisyc_medication_',     // Medication fields
            '_clisyc_ssn',             // Social Security Number
            '_clisyc_insurance_',      // Insurance information
            '_clisyc_phi_',            // Explicitly marked PHI fields
        ];

        foreach ( $sensitive_patterns as $pattern ) {
            if ( str_starts_with( $field_key, $pattern ) ) {
                return true;
            }
        }

        // Check custom fields marked as sensitive
        $custom_fields = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
        if ( isset( $custom_fields[ $field_key ] ) ) {
            // If field has 'sensitive' or 'encrypt' flag, encrypt it
            if ( ! empty( $custom_fields[ $field_key ]['sensitive'] ) || 
                 ! empty( $custom_fields[ $field_key ]['encrypt'] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bulk encrypt multiple fields.
     *
     * @param array $fields Array of field_key => value pairs.
     * @return array Array of field_key => encrypted_value pairs.
     */
    public function encrypt_fields( array $fields ): array {
        $result = [];
        foreach ( $fields as $key => $value ) {
            if ( $this->should_encrypt_field( $key ) ) {
                $result[ $key ] = $this->maybe_encrypt( $value, $key );
            } else {
                $result[ $key ] = $value;
            }
        }
        return $result;
    }

    /**
     * Bulk decrypt multiple fields.
     *
     * @param array $fields Array of field_key => value pairs.
     * @return array Array of field_key => decrypted_value pairs.
     */
    public function decrypt_fields( array $fields ): array {
        $result = [];
        foreach ( $fields as $key => $value ) {
            $result[ $key ] = $this->maybe_decrypt( $value, $key );
        }
        return $result;
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Generate a secure random encryption key.
     *
     * Helper method for administrators to generate a strong key.
     *
     * @param int $length The desired key length (default: 64 characters).
     * @return string A cryptographically secure random key.
     */
    public static function generate_key( int $length = 64 ): string {
        return bin2hex( random_bytes( $length / 2 ) );
    }

    /**
     * Test encryption/decryption functionality.
     *
     * @return array{success: bool, message: string, details: array}
     */
    public function test(): array {
        $details = [
            'openssl_available' => function_exists( 'openssl_encrypt' ),
            'gcm_supported'     => in_array( self::CIPHER_ALGO_GCM, openssl_get_cipher_methods(), true ),
            'cbc_supported'     => in_array( self::CIPHER_ALGO, openssl_get_cipher_methods(), true ),
            'key_configured'    => ! empty( $this->derived_key ),
            'key_v1_available'  => ! empty( $this->derived_key_v1 ),
            'encryption_version' => self::ENCRYPTION_VERSION_2,
            'hipaa_mode'        => function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' )
                                  ? \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode()
                                  : false,
        ];

        if ( ! $this->is_available() ) {
            return [
                'success' => false,
                'message' => __( 'Encryption is not available. Check OpenSSL and encryption key configuration.', 'client-sync' ),
                'details' => $details,
            ];
        }

        // Test v2 (GCM) encryption/decryption.
        $test_value = 'ClientSync Encryption Test ' . wp_generate_password( 20 );
        $encrypted  = $this->encrypt( $test_value );

        if ( false === $encrypted ) {
            return [
                'success' => false,
                'message' => __( 'Encryption failed.', 'client-sync' ),
                'details' => $details,
            ];
        }

        $decrypted = $this->decrypt( $encrypted );

        if ( $decrypted !== $test_value ) {
            return [
                'success' => false,
                'message' => __( 'Decryption produced incorrect result.', 'client-sync' ),
                'details' => $details,
            ];
        }

        $details['encryption_test']  = 'passed';
        $details['encrypted_length'] = strlen( $encrypted );
        $details['original_length']  = strlen( $test_value );
        $details['format_version']   = self::ENCRYPTION_VERSION_2;

        return [
            'success' => true,
            'message' => __( 'Encryption is working correctly (AES-256-GCM).', 'client-sync' ),
            'details' => $details,
        ];
    }
}

// =============================================================================
// GLOBAL HELPER FUNCTIONS
// =============================================================================

/**
 * Get the encryption service instance.
 *
 * @since 3.3.0
 * @return Encryption_Service
 */
function clisyc_encryption(): Encryption_Service {
    return Encryption_Service::get_instance();
}

/**
 * Encrypt a value if HIPAA mode is enabled.
 *
 * Convenience function for use throughout the plugin.
 *
 * @since 3.3.0
 * @param mixed  $value     The value to potentially encrypt.
 * @param string $field_key Optional field key for context.
 * @return mixed
 */
function clisyc_maybe_encrypt( $value, string $field_key = '' ) {
    return Encryption_Service::get_instance()->maybe_encrypt( $value, $field_key );
}

/**
 * Decrypt a value if it's encrypted.
 *
 * Convenience function for use throughout the plugin.
 *
 * @since 3.3.0
 * @param mixed  $value     The value to potentially decrypt.
 * @param string $field_key Optional field key for context.
 * @param int    $object_id Optional object ID for HIPAA audit logging.
 * @return mixed
 */
function clisyc_maybe_decrypt( $value, string $field_key = '', int $object_id = 0 ) {
    return Encryption_Service::get_instance()->maybe_decrypt( $value, $field_key, $object_id );
}