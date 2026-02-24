<?php
/**
 * File: src/shared/includes/core/class-database-manager.php
 * Facade that delegates to focused service classes.
 *
 * This class preserves backward compatibility for the 80+ call sites
 * that reference Database_Manager. Each public method delegates to
 * the appropriate extracted service. New code should depend on the
 * focused services directly (Slot_Query_Service, Slot_Mutation_Service, etc.).
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Services\Slot_Query_Service;
use DependentMedia\ClientSync\Services\Slot_Mutation_Service;
use DependentMedia\ClientSync\Services\Dimension_Query_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Manager {

	const CACHE_GROUP = 'clisyc_slots';

	/** @var Table_Schema_Manager */
	private $schema;

	/** @var Slot_Query_Service */
	private $query;

	/** @var Slot_Mutation_Service */
	private $mutation;

	/** @var Dimension_Query_Service */
	private $dimensions;

	public function __construct() {
		$this->schema     = new Table_Schema_Manager();
		$this->dimensions = new Dimension_Query_Service( $this->schema );
		$this->query      = new Slot_Query_Service( $this->schema, $this->dimensions );
		$this->mutation   = new Slot_Mutation_Service( $this->schema, $this->query );
	}

	// =========================================================================
	// Service accessors — for callers migrating to focused services
	// =========================================================================

	public function get_query_service(): Slot_Query_Service {
		return $this->query;
	}

	public function get_mutation_service(): Slot_Mutation_Service {
		return $this->mutation;
	}

	public function get_dimension_service(): Dimension_Query_Service {
		return $this->dimensions;
	}

	// =========================================================================
	// Table name getters — delegate to Table_Schema_Manager
	// =========================================================================

	public function get_slots_table_name(): string {
		return $this->schema->get_slots_table_name();
	}

	public function get_dimensions_table_name(): string {
		return $this->schema->get_dimensions_table_name();
	}

	public function get_relationships_table_name(): string {
		return $this->schema->get_relationships_table_name();
	}

	public function get_bookings_table_name(): string {
		return $this->schema->get_bookings_table_name();
	}

	public function get_graph_nodes_table_name(): string {
		return $this->schema->get_graph_nodes_table_name();
	}

	public function get_audit_logs_table_name(): string {
		return $this->schema->get_audit_logs_table_name();
	}

	// =========================================================================
	// Table creation & migration — delegate to Table_Schema_Manager
	// =========================================================================

	public function create_tables() {
		$this->schema->create_tables();
	}

	public function create_audit_logs_table() {
		$this->schema->create_audit_logs_table();
	}

	public function migrate_slots_from_option_to_table(): array {
		return $this->schema->migrate_slots_from_option_to_table();
	}

	// =========================================================================
	// Slot queries — delegate to Slot_Query_Service
	// =========================================================================

	public function query_slots( string $start_date_utc, string $end_date_utc, array $dimensions = [] ): array {
		return $this->query->query_slots( $start_date_utc, $end_date_utc, $dimensions );
	}

	public function query_slots_with_combinations( string $start_date_utc, string $end_date_utc, array $dimensions ): array {
		return $this->query->query_slots_with_combinations( $start_date_utc, $end_date_utc, $dimensions );
	}

	public function get_slots_for_date_range( string $start_utc, string $end_utc, array $dimensions = [], bool $is_block = false ): array {
		return $this->query->get_slots_for_date_range( $start_utc, $end_utc, $dimensions, $is_block );
	}

	public function get_all_blocked_slots_in_range( string $start_utc, string $end_utc ): array {
		return $this->query->get_all_blocked_slots_in_range( $start_utc, $end_utc );
	}

	public function get_distinct_available_slot_dates(): array {
		return $this->query->get_distinct_available_slot_dates();
	}

	public function get_latest_slot_date_for_dimensions( array $dimensions ): ?string {
		return $this->query->get_latest_slot_date_for_dimensions( $dimensions );
	}

	public function get_booked_appointments_for_date_range( string $start_utc, string $end_utc, ?int $primary_dim_id = null ): array {
		return $this->query->get_booked_appointments_for_date_range( $start_utc, $end_utc, $primary_dim_id );
	}

	public function find_slot_id_by_start_time_and_dimensions( string $start_time_utc, array $dimensions ): ?int {
		return $this->query->find_slot_id_by_start_time_and_dimensions( $start_time_utc, $dimensions );
	}

	public function count_future_slots_for_dimensions( array $dimensions ): int {
		return $this->query->count_future_slots_for_dimensions( $dimensions );
	}

	public function has_slots_for_service_in_range( string $start_utc, string $end_utc, int $service_id ): bool {
		return $this->query->has_slots_for_service_in_range( $start_utc, $end_utc, $service_id );
	}

	public function get_available_dimension_items_for_range( string $start_date_str, string $end_date_str, array $attribute_filters = [] ): array {
		return $this->query->get_available_dimension_items_for_range( $start_date_str, $end_date_str, $attribute_filters );
	}

	// =========================================================================
	// Slot mutations — delegate to Slot_Mutation_Service
	// =========================================================================

	public function insert_slots( array $slots ): array {
		return $this->mutation->insert_slots( $slots );
	}

	public function replace_editable_slots_in_range( array $slots_to_insert, string $start_utc, string $end_utc ): array {
		return $this->mutation->replace_editable_slots_in_range( $slots_to_insert, $start_utc, $end_utc );
	}

	public function replace_slots_in_range_with_dimensions( array $slots_to_insert, string $start_utc, string $end_utc, array $dimensions ): array {
		return $this->mutation->replace_slots_in_range_with_dimensions( $slots_to_insert, $start_utc, $end_utc, $dimensions );
	}

	public function delete_slots_for_date_range( string $start_utc, string $end_utc, array $dimensions = [], ?bool $is_block_filter = null, ?array $days_of_week = null, array $exclude_slot_ids = [] ): int {
		return $this->mutation->delete_slots_for_date_range( $start_utc, $end_utc, $dimensions, $is_block_filter, $days_of_week, $exclude_slot_ids );
	}

	public function delete_past_available_slots(): int {
		return $this->mutation->delete_past_available_slots();
	}

	public function delete_all_future_available_slots(): int {
		return $this->mutation->delete_all_future_available_slots();
	}

	public function clear_unrepresented_slots_in_range( string $start_utc, string $end_utc, array $represented_dimension_groups ) {
		$this->mutation->clear_unrepresented_slots_in_range( $start_utc, $end_utc, $represented_dimension_groups );
	}

	public function block_overlapping_slots( string $start_utc_str, string $end_utc_str, array $dimensions ) {
		return $this->mutation->block_overlapping_slots( $start_utc_str, $end_utc_str, $dimensions );
	}

	// =========================================================================
	// Dimension queries — delegate to Dimension_Query_Service
	// =========================================================================

	public function get_linked_child_ids( int $parent_id, string $child_cpt_slug ): array {
		return $this->dimensions->get_linked_child_ids( $parent_id, $child_cpt_slug );
	}

	public function get_valid_dimension_combinations( array $dimensions ): array {
		return $this->dimensions->get_valid_dimension_combinations( $dimensions );
	}

	public function repair_missing_relationship_types(): array {
		return $this->dimensions->repair_missing_relationship_types();
	}

	// =========================================================================
	// Cache management — delegate to Slot_Query_Service
	// =========================================================================

	public function clear_all_slot_cache() {
		$this->query->clear_all_slot_cache();
	}
}
