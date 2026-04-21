<?php
/**
 * File: src/pro/includes/modules/messaging/class-inbox-shortcode.php
 * Frontend [clisyc_inbox] shortcode — full inbox UI for clients and admins.
 *
 * Thread list on the left/top, message view on the right/bottom.
 * Compose button, unread indicators, file attachment support.
 * Data loaded via REST API + wp_localize_script.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Inbox_Shortcode {

	/** @var Message_Repository */
	private $message_repo;

	public function __construct( Message_Repository $message_repo ) {
		$this->message_repo = $message_repo;
	}

	/**
	 * Render the [clisyc_inbox] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render( $atts = [] ): string {
		if ( ! is_user_logged_in() ) {
			return '<p class="clisyc-inbox-login-required">' .
				esc_html__( 'Please log in to view your messages.', 'client-sync-pro' ) .
				'</p>';
		}

		$user_id  = get_current_user_id();
		$is_admin = Messaging_Permissions::is_admin( $user_id );

		// Enqueue frontend assets.
		$this->enqueue_assets();

		// Get initial data for the inbox.
		$unread_count = $this->message_repo->get_unread_count( $user_id );

		ob_start();
		include __DIR__ . '/views/view-inbox.php';
		return ob_get_clean();
	}

	/**
	 * Enqueue frontend CSS/JS.
	 */
	private function enqueue_assets(): void {
		$base_url = defined( 'clisyc_PLUGIN_URL' ) ? clisyc_PLUGIN_URL : plugin_dir_url( dirname( __DIR__, 4 ) );

		wp_enqueue_style(
			'clisyc-messaging-frontend',
			$base_url . 'assets/css/clisyc-messaging-frontend.css',
			[],
			defined( 'CLISYC_VERSION' ) ? CLISYC_VERSION : '1.0.0'
		);

		wp_enqueue_script(
			'clisyc-messaging-frontend',
			$base_url . 'assets/js/clisyc-messaging-frontend.js',
			[ 'jquery', 'wp-api-fetch' ],
			defined( 'CLISYC_VERSION' ) ? CLISYC_VERSION : '1.0.0',
			true
		);

		$user_id  = get_current_user_id();
		$is_admin = Messaging_Permissions::is_admin( $user_id );

		wp_localize_script( 'clisyc-messaging-frontend', 'clisycMessaging', [
			'restBase'        => rest_url( 'client-sync-pro/v1/' ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'currentUserId'   => $user_id,
			'isAdmin'         => $is_admin,
			'canInitiate'     => Messaging_Permissions::can_initiate( $user_id ),
			'canReply'        => Messaging_Permissions::can_reply( $user_id ),
			'pollInterval'    => (int) get_option( Constants::OPTION_MESSAGING_POLL_INTERVAL, 30 ),
			'realtimeEnabled' => (bool) get_option( Constants::OPTION_MESSAGING_REALTIME, false ),
			'maxFileSize'     => (int) get_option( Constants::OPTION_MESSAGING_MAX_FILE_SIZE, 10 * 1024 * 1024 ),
			'allowedTypes'    => get_option( Constants::OPTION_MESSAGING_ALLOWED_TYPES, 'image/jpeg,image/png,application/pdf' ),
			'strings'         => [
				'send'              => __( 'Send', 'client-sync-pro' ),
				'compose'           => __( 'New Message', 'client-sync-pro' ),
				'noThreads'         => __( 'No conversations yet.', 'client-sync-pro' ),
				'noMessages'        => __( 'No messages in this conversation.', 'client-sync-pro' ),
				'loading'           => __( 'Loading…', 'client-sync-pro' ),
				'sendFailed'        => __( 'Failed to send message. Please try again.', 'client-sync-pro' ),
				'uploadFailed'      => __( 'File upload failed.', 'client-sync-pro' ),
				'fileTooLarge'      => __( 'File is too large.', 'client-sync-pro' ),
				'fileTypeNotAllowed' => __( 'File type is not allowed.', 'client-sync-pro' ),
				'messagePlaceholder' => __( 'Type your message…', 'client-sync-pro' ),
				'subjectPlaceholder' => __( 'Subject (optional)', 'client-sync-pro' ),
				'attachFile'        => __( 'Attach File', 'client-sync-pro' ),
				'unread'            => __( 'unread', 'client-sync-pro' ),
				'you'               => __( 'You', 'client-sync-pro' ),
			],
		] );
	}
}
