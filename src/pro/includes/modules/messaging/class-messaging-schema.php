<?php
/**
 * File: src/pro/includes/modules/messaging/class-messaging-schema.php
 * Creates and manages database tables for the messaging module.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Messaging_Schema {

	const DB_VERSION = '1.1.0';

	/**
	 * Check if tables need to be created and do so if necessary.
	 */
	public function maybe_create_tables(): void {
		$current_version = get_option( Constants::OPTION_MESSAGING_DB_VERSION, '' );

		if ( self::DB_VERSION !== $current_version ) {
			$this->create_tables();

			// One-time migration: set status for existing threads based on last message sender.
			if ( '' !== $current_version && version_compare( $current_version, '1.1.0', '<' ) ) {
				$this->migrate_thread_statuses();
			}

			update_option( Constants::OPTION_MESSAGING_DB_VERSION, self::DB_VERSION );
		}
	}

	/**
	 * Create all messaging tables using dbDelta.
	 */
	public function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		// -- 1. Message Threads --
		$sql_threads = "CREATE TABLE {$this->get_threads_table()} (
			thread_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NOT NULL,
			appointment_id bigint(20) unsigned DEFAULT NULL,
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'open',
			assigned_to bigint(20) unsigned DEFAULT NULL,
			last_message_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (thread_id),
			KEY idx_client (client_id, last_message_at),
			KEY idx_appointment (appointment_id),
			KEY idx_status_last_msg (status, last_message_at)
		) $charset_collate;";

		// -- 2. Messages --
		$sql_messages = "CREATE TABLE {$this->get_messages_table()} (
			message_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			thread_id bigint(20) unsigned NOT NULL,
			sender_id bigint(20) unsigned NOT NULL,
			recipient_id bigint(20) unsigned NOT NULL,
			body longtext NOT NULL,
			is_read tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (message_id),
			KEY idx_thread (thread_id, created_at),
			KEY idx_recipient_unread (recipient_id, is_read),
			KEY idx_sender (sender_id, created_at)
		) $charset_collate;";

		// -- 3. Message Attachments --
		$sql_attachments = "CREATE TABLE {$this->get_attachments_table()} (
			attachment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id bigint(20) unsigned NOT NULL,
			file_name varchar(255) NOT NULL,
			file_path varchar(500) NOT NULL,
			file_type varchar(100) NOT NULL,
			file_size bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (attachment_id),
			KEY idx_message (message_id)
		) $charset_collate;";

		// -- 4. Client Timeline --
		$sql_timeline = "CREATE TABLE {$this->get_timeline_table()} (
			timeline_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NOT NULL,
			event_type varchar(30) NOT NULL,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			summary varchar(500) NOT NULL DEFAULT '',
			event_date datetime NOT NULL,
			PRIMARY KEY  (timeline_id),
			KEY idx_client_date (client_id, event_date),
			KEY idx_event_type (event_type),
			UNIQUE KEY idx_dedup (client_id, event_type, source_id)
		) $charset_collate;";

		dbDelta( $sql_threads );
		dbDelta( $sql_messages );
		dbDelta( $sql_attachments );
		dbDelta( $sql_timeline );
	}

	/**
	 * Secure file storage directory setup.
	 *
	 * Writes an .htaccess (Apache 2.2 + 2.4 syntax), a web.config (IIS),
	 * and a blank index.php into the secure upload dir. Files on disk are
	 * also AES-256-GCM-encrypted via File_Encryption_Handler, so an attacker
	 * who directly fetches one gets ciphertext + GCM nonce but no plaintext
	 * without also compromising the wp-config encryption key.
	 *
	 * NOTE: .htaccess is IGNORED on nginx. On nginx-fronted sites the
	 * encrypted blobs at wp-content/uploads/clisyc-secure/ are directly
	 * reachable via their hash URLs. The at-rest encryption is the
	 * load-bearing defense there; this method sets an admin transient so
	 * the settings page can surface a warning telling the operator to add
	 * a server-level `location /wp-content/uploads/clisyc-secure/ { deny all; }`
	 * block.
	 */
	public function create_secure_upload_dir(): void {
		$upload_dir = wp_upload_dir();
		$secure_dir = $upload_dir['basedir'] . '/clisyc-secure';

		if ( ! is_dir( $secure_dir ) ) {
			wp_mkdir_p( $secure_dir );
		}

		// .htaccess — deny direct access. Emit both Apache 2.2 and 2.4
		// directives; the modern one takes effect on 2.4 without
		// mod_access_compat, and the legacy one covers older hosts.
		$htaccess = $secure_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Deny all direct access to encrypted messaging attachments.\n"
				. "# Apache 2.4+:\n"
				. "<IfModule mod_authz_core.c>\n"
				. "    Require all denied\n"
				. "</IfModule>\n"
				. "# Apache 2.2 (and 2.4 with mod_access_compat):\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "    Order deny,allow\n"
				. "    Deny from all\n"
				. "</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, $rules );
		}

		// web.config — IIS equivalent of the .htaccess deny rule.
		$web_config = $secure_dir . '/web.config';
		if ( ! file_exists( $web_config ) ) {
			$iis_rules = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<configuration>\n"
				. "    <system.webServer>\n"
				. "        <authorization>\n"
				. "            <deny users=\"*\" />\n"
				. "        </authorization>\n"
				. "    </system.webServer>\n"
				. "</configuration>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $web_config, $iis_rules );
		}

		// Blank index.php to prevent directory listing (works on all servers).
		$index = $secure_dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, '<?php // Silence is golden.' );
		}

		// nginx detection — .htaccess / web.config do nothing there. Set
		// a flag the admin notice / settings page can surface so the
		// operator knows at-rest encryption is the only defense and they
		// need to add a `location /wp-content/uploads/clisyc-secure/ {
		// deny all; }` block at the server-config level. The option key is
		// a literal here so the constants map doesn't have to change in
		// lockstep with this file.
		$server = isset( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) )
			: '';
		if ( false !== strpos( $server, 'nginx' ) ) {
			update_option( 'clisyc_messaging_nginx_warning', 1, false );
		} else {
			delete_option( 'clisyc_messaging_nginx_warning' );
		}
	}

	/**
	 * One-time migration: set thread statuses based on who sent the last message.
	 * Threads where the last message sender is NOT an admin get 'awaiting_reply'.
	 */
	private function migrate_thread_statuses(): void {
		global $wpdb;

		$threads_table  = $this->get_threads_table();
		$messages_table = $this->get_messages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$threads = $wpdb->get_results(
			"SELECT t.thread_id, m.sender_id
			 FROM {$threads_table} t
			 INNER JOIN {$messages_table} m ON m.message_id = (
				 SELECT message_id FROM {$messages_table}
				 WHERE thread_id = t.thread_id
				 ORDER BY created_at DESC
				 LIMIT 1
			 )"
		);

		foreach ( $threads as $thread ) {
			if ( ! user_can( (int) $thread->sender_id, 'edit_others_posts' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$threads_table,
					[ 'status' => Constants::THREAD_STATUS_AWAITING_REPLY ],
					[ 'thread_id' => $thread->thread_id ],
					[ '%s' ],
					[ '%d' ]
				);
			}
		}
	}

	// ── Table Name Helpers ──────────────────────────────────────────

	public function get_threads_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_message_threads';
	}

	public function get_messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_messages';
	}

	public function get_attachments_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_message_attachments';
	}

	public function get_timeline_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'clisyc_client_timeline';
	}
}
