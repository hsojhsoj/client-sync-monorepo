<?php
/**
 * File: tests/unit/SlotGenerationRegressionTest.php
 *
 * Regression test for the onboarding-wizard slot-duration bug.
 *
 * Background: services created by the onboarding wizard shipped without the
 * `_clisyc_duration_minutes` meta. Downstream, `prepare_service_context`
 * read the meta via a bare `(int) get_post_meta(...)` which yielded 0, and
 * `SlotCalculator` then fell through to the global
 * `clisyc_calendar_slot_duration` option (often 15 min) — silently producing
 * 15-min slots instead of the 60-min the admin UI implied.
 *
 * The fix centralized the read behind `Service_Helper::get_duration_minutes`
 * (default 60). This test runs the real slot generation flow end-to-end
 * against a service with no duration meta and a schedule, and asserts the
 * generated slots are 60 min — not 15.
 *
 * @package ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Cron;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Utility\Service_Helper;

class SlotGenerationRegressionTest extends WP_UnitTestCase {

	/** @var Database_Manager */
	private $db;

	/** @var Cron */
	private $cron;

	public function setUp(): void {
		parent::setUp();

		// Custom tables are created in bootstrap via Plugin::activate(), but
		// some tests truncate — ensure a known-good state per test.
		$this->db   = new Database_Manager();
		$this->cron = new Cron( $this->db );

		update_option( 'timezone_string', 'America/New_York' );

		// Worst-case configuration of the global slot-duration option: 15 min.
		// If the code ever falls back to this, our assertion below will fail.
		update_option( Constants::OPTION_CALENDAR_SLOT_DURATION, '00:15:00' );
	}

	/**
	 * A schedule template with a single 09:00-11:00 block on every day of
	 * the week. Guarantees we generate slots regardless of which day the
	 * test window lands on.
	 */
	private function seven_day_schedule_json(): string {
		$templates = [ 'A' => [] ];
		for ( $dow = 0; $dow <= 6; $dow++ ) {
			$templates['A'][ $dow ] = [
				'slots' => [
					[ 'start' => '09:00', 'end' => '11:00' ],
				],
			];
		}
		return wp_json_encode( [ 'templates' => $templates ] );
	}

	private function create_service( array $meta_input = [] ): int {
		return $this->factory()->post->create(
			[
				'post_type'   => 'clisyc_service',
				'post_status' => 'publish',
				'post_title'  => 'Regression Test Service',
				'meta_input'  => array_merge(
					[
						Constants::META_SCHEDULE => $this->seven_day_schedule_json(),
					],
					$meta_input
				),
			]
		);
	}

	/**
	 * Query the custom slots table for all non-block slots tied to a given
	 * service ID, returning an array of duration-minute ints.
	 *
	 * Deliberately raw-SQL — we want to assert on the actual rows written
	 * by the generator, not a value re-derived from the service meta.
	 *
	 * @return int[]
	 */
	private function get_generated_slot_durations( int $service_id ): array {
		global $wpdb;
		$slots_table = $this->db->get_slots_table_name();
		$dims_table  = $this->db->get_dimensions_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) AS dur
				   FROM {$slots_table} s
				   JOIN {$dims_table} d ON d.slot_id = s.slot_id
				  WHERE d.dimension_key = %s
				    AND d.dimension_value = %s
				    AND s.is_block = 0",
				'clisyc_service',
				(string) $service_id
			)
		);

		return array_map( fn( $row ) => (int) $row->dur, $rows ?? [] );
	}

	/**
	 * Main regression: a service with NO `_clisyc_duration_minutes` meta
	 * must produce 60-min slots, NOT fall through to the 15-min global.
	 */
	public function test_service_without_duration_meta_generates_60_minute_slots() {
		$service_id = $this->create_service();

		// Sanity: meta genuinely absent — this mirrors the onboarding-wizard bug.
		$this->assertFalse(
			Service_Helper::has_duration( $service_id ),
			'Precondition: service should have no stored duration meta.'
		);
		$this->assertSame(
			60,
			Service_Helper::get_duration_minutes( $service_id ),
			'Service_Helper must default to 60 when meta is unset.'
		);

		$end = ( new \DateTime( 'today', wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' );
		$this->assertTrue( $this->cron->generate_slots_for_single_post( $service_id, $end ) );

		$durations = $this->get_generated_slot_durations( $service_id );
		$this->assertNotEmpty( $durations, 'Generator should have written at least one slot.' );

		$this->assertNotContains(
			15,
			$durations,
			'Regression: generator fell through to the 15-min global option. '
			. 'Service_Helper fallback is broken.'
		);
		foreach ( $durations as $dur ) {
			$this->assertSame(
				60,
				$dur,
				'Every slot must be 60 minutes (the Service_Helper default) when meta is unset.'
			);
		}
	}

	/**
	 * Control: an explicit `_clisyc_duration_minutes` setting is respected.
	 * This guards against a regression where Service_Helper's default ever
	 * starts overriding a legitimately-configured value.
	 */
	public function test_service_with_explicit_duration_meta_is_respected() {
		$service_id = $this->create_service(
			[ Constants::META_DURATION_MINUTES => 30 ]
		);

		$this->assertSame( 30, Service_Helper::get_duration_minutes( $service_id ) );

		$end = ( new \DateTime( 'today', wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' );
		$this->cron->generate_slots_for_single_post( $service_id, $end );

		$durations = $this->get_generated_slot_durations( $service_id );
		$this->assertNotEmpty( $durations );
		foreach ( $durations as $dur ) {
			$this->assertSame( 30, $dur );
		}
	}
}
