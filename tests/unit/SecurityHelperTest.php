<?php
/**
 * File: tests/unit/SecurityHelperTest.php
 * Unit tests for the Security_Helper utility class.
 *
 * Tests rate limiting behavior, admin bypass, IP handling,
 * and context validation.
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Utility\Security_Helper;

class SecurityHelperTest extends WP_UnitTestCase {

	/** @var int */
	private $admin_id;

	/** @var int */
	private $editor_id;

	/** @var int */
	private $subscriber_id;

	public function setUp(): void {
		parent::setUp();

		$this->admin_id      = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->editor_id     = $this->factory()->user->create( [ 'role' => 'editor' ] );
		$this->subscriber_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		// Simulate a client IP for rate limiting.
		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
	}

	public function tearDown(): void {
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ── Rate Limiting ────────────────────────────────────────────────

	/**
	 * @test
	 * First request should always be allowed.
	 */
	public function it_allows_first_request() {
		wp_set_current_user( $this->subscriber_id );

		$result = Security_Helper::check_rate_limit( 'test_action_' . uniqid(), 5, 60 );
		$this->assertTrue( $result );
	}

	/**
	 * @test
	 * Requests within the limit should be allowed.
	 */
	public function it_allows_requests_within_limit() {
		wp_set_current_user( $this->subscriber_id );

		$action = 'within_limit_' . uniqid();
		for ( $i = 0; $i < 3; $i++ ) {
			$result = Security_Helper::check_rate_limit( $action, 5, 60 );
			$this->assertTrue( $result, "Request $i should be allowed." );
		}
	}

	/**
	 * @test
	 * Exceeding the limit should return WP_Error with 429 status.
	 */
	public function it_blocks_requests_exceeding_limit() {
		wp_set_current_user( $this->subscriber_id );

		$action = 'exceed_limit_' . uniqid();
		$limit  = 3;

		// Use up all allowed requests.
		for ( $i = 0; $i < $limit; $i++ ) {
			Security_Helper::check_rate_limit( $action, $limit, 60 );
		}

		// Next request should be blocked.
		$result = Security_Helper::check_rate_limit( $action, $limit, 60 );
		$this->assertWPError( $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 429, $data['status'] );
	}

	/**
	 * @test
	 * Different actions should have independent rate limits.
	 */
	public function it_tracks_actions_independently() {
		wp_set_current_user( $this->subscriber_id );

		$action_a = 'action_a_' . uniqid();
		$action_b = 'action_b_' . uniqid();

		// Exhaust action A.
		for ( $i = 0; $i < 2; $i++ ) {
			Security_Helper::check_rate_limit( $action_a, 2, 60 );
		}
		$result_a = Security_Helper::check_rate_limit( $action_a, 2, 60 );
		$this->assertWPError( $result_a, 'Action A should be blocked.' );

		// Action B should still be allowed.
		$result_b = Security_Helper::check_rate_limit( $action_b, 2, 60 );
		$this->assertTrue( $result_b, 'Action B should still be allowed.' );
	}

	// ── Admin Bypass ─────────────────────────────────────────────────

	/**
	 * @test
	 * Administrators should bypass rate limiting entirely.
	 */
	public function it_bypasses_rate_limit_for_administrators() {
		wp_set_current_user( $this->admin_id );

		$action = 'admin_bypass_' . uniqid();

		// Even after many requests, admin should always pass.
		for ( $i = 0; $i < 100; $i++ ) {
			$result = Security_Helper::check_rate_limit( $action, 1, 60 );
			$this->assertTrue( $result, "Admin request $i should always be allowed." );
		}
	}

	/**
	 * @test
	 * Editors should NOT bypass rate limiting (only manage_options capability bypasses).
	 */
	public function it_does_not_bypass_rate_limit_for_editors() {
		wp_set_current_user( $this->editor_id );

		$action = 'editor_no_bypass_' . uniqid();
		$limit  = 2;

		for ( $i = 0; $i < $limit; $i++ ) {
			Security_Helper::check_rate_limit( $action, $limit, 60 );
		}

		$result = Security_Helper::check_rate_limit( $action, $limit, 60 );
		$this->assertWPError( $result, 'Editors should NOT bypass rate limits.' );
	}

	// ── IP Handling ──────────────────────────────────────────────────

	/**
	 * @test
	 * When no IP is available, rate limiting should be bypassed.
	 */
	public function it_allows_requests_when_no_ip() {
		wp_set_current_user( $this->subscriber_id );
		unset( $_SERVER['REMOTE_ADDR'] );

		$result = Security_Helper::check_rate_limit( 'no_ip_' . uniqid(), 1, 60 );
		$this->assertTrue( $result );
	}

	/**
	 * @test
	 * Different IPs should have independent rate limits.
	 */
	public function it_tracks_ips_independently() {
		wp_set_current_user( $this->subscriber_id );

		$action = 'ip_test_' . uniqid();
		$limit  = 2;

		// Exhaust limit for IP A.
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		for ( $i = 0; $i < $limit; $i++ ) {
			Security_Helper::check_rate_limit( $action, $limit, 60 );
		}
		$result_a = Security_Helper::check_rate_limit( $action, $limit, 60 );
		$this->assertWPError( $result_a, 'IP A should be blocked.' );

		// IP B should still be allowed.
		$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
		$result_b = Security_Helper::check_rate_limit( $action, $limit, 60 );
		$this->assertTrue( $result_b, 'IP B should still be allowed.' );
	}

	// ── Context Validation ───────────────────────────────────────────

	/**
	 * @test
	 * validate_admin_context should return true for admins with 'admin_editable' context.
	 */
	public function it_validates_admin_context_for_admins() {
		wp_set_current_user( $this->admin_id );

		$this->assertTrue( Security_Helper::validate_admin_context( 'admin_editable' ) );
		$this->assertTrue( Security_Helper::validate_admin_context( 'admin' ) );
	}

	/**
	 * @test
	 * validate_admin_context should return true for editors with admin context.
	 */
	public function it_validates_admin_context_for_editors() {
		wp_set_current_user( $this->editor_id );

		$this->assertTrue( Security_Helper::validate_admin_context( 'admin_editable' ) );
		$this->assertTrue( Security_Helper::validate_admin_context( 'admin' ) );
	}

	/**
	 * @test
	 * validate_admin_context should return false for subscribers.
	 */
	public function it_rejects_admin_context_for_subscribers() {
		wp_set_current_user( $this->subscriber_id );

		$this->assertFalse( Security_Helper::validate_admin_context( 'admin_editable' ) );
		$this->assertFalse( Security_Helper::validate_admin_context( 'admin' ) );
	}

	/**
	 * @test
	 * validate_admin_context should return false for unrecognized contexts.
	 */
	public function it_rejects_unknown_contexts() {
		wp_set_current_user( $this->admin_id );

		$this->assertFalse( Security_Helper::validate_admin_context( 'frontend' ) );
		$this->assertFalse( Security_Helper::validate_admin_context( '' ) );
		$this->assertFalse( Security_Helper::validate_admin_context( 'admin_readonly' ) );
	}

	/**
	 * @test
	 * validate_admin_context should return false for logged-out users.
	 */
	public function it_rejects_context_for_logged_out_users() {
		wp_set_current_user( 0 );

		$this->assertFalse( Security_Helper::validate_admin_context( 'admin_editable' ) );
		$this->assertFalse( Security_Helper::validate_admin_context( 'admin' ) );
	}
}
