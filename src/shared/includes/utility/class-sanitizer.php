<?php
/**
 * File: src/shared/includes/utility/class-sanitizer.php -> client-sync/includes/utility/class-sanitizer.php
 * A collection of static sanitization methods used throughout the plugin.
 *
 * UPDATED: Added encryption-aware sanitization for HIPAA compliance.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Utility
 */

namespace DependentMedia\ClientSync\Utility;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sanitizer {

    /**
     * Sanitize a custom field value based on its field type.
     *
     * @param mixed  $raw_value        The raw value from user input.
     * @param array  $field_definition The field definition array.
     * @param string $field_key        The field meta key.
     * @return mixed The sanitized value.
     */
    public static function sanitize_custom_field_value( $raw_value, array $field_definition, string $field_key ) {
        $sanitized_value     = null;
        $raw_value_unslashed = is_string( $raw_value ) ? wp_unslash( $raw_value ) : $raw_value;
        $field_type          = $field_definition['type'] ?? 'text';

        switch ( $field_type ) {
            case 'number':
                if ( $raw_value_unslashed === '' || is_null($raw_value_unslashed) ) {
                    $sanitized_value = '';
                } elseif ( is_numeric( $raw_value_unslashed ) ) {
                     // Allow decimals
                    $sanitized_value = filter_var($raw_value_unslashed, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                } else {
                    $sanitized_value = '';
                }
                break;
            case 'textarea':
                $sanitized_value = sanitize_textarea_field( $raw_value_unslashed );
                break;
            case 'select':
            case 'radio':
            case 'text':
                $sanitized_value = sanitize_text_field( $raw_value_unslashed );
                break;
            case 'phone':
                $sanitized_value = sanitize_text_field( preg_replace( '/[^\d\+() -]/', '', $raw_value_unslashed ) );
                break;
            case 'multi_checkbox':
                $sanitized_value = is_array( $raw_value_unslashed ) ? array_map( 'sanitize_text_field', $raw_value_unslashed ) : [];
                break;
            case 'image_map':
                if ( is_null( $raw_value_unslashed ) || $raw_value_unslashed === '' ) {
                    $sanitized_value = '[]';
                } elseif ( ! is_string( $raw_value_unslashed ) ) {
                    $sanitized_value = '[]';
                } else {
                    $decoded_coords = json_decode( $raw_value_unslashed, true );
                    $validated_coords = [];
                    if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_coords ) ) {
                        foreach ( $decoded_coords as $coord ) {
                            if ( is_array( $coord ) && isset( $coord['x'], $coord['y'] ) && is_numeric( $coord['x'] ) && is_numeric( $coord['y'] ) ) {
                                $validated_coords[] = [
                                    'x' => max( 0, min( 1, floatval( $coord['x'] ) ) ),
                                    'y' => max( 0, min( 1, floatval( $coord['y'] ) ) ),
                                ];
                            }
                        }
                    }
                    $sanitized_value = wp_json_encode( $validated_coords );
                }
                break;
            default:
                $sanitized_value = sanitize_text_field( (string) $raw_value_unslashed );
                break;
        }
        return $sanitized_value;
    }

    /**
     * Sanitize AND optionally encrypt a custom field value for HIPAA compliance.
     *
     * This method combines sanitization with encryption for sensitive fields.
     * Use this when saving data that may contain PHI.
     *
     * @since 3.3.0
     * @param mixed  $raw_value        The raw value from user input.
     * @param array  $field_definition The field definition array.
     * @param string $field_key        The field meta key.
     * @param bool   $force_encrypt    Force encryption regardless of field settings.
     * @return mixed The sanitized (and possibly encrypted) value.
     */
    public static function sanitize_and_encrypt_custom_field_value( $raw_value, array $field_definition, string $field_key, bool $force_encrypt = false ) {
        // First, sanitize the value
        $sanitized_value = self::sanitize_custom_field_value( $raw_value, $field_definition, $field_key );
        
        // Check if encryption is available and HIPAA mode is active
        if ( ! class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
            return $sanitized_value;
        }
        
        if ( ! function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_operational' ) ) {
            return $sanitized_value;
        }
        
        if ( ! \DependentMedia\ClientSync\Services\clisyc_is_hipaa_operational() ) {
            return $sanitized_value;
        }
        
        // Check if this field should be encrypted
        $encryption = \DependentMedia\ClientSync\Services\Encryption_Service::get_instance();
        
        $should_encrypt = $force_encrypt 
            || $encryption->should_encrypt_field( $field_key )
            || ! empty( $field_definition['sensitive'] )
            || ! empty( $field_definition['encrypt'] );
        
        if ( $should_encrypt && ! empty( $sanitized_value ) ) {
            $sanitized_value = $encryption->maybe_encrypt( $sanitized_value, $field_key );
        }
        
        return $sanitized_value;
    }

    /**
     * Decrypt a custom field value if it's encrypted.
     *
     * Use this when retrieving data that may have been encrypted.
     *
     * @since 3.3.0
     * @param mixed  $value     The value from the database.
     * @param string $field_key The field meta key (for logging).
     * @return mixed The decrypted value (or original if not encrypted).
     */
    public static function maybe_decrypt_field_value( $value, string $field_key = '' ) {
        if ( empty( $value ) || ! is_string( $value ) ) {
            return $value;
        }
        
        if ( ! class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
            return $value;
        }
        
        $encryption = \DependentMedia\ClientSync\Services\Encryption_Service::get_instance();
        return $encryption->maybe_decrypt( $value, $field_key );
    }

    /**
     * Check if a value is encrypted.
     *
     * @since 3.3.0
     * @param mixed $value The value to check.
     * @return bool True if the value appears to be encrypted.
     */
    public static function is_encrypted( $value ): bool {
        if ( ! is_string( $value ) ) {
            return false;
        }
        
        if ( ! class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
            return false;
        }
        
        $encryption = \DependentMedia\ClientSync\Services\Encryption_Service::get_instance();
        return $encryption->is_encrypted( $value );
    }

    public static function sanitize_time_format( $input ): string {
        $input_str = (string) $input;
    
        if ( '24:00' === trim($input_str) ) {
            return '24:00';
        }

        if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $input_str ) ) {
            return $input_str;
        }
        
        return '';
    }

    public static function sanitize_timespan_format( $input ): string {
        if ( preg_match( '/^([0-9]{1,2}):([0-5]\d):([0-5]\d)$/', (string) $input, $matches ) ) {
            $hours = str_pad( $matches[1], 2, '0', STR_PAD_LEFT );
            if ( (int) $hours >= 0 && (int) $hours <= 99 ) {
                return $hours . ':' . $matches[2] . ':' . $matches[3];
            }
        }
        return '00:15:00';
    }

    public static function sanitize_string_array( $input ): array {
        if ( ! is_array( $input ) ) return [];
        return array_map( 'sanitize_key', $input );
    }

    public static function sanitize_integer_array( $input ): array {
        if ( ! is_array( $input ) ) return [];
        return array_map( 'absint', $input );
    }

    public static function sanitize_day_of_week_array( $input ): array {
        $output = [];
        if ( is_array( $input ) ) {
            foreach ( $input as $value ) {
                $day = intval( $value );
                if ( $day >= 0 && $day <= 6 ) {
                    $output[] = $day;
                }
            }
        }
        return array_values( array_unique( $output ) );
    }
}