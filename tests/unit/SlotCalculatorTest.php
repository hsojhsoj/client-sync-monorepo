<?php
/**
 * File: tests/unit/SlotCalculatorTest.php
 * Unit tests for the Slot Calculator Service.
 *
 * @package     ClientSyncPro\Tests\Unit
 * @author      The Client Sync Pro Team
 * @since       1.0.0
 */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Utility\SlotCalculator;

class SlotCalculatorTest extends WP_UnitTestCase {

    /**
     * Set the timezone and create plugin tables.
     */
    public function setUp(): void {
        parent::setUp();
        
        // Manually run the plugin's activation hook to create our custom tables.
        if ( class_exists( '\DependentMedia\ClientSync\Plugin' ) ) {
            $plugin_instance = new \DependentMedia\ClientSync\Plugin();
            $plugin_instance->activate();
        }
        
        // Set a consistent timezone for predictable results.
        update_option('timezone_string', 'America/New_York');
    }

    /**
     * @test
     */
    public function it_calculates_a_basic_set_of_slots_with_no_break() {
        // Arrange: Define the inputs
        $workday_start = '09:00';
        $workday_end = '11:00';
        $duration = 60; // 60 minutes

        // Act: Call the method we are testing
        $slots = SlotCalculator::calculate_slots($workday_start, $workday_end, $duration);

        // Assert: Check if the output is what we expect
        $expected = [
            ['start' => '09:00', 'end' => '10:00'],
            ['start' => '10:00', 'end' => '11:00'],
        ];

        $this->assertIsArray($slots);
        $this->assertCount(2, $slots);
        $this->assertEquals($expected, $slots);
    }

    /**
     * @test
     */
    public function it_correctly_accounts_for_a_lunch_break() {
        // Arrange
        $workday_start = '09:00';
        $workday_end = '13:00';
        $duration = 60;
        $break_start = '11:00';
        $break_end = '12:00';

        // Act
        $slots = SlotCalculator::calculate_slots($workday_start, $workday_end, $duration, $break_start, $break_end);
        
        // Assert
        $expected = [
            ['start' => '09:00', 'end' => '10:00'],
            ['start' => '10:00', 'end' => '11:00'],
            ['start' => '12:00', 'end' => '13:00'],
        ];

        $this->assertCount(3, $slots);
        $this->assertEquals($expected, $slots);
    }
    
    /**
     * @test
     */
    public function it_calculates_slots_with_padding() {
        // Arrange
        $workday_start = '10:00';
        $workday_end = '12:00';
        $duration = 20;
        $padding = 10; // 10 minute padding after each slot

        // Act
        $slots = SlotCalculator::calculate_slots($workday_start, $workday_end, $duration, '', '', 0, 0, $padding);
        
        // Assert
        // 10:00 - 10:20 (next starts at 10:30)
        // 10:30 - 10:50 (next starts at 11:00)
        // 11:00 - 11:20 (next starts at 11:30)
        // 11:30 - 11:50 (next starts at 12:00, which is the end)
        $expected = [
            ['start' => '10:00', 'end' => '10:20'],
            ['start' => '10:30', 'end' => '10:50'],
            ['start' => '11:00', 'end' => '11:20'],
            ['start' => '11:30', 'end' => '11:50'],
        ];
        
        $this->assertCount(4, $slots);
        $this->assertEquals($expected, $slots);
    }

    /**
     * @test
     */
    public function it_returns_empty_array_if_end_is_before_start() {
        // Arrange
        $workday_start = '17:00';
        $workday_end = '09:00';
        $duration = 60;

        // Act
        $slots = SlotCalculator::calculate_slots($workday_start, $workday_end, $duration);
        
        // Assert
        $this->assertIsArray($slots);
        $this->assertEmpty($slots);
    }

    /**
     * @test
     */
    public function it_returns_empty_array_if_break_covers_workday() {
        // Arrange
        $workday_start = '09:00';
        $workday_end = '17:00';
        $duration = 60;
        $break_start = '09:00';
        $break_end = '17:00';

        // Act
        $slots = SlotCalculator::calculate_slots($workday_start, $workday_end, $duration, $break_start, $break_end);
        
        // Assert
        $this->assertIsArray($slots);
        $this->assertEmpty($slots);
    }
}