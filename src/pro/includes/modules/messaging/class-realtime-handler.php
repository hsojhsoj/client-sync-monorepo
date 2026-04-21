<?php
/**
 * File: src/pro/includes/modules/messaging/class-realtime-handler.php
 * Server-Sent Events (SSE) handler for real-time message delivery.
 *
 * Three-tier progressive enhancement:
 * 1. SSE — EventSource to ?clisyc_sse=1, 2s check interval, 30s max connection
 * 2. Polling — REST API poll every N seconds (via JS)
 * 3. Page refresh — Default, notifications alert users
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Realtime_Handler {

	/** @var Message_Repository */
	private $repo;

	/** SSE connection timeout in seconds. */
	const SSE_TIMEOUT = 30;

	/** SSE check interval in seconds. */
	const SSE_INTERVAL = 2;

	public function __construct( Message_Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		// SSE endpoint — intercept early via template_redirect.
		add_action( 'template_redirect', [ $this, 'handle_sse_request' ] );

		// Also handle SSE on admin side.
		add_action( 'admin_init', [ $this, 'handle_sse_request' ] );
	}

	/**
	 * Handle SSE requests.
	 *
	 * Intercepted via query param ?clisyc_sse=1.
	 * Client must be authenticated. Response is text/event-stream.
	 */
	public function handle_sse_request(): void {
		if ( empty( $_GET['clisyc_sse'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Must be logged in.
		if ( ! is_user_logged_in() ) {
			status_header( 401 );
			exit;
		}

		// Real-time must be enabled.
		if ( ! get_option( Constants::OPTION_MESSAGING_REALTIME, false ) ) {
			status_header( 403 );
			echo 'Real-time messaging is not enabled.';
			exit;
		}

		$user_id = get_current_user_id();

		// Verify nonce token.
		$token = sanitize_text_field( $_GET['token'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $token, 'clisyc_sse_' . $user_id ) ) {
			status_header( 403 );
			exit;
		}

		// Set SSE headers.
		$this->set_sse_headers();

		// Prevent PHP from timing out.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 0 );

		// Disable output buffering.
		while ( ob_get_level() ) {
			ob_end_flush();
		}

		$start_time  = time();
		$last_count  = $this->repo->get_unread_count( $user_id );
		$last_check  = '';

		// Send initial connection event.
		$this->send_sse_event( 'connected', [
			'unread_count' => $last_count,
			'timestamp'    => gmdate( 'c' ),
		] );

		// Keep connection alive, checking for new messages.
		while ( ( time() - $start_time ) < self::SSE_TIMEOUT ) {
			// Check if client disconnected.
			if ( connection_aborted() ) {
				break;
			}

			$current_count = $this->repo->get_unread_count( $user_id );

			if ( $current_count !== $last_count ) {
				$this->send_sse_event( 'new_message', [
					'unread_count' => $current_count,
					'timestamp'    => gmdate( 'c' ),
				] );
				$last_count = $current_count;
			}

			// Send heartbeat to keep connection alive.
			$this->send_sse_comment( 'heartbeat' );

			// Close DB connection to free resources during sleep.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.wp_remote_post_wp_remote_post
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}

			sleep( self::SSE_INTERVAL );
		}

		// Send reconnect event so client can re-establish.
		$this->send_sse_event( 'timeout', [
			'message' => 'Connection timeout. Reconnecting…',
		] );

		exit;
	}

	// ── SSE Helpers ───────────────────────────────────────────────

	/**
	 * Set the appropriate headers for SSE.
	 */
	private function set_sse_headers(): void {
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' ); // Disable Nginx buffering.
	}

	/**
	 * Send an SSE event.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data (will be JSON-encoded).
	 */
	private function send_sse_event( string $event, array $data ): void {
		echo "event: {$event}\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
		flush();
	}

	/**
	 * Send an SSE comment (heartbeat/keepalive).
	 *
	 * @param string $comment Comment text.
	 */
	private function send_sse_comment( string $comment ): void {
		echo ": {$comment}\n\n";
		flush();
	}
}
