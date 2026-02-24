<?php
/**
 * File: src/shared/includes/core/class-migration-runner.php
 * Versioned database migration runner for Client Sync.
 *
 * Each migration is a self-contained unit with a version string and an `up()` method.
 * The runner tracks the last completed version via the clisyc_db_schema_version option
 * and executes any migrations newer than that version.
 *
 * To add a new migration:
 *   1. Add a new entry to the $migrations array with a version >= current plugin version.
 *   2. Implement the migration logic in a private method.
 *   3. Bump CURRENT_SCHEMA_VERSION to match.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migration_Runner {

	/**
	 * Current schema version. Bump this when adding new migrations.
	 */
	const CURRENT_SCHEMA_VERSION = '3.6.0';

	/**
	 * Ordered list of migrations. Each entry is:
	 *   'version'     => semver string (compared with version_compare)
	 *   'description' => human-readable label for logging
	 *   'callback'    => callable (usually [ $this, 'method_name' ])
	 *   'legacy_flag' => (optional) old option flag to honour so we don't re-run
	 *
	 * Migrations MUST be listed in ascending version order.
	 *
	 * @return array[]
	 */
	private function get_migrations(): array {
		return [
			[
				'version'     => '3.0.0',
				'description' => 'Migrate slots from wp_options to custom DB table',
				'callback'    => [ $this, 'migrate_slots_to_db_v2' ],
				'legacy_flag' => 'clisyc_slots_migrated_to_db_v2',
			],
			[
				'version'     => '3.1.0',
				'description' => 'Rename clisyc_pending_payment status to clisyc_pending_pay (MySQL varchar limit)',
				'callback'    => [ $this, 'rename_pending_payment_status' ],
				'legacy_flag' => 'clisyc_migrated_pending_pay_status',
			],
			[
				'version'     => '3.5.1',
				'description' => 'Add missing database indexes to slots, bookings, and audit_logs tables',
				'callback'    => [ $this, 'add_missing_indexes' ],
			],
			[
				'version'     => '3.6.0',
				'description' => 'Create seat_holds and seat_bookings tables for venue seat selection',
				'callback'    => [ $this, 'create_seat_tables' ],
			],
		];
	}

	/**
	 * Run all pending migrations.
	 *
	 * Called from Plugin::run_upgrade_routines() on admin_init.
	 */
	public function run_pending_migrations(): void {
		$installed_version = get_option( Constants::OPTION_DB_SCHEMA_VERSION, '0.0.0' );

		// Already up-to-date.
		if ( version_compare( $installed_version, self::CURRENT_SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		$migrations = $this->get_migrations();

		foreach ( $migrations as $migration ) {
			// Skip migrations already completed by version tracking.
			if ( version_compare( $installed_version, $migration['version'], '>=' ) ) {
				continue;
			}

			// Skip migrations already completed by legacy flag (from pre-migration-runner era).
			if ( ! empty( $migration['legacy_flag'] ) && get_option( $migration['legacy_flag'] ) ) {
				continue;
			}

			// Run the migration.
			try {
				Debug_Logger::log(
					sprintf( 'Running migration %s: %s', $migration['version'], $migration['description'] ),
					'Migration'
				);

				call_user_func( $migration['callback'] );

				// Set legacy flag for backward compatibility.
				if ( ! empty( $migration['legacy_flag'] ) ) {
					update_option( $migration['legacy_flag'], 'yes', false );
				}

				Debug_Logger::log(
					sprintf( 'Migration %s completed successfully.', $migration['version'] ),
					'Migration'
				);
			} catch ( \Exception $e ) {
				Debug_Logger::log(
					sprintf( 'Migration %s FAILED: %s', $migration['version'], $e->getMessage() ),
					'Migration',
					'error'
				);
				// Stop processing further migrations on failure.
				return;
			}
		}

		// All migrations succeeded — stamp the current version.
		update_option( Constants::OPTION_DB_SCHEMA_VERSION, self::CURRENT_SCHEMA_VERSION, false );
	}

	/**
	 * Get the current installed schema version.
	 *
	 * @return string
	 */
	public static function get_installed_version(): string {
		return get_option( Constants::OPTION_DB_SCHEMA_VERSION, '0.0.0' );
	}

	// ─── Individual Migration Methods ─────────────────────────────────

	/**
	 * Migration 3.0.0: Move slots from wp_options to the clisyc_slots table.
	 */
	private function migrate_slots_to_db_v2(): void {
		$db_manager    = new Database_Manager();
		$old_slots_data = get_option( 'cs_available_time_slots' );

		if ( ! empty( $old_slots_data ) ) {
			$stats = $db_manager->migrate_slots_from_option_to_table();

			if ( $stats['errors'] > 0 ) {
				throw new \RuntimeException(
					sprintf( 'Slot migration had %d errors out of %d total.', $stats['errors'], $stats['total'] )
				);
			}

			delete_option( 'cs_available_time_slots' );
		}
	}

	/**
	 * Migration 3.1.0: Rename overlong post_status 'clisyc_pending_payment' (22 chars)
	 *                   to 'clisyc_pending_pay' (18 chars) for MySQL varchar(20).
	 */
	private function rename_pending_payment_status(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_status = %s WHERE post_status = %s",
				Constants::STATUS_PENDING_PAYMENT,
				'clisyc_pending_payment'
			)
		);
	}

	/**
	 * Migration 3.5.1: Add missing indexes to slots, bookings, and audit_logs tables.
	 *
	 * Uses dbDelta which safely adds indexes that don't yet exist.
	 */
	private function add_missing_indexes(): void {
		$schema_manager = new Table_Schema_Manager();
		$schema_manager->create_tables();
	}

	/**
	 * Migration 3.6.0: Create seat_holds and seat_bookings tables.
	 *
	 * Uses dbDelta via create_tables() which is idempotent.
	 */
	private function create_seat_tables(): void {
		$schema_manager = new Table_Schema_Manager();
		$schema_manager->create_tables();
	}
}
