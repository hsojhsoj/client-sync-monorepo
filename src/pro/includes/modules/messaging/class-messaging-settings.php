<?php
/**
 * File: src/pro/includes/modules/messaging/class-messaging-settings.php
 * Registers messaging settings on the Client Sync settings page.
 *
 * Adds a "Messaging" section under the Behavior settings tab with:
 * - Enable/disable toggle
 * - Role permissions (initiate / reply)
 * - Attachment limits and types
 * - Real-time delivery settings
 * - Message retention policy
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Messaging_Settings {

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register all messaging settings.
	 */
	public function register_settings(): void {
		$page = 'clisyc_behavior';

		// Register all option names.
		register_setting( $page, Constants::OPTION_MESSAGING_ENABLED, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );
		register_setting( $page, Constants::OPTION_MESSAGING_ROLES_INITIATE, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_roles' ],
			'default'           => [ 'administrator', 'editor' ],
		] );
		register_setting( $page, Constants::OPTION_MESSAGING_ROLES_REPLY, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_roles' ],
			'default'           => [ 'administrator', 'editor', 'subscriber' ],
		] );
		register_setting( $page, Constants::OPTION_MESSAGING_MAX_FILE_SIZE, [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 10 * 1024 * 1024,
		] );
		register_setting( $page, Constants::OPTION_MESSAGING_ALLOWED_TYPES, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'image/jpeg,image/png,application/pdf',
		] );
		register_setting( $page, Constants::OPTION_MESSAGING_REALTIME, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );
		register_setting( $page, Constants::OPTION_MESSAGING_POLL_INTERVAL, [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 30,
		] );
		register_setting( $page, Constants::OPTION_MESSAGING_RETENTION_DAYS, [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		] );

		// ── Section: Messaging ──────────────────────────────────────
		add_settings_section(
			'clisyc_messaging_section',
			__( 'Messaging', 'client-sync-pro' ),
			[ $this, 'render_section_header' ],
			$page
		);

		// Enable Messaging.
		add_settings_field(
			Constants::OPTION_MESSAGING_ENABLED,
			__( 'Enable Messaging', 'client-sync-pro' ),
			[ $this, 'render_checkbox' ],
			$page,
			'clisyc_messaging_section',
			[
				'id'   => Constants::OPTION_MESSAGING_ENABLED,
				'desc' => __( 'Enable encrypted bidirectional messaging between practitioners and clients.', 'client-sync-pro' ),
			]
		);

		// Roles: Can Initiate.
		add_settings_field(
			Constants::OPTION_MESSAGING_ROLES_INITIATE,
			__( 'Roles: Can Start Conversations', 'client-sync-pro' ),
			[ $this, 'render_role_checkboxes' ],
			$page,
			'clisyc_messaging_section',
			[
				'id'   => Constants::OPTION_MESSAGING_ROLES_INITIATE,
				'desc' => __( 'Which roles can start new message threads.', 'client-sync-pro' ),
			]
		);

		// Roles: Can Reply.
		add_settings_field(
			Constants::OPTION_MESSAGING_ROLES_REPLY,
			__( 'Roles: Can Reply', 'client-sync-pro' ),
			[ $this, 'render_role_checkboxes' ],
			$page,
			'clisyc_messaging_section',
			[
				'id'   => Constants::OPTION_MESSAGING_ROLES_REPLY,
				'desc' => __( 'Which roles can reply to existing threads.', 'client-sync-pro' ),
			]
		);

		// Max Attachment Size.
		add_settings_field(
			Constants::OPTION_MESSAGING_MAX_FILE_SIZE,
			__( 'Max Attachment Size', 'client-sync-pro' ),
			[ $this, 'render_file_size' ],
			$page,
			'clisyc_messaging_section',
			[
				'id'      => Constants::OPTION_MESSAGING_MAX_FILE_SIZE,
				'desc'    => __( 'Maximum file size per attachment in megabytes.', 'client-sync-pro' ),
				'default' => 10,
			]
		);

		// Allowed File Types.
		add_settings_field(
			Constants::OPTION_MESSAGING_ALLOWED_TYPES,
			__( 'Allowed File Types', 'client-sync-pro' ),
			[ $this, 'render_text' ],
			$page,
			'clisyc_messaging_section',
			[
				'id'      => Constants::OPTION_MESSAGING_ALLOWED_TYPES,
				'desc'    => __( 'Comma-separated MIME types (e.g., image/jpeg,image/png,application/pdf). Leave empty to allow all.', 'client-sync-pro' ),
				'default' => 'image/jpeg,image/png,application/pdf',
				'class'   => 'regular-text',
			]
		);

		// Enable Real-Time.
		add_settings_field(
			Constants::OPTION_MESSAGING_REALTIME,
			__( 'Enable Real-Time Delivery', 'client-sync-pro' ),
			[ $this, 'render_checkbox' ],
			$page,
			'clisyc_messaging_section',
			[
				'id'   => Constants::OPTION_MESSAGING_REALTIME,
				'desc' => __( 'Use Server-Sent Events (SSE) for instant message delivery. Requires server support for long-running connections. Falls back to polling if unavailable.', 'client-sync-pro' ),
			]
		);

		// Poll Interval.
		add_settings_field(
			Constants::OPTION_MESSAGING_POLL_INTERVAL,
			__( 'Poll Interval', 'client-sync-pro' ),
			[ $this, 'render_number' ],
			$page,
			'clisyc_messaging_section',
			[
				'id'      => Constants::OPTION_MESSAGING_POLL_INTERVAL,
				'desc'    => __( 'seconds. How often to check for new messages when real-time is disabled or unavailable.', 'client-sync-pro' ),
				'default' => 30,
				'min'     => 5,
				'max'     => 300,
				'class'   => 'small-text',
			]
		);

		// Message Retention.
		add_settings_field(
			Constants::OPTION_MESSAGING_RETENTION_DAYS,
			__( 'Message Retention', 'client-sync-pro' ),
			[ $this, 'render_number' ],
			$page,
			'clisyc_messaging_section',
			[
				'id'      => Constants::OPTION_MESSAGING_RETENTION_DAYS,
				'desc'    => __( 'days. Automatically delete messages older than this. Set to 0 to keep forever. HIPAA mode enforces a minimum of 2,190 days (6 years).', 'client-sync-pro' ),
				'default' => 0,
				'min'     => 0,
				'class'   => 'small-text',
			]
		);
	}

	// ── Section Header ────────────────────────────────────────────

	/**
	 * Render the messaging section header.
	 */
	public function render_section_header(): void {
		echo '<p>' . esc_html__( 'Configure encrypted messaging between practitioners and clients. All messages are encrypted at rest using AES-256-GCM.', 'client-sync-pro' ) . '</p>';
	}

	// ── Field Renderers ───────────────────────────────────────────

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_checkbox( array $args ): void {
		$value = get_option( $args['id'], false );
		printf(
			'<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s />',
			esc_attr( $args['id'] ),
			checked( 1, $value, false )
		);
		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	/**
	 * Render a text input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_text( array $args ): void {
		$value = get_option( $args['id'], $args['default'] ?? '' );
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="%3$s" />',
			esc_attr( $args['id'] ),
			esc_attr( $value ),
			esc_attr( $args['class'] ?? 'regular-text' )
		);
		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	/**
	 * Render a number input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_number( array $args ): void {
		$value = get_option( $args['id'], $args['default'] ?? 0 );
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$s" class="%3$s" min="%4$s" %5$s />',
			esc_attr( $args['id'] ),
			esc_attr( $value ),
			esc_attr( $args['class'] ?? 'small-text' ),
			esc_attr( $args['min'] ?? 0 ),
			isset( $args['max'] ) ? 'max="' . esc_attr( $args['max'] ) . '"' : ''
		);
		if ( ! empty( $args['desc'] ) ) {
			echo ' <span class="description">' . esc_html( $args['desc'] ) . '</span>';
		}
	}

	/**
	 * Render a file size field (stored in bytes, displayed in MB).
	 *
	 * @param array $args Field arguments.
	 */
	public function render_file_size( array $args ): void {
		$bytes = get_option( $args['id'], ( $args['default'] ?? 10 ) * 1024 * 1024 );
		$mb    = round( $bytes / ( 1024 * 1024 ), 1 );

		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text" min="1" max="100" step="1" /> MB',
			esc_attr( $args['id'] ),
			esc_attr( $mb )
		);
		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	/**
	 * Render role checkboxes field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_role_checkboxes( array $args ): void {
		$selected_roles = get_option( $args['id'], [] );
		if ( ! is_array( $selected_roles ) ) {
			$selected_roles = [];
		}

		$editable_roles = get_editable_roles();

		echo '<fieldset>';
		foreach ( $editable_roles as $role_slug => $role_data ) {
			$checked = in_array( $role_slug, $selected_roles, true );
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $args['id'] ),
				esc_attr( $role_slug ),
				checked( $checked, true, false ),
				esc_html( translate_user_role( $role_data['name'] ) )
			);
		}
		echo '</fieldset>';

		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	// ── Sanitizers ────────────────────────────────────────────────

	/**
	 * Sanitize an array of role slugs.
	 *
	 * @param mixed $input Input value.
	 * @return array Sanitized array of role slugs.
	 */
	public function sanitize_roles( $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		return array_map( 'sanitize_key', $input );
	}
}
