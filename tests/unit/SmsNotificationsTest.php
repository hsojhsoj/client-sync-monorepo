<?php
/**
 * File: tests/unit/SmsNotificationsTest.php
 * Unit tests for SMS Notifications: Phone Normalizer utility and default SMS templates.
 */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;

class SmsNotificationsTest extends WP_UnitTestCase {

	// ─── Phone Normalizer Tests ───────────────────────────────────────

	public function setUp(): void {
		parent::setUp();

		// Ensure the Phone Normalizer class is loaded.
		$normalizer_path = dirname( __DIR__, 2 ) . '/src/pro/includes/utilities/class-phone-normalizer.php';
		if ( file_exists( $normalizer_path ) ) {
			require_once $normalizer_path;
		}

		if ( ! class_exists( '\ClientSyncPro\Utilities\Phone_Normalizer' ) ) {
			$this->markTestSkipped( 'Phone_Normalizer class not available. Skipping SMS tests.' );
		}
	}

	/**
	 * @test
	 * US-format phone numbers with parentheses and dashes should normalize to E.164.
	 */
	public function it_normalizes_us_phone_to_e164() {
		$result = \ClientSyncPro\Utilities\Phone_Normalizer::to_e164( '(555) 123-4567' );
		$this->assertSame( '+15551234567', $result );
	}

	/**
	 * @test
	 * An 11-digit US number starting with 1 should normalize to E.164.
	 */
	public function it_normalizes_11_digit_us_phone() {
		$result = \ClientSyncPro\Utilities\Phone_Normalizer::to_e164( '15551234567' );
		$this->assertSame( '+15551234567', $result );
	}

	/**
	 * @test
	 * International numbers with a leading '+' should preserve the country code.
	 */
	public function it_preserves_international_format() {
		$result = \ClientSyncPro\Utilities\Phone_Normalizer::to_e164( '+44 20 7946 0958' );
		$this->assertSame( '+442079460958', $result );
	}

	/**
	 * @test
	 * Input with no digits should return an empty string.
	 */
	public function it_returns_empty_for_invalid_input() {
		$this->assertSame( '', \ClientSyncPro\Utilities\Phone_Normalizer::to_e164( 'abc' ) );
		$this->assertSame( '', \ClientSyncPro\Utilities\Phone_Normalizer::to_e164( '' ) );
		$this->assertSame( '', \ClientSyncPro\Utilities\Phone_Normalizer::to_e164( '   ' ) );
	}

	/**
	 * @test
	 * E.164 validation should accept valid numbers and reject invalid ones.
	 */
	public function it_validates_e164_format() {
		$this->assertTrue( \ClientSyncPro\Utilities\Phone_Normalizer::is_valid_e164( '+15551234567' ) );
		$this->assertTrue( \ClientSyncPro\Utilities\Phone_Normalizer::is_valid_e164( '+442079460958' ) );

		// Invalid: no leading plus.
		$this->assertFalse( \ClientSyncPro\Utilities\Phone_Normalizer::is_valid_e164( '5551234567' ) );
		// Invalid: starts with 0 after +.
		$this->assertFalse( \ClientSyncPro\Utilities\Phone_Normalizer::is_valid_e164( '+05551234567' ) );
		// Invalid: too short.
		$this->assertFalse( \ClientSyncPro\Utilities\Phone_Normalizer::is_valid_e164( '+123' ) );
		// Invalid: empty.
		$this->assertFalse( \ClientSyncPro\Utilities\Phone_Normalizer::is_valid_e164( '' ) );
	}

	/**
	 * @test
	 * Dashes-only US phone numbers should normalize correctly.
	 */
	public function it_normalizes_dash_separated_us_phone() {
		$result = \ClientSyncPro\Utilities\Phone_Normalizer::to_e164( '555-123-4567' );
		$this->assertSame( '+15551234567', $result );
	}

	// ─── Default SMS Template Tests ───────────────────────────────────

	/**
	 * @test
	 * Client-facing events should all have non-empty default SMS body templates.
	 */
	public function it_provides_default_sms_templates_for_client_events() {
		$notifications = new \DependentMedia\ClientSync\Core\Notifications();

		$client_events = [
			'new_appointment_client',
			'appointment_cancelled_admin',
			'appointment_cancelled_by_client',
			'appointment_reminder',
			'payment_successful_client',
			'waitlist_joined',
			'waitlist_promoted',
		];

		foreach ( $client_events as $event ) {
			$template = $notifications->get_default_sms_body( $event );
			$this->assertNotEmpty(
				$template,
				"Event '{$event}' should have a non-empty default SMS body template."
			);
			// Verify templates are SMS-appropriate length (under 200 chars before placeholder expansion).
			$this->assertLessThan(
				200,
				strlen( $template ),
				"SMS template for '{$event}' should be under 200 chars (got " . strlen( $template ) . ")."
			);
		}
	}

	/**
	 * @test
	 * Admin-only events that have no client recipient should return an empty default SMS body.
	 */
	public function it_returns_empty_sms_template_for_admin_only_events() {
		$notifications = new \DependentMedia\ClientSync\Core\Notifications();

		$admin_only_events = [
			'new_client_registration_admin',
			'payment_successful_admin',
			'scheduled_payment_failure_admin',
		];

		foreach ( $admin_only_events as $event ) {
			$template = $notifications->get_default_sms_body( $event );
			$this->assertEmpty(
				$template,
				"Admin-only event '{$event}' should return an empty SMS template."
			);
		}
	}
}
