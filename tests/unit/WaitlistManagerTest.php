<?php
/**
 * File: tests/unit/WaitlistManagerTest.php
 * Unit tests for the Waitlist Manager.
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Core\Waitlist_Manager;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Constants;

class WaitlistManagerTest extends WP_UnitTestCase {

	/** @var Database_Manager */
	private $db;

	/** @var int */
	private $service_id;

	/** @var int */
	private $user_id;

	/** @var int */
	private $slot_id;

	/** @var string */
	private $future_time;

	/** @var string */
	private $future_date;

	public function setUp(): void {
		parent::setUp();

		$this->db = new Database_Manager();
		$this->db->create_tables();

		// Register post types if not already registered.
		if ( ! post_type_exists( Constants::POST_TYPE_APPOINTMENT ) ) {
			register_post_type( Constants::POST_TYPE_APPOINTMENT, [ 'public' => false ] );
		}
		if ( ! post_type_exists( 'clisyc_service' ) ) {
			register_post_type( 'clisyc_service', [ 'public' => false ] );
		}

		// Create a test service.
		$this->service_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_service',
			'post_title'  => 'Waitlist Test Service',
			'post_status' => 'publish',
		] );
		update_post_meta( $this->service_id, Constants::META_CAPACITY, 1 );

		// Create a test user.
		$this->user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		// Future time slot (+6 months to avoid gmdate filtering).
		$this->future_time = gmdate( 'Y-m-d H:i:s', strtotime( '+6 months' ) );
		$this->future_date = gmdate( 'Y-m-d', strtotime( '+6 months' ) );

		// Insert a test slot.
		$this->db->insert_slots( [
			[
				'start_time_utc' => $this->future_time,
				'end_time_utc'   => gmdate( 'Y-m-d H:i:s', strtotime( '+6 months +1 hour' ) ),
				'is_block'       => false,
				'dimensions'     => [ 'clisyc_service' => (string) $this->service_id ],
			],
		] );

		$this->slot_id = $this->db->find_slot_id_by_start_time_and_dimensions(
			$this->future_time,
			[ 'clisyc_service' => (string) $this->service_id ]
		);

		// Enable waitlist by default.
		update_option( Constants::OPTION_WAITLIST_ENABLED, true );

		$this->db->clear_all_slot_cache();
	}

	public function tearDown(): void {
		global $wpdb;

		delete_option( Constants::OPTION_WAITLIST_ENABLED );
		delete_option( Constants::OPTION_WAITLIST_MAX_SIZE );
		delete_option( Constants::OPTION_WAITLIST_AUTO_PROMOTE );

		// IMPORTANT: Call parent::tearDown() FIRST so WP_UnitTestCase rolls back
		// the transaction (reverting wp_insert_post calls from add_to_waitlist).
		// Then clean custom tables with DELETE (not TRUNCATE, which causes implicit commit).
		parent::tearDown();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$this->db->get_slots_table_name()}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$this->db->get_bookings_table_name()}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$this->db->get_dimensions_table_name()}" );
	}

	// ── Helper ────────────────────────────────────────────────────────

	/**
	 * Add a user to the waitlist for the default test slot.
	 *
	 * @param int $user_id User ID.
	 * @return int|\WP_Error Appointment ID.
	 */
	private function add_user_to_waitlist( int $user_id ) {
		return Waitlist_Manager::add_to_waitlist( [
			'user_id'          => $user_id,
			'slot_id'          => $this->slot_id,
			'time_slot'        => $this->future_time,
			'appointment_date' => $this->future_date,
			'dimensions'       => [ 'clisyc_service' => $this->service_id ],
			'duration_minutes' => 60,
		] );
	}

	// ── Option Helper Tests ───────────────────────────────────────────

	/**
	 * @test
	 * Waitlist should be disabled by default when option is not set.
	 */
	public function it_returns_disabled_when_option_not_set() {
		delete_option( Constants::OPTION_WAITLIST_ENABLED );
		$this->assertFalse( Waitlist_Manager::is_enabled() );
	}

	/**
	 * @test
	 * Waitlist should be enabled when option is set to true.
	 */
	public function it_returns_enabled_when_option_set() {
		update_option( Constants::OPTION_WAITLIST_ENABLED, true );
		$this->assertTrue( Waitlist_Manager::is_enabled() );
	}

	/**
	 * @test
	 * Max size should default to 0 (unlimited) when not set.
	 */
	public function it_returns_zero_max_size_as_unlimited() {
		delete_option( Constants::OPTION_WAITLIST_MAX_SIZE );
		$this->assertSame( 0, Waitlist_Manager::get_max_size() );
	}

	/**
	 * @test
	 * Max size should return the configured value.
	 */
	public function it_returns_custom_max_size() {
		update_option( Constants::OPTION_WAITLIST_MAX_SIZE, 3 );
		$this->assertSame( 3, Waitlist_Manager::get_max_size() );
	}

	// ── Room Checks ──────────────────────────────────────────────────

	/**
	 * @test
	 * has_waitlist_room should return true when under max size.
	 */
	public function it_reports_room_when_under_max() {
		update_option( Constants::OPTION_WAITLIST_MAX_SIZE, 3 );

		// Add one entry.
		$this->add_user_to_waitlist( $this->user_id );

		$this->assertTrue( Waitlist_Manager::has_waitlist_room( $this->slot_id ) );
	}

	/**
	 * @test
	 * has_waitlist_room should return false when at max size.
	 */
	public function it_reports_no_room_when_at_max() {
		update_option( Constants::OPTION_WAITLIST_MAX_SIZE, 1 );

		$this->add_user_to_waitlist( $this->user_id );

		$this->assertFalse( Waitlist_Manager::has_waitlist_room( $this->slot_id ) );
	}

	/**
	 * @test
	 * When max size is 0 (unlimited), has_waitlist_room should always return true.
	 */
	public function it_reports_room_when_max_is_zero() {
		update_option( Constants::OPTION_WAITLIST_MAX_SIZE, 0 );

		// Add several entries.
		$this->add_user_to_waitlist( $this->user_id );
		$user2 = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->add_user_to_waitlist( $user2 );

		$this->assertTrue( Waitlist_Manager::has_waitlist_room( $this->slot_id ) );
	}

	// ── Add to Waitlist ──────────────────────────────────────────────

	/**
	 * @test
	 * add_to_waitlist should create an appointment with waitlisted status and correct meta.
	 */
	public function it_adds_client_to_waitlist() {
		$result = $this->add_user_to_waitlist( $this->user_id );

		$this->assertIsInt( $result, 'add_to_waitlist should return an appointment ID.' );

		// Verify post status.
		$post = get_post( $result );
		$this->assertSame( Constants::STATUS_WAITLISTED, $post->post_status );
		$this->assertEquals( $this->user_id, (int) $post->post_author );

		// Verify waitlist meta.
		$this->assertEquals( 1, (int) get_post_meta( $result, Constants::META_WAITLIST_POSITION, true ) );
		$this->assertEquals( $this->slot_id, (int) get_post_meta( $result, Constants::META_WAITLIST_SLOT_ID, true ) );
		$this->assertNotEmpty( get_post_meta( $result, Constants::META_WAITLIST_JOINED_AT, true ) );

		// Verify standard appointment meta.
		$this->assertSame( $this->future_time, get_post_meta( $result, Constants::META_TIME_SLOT, true ) );
		$this->assertSame( $this->future_date, get_post_meta( $result, Constants::META_APPOINTMENT_DATE, true ) );
	}

	/**
	 * @test
	 * Multiple users added to the waitlist should get sequential positions.
	 */
	public function it_assigns_sequential_positions() {
		$user2 = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$user3 = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$id1 = $this->add_user_to_waitlist( $this->user_id );
		$id2 = $this->add_user_to_waitlist( $user2 );
		$id3 = $this->add_user_to_waitlist( $user3 );

		$this->assertEquals( 1, (int) get_post_meta( $id1, Constants::META_WAITLIST_POSITION, true ) );
		$this->assertEquals( 2, (int) get_post_meta( $id2, Constants::META_WAITLIST_POSITION, true ) );
		$this->assertEquals( 3, (int) get_post_meta( $id3, Constants::META_WAITLIST_POSITION, true ) );

		// Also verify get_waitlist_count.
		$this->assertSame( 3, Waitlist_Manager::get_waitlist_count( $this->slot_id ) );
	}

	/**
	 * @test
	 * Adding the same user twice to the same slot should return a WP_Error.
	 */
	public function it_returns_error_when_already_waitlisted() {
		$this->add_user_to_waitlist( $this->user_id );

		$result = $this->add_user_to_waitlist( $this->user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'already_waitlisted', $result->get_error_code() );
	}

	// ── Leave Waitlist ───────────────────────────────────────────────

	/**
	 * @test
	 * Leaving the waitlist should trash the appointment and recalculate positions.
	 */
	public function it_leaves_waitlist_and_recalculates_positions() {
		$user2 = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$user3 = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$id1 = $this->add_user_to_waitlist( $this->user_id );
		$id2 = $this->add_user_to_waitlist( $user2 );
		$id3 = $this->add_user_to_waitlist( $user3 );

		// Remove the middle entry.
		$result = Waitlist_Manager::leave_waitlist( $id2 );

		$this->assertTrue( $result['success'] );

		// Verify the removed post is trashed.
		$this->assertSame( 'trash', get_post_status( $id2 ) );

		// Remaining entries should have recalculated positions: 1, 2.
		$this->assertEquals( 1, (int) get_post_meta( $id1, Constants::META_WAITLIST_POSITION, true ) );
		$this->assertEquals( 2, (int) get_post_meta( $id3, Constants::META_WAITLIST_POSITION, true ) );

		// Count should be 2.
		$this->assertSame( 2, Waitlist_Manager::get_waitlist_count( $this->slot_id ) );
	}

	// ── Validation ───────────────────────────────────────────────────

	/**
	 * @test
	 * Missing required fields should return WP_Error.
	 */
	public function it_returns_error_for_missing_data() {
		$result = Waitlist_Manager::add_to_waitlist( [
			'user_id'   => 0,
			'slot_id'   => 0,
			'time_slot' => '',
		] );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_data', $result->get_error_code() );
	}
}
