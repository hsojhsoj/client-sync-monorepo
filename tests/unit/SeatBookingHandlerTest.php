<?php
/**
 * File: tests/unit/SeatBookingHandlerTest.php
 * Unit tests for the Seat_Booking_Handler class.
 *
 * Tests admin column, basket pricing filter, and free-seat-on-trash.
 *
 * NOTE: The confirm_seats() method reads from $_POST and the dimensions
 * table, making it better suited for integration/E2E testing. We focus
 * here on the testable helper methods and column rendering.
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Table_Schema_Manager;
use ClientSyncPro\Modules\Seat_Selection\Seat_Hold_Manager;
use ClientSyncPro\Modules\Seat_Selection\Seat_Booking_Handler;

class SeatBookingHandlerTest extends WP_UnitTestCase {

	/** @var Seat_Booking_Handler */
	private $handler;

	/** @var Table_Schema_Manager */
	private $schema;

	public function setUp(): void {
		parent::setUp();

		$this->schema  = new Table_Schema_Manager();
		$this->schema->create_tables();

		$hold_manager  = new Seat_Hold_Manager( $this->schema );
		$this->handler = new Seat_Booking_Handler( $hold_manager, $this->schema );

		// Register the appointment post type for tests.
		if ( ! post_type_exists( Constants::POST_TYPE_APPOINTMENT ) ) {
			register_post_type( Constants::POST_TYPE_APPOINTMENT, [ 'public' => false ] );
		}

		// Clean tables.
		$this->truncate_seat_tables();
	}

	public function tearDown(): void {
		$this->truncate_seat_tables();
		parent::tearDown();
	}

	private function truncate_seat_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$this->schema->get_seat_holds_table_name()}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$this->schema->get_seat_bookings_table_name()}" );
	}

	// ── add_seats_column ────────────────────────────────────────────

	/**
	 * @test
	 */
	public function it_inserts_seats_column_before_date_column() {
		$input = [
			'cb'     => '<input type="checkbox" />',
			'title'  => 'Title',
			'author' => 'Author',
			'date'   => 'Date',
		];

		$result = $this->handler->add_seats_column( $input );

		$keys = array_keys( $result );

		$this->assertContains( 'clisyc_seats', $keys );

		$seats_pos = array_search( 'clisyc_seats', $keys, true );
		$date_pos  = array_search( 'date', $keys, true );

		$this->assertLessThan( $date_pos, $seats_pos, 'Seats column should come before date' );
	}

	/**
	 * @test
	 */
	public function it_appends_seats_column_when_no_date_column() {
		$input = [
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
		];

		$result = $this->handler->add_seats_column( $input );

		$this->assertArrayHasKey( 'clisyc_seats', $result );
		// Should be at the end.
		$keys = array_keys( $result );
		$this->assertEquals( 'clisyc_seats', end( $keys ) );
	}

	// ── render_seats_column ─────────────────────────────────────────

	/**
	 * @test
	 */
	public function it_renders_dash_for_appointment_without_seats() {
		$appt_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => 'publish',
		] );

		ob_start();
		$this->handler->render_seats_column( 'clisyc_seats', $appt_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( '—', $output );
	}

	/**
	 * @test
	 */
	public function it_renders_seat_count_and_ids_for_booked_appointment() {
		$appt_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => 'publish',
		] );

		update_post_meta( $appt_id, Constants::META_SELECTED_SEATS, [
			'vip-row1-seat1',
			'vip-row1-seat2',
			'gen-row2-seat3',
		] );

		ob_start();
		$this->handler->render_seats_column( 'clisyc_seats', $appt_id );
		$output = ob_get_clean();

		// Should contain the count.
		$this->assertStringContainsString( '3', $output );
		// Should contain the tooltip with full IDs.
		$this->assertStringContainsString( 'vip-row1-seat1', $output );
	}

	/**
	 * @test
	 */
	public function it_does_not_render_for_other_column_names() {
		$appt_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => 'publish',
		] );

		update_post_meta( $appt_id, Constants::META_SELECTED_SEATS, [ 'seat-1' ] );

		ob_start();
		$this->handler->render_seats_column( 'some_other_column', $appt_id );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * @test
	 */
	public function it_truncates_display_to_5_seats_with_more_indicator() {
		$appt_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => 'publish',
		] );

		$seats = [];
		for ( $i = 1; $i <= 8; $i++ ) {
			$seats[] = "row1-seat{$i}";
		}
		update_post_meta( $appt_id, Constants::META_SELECTED_SEATS, $seats );

		ob_start();
		$this->handler->render_seats_column( 'clisyc_seats', $appt_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( '8', $output );
		$this->assertStringContainsString( '+3 more', $output );
	}

	// ── free_appointment_seats (via on_appointment_trashed) ─────────

	/**
	 * @test
	 */
	public function it_frees_seats_when_appointment_is_trashed() {
		global $wpdb;

		$appt_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => 'publish',
		] );

		// Insert seat bookings.
		$bookings_table = $this->schema->get_seat_bookings_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $bookings_table, [
			'venue_id'       => 100,
			'slot_id'        => 42,
			'seat_id'        => 'seat-1',
			'appointment_id' => $appt_id,
			'seat_category'  => 'general',
			'seat_price'     => 1000,
		], [ '%d', '%d', '%s', '%d', '%s', '%d' ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $bookings_table, [
			'venue_id'       => 100,
			'slot_id'        => 42,
			'seat_id'        => 'seat-2',
			'appointment_id' => $appt_id,
			'seat_category'  => 'general',
			'seat_price'     => 1000,
		], [ '%d', '%d', '%s', '%d', '%s', '%d' ] );

		update_post_meta( $appt_id, Constants::META_SELECTED_SEATS, [ 'seat-1', 'seat-2' ] );

		// Trigger the trash callback.
		$this->handler->on_appointment_trashed( $appt_id );

		// Bookings should be gone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$bookings_table} WHERE appointment_id = %d", $appt_id )
		);

		$this->assertEquals( 0, (int) $remaining );

		// Meta should be deleted.
		$meta = get_post_meta( $appt_id, Constants::META_SELECTED_SEATS, true );
		$this->assertEmpty( $meta );
	}

	/**
	 * @test
	 */
	public function it_does_not_free_seats_for_non_appointment_posts() {
		global $wpdb;

		$page_id = $this->factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
		] );

		$bookings_table = $this->schema->get_seat_bookings_table_name();

		// Insert a booking linked to a page ID (shouldn't happen but tests safety).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $bookings_table, [
			'venue_id'       => 100,
			'slot_id'        => 42,
			'seat_id'        => 'seat-1',
			'appointment_id' => $page_id,
			'seat_category'  => 'general',
			'seat_price'     => 1000,
		], [ '%d', '%d', '%s', '%d', '%s', '%d' ] );

		// Trigger — should not delete because post_type is 'page'.
		$this->handler->on_appointment_trashed( $page_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$bookings_table} WHERE appointment_id = %d", $page_id )
		);

		$this->assertEquals( 1, (int) $remaining );
	}

	// ── add_seat_pricing_to_basket ──────────────────────────────────

	/**
	 * @test
	 */
	public function it_adds_seat_pricing_line_items_to_basket() {
		// Create a venue with layout + pricing.
		$venue_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_venue',
			'post_status' => 'publish',
			'post_title'  => 'Test Venue',
		] );

		$layout = [
			'parsing_mode' => 'flat_ids',
			'total_seats'  => 2,
			'sections'     => [
				[
					'id'       => 'vip',
					'label'    => 'VIP',
					'category' => 'vip',
					'rows'     => [
						[
							'id'    => 'row1',
							'seats' => [
								[ 'id' => 'vip-row1-s1', 'category' => 'vip' ],
								[ 'id' => 'vip-row1-s2', 'category' => 'vip' ],
							],
						],
					],
				],
			],
		];

		$pricing_tiers = [
			[ 'category' => 'vip', 'label' => 'VIP', 'price' => 5000 ], // $50.00
		];

		update_post_meta( $venue_id, Constants::META_VENUE_LAYOUT, $layout );
		update_post_meta( $venue_id, Constants::META_SEAT_PRICING_TIERS, $pricing_tiers );

		// Create a service linked to the venue.
		$service_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_service',
			'post_status' => 'publish',
			'post_title'  => 'Concert',
		] );

		update_post_meta( $service_id, Constants::META_LINKED_VENUE_ID, $venue_id );

		// Build a mock WP_REST_Request.
		$request = new \WP_REST_Request( 'GET', '/clisyc/v1/price/calculate-basket' );
		$request->set_param( 'primary_id', $service_id );
		$request->set_param( 'selected_seats', wp_json_encode( [ 'vip-row1-s1', 'vip-row1-s2' ] ) );

		$result = $this->handler->add_seat_pricing_to_basket( [], 0.0, $request );

		$this->assertCount( 1, $result ); // 1 line item for 2 × VIP.
		$this->assertEquals( 'seat', $result[0]['type'] );
		$this->assertEquals( 100.0, $result[0]['price'] ); // 2 × $50.00
		$this->assertStringContainsString( '2', $result[0]['label'] );
		$this->assertStringContainsString( 'VIP', $result[0]['label'] );
	}

	/**
	 * @test
	 */
	public function it_returns_unmodified_items_when_no_seats_selected() {
		$request = new \WP_REST_Request( 'GET', '/clisyc/v1/price/calculate-basket' );
		$request->set_param( 'primary_id', 1 );
		$request->set_param( 'selected_seats', '' );

		$existing = [ [ 'label' => 'Service', 'price' => 50 ] ];
		$result   = $this->handler->add_seat_pricing_to_basket( $existing, 50.0, $request );

		$this->assertCount( 1, $result );
		$this->assertEquals( 'Service', $result[0]['label'] );
	}

	/**
	 * @test
	 */
	public function it_returns_unmodified_items_when_no_venue_linked() {
		$service_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_service',
			'post_status' => 'publish',
		] );
		// No META_LINKED_VENUE_ID set.

		$request = new \WP_REST_Request( 'GET', '/clisyc/v1/price/calculate-basket' );
		$request->set_param( 'primary_id', $service_id );
		$request->set_param( 'selected_seats', wp_json_encode( [ 'seat-1' ] ) );

		$existing = [ [ 'label' => 'Service', 'price' => 50 ] ];
		$result   = $this->handler->add_seat_pricing_to_basket( $existing, 50.0, $request );

		$this->assertCount( 1, $result );
	}

	/**
	 * @test
	 */
	public function it_groups_multiple_categories_into_separate_line_items() {
		$venue_id = $this->factory()->post->create( [
			'post_type' => 'clisyc_venue',
			'post_status' => 'publish',
		] );

		$layout = [
			'sections' => [
				[
					'id'       => 'vip',
					'category' => 'vip',
					'rows'     => [ [ 'id' => 'r1', 'seats' => [
						[ 'id' => 'vip-r1-s1', 'category' => 'vip' ],
					] ] ],
				],
				[
					'id'       => 'gen',
					'category' => 'general',
					'rows'     => [ [ 'id' => 'r2', 'seats' => [
						[ 'id' => 'gen-r2-s1', 'category' => 'general' ],
						[ 'id' => 'gen-r2-s2', 'category' => 'general' ],
					] ] ],
				],
			],
		];

		$pricing_tiers = [
			[ 'category' => 'vip', 'label' => 'VIP', 'price' => 5000 ],
			[ 'category' => 'general', 'label' => 'General', 'price' => 2000 ],
		];

		update_post_meta( $venue_id, Constants::META_VENUE_LAYOUT, $layout );
		update_post_meta( $venue_id, Constants::META_SEAT_PRICING_TIERS, $pricing_tiers );

		$service_id = $this->factory()->post->create( [ 'post_type' => 'clisyc_service', 'post_status' => 'publish' ] );
		update_post_meta( $service_id, Constants::META_LINKED_VENUE_ID, $venue_id );

		$request = new \WP_REST_Request( 'GET', '/clisyc/v1/price/calculate-basket' );
		$request->set_param( 'primary_id', $service_id );
		$request->set_param( 'selected_seats', wp_json_encode( [ 'vip-r1-s1', 'gen-r2-s1', 'gen-r2-s2' ] ) );

		$result = $this->handler->add_seat_pricing_to_basket( [], 0.0, $request );

		$this->assertCount( 2, $result ); // VIP + General line items.

		$types = array_column( $result, 'type' );
		$this->assertContains( 'seat', $types );

		$total = array_sum( array_column( $result, 'price' ) );
		$this->assertEquals( 90.0, $total ); // 1 × $50 + 2 × $20 = $90
	}
}
