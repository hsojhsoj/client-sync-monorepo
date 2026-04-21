<?php
/**
 * File: src/pro/includes/modules/messaging/class-file-encryption-handler.php
 * Encrypts uploaded files at rest and decrypts them on-the-fly for download.
 *
 * Files are stored in wp-content/uploads/clisyc-secure/ with .enc extension
 * and a random hash filename. The directory is protected by .htaccess.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Services\Encryption_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class File_Encryption_Handler {

	/** @var Encryption_Service */
	private $encryption;

	public function __construct() {
		$this->encryption = Encryption_Service::get_instance();
	}

	/**
	 * Validate, encrypt, and store an uploaded file.
	 *
	 * @param array $uploaded_file Entry from $_FILES (tmp_name, name, type, size).
	 * @return array{file_path: string, file_size: int, file_name: string, file_type: string}|false
	 *         File info on success, false on failure.
	 */
	public function encrypt_and_store( array $uploaded_file ) {
		// Validate size.
		$max_size = (int) get_option( Constants::OPTION_MESSAGING_MAX_FILE_SIZE, 10 * 1024 * 1024 );
		if ( $uploaded_file['size'] > $max_size ) {
			return false;
		}

		// Validate MIME type.
		$allowed_types = $this->get_allowed_types();
		$file_type     = wp_check_filetype( $uploaded_file['name'] );
		$mime_type     = ! empty( $file_type['type'] ) ? $file_type['type'] : $uploaded_file['type'];

		if ( ! empty( $allowed_types ) && ! in_array( $mime_type, $allowed_types, true ) ) {
			return false;
		}

		// Read file contents.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$plaintext = file_get_contents( $uploaded_file['tmp_name'] );
		if ( false === $plaintext ) {
			return false;
		}

		// Encrypt.
		$encrypted = $this->encryption->encrypt( $plaintext );
		if ( false === $encrypted ) {
			return false;
		}

		// Generate storage path.
		$storage_path = $this->generate_storage_path();
		$dir          = dirname( $storage_path );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Write encrypted file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $storage_path, $encrypted );
		if ( false === $written ) {
			return false;
		}

		// Clean up temp file.
		if ( file_exists( $uploaded_file['tmp_name'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $uploaded_file['tmp_name'] );
		}

		return [
			'file_path' => $storage_path,
			'file_size' => $uploaded_file['size'],
			'file_name' => sanitize_file_name( $uploaded_file['name'] ),
			'file_type' => $mime_type,
		];
	}

	/**
	 * Decrypt a stored file and stream it to the client.
	 *
	 * @param string $encrypted_path Path to the encrypted file on disk.
	 * @param string $original_name  Original filename for Content-Disposition.
	 * @param string $mime_type      MIME type for Content-Type header.
	 */
	public function decrypt_and_stream( string $encrypted_path, string $original_name, string $mime_type ): void {
		if ( ! file_exists( $encrypted_path ) ) {
			wp_die( esc_html__( 'File not found.', 'client-sync-pro' ), 404 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$encrypted = file_get_contents( $encrypted_path );
		if ( false === $encrypted ) {
			wp_die( esc_html__( 'Could not read file.', 'client-sync-pro' ), 500 );
		}

		$plaintext = $this->encryption->decrypt( $encrypted );
		if ( false === $plaintext ) {
			wp_die( esc_html__( 'Could not decrypt file.', 'client-sync-pro' ), 500 );
		}

		// Prevent output buffering interference.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $original_name ) . '"' );
		header( 'Content-Length: ' . strlen( $plaintext ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file content.
		echo $plaintext;
		exit;
	}

	/**
	 * Delete an encrypted file from disk.
	 *
	 * @param string $encrypted_path Path to the encrypted file.
	 * @return bool True if deleted or didn't exist, false on failure.
	 */
	public function delete_encrypted_file( string $encrypted_path ): bool {
		if ( ! file_exists( $encrypted_path ) ) {
			return true; // Already gone.
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return unlink( $encrypted_path );
	}

	// ── Private Helpers ────────────────────────────────────────────

	/**
	 * Get the secure upload base directory.
	 *
	 * @return string Absolute path to the secure upload directory.
	 */
	private function get_secure_upload_dir(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/clisyc-secure';
	}

	/**
	 * Generate a unique storage path for an encrypted file.
	 *
	 * @return string Absolute path like /uploads/clisyc-secure/2026/02/a1b2c3d4e5f6.enc
	 */
	private function generate_storage_path(): string {
		$base_dir = $this->get_secure_upload_dir();
		$year     = gmdate( 'Y' );
		$month    = gmdate( 'm' );
		$hash     = bin2hex( random_bytes( 16 ) ); // 32-char hex string.

		return "{$base_dir}/{$year}/{$month}/{$hash}.enc";
	}

	/**
	 * Get allowed MIME types from settings.
	 *
	 * @return array Array of allowed MIME type strings.
	 */
	private function get_allowed_types(): array {
		$setting = get_option( Constants::OPTION_MESSAGING_ALLOWED_TYPES, 'image/jpeg,image/png,application/pdf' );

		if ( empty( $setting ) ) {
			return []; // Empty = allow all.
		}

		return array_map( 'trim', explode( ',', $setting ) );
	}
}
