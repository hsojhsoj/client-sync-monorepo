<?php
/**
 * File: src/pro/includes/modules/messaging/class-attachments-rest-controller.php
 * REST API controller for encrypted file attachment upload and download.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Attachments_Rest_Controller extends \WP_REST_Controller {

	protected $namespace = 'client-sync-pro/v1';
	protected $rest_base = 'attachments';

	/** @var Message_Repository */
	private $repo;

	/** @var File_Encryption_Handler */
	private $file_handler;

	public function __construct( Message_Repository $repo, File_Encryption_Handler $file_handler ) {
		$this->repo         = $repo;
		$this->file_handler = $file_handler;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		// POST /attachments — Upload an encrypted file attachment.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'message_id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => 'Message ID to attach the file to.',
						],
					],
				],
			]
		);

		// GET /attachments/{id}/download — Download a decrypted file.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/download',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'download_item' ],
					'permission_callback' => [ $this, 'download_item_permissions_check' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => 'Attachment ID.',
						],
					],
				],
			]
		);
	}

	// ── Permission Callbacks ──────────────────────────────────────

	/**
	 * Permission check for uploading an attachment.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must be logged in.', 'client-sync-pro' ),
				[ 'status' => 401 ]
			);
		}

		$user_id = get_current_user_id();

		// Must have reply permission.
		if ( ! Messaging_Permissions::can_reply( $user_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to upload files.', 'client-sync-pro' ),
				[ 'status' => 403 ]
			);
		}

		// Rate limit uploads.
		if ( class_exists( '\DependentMedia\ClientSync\Utility\Security_Helper' ) ) {
			$rate_check = \DependentMedia\ClientSync\Utility\Security_Helper::check_rate_limit(
				'attachment_upload_' . $user_id,
				10,
				300
			);
			if ( is_wp_error( $rate_check ) ) {
				return $rate_check;
			}
		}

		return true;
	}

	/**
	 * Permission check for downloading an attachment.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool|\WP_Error
	 */
	public function download_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must be logged in.', 'client-sync-pro' ),
				[ 'status' => 401 ]
			);
		}

		$attachment_id = absint( $request->get_param( 'id' ) );
		if ( ! Messaging_Permissions::can_access_attachment( get_current_user_id(), $attachment_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have access to this file.', 'client-sync-pro' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	// ── Callbacks ─────────────────────────────────────────────────

	/**
	 * Upload and encrypt a file attachment.
	 *
	 * Expects multipart/form-data with 'file' field and 'message_id' param.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$message_id = absint( $request->get_param( 'message_id' ) );
		$files      = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'No file uploaded.', 'client-sync-pro' ),
				[ 'status' => 400 ]
			);
		}

		$uploaded_file = $files['file'];

		// Check for upload errors.
		if ( ! empty( $uploaded_file['error'] ) ) {
			return new \WP_Error(
				'rest_upload_error',
				$this->get_upload_error_message( $uploaded_file['error'] ),
				[ 'status' => 400 ]
			);
		}

		// Verify the message exists and the user has access to its thread.
		// SECURITY: this return value MUST be checked — otherwise any user
		// with `can_reply` permission (default role set includes `subscriber`)
		// can upload attachments to threads they don't participate in, by
		// passing a message_id that belongs to someone else's thread.
		if ( ! $this->verify_message_access( $message_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__(
					'You do not have access to that message.',
					'client-sync-pro'
				),
				[ 'status' => 403 ]
			);
		}

		// Encrypt and store.
		$file_info = $this->file_handler->encrypt_and_store( $uploaded_file );
		if ( false === $file_info ) {
			return new \WP_Error(
				'rest_error',
				__( 'File upload failed. Check file size and type restrictions.', 'client-sync-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Create DB record.
		$attachment_id = $this->repo->create_attachment(
			$message_id,
			$file_info['file_name'],
			$file_info['file_path'],
			$file_info['file_type'],
			$file_info['file_size']
		);

		if ( false === $attachment_id ) {
			// Clean up encrypted file if DB insert failed.
			$this->file_handler->delete_encrypted_file( $file_info['file_path'] );

			return new \WP_Error(
				'rest_error',
				__( 'Failed to save attachment record.', 'client-sync-pro' ),
				[ 'status' => 500 ]
			);
		}

		// Audit log.
		if ( function_exists( 'clisyc_audit_log' ) ) {
			clisyc_audit_log( 'attachment_uploaded', 'attachment', $attachment_id, [
				'message_id' => $message_id,
				'file_type'  => $file_info['file_type'],
				'file_size'  => $file_info['file_size'],
				'user_id'    => get_current_user_id(),
			] );
		}

		return new \WP_REST_Response(
			[
				'success'       => true,
				'attachment_id' => $attachment_id,
				'file_name'     => $file_info['file_name'],
				'file_type'     => $file_info['file_type'],
				'file_size'     => $file_info['file_size'],
			],
			201
		);
	}

	/**
	 * Download a decrypted file attachment.
	 *
	 * Streams the file directly to the client — does not return JSON.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_Error|void Error on failure; on success streams file and exits.
	 */
	public function download_item( $request ) {
		$attachment_id = absint( $request->get_param( 'id' ) );

		$attachment = $this->repo->get_attachment( $attachment_id );
		if ( ! $attachment ) {
			return new \WP_Error(
				'rest_not_found',
				__( 'Attachment not found.', 'client-sync-pro' ),
				[ 'status' => 404 ]
			);
		}

		// Audit log.
		if ( function_exists( 'clisyc_audit_log' ) ) {
			clisyc_audit_log( 'attachment_downloaded', 'attachment', $attachment_id, [
				'user_id' => get_current_user_id(),
			] );
		}

		// Stream the decrypted file (this calls exit on success).
		$this->file_handler->decrypt_and_stream(
			$attachment->file_path,
			$attachment->file_name,
			$attachment->file_type
		);
	}

	// ── Private Helpers ───────────────────────────────────────────

	/**
	 * Verify the current user has access to the thread containing a message.
	 *
	 * @param int $message_id Message ID.
	 * @return bool True if accessible.
	 */
	private function verify_message_access( int $message_id ): bool {
		global $wpdb;
		$schema = new Messaging_Schema();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$thread_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT thread_id FROM {$schema->get_messages_table()} WHERE message_id = %d",
				$message_id
			)
		);

		if ( ! $thread_id ) {
			return false;
		}

		return Messaging_Permissions::can_access_thread( get_current_user_id(), (int) $thread_id );
	}

	/**
	 * Get a human-readable error message for PHP upload error codes.
	 *
	 * @param int $error_code PHP upload error code.
	 * @return string Error message.
	 */
	private function get_upload_error_message( int $error_code ): string {
		$messages = [
			UPLOAD_ERR_INI_SIZE   => __( 'File exceeds the server upload size limit.', 'client-sync-pro' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds the form upload size limit.', 'client-sync-pro' ),
			UPLOAD_ERR_PARTIAL    => __( 'File was only partially uploaded.', 'client-sync-pro' ),
			UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'client-sync-pro' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'Server temporary directory is missing.', 'client-sync-pro' ),
			UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'client-sync-pro' ),
			UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the file upload.', 'client-sync-pro' ),
		];

		return $messages[ $error_code ] ?? __( 'Unknown upload error.', 'client-sync-pro' );
	}
}
