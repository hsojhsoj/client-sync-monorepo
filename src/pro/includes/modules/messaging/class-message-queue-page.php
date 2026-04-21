<?php
/**
 * File: src/pro/includes/modules/messaging/class-message-queue-page.php
 * Admin page for the Message Queue (shared admin inbox).
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Message_Queue_Page {

	/** @var Message_Repository */
	private $repo;

	public function __construct( Message_Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Register admin hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue CSS/JS on the queue admin page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'clisyc-message-queue' ) ) {
			return;
		}

		$base_url = defined( 'clisyc_PLUGIN_URL' ) ? clisyc_PLUGIN_URL : plugin_dir_url( dirname( __DIR__, 4 ) );
		$version  = defined( 'CLISYC_VERSION' ) ? CLISYC_VERSION : '1.0.0';

		// Reuse existing messaging admin CSS.
		wp_enqueue_style(
			'clisyc-messaging-admin',
			$base_url . 'assets/css/clisyc-messaging-admin.css',
			[],
			$version
		);

		// Queue-specific CSS.
		wp_enqueue_style(
			'clisyc-message-queue',
			$base_url . 'assets/css/clisyc-message-queue.css',
			[ 'clisyc-messaging-admin' ],
			$version
		);

		// Queue-specific JS.
		wp_enqueue_script(
			'clisyc-message-queue',
			$base_url . 'assets/js/clisyc-message-queue.js',
			[ 'jquery', 'wp-api-fetch' ],
			$version,
			true
		);

		wp_localize_script( 'clisyc-message-queue', 'clisycQueue', [
			'restBase'       => rest_url( 'client-sync-pro/v1/' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'currentUserId'  => get_current_user_id(),
			'pollInterval'   => (int) get_option( Constants::OPTION_MESSAGING_POLL_INTERVAL, 30 ),
			'clientsPageUrl' => admin_url( 'admin.php?page=clisyc-clients' ),
			'strings'        => [
				'loading'           => __( 'Loading...', 'client-sync-pro' ),
				'noThreads'         => __( 'No conversations in the queue.', 'client-sync-pro' ),
				'markResolved'      => __( 'Mark Resolved', 'client-sync-pro' ),
				'reopen'            => __( 'Reopen', 'client-sync-pro' ),
				'awaitingReply'     => __( 'Awaiting Reply', 'client-sync-pro' ),
				'open'              => __( 'Open', 'client-sync-pro' ),
				'resolved'          => __( 'Resolved', 'client-sync-pro' ),
				'allActive'         => __( 'All Active', 'client-sync-pro' ),
				'newestFirst'       => __( 'Newest First', 'client-sync-pro' ),
				'oldestUnanswered'  => __( 'Oldest Unanswered', 'client-sync-pro' ),
				'sendMessage'       => __( 'Send', 'client-sync-pro' ),
				'sendFailed'        => __( 'Failed to send message.', 'client-sync-pro' ),
				'statusUpdated'     => __( 'Status updated.', 'client-sync-pro' ),
				'selectThread'      => __( 'Select a conversation from the queue.', 'client-sync-pro' ),
				'noMessages'        => __( 'No messages in this thread.', 'client-sync-pro' ),
				'messages'          => __( 'messages', 'client-sync-pro' ),
				'viewClient'        => __( 'View Client', 'client-sync-pro' ),
			],
		] );
	}

	/**
	 * Render the Message Queue admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'client-sync-pro' ) );
		}

		include __DIR__ . '/views/view-message-queue.php';
	}
}
