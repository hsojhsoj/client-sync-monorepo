<?php
/**
 * File: src/shared/includes/admin/class-hipaa-compliance-manager.php
 * Handles HIPAA compliance settings, audit log menu, and audit log export.
 *
 * Extracted from class-admin.php to follow the Single Responsibility Principle.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HIPAA_Compliance_Manager {

	/**
	 * Register hooks for HIPAA compliance features.
	 */
	public function register_hooks() {
		add_action( 'admin_init', [ $this, 'register_hipaa_settings' ] );
		add_action( 'admin_menu', [ $this, 'register_audit_logs_menu' ], 20 );
		add_action( 'admin_post_clisyc_export_audit_logs', [ $this, 'handle_audit_log_export' ] );
	}

	/**
	 * Registers HIPAA compliance settings for the WordPress Settings API.
	 *
	 * These settings are used in the HIPAA Compliance tab of the Settings page.
	 *
	 * @return void
	 */
	public function register_hipaa_settings() {
		// HIPAA Mode toggle (database-based, less secure than wp-config.php constant).
		register_setting(
			'clisyc_hipaa_settings_group',
			Constants::OPTION_HIPAA_MODE,
			[
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			]
		);

		// Audit log retention period (HIPAA requires minimum 6 years = 2190 days).
		register_setting(
			'clisyc_hipaa_settings_group',
			Constants::OPTION_AUDIT_LOG_RETENTION,
			[
				'type'              => 'integer',
				'default'           => 2555, // 7 years default
				'sanitize_callback' => [ $this, 'sanitize_audit_retention_days' ],
			]
		);

		// Anonymize data in external calendar sync.
		register_setting(
			'clisyc_hipaa_settings_group',
			'clisyc_anonymize_external_sync',
			[
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			]
		);
	}

	/**
	 * Sanitizes the audit retention days value.
	 *
	 * @param mixed $value The raw value.
	 * @return int Sanitized value clamped between HIPAA min and max.
	 */
	public function sanitize_audit_retention_days( $value ): int {
		$value = absint( $value );
		return max( Constants::HIPAA_AUDIT_MIN_RETENTION_DAYS, min( Constants::HIPAA_AUDIT_MAX_RETENTION_DAYS, $value ) );
	}

	/**
	 * Register the Audit Logs submenu page.
	 *
	 * This page is only visible to administrators and provides
	 * HIPAA-compliant audit log viewing capabilities.
	 *
	 * @return void
	 */
	public function register_audit_logs_menu() {
		add_submenu_page(
			'clisyc-dashboard',
			__( 'Audit Logs', 'client-sync' ),
			__( 'Audit Logs', 'client-sync' ),
			'manage_options',
			'clisyc-audit-logs',
			[ $this, 'render_audit_logs_page' ]
		);
	}

	/**
	 * Render the Audit Logs admin page.
	 *
	 * @return void
	 */
	public function render_audit_logs_page() {
		$view_file = \CLISYC_SHARED_DIR . 'includes/admin/views/view-audit-logs-page.php';

		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Audit Logs', 'client-sync' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Audit logs view file not found.', 'client-sync' ) . '</p></div>';
			echo '</div>';
		}
	}

	/**
	 * Handle audit log export requests.
	 *
	 * Exports filtered audit logs to CSV or JSON format.
	 *
	 * @return void
	 */
	public function handle_audit_log_export() {
		// Check nonce.
		if ( ! isset( $_POST['clisyc_audit_export_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_key( $_POST['clisyc_audit_export_nonce'] ), 'clisyc_export_audit_logs' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}

		// Check permission.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		// Check if Audit Logger exists.
		if ( ! class_exists( '\DependentMedia\ClientSync\Services\Audit_Logger' ) ) {
			wp_die( esc_html__( 'Audit Logger service not available.', 'client-sync' ) );
		}

		$audit_logger = \DependentMedia\ClientSync\Services\Audit_Logger::get_instance();

		// Build query from filters.
		$query_args = [
			'per_page'    => 999999,
			'action'      => isset( $_POST['action_filter'] ) ? sanitize_key( $_POST['action_filter'] ) : '',
			'object_type' => isset( $_POST['object_type'] ) ? sanitize_key( $_POST['object_type'] ) : '',
			'user_id'     => isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0,
			'date_from'   => isset( $_POST['date_from'] ) ? sanitize_text_field( $_POST['date_from'] ) : '',
			'date_to'     => isset( $_POST['date_to'] ) ? sanitize_text_field( $_POST['date_to'] ) : '',
			'search'      => isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '',
		];

		$result = $audit_logger->get_logs( $query_args );
		$logs   = $result['logs'];

		// Log this export action.
		\DependentMedia\ClientSync\Services\Audit_Logger::log(
			'export',
			'audit_log',
			0,
			[ 'export_count' => count( $logs ), 'filters' => $query_args ]
		);

		$format   = isset( $_POST['export_format'] ) ? sanitize_key( $_POST['export_format'] ) : 'csv';
		$filename = 'audit-logs-' . gmdate( 'Y-m-d-His' );

		if ( $format === 'json' ) {
			header( 'Content-Type: application/json' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '.json"' );
			echo wp_json_encode( $logs, JSON_PRETTY_PRINT );
			exit;
		}

		// CSV export.
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );

		$output = fopen( 'php://output', 'w' );

		// Header row.
		fputcsv( $output, [
			'Log ID',
			'Timestamp (UTC)',
			'User ID',
			'Username',
			'Action',
			'Object Type',
			'Object ID',
			'IP Address',
			'Request URI',
			'Metadata',
		] );

		// Data rows.
		foreach ( $logs as $log ) {
			fputcsv( $output, [
				$log['log_id'],
				$log['created_at'],
				$log['user_id'],
				$log['username'],
				$log['action'],
				$log['object_type'],
				$log['object_id'],
				$log['ip_address'],
				$log['request_uri'],
				$log['meta_data'],
			] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );
		exit;
	}
}
