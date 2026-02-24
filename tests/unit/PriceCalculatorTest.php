<?php
/**
 * File: tests/unit/PriceCalculatorTest.php
 * Unit tests for the Price Calculator Service.
 *
 * @package     ClientSyncPro\Tests\Unit
 * @author      The Client Sync Pro Team
 * @since       1.0.0
 */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Services\Price_Calculator;

class PriceCalculatorTest extends WP_UnitTestCase {

    protected $product;

    /**
     * Set up a virtual WooCommerce product before each test.
     */
    public function setUp(): void {
        parent::setUp();
        
        // We need WooCommerce to be active for these tests.
        // The bootstrap process doesn't activate plugins, so we do it here.
        if ( ! class_exists('WooCommerce') ) {
            $this->markTestSkipped('WooCommerce is not active. Skipping PriceCalculator tests.');
        }

        // Manually create a simple, virtual product instead of relying on WC_Helper_Product.
        $product = new \WC_Product_Simple();
        $product->set_name( 'Test Product' );
        $product->set_sku( 'CLISYC-TEST-PRODUCT' );
        $this->product = $product;
        $this->product->set_virtual(true);
        $this->product->set_regular_price(100.00); // Base price of $100/night
        $this->product->save();
    }

    /**
     * Clean up the product after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        if ( $this->product ) {
            wp_delete_post( $this->product->get_id(), true );
        }
    }

    /**
     * @test
     */
    public function it_returns_the_base_price_when_no_rules_exist() {
        // Arrange
        $calculator = new Price_Calculator();
        $product_id = $this->product->get_id();
        $start_date = '2025-10-20';
        $end_date = '2025-10-23'; // 3 nights

        // Act
        $price = $calculator->calculate_price( $product_id, $start_date, $end_date );

        // Assert
        // The method should return the average nightly rate.
        $this->assertEquals(100.00, $price);
    }

    /**
     * @test
     */
    public function it_applies_a_seasonal_price_increase() {
        // Arrange
        $calculator = new Price_Calculator();
        $product_id = $this->product->get_id();
        $start_date = '2025-12-24'; // Christmas Eve
        $end_date = '2025-12-26'; // 2 nights

        $rules = [
            [
                'type' => 'seasonal',
                'start' => '2025-12-20',
                'end' => '2025-12-31',
                'mode' => 'set',
                'value' => '150.00' // Holiday rate is $150
            ]
        ];
        update_post_meta($product_id, '_clisyc_pricing_rules', $rules);

        // Act
        $price = $calculator->calculate_price($product_id, $start_date, $end_date);

        // Assert
        // Both nights fall within the seasonal rule. The average nightly rate should be 150.
        $this->assertEquals(150.00, $price);
    }

    /**
     * @test
     */
    public function it_applies_a_day_of_week_surcharge() {
        // Arrange
        $calculator = new Price_Calculator();
        $product_id = $this->product->get_id();
        // Dates: Friday, Saturday, Sunday (3 nights)
        // Friday is a weekend night, Saturday is a weekend night. Sunday is not.
        $start_date = '2025-10-24'; // A Friday
        $end_date = '2025-10-27';   // A Monday (checkout)

        $rules = [
            [
                'type' => 'dow',
                'days' => ['5', '6'], // Friday, Saturday
                'mode' => 'add',
                'value' => '25' // Add $25 for weekend nights
            ]
        ];
        update_post_meta($product_id, '_clisyc_pricing_rules', $rules);

        // Act
        $price = $calculator->calculate_price($product_id, $start_date, $end_date);

        // Assert
        // Base price is $100.
        // Night 1 (Fri): $100 + $25 = $125
        // Night 2 (Sat): $100 + $25 = $125
        // Night 3 (Sun): $100
        // Total = $350. Average nightly rate = $350 / 3 = 116.666...
        $this->assertEqualsWithDelta(116.67, $price, 0.01);
    }

    /**
     * @test
     */
    public function it_applies_a_length_of_stay_discount() {
        // Arrange
        $calculator = new Price_Calculator();
        $product_id = $this->product->get_id();
        $start_date = '2025-07-01';
        $end_date = '2025-07-08'; // 7 nights

        $rules = [
            [
                'type' => 'los',
                'min_days' => '7',
                'max_days' => '0', // No max
                'mode' => 'subtract_percent',
                'value' => '10' // 10% discount for a week or more
            ]
        ];
        update_post_meta($product_id, '_clisyc_pricing_rules', $rules);

        // Act
        $price = $calculator->calculate_price($product_id, $start_date, $end_date);

        // Assert
        // Total for 7 nights at base rate = $700.
        // 10% discount on total = $70.
        // Final total = $630.
        // Average nightly rate = $630 / 7 = $90.
        $this->assertEquals(90.00, $price);
    }

    /**
     * @test
     */
    public function it_combines_seasonal_day_of_week_and_length_of_stay_rules() {
        // Arrange
        $calculator = new Price_Calculator();
        $product_id = $this->product->get_id();
        // A 7-night stay from Friday, Dec 26, 2025 to Friday, Jan 2, 2026
        $start_date = '2025-12-26';
        $end_date = '2026-01-02';

        $rules = [
            [ // Seasonal Holiday Rate
                'type' => 'seasonal',
                'start' => '2025-12-20',
                'end' => '2025-12-31',
                'mode' => 'set',
                'value' => '150.00' // Holiday rate is $150
            ],
            [ // Weekend Surcharge
                'type' => 'dow',
                'days' => ['5', '6'], // Friday, Saturday
                'mode' => 'add',
                'value' => '25' // Add $25
            ],
            [ // Weekly Discount
                'type' => 'los',
                'min_days' => '7',
                'max_days' => '0',
                'mode' => 'subtract_percent',
                'value' => '10' // 10% discount on total for 7+ nights
            ]
        ];
        update_post_meta($product_id, '_clisyc_pricing_rules', $rules);

        // Act
        $price = $calculator->calculate_price($product_id, $start_date, $end_date);

        // Assert
        // Nightly calculations:
        // Dec 26 (Fri): $150 (seasonal) + $25 (dow) = $175
        // Dec 27 (Sat): $150 (seasonal) + $25 (dow) = $175
        // Dec 28 (Sun): $150 (seasonal) = $150
        // Dec 29 (Mon): $150 (seasonal) = $150
        // Dec 30 (Tue): $150 (seasonal) = $150
        // Dec 31 (Wed): $150 (seasonal) = $150
        // Jan 01 (Thu): $100 (base) = $100
        // Sub-total for 7 nights = 175*2 + 150*4 + 100 = $350 + $600 + $100 = $1050
        // LOS Discount (10%) = $1050 * 0.90 = $945
        // Average nightly rate = $945 / 7 = $135
        $this->assertEquals(135.00, $price);
    }
}