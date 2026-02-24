<?php
/** File:  tests/unit/SeriesValidatorTest.php*/

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;
use ClientSyncPro\Services\Series_Validator;
use DependentMedia\ClientSync\Core\Database_Manager;

class SeriesValidatorTest extends WP_UnitTestCase {

    protected $db_manager;
    protected $service_cpt_slug = 'clisyc_service';
    protected $service_id;

    /**
     * Set up the test environment before each test.
     * This method is now simplified because the bootstrap.php file handles CPT registration and table creation.
     */
    public function setUp(): void {
        parent::setUp();
        
        if ( ! class_exists(Series_Validator::class) ) {
            $this->markTestSkipped('Client Sync Pro is not active. Skipping SeriesValidator tests.');
        }

        $this->db_manager = new Database_Manager();
        
        // The factory can now create the post because the CPT is already registered by bootstrap.php.
        $this->service_id = $this->factory()->post->create([
            'post_type' => $this->service_cpt_slug,
            'post_title' => 'Test Service',
            'post_status' => 'publish',
        ]);
    }
    
    /**
     * Clean up custom database tables after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->db_manager->get_slots_table_name()}");
        $wpdb->query("TRUNCATE TABLE {$this->db_manager->get_dimensions_table_name()}");
    }

    /**
     * Helper to insert a series of weekly slots into the test database.
     */
    private function insert_weekly_slots($start_date_str, $time, $count) {
        $slots_to_insert = [];
        $start_date = new \DateTime($start_date_str);

        for ($i = 0; $i < $count; $i++) {
            $slot_date = (clone $start_date)->modify("+$i weeks");
            $start_time_utc = (clone $slot_date)->setTime(...explode(':', $time))->setTimezone(new \DateTimeZone('UTC'));
            $end_time_utc = (clone $start_time_utc)->modify('+1 hour');

            $slots_to_insert[] = [
                'start_time_utc' => $start_time_utc->format('Y-m-d H:i:s'),
                'end_time_utc'   => $end_time_utc->format('Y-m-d H:i:s'),
                'is_block'       => false,
                'dimensions'     => [$this->service_cpt_slug => $this->service_id],
            ];
        }
        
        $this->db_manager->insert_slots($slots_to_insert);
    }
    
    /**
     * @test
     */
    public function it_successfully_validates_an_available_series() {
        // Arrange
        $this->insert_weekly_slots('2025-11-03', '10:00', 5); // Insert 5 weekly slots at 10 AM
        $validator = new Series_Validator();
        
        $initial_slot_utc = '2025-11-03 10:00:00';
        $dimensions = [$this->service_cpt_slug => $this->service_id];
        $frequency = 'weekly';
        $count = 4;

        // Act
        $result = $validator->validate_series_availability($initial_slot_utc, $dimensions, $frequency, $count);

        // Assert
        $this->assertTrue($result);
    }
    
    /**
     * @test
     */
    public function it_fails_validation_if_one_slot_is_missing() {
        // Arrange
        $this->insert_weekly_slots('2025-11-03', '10:00', 5); // Insert 5 weekly slots
        // Manually delete the 3rd slot (index 2)
        $date_to_delete = (new \DateTime('2025-11-03 10:00:00', new \DateTimeZone('UTC')))->modify('+2 weeks');
        $this->db_manager->delete_slots_for_date_range(
            $date_to_delete->format('Y-m-d 00:00:00'),
            $date_to_delete->format('Y-m-d 23:59:59'),
            [$this->service_cpt_slug => $this->service_id]
        );
        
        $validator = new Series_Validator();
        $initial_slot_utc = '2025-11-03 10:00:00';
        $dimensions = [$this->service_cpt_slug => $this->service_id];
        $frequency = 'weekly';
        $count = 4;

        // Act
        $result = $validator->validate_series_availability($initial_slot_utc, $dimensions, $frequency, $count);
        
        // Assert
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('slot_unavailable', $result->get_error_code());
    }
}