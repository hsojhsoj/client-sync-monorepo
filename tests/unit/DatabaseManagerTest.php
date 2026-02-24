<?php
/**
 * File: tests/unit/DatabaseManagerTest.php
 * Integration tests for the central Database_Manager.
 *
 * Tests slot CRUD, dimension matching, capacity logic, and date utilities.
 * Requires a WordPress test database (WP_UnitTestCase).
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Core\Database_Manager;

class DatabaseManagerTest extends WP_UnitTestCase {

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
		$this->db = new Database_Manager();

		// Ensure tables exist (bootstrap already activates the plugin, but be safe)
		$this->db->create_tables();

		// Create a test service
		$this->service_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_service',
			'post_title'  => 'DB Manager Test Service',
			'post_status' => 'publish',
		] );
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
	public function it_creates_all_custom_tables() {
		global $wpdb;

		$tables = [
			$this->db->get_slots_table_name(),
			$this->db->get_dimensions_table_name(),
			$this->db->get_relationships_table_name(),
			$this->db->get_bookings_table_name(),
			$this->db->get_graph_nodes_table_name(),
			$this->db->get_audit_logs_table_name(),
		];

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertEquals( $table, $exists, "Table $table should exist." );
		}
	}

	/**
	 * @test
	 */
	public function it_inserts_slots_with_dimensions() {
		// Arrange
		$slots = [
			[
				'start_time_utc' => '2026-03-01 10:00:00',
				'end_time_utc'   => '2026-03-01 11:00:00',
				'is_block'       => false,
				'dimensions'     => [
					'clisyc_service' => (string) $this->service_id,
				],
			],
			[
				'start_time_utc' => '2026-03-01 11:00:00',
				'end_time_utc'   => '2026-03-01 12:00:00',
				'is_block'       => false,
				'dimensions'     => [
					'clisyc_service' => (string) $this->service_id,
				],
			],
		];

		// Act
		$stats = $this->db->insert_slots( $slots );

		// Assert
		$this->assertEquals( 2, $stats['inserted'] );
		$this->assertEquals( 0, $stats['errors'] );
	}

	/**
	 * @test
	 */
	public function it_queries_slots_by_date_range() {
		// Arrange — insert 3 slots across 2 days
		$this->db->insert_slots( [
			[
				'start_time_utc' => '2026-03-01 09:00:00',
				'end_time_utc'   => '2026-03-01 10:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
			[
				'start_time_utc' => '2026-03-01 14:00:00',
				'end_time_utc'   => '2026-03-01 15:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
			[
				'start_time_utc' => '2026-03-02 09:00:00',
				'end_time_utc'   => '2026-03-02 10:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
		] );

		// Act — query only the first day
		// Clear transient cache so query hits DB
		$this->db->clear_all_slot_cache();

		$results = $this->db->query_slots(
			'2026-03-01 00:00:00',
			'2026-03-02 00:00:00',
			[ 'clisyc_service' => (string) $this->service_id ]
		);

		// Assert — should get 2 slots from March 1st
		$this->assertCount( 2, $results );
	}

	/**
	 * @test
	 */
	public function it_deletes_slots_in_date_range() {
		// Arrange — insert 2 slots on different days
		$this->db->insert_slots( [
			[
				'start_time_utc' => '2026-03-01 10:00:00',
				'end_time_utc'   => '2026-03-01 11:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
			[
				'start_time_utc' => '2026-03-05 10:00:00',
				'end_time_utc'   => '2026-03-05 11:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
		] );

		// Act — delete only the first day's slot
		$deleted = $this->db->delete_slots_for_date_range(
			'2026-03-01 00:00:00',
			'2026-03-02 00:00:00',
			[ 'clisyc_service' => (string) $this->service_id ]
		);

		// Assert
		$this->assertEquals( 1, $deleted );

		// Verify the other slot still exists
		$this->db->clear_all_slot_cache();
		$remaining = $this->db->query_slots(
			'2026-03-01 00:00:00',
			'2026-03-31 00:00:00',
			[ 'clisyc_service' => (string) $this->service_id ]
		);
		$this->assertCount( 1, $remaining );
	}

	/**
	 * @test
	 */
	public function it_finds_slot_id_by_start_time_and_dimensions() {
		// Arrange
		$this->db->insert_slots( [
			[
				'start_time_utc' => '2026-04-01 09:00:00',
				'end_time_utc'   => '2026-04-01 10:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
		] );

		// Act
		$slot_id = $this->db->find_slot_id_by_start_time_and_dimensions(
			'2026-04-01 09:00:00',
			[ 'clisyc_service' => (string) $this->service_id ]
		);

		// Assert
		$this->assertNotNull( $slot_id );
		$this->assertIsInt( $slot_id );
		$this->assertGreaterThan( 0, $slot_id );
	}

	/**
	 * @test
	 */
	public function it_returns_null_for_nonexistent_slot_lookup() {
		// Act — look up a slot that doesn't exist
		$slot_id = $this->db->find_slot_id_by_start_time_and_dimensions(
			'2099-12-31 23:59:59',
			[ 'clisyc_service' => '99999' ]
		);

		// Assert
		$this->assertNull( $slot_id );
	}

	/**
	 * @test
	 */
	public function it_returns_distinct_available_dates() {
		// Arrange — insert available slots on 3 different dates
		$this->db->insert_slots( [
			[
				'start_time_utc' => gmdate( 'Y-m-d', strtotime( '+1 day' ) ) . ' 09:00:00',
				'end_time_utc'   => gmdate( 'Y-m-d', strtotime( '+1 day' ) ) . ' 10:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
			[
				'start_time_utc' => gmdate( 'Y-m-d', strtotime( '+1 day' ) ) . ' 14:00:00',
				'end_time_utc'   => gmdate( 'Y-m-d', strtotime( '+1 day' ) ) . ' 15:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
			[
				'start_time_utc' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ) . ' 09:00:00',
				'end_time_utc'   => gmdate( 'Y-m-d', strtotime( '+3 days' ) ) . ' 10:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
		] );

		// Act
		$this->db->clear_all_slot_cache();
		$dates = $this->db->get_distinct_available_slot_dates();

		// Assert — should get 2 unique dates (not 3, since two slots share a date)
		$this->assertIsArray( $dates );
		$this->assertCount( 2, $dates );
		$this->assertEquals( gmdate( 'Y-m-d', strtotime( '+1 day' ) ), $dates[0] );
		$this->assertEquals( gmdate( 'Y-m-d', strtotime( '+3 days' ) ), $dates[1] );
	}

	/**
	 * @test
	 */
	public function it_skips_slots_with_missing_start_time() {
		// Arrange — one valid slot, one invalid (missing start_time_utc)
		$stats = $this->db->insert_slots( [
			[
				'start_time_utc' => '2026-03-01 10:00:00',
				'end_time_utc'   => '2026-03-01 11:00:00',
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
			[
				// Missing start_time_utc
				'end_time_utc' => '2026-03-01 12:00:00',
				'is_block'     => false,
				'dimensions'   => [ 'clisyc_service' => (string) $this->service_id ],
			],
		] );

		// Assert
		$this->assertEquals( 1, $stats['inserted'] );
		$this->assertEquals( 1, $stats['errors'] );
	}

	/**
	 * @test
	 */
	public function it_returns_empty_stats_for_empty_input() {
		// Act
		$stats = $this->db->insert_slots( [] );

		// Assert
		$this->assertEquals( 0, $stats['inserted'] );
		$this->assertEquals( 0, $stats['errors'] );
	}
}
