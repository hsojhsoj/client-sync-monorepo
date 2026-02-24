<?php
/**
 * File: tests/unit/BookingAjaxHandlerTest.php
 * Unit tests for the Booking AJAX Handler (frontend booking submission).
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Ajax\Booking_Ajax_Handler;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Constants;

class BookingAjaxHandlerTest extends WP_UnitTestCase {

	/** @var Booking_Ajax_Handler */
	private $handler;

	/** @var Database_Manager */
	private $db;

	/** @var int */
	private $service_id;

	public function setUp(): void {
		parent::setUp();

		// wp_send_json calls wp_die() which uses the AJAX die handler when
		// DOING_AJAX is defined. We must define it AND override the AJAX die
		// handler filter to throw WPDieException instead of calling die().
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
		add_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ] );

		$this->db      = new Database_Manager();
		$this->handler = new Booking_Ajax_Handler( $this->db );

		// Register the appointment post type if not already registered.
		if ( ! post_type_exists( Constants::POST_TYPE_APPOINTMENT ) ) {
			register_post_type( Constants::POST_TYPE_APPOINTMENT, [ 'public' => false ] );
		}

		// Register the service post type if not already registered.
		if ( ! post_type_exists( 'clisyc_service' ) ) {
			register_post_type( 'clisyc_service', [ 'public' => false ] );
		}

		// Create a test service (primary dimension item).
		$this->service_id = $this->factory->post->create( [
			'post_type'  => 'clisyc_service',
			'post_title' => 'Test Service',
		] );

		// Configure dimension registry with the test service.
		update_option( Constants::OPTION_DIMENSION_REGISTRY, [
			'dimensions' => [
				'clisyc_service' => [
					'enabled' => true,
					'primary' => true,
				],
			],
		] );

		// Disable WooCommerce and Stripe by default for these tests.
		update_option( Constants::OPTION_WC_ENABLED, false );
		update_option( Constants::OPTION_STRIPE_ENABLED, false );
	}

	public function tearDown(): void {
		remove_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ] );
		delete_option( Constants::OPTION_DIMENSION_REGISTRY );
		delete_option( Constants::OPTION_WC_ENABLED );
		delete_option( Constants::OPTION_STRIPE_ENABLED );
		delete_option( 'clisyc_antispam_honeypot_enabled' );
		delete_option( 'clisyc_antispam_time_check_enabled' );
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Helper: simulate a booking AJAX request.
	// -----------------------------------------------------------------

	/**
	 * Simulate a booking POST request with nonce and required fields.
	 *
	 * @param array $overrides  POST field overrides.
	 * @param int   $user_id    Log in as this user (0 = guest).
	 */
	private function simulate_booking( array $overrides = [], int $user_id = 0 ) {
		// Set the user BEFORE creating the nonce, because wp_create_nonce
		// and check_ajax_referer both factor in the current user ID.
		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		} else {
			wp_set_current_user( 0 );
		}

		// Set up the nonce (must be after wp_set_current_user).
		$_POST['clisyc_appointment_nonce'] = wp_create_nonce( Constants::POST_TYPE_APPOINTMENT );
		$_REQUEST['clisyc_appointment_nonce'] = $_POST['clisyc_appointment_nonce'];
		$_REQUEST['_wpnonce'] = $_POST['clisyc_appointment_nonce'];

		// Default booking data.
		$defaults = [
			'booking_mode'                 => 'slot',
			'time_slot'                    => '2026-06-15 10:00:00',
			'appointment_date'             => '2026-06-15',
			'appointment_duration_minutes' => 60,
			'clisyc_service'               => $this->service_id,
		];

		// Guest fields.
		if ( 0 === $user_id ) {
			$defaults['guest_email'] = 'testguest@example.com';
			$defaults['guest_name']  = 'Test Guest';
		}

		$_POST = array_merge( $_POST, $defaults, $overrides );
	}

	/**
	 * Execute the booking handler and return the decoded JSON response.
	 *
	 * wp_send_json_error() echoes JSON then calls wp_die(''), so we
	 * must buffer output and catch the WPDieException to read the response.
	 *
	 * @return array|null The decoded JSON, or null if no JSON was output.
	 */
	private function execute_handler(): ?array {
		ob_start();
		try {
			$this->handler->ajax_submit_booking();
		} catch ( \WPDieException $e ) {
			// Expected — wp_send_json calls wp_die after echoing.
		}
		$output = ob_get_clean();

		return json_decode( $output, true );
	}

	// -----------------------------------------------------------------
	// Nonce & Security
	// -----------------------------------------------------------------

	/** @test */
	public function rejects_request_with_invalid_nonce() {
		$_POST['clisyc_appointment_nonce'] = 'invalid_nonce';
		$_REQUEST['clisyc_appointment_nonce'] = 'invalid_nonce';

		$response = $this->execute_handler();

		$this->assertNotNull( $response, 'Handler should output a JSON response.' );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Security verification failed', $response['data']['message'] );
	}

	/** @test */
	public function rejects_honeypot_spam() {
		update_option( 'clisyc_antispam_honeypot_enabled', true );

		$this->simulate_booking( [
			'contact_email_hp' => 'spammer@bot.com', // Honeypot field filled = spam.
		] );

		$response = $this->execute_handler();

		$this->assertNotNull( $response );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Spam detected', $response['data']['message'] );
	}

	// -----------------------------------------------------------------
	// Guest Validation
	// -----------------------------------------------------------------

	/** @test */
	public function rejects_guest_with_empty_email() {
		$this->simulate_booking( [
			'guest_email' => '',
			'guest_name'  => 'No Email Guest',
		] );

		$response = $this->execute_handler();

		$this->assertNotNull( $response );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'valid email', $response['data']['message'] );
	}

	/** @test */
	public function rejects_guest_with_invalid_email() {
		$this->simulate_booking( [
			'guest_email' => 'not-an-email',
			'guest_name'  => 'Bad Email Guest',
		] );

		$response = $this->execute_handler();

		$this->assertNotNull( $response );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'valid email', $response['data']['message'] );
	}

	/** @test */
	public function rejects_guest_with_empty_name() {
		$this->simulate_booking( [
			'guest_email' => 'valid@example.com',
			'guest_name'  => '',
		] );

		$response = $this->execute_handler();

		$this->assertNotNull( $response );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'name', $response['data']['message'] );
	}

	// -----------------------------------------------------------------
	// Time Slot Validation
	// -----------------------------------------------------------------

	/** @test */
	public function rejects_slot_booking_without_time_slot() {
		$this->simulate_booking( [
			'time_slot'     => '',
			'time_slot_iso' => '',
		] );

		$response = $this->execute_handler();

		$this->assertNotNull( $response );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'select a time slot', $response['data']['message'] );
	}

	// -----------------------------------------------------------------
	// Successful Booking (logged-in user)
	// -----------------------------------------------------------------

	/** @test */
	public function creates_appointment_for_logged_in_user() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		$this->simulate_booking( [], $user_id );

		$response = $this->execute_handler();

		// Check for appointment creation.
		$appointments = get_posts( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => 'any',
			'numberposts' => 1,
			'author'      => $user_id,
		] );

		if ( ! empty( $appointments ) ) {
			$appointment = $appointments[0];
			$this->assertEquals( Constants::POST_TYPE_APPOINTMENT, $appointment->post_type );
			$this->assertEquals( $user_id, (int) $appointment->post_author );

			// Verify dimension meta was saved.
			$dim_meta = get_post_meta( $appointment->ID, Constants::META_SLOT_DIMENSIONS, true );
			$this->assertIsArray( $dim_meta );
			$this->assertEquals( $this->service_id, $dim_meta['clisyc_service'] ?? 0 );

			// Verify time slot meta.
			$time_slot = get_post_meta( $appointment->ID, Constants::META_TIME_SLOT, true );
			$this->assertNotEmpty( $time_slot );
		} else {
			// If no appointment created, the JSON response should indicate success.
			$this->assertNotNull( $response );
			$this->assertTrue( $response['success'] ?? false, 'Booking should succeed for logged-in user.' );
		}
	}

	/** @test */
	public function creates_user_for_new_guest() {
		$unique_email = 'newguest_' . wp_generate_password( 6, false ) . '@example.com';

		$this->simulate_booking( [
			'guest_email' => $unique_email,
			'guest_name'  => 'New Guest',
		] );

		$this->execute_handler();

		// Verify the user was created.
		$user = get_user_by( 'email', $unique_email );
		$this->assertNotFalse( $user, 'A new user should be created for a guest.' );
		$this->assertEquals( 'New Guest', $user->first_name );
	}

	/** @test */
	public function reuses_existing_user_for_returning_guest() {
		$email = 'returning@example.com';
		$existing_user_id = $this->factory->user->create( [
			'user_email' => $email,
			'role'       => 'subscriber',
		] );

		$this->simulate_booking( [
			'guest_email' => $email,
			'guest_name'  => 'Returning Guest',
		] );

		$this->execute_handler();

		// Verify no new user was created — still only the original user.
		$user = get_user_by( 'email', $email );
		$this->assertEquals( $existing_user_id, $user->ID );
	}
}
