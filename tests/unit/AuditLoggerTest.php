<?php
/**
 * File: tests/unit/AuditLoggerTest.php
 * Unit tests for the Audit_Logger service.
 *
 * Tests log creation, querying, cleanup, IP detection,
 * and HIPAA compliance features.
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Services\Audit_Logger;
use DependentMedia\ClientSync\Constants;

class AuditLoggerTest extends WP_UnitTestCase {

	/** @var Audit_Logger */
	private $logger;

	/** @var int */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();

		// Reset singleton.
		$ref = new \ReflectionClass( Audit_Logger::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$this->logger = Audit_Logger::get_instance();

		// Ensure the audit log table exists.
		$this->logger->create_table();

		// Enable audit logging for tests.
		update_option( 'clisyc_audit_logging_enabled', true );

		// Create an admin user.
		$this->admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		$_SERVER['REMOTE_ADDR']    = '192.168.1.50';
		$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test Agent';
		$_SERVER['REQUEST_URI']    = '/wp-admin/edit.php';
	}

	public function tearDown(): void {
		// Clean up all log entries.
		global $wpdb;
		$table = $wpdb->prefix . Audit_Logger::TABLE_NAME;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		delete_option( 'clisyc_audit_logging_enabled' );
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['HTTP_USER_AGENT'] = '';
		$_SERVER['REQUEST_URI']     = '';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );

		parent::tearDown();
	}

	// ── Core Logging ─────────────────────────────────────────────────

	/**
	 * @test
	 * A log entry should be created with all expected fields.
	 */
	public function it_creates_a_log_entry() {
		$log_id = Audit_Logger::log(
			Audit_Logger::ACTION_VIEW,
			Audit_Logger::OBJECT_APPOINTMENT,
			42,
			[ 'context' => 'test' ]
		);

		$this->assertIsInt( $log_id );
		$this->assertGreaterThan( 0, $log_id );

		// Verify the entry in the database.
		$result = $this->logger->get_logs( [ 'object_id' => 42 ] );
		$this->assertCount( 1, $result['logs'] );

		$entry = $result['logs'][0];
		$this->assertEquals( $this->admin_id, $entry['user_id'] );
		$this->assertSame( 'view', $entry['action'] );
		$this->assertSame( 'appointment', $entry['object_type'] );
		$this->assertEquals( 42, $entry['object_id'] );
		$this->assertSame( '192.168.1.50', $entry['ip_address'] );
		$this->assertStringContainsString( 'PHPUnit', $entry['user_agent'] );
	}

	/**
	 * @test
	 * Logging should store metadata as JSON.
	 */
	public function it_stores_metadata_as_json() {
		$meta = [ 'client_id' => 99, 'context' => 'admin_save' ];
		Audit_Logger::log( Audit_Logger::ACTION_EDIT, Audit_Logger::OBJECT_APPOINTMENT, 10, $meta );

		$result = $this->logger->get_logs( [ 'object_id' => 10 ] );
		$entry  = $result['logs'][0];
		$decoded = json_decode( $entry['meta_data'], true );

		$this->assertIsArray( $decoded );
		$this->assertEquals( 99, $decoded['client_id'] );
		$this->assertSame( 'admin_save', $decoded['context'] );
	}

	/**
	 * @test
	 * Logging should return false when audit logging is disabled.
	 */
	public function it_returns_false_when_logging_disabled() {
		update_option( 'clisyc_audit_logging_enabled', false );

		// Reset singleton so it re-reads the option.
		$ref = new \ReflectionClass( Audit_Logger::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$result = Audit_Logger::log( Audit_Logger::ACTION_VIEW, Audit_Logger::OBJECT_APPOINTMENT, 1 );
		$this->assertFalse( $result );
	}

	/**
	 * @test
	 * The log() method should sanitize action and object_type.
	 */
	public function it_sanitizes_action_and_object_type() {
		Audit_Logger::log( 'View <script>', 'Appointment & More', 5 );

		$result = $this->logger->get_logs( [ 'object_id' => 5 ] );
		$entry  = $result['logs'][0];

		// sanitize_key lowercases and strips non-alphanumeric.
		$this->assertStringNotContainsString( '<script>', $entry['action'] );
		$this->assertStringNotContainsString( '&', $entry['object_type'] );
	}

	// ── Querying ─────────────────────────────────────────────────────

	/**
	 * @test
	 * get_logs should support filtering by action type.
	 */
	public function it_filters_logs_by_action() {
		Audit_Logger::log( Audit_Logger::ACTION_VIEW, Audit_Logger::OBJECT_APPOINTMENT, 1 );
		Audit_Logger::log( Audit_Logger::ACTION_EDIT, Audit_Logger::OBJECT_APPOINTMENT, 2 );
		Audit_Logger::log( Audit_Logger::ACTION_VIEW, Audit_Logger::OBJECT_APPOINTMENT, 3 );

		$result = $this->logger->get_logs( [ 'action' => Audit_Logger::ACTION_VIEW ] );
		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * @test
	 * get_logs should support pagination.
	 */
	public function it_paginates_log_results() {
		for ( $i = 1; $i <= 5; $i++ ) {
			Audit_Logger::log( Audit_Logger::ACTION_VIEW, Audit_Logger::OBJECT_APPOINTMENT, $i );
		}

		$page1 = $this->logger->get_logs( [ 'per_page' => 2, 'page' => 1 ] );
		$this->assertCount( 2, $page1['logs'] );
		$this->assertSame( 5, $page1['total'] );
		$this->assertSame( 3, $page1['pages'] );

		$page3 = $this->logger->get_logs( [ 'per_page' => 2, 'page' => 3 ] );
		$this->assertCount( 1, $page3['logs'] );
	}

	/**
	 * @test
	 * get_logs_for_object should return logs for a specific object.
	 */
	public function it_gets_logs_for_specific_object() {
		Audit_Logger::log( Audit_Logger::ACTION_VIEW, Audit_Logger::OBJECT_APPOINTMENT, 100 );
		Audit_Logger::log( Audit_Logger::ACTION_EDIT, Audit_Logger::OBJECT_APPOINTMENT, 100 );
		Audit_Logger::log( Audit_Logger::ACTION_VIEW, Audit_Logger::OBJECT_APPOINTMENT, 200 );

		$logs = $this->logger->get_logs_for_object( Audit_Logger::OBJECT_APPOINTMENT, 100 );
		$this->assertCount( 2, $logs );
	}

	/**
	 * @test
	 * get_logs_for_user should return logs for a specific user.
	 */
	public function it_gets_logs_for_specific_user() {
		Audit_Logger::log( Audit_Logger::ACTION_VIEW, Audit_Logger::OBJECT_APPOINTMENT, 1 );
		Audit_Logger::log( Audit_Logger::ACTION_EDIT, Audit_Logger::OBJECT_APPOINTMENT, 2 );

		$logs = $this->logger->get_logs_for_user( $this->admin_id );
		$this->assertCount( 2, $logs );
	}

	// ── Cleanup ──────────────────────────────────────────────────────

	/**
	 * @test
	 * cleanup_old_logs should respect the minimum 6-year retention (HIPAA).
	 */
	public function it_enforces_minimum_retention_period() {
		global $wpdb;
		$table = $wpdb->prefix . Audit_Logger::TABLE_NAME;

		// Insert a log entry "5 years ago" (should NOT be deleted — under 6-year minimum).
		$five_years_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-5 years' ) );
		$wpdb->insert( $table, [
			'user_id'     => $this->admin_id,
			'username'    => 'admin',
			'action'      => 'view',
			'object_type' => 'appointment',
			'object_id'   => 999,
			'ip_address'  => '127.0.0.1',
			'user_agent'  => '',
			'request_uri' => '',
			'created_at'  => $five_years_ago,
		] );

		// Set retention to something dangerously low (should be clamped to 2190 days).
		update_option( 'clisyc_audit_log_retention_days', 30 );

		$this->logger->cleanup_old_logs();

		// The 5-year-old log should still exist (6-year minimum applies).
		$result = $this->logger->get_logs( [ 'object_id' => 999 ] );
		$this->assertGreaterThanOrEqual( 1, $result['total'], 'Logs under 6 years old should NOT be deleted.' );

		delete_option( 'clisyc_audit_log_retention_days' );
	}

	/**
	 * @test
	 * cleanup_old_logs should delete entries older than the retention period.
	 */
	public function it_deletes_entries_beyond_retention() {
		global $wpdb;
		$table = $wpdb->prefix . Audit_Logger::TABLE_NAME;

		// Insert a log entry "8 years ago" (should be deleted).
		$eight_years_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-8 years' ) );
		$wpdb->insert( $table, [
			'user_id'     => $this->admin_id,
			'username'    => 'admin',
			'action'      => 'view',
			'object_type' => 'appointment',
			'object_id'   => 888,
			'ip_address'  => '127.0.0.1',
			'user_agent'  => '',
			'request_uri' => '',
			'created_at'  => $eight_years_ago,
		] );

		// Default retention is 2555 days (~7 years).
		$deleted = $this->logger->cleanup_old_logs();

		$this->assertGreaterThanOrEqual( 1, $deleted );

		$result = $this->logger->get_logs( [ 'object_id' => 888 ] );
		$this->assertSame( 0, $result['total'], 'Logs older than retention period should be deleted.' );
	}

	// ── IP Detection ─────────────────────────────────────────────────

	/**
	 * @test
	 * Without trusted proxies, REMOTE_ADDR should be used directly.
	 */
	public function it_uses_remote_addr_without_trusted_proxies() {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.50';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';

		Audit_Logger::log( Audit_Logger::ACTION_VIEW, Audit_Logger::OBJECT_APPOINTMENT, 700 );

		$result = $this->logger->get_logs( [ 'object_id' => 700 ] );
		$entry  = $result['logs'][0];
		$this->assertSame( '203.0.113.50', $entry['ip_address'], 'Should use REMOTE_ADDR, not forwarded header.' );
	}

	/**
	 * @test
	 * With trusted proxies, forwarded header should be used.
	 */
	public function it_uses_forwarded_header_with_trusted_proxy() {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.75, 10.0.0.1';

		// Register the proxy as trusted.
		add_filter( 'clisyc_trusted_proxy_ips', function () {
			return [ '10.0.0.1' ];
		} );

		Audit_Logger::log( Audit_Logger::ACTION_VIEW, Audit_Logger::OBJECT_APPOINTMENT, 701 );

		$result = $this->logger->get_logs( [ 'object_id' => 701 ] );
		$entry  = $result['logs'][0];
		$this->assertSame( '203.0.113.75', $entry['ip_address'], 'Should use first IP from X-Forwarded-For.' );

		remove_all_filters( 'clisyc_trusted_proxy_ips' );
	}

	// ── Statistics ────────────────────────────────────────────────────

	/**
	 * @test
	 * get_statistics should return correct counts.
	 */
	public function it_returns_correct_statistics() {
		Audit_Logger::log( Audit_Logger::ACTION_VIEW, Audit_Logger::OBJECT_APPOINTMENT, 1 );
		Audit_Logger::log( Audit_Logger::ACTION_EDIT, Audit_Logger::OBJECT_APPOINTMENT, 2 );
		Audit_Logger::log( Audit_Logger::ACTION_VIEW, Audit_Logger::OBJECT_CLIENT, 3 );

		$stats = $this->logger->get_statistics();

		$this->assertSame( 3, $stats['total_entries'] );
		$this->assertSame( 3, $stats['entries_today'] );
		$this->assertSame( 1, $stats['unique_users'] );
		$this->assertArrayHasKey( 'view', $stats['by_action'] );
		$this->assertArrayHasKey( 'edit', $stats['by_action'] );
		$this->assertEquals( 2, $stats['by_action']['view'] );
		$this->assertEquals( 1, $stats['by_action']['edit'] );
		$this->assertArrayHasKey( 'appointment', $stats['by_object_type'] );
		$this->assertArrayHasKey( 'client', $stats['by_object_type'] );
	}

	// ── Labels ────────────────────────────────────────────────────────

	/**
	 * @test
	 * get_action_label should return human-readable labels.
	 */
	public function it_returns_action_labels() {
		$this->assertSame( 'View', Audit_Logger::get_action_label( Audit_Logger::ACTION_VIEW ) );
		$this->assertSame( 'Edit', Audit_Logger::get_action_label( Audit_Logger::ACTION_EDIT ) );
		$this->assertSame( 'Export', Audit_Logger::get_action_label( Audit_Logger::ACTION_EXPORT ) );
		$this->assertSame( 'Decrypt', Audit_Logger::get_action_label( Audit_Logger::ACTION_DECRYPT ) );
	}

	/**
	 * @test
	 * get_action_label should capitalize unknown actions.
	 */
	public function it_falls_back_for_unknown_action_labels() {
		$this->assertSame( 'Custom_thing', Audit_Logger::get_action_label( 'custom_thing' ) );
	}

	/**
	 * @test
	 * get_object_type_label should return human-readable labels.
	 */
	public function it_returns_object_type_labels() {
		$this->assertSame( 'Appointment', Audit_Logger::get_object_type_label( Audit_Logger::OBJECT_APPOINTMENT ) );
		$this->assertSame( 'Client', Audit_Logger::get_object_type_label( Audit_Logger::OBJECT_CLIENT ) );
		$this->assertSame( 'Audit Log', Audit_Logger::get_object_type_label( Audit_Logger::OBJECT_AUDIT_LOG ) );
	}

	// ── Table Management ─────────────────────────────────────────────

	/**
	 * @test
	 * table_exists should return true after create_table.
	 */
	public function it_confirms_table_exists() {
		$this->assertTrue( $this->logger->table_exists() );
	}

	/**
	 * @test
	 * table_exists should return false after drop_table.
	 */
	public function it_confirms_table_dropped() {
		global $wpdb;

		$table_name = $wpdb->prefix . Audit_Logger::TABLE_NAME;

		// The WP test suite creates a TEMPORARY table (via the query filter
		// that converts CREATE TABLE → CREATE TEMPORARY TABLE), and plugin
		// activation in bootstrap.php creates a real base table. Both must
		// be dropped to verify table_exists() returns false.
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table_name}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		$this->assertFalse( $this->logger->table_exists() );

		// Re-create both real and temporary tables, then restore filters.
		$this->logger->create_table();
		add_filter( 'query', [ $this, '_create_temporary_tables' ] );
		add_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		$this->logger->create_table();
	}

	// ── Hook Callbacks ───────────────────────────────────────────────

	/**
	 * @test
	 * log_appointment_save should log edit action for updates.
	 */
	public function it_logs_appointment_save_as_edit() {
		if ( ! post_type_exists( Constants::POST_TYPE_APPOINTMENT ) ) {
			register_post_type( Constants::POST_TYPE_APPOINTMENT, [ 'public' => false ] );
		}

		$post_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => 'publish',
		] );
		$post = get_post( $post_id );

		$this->logger->log_appointment_save( $post_id, $post, true );

		$logs = $this->logger->get_logs_for_object( Audit_Logger::OBJECT_APPOINTMENT, $post_id );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'edit', $logs[0]['action'] );
	}

	/**
	 * @test
	 * log_appointment_save should log create action for new posts.
	 */
	public function it_logs_appointment_save_as_create() {
		if ( ! post_type_exists( Constants::POST_TYPE_APPOINTMENT ) ) {
			register_post_type( Constants::POST_TYPE_APPOINTMENT, [ 'public' => false ] );
		}

		$post_id = $this->factory()->post->create( [
			'post_type'   => Constants::POST_TYPE_APPOINTMENT,
			'post_status' => 'publish',
		] );
		$post = get_post( $post_id );

		$this->logger->log_appointment_save( $post_id, $post, false );

		$logs = $this->logger->get_logs_for_object( Audit_Logger::OBJECT_APPOINTMENT, $post_id );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'create', $logs[0]['action'] );
	}
}
