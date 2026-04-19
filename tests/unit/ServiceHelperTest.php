<?php
/**
 * File: tests/unit/ServiceHelperTest.php
 * Unit tests for the Service_Helper utility class.
 *
 * Tests the defaulting behavior that prevents the class of bug where the admin
 * UI and downstream readers disagreed about the effective duration value when
 * `_clisyc_duration_minutes` meta was unset. Every branch here corresponds to
 * a real historical callsite that had inconsistent fallback logic.
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Service_Helper;

class ServiceHelperTest extends WP_UnitTestCase {

	/** @var int */
	private $post_id;

	public function setUp(): void {
		parent::setUp();
		$this->post_id = $this->factory()->post->create();
	}

	public function test_returns_default_60_when_meta_is_unset() {
		$this->assertSame( 60, Service_Helper::get_duration_minutes( $this->post_id ) );
	}

	public function test_returns_saved_value_when_meta_is_set() {
		update_post_meta( $this->post_id, Constants::META_DURATION_MINUTES, 90 );
		$this->assertSame( 90, Service_Helper::get_duration_minutes( $this->post_id ) );
	}

	public function test_returns_saved_value_when_meta_is_numeric_string() {
		// WordPress postmeta often stores values as strings; the helper must coerce.
		update_post_meta( $this->post_id, Constants::META_DURATION_MINUTES, '45' );
		$this->assertSame( 45, Service_Helper::get_duration_minutes( $this->post_id ) );
	}

	public function test_returns_custom_default_when_meta_is_unset() {
		// Display-oriented callers pass 0 to signal "not configured".
		$this->assertSame( 0, Service_Helper::get_duration_minutes( $this->post_id, 0 ) );
	}

	public function test_returns_default_when_saved_value_is_zero_or_negative() {
		// A saved 0 or negative is treated as "not configured" — we don't want
		// downstream code computing zero-length slots.
		update_post_meta( $this->post_id, Constants::META_DURATION_MINUTES, 0 );
		$this->assertSame( 60, Service_Helper::get_duration_minutes( $this->post_id ) );

		update_post_meta( $this->post_id, Constants::META_DURATION_MINUTES, -5 );
		$this->assertSame( 60, Service_Helper::get_duration_minutes( $this->post_id ) );
	}

	public function test_returns_default_when_saved_value_is_empty_string() {
		update_post_meta( $this->post_id, Constants::META_DURATION_MINUTES, '' );
		$this->assertSame( 60, Service_Helper::get_duration_minutes( $this->post_id ) );
	}

	public function test_has_duration_returns_false_when_unset() {
		$this->assertFalse( Service_Helper::has_duration( $this->post_id ) );
	}

	public function test_has_duration_returns_true_for_positive_value() {
		update_post_meta( $this->post_id, Constants::META_DURATION_MINUTES, 30 );
		$this->assertTrue( Service_Helper::has_duration( $this->post_id ) );
	}

	public function test_has_duration_returns_false_for_zero() {
		update_post_meta( $this->post_id, Constants::META_DURATION_MINUTES, 0 );
		$this->assertFalse( Service_Helper::has_duration( $this->post_id ) );
	}

	public function test_has_duration_returns_false_for_empty_string() {
		update_post_meta( $this->post_id, Constants::META_DURATION_MINUTES, '' );
		$this->assertFalse( Service_Helper::has_duration( $this->post_id ) );
	}
}
