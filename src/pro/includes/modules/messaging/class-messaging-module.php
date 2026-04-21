<?php
/**
 * File: src/pro/includes/modules/messaging/class-messaging-module.php
 * Main entry point for the Messaging module.
 *
 * Provides encrypted bidirectional messaging between practitioners and clients,
 * encrypted file attachments, and a unified per-client activity timeline.
 *
 * Auto-discovered by Module_Manager: directory "messaging" → class Messaging_Module.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use ClientSyncPro\Module_Interface;
use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Messaging_Module implements Module_Interface {

	/** @var Message_Queue_Page|null */
	private $queue_page;

	/**
	 * Initialize the module.
	 * Called automatically by Module_Manager after loading the file.
	 */
	public function initialize() {
		// Gate 1: License check.
		if ( ! function_exists( 'clisyc_pro_is_license_active' ) || ! \clisyc_pro_is_license_active() ) {
			return;
		}

		// Gate 2: Messaging must be enabled in settings.
		if ( ! get_option( Constants::OPTION_MESSAGING_ENABLED, false ) ) {
			return;
		}

		// Load all component files.
		$this->load_dependencies();

		// Ensure database tables exist.
		$schema = new Messaging_Schema();
		$schema->maybe_create_tables();
		$schema->create_secure_upload_dir();

		// Initialize core repositories.
		$message_repo  = new Message_Repository( $schema );
		$timeline_repo = new Timeline_Repository( $schema );

		// Timeline event bridge — hooks existing WordPress actions into timeline.
		$event_bridge = new Timeline_Event_Bridge( $timeline_repo );
		$event_bridge->register_hooks();

		// REST API routes.
		add_action( 'rest_api_init', function () use ( $schema, $message_repo, $timeline_repo ) {
			( new Messages_Rest_Controller( $message_repo, $schema ) )->register_routes();
			( new Threads_Rest_Controller( $message_repo ) )->register_routes();
			( new Timeline_Rest_Controller( $timeline_repo ) )->register_routes();
			( new Attachments_Rest_Controller( $message_repo, new File_Encryption_Handler() ) )->register_routes();
			( new Queue_Rest_Controller( $message_repo ) )->register_routes();
		} );

		// Admin UI.
		$client_profile = new Client_Profile_Page( $message_repo, $timeline_repo );
		$client_profile->register_hooks();

		$meta_box = new Timeline_Meta_Box( $message_repo, $timeline_repo );
		$meta_box->register_hooks();

		$dashboard_widget = new Messages_Dashboard_Widget( $message_repo );
		$dashboard_widget->register_hooks();

		// Queue (shared admin inbox).
		$this->queue_page = new Message_Queue_Page( $message_repo );
		$this->queue_page->register_hooks();

		// Frontend UI.
		$appointment_messages = new Appointment_Messages( $message_repo );
		$appointment_messages->register_hooks();

		// Settings.
		$settings = new Messaging_Settings();
		$settings->register_hooks();

		// Real-time handler (SSE).
		$realtime = new Realtime_Handler( $message_repo );
		$realtime->register_hooks();

		// Notification events (inject via filter — no free plugin file edits needed).
		add_filter( 'clisyc_notification_event_types', [ $this, 'register_notification_events' ] );

		// Admin menu.
		add_action( 'clisyc_register_extra_submenu_items', [ $this, 'add_menu_items' ] );

		// Frontend shortcode.
		add_shortcode( 'clisyc_inbox', [ new Inbox_Shortcode( $message_repo ), 'render' ] );

		// Cron: message retention cleanup.
		if ( ! wp_next_scheduled( 'clisyc_message_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'clisyc_message_cleanup' );
		}
		add_action( 'clisyc_message_cleanup', function () use ( $message_repo ) {
			$retention = (int) get_option( Constants::OPTION_MESSAGING_RETENTION_DAYS, 0 );
			$deleted   = $message_repo->cleanup_old_messages( $retention );

			if ( $deleted > 0 && function_exists( 'clisyc_audit_log' ) ) {
				clisyc_audit_log( 'cleanup', 'message', 0, [
					'messages_deleted' => $deleted,
					'retention_days'   => $retention,
				] );
			}
		} );
	}

	/**
	 * Load all module component files.
	 */
	private function load_dependencies(): void {
		require_once __DIR__ . '/class-messaging-schema.php';
		require_once __DIR__ . '/class-message-repository.php';
		require_once __DIR__ . '/class-timeline-repository.php';
		require_once __DIR__ . '/class-file-encryption-handler.php';
		require_once __DIR__ . '/class-messaging-permissions.php';
		require_once __DIR__ . '/class-timeline-event-bridge.php';
		require_once __DIR__ . '/class-messages-rest-controller.php';
		require_once __DIR__ . '/class-threads-rest-controller.php';
		require_once __DIR__ . '/class-timeline-rest-controller.php';
		require_once __DIR__ . '/class-attachments-rest-controller.php';
		require_once __DIR__ . '/class-client-profile-page.php';
		require_once __DIR__ . '/class-timeline-meta-box.php';
		require_once __DIR__ . '/class-messages-dashboard-widget.php';
		require_once __DIR__ . '/class-inbox-shortcode.php';
		require_once __DIR__ . '/class-appointment-messages.php';
		require_once __DIR__ . '/class-messaging-settings.php';
		require_once __DIR__ . '/class-realtime-handler.php';
		require_once __DIR__ . '/class-message-queue-page.php';
		require_once __DIR__ . '/class-queue-rest-controller.php';
	}

	/**
	 * Register the Clients admin menu item.
	 *
	 * @param string $main_page_slug The main Client Sync menu slug.
	 */
	public function add_menu_items( string $main_page_slug ): void {
		add_submenu_page(
			$main_page_slug,
			__( 'Clients', 'client-sync-pro' ),
			__( 'Clients', 'client-sync-pro' ),
			'edit_others_posts',
			'clisyc-clients',
			[ new Client_Profile_Page( null, null ), 'render' ]
		);

		// Message Queue with awaiting-reply badge.
		$menu_title = __( 'Message Queue', 'client-sync-pro' );
		$awaiting   = $this->get_awaiting_count_cached();
		if ( $awaiting > 0 ) {
			$menu_title .= sprintf(
				' <span class="update-plugins count-%d"><span class="update-count">%d</span></span>',
				$awaiting,
				$awaiting
			);
		}

		add_submenu_page(
			$main_page_slug,
			__( 'Message Queue', 'client-sync-pro' ),
			$menu_title,
			'edit_others_posts',
			'clisyc-message-queue',
			[ $this->queue_page, 'render' ]
		);
	}

	/**
	 * Get awaiting reply count using a 5-min transient.
	 *
	 * @return int
	 */
	private function get_awaiting_count_cached(): int {
		$count = get_transient( 'clisyc_queue_awaiting_count' );

		if ( false === $count ) {
			// Build repo if needed — queue_page already has repo so just query directly.
			global $wpdb;
			$table = $wpdb->prefix . 'clisyc_message_threads';
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE status = %s",
					Constants::THREAD_STATUS_AWAITING_REPLY
				)
			);
			set_transient( 'clisyc_queue_awaiting_count', $count, 5 * MINUTE_IN_SECONDS );
		}

		return (int) $count;
	}

	/**
	 * Register messaging notification events via filter.
	 *
	 * @param array $events Existing notification event types.
	 * @return array Modified event types array.
	 */
	public function register_notification_events( array $events ): array {
		$events['new_message_client'] = [
			'label'           => __( 'New Message (To Client)', 'client-sync-pro' ),
			'recipient_types' => [ 'client' ],
			'default_subject' => __( 'You have a new message from {message_sender}', 'client-sync-pro' ),
			'default_body'    => __( "Hi {client_name},\n\n{message_sender} has sent you a new message.\n\nView your messages: {message_thread_url}\n\nThanks,\n{site_name}", 'client-sync-pro' ),
			'placeholders'    => [
				'{site_name}', '{site_url}',
				'{client_name}', '{client_email}',
				'{message_sender}', '{message_preview}', '{message_thread_url}',
			],
		];

		$events['new_message_admin'] = [
			'label'           => __( 'New Message (To Admin)', 'client-sync-pro' ),
			'recipient_types' => [ 'admin' ],
			'default_subject' => __( 'New message from {client_name}', 'client-sync-pro' ),
			'default_body'    => __( "Hi,\n\n{client_name} has sent a new message.\n\nPreview: {message_preview}\n\nView in admin: {message_thread_url}\n\nThanks,\n{site_name}", 'client-sync-pro' ),
			'placeholders'    => [
				'{site_name}', '{site_url}',
				'{client_name}', '{client_email}',
				'{message_sender}', '{message_preview}', '{message_thread_url}',
			],
		];

		return $events;
	}
}
