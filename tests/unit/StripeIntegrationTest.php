<?php
/**
 * File: tests/unit/StripeIntegrationTest.php
 * Unit tests for the Stripe Integration (webhook verification, rate limiting).
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Integrations\Stripe_Integration;
use DependentMedia\ClientSync\Constants;

class StripeIntegrationTest extends WP_UnitTestCase {

	/** @var Stripe_Integration */
	private $stripe;

	public function setUp(): void {
		parent::setUp();
		$this->stripe = new Stripe_Integration();

		// Register the appointment post type so factory can create posts.
		if ( ! post_type_exists( Constants::POST_TYPE_APPOINTMENT ) ) {
			register_post_type( Constants::POST_TYPE_APPOINTMENT, [ 'public' => false ] );
		}
	}

	public function tearDown(): void {
		delete_option( Constants::OPTION_STRIPE_ENABLED );
		delete_option( Constants::OPTION_STRIPE_KEY_SECRET );
		delete_option( Constants::OPTION_STRIPE_KEY_PUBLIC );
		delete_option( Constants::OPTION_STRIPE_WEBHOOK_SECRET );
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// is_active()
	// -----------------------------------------------------------------

	/** @test */
	public function is_active_returns_false_when_disabled() {
		update_option( Constants::OPTION_STRIPE_ENABLED, false );
		update_option( Constants::OPTION_STRIPE_KEY_SECRET, 'sk_test_xxx' );
		update_option( Constants::OPTION_STRIPE_KEY_PUBLIC, 'pk_test_xxx' );

		$this->assertFalse( Stripe_Integration::is_active() );
	}

	/** @test */
	public function is_active_returns_false_when_keys_missing() {
		update_option( Constants::OPTION_STRIPE_ENABLED, true );
		update_option( Constants::OPTION_STRIPE_KEY_SECRET, '' );
		update_option( Constants::OPTION_STRIPE_KEY_PUBLIC, '' );

		$this->assertFalse( Stripe_Integration::is_active() );
	}

	/** @test */
	public function is_active_returns_true_when_fully_configured() {
		update_option( Constants::OPTION_STRIPE_ENABLED, true );
		update_option( Constants::OPTION_STRIPE_KEY_SECRET, 'sk_test_xxx' );
		update_option( Constants::OPTION_STRIPE_KEY_PUBLIC, 'pk_test_xxx' );

		$this->assertTrue( Stripe_Integration::is_active() );
	}

	// -----------------------------------------------------------------
	// verify_signature() — accessed via handle_webhook()
	// -----------------------------------------------------------------

	/** @test */
	public function webhook_rejects_missing_signature() {
		update_option( Constants::OPTION_STRIPE_WEBHOOK_SECRET, 'whsec_test' );

		$request = new \WP_REST_Request( 'POST', '/clisyc/v1/stripe-webhook' );
		$request->set_body( '{}' );
		// No Stripe-Signature header.

		$response = $this->stripe->handle_webhook( $request );
		$this->assertEquals( 400, $response->get_status() );
	}

	/** @test */
	public function webhook_rejects_missing_webhook_secret() {
		update_option( Constants::OPTION_STRIPE_WEBHOOK_SECRET, '' );

		$request = new \WP_REST_Request( 'POST', '/clisyc/v1/stripe-webhook' );
		$request->set_body( '{}' );
		$request->set_header( 'Stripe-Signature', 't=123,v1=abc' );

		$response = $this->stripe->handle_webhook( $request );
		$this->assertEquals( 400, $response->get_status() );
	}

	/** @test */
	public function webhook_rejects_invalid_signature() {
		$secret = 'whsec_test_secret_123';
		update_option( Constants::OPTION_STRIPE_WEBHOOK_SECRET, $secret );

		$payload   = '{"type":"checkout.session.completed"}';
		$timestamp = time();
		$bad_sig   = 'wrong_signature_value';

		$request = new \WP_REST_Request( 'POST', '/clisyc/v1/stripe-webhook' );
		$request->set_body( $payload );
		$request->set_header( 'Stripe-Signature', "t={$timestamp},v1={$bad_sig}" );

		$response = $this->stripe->handle_webhook( $request );
		$this->assertEquals( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertStringContainsString( 'Signature verification failed', $data['error'] );
	}

	/** @test */
	public function webhook_rejects_expired_timestamp() {
		$secret    = 'whsec_test_secret_123';
		update_option( Constants::OPTION_STRIPE_WEBHOOK_SECRET, $secret );

		$payload   = '{"type":"checkout.session.completed"}';
		$timestamp = time() - 600; // 10 minutes ago — beyond 5-minute window.
		$expected  = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		$request = new \WP_REST_Request( 'POST', '/clisyc/v1/stripe-webhook' );
		$request->set_body( $payload );
		$request->set_header( 'Stripe-Signature', "t={$timestamp},v1={$expected}" );

		$response = $this->stripe->handle_webhook( $request );
		$this->assertEquals( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertStringContainsString( 'Timestamp too old', $data['error'] );
	}

	/** @test */
	public function webhook_accepts_valid_signature_and_ignores_unknown_event() {
		$secret  = 'whsec_test_secret_123';
		update_option( Constants::OPTION_STRIPE_WEBHOOK_SECRET, $secret );

		$payload   = json_encode( [ 'type' => 'charge.succeeded', 'data' => [] ] );
		$timestamp = time();
		$expected  = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		$request = new \WP_REST_Request( 'POST', '/clisyc/v1/stripe-webhook' );
		$request->set_body( $payload );
		$request->set_header( 'Stripe-Signature', "t={$timestamp},v1={$expected}" );

		$response = $this->stripe->handle_webhook( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['received'] );
	}

	/** @test */
	public function webhook_confirms_appointment_on_valid_checkout_completed() {
		$secret = 'whsec_test_secret_123';
		update_option( Constants::OPTION_STRIPE_WEBHOOK_SECRET, $secret );

		// Create a pending-payment appointment.
		$appointment_id = $this->factory->post->create( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => Constants::STATUS_PENDING_PAYMENT,
		] );

		$payload = json_encode( [
			'type' => 'checkout.session.completed',
			'data' => [
				'object' => [
					'id'             => 'cs_test_session_123',
					'payment_intent' => 'pi_test_intent_456',
					'metadata'       => [ 'appointment_id' => $appointment_id ],
				],
			],
		] );

		$timestamp = time();
		$expected  = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		$request = new \WP_REST_Request( 'POST', '/clisyc/v1/stripe-webhook' );
		$request->set_body( $payload );
		$request->set_header( 'Stripe-Signature', "t={$timestamp},v1={$expected}" );

		$response = $this->stripe->handle_webhook( $request );
		$this->assertEquals( 200, $response->get_status() );

		// Verify appointment was confirmed.
		$post = get_post( $appointment_id );
		$this->assertEquals( 'publish', $post->post_status );

		// Verify payment meta was saved.
		$this->assertEquals( 'paid_via_stripe', get_post_meta( $appointment_id, Constants::META_PAYMENT_STATUS, true ) );
		$this->assertEquals( 'cs_test_session_123', get_post_meta( $appointment_id, Constants::META_STRIPE_SESSION_ID, true ) );
		$this->assertEquals( 'pi_test_intent_456', get_post_meta( $appointment_id, Constants::META_STRIPE_PAYMENT_INTENT, true ) );
	}

	/** @test */
	public function webhook_rejects_invalid_appointment_id() {
		$secret = 'whsec_test_secret_123';
		update_option( Constants::OPTION_STRIPE_WEBHOOK_SECRET, $secret );

		$payload = json_encode( [
			'type' => 'checkout.session.completed',
			'data' => [
				'object' => [
					'id'       => 'cs_test_session_999',
					'metadata' => [ 'appointment_id' => 999999 ],
				],
			],
		] );

		$timestamp = time();
		$expected  = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		$request = new \WP_REST_Request( 'POST', '/clisyc/v1/stripe-webhook' );
		$request->set_body( $payload );
		$request->set_header( 'Stripe-Signature', "t={$timestamp},v1={$expected}" );

		$response = $this->stripe->handle_webhook( $request );
		$this->assertEquals( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertStringContainsString( 'Invalid appointment', $data['error'] );
	}

	// -----------------------------------------------------------------
	// create_checkout_session() — tests for input validation only
	// (No real Stripe API calls)
	// -----------------------------------------------------------------

	/** @test */
	public function create_checkout_session_fails_without_secret_key() {
		update_option( Constants::OPTION_STRIPE_KEY_SECRET, '' );

		$result = $this->stripe->create_checkout_session( 1, 1000 );
		$this->assertWPError( $result );
		$this->assertEquals( 'stripe_not_configured', $result->get_error_code() );
	}
}
