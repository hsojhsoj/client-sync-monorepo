<?php
/**
 * File: tests/unit/RestApiPublicControllersTest.php
 * Unit tests for REST API Controllers (Price, Dimensions, Filter Options, Timeline).
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use DependentMedia\ClientSync\RestAPI\Price_Controller;
use DependentMedia\ClientSync\RestAPI\Dimensions_Controller;
use DependentMedia\ClientSync\RestAPI\Filter_Options_Controller;
use DependentMedia\ClientSync\RestAPI\Timeline_Controller;
use DependentMedia\ClientSync\Constants;

class RestApiPublicControllersTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private static $server;

	/** @var int */
	private $admin_id;

	/** @var int */
	private $subscriber_id;

	/** @var int */
	private $editor_id;

	public static function wpSetUpBeforeClass( $factory ) {
		// Bootstrap the REST server.
		global $wp_rest_server;
		self::$server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Manually register our controllers.
		$price = new Price_Controller();
		$price->register_routes();

		$dimensions = new Dimensions_Controller();
		$dimensions->register_routes();

		$filter_options = new Filter_Options_Controller();
		$filter_options->register_routes();

		$timeline = new Timeline_Controller();
		$timeline->register_routes();
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
		if ( ! post_type_exists( Constants::POST_TYPE_SERVICE ) ) {
			register_post_type( Constants::POST_TYPE_SERVICE, [ 'public' => false ] );
		}
		if ( ! post_type_exists( 'clisyc_room' ) ) {
			register_post_type( 'clisyc_room', [ 'public' => false ] );
		}

		// Create users with specific capabilities.
		$this->admin_id      = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->editor_id     = $this->factory()->user->create( [ 'role' => 'editor' ] );
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	// =====================================================================
	// Price Controller Tests
	// =====================================================================

	/**
	 * @test
	 * Price route should be registered and accessible via GET.
	 */
	public function it_registers_price_route() {
		$routes = self::$server->get_routes();
		$this->assertArrayHasKey( '/clisyc/v1/price', $routes );

		// Verify the route accepts GET requests.
		$route_data = $routes['/clisyc/v1/price'];
		$methods    = $route_data[0]['methods'];
		$this->assertArrayHasKey( 'GET', $methods );
	}

	/**
	 * @test
	 * Calculate-basket route should be registered and accessible via GET.
	 */
	public function it_registers_calculate_basket_route() {
		$routes = self::$server->get_routes();
		$this->assertArrayHasKey( '/clisyc/v1/price/calculate-basket', $routes );

		$route_data = $routes['/clisyc/v1/price/calculate-basket'];
		$methods    = $route_data[0]['methods'];
		$this->assertArrayHasKey( 'GET', $methods );
	}

	/**
	 * @test
	 * Price endpoint should be publicly accessible (no auth required).
	 */
	public function it_allows_unauthenticated_access_to_price_endpoint() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price' );
		$request->set_param( 'product_id', '123' );
		$request->set_param( 'start_date', '2026-08-01' );
		$request->set_param( 'end_date', '2026-08-05' );
		$response = self::$server->dispatch( $request );

		// Should not be 401 or 403 since it is a public endpoint.
		// It will return 500 because WooCommerce is not active, which is correct behavior.
		$this->assertNotEquals( 401, $response->get_status() );
		$this->assertNotEquals( 403, $response->get_status() );
	}

	/**
	 * @test
	 * Price endpoint should return an error for a non-existent product.
	 * Note: WooCommerce IS active in the test environment, so the WC check passes.
	 * With a non-existent product, the calculator returns null → 400 calculation_error.
	 */
	public function it_returns_error_for_nonexistent_product_price() {
		// CI skip: test assumes WooCommerce is loaded (comment above says so)
		// but WooCommerce isn't installed in the CI runner, so the calculator
		// throws and the endpoint returns 500 instead of 400. Locally-only.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'Requires WooCommerce (not installed in CI).' );
		}

		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price' );
		$request->set_param( 'product_id', '999999' );
		$request->set_param( 'start_date', '2026-08-01' );
		$request->set_param( 'end_date', '2026-08-05' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'calculation_error', $data['code'] );
	}

	/**
	 * @test
	 * Calculate-basket endpoint should return 200 with empty line items when no WC product is linked.
	 * Note: WooCommerce IS active in the test environment. With a non-existent primary,
	 * no WC product ID is found, so it returns an empty basket with $0 total.
	 */
	public function it_returns_empty_basket_for_nonexistent_primary() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'Requires WooCommerce (not installed in CI).' );
		}

		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price/calculate-basket' );
		$request->set_param( 'primary_id', '999999' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 0, $data['total'] );
		$this->assertIsArray( $data['line_items'] );
	}

	/**
	 * @test
	 * Price endpoint should reject missing product_id parameter.
	 */
	public function it_rejects_missing_product_id_for_price() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price' );
		$request->set_param( 'start_date', '2026-08-01' );
		$request->set_param( 'end_date', '2026-08-05' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Price endpoint should reject missing start_date parameter.
	 */
	public function it_rejects_missing_start_date_for_price() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price' );
		$request->set_param( 'product_id', '123' );
		$request->set_param( 'end_date', '2026-08-05' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Price endpoint should reject missing end_date parameter.
	 */
	public function it_rejects_missing_end_date_for_price() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price' );
		$request->set_param( 'product_id', '123' );
		$request->set_param( 'start_date', '2026-08-01' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Price endpoint should reject invalid date format (not Y-m-d).
	 */
	public function it_rejects_invalid_date_format_for_price() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price' );
		$request->set_param( 'product_id', '123' );
		$request->set_param( 'start_date', '08/01/2026' );
		$request->set_param( 'end_date', '2026-08-05' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Price endpoint should reject relative date strings.
	 */
	public function it_rejects_relative_date_strings_for_price() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price' );
		$request->set_param( 'product_id', '123' );
		$request->set_param( 'start_date', 'next week' );
		$request->set_param( 'end_date', '2026-08-05' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Price endpoint should reject non-numeric product_id.
	 */
	public function it_rejects_non_numeric_product_id_for_price() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price' );
		$request->set_param( 'product_id', 'abc' );
		$request->set_param( 'start_date', '2026-08-01' );
		$request->set_param( 'end_date', '2026-08-05' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Calculate-basket endpoint should reject missing primary_id.
	 */
	public function it_rejects_missing_primary_id_for_basket() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price/calculate-basket' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Calculate-basket endpoint should reject non-numeric primary_id.
	 */
	public function it_rejects_non_numeric_primary_id_for_basket() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price/calculate-basket' );
		$request->set_param( 'primary_id', 'not-a-number' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Calculate-basket should reject invalid dimension_ids format.
	 */
	public function it_rejects_invalid_dimension_ids_for_basket() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price/calculate-basket' );
		$request->set_param( 'primary_id', '123' );
		$request->set_param( 'dimension_ids', 'abc,def' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Calculate-basket should accept valid comma-separated dimension_ids.
	 */
	public function it_accepts_valid_dimension_ids_for_basket() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'Requires WooCommerce (not installed in CI).' );
		}

		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price/calculate-basket' );
		$request->set_param( 'primary_id', '123' );
		$request->set_param( 'dimension_ids', '1,2,3' );
		$response = self::$server->dispatch( $request );

		// Should pass validation. WooCommerce is active but no products exist → empty basket.
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * @test
	 * Calculate-basket should accept empty dimension_ids.
	 */
	public function it_accepts_empty_dimension_ids_for_basket() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'Requires WooCommerce (not installed in CI).' );
		}

		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price/calculate-basket' );
		$request->set_param( 'primary_id', '123' );
		$request->set_param( 'dimension_ids', '' );
		$response = self::$server->dispatch( $request );

		// Should pass validation. WooCommerce is active but no products exist → empty basket.
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * @test
	 * Price endpoint should reject an impossible date (e.g. Feb 30).
	 */
	public function it_rejects_impossible_date_for_price() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/price' );
		$request->set_param( 'product_id', '123' );
		$request->set_param( 'start_date', '2026-02-30' );
		$request->set_param( 'end_date', '2026-08-05' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Price controller validation: is_valid_date accepts valid Y-m-d.
	 */
	public function it_validates_correct_date_format() {
		$controller = new Price_Controller();
		$request    = new WP_REST_Request( 'GET', '/clisyc/v1/price' );

		$this->assertTrue( $controller->is_valid_date( '2026-08-15', $request, 'start_date' ) );
		$this->assertTrue( $controller->is_valid_date( '2026-01-01', $request, 'start_date' ) );
		$this->assertTrue( $controller->is_valid_date( '2026-12-31', $request, 'end_date' ) );
	}

	/**
	 * @test
	 * Price controller validation: is_valid_date rejects invalid formats.
	 */
	public function it_rejects_invalid_date_formats() {
		$controller = new Price_Controller();
		$request    = new WP_REST_Request( 'GET', '/clisyc/v1/price' );

		$this->assertFalse( $controller->is_valid_date( 'not-a-date', $request, 'start_date' ) );
		$this->assertFalse( $controller->is_valid_date( '2026/08/15', $request, 'start_date' ) );
		$this->assertFalse( $controller->is_valid_date( '08-15-2026', $request, 'start_date' ) );
		$this->assertFalse( $controller->is_valid_date( '2026-13-01', $request, 'start_date' ) );
		$this->assertFalse( $controller->is_valid_date( '2026-02-30', $request, 'start_date' ) );
		$this->assertFalse( $controller->is_valid_date( '+1 year', $request, 'start_date' ) );
		$this->assertFalse( $controller->is_valid_date( 'next week', $request, 'start_date' ) );
	}

	/**
	 * @test
	 * Price controller validation: is_valid_date_or_empty accepts empty strings.
	 */
	public function it_accepts_empty_string_for_optional_date() {
		$controller = new Price_Controller();
		$request    = new WP_REST_Request( 'GET', '/clisyc/v1/price/calculate-basket' );

		$this->assertTrue( $controller->is_valid_date_or_empty( '', $request, 'start_date' ) );
		$this->assertTrue( $controller->is_valid_date_or_empty( '2026-08-01', $request, 'start_date' ) );
		$this->assertFalse( $controller->is_valid_date_or_empty( 'invalid', $request, 'start_date' ) );
	}

	/**
	 * @test
	 * Price controller validation: is_valid_numeric_id accepts numeric values.
	 */
	public function it_validates_numeric_ids() {
		$controller = new Price_Controller();
		$request    = new WP_REST_Request( 'GET', '/clisyc/v1/price' );

		$this->assertTrue( $controller->is_valid_numeric_id( '123', $request, 'product_id' ) );
		$this->assertTrue( $controller->is_valid_numeric_id( 456, $request, 'product_id' ) );
		$this->assertTrue( $controller->is_valid_numeric_id( '0', $request, 'product_id' ) );
		$this->assertFalse( $controller->is_valid_numeric_id( 'abc', $request, 'product_id' ) );
		$this->assertFalse( $controller->is_valid_numeric_id( '', $request, 'product_id' ) );
	}

	/**
	 * @test
	 * Price endpoint schema should contain total and formatted properties.
	 */
	public function it_returns_correct_price_schema() {
		$controller = new Price_Controller();
		$schema     = $controller->get_public_item_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'total', $schema['properties'] );
		$this->assertArrayHasKey( 'formatted', $schema['properties'] );
		$this->assertEquals( 'number', $schema['properties']['total']['type'] );
		$this->assertEquals( 'string', $schema['properties']['formatted']['type'] );
	}

	// =====================================================================
	// Dimensions Controller Tests
	// =====================================================================

	/**
	 * @test
	 * Dimensions graph route should be registered.
	 */
	public function it_registers_dimensions_graph_route() {
		$routes = self::$server->get_routes();
		$this->assertArrayHasKey( '/clisyc/v1/dimensions/graph', $routes );
	}

	/**
	 * @test
	 * Dimensions registry route should be registered.
	 */
	public function it_registers_dimensions_registry_route() {
		$routes = self::$server->get_routes();
		$this->assertArrayHasKey( '/clisyc/v1/dimensions/registry', $routes );
	}

	/**
	 * @test
	 * Dimensions types route should be registered.
	 */
	public function it_registers_dimensions_types_route() {
		$routes = self::$server->get_routes();
		$this->assertArrayHasKey( '/clisyc/v1/dimensions/types', $routes );
	}

	/**
	 * @test
	 * Dimensions posts route should be registered.
	 */
	public function it_registers_dimensions_posts_route() {
		$routes = self::$server->get_routes();
		$this->assertArrayHasKey( '/clisyc/v1/dimensions/posts', $routes );
	}

	/**
	 * @test
	 * Dimensions create-post route should be registered.
	 */
	public function it_registers_dimensions_create_post_route() {
		$routes = self::$server->get_routes();
		$this->assertArrayHasKey( '/clisyc/v1/dimensions/create-post', $routes );
	}

	/**
	 * @test
	 * Subscriber should not be able to access dimensions graph endpoint.
	 */
	public function it_rejects_subscriber_access_to_dimensions_graph() {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/graph' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * @test
	 * Subscriber should not be able to access dimensions registry endpoint.
	 */
	public function it_rejects_subscriber_access_to_dimensions_registry() {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/registry' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * @test
	 * Subscriber should not be able to access dimensions types endpoint.
	 */
	public function it_rejects_subscriber_access_to_dimensions_types() {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/types' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * @test
	 * Unauthenticated user should not be able to access dimensions graph endpoint.
	 * WP REST API returns 401 (not 403) when permission_callback returns false for user_id=0.
	 */
	public function it_rejects_unauthenticated_access_to_dimensions_graph() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/graph' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * @test
	 * Editor should not be able to access dimensions graph (requires manage_options).
	 */
	public function it_rejects_editor_access_to_dimensions_graph() {
		wp_set_current_user( $this->editor_id );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/graph' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * @test
	 * Admin should be able to access dimensions registry and get 200.
	 */
	public function it_allows_admin_access_to_dimensions_registry() {
		wp_set_current_user( $this->admin_id );

		// Ensure the option exists with default empty value.
		update_option( Constants::OPTION_DIMENSION_REGISTRY, [] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/registry' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
	}

	/**
	 * @test
	 * Admin should receive registry data that was stored in options.
	 */
	public function it_returns_stored_dimension_registry_data() {
		wp_set_current_user( $this->admin_id );

		$registry_data = [
			'dimensions'   => [
				'clisyc_service' => [ 'primary' => true, 'post_type' => 'clisyc_service' ],
			],
			'filter_order' => [ 'clisyc_service' ],
		];
		update_option( Constants::OPTION_DIMENSION_REGISTRY, $registry_data );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/registry' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'dimensions', $data );
		$this->assertArrayHasKey( 'filter_order', $data );
		$this->assertArrayHasKey( 'clisyc_service', $data['dimensions'] );
	}

	/**
	 * @test
	 * Admin should be able to access dimension types and get properly structured array.
	 */
	public function it_returns_dimension_types_as_numeric_array() {
		wp_set_current_user( $this->admin_id );

		$types_data = [
			'clisyc_service' => [
				'singular' => 'Service',
				'plural'   => 'Services',
				'icon'     => 'dashicons-admin-tools',
			],
			'clisyc_room'    => [
				'singular' => 'Room',
				'plural'   => 'Rooms',
				'icon'     => 'dashicons-building',
			],
		];
		update_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, $types_data );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/types' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 2, $data );

		// Verify it is a numeric (not associative) array for React .map().
		$this->assertArrayHasKey( 0, $data );
		$this->assertArrayHasKey( 1, $data );

		// Verify structure of each item.
		$this->assertArrayHasKey( 'slug', $data[0] );
		$this->assertArrayHasKey( 'label', $data[0] );
		$this->assertArrayHasKey( 'singular', $data[0] );
		$this->assertArrayHasKey( 'plural', $data[0] );
		$this->assertArrayHasKey( 'icon', $data[0] );
	}

	/**
	 * @test
	 * Dimension types should return empty array when no types configured.
	 */
	public function it_returns_empty_array_for_no_dimension_types() {
		wp_set_current_user( $this->admin_id );

		update_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/types' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertEmpty( $data );
	}

	/**
	 * @test
	 * Dimensions posts endpoint should be publicly accessible.
	 */
	public function it_allows_unauthenticated_access_to_dimension_posts() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/posts' );
		$request->set_param( 'type', Constants::POST_TYPE_SERVICE );
		$response = self::$server->dispatch( $request );

		// Should get 200, not 401/403.
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * @test
	 * Dimensions posts endpoint requires type parameter.
	 */
	public function it_rejects_dimension_posts_without_type() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/posts' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Dimensions posts endpoint rejects non-clisyc post types.
	 */
	public function it_rejects_non_clisyc_post_type_for_dimension_posts() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/posts' );
		$request->set_param( 'type', 'post' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'invalid_cpt', $data['code'] );
	}

	/**
	 * @test
	 * Dimensions posts endpoint returns 404 for non-existent post type.
	 */
	public function it_returns_404_for_nonexistent_dimension_type() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/posts' );
		$request->set_param( 'type', 'clisyc_nonexistent' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * @test
	 * Dimensions posts endpoint returns correct structure for published posts.
	 */
	public function it_returns_dimension_posts_with_correct_structure() {
		wp_set_current_user( 0 );

		$service_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_SERVICE,
			'post_title'  => 'Test Dimension Post',
			'post_status' => 'publish',
		] );
		update_post_meta( $service_id, Constants::META_COLOR, '#ff0000' );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/dimensions/posts' );
		$request->set_param( 'type', Constants::POST_TYPE_SERVICE );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertGreaterThanOrEqual( 1, count( $data ) );

		// Verify structure of each item.
		$found = false;
		foreach ( $data as $item ) {
			if ( (int) $item['value'] === $service_id ) {
				$found = true;
				$this->assertArrayHasKey( 'value', $item );
				$this->assertArrayHasKey( 'label', $item );
				$this->assertArrayHasKey( 'text', $item );
				$this->assertArrayHasKey( 'color', $item );
				$this->assertEquals( 'Test Dimension Post', $item['label'] );
				$this->assertEquals( $item['label'], $item['text'] );
				$this->assertEquals( '#ff0000', $item['color'] );
				break;
			}
		}
		$this->assertTrue( $found, 'The created dimension post should appear in results.' );
	}

	/**
	 * @test
	 * Subscriber should not be able to create dimension posts.
	 */
	public function it_rejects_subscriber_creating_dimension_post() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/clisyc/v1/dimensions/create-post' );
		$request->set_param( 'post_type', Constants::POST_TYPE_SERVICE );
		$request->set_param( 'title', 'Unauthorized Service' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * @test
	 * Admin should be able to create dimension post.
	 */
	public function it_allows_admin_to_create_dimension_post() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/clisyc/v1/dimensions/create-post' );
		$request->set_param( 'post_type', Constants::POST_TYPE_SERVICE );
		$request->set_param( 'title', 'New Test Service' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'post_id', $data );
		$this->assertGreaterThan( 0, $data['post_id'] );

		// Verify post was actually created.
		$post = get_post( $data['post_id'] );
		$this->assertNotNull( $post );
		$this->assertEquals( 'New Test Service', $post->post_title );
		$this->assertEquals( Constants::POST_TYPE_SERVICE, $post->post_type );
		$this->assertEquals( 'publish', $post->post_status );
	}

	/**
	 * @test
	 * Create dimension post should reject invalid (non-clisyc) post types.
	 */
	public function it_rejects_creating_dimension_post_with_invalid_type() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/clisyc/v1/dimensions/create-post' );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'title', 'Invalid Type' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'invalid_post_type', $data['code'] );
	}

	/**
	 * @test
	 * Dimensions graph endpoint supports both GET and POST methods.
	 */
	public function it_registers_graph_with_get_and_post_methods() {
		$routes    = self::$server->get_routes();
		$route_data = $routes['/clisyc/v1/dimensions/graph'];

		// Route 0: GET
		$this->assertArrayHasKey( 'GET', $route_data[0]['methods'] );

		// Route 1: POST/PUT/PATCH (EDITABLE)
		$this->assertTrue(
			isset( $route_data[1]['methods']['POST'] ) ||
			isset( $route_data[1]['methods']['PUT'] ) ||
			isset( $route_data[1]['methods']['PATCH'] )
		);
	}

	/**
	 * @test
	 * Subscriber should not be able to update graph data via POST.
	 */
	public function it_rejects_subscriber_updating_graph_data() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/clisyc/v1/dimensions/graph' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'nodes' => [], 'edges' => [] ] ) );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	// =====================================================================
	// Filter Options Controller Tests
	// =====================================================================

	/**
	 * @test
	 * Filter options route should be registered.
	 */
	public function it_registers_filter_options_route() {
		$routes = self::$server->get_routes();
		$this->assertArrayHasKey( '/clisyc/v1/filter-options', $routes );

		$route_data = $routes['/clisyc/v1/filter-options'];
		$this->assertArrayHasKey( 'GET', $route_data[0]['methods'] );
	}

	/**
	 * @test
	 * Filter options should be publicly accessible.
	 */
	public function it_allows_unauthenticated_access_to_filter_options() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertNotEquals( 401, $response->get_status() );
		$this->assertNotEquals( 403, $response->get_status() );
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * @test
	 * Filter options should return empty array when no dimensions configured.
	 */
	public function it_returns_empty_options_when_no_dimensions() {
		wp_set_current_user( 0 );

		update_option( Constants::OPTION_DIMENSION_REGISTRY, [] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertEmpty( $data );
	}

	/**
	 * @test
	 * Filter options should return empty array when dimensions key exists but is empty.
	 */
	public function it_returns_empty_options_when_dimensions_key_is_empty() {
		wp_set_current_user( 0 );

		update_option( Constants::OPTION_DIMENSION_REGISTRY, [
			'dimensions' => [],
			'order'      => [],
		] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertEmpty( $data );
	}

	/**
	 * @test
	 * Filter options should return options grouped by dimension slug.
	 */
	public function it_returns_options_grouped_by_slug() {
		wp_set_current_user( 0 );

		// Create test service posts.
		$service_1 = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_SERVICE,
			'post_title'  => 'Haircut',
			'post_status' => 'publish',
		] );
		$service_2 = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_SERVICE,
			'post_title'  => 'Massage',
			'post_status' => 'publish',
		] );
		update_post_meta( $service_1, Constants::META_COLOR, '#ff0000' );

		// Set up dimension registry.
		update_option( Constants::OPTION_DIMENSION_REGISTRY, [
			'dimensions' => [
				Constants::POST_TYPE_SERVICE => [
					'post_type' => Constants::POST_TYPE_SERVICE,
					'primary'   => true,
				],
			],
			'order' => [ Constants::POST_TYPE_SERVICE ],
		] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( Constants::POST_TYPE_SERVICE, $data );

		$service_options = $data[ Constants::POST_TYPE_SERVICE ];
		$this->assertIsArray( $service_options );
		$this->assertGreaterThanOrEqual( 2, count( $service_options ) );

		// Verify structure of each option.
		foreach ( $service_options as $option ) {
			$this->assertArrayHasKey( 'value', $option );
			$this->assertArrayHasKey( 'label', $option );
		}
	}

	/**
	 * @test
	 * Filter options should include price when set on the dimension post.
	 */
	public function it_includes_price_in_filter_options_when_set() {
		wp_set_current_user( 0 );

		$service_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_SERVICE,
			'post_title'  => 'Premium Service',
			'post_status' => 'publish',
		] );
		update_post_meta( $service_id, Constants::META_PRICE, 99.99 );

		update_option( Constants::OPTION_DIMENSION_REGISTRY, [
			'dimensions' => [
				Constants::POST_TYPE_SERVICE => [
					'post_type' => Constants::POST_TYPE_SERVICE,
				],
			],
			'order' => [ Constants::POST_TYPE_SERVICE ],
		] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data    = $response->get_data();
		$options = $data[ Constants::POST_TYPE_SERVICE ];

		$found = false;
		foreach ( $options as $option ) {
			if ( $option['value'] === $service_id ) {
				$found = true;
				$this->assertArrayHasKey( 'price', $option );
				$this->assertEquals( 99.99, $option['price'] );
				break;
			}
		}
		$this->assertTrue( $found, 'Service with price should appear in filter options.' );
	}

	/**
	 * @test
	 * Filter options should omit price when it is zero or not set.
	 */
	public function it_omits_price_when_not_set() {
		wp_set_current_user( 0 );

		$service_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_SERVICE,
			'post_title'  => 'Free Service',
			'post_status' => 'publish',
		] );
		// Explicitly set price to 0.
		update_post_meta( $service_id, Constants::META_PRICE, 0 );

		update_option( Constants::OPTION_DIMENSION_REGISTRY, [
			'dimensions' => [
				Constants::POST_TYPE_SERVICE => [
					'post_type' => Constants::POST_TYPE_SERVICE,
				],
			],
			'order' => [ Constants::POST_TYPE_SERVICE ],
		] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data    = $response->get_data();
		$options = $data[ Constants::POST_TYPE_SERVICE ];

		foreach ( $options as $option ) {
			if ( $option['value'] === $service_id ) {
				$this->assertArrayNotHasKey( 'price', $option );
				break;
			}
		}
	}

	/**
	 * @test
	 * Filter options should include capacity when set on dimension post.
	 */
	public function it_includes_capacity_in_filter_options_when_set() {
		wp_set_current_user( 0 );

		$room_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_room',
			'post_title'  => 'Conference Room',
			'post_status' => 'publish',
		] );
		update_post_meta( $room_id, Constants::META_CAPACITY, 20 );

		update_option( Constants::OPTION_DIMENSION_REGISTRY, [
			'dimensions' => [
				'clisyc_room' => [
					'post_type' => 'clisyc_room',
				],
			],
			'order' => [ 'clisyc_room' ],
		] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data    = $response->get_data();
		$options = $data['clisyc_room'];

		$found = false;
		foreach ( $options as $option ) {
			if ( $option['value'] === $room_id ) {
				$found = true;
				$this->assertArrayHasKey( 'capacity', $option );
				$this->assertEquals( 20, $option['capacity'] );
				break;
			}
		}
		$this->assertTrue( $found, 'Room with capacity should appear in filter options.' );
	}

	/**
	 * @test
	 * Filter options should include description (trimmed) when post has content.
	 */
	public function it_includes_trimmed_description_in_filter_options() {
		wp_set_current_user( 0 );

		$service_id = $this->factory()->post->create( [
			'post_type'    => Constants::POST_TYPE_SERVICE,
			'post_title'   => 'Described Service',
			'post_status'  => 'publish',
			'post_content' => 'This is a long description that should be trimmed down to a reasonable length for display purposes.',
		] );

		update_option( Constants::OPTION_DIMENSION_REGISTRY, [
			'dimensions' => [
				Constants::POST_TYPE_SERVICE => [
					'post_type' => Constants::POST_TYPE_SERVICE,
				],
			],
			'order' => [ Constants::POST_TYPE_SERVICE ],
		] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data    = $response->get_data();
		$options = $data[ Constants::POST_TYPE_SERVICE ];

		$found = false;
		foreach ( $options as $option ) {
			if ( $option['value'] === $service_id ) {
				$found = true;
				$this->assertArrayHasKey( 'description', $option );
				$this->assertNotEmpty( $option['description'] );
				break;
			}
		}
		$this->assertTrue( $found, 'Service with content should have a description in filter options.' );
	}

	/**
	 * @test
	 * Filter options should include minStay/maxStay when set.
	 */
	public function it_includes_min_max_stay_in_filter_options() {
		wp_set_current_user( 0 );

		$service_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_SERVICE,
			'post_title'  => 'Stay Service',
			'post_status' => 'publish',
		] );
		update_post_meta( $service_id, '_clisyc_min_stay', 2 );
		update_post_meta( $service_id, '_clisyc_max_stay', 14 );

		update_option( Constants::OPTION_DIMENSION_REGISTRY, [
			'dimensions' => [
				Constants::POST_TYPE_SERVICE => [
					'post_type' => Constants::POST_TYPE_SERVICE,
				],
			],
			'order' => [ Constants::POST_TYPE_SERVICE ],
		] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data    = $response->get_data();
		$options = $data[ Constants::POST_TYPE_SERVICE ];

		$found = false;
		foreach ( $options as $option ) {
			if ( $option['value'] === $service_id ) {
				$found = true;
				$this->assertArrayHasKey( 'minStay', $option );
				$this->assertArrayHasKey( 'maxStay', $option );
				$this->assertEquals( 2, $option['minStay'] );
				$this->assertEquals( 14, $option['maxStay'] );
				break;
			}
		}
		$this->assertTrue( $found, 'Service with min/max stay should appear in filter options.' );
	}

	/**
	 * @test
	 * Filter options should only include published posts, not drafts.
	 */
	public function it_excludes_draft_posts_from_filter_options() {
		wp_set_current_user( 0 );

		$this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_SERVICE,
			'post_title'  => 'Published Service',
			'post_status' => 'publish',
		] );
		$draft_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_SERVICE,
			'post_title'  => 'Draft Service',
			'post_status' => 'draft',
		] );

		update_option( Constants::OPTION_DIMENSION_REGISTRY, [
			'dimensions' => [
				Constants::POST_TYPE_SERVICE => [
					'post_type' => Constants::POST_TYPE_SERVICE,
				],
			],
			'order' => [ Constants::POST_TYPE_SERVICE ],
		] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data    = $response->get_data();
		$options = $data[ Constants::POST_TYPE_SERVICE ];

		foreach ( $options as $option ) {
			$this->assertNotEquals( $draft_id, $option['value'], 'Draft posts should not appear in filter options.' );
		}
	}

	/**
	 * @test
	 * Filter options should respect the configured order of dimensions.
	 */
	public function it_respects_dimension_order_in_filter_options() {
		wp_set_current_user( 0 );

		// Create posts for both types.
		$this->factory()->post->create( [
			'post_type'   => 'clisyc_room',
			'post_title'  => 'Room A',
			'post_status' => 'publish',
		] );
		$this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_SERVICE,
			'post_title'  => 'Service A',
			'post_status' => 'publish',
		] );

		// Configure with clisyc_room first in order.
		update_option( Constants::OPTION_DIMENSION_REGISTRY, [
			'dimensions' => [
				Constants::POST_TYPE_SERVICE => [
					'post_type' => Constants::POST_TYPE_SERVICE,
				],
				'clisyc_room' => [
					'post_type' => 'clisyc_room',
				],
			],
			'order' => [ 'clisyc_room', Constants::POST_TYPE_SERVICE ],
		] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$keys = array_keys( $data );

		$this->assertEquals( 'clisyc_room', $keys[0] );
		$this->assertEquals( Constants::POST_TYPE_SERVICE, $keys[1] );
	}

	/**
	 * @test
	 * Filter options should skip dimensions whose slug is in order but not in dimensions config.
	 */
	public function it_skips_order_entries_not_in_dimensions_config() {
		wp_set_current_user( 0 );

		$this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_SERVICE,
			'post_title'  => 'Only Service',
			'post_status' => 'publish',
		] );

		update_option( Constants::OPTION_DIMENSION_REGISTRY, [
			'dimensions' => [
				Constants::POST_TYPE_SERVICE => [
					'post_type' => Constants::POST_TYPE_SERVICE,
				],
			],
			'order' => [ 'clisyc_nonexistent', Constants::POST_TYPE_SERVICE ],
		] );

		$request  = new WP_REST_Request( 'GET', '/clisyc/v1/filter-options' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'clisyc_nonexistent', $data );
		$this->assertArrayHasKey( Constants::POST_TYPE_SERVICE, $data );
	}

	// =====================================================================
	// Timeline Controller Tests
	// =====================================================================

	/**
	 * @test
	 * Timeline route should be registered.
	 */
	public function it_registers_timeline_route() {
		$routes = self::$server->get_routes();
		$this->assertArrayHasKey( '/clisyc/v1/timeline-appointments', $routes );

		$route_data = $routes['/clisyc/v1/timeline-appointments'];
		$this->assertArrayHasKey( 'GET', $route_data[0]['methods'] );
	}

	/**
	 * @test
	 * Timeline endpoint should reject unauthenticated access.
	 */
	public function it_rejects_unauthenticated_access_to_timeline() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/timeline-appointments' );
		$request->set_param( 'start', '2026-08-01' );
		$request->set_param( 'end', '2026-08-07' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * @test
	 * Timeline endpoint should reject subscriber access (requires edit_posts).
	 */
	public function it_rejects_subscriber_access_to_timeline() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/timeline-appointments' );
		$request->set_param( 'start', '2026-08-01' );
		$request->set_param( 'end', '2026-08-07' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'rest_forbidden', $data['code'] );
	}

	/**
	 * @test
	 * Timeline endpoint should allow editor access (has edit_posts capability).
	 */
	public function it_allows_editor_access_to_timeline() {
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/timeline-appointments' );
		$request->set_param( 'start', '2026-08-01' );
		$request->set_param( 'end', '2026-08-07' );
		$response = self::$server->dispatch( $request );

		// Editor has edit_posts cap so should pass permission check.
		// Might fail downstream but should not be 403.
		$this->assertNotEquals( 403, $response->get_status() );
	}

	/**
	 * @test
	 * Timeline endpoint should allow admin access.
	 */
	public function it_allows_admin_access_to_timeline() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/timeline-appointments' );
		$request->set_param( 'start', '2026-08-01' );
		$request->set_param( 'end', '2026-08-07' );
		$response = self::$server->dispatch( $request );

		$this->assertNotEquals( 403, $response->get_status() );
	}

	/**
	 * @test
	 * Timeline endpoint should require start parameter.
	 */
	public function it_requires_start_param_for_timeline() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/timeline-appointments' );
		$request->set_param( 'end', '2026-08-07' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Timeline endpoint should require end parameter.
	 */
	public function it_requires_end_param_for_timeline() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/timeline-appointments' );
		$request->set_param( 'start', '2026-08-01' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Timeline endpoint should reject invalid start date.
	 */
	public function it_rejects_invalid_start_date_for_timeline() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/timeline-appointments' );
		$request->set_param( 'start', 'not-a-date' );
		$request->set_param( 'end', '2026-08-07' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Timeline endpoint should reject invalid resources format.
	 */
	public function it_rejects_invalid_resources_for_timeline() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/clisyc/v1/timeline-appointments' );
		$request->set_param( 'start', '2026-08-01' );
		$request->set_param( 'end', '2026-08-07' );
		$request->set_param( 'resources', 'abc,def' );
		$response = self::$server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * @test
	 * Timeline collection params should define start, end, and resources.
	 */
	public function it_defines_correct_collection_params_for_timeline() {
		$controller = new Timeline_Controller();
		$params     = $controller->get_collection_params();

		$this->assertArrayHasKey( 'start', $params );
		$this->assertArrayHasKey( 'end', $params );
		$this->assertArrayHasKey( 'resources', $params );

		$this->assertTrue( $params['start']['required'] );
		$this->assertTrue( $params['end']['required'] );
		$this->assertFalse( $params['resources']['required'] );
	}

	/**
	 * @test
	 * Timeline permission check should return WP_Error with rest_forbidden code.
	 */
	public function it_returns_wp_error_for_forbidden_timeline_access() {
		wp_set_current_user( $this->subscriber_id );

		$controller = new Timeline_Controller();
		$request    = new WP_REST_Request( 'GET', '/clisyc/v1/timeline-appointments' );
		$result     = $controller->get_items_permissions_check( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );

		$error_data = $result->get_error_data();
		$this->assertEquals( 403, $error_data['status'] );
	}

	/**
	 * @test
	 * Timeline permission check should return true for admin.
	 */
	public function it_returns_true_for_admin_timeline_permission_check() {
		wp_set_current_user( $this->admin_id );

		$controller = new Timeline_Controller();
		$request    = new WP_REST_Request( 'GET', '/clisyc/v1/timeline-appointments' );
		$result     = $controller->get_items_permissions_check( $request );

		$this->assertTrue( $result );
	}
}
