<?php
/**
 * File: src/pro/includes/utilities/class-phone-normalizer.php
 * Utility class for normalizing and validating phone numbers to E.164 format.
 *
 * Twilio requires phone numbers in E.164 format (+[country code][number]).
 * This class handles converting common user input formats into E.164.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Utilities
 */

namespace ClientSyncPro\Utilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Phone_Normalizer {

	/**
	 * Normalize a phone number to E.164 format.
	 *
	 * Handles common US/Canada formats by default. International numbers
	 * must include a leading '+' to be recognized correctly.
	 *
	 * Examples:
	 *   '(555) 123-4567'   => '+15551234567'
	 *   '555-123-4567'     => '+15551234567'
	 *   '15551234567'      => '+15551234567'
	 *   '+44 20 7946 0958' => '+442079460958'
	 *   ''                 => ''
	 *
	 * @param string $phone                Raw phone number input.
	 * @param string $default_country_code Default country code (digits only, e.g., '1' for US/Canada).
	 * @return string E.164 formatted number, or empty string if input is unusable.
	 */
	public static function to_e164( string $phone, string $default_country_code = '1' ): string {
		$phone = trim( $phone );
		if ( '' === $phone ) {
			return '';
		}

		// Remember if the original input starts with '+'
		$has_plus = ( strpos( $phone, '+' ) === 0 );

		// Strip all non-digit characters.
		$digits = preg_replace( '/[^0-9]/', '', $phone );

		if ( empty( $digits ) ) {
			return '';
		}

		// If the original input started with '+', treat as full international number.
		if ( $has_plus ) {
			return '+' . $digits;
		}

		// US/Canada: 10-digit number without country code.
		if ( strlen( $digits ) === 10 && $default_country_code === '1' ) {
			return '+1' . $digits;
		}

		// US/Canada: 11-digit number starting with '1' (country code included).
		if ( strlen( $digits ) === 11 && $digits[0] === '1' && $default_country_code === '1' ) {
			return '+' . $digits;
		}

		// Fallback: prepend '+' to whatever digits we have.
		return '+' . $digits;
	}

	/**
	 * Validate that a string is a valid E.164 phone number.
	 *
	 * E.164 format: + followed by 7 to 15 digits, first digit non-zero.
	 *
	 * @param string $phone The phone number to validate.
	 * @return bool True if valid E.164 format, false otherwise.
	 */
	public static function is_valid_e164( string $phone ): bool {
		return (bool) preg_match( '/^\+[1-9]\d{6,14}$/', $phone );
	}
}
