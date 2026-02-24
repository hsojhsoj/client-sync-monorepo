<?php
/**
 * File: src/shared/includes/core/class-table-schema-manager.php
 * Manages custom table names, creation, and schema migrations.
 *
 * Extracted from Database_Manager to give table schema management a
 * single responsibility. Other services accept this class as a
 * dependency to access table names.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Utility\Transaction_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Table_Schema_Manager {

	public function get_slots_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_time_slots';
	}

	public function get_dimensions_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_slot_dimensions';
	}

	public function get_relationships_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_relationships';
	}

	public function get_bookings_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_bookings';
	}

	public function get_graph_nodes_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_graph_nodes';
	}

	public function get_audit_logs_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_audit_logs';
	}

	public function get_seat_holds_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_seat_holds';
	}

	public function get_seat_bookings_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_seat_bookings';
	}

	/**
	 * Create all custom tables using dbDelta.
	 */
	public function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$sql_slots = "CREATE TABLE " . $this->get_slots_table_name() . " (
			slot_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			start_time datetime NOT NULL,
			end_time datetime NOT NULL,
			is_block tinyint(1) NOT NULL DEFAULT 0,
			booking_count int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (slot_id),
			KEY start_time (start_time),
			KEY end_time (end_time),
			KEY is_block (is_block)
		) $charset_collate;";

		$sql_dims = "CREATE TABLE " . $this->get_dimensions_table_name() . " (
            slot_id bigint(20) unsigned NOT NULL,
            dimension_key varchar(191) NOT NULL,
            dimension_value varchar(191) NOT NULL,
            PRIMARY KEY  (slot_id, dimension_key),
            KEY dimension_key_value (dimension_key, dimension_value)
        ) $charset_collate;";

		$sql_relationships = "CREATE TABLE " . $this->get_relationships_table_name() . " (
            relationship_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            parent_object_id bigint(20) unsigned NOT NULL,
            parent_object_type varchar(191) NOT NULL,
            child_object_id bigint(20) unsigned NOT NULL,
            child_object_type varchar(191) NOT NULL,
            PRIMARY KEY  (relationship_id),
			UNIQUE KEY unique_relationship (parent_object_id, child_object_id),
            KEY parent_child_idx (parent_object_id, child_object_type),
            KEY child_parent_idx (child_object_id, parent_object_type)
        ) $charset_collate;";

		$sql_bookings = "CREATE TABLE " . $this->get_bookings_table_name() . " (
            booking_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slot_id bigint(20) unsigned NOT NULL,
            appointment_id bigint(20) unsigned NOT NULL,
            booking_time_utc datetime NOT NULL,
            PRIMARY KEY  (booking_id),
            KEY slot_id (slot_id),
            KEY appointment_id (appointment_id),
            KEY slot_appointment (slot_id, appointment_id)
        ) $charset_collate;";

		$sql_graph_nodes = "CREATE TABLE " . $this->get_graph_nodes_table_name() . " (
			node_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			node_hash VARCHAR(255) NOT NULL,
			cpt_slug VARCHAR(191) NOT NULL,
			wp_post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			node_type VARCHAR(50) NOT NULL,
			label VARCHAR(255) NOT NULL,
			parent_hash VARCHAR(255) DEFAULT NULL,
			last_updated DATETIME NOT NULL,
			PRIMARY KEY (node_id),
			UNIQUE KEY node_hash (node_hash),
			KEY cpt_slug (cpt_slug),
			KEY parent_hash (parent_hash)
		) $charset_collate;";

		$sql_seat_holds = "CREATE TABLE " . $this->get_seat_holds_table_name() . " (
			hold_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			venue_id bigint(20) unsigned NOT NULL,
			slot_id bigint(20) unsigned NOT NULL,
			seat_id varchar(100) NOT NULL,
			session_token varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			hold_expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (hold_id),
			UNIQUE KEY venue_slot_seat (venue_id, slot_id, seat_id),
			KEY session_token (session_token),
			KEY hold_expires_at (hold_expires_at)
		) $charset_collate;";

		$sql_seat_bookings = "CREATE TABLE " . $this->get_seat_bookings_table_name() . " (
			seat_booking_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			venue_id bigint(20) unsigned NOT NULL,
			slot_id bigint(20) unsigned NOT NULL,
			seat_id varchar(100) NOT NULL,
			appointment_id bigint(20) unsigned NOT NULL,
			seat_category varchar(50) NOT NULL DEFAULT '',
			seat_price int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (seat_booking_id),
			UNIQUE KEY venue_slot_seat (venue_id, slot_id, seat_id),
			KEY appointment_id (appointment_id)
		) $charset_collate;";

		dbDelta( $sql_slots );
		dbDelta( $sql_dims );
		dbDelta( $sql_relationships );
		dbDelta( $sql_bookings );
		dbDelta( $sql_graph_nodes );
		dbDelta( $sql_seat_holds );
		dbDelta( $sql_seat_bookings );

		$this->create_audit_logs_table();
	}

	/**
	 * Create the HIPAA audit logs table.
	 */
	public function create_audit_logs_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$sql_audit_logs = "CREATE TABLE " . $this->get_audit_logs_table_name() . " (
			log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			username VARCHAR(60) NOT NULL DEFAULT '',
			action VARCHAR(50) NOT NULL,
			object_type VARCHAR(50) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(500) NOT NULL DEFAULT '',
			request_uri VARCHAR(2000) NOT NULL DEFAULT '',
			meta_data LONGTEXT,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (log_id),
			KEY idx_user_id (user_id),
			KEY idx_action (action),
			KEY idx_object (object_type, object_id),
			KEY idx_created_at (created_at),
			KEY idx_ip_address (ip_address),
			KEY idx_action_created (action, created_at)
		) $charset_collate;";

		dbDelta( $sql_audit_logs );
	}

	/**
	 * Migrate legacy slot data from wp_options to custom tables.
	 *
	 * @return array{migrated: int, skipped: int, errors: int}
	 */
	public function migrate_slots_from_option_to_table(): array {
		global $wpdb;
		$stats           = [
			'migrated' => 0,
			'skipped'  => 0,
			'errors'   => 0,
		];
		$old_slots_array = get_option( 'clisyc_available_time_slots' );
		if ( empty( $old_slots_array ) || ! is_array( $old_slots_array ) ) {
			return $stats;
		}

		$site_timezone = wp_timezone();
		$utc_timezone  = new \DateTimeZone( 'UTC' );
		$slots_table   = $this->get_slots_table_name();
		$dims_table    = $this->get_dimensions_table_name();

		try {
			Transaction_Manager::execute( function () use ( $wpdb, $old_slots_array, &$stats, $site_timezone, $utc_timezone, $slots_table, $dims_table ) {
				foreach ( $old_slots_array as $slot_data ) {
					if ( ! is_array( $slot_data ) || empty( $slot_data['date'] ) || empty( $slot_data['start_time'] ) || empty( $slot_data['end_time'] ) ) {
						$stats['skipped']++;
						continue;
					}
					$start_dt_site = new \DateTime( $slot_data['date'] . ' ' . $slot_data['start_time'], $site_timezone );
					$end_dt_site   = new \DateTime( $slot_data['date'] . ' ' . $slot_data['end_time'], $site_timezone );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$insert_result = $wpdb->insert(
						$slots_table,
						[
							'start_time' => $start_dt_site->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' ),
							'end_time'   => $end_dt_site->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' ),
							'is_block'   => ( isset( $slot_data['is_block'] ) && true === $slot_data['is_block'] ) ? 1 : 0,
						],
						[ '%s', '%s', '%d' ]
					);
					if ( false === $insert_result ) {
						$stats['errors']++;
						continue;
					}
					$new_slot_id = $wpdb->insert_id;
					if ( ! empty( $slot_data['dimensions'] ) && is_array( $slot_data['dimensions'] ) ) {
						foreach ( $slot_data['dimensions'] as $key => $value ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
							$wpdb->insert(
								$dims_table,
								[
									'slot_id'         => $new_slot_id,
									'dimension_key'   => sanitize_key( $key ),
									'dimension_value' => sanitize_text_field( $value ),
								],
								[ '%d', '%s', '%s' ]
							);
						}
					}
					$stats['migrated']++;
				}

				if ( $stats['errors'] > 0 ) {
					throw new \Exception( 'Migration encountered errors.' );
				}
			} );
		} catch ( \Exception $e ) {
			// Transaction was rolled back by Transaction_Manager.
		}

		return $stats;
	}
}
