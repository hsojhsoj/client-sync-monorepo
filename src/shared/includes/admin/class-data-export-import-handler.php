<?php
/**
 * File: src/shared/includes/admin/class-data-export-import-handler.php
 * Handles all data export and import operations for the admin area.
 *
 * Extracted from class-form-handler.php following the Single Responsibility Principle.
 * This class encapsulates settings export/import, database export, and appointment
 * export (CSV/ICS) functionality that was previously part of the Form_Handler class.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Services\Importer;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Security_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Data_Export_Import_Handler {

	/**
	 * A reference to the database manager instance.
	 *
	 * @var \DependentMedia\ClientSync\Core\Database_Manager
	 */
	private $db_manager;

	/**
	 * Constructor.
	 *
	 * @param \DependentMedia\ClientSync\Core\Database_Manager $db_manager An instance of the database manager class.
	 */
	public function __construct( Database_Manager $db_manager ) {
		$this->db_manager = $db_manager;
	}

	/**
	 * Check if HIPAA mode is active.
	 *
	 * Uses the global helper function if available, otherwise checks
	 * both the constant and database option.
	 *
	 * @return bool True if HIPAA mode is active, false otherwise.
	 */
	private function is_hipaa_mode_active(): bool {
		// Use global helper if available
		if ( function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) ) {
			return \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode();
		}

		// Fallback: Check constant first (set in wp-config.php for security)
		if ( defined( 'CLISYC_HIPAA_MODE' ) && CLISYC_HIPAA_MODE ) {
			return true;
		}

		// Fallback: Check database option
		return (bool) get_option( Constants::OPTION_HIPAA_MODE, false );
	}

	/**
	 * Registers all export/import handling hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_post_clisyc_export_settings', [ $this, 'handle_export_settings' ] );
		add_action( 'admin_post_clisyc_import_settings', [ $this, 'handle_import_settings' ] );
		add_action( 'admin_post_clisyc_export_database_data', [ $this, 'handle_export_database_data' ] );
		add_action( 'admin_post_clisyc_export_appointments_csv', [ $this, 'handle_export_appointments_csv' ] );
		add_action( 'admin_post_clisyc_export_appointments_ics', [ $this, 'handle_export_appointments_ics' ] );
	}

	/**
	 * Handles exporting plugin settings to a JSON file.
	 */
	public function handle_export_settings() {
		$nonce       = isset( $_POST['clisyc_export_settings_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_export_settings_nonce'] ) ) : '';
		$export_note = isset( $_POST['clisyc_export_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clisyc_export_note'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_export_settings_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		// =========================================================================
		// HIPAA COMPLIANCE: Block settings export in HIPAA mode
		// Settings export may contain email addresses and other sensitive config.
		// =========================================================================
		if ( $this->is_hipaa_mode_active() ) {
			wp_die(
				esc_html__( 'Settings export is disabled when HIPAA mode is active to prevent accidental PHI disclosure. Settings may contain email addresses and sensitive configuration data. Please disable HIPAA mode temporarily if you need to export settings for migration purposes.', 'client-sync' ),
				esc_html__( 'Export Blocked - HIPAA Mode Active', 'client-sync' ),
				[ 'response' => 403, 'back_link' => true ]
			);
		}

		global $wpdb;
		$export_data = [
			'template_meta' => [],
			'options'       => [],
			'cpts'          => [],
			'relationships' => [],
		];

		if ( ! empty( $export_note ) ) {
			$export_data['template_meta']['title']       = __( 'Custom Export', 'client-sync' ) . ' - ' . wp_date( 'Y-m-d' );
			$export_data['template_meta']['description'] = $export_note;
		}

		$options_to_export = [
			Constants::OPTION_CALENDAR_START_TIME,
			Constants::OPTION_CALENDAR_END_TIME,
			'clisyc_calendar_slot_duration',
			'clisyc_standard_schedule_calendar_start_time',
			'clisyc_standard_schedule_calendar_end_time',
			'clisyc_frontend_calendar_style',
			'clisyc_login_page_url',
			Constants::OPTION_CUSTOM_FIELDS,
			Constants::OPTION_CUSTOM_FIELDS_ORDER,
			Constants::OPTION_APPOINTMENT_FIELDS,
			Constants::OPTION_NOTIFICATION_SETTINGS,
			Constants::OPTION_EMAIL_FROM_NAME,
			Constants::OPTION_EMAIL_FROM_ADDRESS,
			Constants::OPTION_NOTIFICATION_ADMINS,
			'clisyc_reminder_settings',
			'clisyc_auto_generate_enabled',
			'clisyc_auto_generate_lookahead',
			'clisyc_wc_integration_enabled',
			'clisyc_wc_appointment_product_id',
			'clisyc_min_booking_notice_minutes',
			'clisyc_global_buffer_before',
			'clisyc_global_buffer_after',
			'clisyc_calendar_show_overview_availability',
			'clisyc_calendar_smart_start_date',
			'clisyc_mysql_convert_tz_override',
			Constants::OPTION_CALENDAR_COLOR_SETTINGS,
			Constants::OPTION_ENABLE_SELF_SERVICE,
			Constants::OPTION_CANCEL_CUTOFF_VALUE,
			Constants::OPTION_CANCEL_CUTOFF_UNIT,
			Constants::OPTION_CANCEL_REFUND_POLICY,
			'clisyc_appointment_view_page_id',
			'clisyc_booking_page_id',
			'clisyc_booking_success_page_id',
			Constants::OPTION_ANTISPAM_HONEYPOT,
			Constants::OPTION_ANTISPAM_TIME_CHECK,
			'clisyc_recaptcha_v3_settings',
			Constants::OPTION_DIMENSION_REGISTRY,
			Constants::OPTION_CUSTOM_DIMENSION_TYPES,
			Constants::OPTION_GRAPH_VISUAL_STATE,
		];

		foreach ( $options_to_export as $option_name ) {
			$export_data['options'][ $option_name ] = get_option( $option_name );
		}

		$registry       = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$cpts_to_export = array_keys( $registry['dimensions'] ?? [] );

		foreach ( $cpts_to_export as $cpt_slug ) {
			if ( empty( $registry['dimensions'][ $cpt_slug ]['enabled'] ) ) {
				continue;
			}
			$export_data['cpts'][ $cpt_slug ] = [];
			$posts                          = get_posts(
				[
					'post_type'      => $cpt_slug,
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				]
			);
			foreach ( $posts as $post ) {
				$export_data['cpts'][ $cpt_slug ][] = [
					'original_id'  => $post->ID,
					'post_name'    => $post->post_name,
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
					'post_meta'    => get_post_meta( $post->ID ),
				];
			}
		}

		$rels_table = $this->db_manager->get_relationships_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely retrieved.
		$query = "SELECT * FROM {$rels_table} WHERE parent_object_id < child_object_id";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from internal method is safe.
		$relationships_raw = $wpdb->get_results( $query );

		$post_ids_to_lookup = [];
		foreach ( $relationships_raw as $rel ) {
			$post_ids_to_lookup[] = $rel->parent_object_id;
			$post_ids_to_lookup[] = $rel->child_object_id;
		}

		if ( ! empty( $post_ids_to_lookup ) ) {
			$unique_ids = array_unique( array_map( 'absint', $post_ids_to_lookup ) );
			if ( ! empty( $unique_ids ) ) {
				$placeholders     = implode( ',', array_fill( 0, count( $unique_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are dynamically generated and safe.
				$query            = "SELECT ID, post_name FROM {$wpdb->posts} WHERE ID IN ($placeholders)";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared below; table name is safe.
				$post_slugs_by_id = $wpdb->get_results( $wpdb->prepare( $query, ...$unique_ids ), OBJECT_K );
				foreach ( $relationships_raw as $rel ) {
					$parent_slug = $post_slugs_by_id[ $rel->parent_object_id ]->post_name ?? null;
					$child_slug  = $post_slugs_by_id[ $rel->child_object_id ]->post_name ?? null;
					if ( $parent_slug && $child_slug ) {
						$export_data['relationships'][] = [
							'parent_slug' => $parent_slug,
							'parent_type' => $rel->parent_object_type,
							'child_slug'  => $child_slug,
							'child_type'  => $rel->child_object_type,
						];
					}
				}
			}
		}

		// Export Output Templates (Pro feature — includes all published templates).
		if ( post_type_exists( 'clisyc_output_tmpl' ) ) {
			$output_templates = get_posts( [
				'post_type'      => 'clisyc_output_tmpl',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			] );

			$export_data['output_templates'] = [];
			foreach ( $output_templates as $ot ) {
				$export_data['output_templates'][] = [
					'post_title'          => $ot->post_title,
					'clisyc_template_json' => get_post_meta( $ot->ID, 'clisyc_template_json', true ),
					'clisyc_triggers'     => get_post_meta( $ot->ID, 'clisyc_triggers', true ) ?: [],
					'clisyc_channels'     => get_post_meta( $ot->ID, 'clisyc_channels', true ) ?: [],
				];
			}
		}

		$filename = 'clisyc-settings-export-' . wp_date( 'Y-m-d' ) . '.json';
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( [ $export_data ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Handles importing plugin settings from a JSON file or built-in template.
	 */
	public function handle_import_settings() {
		$nonce                = isset( $_POST['clisyc_import_settings_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_import_settings_nonce'] ) ) : '';
		$import_template_slug = isset( $_POST['import_template_slug'] ) ? sanitize_key( wp_unslash( $_POST['import_template_slug'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_import_settings_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		$import_data = null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_FILES['clisyc_import_file']['error'] ) && UPLOAD_ERR_OK === $_FILES['clisyc_import_file']['error'] && ! empty( $_FILES['clisyc_import_file']['tmp_name'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$file_path = $_FILES['clisyc_import_file']['tmp_name'];
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$file_name = isset( $_FILES['clisyc_import_file']['name'] ) ? sanitize_file_name( $_FILES['clisyc_import_file']['name'] ) : '';
			$file_type = wp_check_filetype( $file_name, [ 'json' => 'application/json' ] );
			if ( 'application/json' === $file_type['type'] ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$json_data   = file_get_contents( $file_path );
				$import_data = json_decode( $json_data, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					$import_data = null;
				}
			}
		} elseif ( ! empty( $import_template_slug ) ) {
			$template_file_path = realpath( clisyc_PLUGIN_DIR . 'setup-templates/' . $import_template_slug . '.json' );
			if ( $template_file_path && 0 === strpos( $template_file_path, realpath( clisyc_PLUGIN_DIR . 'setup-templates/' ) . DIRECTORY_SEPARATOR ) && file_exists( $template_file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$json_data    = file_get_contents( $template_file_path );
				$decoded_data = json_decode( $json_data, true );
				$import_data  = ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_data ) ) ? ( $decoded_data[0] ?? null ) : null;
			}
		}

		if ( is_array( $import_data ) ) {
			$importer = new Importer();
			$result   = $importer->install_from_data( $import_data );

			if ( is_wp_error( $result ) ) {
				set_transient( 'clisyc_import_export_feedback', [ 'error' => __( 'Import failed: ', 'client-sync' ) . $result->get_error_message() ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
			} else {
				$feedback_message = __( 'Configuration imported successfully.', 'client-sync' );
				if ( ! empty( $import_data['template_meta']['title'] ) ) {
					/* translators: %s: The title of the imported template. */
					$feedback_message .= '<br/>' . sprintf( '<strong>%s:</strong> %s', __( 'Template', 'client-sync' ), esc_html( $import_data['template_meta']['title'] ) );
				}
				set_transient( 'clisyc_import_export_feedback', [ 'message' => $feedback_message ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
			}
		} else {
			set_transient( 'clisyc_import_export_feedback', [ 'error' => __( 'Import failed. The provided file was invalid or could not be read.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		}

		$redirect_url = wp_get_referer();
		if ( ! $redirect_url ) {
			$redirect_url = admin_url( 'admin.php?page=clisyc-settings&tab=import_export' );
		}
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handles exporting all plugin database data (slots, dimensions, relationships, etc.) to a JSON file.
	 */
	public function handle_export_database_data() {
		$nonce = isset( $_POST['clisyc_export_db_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_export_db_nonce'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_export_db_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		// =========================================================================
		// HIPAA COMPLIANCE: Block database export in HIPAA mode
		// Database export includes encrypted PHI that should not leave the system.
		// =========================================================================
		if ( $this->is_hipaa_mode_active() ) {
			wp_die(
				esc_html__( 'Database export is disabled when HIPAA mode is active to prevent accidental PHI disclosure. This export would include encrypted appointment data and potentially identifiable information. Please use the HIPAA-compliant migration tools or disable HIPAA mode temporarily for data migration purposes.', 'client-sync' ),
				esc_html__( 'Export Blocked - HIPAA Mode Active', 'client-sync' ),
				[ 'response' => 403, 'back_link' => true ]
			);
		}

		global $wpdb;
		$db_manager     = new \DependentMedia\ClientSync\Core\Database_Manager();
		$slots_table    = $db_manager->get_slots_table_name();
		$dims_table     = $db_manager->get_dimensions_table_name();
		$rels_table     = $db_manager->get_relationships_table_name();
		$nodes_table    = $wpdb->prefix . 'clisyc_graph_nodes';
		$bookings_table = $wpdb->prefix . 'clisyc_bookings';

		// --- START: MODIFIED LOGIC ---

		// 1. Export all relevant plugin options
		$options_to_export = [
			Constants::OPTION_DIMENSION_REGISTRY,
			Constants::OPTION_CUSTOM_DIMENSION_TYPES,
			Constants::OPTION_DIMENSION_FIELDS,
			Constants::OPTION_CUSTOM_FIELDS,
			Constants::OPTION_CUSTOM_FIELDS_ORDER,
			Constants::OPTION_APPOINTMENT_FIELDS,
			Constants::OPTION_GRAPH_VISUAL_STATE,
			Constants::OPTION_CALENDAR_START_TIME,
			Constants::OPTION_CALENDAR_END_TIME,
			'clisyc_calendar_slot_duration',
			'clisyc_standard_schedule_calendar_start_time',
			'clisyc_standard_schedule_calendar_end_time',
			'clisyc_frontend_calendar_style',
			'clisyc_auto_generate_enabled',
			'clisyc_auto_generate_lookahead',
			'clisyc_wc_integration_enabled',
			'clisyc_min_booking_notice_minutes',
			'clisyc_global_buffer_before',
			'clisyc_global_buffer_after',
			Constants::OPTION_ENABLE_SELF_SERVICE,
			Constants::OPTION_CANCEL_CUTOFF_VALUE,
			Constants::OPTION_CANCEL_CUTOFF_UNIT,
		];
		$exported_options  = [];
		foreach ( $options_to_export as $option_name ) {
			$exported_options[ $option_name ] = get_option( $option_name );
		}

		// 1. Find the primary dimension slug
		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				if ( ! empty( $settings['primary'] ) ) {
					$primary_dim_slug = $slug;
					break;
				}
			}
		}

		// 2. Build the new SQL query with a JOIN to postmeta
		$sql_params = [];
		$sql_join_capacity = '';

		if ( $primary_dim_slug ) {
			$sql_join_capacity = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from internal class property.
				"LEFT JOIN {$wpdb->postmeta} pm ON (d.dimension_value = pm.post_id AND pm.meta_key = Constants::META_CAPACITY AND d.dimension_key = %s)",
				$primary_dim_slug
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// MODIFIED: Select s.* to include the new 'booking_count' column for debugging
		$slots_query = "
			SELECT s.*,
			GROUP_CONCAT(CONCAT(d.dimension_key, ':::', d.dimension_value) SEPARATOR '|||') AS dimensions_concat,
			" . ( $primary_dim_slug ? 'pm.meta_value as capacity' : '1 as capacity' ) . "
			FROM {$slots_table} s
			LEFT JOIN {$dims_table} d ON s.slot_id = d.slot_id
			{$sql_join_capacity}
			GROUP BY s.slot_id
			ORDER BY s.start_time ASC
		";
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query uses interpolated safe table names from internal methods.
		$slots_results = $wpdb->get_results( $slots_query, ARRAY_A );

		$slots_export_data = [];
		if ( ! empty( $slots_results ) ) {
			foreach ( $slots_results as $row ) {
				$dimensions_array = [];
				if ( ! empty( $row['dimensions_concat'] ) ) {
					$pairs = explode( '|||', $row['dimensions_concat'] );
					foreach ( $pairs as $pair ) {
						$parts = explode( ':::', $pair, 2 );
						if ( count( $parts ) === 2 ) {
							list( $key, $value ) = $parts;
							$dimensions_array[ $key ] = $value;
						}
					}
				}
				$slots_export_data[] = [
					'slot_id'        => (int) $row['slot_id'],
					'start_time_utc' => $row['start_time'],
					'end_time_utc'   => $row['end_time'],
					'is_block'       => (int) $row['is_block'],
					'booking_count'  => (int) ( $row['booking_count'] ?? 0 ), // Export the optimization column
					'capacity'       => (int) ( $row['capacity'] ?? 1 ),
					'dimensions'     => $dimensions_array,
				];
			}
		}

		// --- END: MODIFIED LOGIC ---

		// The rest of the function remains the same...
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from internal method is safe.
		$relationships_data = $wpdb->get_results( "SELECT * FROM {$rels_table} ORDER BY parent_object_type, parent_object_id", ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name uses $wpdb->prefix which is safe.
		$graph_nodes_data = $wpdb->get_results( "SELECT * FROM {$nodes_table} ORDER BY node_type, cpt_slug, wp_post_id", ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name uses $wpdb->prefix which is safe.
		$bookings_data = $wpdb->get_results( "SELECT * FROM {$bookings_table} ORDER BY booking_time_utc DESC", ARRAY_A );

		// --- START: ADDED DIMENSION POST META EXPORT ---
		$dimension_posts_data = [];
		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				if ( empty( $settings['enabled'] ) ) {
					continue;
				}

				$posts = get_posts([
					'post_type'      => $slug,
					'posts_per_page' => -1,
					'post_status'    => 'any', // Get drafts/trash too for completeness
				]);

				foreach ( $posts as $post ) {
					$all_meta = get_post_meta( $post->ID );
					$clisyc_meta = [];

					// Filter to only include plugin-specific meta to keep file size reasonable
					foreach ( $all_meta as $key => $values ) {
						// Export all keys starting with _clisyc_ (schedules, capacity, etc.)
						// Also export standard keys if useful
						if ( strpos( $key, '_clisyc_' ) === 0 ) {
							$clisyc_meta[ $key ] = maybe_unserialize( $values[0] );
						}
					}

					$dimension_posts_data[ $slug ][] = [
						'ID'          => $post->ID,
						'post_title'  => $post->post_title,
						'post_status' => $post->post_status,
						'meta_data'   => $clisyc_meta
					];
				}
			}
		}
		// --- END: ADDED DIMENSION POST META EXPORT ---

		$final_export_data = [
			'plugin_options'        => $exported_options,
			'slots_with_dimensions' => $slots_export_data,
			'relationships'         => $relationships_data,
			'graph_nodes'           => $graph_nodes_data,
			'bookings'              => $bookings_data,
			// Added new section
			'dimension_source_data' => $dimension_posts_data,
		];

		$filename = 'clisyc-db-export-' . wp_date( 'Y-m-d' ) . '.json';
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $final_export_data, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Handles exporting appointments as a CSV file.
	 */
	public function handle_export_appointments_csv(): void {
		if ( ! isset( $_POST['clisyc_export_appt_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clisyc_export_appt_nonce'] ) ), 'clisyc_export_appointments' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		$appointments    = $this->query_appointments_for_export();
		$hipaa           = $this->is_hipaa_mode_active();
		$filename        = 'appointments-' . wp_date( 'Y-m-d' ) . '.csv';
		$dimension_types = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );

		// Prime the post meta cache for all appointments in one query.
		$appt_ids = wp_list_pluck( $appointments, 'ID' );
		if ( $appt_ids ) {
			update_postmeta_cache( $appt_ids );
		}

		// Pre-load author user objects to avoid N+1 get_user_by() calls.
		if ( ! $hipaa && $appt_ids ) {
			$author_ids = array_unique( wp_list_pluck( $appointments, 'post_author' ) );
			// Primes the WP user cache for all authors at once.
			$user_query = new \WP_User_Query( [
				'include'     => $author_ids,
				'fields'      => 'all',
				'count_total' => false,
			] );
			$user_query->get_results();
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );

		// Build ordered list of dimension labels for column headers.
		$dim_slugs  = array_keys( $dimension_types );
		$dim_labels = [];
		foreach ( $dim_slugs as $slug ) {
			$dim_labels[] = $dimension_types[ $slug ]['singular'] ?? ucfirst( str_replace( [ 'clisyc_', '_' ], [ '', ' ' ], $slug ) );
		}

		// Header row.
		$headers = [
			__( 'ID', 'client-sync' ),
			__( 'Date', 'client-sync' ),
			__( 'Time (UTC)', 'client-sync' ),
			__( 'Status', 'client-sync' ),
			__( 'Service', 'client-sync' ),
			__( 'Duration (min)', 'client-sync' ),
		];
		// Add dimension columns.
		foreach ( $dim_labels as $label ) {
			$headers[] = $label;
		}
		if ( ! $hipaa ) {
			$headers[] = __( 'Client Name', 'client-sync' );
			$headers[] = __( 'Client Email', 'client-sync' );
		}
		$headers[] = __( 'Notes', 'client-sync' );

		fputcsv( $output, Security_Helper::csv_escape_row( $headers ) );

		foreach ( $appointments as $appt ) {
			$post_id    = $appt->ID;
			$time_slot  = get_post_meta( $post_id, Constants::META_TIME_SLOT, true );
			$appt_date  = get_post_meta( $post_id, Constants::META_APPOINTMENT_DATE, true );
			$status_obj = get_post_status_object( get_post_status( $post_id ) );
			$status     = $status_obj ? $status_obj->label : get_post_status( $post_id );
			$service    = get_post_meta( $post_id, Constants::META_SERVICE_NAME, true );
			$duration   = get_post_meta( $post_id, Constants::META_APPOINTMENT_DURATION, true );
			$notes      = get_post_meta( $post_id, Constants::META_NOTES, true );

			$row = [
				$post_id,
				$appt_date ?: '',
				$time_slot ?: '',
				$status ?: '',
				$service ?: get_the_title( $post_id ),
				$duration ?: '',
			];

			// Add dimension values.
			$slot_dims = get_post_meta( $post_id, Constants::META_SLOT_DIMENSIONS, true );
			foreach ( $dim_slugs as $slug ) {
				$dim_id    = is_array( $slot_dims ) ? ( $slot_dims[ $slug ] ?? '' ) : '';
				$row[]     = $dim_id ? get_the_title( $dim_id ) : '';
			}

			if ( ! $hipaa ) {
				$client = get_user_by( 'id', $appt->post_author );
				$row[]  = $client ? $client->display_name : '';
				$row[]  = $client ? $client->user_email : '';
			}

			$row[] = $notes ?: '';

			// Guard against CSV formula injection — $notes and dimension
			// titles can be attacker-controlled. See Security_Helper.
			fputcsv( $output, Security_Helper::csv_escape_row( $row ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Writing to php://output stream, not a file.
		fclose( $output );
		exit;
	}

	/**
	 * Handles exporting appointments as an ICS (iCalendar) file.
	 */
	public function handle_export_appointments_ics(): void {
		if ( ! isset( $_POST['clisyc_export_appt_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clisyc_export_appt_nonce'] ) ), 'clisyc_export_appointments' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		$appointments = $this->query_appointments_for_export();
		$hipaa        = $this->is_hipaa_mode_active();
		$filename     = 'appointments-' . wp_date( 'Y-m-d' ) . '.ics';
		$host         = wp_parse_url( home_url(), PHP_URL_HOST );

		// Prime the post meta and user caches to avoid N+1 queries.
		$appt_ids = wp_list_pluck( $appointments, 'ID' );
		if ( $appt_ids ) {
			update_postmeta_cache( $appt_ids );
		}
		if ( ! $hipaa && $appt_ids ) {
			$author_ids = array_unique( wp_list_pluck( $appointments, 'post_author' ) );
			$user_query = new \WP_User_Query( [
				'include'     => $author_ids,
				'fields'      => 'all',
				'count_total' => false,
			] );
			$user_query->get_results();
		}

		$organizer_name  = get_option( Constants::OPTION_EMAIL_FROM_NAME, get_bloginfo( 'name' ) );
		$organizer_email = get_option( Constants::OPTION_EMAIL_FROM_ADDRESS, '' ) ?: get_option( 'admin_email' );

		$lines   = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//ClientSyncPlugin//NONSGML v1.0//EN';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'X-WR-CALNAME:' . $this->escape_ical( get_bloginfo( 'name' ) . ' Appointments' );

		foreach ( $appointments as $appt ) {
			$post_id        = $appt->ID;
			$time_slot_str  = get_post_meta( $post_id, Constants::META_TIME_SLOT, true );
			$duration       = (int) get_post_meta( $post_id, Constants::META_APPOINTMENT_DURATION, true );

			if ( empty( $time_slot_str ) || $duration <= 0 ) {
				continue;
			}

			try {
				$start = new \DateTime( $time_slot_str, new \DateTimeZone( 'UTC' ) );
				$end   = ( clone $start )->modify( "+{$duration} minutes" );
			} catch ( \Exception $e ) {
				continue;
			}

			$uid     = md5( 'clisyc-' . $post_id . '-' . $time_slot_str ) . '@' . $host;
			$summary = get_the_title( $post_id );
			$desc    = wp_strip_all_tags( $appt->post_content );
			$notes   = get_post_meta( $post_id, Constants::META_NOTES, true );
			if ( $notes ) {
				$desc = $desc ? $desc . '\n\n' . $notes : $notes;
			}

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $uid;
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
			$lines[] = 'DTSTART:' . $start->format( 'Ymd\THis\Z' );
			$lines[] = 'DTEND:' . $end->format( 'Ymd\THis\Z' );
			$lines[] = 'SUMMARY:' . $this->escape_ical( $summary );
			if ( $desc ) {
				$lines[] = 'DESCRIPTION:' . $this->escape_ical( $desc );
			}
			$lines[] = 'ORGANIZER;CN="' . $this->escape_ical( $organizer_name ) . '":MAILTO:' . $organizer_email;

			if ( ! $hipaa ) {
				$client = get_user_by( 'id', $appt->post_author );
				if ( $client && $client->user_email ) {
					$lines[] = 'ATTENDEE;CN="' . $this->escape_ical( $client->display_name ) . '";ROLE=REQ-PARTICIPANT:mailto:' . $client->user_email;
				}
			}

			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: outputting raw .ics text, not HTML.
		echo implode( "\r\n", $lines );
		exit;
	}

	/**
	 * Queries appointments for export based on POST filters (date range, status).
	 *
	 * @return array Array of WP_Post objects.
	 */
	private function query_appointments_for_export(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by calling method.
		$post_data     = wp_unslash( $_POST );
		$start_date    = ! empty( $post_data['start_date'] ) ? sanitize_text_field( $post_data['start_date'] ) : '';
		$end_date      = ! empty( $post_data['end_date'] ) ? sanitize_text_field( $post_data['end_date'] ) : '';
		$status_filter = ! empty( $post_data['export_status'] ) ? sanitize_text_field( $post_data['export_status'] ) : 'any';

		$meta_query = [];
		if ( $start_date && $end_date ) {
			$meta_query[] = [
				'key'     => Constants::META_APPOINTMENT_DATE,
				'value'   => [ $start_date, $end_date ],
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			];
		} elseif ( $start_date ) {
			$meta_query[] = [
				'key'     => Constants::META_APPOINTMENT_DATE,
				'value'   => $start_date,
				'compare' => '>=',
				'type'    => 'DATE',
			];
		} elseif ( $end_date ) {
			$meta_query[] = [
				'key'     => Constants::META_APPOINTMENT_DATE,
				'value'   => $end_date,
				'compare' => '<=',
				'type'    => 'DATE',
			];
		}

		// Determine post statuses.
		$post_statuses = 'any';
		if ( 'any' !== $status_filter ) {
			$allowed_statuses = [ 'publish', 'confirmed', 'clisyc_paid_on_day', 'pending', 'clisyc_pending_pay', 'cancelled', 'trash', 'wc-processing', 'wc-completed', 'draft' ];
			if ( in_array( $status_filter, $allowed_statuses, true ) ) {
				$post_statuses = [ $status_filter ];
			}
		}

		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => $post_statuses,
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => Constants::META_APPOINTMENT_DATE,
			'order'          => 'ASC',
		];

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		return get_posts( $args );
	}

	/**
	 * Escapes a string for safe inclusion in an iCalendar (.ics) file.
	 *
	 * @param string $text The text to escape.
	 * @return string The escaped text.
	 */
	private function escape_ical( string $text ): string {
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( ',', '\\,', $text );
		$text = str_replace( ';', '\\;', $text );
		$text = preg_replace( "/\r\n|\n|\r/", '\\n', $text );
		return $text;
	}
}
