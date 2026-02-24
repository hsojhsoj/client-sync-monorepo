<?php
/**
 * File: tests/unit/CapacityResolverTest.php
 * Unit tests for the Capacity Resolver.
 *
 * Tests the per-combination capacity resolution logic that takes the minimum
 * capacity across all dimensions in a slot combination.
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Core\Capacity_Resolver;
use DependentMedia\ClientSync\Constants;

class CapacityResolverTest extends WP_UnitTestCase {

	/** @var int */
	private $service_id;

	/** @var int */
	private $room_id;

	/** @var int */
	private $practitioner_id;

	public function setUp(): void {
		parent::setUp();

		// Register post types if not already registered.
		if ( ! post_type_exists( 'clisyc_service' ) ) {
			register_post_type( 'clisyc_service', [ 'public' => false ] );
		}
		if ( ! post_type_exists( 'clisyc_room' ) ) {
			register_post_type( 'clisyc_room', [ 'public' => false ] );
		}
		if ( ! post_type_exists( 'clisyc_practitioner' ) ) {
			register_post_type( 'clisyc_practitioner', [ 'public' => false ] );
		}

		// Create a service with capacity 10.
		$this->service_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_service',
			'post_title'  => 'Capacity Test Service',
			'post_status' => 'publish',
		] );
		update_post_meta( $this->service_id, Constants::META_CAPACITY, 10 );

		// Create a room with capacity 4.
		$this->room_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_room',
			'post_title'  => 'Small Meeting Room',
			'post_status' => 'publish',
		] );
		update_post_meta( $this->room_id, Constants::META_CAPACITY, 4 );

		// Create a practitioner with NO capacity set.
		$this->practitioner_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_practitioner',
			'post_title'  => 'Dr. Test',
			'post_status' => 'publish',
		] );
	}

	// ── Core Resolution Logic ────────────────────────────────────────

	/**
	 * @test
	 * resolve() should return 1 when no dimensions are provided.
	 */
	public function it_returns_1_when_no_dimensions() {
		$this->assertSame( 1, Capacity_Resolver::resolve( [] ) );
	}

	/**
	 * @test
	 * resolve() should return the primary dimension's capacity for single-dimension setups.
	 */
	public function it_returns_primary_capacity_for_single_dimension() {
		$result = Capacity_Resolver::resolve( [
			'clisyc_service' => $this->service_id,
		] );

		$this->assertSame( 10, $result );
	}

	/**
	 * @test
	 * resolve() should return the minimum capacity across multiple dimensions.
	 */
	public function it_returns_min_across_multiple_dimensions() {
		$result = Capacity_Resolver::resolve( [
			'clisyc_service' => $this->service_id, // capacity 10
			'clisyc_room'    => $this->room_id,    // capacity 4
		] );

		$this->assertSame( 4, $result );
	}

	/**
	 * @test
	 * resolve() should ignore dimensions that have no capacity set.
	 */
	public function it_ignores_dimensions_without_capacity() {
		$result = Capacity_Resolver::resolve( [
			'clisyc_service'      => $this->service_id,      // capacity 10
			'clisyc_practitioner' => $this->practitioner_id, // no capacity
		] );

		$this->assertSame( 10, $result );
	}

	/**
	 * @test
	 * resolve() should return 1 when no dimension has capacity set.
	 */
	public function it_returns_1_when_no_capacity_set() {
		$result = Capacity_Resolver::resolve( [
			'clisyc_practitioner' => $this->practitioner_id, // no capacity
		] );

		$this->assertSame( 1, $result );
	}

	/**
	 * @test
	 * resolve() should ignore dimension IDs of 0 or less.
	 */
	public function it_ignores_zero_dim_ids() {
		$result = Capacity_Resolver::resolve( [
			'clisyc_service' => 0,
		] );

		$this->assertSame( 1, $result );
	}

	/**
	 * @test
	 * Single-dimension setup should behave identically to old primary-only lookup.
	 */
	public function it_handles_single_dimension_backward_compat() {
		// Set capacity to 5 on the service.
		update_post_meta( $this->service_id, Constants::META_CAPACITY, 5 );

		// Old way: (int) get_post_meta( $id, META_CAPACITY, true ) ?: 1
		$old_result = (int) get_post_meta( $this->service_id, Constants::META_CAPACITY, true ) ?: 1;

		// New way: Capacity_Resolver::resolve()
		$new_result = Capacity_Resolver::resolve( [
			'clisyc_service' => $this->service_id,
		] );

		$this->assertSame( $old_result, $new_result, 'Backward compatibility: resolve() should match primary-only lookup.' );
	}

	/**
	 * @test
	 * Three-dimension combination should use the smallest capacity.
	 */
	public function it_resolves_three_dimension_combination() {
		// Set capacity on room to 2 (smallest).
		update_post_meta( $this->room_id, Constants::META_CAPACITY, 2 );

		// Set capacity on practitioner to 6.
		update_post_meta( $this->practitioner_id, Constants::META_CAPACITY, 6 );

		$result = Capacity_Resolver::resolve( [
			'clisyc_service'      => $this->service_id,      // capacity 10
			'clisyc_room'         => $this->room_id,         // capacity 2
			'clisyc_practitioner' => $this->practitioner_id, // capacity 6
		] );

		$this->assertSame( 2, $result );
	}
}
