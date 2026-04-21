<?php
/**
 * File: src/pro/includes/modules/messaging/class-appointment-messages.php
 * Injects an appointment-scoped message thread into the frontend appointment detail view.
 *
 * Hooks into the `clisyc_after_appointment_detail_sections` action to render
 * a compact messaging section with a reply form.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Appointment_Messages {

	/** @var Message_Repository */
	private $message_repo;

	public function __construct( Message_Repository $message_repo ) {
		$this->message_repo = $message_repo;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'clisyc_after_appointment_detail_sections', [ $this, 'render_appointment_thread' ], 10, 1 );
	}

	/**
	 * Render the appointment-scoped message thread section.
	 *
	 * @param \WP_Post $appointment_post The appointment post object.
	 */
	public function render_appointment_thread( \WP_Post $appointment_post ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		// Only show to the appointment owner or admins.
		if ( (int) $appointment_post->post_author !== $user_id && ! Messaging_Permissions::is_admin( $user_id ) ) {
			return;
		}

		// Check if user can at least reply.
		if ( ! Messaging_Permissions::can_reply( $user_id ) ) {
			return;
		}

		// Get or find the appointment-scoped thread.
		$thread   = $this->message_repo->get_thread_for_appointment( $appointment_post->ID );
		$messages = [];

		if ( $thread ) {
			$messages = $this->message_repo->get_messages_for_thread( (int) $thread->thread_id, 1, 20 );
			// Mark as read for current user.
			$this->message_repo->mark_thread_as_read( (int) $thread->thread_id, $user_id );
		}

		// Enqueue frontend messaging assets.
		$this->enqueue_assets();

		// Render the compact message thread section.
		include __DIR__ . '/views/view-message-thread.php';
	}

	/**
	 * Enqueue frontend assets for appointment messaging.
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

		$user_id = get_current_user_id();

		// Only localize once.
		static $localized = false;
		if ( ! $localized ) {
			wp_localize_script( 'clisyc-messaging-frontend', 'clisycMessaging', [
				'restBase'        => rest_url( 'client-sync-pro/v1/' ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'currentUserId'   => $user_id,
				'isAdmin'         => Messaging_Permissions::is_admin( $user_id ),
				'canInitiate'     => Messaging_Permissions::can_initiate( $user_id ),
				'canReply'        => Messaging_Permissions::can_reply( $user_id ),
				'pollInterval'    => (int) get_option( Constants::OPTION_MESSAGING_POLL_INTERVAL, 30 ),
				'realtimeEnabled' => (bool) get_option( Constants::OPTION_MESSAGING_REALTIME, false ),
				'maxFileSize'     => (int) get_option( Constants::OPTION_MESSAGING_MAX_FILE_SIZE, 10 * 1024 * 1024 ),
				'allowedTypes'    => get_option( Constants::OPTION_MESSAGING_ALLOWED_TYPES, 'image/jpeg,image/png,application/pdf' ),
				'strings'         => [
					'send'              => __( 'Send', 'client-sync-pro' ),
					'loading'           => __( 'Loading…', 'client-sync-pro' ),
					'sendFailed'        => __( 'Failed to send message. Please try again.', 'client-sync-pro' ),
					'messagePlaceholder' => __( 'Type your message…', 'client-sync-pro' ),
					'attachFile'        => __( 'Attach File', 'client-sync-pro' ),
					'you'               => __( 'You', 'client-sync-pro' ),
				],
			] );
			$localized = true;
		}
	}
}
