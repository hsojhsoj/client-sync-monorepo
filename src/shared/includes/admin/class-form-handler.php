<?php
/**
 * File: src/shared/includes/admin/class-form-handler.php
 * Orchestrates admin-post.php form submissions for the admin area.
 *
 * Delegates to focused sub-handlers following the Single Responsibility Principle:
 *  - Custom_Fields_Handler      — client, appointment, and dimension custom field CRUD.
 *  - Data_Export_Import_Handler  — settings/database/appointment export and import.
 *  - Testing_Tools_Handler      — developer testing & debugging actions.
 *  - Slot_Management_Handler    — time-slot generation, removal, and list-view actions.
 *
 * This class retains: dimension schedule saving, blocked-period management,
 * milestone completion, and dimension-type CRUD.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Admin\Dimension_CPT_Manager;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Form_Handler {

	/**
	 * A reference to the database manager instance.
	 *
	 * @var \DependentMedia\ClientSync\Core\Database_Manager
	 */
	private $db_manager;

	/**
	 * @var Custom_Fields_Handler
	 */
	private $custom_fields_handler;

	/**
	 * @var Data_Export_Import_Handler
	 */
	private $export_import_handler;

	/**
	 * @var Testing_Tools_Handler
	 */
	private $testing_handler;

	/**
	 * @var Slot_Management_Handler
	 */
	private $slot_handler;

	/**
	 * Constructor.
	 *
	 * @param \DependentMedia\ClientSync\Core\Database_Manager $db_manager An instance of the database manager class.
	 */
	public function __construct( Database_Manager $db_manager ) {
		$this->db_manager = $db_manager;

		// Instantiate focused sub-handlers.
		$this->custom_fields_handler = new Custom_Fields_Handler();
		$this->export_import_handler = new Data_Export_Import_Handler( $db_manager );
		$this->testing_handler       = new Testing_Tools_Handler( $db_manager );
		$this->slot_handler          = new Slot_Management_Handler( $db_manager );
	}

	/**
	 * Registers all form handling hooks.
	 */
	public function register_hooks() {
		// Delegate to sub-handlers.
		$this->custom_fields_handler->register_hooks();
		$this->export_import_handler->register_hooks();
		$this->testing_handler->register_hooks();
		$this->slot_handler->register_hooks();

		// Hooks handled directly by this class.
		add_action( 'admin_post_clisyc_save_blocked_period', [ $this, 'handle_save_blocked_period' ] );
		add_action( 'admin_post_clisyc_delete_blocked_period', [ $this, 'handle_delete_blocked_period' ] );
		add_action( 'admin_post_clisyc_mark_milestone_complete', [ $this, 'handle_mark_milestone_complete' ] );
		add_action( 'admin_post_clisyc_add_dimension_type', [ $this, 'handle_add_or_update_dimension_type' ] );
		add_action( 'admin_post_clisyc_delete_dimension_type', [ $this, 'handle_delete_dimension_type' ] );

		// Saves the weekly schedule for dimension posts.
		add_action( 'save_post', [ $this, 'save_dimension_schedule' ], 10, 2 );
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

	// =====================================================================
	// Dimension Schedule (save_post hook)
	// =====================================================================

	/**
	 * Saves the weekly schedule when a dimension post is saved.
	 * Hooked into save_post for all dimension post types.
	 *
	 * @param int     $post_id The ID of the post being saved.
	 * @param \WP_Post $post    The post object.
	 */
	public function save_dimension_schedule( $post_id, $post ) {
		// Check if this is an autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Verify the user has permission to edit this post
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Check if our schedule data field exists in the POST data
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() in metabox rendering or generally on post save.
		if ( ! isset( $_POST['clisyc_schedule_json_output'] ) ) {
			return;
		}

		// Get and decode the schedule data
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified on post save. JSON is decoded immediately.
		$schedule_json = isset( $_POST['clisyc_schedule_json_output'] ) ? wp_unslash( $_POST['clisyc_schedule_json_output'] ) : '';

		if ( empty( $schedule_json ) ) {
			return;
		}

		$schedule_data = json_decode( $schedule_json, true );

		// Validate that we have valid JSON
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			Debug_Logger::log( 'Invalid JSON in schedule data for post ' . $post_id . ': ' . json_last_error_msg(), 'AdminForm' );
			return;
		}

		// Optional: Additional validation
		if ( ! is_array( $schedule_data ) ) {
			Debug_Logger::log( 'Schedule data is not an array for post ' . $post_id, 'AdminForm' );
			return;
		}

		// Recursively sanitize all string values in the schedule data to prevent stored XSS.
		$schedule_data = $this->sanitize_schedule_data( $schedule_data );

		// Save the schedule data as post meta
		$result = update_post_meta( $post_id, Constants::META_SCHEDULE, wp_json_encode( $schedule_data ) );

		// Optional: Log success for debugging
		if ( $result ) {
			Debug_Logger::log( 'Successfully saved schedule for post ' . $post_id, 'AdminForm' );
		}
	}

	/**
	 * Recursively sanitize all string values in a schedule data array.
	 *
	 * @param array $data The schedule data to sanitize.
	 * @return array The sanitized schedule data.
	 */
	private function sanitize_schedule_data( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->sanitize_schedule_data( $value );
			} elseif ( is_string( $value ) ) {
				$data[ $key ] = sanitize_text_field( $value );
			}
			// Numeric and boolean values pass through unchanged.
		}
		return $data;
	}

	// =====================================================================
	// Setup Milestones
	// =====================================================================

	/**
	 * Handles marking a setup milestone as complete.
	 */
	public function handle_mark_milestone_complete() {
		$milestone_key = isset( $_POST['milestone_key'] ) ? sanitize_key( wp_unslash( $_POST['milestone_key'] ) ) : '';
		$nonce         = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_mark_milestone_' . $milestone_key ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		if ( ! empty( $milestone_key ) ) {
			$completed_milestones_option = get_option( Constants::OPTION_SETUP_MILESTONES, [] );
			if ( ! is_array( $completed_milestones_option ) ) {
				$completed_milestones_option = [];
			}
			$completed_milestones_option[ $milestone_key ] = true;
			update_option( Constants::OPTION_SETUP_MILESTONES, $completed_milestones_option );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=clisyc-guide' ) );
		exit;
	}

	// =====================================================================
	// Calendar Blocked Periods
	// =====================================================================

	/**
	 * Handles saving a new global blocked period.
	 */
	public function handle_save_blocked_period() {
		$nonce      = isset( $_POST['clisyc_blocked_period_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_blocked_period_nonce'] ) ) : '';
		$title      = isset( $_POST['blocked_period_title'] ) ? sanitize_text_field( wp_unslash( $_POST['blocked_period_title'] ) ) : '';
		$start_date = isset( $_POST['blocked_period_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['blocked_period_start_date'] ) ) : '';
		$end_date   = isset( $_POST['blocked_period_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['blocked_period_end_date'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_save_blocked_period_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		if ( empty( $title ) || empty( $start_date ) || empty( $end_date ) ) {
			set_transient( 'clisyc_blocked_periods_feedback', [ 'error' => __( 'All fields are required to add a blocked period.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		} elseif ( strtotime( $end_date ) < strtotime( $start_date ) ) {
			set_transient( 'clisyc_blocked_periods_feedback', [ 'error' => __( 'End date cannot be before the start date.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		} else {
			$blocked_periods   = get_option( Constants::OPTION_GLOBAL_BLOCKED_PERIODS, [] );
			$blocked_periods[] = [
				'title'      => $title,
				'start_date' => $start_date,
				'end_date'   => $end_date,
			];
			update_option( Constants::OPTION_GLOBAL_BLOCKED_PERIODS, $blocked_periods );
			set_transient( 'clisyc_blocked_periods_feedback', [ 'message' => __( 'Blocked period added successfully.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=clisyc-calendars&tab=blocked-periods' ) );
		exit;
	}

	/**
	 * Handles deleting an existing global blocked period.
	 */
	public function handle_delete_blocked_period() {
		$period_index = isset( $_GET['period_index'] ) ? absint( wp_unslash( $_GET['period_index'] ) ) : -1;
		$nonce        = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_delete_blocked_period_' . $period_index ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		if ( $period_index >= 0 ) {
			$blocked_periods = get_option( Constants::OPTION_GLOBAL_BLOCKED_PERIODS, [] );
			if ( isset( $blocked_periods[ $period_index ] ) ) {
				unset( $blocked_periods[ $period_index ] );
				// Re-index the array to prevent issues.
				update_option( Constants::OPTION_GLOBAL_BLOCKED_PERIODS, array_values( $blocked_periods ) );
				set_transient( 'clisyc_blocked_periods_feedback', [ 'message' => __( 'Blocked period deleted.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=clisyc-calendars&tab=blocked-periods' ) );
		exit;
	}

	// =====================================================================
	// Dimension Type CRUD
	// =====================================================================

	public function handle_add_or_update_dimension_type() {
		$nonce     = isset( $_POST['clisyc_add_dimension_nonce'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_add_dimension_nonce'] ) ) : '';
		$action    = isset( $_POST['clisyc_dimension_action'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_dimension_action'] ) ) : 'add';
		$plural    = isset( $_POST['dim_plural_name'] ) ? sanitize_text_field( wp_unslash( $_POST['dim_plural_name'] ) ) : '';
		$singular  = isset( $_POST['dim_singular_name'] ) ? sanitize_text_field( wp_unslash( $_POST['dim_singular_name'] ) ) : '';
		$icon      = isset( $_POST['dim_icon'] ) ? sanitize_text_field( wp_unslash( $_POST['dim_icon'] ) ) : 'dashicons-admin-generic';
		$public    = ! empty( $_POST['dim_public'] );
		$slug      = ( 'update' === $action )
			? ( isset( $_POST['edit_dimension_slug'] ) ? sanitize_key( wp_unslash( $_POST['edit_dimension_slug'] ) ) : '' )
			: ( isset( $_POST['dim_slug'] ) ? sanitize_key( wp_unslash( $_POST['dim_slug'] ) ) : '' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_add_dimension_type_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		$custom_types       = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
		$is_first_dimension = empty( $custom_types );
		$is_public_flag     = false; // Default to false

		if ( 'add' === $action ) {
			// For any new dimension, set it to be Public by default.
			$is_public_flag = true;
		} elseif ( 'update' === $action && isset( $custom_types[ $slug ] ) ) {
			// When updating, preserve the existing 'public' status.
			$is_public_flag = ! empty( $custom_types[ $slug ]['public'] );
		}

		$result = Dimension_CPT_Manager::save_custom_dimension( $singular, $plural, $slug, $icon, $is_public_flag );

		if ( is_wp_error( $result ) ) {
			set_transient( 'clisyc_admin_feedback', [ 'error' => $result->get_error_message() ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
			wp_safe_redirect( admin_url( 'admin.php?page=clisyc-dimensions&tab=setup' ) );
			exit;
		}

		if ( 'add' === $action ) {
			$prefixed_slug = 'clisyc_' . str_replace( [ 'cs_', 'clisyc_' ], '', $slug );
			$registry      = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] ); // Still need this for updating
			// The is_first_dimension check is already performed above, we can reuse it.

			if ( ! isset( $registry['dimensions'] ) ) {
				$registry['dimensions'] = [];
			}

			// If this is the very first dimension being enabled, apply smart defaults.
			if ( $is_first_dimension ) {
				$registry['dimensions'][ $prefixed_slug ] = [
					'enabled'          => true,
					'primary'          => true,
					'frontend_visible' => true,
					'is_resource'      => false, // Corrected default
				];
			} else {
				$registry['dimensions'][ $prefixed_slug ] = [
					'enabled'          => true,
					'primary'          => false,
					'frontend_visible' => true, // This is the added line
					'is_resource'      => false, // Added for consistency
				];
			}
			update_option( Constants::OPTION_DIMENSION_REGISTRY, $registry );
		}

		set_transient( 'clisyc_admin_feedback', [ 'message' => __( 'Dimension type saved successfully.', 'client-sync' ) ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		wp_safe_redirect( admin_url( 'admin.php?page=clisyc-dimensions&tab=setup' ) );
		exit;
	}

	public function handle_delete_dimension_type() {
		$slug  = isset( $_GET['dimension_slug'] ) ? sanitize_key( wp_unslash( $_GET['dimension_slug'] ) ) : '';
		$nonce = isset( $_GET['clisyc_delete_dimension_nonce'] ) ? sanitize_key( wp_unslash( $_GET['clisyc_delete_dimension_nonce'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_delete_dimension_' . $slug ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		Dimension_CPT_Manager::delete_custom_dimension( $slug );

		wp_safe_redirect( admin_url( 'admin.php?page=clisyc-dimensions&tab=setup' ) );
		exit;
	}

	// =====================================================================
	// Private Helpers
	// =====================================================================

	/**
	 * Checks if a user already has an appointment for a specific service on a given date.
	 *
	 * @param int   $user_id    The WordPress User ID.
	 * @param int   $service_id The service dimension ID.
	 * @param array $date_query The date query array for the check range.
	 * @return bool True if an appointment exists, false otherwise.
	 */
	private function has_user_appointment_for_service( $user_id, $service_id, $date_query ) {
		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => [ 'publish', 'confirmed', 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ],
			'author'         => $user_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'date_query'     => [ $date_query ],
			// THE FIX: Add the ignore comment with a performance justification.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- This query is limited by user_id and a specific date range, ensuring performance even with a meta_query.
			'meta_query'     => [
				[
					'key'     => Constants::META_SLOT_DIMENSIONS,
					'value'   => '"clisyc_service";i:' . $service_id . ';',
					'compare' => 'LIKE',
				],
			],
		];

		$appointments = get_posts( $args );
		return ! empty( $appointments );
	}
}
