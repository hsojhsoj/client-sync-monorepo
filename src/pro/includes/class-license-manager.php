<?php
/**
 * File: src/pro/includes/class-license-manager.php
 * Manages the Pro license key activation, validation, and deactivation.
 *
 * REFACTORED: Now hooks into 'clisyc_register_extra_submenu_items' for modular menu registration.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Admin
 */

namespace ClientSyncPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class License_Manager {
	const OPTION_NAME   = 'clisyc_pro_license_data';
	const WHMCS_API_URL = 'https://dependentmedia.com/hosting/modules/servers/licensing/verify.php';

	/**
	 * Register hooks for the license manager.
	 */
	public function register_hooks() {
		// REFACTORED: Hook into the core plugin's dynamic menu action instead of 'admin_menu'.
		add_action( 'clisyc_register_extra_submenu_items', [ $this, 'add_license_menu_item' ] );

		add_action( 'admin_post_clisyc_pro_activate_license', [ $this, 'handle_license_activation' ] );
		add_action( 'admin_post_clisyc_pro_deactivate_license', [ $this, 'handle_license_deactivation' ] );

		// Schedule weekly license re-validation.
		$this->schedule_revalidation();
		add_action( 'clisyc_license_revalidation', [ $this, 'revalidate_license' ] );
	}

	/**
	 * Schedule a weekly cron event to re-validate the license with the server.
	 */
	private function schedule_revalidation(): void {
		if ( ! wp_next_scheduled( 'clisyc_license_revalidation' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'clisyc_license_revalidation' );
		}
	}

	/**
	 * Re-validate the stored license key with the remote server.
	 * Called by the weekly cron event.
	 */
	public function revalidate_license(): void {
		$license_data = get_option( self::OPTION_NAME, [] );
		$license_key  = $license_data['key'] ?? '';

		if ( empty( $license_key ) ) {
			return;
		}

		$this->validate_license_key( $license_key );
	}

	/**
	 * Adds the License page to the main Client Sync menu.
	 *
	 * @param string $main_page_slug The slug of the parent menu item passed by the hook.
	 */
	public function add_license_menu_item( $main_page_slug ) {
		add_submenu_page(
			$main_page_slug,
			__( 'License', 'client-sync-pro' ),
			__( 'License', 'client-sync-pro' ),
			'manage_options',
			'client-sync-pro-license',
			[ $this, 'render_license_page' ]
		);
	}

	public function render_license_page() {
		$license_data   = get_option( self::OPTION_NAME, [] );
		$license_key    = $license_data['key'] ?? '';
		$license_status = $license_data['status'] ?? 'Inactive';
		$expires        = $license_data['expires'] ?? '';
		include_once plugin_dir_path( __FILE__ ) . 'views/view-license-page.php';
	}

	public function handle_license_activation() {
		check_admin_referer( 'clisyc_pro_license_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync-pro' ) );
		}

		$license_key = sanitize_text_field( wp_unslash( $_POST['clisyc_pro_license_key'] ?? '' ) );
		$this->validate_license_key( $license_key );

		wp_safe_redirect( admin_url( 'admin.php?page=client-sync-pro-license' ) );
		exit;
	}

	public function handle_license_deactivation() {
		check_admin_referer( 'clisyc_pro_license_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync-pro' ) );
		}

		delete_option( self::OPTION_NAME );
		set_transient( 'clisyc_pro_admin_notice', __( 'License deactivated successfully.', 'client-sync-pro' ), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=client-sync-pro-license' ) );
		exit;
	}

	private function validate_license_key( string $license_key ) {
		if ( empty( $license_key ) ) {
			set_transient( 'clisyc_pro_admin_notice', __( 'Please enter a license key.', 'client-sync-pro' ), 30 );
			return;
		}

		$request_args = [
			'body' => [
				'licensekey' => $license_key,
				'domain'     => home_url(),
			],
		];

		$response = wp_remote_post( self::WHMCS_API_URL, $request_args );

		if ( is_wp_error( $response ) ) {
			set_transient( 'clisyc_pro_admin_notice', __( 'Error connecting to the license server: ', 'client-sync-pro' ) . $response->get_error_message(), 30 );
			return;
		}

		$response_body = wp_remote_retrieve_body( $response );

		// Use regex to extract values from the XML-like string returned by WHMCS.
		$status        = $this->extract_from_response_body( 'status', $response_body );
		$next_due_date = $this->extract_from_response_body( 'nextduedate', $response_body );
		$message       = $this->extract_from_response_body( 'message', $response_body );

		if ( 'Active' === $status ) {
			$data = [
				'key'     => $license_key,
				'status'  => 'Active',
				'expires' => $next_due_date ?? '',
			];
			// Sign the license data with a site-specific HMAC to prevent local tampering.
			$data['signature'] = wp_hash( wp_json_encode( [
				'key'     => $data['key'],
				'status'  => $data['status'],
				'expires' => $data['expires'],
			] ) );

			update_option( self::OPTION_NAME, $data );
			set_transient( 'clisyc_pro_admin_notice', __( 'License activated successfully!', 'client-sync-pro' ), 30 );
		} else {
			delete_option( self::OPTION_NAME );
			$error_message = $message ?: __( 'Invalid license key or error from license server.', 'client-sync-pro' );
			set_transient( 'clisyc_pro_admin_notice', $error_message, 30 );
		}
	}

	/**
	 * Helper function to extract a value from the WHMCS XML-like response body.
	 *
	 * @param string $key The key of the tag to extract (e.g., 'status').
	 * @param string $body The raw response body.
	 * @return string|null The extracted value or null if not found.
	 */
	private function extract_from_response_body( string $key, string $body ): ?string {
		// Escape the key for safe use in regex pattern.
		$escaped_key = preg_quote( $key, '/' );
		if ( preg_match( "/<{$escaped_key}>(.*?)<\/{$escaped_key}>/i", $body, $matches ) ) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Check if the stored license is active, unexpired, and untampered.
	 *
	 * @return bool True if the license is valid and active.
	 */
	public static function is_license_active(): bool {
		$license_data = get_option( self::OPTION_NAME, [] );

		// Must have Active status.
		if ( empty( $license_data['status'] ) || 'Active' !== $license_data['status'] ) {
			return false;
		}

		// Verify HMAC signature to detect local tampering (e.g., via WP-CLI or DB edit).
		$stored_signature = $license_data['signature'] ?? '';
		if ( ! empty( $stored_signature ) ) {
			$expected_signature = wp_hash( wp_json_encode( [
				'key'     => $license_data['key'] ?? '',
				'status'  => $license_data['status'],
				'expires' => $license_data['expires'] ?? '',
			] ) );

			if ( ! hash_equals( $expected_signature, $stored_signature ) ) {
				return false;
			}
		}

		// Check expiry date if present.
		// WHMCS returns '0000-00-00' for licenses without a renewal date (e.g., lifetime).
		$expires = $license_data['expires'] ?? '';
		if ( ! empty( $expires ) && '0000-00-00' !== $expires ) {
			$expiry_time = strtotime( $expires );
			if ( false !== $expiry_time && $expiry_time < time() ) {
				return false;
			}
		}

		return true;
	}
}