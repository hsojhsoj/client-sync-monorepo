<?php
/**
 * File: tests/unit/RestApiControllersTest.php
 * Unit tests for REST API Controllers (Appointments and Users).
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use DependentMedia\ClientSync\RestAPI\Appointments_Rest_Controller;
use DependentMedia\ClientSync\RestAPI\Users_Controller;
use DependentMedia\ClientSync\Constants;

class RestApiControllersTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private static $server;

	/** @var int */
	private $admin_id;

	/** @var int */
	private $subscriber_id;

	/** @var int */
	private $service_id;

	public static function wpSetUpBeforeClass( $factory ) {
		// Bootstrap the REST server.
		global $wp_rest_server;
		self::$server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Manually register our controllers.
		$appointments = new Appointments_Rest_Controller();
		$appointments->register_routes();

		$users = new Users_Controller();
		$users->register_routes();
	}

	public static function wpTearDownAfterClass() {
		global $wp_rest_server;
		$wp_rest_server = null;
	}

	public function setUp(): void {
		parent::setUp();

		// Register post types.
		if ( ! post_type_exists( Constants::POST_TYPE_APPOINTMENT ) ) {
			register_post_type( Constants::POST_TYPE_APPOINTMENT, [ 'public' => false ] );
		}
		if ( ! post_type_exists( 'clisyc_service' ) ) {
			register_post_type( 'clisyc_service', [ 'public' => false ] );
		}

		// Create users with specific capabilities.
		$this->admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		// Create a test service.
		$this->service_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_service',
			'post_title'  => 'REST Test Service',
			'post_status' => 'publish',
		] );
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	// ── Helper ────────────────────────────────────────────────────────

	/**
	 * Create a test appointment and return its ID.
	 */
	private function create_test_appointment( array $meta_overrides = [], string $status = 'publish', int $author = 0 ): int {
		$appointment_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => $status,
			'post_author' => $author ?: $this->subscriber_id,
			'post_title'  => 'Test Appointment',
		] );

		$defaults = [
			Constants::META_APPOINTMENT_DATE => '2026-08-15',
			Constants::META_TIME_SLOT        => '2026-08-15 10:00:00',
			Constants::META_BOOKING_MODE     => 'slot',
			Constants::META_SLOT_DIMENSIONS  => [ 'clisyc_service' => $this->service_id ],
		];

		foreach ( array_merge( $defaults, $meta_overrides ) as $key => $value ) {
			update_post_meta( $appointment_id, $key, $value );
		}

		return $appointment_id;
	}

	// =====================================================================
	// Users Controller Tests
	// =====================================================================

	/**
	 * @test
	 * Subscribers should not be able to search users (requires edit_posts).
	 */
	public function it_rejects_unauthenticated_user_search() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/users/search' );
		$request->set_param( 'q', 'test' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * @test
	 * Admin should be able to search users by display name.
	 */
	public function it_searches_users_by_name() {
		wp_set_current_user( $this->admin_id );

		// Create a user with a unique name.
		$target_id = $this->factory()->user->create( [
			'display_name' => 'UniqueSearchableName',
			'user_login'   => 'searchable_user_' . wp_generate_password( 4, false ),
			'user_email'   => 'searchable_' . wp_generate_password( 4, false ) . '@example.com',
		] );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/users/search' );
		$request->set_param( 'q', 'UniqueSearchable' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'results', $data );
		$this->assertGreaterThanOrEqual( 1, count( $data['results'] ) );

		// Verify the target user is in results.
		$found = false;
		foreach ( $data['results'] as $user ) {
			if ( $user['id'] === $target_id ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'The searchable user should appear in results.' );
	}

	/**
	 * @test
	 * Admin should be able to search users by email.
	 */
	public function it_searches_users_by_email() {
		wp_set_current_user( $this->admin_id );

		$unique_email = 'uniquesearchemail_' . wp_generate_password( 6, false ) . '@example.com';
		$target_id = $this->factory()->user->create( [
			'user_email' => $unique_email,
			'user_login' => 'email_search_user_' . wp_generate_password( 4, false ),
		] );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/users/search' );
		$request->set_param( 'q', 'uniquesearchemail' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$found = false;
		foreach ( $data['results'] as $user ) {
			if ( $user['id'] === $target_id ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'User should be findable by email.' );
	}

	/**
	 * @test
	 * Search query must be at least 2 characters.
	 */
	public function it_requires_minimum_query_length() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/users/search' );
		$request->set_param( 'q', 'J' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * User response should contain all expected fields.
	 */
	public function it_returns_formatted_user_response() {
		wp_set_current_user( $this->admin_id );

		$target_id = $this->factory()->user->create( [
			'display_name' => 'FormattedResponseUser',
			'user_login'   => 'formatted_user_' . wp_generate_password( 4, false ),
			'user_email'   => 'formatted_' . wp_generate_password( 4, false ) . '@example.com',
			'first_name'   => 'Test',
			'last_name'    => 'User',
		] );
		update_user_meta( $target_id, 'first_name', 'Test' );
		update_user_meta( $target_id, 'last_name', 'User' );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/users/' . $target_id );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'display_name', $data );
		$this->assertArrayHasKey( 'email', $data );
		$this->assertArrayHasKey( 'first_name', $data );
		$this->assertArrayHasKey( 'last_name', $data );
		$this->assertArrayHasKey( 'full_name', $data );
		$this->assertArrayHasKey( 'display_string', $data );
		$this->assertArrayHasKey( 'avatar_url', $data );
		$this->assertArrayHasKey( 'roles', $data );

		$this->assertEquals( $target_id, $data['id'] );
	}

	/**
	 * @test
	 * Should fetch a single user by ID.
	 */
	public function it_fetches_single_user_by_id() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/users/' . $this->subscriber_id );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( $this->subscriber_id, $data['id'] );
	}

	// =====================================================================
	// Appointments Controller Tests
	// =====================================================================

	/**
	 * @test
	 * Subscribers should not be able to list appointments.
	 */
	public function it_rejects_unauthenticated_appointment_list() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/appointments' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * @test
	 * Appointments list should support pagination with correct headers.
	 */
	public function it_lists_appointments_with_pagination() {
		wp_set_current_user( $this->admin_id );

		// Create 3 appointments.
		$this->create_test_appointment();
		$this->create_test_appointment( [ Constants::META_APPOINTMENT_DATE => '2026-08-16' ] );
		$this->create_test_appointment( [ Constants::META_APPOINTMENT_DATE => '2026-08-17' ] );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/appointments' );
		$request->set_param( 'per_page', 2 );
		$request->set_param( 'page', 1 );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 2, $data );

		$headers = $response->get_headers();
		$this->assertEquals( 3, $headers['X-WP-Total'] );
		$this->assertEquals( 2, $headers['X-WP-TotalPages'] );
	}

	/**
	 * @test
	 * Appointments should be filterable by status.
	 */
	public function it_filters_appointments_by_status() {
		wp_set_current_user( $this->admin_id );

		$this->create_test_appointment( [], 'publish' );
		$this->create_test_appointment( [], Constants::STATUS_CANCELLED );
		$this->create_test_appointment( [], 'publish' );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/appointments' );
		$request->set_param( 'status', 'publish' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		foreach ( $data as $appointment ) {
			$this->assertEquals( 'publish', $appointment['status'] );
		}
	}

	/**
	 * @test
	 * Appointments should be filterable by date range.
	 */
	public function it_filters_appointments_by_date_range() {
		wp_set_current_user( $this->admin_id );

		$this->create_test_appointment( [ Constants::META_APPOINTMENT_DATE => '2026-07-01' ] );
		$this->create_test_appointment( [ Constants::META_APPOINTMENT_DATE => '2026-08-15' ] );
		$this->create_test_appointment( [ Constants::META_APPOINTMENT_DATE => '2026-09-30' ] );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/appointments' );
		$request->set_param( 'start_date', '2026-08-01' );
		$request->set_param( 'end_date', '2026-08-31' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertEquals( '2026-08-15', $data[0]['appointment_date'] );
	}

	/**
	 * @test
	 * Should return a single appointment by ID.
	 */
	public function it_returns_single_appointment() {
		wp_set_current_user( $this->admin_id );

		$appt_id = $this->create_test_appointment();

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/appointments/' . $appt_id );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( $appt_id, $data['id'] );
		$this->assertEquals( 'publish', $data['status'] );
		$this->assertEquals( '2026-08-15', $data['appointment_date'] );
		$this->assertArrayHasKey( 'client', $data );
		$this->assertNotNull( $data['client'] );
	}

	/**
	 * @test
	 * Should return 404 for a non-existent appointment.
	 */
	public function it_returns_404_for_nonexistent_appointment() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/appointments/99999' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * @test
	 * Admin should be able to update appointment status via PUT.
	 */
	public function it_updates_appointment_status() {
		wp_set_current_user( $this->admin_id );

		$appt_id = $this->create_test_appointment();

		$request = new WP_REST_Request( 'PUT', '/clisyc/v1/appointments/' . $appt_id );
		$request->set_param( 'status', Constants::STATUS_CONFIRMED );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( Constants::STATUS_CONFIRMED, $data['status'] );

		// Verify in database.
		$this->assertEquals( Constants::STATUS_CONFIRMED, get_post_status( $appt_id ) );
	}

	/**
	 * @test
	 * Admin should be able to cancel an appointment via DELETE.
	 */
	public function it_cancels_appointment_via_delete() {
		wp_set_current_user( $this->admin_id );

		$appt_id = $this->create_test_appointment();

		$request = new WP_REST_Request( 'DELETE', '/clisyc/v1/appointments/' . $appt_id );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['deleted'] );
		$this->assertEquals( Constants::STATUS_CANCELLED, $data['status'] );

		// Verify in database.
		$this->assertEquals( Constants::STATUS_CANCELLED, get_post_status( $appt_id ) );
	}
}
