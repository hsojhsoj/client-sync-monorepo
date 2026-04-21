<?php
/**
 * File: tests/unit/MessagingPermissionsTest.php
 * Smoke tests for ClientSyncPro\Modules\Messaging\Messaging_Permissions.
 *
 * Covers the security-critical authorization surface of the messaging
 * module: who can initiate threads, who can reply, who can read a thread,
 * and who can read an attachment. These are the functions that gate
 * every REST endpoint and every file-download request.
 *
 * @package ClientSync\Tests\Unit
 */

namespace ClientSync\Tests\Unit;

use WP_UnitTestCase;
use ClientSyncPro\Modules\Messaging\Messaging_Permissions;
use ClientSyncPro\Modules\Messaging\Messaging_Schema;
use DependentMedia\ClientSync\Constants;

class MessagingPermissionsTest extends WP_UnitTestCase {

	/** @var int */
	private $admin_id;

	/** @var int */
	private $editor_id;

	/** @var int */
	private $subscriber_id;

	/** @var int Another subscriber — used as "other client" */
	private $other_subscriber_id;

	/** @var Messaging_Schema */
	private $schema;

	public function setUp(): void {
		parent::setUp();

		// Permissions class reads from the messaging tables — ensure they exist.
		$this->schema = new Messaging_Schema();
		$this->schema->create_tables();

		$this->admin_id            = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->editor_id           = $this->factory()->user->create( [ 'role' => 'editor' ] );
		$this->subscriber_id       = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->other_subscriber_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . $this->schema->get_threads_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . $this->schema->get_messages_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . $this->schema->get_attachments_table() );
		delete_option( Constants::OPTION_MESSAGING_ROLES_INITIATE );
		delete_option( Constants::OPTION_MESSAGING_ROLES_REPLY );
		parent::tearDown();
	}

	// ── can_initiate ──────────────────────────────────────────────

	public function test_can_initiate_allows_default_roles_administrator_and_editor(): void {
		$this->assertTrue( Messaging_Permissions::can_initiate( $this->admin_id ) );
		$this->assertTrue( Messaging_Permissions::can_initiate( $this->editor_id ) );
	}

	public function test_can_initiate_denies_subscriber_by_default(): void {
		$this->assertFalse( Messaging_Permissions::can_initiate( $this->subscriber_id ) );
	}

	public function test_can_initiate_returns_false_for_unknown_user(): void {
		$this->assertFalse( Messaging_Permissions::can_initiate( 999999 ) );
	}

	public function test_can_initiate_respects_option_override(): void {
		update_option( Constants::OPTION_MESSAGING_ROLES_INITIATE, [ 'administrator' ] );
		$this->assertTrue( Messaging_Permissions::can_initiate( $this->admin_id ) );
		$this->assertFalse( Messaging_Permissions::can_initiate( $this->editor_id ) );
	}

	public function test_can_initiate_falls_back_to_defaults_when_option_is_not_array(): void {
		update_option( Constants::OPTION_MESSAGING_ROLES_INITIATE, 'not-an-array' );
		$this->assertTrue( Messaging_Permissions::can_initiate( $this->admin_id ) );
	}

	// ── can_reply ─────────────────────────────────────────────────

	public function test_can_reply_allows_default_roles(): void {
		// Default reply set is admin + editor + subscriber.
		$this->assertTrue( Messaging_Permissions::can_reply( $this->admin_id ) );
		$this->assertTrue( Messaging_Permissions::can_reply( $this->editor_id ) );
		$this->assertTrue( Messaging_Permissions::can_reply( $this->subscriber_id ) );
	}

	public function test_can_reply_denies_unknown_user(): void {
		$this->assertFalse( Messaging_Permissions::can_reply( 999999 ) );
	}

	// ── can_access_thread ─────────────────────────────────────────

	public function test_can_access_thread_admin_sees_all(): void {
		$other_thread_id = $this->create_thread( $this->subscriber_id );
		$this->assertTrue( Messaging_Permissions::can_access_thread( $this->admin_id, $other_thread_id ) );
	}

	public function test_can_access_thread_client_sees_own(): void {
		$thread_id = $this->create_thread( $this->subscriber_id );
		$this->assertTrue( Messaging_Permissions::can_access_thread( $this->subscriber_id, $thread_id ) );
	}

	public function test_can_access_thread_unrelated_subscriber_is_denied(): void {
		$thread_id = $this->create_thread( $this->subscriber_id );
		$this->assertFalse( Messaging_Permissions::can_access_thread( $this->other_subscriber_id, $thread_id ) );
	}

	public function test_can_access_thread_nonexistent_thread_returns_false_for_client(): void {
		// Unknown thread ID → non-admin denied.
		$this->assertFalse( Messaging_Permissions::can_access_thread( $this->subscriber_id, 999999 ) );
	}

	// ── can_access_attachment ─────────────────────────────────────

	public function test_can_access_attachment_follows_thread_access(): void {
		$thread_id     = $this->create_thread( $this->subscriber_id );
		$message_id    = $this->create_message( $thread_id, $this->admin_id, $this->subscriber_id );
		$attachment_id = $this->create_attachment( $message_id );

		// Thread owner can access.
		$this->assertTrue( Messaging_Permissions::can_access_attachment( $this->subscriber_id, $attachment_id ) );
		// Admin can access.
		$this->assertTrue( Messaging_Permissions::can_access_attachment( $this->admin_id, $attachment_id ) );
		// Unrelated subscriber is denied — the IDOR path we care about.
		$this->assertFalse( Messaging_Permissions::can_access_attachment( $this->other_subscriber_id, $attachment_id ) );
	}

	public function test_can_access_attachment_nonexistent_returns_false(): void {
		$this->assertFalse( Messaging_Permissions::can_access_attachment( $this->admin_id, 999999 ) );
	}

	// ── is_admin ──────────────────────────────────────────────────

	public function test_is_admin_recognizes_administrator_and_editor(): void {
		$this->assertTrue( Messaging_Permissions::is_admin( $this->admin_id ) );
		$this->assertTrue( Messaging_Permissions::is_admin( $this->editor_id ) );
	}

	public function test_is_admin_rejects_subscriber(): void {
		$this->assertFalse( Messaging_Permissions::is_admin( $this->subscriber_id ) );
	}

	// ── Helpers ───────────────────────────────────────────────────

	/**
	 * Insert a minimal thread row and return its ID.
	 */
	private function create_thread( int $client_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$this->schema->get_threads_table(),
			[
				'client_id'  => $client_id,
				'subject'    => 'Test',
				'status'     => 'open',
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a minimal message row and return its ID.
	 */
	private function create_message( int $thread_id, int $sender_id, int $recipient_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$this->schema->get_messages_table(),
			[
				'thread_id'    => $thread_id,
				'sender_id'    => $sender_id,
				'recipient_id' => $recipient_id,
				'body'         => 'test body',
				'created_at'   => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%d', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a minimal attachment row and return its ID.
	 */
	private function create_attachment( int $message_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$this->schema->get_attachments_table(),
			[
				'message_id' => $message_id,
				'file_name'  => 'test.pdf',
				'file_path'  => '/tmp/fake.enc',
				'file_type'  => 'application/pdf',
				'file_size'  => 1024,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%d', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}
}
