<?php
/**
 * File: src/shared/includes/utility/class-debug-logger.php
 * Centralized debug logging utility for Client Sync.
 *
 * Replaces the repetitive pattern:
 *   if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { error_log( '[CLISYC] ...' ); }
 *
 * Usage:
 *   Debug_Logger::log( 'Slot reserved for appointment 42', 'Booking' );
 *   Debug_Logger::log_data( 'Dimensions received', $dimensions, 'Slots' );
 *
 * @package    ClientSync
 * @subpackage ClientSync/Utility
 */

namespace DependentMedia\ClientSync\Utility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Debug_Logger {

	/**
	 * PII keys that should be redacted in request logs.
	 *
	 * @var array
	 */
	private static $pii_keys = [
		'email', 'guest_email', 'user_email', 'clisyc_email', 'clisyc_reg_email',
		'phone', 'phone_number', 'clisyc_phone_number',
		'name', 'guest_name', 'first_name', 'last_name', 'display_name',
		'clisyc_first_name', 'clisyc_last_name', 'clisyc_reg_first_name', 'clisyc_reg_last_name',
		'address', 'street', 'city', 'zip', 'postal_code',
		'password', 'clisyc_reg_password', 'user_pass',
		'card', 'card_number', 'cvv', 'cvc', 'expiry',
		'ssn', 'social_security', 'insurance_id',
	];

	/**
	 * Log a message when WP_DEBUG is enabled.
	 *
	 * @param string $message The message to log.
	 * @param string $module  Optional module prefix (e.g. 'Booking', 'Cron', 'Google Sync').
	 *                        Defaults to 'CLISYC'.
	 */
	public static function log( string $message, string $module = 'CLISYC' ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Conditional debug logging.
		error_log( '[Client Sync ' . $module . '] ' . $message );
	}

	/**
	 * Log a message along with structured data when WP_DEBUG is enabled.
	 *
	 * @param string $message The message to log.
	 * @param mixed  $data    The data to dump (will be JSON-encoded).
	 * @param string $module  Optional module prefix. Defaults to 'CLISYC'.
	 */
	public static function log_data( string $message, $data, string $module = 'CLISYC' ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( false === $encoded ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Fallback for non-JSON-encodable data.
			$encoded = print_r( $data, true );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Conditional debug logging.
		error_log( '[Client Sync ' . $module . '] ' . $message . ': ' . $encoded );
	}

	/**
	 * Log request/form data with PII automatically redacted.
	 *
	 * Replaces the dangerous pattern of `print_r( $_POST )` in debug logs.
	 * Outputs a safe summary showing which keys were present, with sensitive
	 * values replaced by [REDACTED].
	 *
	 * @param array  $data   The request data (e.g. wp_unslash( $_POST )).
	 * @param string $module Optional module prefix. Defaults to 'CLISYC'.
	 */
	public static function log_request( array $data, string $module = 'CLISYC' ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$safe = [];
		foreach ( $data as $key => $value ) {
			if ( self::_is_pii_key( $key ) ) {
				$safe[ $key ] = '[REDACTED]';
			} elseif ( is_array( $value ) ) {
				$safe[ $key ] = '[array:' . count( $value ) . ']';
			} elseif ( is_string( $value ) && strlen( $value ) > 100 ) {
				$safe[ $key ] = substr( $value, 0, 50 ) . '...[truncated]';
			} else {
				$safe[ $key ] = $value;
			}
		}

		$encoded = wp_json_encode( $safe, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Conditional debug logging with PII redaction.
		error_log( '[Client Sync ' . $module . '] Request data: ' . $encoded );
	}

	/**
	 * Log a critical message regardless of WP_DEBUG.
	 *
	 * Use for production-critical errors (encryption failures, data integrity
	 * issues) that must always be logged for monitoring and incident response.
	 *
	 * @param string $message The critical message to log.
	 * @param string $module  Optional module prefix. Defaults to 'CLISYC'.
	 */
	public static function log_critical( string $message, string $module = 'CLISYC' ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Critical error logging, always active.
		error_log( '[Client Sync ' . $module . '] [CRITICAL] ' . $message );
	}

	/**
	 * Check if a key is a known PII field that should be redacted.
	 *
	 * @param string $key The key to check.
	 * @return bool True if the key contains PII.
	 */
	private static function _is_pii_key( string $key ): bool {
		$lower_key = strtolower( $key );

		// Direct match.
		if ( in_array( $lower_key, self::$pii_keys, true ) ) {
			return true;
		}

		// Partial match for custom fields that may contain PII.
		$pii_fragments = [ 'email', 'phone', 'password', 'pass', 'ssn', 'card', 'name' ];
		foreach ( $pii_fragments as $fragment ) {
			if ( str_contains( $lower_key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}
