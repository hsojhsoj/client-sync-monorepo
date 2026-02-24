<?php
/**
 * File: src/shared/includes/admin/settings/trait-settings-integrations.php
 * Integrations settings registration and callbacks (Google Calendar, Twilio,
 * Zoom, Webhooks).
 *
 * Extracted from Settings_Manager to keep each settings group focused.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Settings
 */

namespace DependentMedia\ClientSync\Admin\Settings;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Settings_Integrations {

	/**
	 * Register integration-related settings, sections, and fields.
	 */
	protected function register_integration_settings(): void {
		$group = 'clisyc_integrations_settings_group';
		$page  = 'clisyc-integrations';

		// --- Google Calendar ---
		register_setting( $group, 'clisyc_google_api_credentials', [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize_google_credentials' ] ] );

		add_settings_section( 'clisyc_google_section', __( 'Google Calendar', 'client-sync' ), [ $this, 'render_google_section_header' ], $page );
		add_settings_field( 'clisyc_google_api_credentials[client_id]', __( 'Client ID', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_google_section', [ 'id' => 'clisyc_google_api_credentials[client_id]', 'type' => 'text', 'class' => 'regular-text', 'option_name' => 'clisyc_google_api_credentials', 'path' => ['client_id'], 'desc' => __( 'From your Google Cloud Console OAuth 2.0 credentials.', 'client-sync' ) ] );
		add_settings_field( 'clisyc_google_api_credentials[client_secret]', __( 'Client Secret', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_google_section', [ 'id' => 'clisyc_google_api_credentials[client_secret]', 'type' => 'password', 'class' => 'regular-text', 'option_name' => 'clisyc_google_api_credentials', 'path' => ['client_secret'], 'desc' => __( 'Keep this secret. Never share publicly.', 'client-sync' ) ] );

		// --- Twilio SMS ---
		register_setting( $group, 'clisyc_sms_credentials', [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize_sms_credentials' ] ] );
		register_setting( $group, 'clisyc_webhooks', [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize_webhooks' ] ] );

		add_settings_section( 'clisyc_twilio_section', __( 'Twilio SMS', 'client-sync' ), [ $this, 'render_twilio_section_header' ], $page );
		add_settings_field( 'clisyc_sms_credentials[twilio_sid]', __('Account SID', 'client-sync'), [$this, 'render_settings_field_callback'], $page, 'clisyc_twilio_section', ['id' => 'clisyc_sms_credentials[twilio_sid]', 'type' => 'text', 'class' => 'regular-text', 'option_name' => 'clisyc_sms_credentials', 'path' => ['twilio_sid']]);
		add_settings_field( 'clisyc_sms_credentials[twilio_token]', __('Auth Token', 'client-sync'), [$this, 'render_settings_field_callback'], $page, 'clisyc_twilio_section', ['id' => 'clisyc_sms_credentials[twilio_token]', 'type' => 'password', 'class' => 'regular-text', 'option_name' => 'clisyc_sms_credentials', 'path' => ['twilio_token']]);
		add_settings_field( 'clisyc_sms_credentials[twilio_from]', __('From Number', 'client-sync'), [$this, 'render_settings_field_callback'], $page, 'clisyc_twilio_section', ['id' => 'clisyc_sms_credentials[twilio_from]', 'type' => 'text', 'class' => 'regular-text', 'option_name' => 'clisyc_sms_credentials', 'path' => ['twilio_from'], 'desc' => __('Your Twilio phone number (e.g., +1234567890).', 'client-sync')]);

		// --- Video Conferencing ---
		register_setting( $group, Constants::OPTION_VIDEO_PROVIDER, [ 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_video_provider' ], 'default' => 'none' ] );
		register_setting( $group, Constants::OPTION_ZOOM_API_CREDENTIALS, [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize_zoom_credentials' ] ] );

		add_settings_section( 'clisyc_videoconf_section', __( 'Video Conferencing', 'client-sync' ), [ $this, 'render_videoconf_section_header' ], $page );
		add_settings_field( Constants::OPTION_VIDEO_PROVIDER, __( 'Video Provider', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_videoconf_section', [
			'id' => Constants::OPTION_VIDEO_PROVIDER,
			'type' => 'select',
			'options' => [
				'none'        => __( 'None (Disabled)', 'client-sync' ),
				'google_meet' => __( 'Google Meet (via Google Calendar)', 'client-sync' ),
				'zoom'        => __( 'Zoom', 'client-sync' ),
			],
			'desc' => __( 'Select your video conferencing provider. Google Meet requires Google Calendar Sync to be configured above.', 'client-sync' ),
		] );
		add_settings_field( 'clisyc_zoom_api_credentials[account_id]', __( 'Zoom Account ID', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_videoconf_section', [ 'id' => 'clisyc_zoom_api_credentials[account_id]', 'type' => 'text', 'class' => 'regular-text', 'option_name' => Constants::OPTION_ZOOM_API_CREDENTIALS, 'path' => ['account_id'], 'desc' => __( 'From your Zoom Server-to-Server OAuth app.', 'client-sync' ) ] );
		add_settings_field( 'clisyc_zoom_api_credentials[client_id]', __( 'Zoom Client ID', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_videoconf_section', [ 'id' => 'clisyc_zoom_api_credentials[client_id]', 'type' => 'text', 'class' => 'regular-text', 'option_name' => Constants::OPTION_ZOOM_API_CREDENTIALS, 'path' => ['client_id'] ] );
		add_settings_field( 'clisyc_zoom_api_credentials[client_secret]', __( 'Zoom Client Secret', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_videoconf_section', [ 'id' => 'clisyc_zoom_api_credentials[client_secret]', 'type' => 'password', 'class' => 'regular-text', 'option_name' => Constants::OPTION_ZOOM_API_CREDENTIALS, 'path' => ['client_secret'], 'desc' => __( 'Keep this secret. Never share publicly.', 'client-sync' ) ] );

		// --- Webhooks ---
		add_settings_section( 'clisyc_webhooks_section', __( 'Webhooks', 'client-sync' ), [ $this, 'render_webhooks_section_header' ], $page );
		add_settings_field( 'clisyc_webhooks', '', [ $this, 'render_webhooks_field' ], $page, 'clisyc_webhooks_section' );
	}

	// -----------------------------------------------------------------
	// Section Callbacks
	// -----------------------------------------------------------------

	public function render_google_section_header() {
		echo '<p>' . esc_html__( 'Enter your Google Cloud Console OAuth 2.0 credentials to enable two-way Google Calendar sync for your staff.', 'client-sync' ) . '</p>';
		echo '<p class="description">' . wp_kses(
			/* translators: %s: The OAuth redirect URI. */
			__( 'Create credentials at <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>. Enable the Google Calendar API and set the authorized redirect URI to:', 'client-sync' ),
			[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
		) . '</p>';
		echo '<code>' . esc_html( admin_url( 'admin.php?page=clisyc-settings&tab=integrations&action=google_oauth_callback' ) ) . '</code>';
	}

	public function render_twilio_section_header() {
		echo '<p>' . esc_html__( 'Enter your Twilio credentials to enable SMS notifications.', 'client-sync' ) . '</p>';

		$creds = get_option( 'clisyc_sms_credentials', [] );
		if ( ! empty( $creds['twilio_sid'] ) && ! empty( $creds['twilio_token'] ) && ! empty( $creds['twilio_from'] ) ) {
			$nonce = wp_create_nonce( 'clisyc_send_test_sms' );
			echo '<div id="clisyc-test-sms-wrapper" style="margin-top: 10px; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px;">';
			echo '<label style="display: block; margin-bottom: 6px;"><strong>' . esc_html__( 'Send Test SMS', 'client-sync' ) . '</strong></label>';
			echo '<input type="tel" id="clisyc-test-sms-phone" placeholder="+15551234567" class="regular-text" style="margin-right: 8px; vertical-align: middle;">';
			echo '<button type="button" id="clisyc-test-sms-btn" class="button button-secondary" style="vertical-align: middle;">' . esc_html__( 'Send Test', 'client-sync' ) . '</button>';
			echo '<span id="clisyc-test-sms-result" style="margin-left: 10px; vertical-align: middle;"></span>';
			echo '<input type="hidden" id="clisyc-test-sms-nonce" value="' . esc_attr( $nonce ) . '">';
			echo '<p class="description" style="margin-top: 6px;">' . esc_html__( 'Enter a phone number to verify your Twilio credentials are working.', 'client-sync' ) . '</p>';
			echo '</div>';
			echo '<script>jQuery(document).ready(function($){';
			echo '$("#clisyc-test-sms-btn").on("click",function(){';
			echo 'var $btn=$(this),$result=$("#clisyc-test-sms-result"),phone=$("#clisyc-test-sms-phone").val();';
			echo 'if(!phone){$result.text("' . esc_js( __( 'Enter a phone number.', 'client-sync' ) ) . '").css("color","#d63638");return;}';
			echo '$btn.prop("disabled",true).text("' . esc_js( __( 'Sending...', 'client-sync' ) ) . '");$result.text("");';
			echo '$.post(ajaxurl,{action:"clisyc_send_test_sms",nonce:$("#clisyc-test-sms-nonce").val(),phone:phone},function(r){';
			echo '$btn.prop("disabled",false).text("' . esc_js( __( 'Send Test', 'client-sync' ) ) . '");';
			echo 'if(r.success){$result.text(r.data.message).css("color","#00a32a");}else{$result.text(r.data.message).css("color","#d63638");}';
			echo '}).fail(function(){$btn.prop("disabled",false).text("' . esc_js( __( 'Send Test', 'client-sync' ) ) . '");$result.text("' . esc_js( __( 'Request failed.', 'client-sync' ) ) . '").css("color","#d63638");});';
			echo '});});';
			echo '</script>';
		}
	}

	public function render_videoconf_section_header() {
		echo '<p>' . esc_html__( 'Auto-attach video meeting links to appointments. Google Meet works through Google Calendar Sync (configure above). Zoom requires a Server-to-Server OAuth app.', 'client-sync' ) . '</p>';
		echo '<p class="description">' . wp_kses(
			__( 'For Zoom, create a Server-to-Server OAuth app at <a href="https://marketplace.zoom.us/develop/create" target="_blank" rel="noopener">Zoom Marketplace</a> and add the <code>meeting:write:meeting</code> scope.', 'client-sync' ),
			[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ], 'code' => [] ]
		) . '</p>';
	}

	public function render_webhooks_section_header() {
		echo '<p>' . esc_html__( 'Configure webhooks to notify external systems when appointments are created, updated, or cancelled.', 'client-sync' ) . '</p>';
	}

	// -----------------------------------------------------------------
	// Field Callbacks
	// -----------------------------------------------------------------

	public function render_webhooks_field() {
		$webhooks = get_option( Constants::OPTION_WEBHOOKS, [] );
		if ( empty( $webhooks ) ) {
			$webhooks = [ [ 'enabled' => false, 'url' => '', 'events' => [] ] ];
		}

		$available_events = [
			'appointment_created'   => __( 'Appointment Created', 'client-sync' ),
			'appointment_updated'   => __( 'Appointment Updated', 'client-sync' ),
			'appointment_cancelled' => __( 'Appointment Cancelled', 'client-sync' ),
		];

		echo '<div id="clisyc-webhooks-container">';
		foreach ( $webhooks as $index => $webhook ) {
			echo '<div class="clisyc-webhook-row" style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">';

			printf(
				'<p><label><input type="checkbox" name="clisyc_webhooks[%d][enabled]" value="1" %s /> %s</label></p>',
				absint( $index ),
				checked( ! empty( $webhook['enabled'] ), true, false ),
				esc_html__( 'Enable this webhook', 'client-sync' )
			);

			printf(
				'<p><label>%s<br><input type="url" name="clisyc_webhooks[%d][url]" value="%s" class="regular-text" placeholder="https://example.com/webhook" /></label></p>',
				esc_html__( 'Webhook URL', 'client-sync' ),
				absint( $index ),
				esc_attr( $webhook['url'] ?? '' )
			);

			echo '<p><strong>' . esc_html__( 'Trigger on events:', 'client-sync' ) . '</strong></p>';
			foreach ( $available_events as $event_key => $event_label ) {
				$checked = in_array( $event_key, $webhook['events'] ?? [], true ) ? 'checked' : '';
				printf(
					'<label style="display: block; margin-left: 10px;"><input type="checkbox" name="clisyc_webhooks[%d][events][]" value="%s" %s /> %s</label>',
					absint( $index ),
					esc_attr( $event_key ),
					esc_attr( $checked ),
					esc_html( $event_label )
				);
			}

			echo '</div>';
		}
		echo '</div>';
	}

	// -----------------------------------------------------------------
	// Sanitization
	// -----------------------------------------------------------------

	public function sanitize_google_credentials( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return get_option( 'clisyc_google_api_credentials', [] );
		}
		if ( ! is_array( $input ) ) {
			return [];
		}
		return [
			'client_id'     => sanitize_text_field( $input['client_id'] ?? '' ),
			'client_secret' => sanitize_text_field( $input['client_secret'] ?? '' ),
		];
	}

	public function sanitize_video_provider( $input ) {
		return in_array( $input, [ 'none', 'google_meet', 'zoom' ], true ) ? $input : 'none';
	}

	public function sanitize_zoom_credentials( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return get_option( Constants::OPTION_ZOOM_API_CREDENTIALS, [] );
		}
		if ( ! is_array( $input ) ) {
			return [];
		}
		return [
			'account_id'    => sanitize_text_field( $input['account_id'] ?? '' ),
			'client_id'     => sanitize_text_field( $input['client_id'] ?? '' ),
			'client_secret' => sanitize_text_field( $input['client_secret'] ?? '' ),
		];
	}

	public function sanitize_sms_credentials( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return get_option( Constants::OPTION_SMS_CREDENTIALS, [] );
		}
		$output = get_option( Constants::OPTION_SMS_CREDENTIALS, [] );
		if ( ! is_array( $input ) ) {
			return $output;
		}
		$output['twilio_sid'] = isset( $input['twilio_sid'] ) ? sanitize_text_field( $input['twilio_sid'] ) : '';
		$output['twilio_token'] = isset( $input['twilio_token'] ) ? sanitize_text_field( $input['twilio_token'] ) : '';
		$output['twilio_from'] = isset( $input['twilio_from'] ) ? sanitize_text_field( $input['twilio_from'] ) : '';
		return $output;
	}

	public function sanitize_webhooks( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return get_option( Constants::OPTION_WEBHOOKS, [] );
		}
		$output = [];
		if ( ! is_array( $input ) ) {
			return [];
		}
		foreach ( $input as $webhook ) {
			if ( empty( $webhook['url'] ) || ! filter_var( $webhook['url'], FILTER_VALIDATE_URL ) ) {
				continue;
			}
			$output[] = [
				'enabled' => ! empty( $webhook['enabled'] ),
				'url'     => esc_url_raw( $webhook['url'] ),
				'events'  => isset( $webhook['events'] ) && is_array( $webhook['events'] ) ? array_map( 'sanitize_key', $webhook['events'] ) : [],
			];
		}
		return $output;
	}
}
