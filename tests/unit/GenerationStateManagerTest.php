<?php
/**
 * File: tests/unit/GenerationStateManagerTest.php
 * Unit tests for the slot generation state manager.
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Core\Generation_State_Manager;

class GenerationStateManagerTest extends WP_UnitTestCase {

	/**
	 * @var Generation_State_Manager
	 */
	private $manager;

	public function setUp(): void {
		parent::setUp();
		$this->manager = new Generation_State_Manager();
		// Ensure clean state for each test
		delete_option( Generation_State_Manager::OPTION_KEY );
	}

	public function tearDown(): void {
		delete_option( Generation_State_Manager::OPTION_KEY );
		parent::tearDown();
	}

	/**
	 * @test
	 */
	public function it_returns_default_state_when_no_state_exists() {
		// Act
		$state = $this->manager->get_state();

		// Assert
		$this->assertIsArray( $state );
		$this->assertFalse( $state['in_progress'] );
		$this->assertEquals( 0, $state['current_post_index'] );
		$this->assertNull( $state['current_date_utc'] );
		$this->assertEquals( [ 'inserted' => 0, 'errors' => 0 ], $state['stats'] );
	}

	/**
	 * @test
	 */
	public function it_saves_and_retrieves_state() {
		// Arrange
		$post_index = 5;
		$date_utc   = '2026-03-15';
		$stats      = [ 'inserted' => 42, 'errors' => 2 ];

		// Act
		$this->manager->save_state( $post_index, $date_utc, $stats );
		$state = $this->manager->get_state();

		// Assert
		$this->assertTrue( $state['in_progress'] );
		$this->assertEquals( 5, $state['current_post_index'] );
		$this->assertEquals( '2026-03-15', $state['current_date_utc'] );
		$this->assertEquals( 42, $state['stats']['inserted'] );
		$this->assertEquals( 2, $state['stats']['errors'] );
		$this->assertArrayHasKey( 'last_updated', $state );
	}

	/**
	 * @test
	 */
	public function it_clears_state_back_to_default() {
		// Arrange — save some state first
		$this->manager->save_state( 3, '2026-02-01', [ 'inserted' => 10, 'errors' => 0 ] );
		$this->assertTrue( $this->manager->get_state()['in_progress'] );

		// Act
		$this->manager->clear_state();
		$state = $this->manager->get_state();

		// Assert
		$this->assertFalse( $state['in_progress'] );
		$this->assertEquals( 0, $state['current_post_index'] );
	}

	/**
	 * @test
	 */
	public function it_detects_stalled_when_last_updated_is_old() {
		// Arrange — save state with a timestamp from 15 minutes ago
		$stale_state = [
			'in_progress'        => true,
			'current_post_index' => 2,
			'current_date_utc'   => '2026-01-10',
			'stats'              => [ 'inserted' => 5, 'errors' => 0 ],
			'last_updated'       => time() - ( 15 * MINUTE_IN_SECONDS ), // 15 minutes ago
		];
		update_option( Generation_State_Manager::OPTION_KEY, $stale_state );

		// Act & Assert
		$this->assertTrue( $this->manager->is_stalled() );
	}

	/**
	 * @test
	 */
	public function it_does_not_detect_stalled_when_recently_updated() {
		// Arrange — save state normally (which sets last_updated to now)
		$this->manager->save_state( 1, '2026-02-01', [ 'inserted' => 3, 'errors' => 0 ] );

		// Act & Assert
		$this->assertFalse( $this->manager->is_stalled() );
	}

	/**
	 * @test
	 */
	public function it_does_not_detect_stalled_when_no_state_exists() {
		// Act & Assert — no state = no last_updated = not stalled
		$this->assertFalse( $this->manager->is_stalled() );
	}
}
