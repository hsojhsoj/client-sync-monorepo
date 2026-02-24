<?php
/** 
 * File: tests/unit/CancellationManagerTest.php 
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Core\Cancellation_Manager;
use DependentMedia\ClientSync\Core\Database_Manager;

class CancellationManagerTest extends WP_UnitTestCase {

    private $user_id;
    private $appointment_id;
    private $service_id;

    public function setUp(): void {
        parent::setUp();
        $this->user_id = $this->factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($this->user_id);
    }

    private function create_test_appointment($time_utc_str, $service_id = 0) {
        if ( ! $this->service_id ) {
            $this->service_id = $this->factory()->post->create([
                'post_type' => 'clisyc_service',
                'post_title' => 'Test Service for Cancellation',
            ]);
            update_post_meta($this->service_id, '_clisyc_capacity', 1);
        }

        return $this->factory()->post->create([
            'post_type' => 'clisyc_appointment',
            'post_author' => $this->user_id,
            'post_status' => 'publish',
            'meta_input' => [
                \clisyc_TIME_SLOT => $time_utc_str, // Use the global constant
                '_clisyc_appointment_duration' => 60,
                '_clisyc_slot_dimensions' => ['clisyc_service' => $this->service_id], // Also update this to be consistent
            ]
        ]);
    }
    
    /**
     * @test
     */
    public function it_allows_cancellation_when_conditions_are_met() {
        // Arrange
        update_option('clisyc_enable_self_service', true);
        update_option('clisyc_cancellation_cutoff_value', 24);
        update_option('clisyc_cancellation_cutoff_unit', 'hours');
        
        $future_time = gmdate('Y-m-d H:i:s', strtotime('+48 hours'));
        $this->appointment_id = $this->create_test_appointment($future_time);
        
        // Act
        $manager = new Cancellation_Manager($this->appointment_id);
        $result = $manager->can_be_managed();

        // Assert
        $this->assertTrue($result);
    }
    
    /**
     * @test
     */
    public function it_prevents_cancellation_when_self_service_is_disabled() {
        // Arrange
        update_option('clisyc_enable_self_service', false);
        $future_time = gmdate('Y-m-d H:i:s', strtotime('+48 hours'));
        $this->appointment_id = $this->create_test_appointment($future_time);
        
        // Act
        $manager = new Cancellation_Manager($this->appointment_id);
        $result = $manager->can_be_managed();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function it_prevents_cancellation_when_within_the_cutoff_period() {
        // Arrange
        update_option('clisyc_enable_self_service', true);
        update_option('clisyc_cancellation_cutoff_value', 24);
        update_option('clisyc_cancellation_cutoff_unit', 'hours');

        $too_soon_time = gmdate('Y-m-d H:i:s', strtotime('+12 hours'));
        $this->appointment_id = $this->create_test_appointment($too_soon_time);
        
        // Act
        $manager = new Cancellation_Manager($this->appointment_id);
        $result = $manager->can_be_managed();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function it_prevents_cancellation_for_a_past_appointment() {
        // Arrange
        update_option('clisyc_enable_self_service', true);
        $past_time = gmdate('Y-m-d H:i:s', strtotime('-2 hours'));
        $this->appointment_id = $this->create_test_appointment($past_time);
        
        // Act
        $manager = new Cancellation_Manager($this->appointment_id);
        $result = $manager->can_be_managed();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function it_trashes_appointment_and_recreates_slot_on_cancellation() {
        // Arrange
        // 1. Set up conditions for a valid cancellation.
        update_option('clisyc_enable_self_service', true);
        update_option('clisyc_cancellation_cutoff_value', 24);
        update_option('clisyc_cancellation_cutoff_unit', 'hours');
        
        $future_time_utc = gmdate('Y-m-d H:i:s', strtotime('+48 hours'));
        $this->appointment_id = $this->create_test_appointment($future_time_utc);

        // 2. Instantiate the class to be tested.
        $manager = new Cancellation_Manager($this->appointment_id);
        $db_manager = new Database_Manager();

        // Act
        // 3. Perform the cancellation.
        $result = $manager->process_cancellation();

        // Assert
        // 4. Verify the operation was reported as successful.
        $this->assertTrue($result['success']);

        // 5. Verify the appointment post is now in the trash.
        $this->assertEquals('trash', get_post_status($this->appointment_id));
        
        // 6. Verify the corresponding time slot was recreated in the database.
        $recreated_slots = $db_manager->query_slots($future_time_utc, gmdate('Y-m-d H:i:s', strtotime($future_time_utc) + 1));
        
        $this->assertCount(1, $recreated_slots, 'Expected exactly one slot to be recreated.');
        $this->assertEquals($future_time_utc, $recreated_slots[0]['start_time_utc']);
        $this->assertFalse($recreated_slots[0]['is_block']);
    }
}