<?php
/**
 * File: src/free/uninstall.php -> client-sync/uninstall.php
 * Client Sync Uninstall
 *
 * This file runs when the user deletes the Client Sync plugin from the WordPress dashboard.
 * It is designed to remove all plugin data from the database, including:
 * - Custom database tables for time slots.
 * - All 'clisyc_appointment' custom post types and their associated meta data.
 * - All plugin options stored in the wp_options table.
 * - All user meta data associated with the plugin (if enabled).
 * - All scheduled cron events.
 *
 * @package    ClientSync
 * @author     Joshua Jordan
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * =================================================================================
 * Section 0: Drop Custom Database Tables
 * =================================================================================
 * *** REFACTORED ***: All table names updated with the 'clisyc_' prefix.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}clisyc_time_slots" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}clisyc_slot_dimensions" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}clisyc_relationships" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}clisyc_bookings" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}clisyc_graph_nodes" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}clisyc_audit_logs" );


/**
 * =================================================================================
 * Section 1: Delete All 'clisyc_appointment' Custom Posts
 * =================================================================================
 * REFACTORED: Updated post_type from 'appointment' to 'clisyc_appointment'.
 */
$clisyc_appointment_posts = get_posts(
	[
		'post_type'      => 'clisyc_appointment', // FIX: Use the prefixed CPT name
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
	]
);

if ( ! empty( $clisyc_appointment_posts ) ) {
	foreach ( $clisyc_appointment_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
}

/**
 * =================================================================================
 * Section 2: Delete User Meta Data (Conditional)
 * =================================================================================
 * *** REFACTORED ***: Updated option name and meta key prefix.
 */
if ( get_option( 'clisyc_delete_user_data_on_uninstall' ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'clisyc_' ) . '%'
		)
	);
}

/**
 * =================================================================================
 * Section 3: Delete All Plugin Options
 * =================================================================================
 * *** REFACTORED ***: Updated all option names with the 'clisyc_' prefix.
 */
$clisyc_options_to_delete = [
	'clisyc_calendar_start_time',
	'clisyc_calendar_end_time',
	'clisyc_calendar_slot_duration',
	'clisyc_standard_schedule_calendar_start_time',
	'clisyc_standard_schedule_calendar_end_time',
	'clisyc_frontend_calendar_style',
	'clisyc_login_page_url',
	'clisyc_last_frequent_maintenance_run_timestamp',
	'clisyc_calendar_show_overview_availability',
	'clisyc_available_time_slots', // Old option, for cleanup
	'clisyc_standard_schedules_by_dimension',
	'clisyc_custom_fields',
	'clisyc_custom_fields_order',
	'clisyc_appointment_custom_fields',
	'clisyc_appointment_custom_fields_order',
	'clisyc_availability_dimensions_fields',
	'clisyc_client_list_custom_field_column',
	'clisyc_appt_list_cf_columns',
	'clisyc_primary_service_dimension_key',
	'clisyc_service_attributes',
	'clisyc_notification_settings',
	'clisyc_email_from_name',
	'clisyc_email_from_address',
	'clisyc_notification_admin_recipients',
	'clisyc_reminder_settings',
	'clisyc_appointment_view_page_slug',
	'clisyc_auto_generate_enabled',
	'clisyc_auto_generate_lookahead',
	'clisyc_auto_generate_days_of_week',
	'clisyc_wc_integration_enabled',
	'clisyc_wc_appointment_product_id',
	'clisyc_min_booking_notice_minutes',
	'clisyc_global_buffer_before',
	'clisyc_global_buffer_after',
	'clisyc_slots_migrated_to_db_v2',
	'clisyc_migrated_pending_pay_status',
	'clisyc_db_schema_version',
	'clisyc_setup_milestones_completed',
	'clisyc_wizard_last_created_dim_key',
	'clisyc_wizard_last_created_dim_value',
	'clisyc_calendar_smart_start_date',
	'clisyc_calendar_week_starts_on',
	'clisyc_debug_standard_schedule_output',
	'clisyc_appointment_view_page_id',
	'clisyc_booking_page_id',
	'clisyc_booking_success_page_id',
	'clisyc_enable_self_service',
	'clisyc_cancellation_cutoff_value',
	'clisyc_cancellation_cutoff_unit',
	'clisyc_cancellation_refund_policy',
	'clisyc_auto_generate_schedule_map',
	'clisyc_mysql_convert_tz_override',
	'clisyc_mysql_convert_tz_status',
	'clisyc_calendar_color_settings',
	'clisyc_delete_user_data_on_uninstall',
	'clisyc_slot_gen_state', // Remove batch processing state
	'clisyc_global_blocked_periods',
	'clisyc_search_results_page_id',
	'clisyc_pro_license_data', // Also remove pro license on uninstall
	'clisyc_dimension_registry',
	'clisyc_custom_dimension_types',
	'clisyc_dimension_fields',
	'clisyc_hipaa_mode_enabled',
	'clisyc_audit_logging_enabled',
	'clisyc_audit_log_retention_days',
	'clisyc_stripe_enabled',
	'clisyc_stripe_key_public',
	'clisyc_stripe_key_secret',
	'clisyc_stripe_webhook_secret',
	'clisyc_video_conferencing_provider',
	'clisyc_zoom_api_credentials',
	'clisyc_sms_credentials',
	'clisyc_webhooks',
	'clisyc_waitlist_enabled',
	'clisyc_waitlist_max_per_slot',
	'clisyc_waitlist_auto_promote',
	'clisyc_antispam_honeypot_enabled',
	'clisyc_antispam_time_check_enabled',
	'clisyc_default_booking_mode',
	'clisyc_default_buffer_days',
	'clisyc_graph_visual_state',
	'clisyc_universal_shortcode_default_mode',
	'clisyc_anonymize_external_sync',
	'clisyc_personnel_dimension_slug',
	'clisyc_my_appointments_page_id',
	'clisyc_manager_edit_page_id',
	'clisyc_contact_page_id',
	'clisyc_last_successful_maintenance_run_timestamp',
	'clisyc_appointment_custom_fields_order',
	'clisyc_calendar_enabled_views',
	'clisyc_calendar_slot_height',
	'clisyc_calendar_smart_start_date_next_available',
	'clisyc_standard_schedule_calendar_start_time',
	'clisyc_standard_schedule_calendar_end_time',
];

foreach ( $clisyc_options_to_delete as $clisyc_option_name ) {
	delete_option( $clisyc_option_name );
}

// Delete transients (optional, but good for a deep clean)
// *** REFACTORED ***: Updated all transient names with the 'clisyc_' prefix.
$clisyc_transients_to_delete = [
	'clisyc_booking_feedback_error',
	'clisyc_booking_feedback_success',
	'clisyc_booking_feedback_notice',
	'clisyc_custom_field_feedback',
	'clisyc_manage_slots_feedback',
	'clisyc_standard_schedule_feedback',
	'clisyc_testing_feedback',
	'clisyc_service_attributes_feedback',
	'clisyc_automation_feedback',
	'clisyc_activation_redirect',
	'clisyc_convert_tz_notice_shown',
	'clisyc_failed_notifications_count',
];

foreach ( $clisyc_transients_to_delete as $clisyc_transient_name ) {
	delete_transient( $clisyc_transient_name );
}

/**
 * =================================================================================
 * Section 4: Unschedule All Cron Events
 * =================================================================================
 * *** REFACTORED ***: Updated all cron hook names with the 'clisyc_' prefix.
 */
wp_clear_scheduled_hook( 'clisyc_frequent_maintenance_tasks' );
wp_clear_scheduled_hook( 'clisyc_cron_charge_due_payments' );
wp_clear_scheduled_hook( 'clisyc_cron_send_reminders' );

/**
 * =================================================================================
 * Section 5: Delete Dimension Post Types
 * =================================================================================
 * Remove any posts created for registered dimension types.
 */
$clisyc_registry = get_option( 'clisyc_dimension_registry', [] );
$clisyc_dim_types = isset( $clisyc_registry['dimensions'] ) ? array_keys( $clisyc_registry['dimensions'] ) : [];

// Always include the built-in service post type.
$clisyc_dim_types[] = 'clisyc_service';
$clisyc_dim_types = array_unique( $clisyc_dim_types );

foreach ( $clisyc_dim_types as $clisyc_dim_pt ) {
	$clisyc_dim_posts = get_posts(
		[
			'post_type'      => $clisyc_dim_pt,
			'post_status'    => 'any',
			'numberposts'    => -1,
			'fields'         => 'ids',
		]
	);
	if ( ! empty( $clisyc_dim_posts ) ) {
		foreach ( $clisyc_dim_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}
}

/**
 * =================================================================================
 * Section 6: Clean Up Rate Limit Transients
 * =================================================================================
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_clisyc_' ) . '%'
	)
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_clisyc_' ) . '%'
	)
);