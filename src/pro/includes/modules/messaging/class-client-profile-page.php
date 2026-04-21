<?php
/**
 * File: src/pro/includes/modules/messaging/class-client-profile-page.php
 * Admin page: Client Sync > Clients — Unified client timeline with messaging.
 *
 * Provides a client search/selector, unified timeline with type filters,
 * thread list, and a "Send Message" button.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Client_Profile_Page {

	/** @var Message_Repository|null */
	private $message_repo;

	/** @var Timeline_Repository|null */
	private $timeline_repo;

	public function __construct( ?Message_Repository $message_repo, ?Timeline_Repository $timeline_repo ) {
		$this->message_repo  = $message_repo;
		$this->timeline_repo = $timeline_repo;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue CSS/JS on our admin page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'clisyc-clients' ) ) {
			return;
		}

		$base_url = defined( 'clisyc_PLUGIN_URL' ) ? clisyc_PLUGIN_URL : plugin_dir_url( dirname( __DIR__, 4 ) );

		wp_enqueue_style(
			'clisyc-messaging-admin',
			$base_url . 'assets/css/clisyc-messaging-admin.css',
			[],
			defined( 'CLISYC_VERSION' ) ? CLISYC_VERSION : '1.0.0'
		);

		wp_enqueue_script(
			'clisyc-messaging-admin',
			$base_url . 'assets/js/clisyc-messaging-admin.js',
			[ 'jquery', 'wp-api-fetch' ],
			defined( 'CLISYC_VERSION' ) ? CLISYC_VERSION : '1.0.0',
			true
		);

		wp_localize_script( 'clisyc-messaging-admin', 'clisycMessaging', [
			'restBase'      => rest_url( 'client-sync-pro/v1/' ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'currentUserId' => get_current_user_id(),
			'isAdmin'       => Messaging_Permissions::is_admin( get_current_user_id() ),
			'canInitiate'   => Messaging_Permissions::can_initiate( get_current_user_id() ),
			'pollInterval'  => (int) get_option( Constants::OPTION_MESSAGING_POLL_INTERVAL, 30 ),
			'realtimeEnabled' => (bool) get_option( Constants::OPTION_MESSAGING_REALTIME, false ),
			'strings'       => [
				'sendMessage'    => __( 'Send Message', 'client-sync-pro' ),
				'newThread'      => __( 'New Conversation', 'client-sync-pro' ),
				'noMessages'     => __( 'No messages yet.', 'client-sync-pro' ),
				'noEvents'       => __( 'No timeline events found.', 'client-sync-pro' ),
				'loading'        => __( 'Loading…', 'client-sync-pro' ),
				'sendFailed'     => __( 'Failed to send message.', 'client-sync-pro' ),
				'allTypes'       => __( 'All', 'client-sync-pro' ),
				'messagePlaceholder' => __( 'Type your message…', 'client-sync-pro' ),
				'subjectPlaceholder' => __( 'Subject (optional)', 'client-sync-pro' ),
				'selectClient'   => __( 'Search and select a client…', 'client-sync-pro' ),
			],
		] );
	}

	/**
	 * Render the Clients admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'client-sync-pro' ) );
		}

		// Check if viewing a specific client.
		$client_id = isset( $_GET['client_id'] ) ? absint( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$thread_id = isset( $_GET['thread_id'] ) ? absint( $_GET['thread_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		include __DIR__ . '/views/view-client-timeline.php';
	}
}
