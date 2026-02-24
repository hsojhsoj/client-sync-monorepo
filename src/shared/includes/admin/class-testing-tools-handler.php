<?php
/**
 * File: src/shared/includes/admin/class-testing-tools-handler.php
 * Handles all admin-post.php form submissions for the Testing Tools page.
 *
 * Extracted from class-form-handler.php following the Single Responsibility Principle
 * to isolate testing/debugging tool actions into their own dedicated handler.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Testing_Tools_Handler {

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
	 * Register WordPress hooks for testing tool form submissions.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_clisyc_handle_testing_actions', [ $this, 'handle_testing_actions' ] );
	}

	/**
	 * Handle all testing tool form submissions.
	 *
	 * @return void
	 */
	public function handle_testing_actions() {
		$action = isset( $_POST['clisyc_test_action'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_test_action'] ) ) : '';
		if ( ! $action || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Invalid action or insufficient permissions.', 'client-sync' ) );
		}
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			wp_die( esc_html__( 'Testing tools are only available when WP_DEBUG is enabled.', 'client-sync' ) );
		}

		$redirect_url = admin_url( 'admin.php?page=clisyc-testing' );
		$feedback     = [];

		switch ( $action ) {
			case 'generate_file_tree':
				$nonce = isset( $_POST['clisyc_testing_file_tree_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_testing_file_tree_nonce'] ) ) : '';
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_testing_generate_file_tree' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
				}

				$file_tree_content = \DependentMedia\ClientSync\Admin\Admin::generate_file_tree( clisyc_PLUGIN_DIR );
				$filename          = 'clisyc-file-tree-' . wp_date( 'Y-m-d' ) . '.txt';

				header( 'Content-Type: text/plain; charset=utf-8' );
				header( 'X-Content-Type-Options: nosniff' );
				header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $file_tree_content;
				exit;

			case 'generate_site_file_tree':
				$nonce = isset( $_POST['clisyc_testing_site_file_tree_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_testing_site_file_tree_nonce'] ) ) : '';
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_testing_generate_site_file_tree' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
				}

				// SECURITY: Limit traversal depth to 3 levels to reduce server structure exposure.
				$file_tree_content = \DependentMedia\ClientSync\Admin\Admin::generate_file_tree( ABSPATH, 3 );
				$filename          = 'site-root-file-tree-' . wp_date( 'Y-m-d' ) . '.txt';

				header( 'Content-Type: text/plain; charset=utf-8' );
				header( 'X-Content-Type-Options: nosniff' );
				header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $file_tree_content;
				exit;

			case 'force_run_frequent_maintenance':
				$nonce = isset( $_POST['clisyc_testing_run_frequent_cron_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_testing_run_frequent_cron_nonce'] ) ) : '';
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_testing_run_frequent_cron' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
				}

				// Reset state to ensure manual run starts fresh
				delete_option( Constants::OPTION_SLOT_GEN_STATE );

				$cron_manager = new \DependentMedia\ClientSync\Core\Cron( $this->db_manager );
				$cron_manager->run_frequent_maintenance_tasks();
				$feedback['message'] = __( 'Forced maintenance tasks complete. Check debug.log for details on slot generation.', 'client-sync' );

				// Redirect back to the Automation tab if that's where the request came from
				$referer = wp_get_referer();
				if ( $referer && strpos( $referer, 'tab=automation' ) !== false ) {
					$redirect_url = admin_url( 'admin.php?page=clisyc-settings&tab=automation' );
				}
				break;

			case 'reset_plugin':
				$nonce = isset( $_POST['clisyc_testing_reset_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_testing_reset_nonce'] ) ) : '';
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_testing_reset' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
				}

				// 1. Wipe all dimension CPTs and tables.
				if ( class_exists( '\DependentMedia\ClientSync\Admin\Onboarding_Wizard' ) ) {
					$wizard_instance = new \DependentMedia\ClientSync\Admin\Onboarding_Wizard();
					$wizard_instance->wipe_all_dimension_data();
				}

				// 2. Wipe appointment CPTs and related tables
				global $wpdb;
				$slots_table    = $this->db_manager->get_slots_table_name();
				$dims_table     = $this->db_manager->get_dimensions_table_name();
				$bookings_table = $wpdb->prefix . 'clisyc_bookings';

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from internal methods are safe; truncating tables is an admin action.
				$wpdb->query( "TRUNCATE TABLE {$slots_table}" );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from internal methods are safe.
				$wpdb->query( "TRUNCATE TABLE {$dims_table}" );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name uses $wpdb->prefix which is safe.
				$wpdb->query( "TRUNCATE TABLE {$bookings_table}" );

				$appointment_posts = get_posts(
					[
						'post_type'   => Constants::POST_TYPE_APPOINTMENT,
						'post_status' => 'any',
						'numberposts' => -1,
						'fields'      => 'ids',
					]
				);
				foreach ( $appointment_posts as $post_id ) {
					wp_delete_post( $post_id, true );
				}

				// 2b. Wipe Venue CPTs (Seat Selection module)
				$venue_posts = get_posts(
					[
						'post_type'   => Constants::POST_TYPE_VENUE,
						'post_status' => 'any',
						'numberposts' => -1,
						'fields'      => 'ids',
					]
				);
				foreach ( $venue_posts as $post_id ) {
					wp_delete_post( $post_id, true );
				}

				// 3. Delete all plugin options
				$options_to_delete = [
					Constants::OPTION_CALENDAR_START_TIME, Constants::OPTION_CALENDAR_END_TIME, 'clisyc_calendar_slot_duration',
					'clisyc_standard_schedule_calendar_start_time', 'clisyc_standard_schedule_calendar_end_time',
					'clisyc_frontend_calendar_style', 'clisyc_login_page_url', 'clisyc_last_frequent_maintenance_run_timestamp',
					'clisyc_available_time_slots', Constants::OPTION_CUSTOM_FIELDS, Constants::OPTION_CUSTOM_FIELDS_ORDER,
					Constants::OPTION_APPOINTMENT_FIELDS, 'clisyc_client_list_custom_field_column',
					Constants::OPTION_NOTIFICATION_SETTINGS, Constants::OPTION_EMAIL_FROM_NAME, Constants::OPTION_EMAIL_FROM_ADDRESS,
					Constants::OPTION_NOTIFICATION_ADMINS, 'clisyc_reminder_settings', 'clisyc_appointment_view_page_slug',
					'clisyc_auto_generate_enabled', 'clisyc_auto_generate_lookahead', 'clisyc_wc_integration_enabled',
					'clisyc_wc_appointment_product_id', 'clisyc_min_booking_notice_minutes', 'clisyc_global_buffer_before',
					'clisyc_global_buffer_after', 'clisyc_slots_migrated_to_db_v2', Constants::OPTION_SETUP_MILESTONES,
					'clisyc_calendar_smart_start_date', 'clisyc_calendar_week_starts_on', 'clisyc_debug_standard_schedule_output',
					'clisyc_appointment_view_page_id', 'clisyc_booking_page_id', 'clisyc_booking_success_page_id',
					Constants::OPTION_ENABLE_SELF_SERVICE, Constants::OPTION_CANCEL_CUTOFF_VALUE, Constants::OPTION_CANCEL_CUTOFF_UNIT,
					Constants::OPTION_CANCEL_REFUND_POLICY, 'clisyc_mysql_convert_tz_override', 'clisyc_mysql_convert_tz_status',
					Constants::OPTION_CALENDAR_COLOR_SETTINGS, Constants::OPTION_DIMENSION_REGISTRY, Constants::OPTION_CUSTOM_DIMENSION_TYPES,
					// NEW OPTIONS
					'clisyc_slot_gen_state',
					'clisyc_search_results_page_id',
					Constants::OPTION_GLOBAL_BLOCKED_PERIODS,
					'clisyc_manager_edit_page_id',
					'clisyc_contact_page_id',
					'clisyc_last_successful_maintenance_run_timestamp',
					Constants::OPTION_SEAT_HOLD_TTL,
				];
				foreach ( $options_to_delete as $option_name ) {
					delete_option( $option_name );
				}

				// 4. Clean up transients and crons
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_clisyc_' ) . '%' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_clisyc_' ) . '%' ) );

				$cron_manager = new \DependentMedia\ClientSync\Core\Cron( $this->db_manager );
				$cron_manager->unschedule_cron_jobs();

				// 5. Reactivate to set up defaults
				if ( class_exists( '\DependentMedia\ClientSync\Plugin' ) ) {
					$plugin_instance = new \DependentMedia\ClientSync\Plugin();
					$plugin_instance->activate();
				}

				$feedback['message'] = __( 'Plugin has been completely reset to default settings.', 'client-sync' );
				break;

			case 'repair_relationships':
				$nonce = isset( $_POST['clisyc_testing_repair_rels_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_testing_repair_rels_nonce'] ) ) : '';
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_testing_repair_rels' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
				}
				$db_manager = new \DependentMedia\ClientSync\Core\Database_Manager();
				$result     = $db_manager->repair_missing_relationship_types();
				/* translators: 1: Number of repaired rows. 2: Number of unrepaired rows. */
				$feedback['message'] = sprintf( __( 'Relationship repair complete. Repaired: %1$d rows. Could not repair: %2$d rows.', 'client-sync' ), $result['repaired'], $result['unrepaired'] );
				break;

			case 'rebuild_graph_index':
				$nonce = isset( $_POST['clisyc_rebuild_index_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_rebuild_index_nonce'] ) ) : '';
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_rebuild_graph_index_action' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
				}
				if ( class_exists( '\DependentMedia\ClientSync\Admin\Graph_Node_Manager' ) ) {
					$graph_node_manager = new \DependentMedia\ClientSync\Admin\Graph_Node_Manager();
					$graph_node_manager->rebuild_index();
					$feedback['message'] = __( 'The dimension graph index has been successfully rebuilt.', 'client-sync' );
				} else {
					$feedback['error'] = __( 'Could not find the Graph_Node_Manager class.', 'client-sync' );
				}
				break;

			case 'clear_transients':
				$nonce = isset( $_POST['clisyc_testing_clear_transients_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_testing_clear_transients_nonce'] ) ) : '';
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_testing_clear_transients' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
				}
				$db_manager = new \DependentMedia\ClientSync\Core\Database_Manager();
				$db_manager->clear_all_slot_cache();

				// Also clear Smart Start Date cached option (used by some shortcodes)
				delete_option( 'clisyc_calendar_smart_start_date_next_available' );

				// Clear MySQL timezone detection status to allow re-detection
				delete_option( 'clisyc_mysql_convert_tz_status' );

				// Clear the Batch Generation State (fixes stalled cron jobs)
				delete_option( Constants::OPTION_SLOT_GEN_STATE );

				$feedback['message'] = __( 'All Client Sync transients, caches, generation states, and Smart Start Date data have been cleared.', 'client-sync' );
				break;

			case 'remove_ds_store':
				$nonce = isset( $_POST['clisyc_testing_remove_ds_store_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_testing_remove_ds_store_nonce'] ) ) : '';
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_testing_remove_ds_store' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
				}

				$deleted_count = 0;
				try {
					// THE FIX: Scan from the parent of the shared directory to cover shared, free, and pro.
					// We use CLISYC_SHARED_DIR as our anchor and go up one level to the 'src' folder.
					$scan_path = dirname( rtrim( CLISYC_SHARED_DIR, '/\\' ) );

					// We use RecursiveDirectoryIterator WITHOUT SKIP_DOTS to ensure .DS_Store is found.
					$dir_iterator = new \RecursiveDirectoryIterator( $scan_path );
					$iterator     = new \RecursiveIteratorIterator( $dir_iterator, \RecursiveIteratorIterator::CHILD_FIRST );

					foreach ( $iterator as $file_info ) {
						// Check for exactly '.DS_Store'
						if ( $file_info->getFilename() === '.DS_Store' ) {
							$path = $file_info->getRealPath();

							/**
							 * We use native PHP unlink and is_writable here because this is a specific
							 * cleanup utility for hidden system files. Using WP_Filesystem here would
							 * require prompting the admin for credentials on many server setups,
							 * which is intrusive for a simple developer maintenance tool.
							 */

							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPress.WP.AlternativeFunctions.unlink_unlink -- Native functions used for targeted system file cleanup.
							if ( file_exists( $path ) && is_writable( $path ) && @unlink( $path ) ) {
								$deleted_count++;
							}
						}
					}

					if ( $deleted_count > 0 ) {
						/* translators: %d: The number of .DS_Store files found and deleted. */
						$feedback['message'] = sprintf( _n( 'Success: %d .DS_Store file was found and deleted.', 'Success: %d .DS_Store files were found and deleted.', $deleted_count, 'client-sync' ), $deleted_count );
					} else {
						$feedback['message'] = __( 'Scan complete. No .DS_Store files were found in the source directories.', 'client-sync' );
					}
				} catch ( \Exception $e ) {
					$feedback['error'] = __( 'An error occurred while scanning:', 'client-sync' ) . ' ' . $e->getMessage();
				}
				break;

			case 'fix_dimension_registry':
				$nonce = isset( $_POST['clisyc_testing_fix_registry_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_testing_fix_registry_nonce'] ) ) : '';
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_testing_fix_registry' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
				}
				$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
				// Force Practitioners to be a Resource
				if ( isset( $registry['dimensions'] ) && isset( $registry['dimensions']['clisyc_practitioner'] ) ) {
					$registry['dimensions']['clisyc_practitioner']['is_resource'] = true;
					update_option( Constants::OPTION_DIMENSION_REGISTRY, $registry );
					$feedback['message'] = __( 'Registry updated: Practitioners are now explicitly a Resource.', 'client-sync' );
				} else {
					$feedback['error'] = __( 'Practitioner dimension not found.', 'client-sync' );
				}
				break;
		}

		// Set feedback transient based on redirect destination
		if ( strpos( $redirect_url, 'tab=automation' ) !== false ) {
			set_transient( 'clisyc_automation_feedback', $feedback, Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		} else {
			set_transient( 'clisyc_testing_feedback', $feedback, Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		}
		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}
}
