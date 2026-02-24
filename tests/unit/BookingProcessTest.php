<?php
/** 
 * File: tests/unit/BookingProcessTest.php 
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Core\Database_Manager;

class BookingProcessTest extends WP_UnitTestCase {

    private $db_manager;
    private $user_id;
    private $service_id;
    private $slot_id;
    private $slot_time_utc = '2025-12-25 10:00:00';

    public function setUp(): void {
        parent::setUp();

        $this->db_manager = new Database_Manager();

        // 1. Create a test user (the client)
        $this->user_id = $this->factory()->user->create(['role' => 'subscriber']);

        // 2. Create a test service (the bookable item)
        $this->service_id = $this->factory()->post->create([
            'post_type' => 'clisyc_service',
            'post_title' => 'Test Service for Booking',
            'meta_input' => [
                '_clisyc_capacity' => 1, // Service has a capacity of 1
            ],
        ]);

        // 3. Create an available slot in the database for that service
        $this->db_manager->insert_slots([
            [
                'start_time_utc' => $this->slot_time_utc,
                'end_time_utc'   => '2025-12-25 11:00:00',
                'is_block'       => false,
                'dimensions'     => ['clisyc_service' => $this->service_id],
            ]
        ]);
        
        $this->slot_id = $this->db_manager->find_slot_id_by_start_time_and_dimensions(
            $this->slot_time_utc,
            ['clisyc_service' => $this->service_id]
        );
    }

    /**
     * @test
     */
    public function it_creates_an_appointment_and_booking_record_on_success() {
        // This test simulates the core logic of creating an appointment.
        // In your actual plugin, this logic would be in a dedicated class/method.
        // For this example, we'll perform the steps directly.

        // Arrange: We have a user, a service, and an available slot from setUp().

        // Act: Create the appointment post
        $appointment_id = wp_insert_post([
            'post_type'   => 'clisyc_appointment',
            'post_status' => 'publish',
            'post_author' => $this->user_id,
            'post_title'  => get_the_title($this->service_id),
            'meta_input'  => [
                \clisyc_TIME_SLOT => $this->slot_time_utc,
                '_clisyc_slot_dimensions' => ['clisyc_service' => $this->service_id],
            ]
        ]);
        
        // Act: Create the corresponding entry in the clisyc_bookings table
        global $wpdb;
        $wpdb->insert(
            $this->db_manager->get_bookings_table_name(),
            [
                'appointment_id'   => $appointment_id,
                'slot_id'          => $this->slot_id,
                'booking_time_utc' => current_time( 'mysql', 1 ),
            ]
        );

        // Assert: Check that everything was created correctly
        $this->assertNotWPError($appointment_id);
        $this->assertGreaterThan(0, $appointment_id);
        
        // Assert that the booking record exists in our custom table
        $booking_count = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$this->db_manager->get_bookings_table_name()} WHERE slot_id = %d AND appointment_id = %d", $this->slot_id, $appointment_id)
        );
        $this->assertEquals(1, $booking_count, 'A record should exist in the bookings table.');
    }

    /**
     * @test
     */
    public function it_respects_slot_capacity() {
        // Arrange: Create one booking for the slot, filling it to its capacity of 1.
        $first_appointment_id = $this->factory()->post->create([
            'post_type' => 'clisyc_appointment', 'post_status' => 'publish', 'post_author' => $this->user_id
        ]);
        global $wpdb;
        $wpdb->insert($this->db_manager->get_bookings_table_name(), ['appointment_id' => $first_appointment_id, 'slot_id' => $this->slot_id, 'booking_time_utc' => current_time('mysql', 1)]);

        // Act & Assert: Check if the slot is now considered "at capacity".
        // This logic would be in a validation method in your plugin. We simulate it here.
        $booked_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->db_manager->get_bookings_table_name()} WHERE slot_id = %d", $this->slot_id));
        $capacity = (int) get_post_meta($this->service_id, '_clisyc_capacity', true);

        $this->assertTrue($booked_count >= $capacity, 'The slot should be considered at full capacity.');
    }
}