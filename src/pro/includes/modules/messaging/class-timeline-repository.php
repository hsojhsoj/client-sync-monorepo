<?php
/**
 * File: src/pro/includes/modules/messaging/class-timeline-repository.php
 * CRUD operations for the unified client timeline.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Services\Encryption_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Timeline_Repository {

	/** @var Messaging_Schema */
	private $schema;

	public function __construct( Messaging_Schema $schema ) {
		$this->schema = $schema;
	}

	/**
	 * Insert a timeline event.
	 *
	 * Uses INSERT IGNORE to gracefully handle the dedup unique key
	 * (client_id, event_type, source_id).
	 *
	 * @param int    $client_id  Client user ID.
	 * @param string $event_type Event type slug (max 30 chars).
	 * @param int    $source_id  Reference to source record.
	 * @param int    $actor_id   User who performed the action.
	 * @param string $summary    Short display text.
	 * @param string $event_date MySQL datetime (UTC). Defaults to now.
	 * @return int|false Timeline ID on success, false on failure/duplicate.
	 */
	public function insert_event(
		int $client_id,
		string $event_type,
		int $source_id,
		int $actor_id,
		string $summary,
		string $event_date = ''
	) {
		global $wpdb;

		if ( '' === $event_date ) {
			$event_date = current_time( 'mysql', true );
		}

		// Encrypt summary in HIPAA mode.
		$stored_summary = $summary;
		if ( function_exists( 'clisyc_is_hipaa_mode' ) && clisyc_is_hipaa_mode() ) {
			$encryption     = Encryption_Service::get_instance();
			$encrypted      = $encryption->encrypt( $summary );
			$stored_summary = false !== $encrypted ? $encrypted : $summary;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->schema->get_timeline_table()}
				 (client_id, event_type, source_id, actor_id, summary, event_date)
				 VALUES (%d, %s, %d, %d, %s, %s)",
				$client_id,
				$event_type,
				$source_id,
				$actor_id,
				$stored_summary,
				$event_date
			)
		);

		if ( false === $result || 0 === $result ) {
			return false; // Insert failed or duplicate.
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get paginated timeline events for a client.
	 *
	 * @param int         $client_id   Client user ID.
	 * @param int         $page        Page number (1-based).
	 * @param int         $per_page    Results per page.
	 * @param string|null $filter_type Filter by event type (null = all).
	 * @return array Array of timeline event objects with decrypted summaries.
	 */
	public function get_timeline(
		int $client_id,
		int $page = 1,
		int $per_page = 20,
		?string $filter_type = null
	): array {
		global $wpdb;

		$offset      = ( $page - 1 ) * $per_page;
		$type_clause = '';

		if ( null !== $filter_type && '' !== $filter_type ) {
			$type_clause = $wpdb->prepare( ' AND event_type = %s', $filter_type );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->schema->get_timeline_table()}
				 WHERE client_id = %d {$type_clause}
				 ORDER BY event_date DESC
				 LIMIT %d OFFSET %d",
				$client_id,
				$per_page,
				$offset
			)
		);

		$this->decrypt_summaries( $events );

		return $events;
	}

	/**
	 * Get total event count for a client (for pagination).
	 *
	 * @param int         $client_id   Client user ID.
	 * @param string|null $filter_type Filter by event type (null = all).
	 * @return int Total count.
	 */
	public function get_timeline_count( int $client_id, ?string $filter_type = null ): int {
		global $wpdb;

		$type_clause = '';
		if ( null !== $filter_type && '' !== $filter_type ) {
			$type_clause = $wpdb->prepare( ' AND event_type = %s', $filter_type );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->schema->get_timeline_table()}
				 WHERE client_id = %d {$type_clause}",
				$client_id
			)
		);
	}

	/**
	 * Delete timeline events for a specific source.
	 *
	 * @param string $event_type Event type slug.
	 * @param int    $source_id  Source record ID.
	 * @return int Number of rows deleted.
	 */
	public function delete_events_for_source( string $event_type, int $source_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$this->schema->get_timeline_table(),
			[
				'event_type' => $event_type,
				'source_id'  => $source_id,
			],
			[ '%s', '%d' ]
		);

		return false !== $deleted ? $deleted : 0;
	}

	/**
	 * Delete timeline events older than a cutoff date for a specific event type.
	 *
	 * @param string $event_type Event type slug.
	 * @param string $cutoff     MySQL datetime cutoff (UTC).
	 * @return int Number of rows deleted.
	 */
	public function cleanup_old_events( string $event_type, string $cutoff ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->schema->get_timeline_table()}
				 WHERE event_type = %s AND event_date < %s",
				$event_type,
				$cutoff
			)
		);

		return (int) $deleted;
	}

	/**
	 * Get the distinct event types present for a client (for filter UI).
	 *
	 * @param int $client_id Client user ID.
	 * @return array Array of event type strings.
	 */
	public function get_event_types_for_client( int $client_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT event_type FROM {$this->schema->get_timeline_table()}
				 WHERE client_id = %d ORDER BY event_type ASC",
				$client_id
			)
		);
	}

	// ── Private Helpers ────────────────────────────────────────────

	/**
	 * Decrypt summaries if they appear to be encrypted.
	 *
	 * @param array $events Array of event objects (modified in place).
	 */
	private function decrypt_summaries( array &$events ): void {
		$encryption = Encryption_Service::get_instance();

		foreach ( $events as $event ) {
			if ( ! empty( $event->summary ) && $encryption->is_encrypted( $event->summary ) ) {
				$decrypted = $encryption->decrypt( $event->summary );
				if ( false !== $decrypted ) {
					$event->summary = $decrypted;
				}
			}
		}
	}
}
