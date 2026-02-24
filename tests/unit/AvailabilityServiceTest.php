<?php
/**
 * File: tests/unit/AvailabilityServiceTest.php
 * Integration tests for the Availability Service.
 *
 * Tests the slot processing pipeline: merging raw slots with capacity checks
 * and global block overlays.
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Services\Availability_Service;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Constants;

class AvailabilityServiceTest extends WP_UnitTestCase {

	/**
	 * @var Availability_Service
	 */
	private $availability;

	/**
	 * @var Database_Manager
	 */
	private $db;

	/**
	 * @var int Test service post ID.
	 */
	private $service_id;

	public function setUp(): void {
		parent::setUp();

		$this->db           = new Database_Manager();
		$this->availability = new Availability_Service();

		// Ensure tables exist
		$this->db->create_tables();

		// Create a test service with capacity = 1 (default)
		$this->service_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_service',
			'post_title'  => 'Availability Test Service',
			'post_status' => 'publish',
		] );
		update_post_meta( $this->service_id, Constants::META_CAPACITY, 1 );
	}

	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$this->db->get_slots_table_name()}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$this->db->get_dimensions_table_name()}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$this->db->get_bookings_table_name()}" );
		parent::tearDown();
	}

	/**
	 * @test
	 */
	public function it_returns_available_slots() {
		// Arrange
		$this->db->insert_slots( [
			[
				'start_time_utc' => '2026-04-01 09:00:00',
				'end_time_utc'   => '2026-04-01 10:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
		] );
		$this->db->clear_all_slot_cache();

		// Act
		$processed = $this->availability->get_processed_slots(
			'2026-04-01 00:00:00',
			'2026-04-02 00:00:00',
			[ 'clisyc_service' => (string) $this->service_id ],
			'clisyc_service'
		);

		// Assert
		$this->assertCount( 1, $processed );
		$this->assertEquals( 1, $processed[0]['capacity'] );
		$this->assertEquals( 1, $processed[0]['spots_left'] );
	}

	/**
	 * @test
	 */
	public function it_reduces_spots_when_booking_exists() {
		global $wpdb;

		// Arrange — insert a slot
		$this->db->insert_slots( [
			[
				'start_time_utc' => '2026-04-01 09:00:00',
				'end_time_utc'   => '2026-04-01 10:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
		] );

		// Set capacity to 2 so one booking doesn't fully book it
		update_post_meta( $this->service_id, Constants::META_CAPACITY, 2 );

		// Find the slot_id we just inserted
		$slot_id = $this->db->find_slot_id_by_start_time_and_dimensions(
			'2026-04-01 09:00:00',
			[ 'clisyc_service' => (string) $this->service_id ]
		);

		// Increment booking_count on the slot directly
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->db->get_slots_table_name(),
			[ 'booking_count' => 1 ],
			[ 'slot_id' => $slot_id ],
			[ '%d' ],
			[ '%d' ]
		);

		$this->db->clear_all_slot_cache();

		// Act
		$processed = $this->availability->get_processed_slots(
			'2026-04-01 00:00:00',
			'2026-04-02 00:00:00',
			[ 'clisyc_service' => (string) $this->service_id ],
			'clisyc_service'
		);

		// Assert — capacity 2, 1 booked → spots_left = 1
		$this->assertCount( 1, $processed );
		$this->assertEquals( 2, $processed[0]['capacity'] );
		$this->assertEquals( 1, $processed[0]['spots_left'] );
	}

	/**
	 * @test
	 */
	public function it_shows_zero_spots_when_fully_booked() {
		global $wpdb;

		// Arrange — insert a slot with capacity = 1
		$this->db->insert_slots( [
			[
				'start_time_utc' => '2026-04-01 09:00:00',
				'end_time_utc'   => '2026-04-01 10:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
		] );
		update_post_meta( $this->service_id, Constants::META_CAPACITY, 1 );

		// Find and fully book the slot
		$slot_id = $this->db->find_slot_id_by_start_time_and_dimensions(
			'2026-04-01 09:00:00',
			[ 'clisyc_service' => (string) $this->service_id ]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->db->get_slots_table_name(),
			[ 'booking_count' => 1 ],
			[ 'slot_id' => $slot_id ],
			[ '%d' ],
			[ '%d' ]
		);

		$this->db->clear_all_slot_cache();

		// Act
		$processed = $this->availability->get_processed_slots(
			'2026-04-01 00:00:00',
			'2026-04-02 00:00:00',
			[ 'clisyc_service' => (string) $this->service_id ],
			'clisyc_service'
		);

		// Assert — fully booked
		$this->assertCount( 1, $processed );
		$this->assertEquals( 0, $processed[0]['spots_left'] );
	}

	/**
	 * @test
	 */
	public function it_returns_empty_for_date_range_with_no_slots() {
		// Act
		$this->db->clear_all_slot_cache();
		$processed = $this->availability->get_processed_slots(
			'2099-01-01 00:00:00',
			'2099-01-02 00:00:00',
			[ 'clisyc_service' => (string) $this->service_id ],
			'clisyc_service'
		);

		// Assert
		$this->assertIsArray( $processed );
		$this->assertEmpty( $processed );
	}

	/**
	 * @test
	 */
	public function it_passes_through_blocked_slots() {
		// Arrange — insert a blocked slot
		$this->db->insert_slots( [
			[
				'start_time_utc' => '2026-04-01 09:00:00',
				'end_time_utc'   => '2026-04-01 10:00:00',
				'is_block'       => true,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
		] );
		$this->db->clear_all_slot_cache();

		// Act
		$processed = $this->availability->get_processed_slots(
			'2026-04-01 00:00:00',
			'2026-04-02 00:00:00',
			[ 'clisyc_service' => (string) $this->service_id ],
			'clisyc_service'
		);

		// Assert — blocked slots pass through with capacity = 0
		$this->assertCount( 1, $processed );
		$this->assertEquals( 0, $processed[0]['capacity'] );
		$this->assertEquals( 0, $processed[0]['spots_left'] );
	}

	/**
	 * @test
	 */
	public function it_processes_multiple_slots_independently() {
		global $wpdb;

		// Arrange — 2 available slots
		$this->db->insert_slots( [
			[
				'start_time_utc' => '2026-04-01 09:00:00',
				'end_time_utc'   => '2026-04-01 10:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
			[
				'start_time_utc' => '2026-04-01 10:00:00',
				'end_time_utc'   => '2026-04-01 11:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
		] );

		// Book the first slot
		$slot_id = $this->db->find_slot_id_by_start_time_and_dimensions(
			'2026-04-01 09:00:00',
			[ 'clisyc_service' => (string) $this->service_id ]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->db->get_slots_table_name(),
			[ 'booking_count' => 1 ],
			[ 'slot_id' => $slot_id ],
			[ '%d' ],
			[ '%d' ]
		);

		$this->db->clear_all_slot_cache();

		// Act
		$processed = $this->availability->get_processed_slots(
			'2026-04-01 00:00:00',
			'2026-04-02 00:00:00',
			[ 'clisyc_service' => (string) $this->service_id ],
			'clisyc_service'
		);

		// Assert — 2 slots, first fully booked, second available
		$this->assertCount( 2, $processed );

		// Sort by start time to ensure predictable order
		usort( $processed, fn( $a, $b ) => strcmp( $a['start_time_utc'] ?? $a['start_time'], $b['start_time_utc'] ?? $b['start_time'] ) );

		$this->assertEquals( 0, $processed[0]['spots_left'], 'First slot should be fully booked.' );
		$this->assertEquals( 1, $processed[1]['spots_left'], 'Second slot should be available.' );
	}
}
