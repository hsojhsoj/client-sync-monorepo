<?php
/**
 * File: tests/unit/NotificationsIntegrationTest.php
 * Integration tests for the Notifications system.
 *
 * Tests placeholder preparation, HIPAA anonymization, recipient determination,
 * and default template completeness.
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Core\Notifications;
use DependentMedia\ClientSync\Constants;

class NotificationsIntegrationTest extends WP_UnitTestCase {

	/** @var Notifications */
	private $notifications;

	/** @var int */
	private $client_id;

	/** @var int */
	private $appointment_id;

	public function setUp(): void {
		parent::setUp();

		$this->notifications = new Notifications();

		// Register post types.
		if ( ! post_type_exists( Constants::POST_TYPE_APPOINTMENT ) ) {
			register_post_type( Constants::POST_TYPE_APPOINTMENT, [ 'public' => false ] );
		}

		// Create a client user with known data.
		$this->client_id = $this->factory()->user->create( [
			'user_login'   => 'testclient',
			'user_email'   => 'testclient@example.com',
			'display_name' => 'Test Client',
			'first_name'   => 'TestFirst',
			'last_name'    => 'TestLast',
			'role'         => 'subscriber',
		] );
		update_user_meta( $this->client_id, 'first_name', 'TestFirst' );
		update_user_meta( $this->client_id, 'last_name', 'TestLast' );
		update_user_meta( $this->client_id, 'phone_number', '+15551234567' );

		// Create a test appointment authored by the client.
		$this->appointment_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => 'publish',
			'post_author' => $this->client_id,
			'post_title'  => 'Test Appointment',
		] );
		update_post_meta( $this->appointment_id, Constants::META_TIME_SLOT, '2026-08-15 14:00:00' );
		update_post_meta( $this->appointment_id, Constants::META_APPOINTMENT_DATE, '2026-08-15' );
		update_post_meta( $this->appointment_id, Constants::META_SLOT_DIMENSIONS, [ 'clisyc_service' => 1 ] );
		update_post_meta( $this->appointment_id, Constants::META_BOOKING_MODE, 'slot' );

		// Set admin notification recipients.
		update_option( Constants::OPTION_NOTIFICATION_ADMINS, 'admin@testsite.com' );
	}

	public function tearDown(): void {
		delete_option( Constants::OPTION_NOTIFICATION_ADMINS );
		delete_option( Constants::OPTION_HIPAA_MODE );
		delete_option( Constants::OPTION_ANONYMIZE_EXTERNAL_SYNC );
		delete_option( Constants::OPTION_NOTIFICATION_SETTINGS );

		// Clear HIPAA cache if the helper exists.
		if ( class_exists( '\DependentMedia\ClientSync\Services\HIPAA_Helper' ) ) {
			\DependentMedia\ClientSync\Services\HIPAA_Helper::get_instance()->clear_cache();
		}

		parent::tearDown();
	}

	// ── Helper ────────────────────────────────────────────────────────

	/**
	 * Invoke a private method on the Notifications instance.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments to pass.
	 * @return mixed
	 */
	private function invoke_private( string $method, array $args = [] ) {
		$ref = new \ReflectionMethod( Notifications::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->notifications, $args );
	}

	// ── Event Types ──────────────────────────────────────────────────

	/**
	 * @test
	 * get_event_types should return all expected notification events.
	 */
	public function it_returns_all_registered_event_types() {
		$events = $this->notifications->get_event_types();

		$this->assertIsArray( $events );

		$expected_events = [
			'new_appointment_client',
			'new_appointment_admin',
			'appointment_updated_admin',
			'appointment_cancelled_admin',
			'appointment_cancelled_by_client',
			'new_client_registration_client',
			'new_client_registration_admin',
			'payment_successful_client',
			'payment_successful_admin',
			'scheduled_payment_failure_client',
			'scheduled_payment_failure_admin',
			'appointment_reminder',
			'waitlist_joined',
			'waitlist_promoted',
		];

		foreach ( $expected_events as $event_key ) {
			$this->assertArrayHasKey( $event_key, $events, "Event '{$event_key}' should be registered." );
			$this->assertArrayHasKey( 'label', $events[ $event_key ] );
			$this->assertArrayHasKey( 'recipient_types', $events[ $event_key ] );
			$this->assertArrayHasKey( 'placeholders', $events[ $event_key ] );
			$this->assertNotEmpty( $events[ $event_key ]['recipient_types'] );
		}
	}

	// ── Placeholder Preparation ──────────────────────────────────────

	/**
	 * @test
	 * prepare_placeholder_data should populate appointment and client data.
	 */
	public function it_prepares_placeholder_data_for_appointment() {
		$data = $this->invoke_private( 'prepare_placeholder_data', [
			'new_appointment_client',
			$this->appointment_id,
			[],
		] );

		$this->assertIsArray( $data );

		// Client data should be populated from the appointment author.
		$this->assertSame( 'TestFirst', $data['{client_name}'] );
		$this->assertSame( 'testclient@example.com', $data['{client_email}'] );
		$this->assertSame( 'testclient', $data['{client_username}'] );
		$this->assertSame( 'TestFirst', $data['{client_first_name}'] );
		$this->assertSame( 'TestLast', $data['{client_last_name}'] );
		$this->assertSame( '+15551234567', $data['{phone_number}'] );

		// Appointment data should be populated.
		$this->assertEquals( $this->appointment_id, $data['{appointment_id}'] );
		$this->assertSame( '2026-08-15', $data['{appointment_date}'] );
		$this->assertNotEmpty( $data['{appointment_date_formatted}'] );

		// Site data.
		$this->assertNotEmpty( $data['{site_name}'] );
		$this->assertNotEmpty( $data['{site_url}'] );
	}

	/**
	 * @test
	 * prepare_placeholder_data should populate client data for registration events.
	 */
	public function it_prepares_placeholder_data_for_client_registration() {
		$data = $this->invoke_private( 'prepare_placeholder_data', [
			'new_client_registration_client',
			$this->client_id,
			[],
		] );

		$this->assertIsArray( $data );

		$this->assertSame( 'TestFirst', $data['{client_name}'] );
		$this->assertSame( 'testclient@example.com', $data['{client_email}'] );
		$this->assertSame( 'testclient', $data['{client_username}'] );
	}

	// ── HIPAA Anonymization ──────────────────────────────────────────

	/**
	 * @test
	 * PHI placeholders should be anonymized when HIPAA mode is active.
	 */
	public function it_anonymizes_phi_when_hipaa_enabled() {
		// Skip if HIPAA helper function doesn't exist.
		if ( ! function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) ) {
			$this->markTestSkipped( 'HIPAA helper function not available.' );
		}

		// Enable HIPAA mode.
		update_option( Constants::OPTION_HIPAA_MODE, true );
		update_option( Constants::OPTION_ANONYMIZE_EXTERNAL_SYNC, true );

		// Clear HIPAA cache so new options take effect.
		\DependentMedia\ClientSync\Services\HIPAA_Helper::get_instance()->clear_cache();

		$placeholder_data = [
			'{client_name}'       => 'John Doe',
			'{client_first_name}' => 'John',
			'{client_last_name}'  => 'Doe',
			'{client_username}'   => 'johndoe',
			'{phone_number}'      => '+15551234567',
			'{appointment_notes}' => 'Sensitive notes here',
			'{site_name}'         => 'Test Site',
		];

		$result = $this->invoke_private( 'maybe_anonymize_placeholders_for_hipaa', [
			$placeholder_data,
			'new_appointment_client',
		] );

		$this->assertSame( 'Valued Client', $result['{client_name}'] );
		$this->assertSame( 'Client', $result['{client_first_name}'] );
		$this->assertSame( '', $result['{client_last_name}'] );
		$this->assertSame( '[Protected]', $result['{client_username}'] );
		$this->assertSame( '[Login to view]', $result['{phone_number}'] );
		$this->assertSame( '[Protected Content - Login to view]', $result['{appointment_notes}'] );
		$this->assertTrue( $result['_hipaa_anonymized'] );

		// Site name should NOT be anonymized.
		$this->assertSame( 'Test Site', $result['{site_name}'] );
	}

	/**
	 * @test
	 * PHI placeholders should be preserved when HIPAA mode is disabled.
	 */
	public function it_preserves_data_when_hipaa_disabled() {
		delete_option( Constants::OPTION_HIPAA_MODE );
		delete_option( Constants::OPTION_ANONYMIZE_EXTERNAL_SYNC );

		// Clear cache.
		if ( class_exists( '\DependentMedia\ClientSync\Services\HIPAA_Helper' ) ) {
			\DependentMedia\ClientSync\Services\HIPAA_Helper::get_instance()->clear_cache();
		}

		$placeholder_data = [
			'{client_name}'       => 'John Doe',
			'{client_first_name}' => 'John',
			'{phone_number}'      => '+15551234567',
			'{site_name}'         => 'Test Site',
		];

		$result = $this->invoke_private( 'maybe_anonymize_placeholders_for_hipaa', [
			$placeholder_data,
			'new_appointment_client',
		] );

		$this->assertSame( 'John Doe', $result['{client_name}'] );
		$this->assertSame( 'John', $result['{client_first_name}'] );
		$this->assertSame( '+15551234567', $result['{phone_number}'] );
		$this->assertArrayNotHasKey( '_hipaa_anonymized', $result );
	}

	// ── Recipient Determination ──────────────────────────────────────

	/**
	 * @test
	 * Admin-only events should return admin recipients.
	 */
	public function it_determines_admin_recipients() {
		$recipients = $this->invoke_private( 'get_recipients_for_event', [
			'new_client_registration_admin',
			$this->client_id,
			[],
		] );

		$this->assertNotEmpty( $recipients );

		$admin_found = false;
		foreach ( $recipients as $recipient ) {
			if ( 'admin' === $recipient['type'] ) {
				$admin_found = true;
				$this->assertSame( 'admin@testsite.com', $recipient['email'] );
			}
		}
		$this->assertTrue( $admin_found, 'Admin recipient should be present.' );
	}

	/**
	 * @test
	 * Client events should return the client's email as a recipient.
	 */
	public function it_determines_client_recipients() {
		$recipients = $this->invoke_private( 'get_recipients_for_event', [
			'new_client_registration_client',
			$this->client_id,
			[],
		] );

		$client_found = false;
		foreach ( $recipients as $recipient ) {
			if ( 'client' === $recipient['type'] ) {
				$client_found = true;
				$this->assertSame( 'testclient@example.com', $recipient['email'] );
			}
		}
		$this->assertTrue( $client_found, 'Client recipient should be present.' );
	}

	/**
	 * @test
	 * Events with both admin and client recipient types should return both.
	 */
	public function it_returns_both_admin_and_client_recipients() {
		$recipients = $this->invoke_private( 'get_recipients_for_event', [
			'new_appointment_client',
			$this->appointment_id,
			[],
		] );

		$has_admin  = false;
		$has_client = false;
		foreach ( $recipients as $recipient ) {
			if ( 'admin' === $recipient['type'] ) {
				$has_admin = true;
			}
			if ( 'client' === $recipient['type'] ) {
				$has_client = true;
			}
		}
		$this->assertTrue( $has_admin, 'Admin recipient should be present for new_appointment_client.' );
		$this->assertTrue( $has_client, 'Client recipient should be present for new_appointment_client.' );
	}

	// ── Default Templates ────────────────────────────────────────────

	/**
	 * @test
	 * Every event should have non-empty default subject and body templates.
	 */
	public function it_returns_default_templates_for_all_events() {
		$events = $this->notifications->get_event_types();

		foreach ( $events as $event_key => $event_data ) {
			$defaults = $this->invoke_private( 'get_default_templates_for_event', [ $event_key ] );

			$this->assertIsArray( $defaults, "Event '{$event_key}' should return an array of defaults." );

			foreach ( $event_data['recipient_types'] as $type ) {
				$this->assertArrayHasKey(
					$type . '_subject',
					$defaults,
					"Event '{$event_key}' should have a {$type}_subject default."
				);
				$this->assertNotEmpty(
					$defaults[ $type . '_subject' ],
					"Event '{$event_key}' {$type}_subject should not be empty."
				);
				$this->assertArrayHasKey(
					$type . '_body',
					$defaults,
					"Event '{$event_key}' should have a {$type}_body default."
				);
				$this->assertNotEmpty(
					$defaults[ $type . '_body' ],
					"Event '{$event_key}' {$type}_body should not be empty."
				);
			}
		}
	}

	/**
	 * @test
	 * Client-facing events should include SMS defaults; admin-only events should not.
	 */
	public function it_includes_sms_defaults_for_client_events() {
		$events = $this->notifications->get_event_types();

		foreach ( $events as $event_key => $event_data ) {
			$defaults = $this->invoke_private( 'get_default_templates_for_event', [ $event_key ] );

			if ( in_array( 'client', $event_data['recipient_types'], true ) ) {
				// Client-facing events should have SMS fields.
				$this->assertArrayHasKey(
					'sms_enabled',
					$defaults,
					"Client event '{$event_key}' should have sms_enabled."
				);
				$this->assertArrayHasKey(
					'sms_body',
					$defaults,
					"Client event '{$event_key}' should have sms_body."
				);
			} else {
				// Admin-only events should NOT have SMS fields.
				$this->assertArrayNotHasKey(
					'sms_body',
					$defaults,
					"Admin-only event '{$event_key}' should not have sms_body."
				);
			}
		}
	}
}
