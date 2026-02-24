<?php
/** 
 * File: tests/unit/CalendarDataProviderTest.php 
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Services\CalendarDataProvider;

class CalendarDataProviderTest extends WP_UnitTestCase {

    private $db_manager;
    private $service_id;
    // Use dates far in the future so the slot is never considered "past".
    private $slot_time_utc = '2030-06-15 14:00:00';
    private $slot_end_time_utc = '2030-06-15 15:00:00';

    public function setUp(): void {
        parent::setUp();

        $this->db_manager = new Database_Manager();

        // Create a test service
        $this->service_id = $this->factory()->post->create([
            'post_type' => 'clisyc_service',
            'post_title' => 'Test Calendar Service',
        ]);

        // Insert one available slot into the database
        $this->db_manager->insert_slots([
            [
                'start_time_utc' => $this->slot_time_utc,
                'end_time_utc'   => $this->slot_end_time_utc,
                'is_block'       => false,
                'dimensions'     => ['clisyc_service' => $this->service_id],
            ]
        ]);
    }

    /**
     * @test
     */
    public function it_returns_an_available_slot_as_a_calendar_event() {
        // Arrange: Set up the date range for the data fetch
        $fetch_info = [
            'start' => new \DateTime('2030-06-15 00:00:00', new \DateTimeZone('UTC')),
            'end'   => new \DateTime('2030-06-15 23:59:59', new \DateTimeZone('UTC')),
        ];
        $dimensions_to_query = ['clisyc_service' => $this->service_id];

        // Act: Instantiate the service and get the calendar data
        $provider = new CalendarDataProvider($this->db_manager);
        $calendar_data = $provider->get_calendar_data(
            $fetch_info,
            false, // is_admin_editable_context
            'default', // view_context
            null, // user_id
            false, // hide_booked
            'none', // overview_mode
            $dimensions_to_query
        );

        // Assert: Check if the returned data is correct
        $this->assertIsArray($calendar_data);
        $this->assertArrayHasKey('events', $calendar_data);
        $this->assertCount(1, $calendar_data['events'], 'Should find exactly one event.');

        $event = $calendar_data['events'][0];
        
        // Check core properties
        $this->assertEquals('Test Calendar Service', $event['title']);
        
        // Check extended properties that determine how the event behaves on the frontend
        $props = $event['extendedProps'];
        $this->assertFalse($props['isBooked']);
        $this->assertFalse($props['is_block']);
        $this->assertTrue($props['isBookable']);
        $this->assertEquals($this->slot_time_utc, $props['slotIdentifier']);
    }
}