<?php
/**
 * Slot Management Handler.
 *
 * Handles admin actions related to time slot management, including
 * generating slots, removing past slots, and list view actions
 * (single delete, bulk delete).
 *
 * Extracted from class-form-handler.php following the Single Responsibility
 * Principle to isolate slot management concerns into a dedicated class.
 *
 * @package DependentMedia\ClientSync\Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Core\Cron;
use DependentMedia\ClientSync\Constants;

/**
 * Class Slot_Management_Handler
 *
 * Manages admin-side time slot operations such as generation,
 * removal of past slots, and list view bulk/single actions.
 */
class Slot_Management_Handler {

	/**
	 * Database manager instance.
	 *
	 * @var Database_Manager
	 */
	private $db_manager;

	/**
	 * Constructor.
	 *
	 * @param Database_Manager $db_manager Database manager instance.
	 */
	public function __construct( Database_Manager $db_manager ) {
		$this->db_manager = $db_manager;
	}

	/**
	 * Register WordPress hooks for slot management actions.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_init', [ $this, 'handle_list_view_actions' ] );
		add_action( 'admin_post_clisyc_handle_generate_slots_submission', [ $this, 'handle_generate_slots_submission' ] );
		add_action( 'admin_post_clisyc_handle_remove_past_slots', [ $this, 'handle_remove_past_slots' ] );
	}

	/**
	 * Handle the generate slots form submission.
	 *
	 * @return void
	 */
	public function handle_generate_slots_submission() {
		check_admin_referer( 'clisyc_save_time_slots', 'clisyc_time_slot_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'client-sync' ) );
		}

		$start_date_str = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date_str   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$errors         = [];
		$start_date     = null;
		$end_date       = null;
		$site_timezone  = wp_timezone();

		try {
			if ( ! $start_date_str || ! $end_date_str ) {
				throw new \Exception( __( 'Start and end dates are required.', 'client-sync' ) );
			}
			$start_date = new \DateTime( $start_date_str, $site_timezone );
			$end_date   = new \DateTime( $end_date_str, $site_timezone );
			if ( $end_date < $start_date ) {
				throw new \Exception( __( 'End date cannot be before start date.', 'client-sync' ) );
			}
		} catch ( \Exception $e ) {
			$errors[] = $e->getMessage();
		}

		if ( empty( $errors ) ) {
			$cron_manager = new Cron( $this->db_manager );
			$result       = $cron_manager->run_slot_generation_for_date_range( $start_date, $end_date );

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				$inserted_count = $result['inserted'] ?? 0;
				if ( $inserted_count > 0 ) {
					/* translators: %d: The number of slots generated or updated. */
					set_transient( 'clisyc_manage_slots_feedback', [ 'message' => sprintf( _n( '%d slot generated/updated.', '%d slots generated/updated.', $inserted_count, 'client-sync' ), $inserted_count ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
				} else {
					set_transient( 'clisyc_manage_slots_feedback', [ 'message' => __( 'No new slots were generated. This could be due to conflicts with existing bookings, or no valid schedules were found for the selected date range.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
				}
			}
		}

		if ( ! empty( $errors ) ) {
			set_transient( 'clisyc_manage_slots_feedback', [ 'error' => implode( '<br>', $errors ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=clisyc-calendars&tab=time-slots' ) );
		exit;
	}

	/**
	 * Handle the remove past slots form submission.
	 *
	 * @return void
	 */
	public function handle_remove_past_slots() {
		check_admin_referer( 'clisyc_remove_past_slots_action', 'clisyc_remove_past_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'client-sync' ) );
		}
		$deleted_count = $this->db_manager->delete_past_available_slots();
		if ( is_wp_error( $deleted_count ) ) {
			set_transient( 'clisyc_manage_slots_feedback', [ 'error' => __( 'Error removing past slots: ', 'client-sync' ) . $deleted_count->get_error_message() ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		} elseif ( $deleted_count > 0 ) {
			/* translators: %d: The number of past available slots removed. */
			set_transient( 'clisyc_manage_slots_feedback', [ 'message' => sprintf( _n( '%d past available slot removed.', '%d past available slots removed.', $deleted_count, 'client-sync' ), $deleted_count ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		} else {
			set_transient( 'clisyc_manage_slots_feedback', [ 'message' => __( 'No past available slots found to remove.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=clisyc-available-slots-list' ) );
		exit;
	}

	/**
	 * Handle list view actions (single delete, bulk delete) on the available slots list page.
	 *
	 * @return void
	 */
	public function handle_list_view_actions() {
		$action = $this->current_action();
		$page   = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'clisyc-available-slots-list' !== $page || ! $action ) {
			return;
		}

		if ( 'clisyc_single_delete' === $action ) {
			$slot_id = isset( $_GET['slot_id'] ) ? absint( $_GET['slot_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $slot_id > 0 ) {
				check_admin_referer( 'clisyc_delete_slot_' . $slot_id );
				if ( current_user_can( 'manage_options' ) ) {
					global $wpdb;
					$slots_table = $this->db_manager->get_slots_table_name();
					$dims_table  = $this->db_manager->get_dimensions_table_name();
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from internal class method.
					$wpdb->delete( $dims_table, [ 'slot_id' => $slot_id ], [ '%d' ] );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from internal class method.
					$deleted = $wpdb->delete( $slots_table, [ 'slot_id' => $slot_id ], [ '%d' ] );
					if ( $deleted ) {
						$this->db_manager->clear_all_slot_cache();
						set_transient( 'clisyc_manage_slots_feedback', [ 'message' => __( 'Time slot deleted successfully.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
					} else {
						set_transient( 'clisyc_manage_slots_feedback', [ 'error' => __( 'Could not delete the specified time slot from the database.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
					}
				}
			}
		}

		if ( 'bulk-delete' === $action ) {
			check_admin_referer( 'bulk-availableslots' );
			if ( current_user_can( 'manage_options' ) ) {
				$selected_slots = isset( $_POST['selected_slots'] ) && is_array( $_POST['selected_slots'] ) ? array_map( 'absint', wp_unslash( $_POST['selected_slots'] ) ) : [];
				$ids_to_delete  = $selected_slots;
				if ( ! empty( $ids_to_delete ) ) {
					global $wpdb;
					$how_many      = count( $ids_to_delete );
					$placeholders  = implode( ',', array_fill( 0, $how_many, '%d' ) );
					$slots_table   = $this->db_manager->get_slots_table_name();
					$dims_table    = $this->db_manager->get_dimensions_table_name();

					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is dynamically generated and safe.
					$sql_delete_dims = "DELETE FROM {$dims_table} WHERE slot_id IN ($placeholders)";
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared above; table name from internal method is safe.
					$wpdb->query( $wpdb->prepare( $sql_delete_dims, $ids_to_delete ) );

					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is dynamically generated and safe.
					$sql_delete_slots = "DELETE FROM {$slots_table} WHERE slot_id IN ($placeholders)";
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared above; table name from internal method is safe.
					$deleted_count = $wpdb->query( $wpdb->prepare( $sql_delete_slots, $ids_to_delete ) );

					if ( $deleted_count > 0 ) {
						$this->db_manager->clear_all_slot_cache();
					}
					/* translators: %d: The number of time slots deleted. */
					set_transient( 'clisyc_manage_slots_feedback', [ 'message' => sprintf( _n( '%d time slot deleted.', '%d time slots deleted.', (int) $deleted_count, 'client-sync' ), (int) $deleted_count ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
				}
			}
		}

		$referer_url = wp_get_referer();
		if ( false === $referer_url ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$referer_page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : 'clisyc-dashboard';
			$referer_url  = admin_url( 'admin.php?page=' . $referer_page );
		}
		if ( ! is_string( $referer_url ) ) {
			$referer_url = admin_url( 'admin.php?page=clisyc-dashboard' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_url = remove_query_arg( [ 'action', 'slot_id', '_wpnonce', 'action2', 'selected_slots', '_wp_http_referer' ], $referer_url );
		if ( $redirect_url ) {
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Determine the current action from the request parameters.
	 *
	 * Mirrors the logic of WP_List_Table::current_action() so this
	 * handler can operate independently of the list table instance.
	 *
	 * @return string|false The current action or false if none.
	 */
	private function current_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['filter_action'] ) && ! empty( $_REQUEST['filter_action'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['action'] ) && -1 != $_REQUEST['action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['action2'] ) && -1 != $_REQUEST['action2'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		return false;
	}
}
