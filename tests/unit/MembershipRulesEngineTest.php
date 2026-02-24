<?php
/** File: tests/unit/MembershipRulesEngineTest.php */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;
use ClientSyncPro\Services\Membership_Rules_Engine;
use DependentMedia\ClientSync\Admin\Dimension_CPT_Manager;
use ClientSyncPro\Modules\Memberships\Membership_CPT;

class MembershipRulesEngineTest extends WP_UnitTestCase {

    /** @var Membership_Rules_Engine */
    protected $engine;

    protected $user_id;
    protected $service_id_yoga;
    protected $service_id_spin;
    protected $plan_id_gold;
    protected $plan_id_silver;
    protected $service_cpt_slug = 'clisyc_service';

    /**
     * Set up the test environment before each test.
     * This method is now simplified because the bootstrap.php file handles CPT registration and table creation.
     */
    public function setUp(): void {
        parent::setUp();
        
        if ( ! class_exists(Membership_Rules_Engine::class) ) {
            $this->markTestSkipped('Client Sync Pro is not active. Skipping MembershipRulesEngineTest tests.');
        }

        // The factory can now create all posts because the CPTs and options are set up by bootstrap.php.
        $this->user_id = $this->factory()->user->create(['role' => 'subscriber']);
        $this->service_id_yoga = $this->factory()->post->create([ 'post_type' => $this->service_cpt_slug, 'post_title' => 'Yoga Class' ]);
        $this->service_id_spin = $this->factory()->post->create([ 'post_type' => $this->service_cpt_slug, 'post_title' => 'Spin Class' ]);
        $this->plan_id_gold = $this->factory()->post->create([ 'post_type' => 'clisyc_member_plan', 'post_title' => 'Gold Plan' ]);
        $this->plan_id_silver = $this->factory()->post->create([ 'post_type' => 'clisyc_member_plan', 'post_title' => 'Silver Plan' ]);

        $this->engine = new Membership_Rules_Engine();
    }

    /**
     * @test
     */
    public function it_returns_true_for_a_non_member() {
        // Act: Check if a user with no membership can book a service.
        $result = $this->engine->can_user_book_service($this->user_id, $this->service_id_yoga);

        // Assert: The user should be allowed to book, with no special message.
        $this->assertIsArray($result);
        $this->assertTrue($result['can_book']);
        $this->assertEmpty($result['message']);
    }

    /**
     * @test
     */
    public function it_returns_true_for_a_member_with_an_unlimited_plan() {
        // Arrange: Give the user the Gold plan with an unlimited rule for Yoga.
        $rules = [
            [
                'service_id' => $this->service_id_yoga,
                'rule_type' => 'booking_limit',
                'limit_period' => 'unlimited',
            ]
        ];
        update_post_meta($this->plan_id_gold, '_clisyc_member_plan_access_rules', $rules);
        update_user_meta($this->user_id, '_clisyc_active_membership_plan_id', $this->plan_id_gold);

        // Act
        $result = $this->engine->can_user_book_service($this->user_id, $this->service_id_yoga);

        // Assert
        $this->assertTrue($result['can_book']);
        $this->assertEquals('Included in your membership', $result['message']);
    }

    /**
     * @test
     */
    public function it_returns_true_when_user_is_under_their_booking_limit() {
        // Arrange: Give the user the Silver plan (2 spin classes/month) and one past booking.
        $rules = [
            [
                'service_id' => $this->service_id_spin,
                'rule_type' => 'booking_limit',
                'limit_period' => 'per_month',
                'limit_value' => 2,
            ]
        ];
        update_post_meta($this->plan_id_silver, '_clisyc_member_plan_access_rules', $rules);
        update_user_meta($this->user_id, '_clisyc_active_membership_plan_id', $this->plan_id_silver);

        // Create one appointment for this user, for this service, this month.
        $this->factory()->post->create([
            'post_type' => 'clisyc_appointment',
            'post_author' => $this->user_id,
            'post_status' => 'publish',
            'meta_input' => [
                'clisyc_appointment_date' => wp_date('Y-m-05'), // 5th of the current month
                // Let's use an array instead of a serialized string for clarity
                '_clisyc_slot_dimensions' => ['clisyc_service' => $this->service_id_spin],
            ]
        ]);
        
        // Act
        $result = $this->engine->can_user_book_service($this->user_id, $this->service_id_spin);

        // Assert
        $this->assertTrue($result['can_book']);
        $this->assertEquals(sprintf('Included in your membership (%d of %d used)', 1, 2), $result['message']);
    }

    /**
     * @test
     */
    public function it_returns_false_when_user_is_at_their_booking_limit() {
        // Arrange: Give the user the Silver plan (2 spin classes/month) and TWO past bookings.
        $rules = [
            [
                'service_id' => $this->service_id_spin,
                'rule_type' => 'booking_limit',
                'limit_period' => 'per_month',
                'limit_value' => 2,
            ]
        ];
        update_post_meta($this->plan_id_silver, '_clisyc_member_plan_access_rules', $rules);
        update_user_meta($this->user_id, '_clisyc_active_membership_plan_id', $this->plan_id_silver);

        // Create two appointments for this user, for this service, this month.
        for ($i = 0; $i < 2; $i++) {
            $this->factory()->post->create([
                'post_type' => 'clisyc_appointment',
                'post_author' => $this->user_id,
                'post_status' => 'publish',
                'meta_input' => [
                    'clisyc_appointment_date' => wp_date('Y-m-0' . ($i + 5)),
                    '_clisyc_slot_dimensions' => ['clisyc_service' => $this->service_id_spin],
                ]
            ]);
        }
        
        // Act
        $result = $this->engine->can_user_book_service($this->user_id, $this->service_id_spin);

        // Assert
        $this->assertFalse($result['can_book']);
        $this->assertEquals('You have reached your booking limit for this period.', $result['message']);
    }
    
    /**
     * @test
     */
    public function it_prefers_a_specific_rule_over_an_all_services_rule() {
        // Arrange: Create two rules, one for all services and a more specific one for Yoga.
        $rules = [
            [
                'service_id' => 0, // All services
                'rule_type' => 'booking_limit',
                'limit_period' => 'per_month',
                'limit_value' => 10,
            ],
            [
                'service_id' => $this->service_id_yoga, // Specific Yoga rule
                'rule_type' => 'booking_limit',
                'limit_period' => 'per_month',
                'limit_value' => 2, // Only 2 Yoga classes are allowed
            ],
        ];

        // Act: Ask the engine to find the correct rule for the Yoga service.
        $applicable_rule = $this->engine->get_applicable_rule_for_service($rules, $this->service_id_yoga);
        
        // Assert: The returned rule should be the specific Yoga rule (limit 2), not the general one (limit 10).
        $this->assertNotNull($applicable_rule);
        $this->assertEquals(2, $applicable_rule['limit_value']);
    }

    /**
     * @test
     */
    public function it_handles_stringy_numeric_service_ids_in_rules() {
        // Arrange: Create a rule where the service ID is a string, not an integer.
        // This tests the robustness of the (int) casting in the get_applicable_rule_for_service method.
        $rules = [
            [
                'service_id' => (string) $this->service_id_yoga, // Specific Yoga rule with string ID
                'rule_type' => 'booking_limit',
                'limit_period' => 'per_month',
                'limit_value' => 5,
            ],
        ];

        // Act: Ask the engine to find the correct rule for the Yoga service, passing an integer ID.
        $applicable_rule = $this->engine->get_applicable_rule_for_service($rules, $this->service_id_yoga);
        
        // Assert: The rule should be found, demonstrating that the type difference was handled correctly.
        $this->assertNotNull($applicable_rule);
        $this->assertEquals(5, $applicable_rule['limit_value']);
    }

    /**
     * @test
     */
    public function it_applies_a_percentage_discount() {
        // Arrange: Give the user a plan with a 10% discount rule for Yoga.
        $rules = [
            [
                'service_id' => $this->service_id_yoga,
                'rule_type' => 'discount_percent',
                'discount_value' => 10,
            ]
        ];
        update_post_meta($this->plan_id_gold, '_clisyc_member_plan_access_rules', $rules);
        update_user_meta($this->user_id, '_clisyc_active_membership_plan_id', $this->plan_id_gold);
        $base_price = 100.00;

        // Act: Calculate the price for the user.
        $final_price = $this->engine->get_membership_price_for_service($this->user_id, $this->service_id_yoga, $base_price);

        // Assert: The final price should be 90.00 (100 - 10%).
        $this->assertEquals(90.00, $final_price);
    }

    /**
     * @test
     */
    public function it_applies_a_fixed_amount_discount() {
        // Arrange: Give the user a plan with a $25 discount rule for Spin.
        $rules = [
            [
                'service_id' => $this->service_id_spin,
                'rule_type' => 'discount_amount',
                'discount_value' => 25,
            ]
        ];
        update_post_meta($this->plan_id_silver, '_clisyc_member_plan_access_rules', $rules);
        update_user_meta($this->user_id, '_clisyc_active_membership_plan_id', $this->plan_id_silver);
        $base_price = 100.00;

        // Act: Calculate the price for the user.
        $final_price = $this->engine->get_membership_price_for_service($this->user_id, $this->service_id_spin, $base_price);

        // Assert: The final price should be 75.00 (100 - 25).
        $this->assertEquals(75.00, $final_price);
    }

    /**
     * @test
     */
    public function it_makes_service_free_when_under_booking_limit() {
        // Arrange: Give the user the Silver plan (2 spin classes/month) and one past booking.
        $rules = [
            [
                'service_id' => $this->service_id_spin,
                'rule_type' => 'booking_limit',
                'limit_period' => 'per_month',
                'limit_value' => 2,
            ]
        ];
        update_post_meta($this->plan_id_silver, '_clisyc_member_plan_access_rules', $rules);
        update_user_meta($this->user_id, '_clisyc_active_membership_plan_id', $this->plan_id_silver);
        $base_price = 100.00;

        // Create one appointment for this user, for this service, this month.
        $this->factory()->post->create([
            'post_type' => 'clisyc_appointment',
            'post_author' => $this->user_id,
            'post_status' => 'publish',
            'meta_input' => [
                'clisyc_appointment_date' => wp_date('Y-m-05'),
                '_clisyc_slot_dimensions' => ['clisyc_service' => $this->service_id_spin],
            ]
        ]);
        
        // Act: Calculate the price for the user.
        $final_price = $this->engine->get_membership_price_for_service($this->user_id, $this->service_id_spin, $base_price);

        // Assert: The user is under their limit (1 of 2 used), so the price should be 0.
        $this->assertEquals(0.00, $final_price);
    }

    /**
     * @test
     */
    public function it_charges_base_price_when_over_booking_limit() {
        // Arrange: Give the user the Silver plan (2 spin classes/month) and two past bookings.
        $rules = [
            [
                'service_id' => $this->service_id_spin,
                'rule_type' => 'booking_limit',
                'limit_period' => 'per_month',
                'limit_value' => 2,
            ]
        ];
        update_post_meta($this->plan_id_silver, '_clisyc_member_plan_access_rules', $rules);
        update_user_meta($this->user_id, '_clisyc_active_membership_plan_id', $this->plan_id_silver);
        $base_price = 100.00;

        // Create two appointments for this user, for this service, this month.
        for ($i = 0; $i < 2; $i++) {
            $this->factory()->post->create([
                'post_type' => 'clisyc_appointment',
                'post_author' => $this->user_id,
                'post_status' => 'publish',
                'meta_input' => [
                    'clisyc_appointment_date' => wp_date('Y-m-0' . ($i + 5)),
                    '_clisyc_slot_dimensions' => ['clisyc_service' => $this->service_id_spin],
                ]
            ]);
        }
        
        // Act: Calculate the price for the user.
        $final_price = $this->engine->get_membership_price_for_service($this->user_id, $this->service_id_spin, $base_price);

        // Assert: The user is at their limit (2 of 2 used), so they must pay the base price for the next one.
        $this->assertEquals($base_price, $final_price);
    }
}