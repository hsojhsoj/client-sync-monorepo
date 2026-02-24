<?php
/**
 * File: src/shared/includes/services/class-audit-logger.php
 * HIPAA Compliance Audit Logger Service.
 *
 * Records all access, modifications, and exports of Protected Health Information (PHI)
 * as required by HIPAA regulations. Logs are immutable and retained for the
 * configured retention period (default: 7 years per HIPAA requirements).
 *
 * @package    ClientSync
 * @subpackage ClientSync/Services
 */

namespace DependentMedia\ClientSync\Services;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Audit_Logger
 *
 * Provides comprehensive audit logging for HIPAA compliance.
 * All PHI access, modifications, and exports are recorded.
 */
class Audit_Logger {

    /**
     * Singleton instance.
     *
     * @var Audit_Logger|null
     */
    private static $instance = null;

    /**
     * Database table name (without prefix).
     */
    const TABLE_NAME = 'clisyc_audit_logs';

    /**
     * Action type constants.
     */
    const ACTION_VIEW     = 'view';
    const ACTION_CREATE   = 'create';
    const ACTION_EDIT     = 'edit';
    const ACTION_DELETE   = 'delete';
    const ACTION_EXPORT   = 'export';
    const ACTION_LOGIN    = 'login';
    const ACTION_LOGOUT   = 'logout';
    const ACTION_DECRYPT  = 'decrypt';
    const ACTION_PRINT    = 'print';

    /**
     * Object type constants.
     */
    const OBJECT_APPOINTMENT  = 'appointment';
    const OBJECT_CLIENT       = 'client';
    const OBJECT_SETTINGS     = 'settings';
    const OBJECT_REPORT       = 'report';
    const OBJECT_AUDIT_LOG    = 'audit_log';

    /**
     * Get singleton instance.
     *
     * @return Audit_Logger
     */
    public static function get_instance(): Audit_Logger {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register WordPress hooks for automatic audit logging.
     */
    public function register_hooks() {
        // Only register hooks if HIPAA mode is active or audit logging is explicitly enabled
        if ( ! $this->is_audit_logging_enabled() ) {
            return;
        }

        // =========================================================================
        // APPOINTMENT HOOKS
        // =========================================================================
        
        // Log appointment views (frontend detail page)
        add_action( 'clisyc_before_appointment_detail_render', [ $this, 'log_appointment_view' ], 10, 1 );
        
        // Log appointment edits (admin save)
        add_action( 'save_post_clisyc_appointment', [ $this, 'log_appointment_save' ], 20, 3 );
        
        // Log appointment deletion
        add_action( 'before_delete_post', [ $this, 'log_appointment_delete' ], 10, 2 );
        add_action( 'wp_trash_post', [ $this, 'log_appointment_trash' ], 10, 1 );
        
        // Log frontend manager edits
        add_action( 'clisyc_after_manager_edit_appointment', [ $this, 'log_manager_edit' ], 10, 2 );

        // =========================================================================
        // CLIENT/USER HOOKS
        // =========================================================================
        
        // Log client profile views
        add_action( 'edit_user_profile', [ $this, 'log_client_profile_view' ], 10, 1 );
        add_action( 'show_user_profile', [ $this, 'log_own_profile_view' ], 10, 1 );
        
        // Log client data edits
        add_action( 'profile_update', [ $this, 'log_client_profile_edit' ], 10, 2 );

        // =========================================================================
        // EXPORT HOOKS
        // =========================================================================
        
        // Log CSV exports
        add_action( 'clisyc_before_csv_export', [ $this, 'log_csv_export' ], 10, 2 );
        
        // Log PDF exports
        add_action( 'clisyc_before_pdf_export', [ $this, 'log_pdf_export' ], 10, 2 );
        
        // Log report generation
        add_action( 'clisyc_before_report_generation', [ $this, 'log_report_generation' ], 10, 2 );

        // =========================================================================
        // SETTINGS HOOKS
        // =========================================================================
        
        // Log HIPAA settings changes
        add_action( 'update_option_' . Constants::OPTION_HIPAA_MODE, [ $this, 'log_hipaa_setting_change' ], 10, 2 );
        add_action( 'update_option_clisyc_anonymize_external_sync', [ $this, 'log_anonymize_setting_change' ], 10, 2 );

        // =========================================================================
        // AUTHENTICATION HOOKS
        // =========================================================================
        
        // Log logins (for users who can access PHI)
        add_action( 'wp_login', [ $this, 'log_user_login' ], 10, 2 );
        add_action( 'wp_logout', [ $this, 'log_user_logout' ], 10, 1 );

        // =========================================================================
        // AUDIT LOG ACCESS HOOKS
        // =========================================================================
        
        // Log when someone views the audit log itself
        add_action( 'clisyc_audit_log_viewed', [ $this, 'log_audit_log_access' ], 10, 1 );

        // =========================================================================
        // CLEANUP HOOKS
        // =========================================================================

        // Register the cron handler. Scheduling is handled by Plugin::schedule_audit_log_cleanup()
        // on activation/upgrade to avoid wp_next_scheduled() overhead on every request.
        add_action( 'clisyc_audit_log_cleanup', [ $this, 'cleanup_old_logs' ] );
    }

    // =========================================================================
    // CORE LOGGING METHOD
    // =========================================================================

    /**
     * Log an audit event.
     *
     * This is the main method for recording audit events. It captures:
     * - Who performed the action (user ID, username)
     * - What action was performed
     * - What object was affected
     * - When it happened
     * - Where it happened (IP address, user agent)
     * - Additional context metadata
     *
     * @param string $action      The action performed (use class constants).
     * @param string $object_type The type of object affected (use class constants).
     * @param int    $object_id   The ID of the affected object.
     * @param array  $meta        Optional additional metadata.
     * @return int|false The log entry ID or false on failure.
     */
    public static function log( string $action, string $object_type, int $object_id, array $meta = [] ) {
        global $wpdb;

        // Get the singleton instance for non-static context
        $instance = self::get_instance();

        // Skip logging if audit logging is disabled
        if ( ! $instance->is_audit_logging_enabled() ) {
            return false;
        }

        // Get current user info
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID ?? 0;
        $username = $current_user->user_login ?? 'anonymous';

        // Get request context
        $ip_address = $instance->get_client_ip();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) 
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) 
            : '';

        // Build the log entry
        $log_data = [
            'user_id'       => $user_id,
            'username'      => $username,
            'action'        => sanitize_key( $action ),
            'object_type'   => sanitize_key( $object_type ),
            'object_id'     => absint( $object_id ),
            'ip_address'    => $ip_address,
            'user_agent'    => substr( $user_agent, 0, 500 ), // Limit length
            'request_uri'   => isset( $_SERVER['REQUEST_URI'] ) 
                ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) 
                : '',
            'meta_data'     => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
            'created_at'    => current_time( 'mysql', true ), // UTC time
        ];

        // Insert into database
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // Verify table exists
        if ( ! $instance->table_exists() ) {
            // Attempt to create the table
            $instance->create_table();
            
            // If still doesn't exist, log to error log and return
            if ( ! $instance->table_exists() ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( '[CLISYC Audit] Failed to create audit log table.' );
                }
                return false;
            }
        }

        $result = $wpdb->insert(
            $table_name,
            $log_data,
            [ '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $result === false ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[CLISYC Audit] Failed to insert log entry: ' . $wpdb->last_error );
            }
            return false;
        }

        $log_id = $wpdb->insert_id;

        /**
         * Fires after an audit log entry is created.
         *
         * @param int    $log_id     The ID of the new log entry.
         * @param string $action     The action that was logged.
         * @param string $object_type The type of object affected.
         * @param int    $object_id  The ID of the affected object.
         * @param array  $meta       Additional metadata.
         */
        do_action( 'clisyc_audit_log_created', $log_id, $action, $object_type, $object_id, $meta );

        return $log_id;
    }

    // =========================================================================
    // HOOK CALLBACKS
    // =========================================================================

    /**
     * Log appointment view from frontend detail page.
     *
     * @param int $appointment_id The appointment ID.
     */
    public function log_appointment_view( int $appointment_id ) {
        // Get additional context
        $appointment = get_post( $appointment_id );
        $client_id = $appointment ? $appointment->post_author : 0;

        self::log(
            self::ACTION_VIEW,
            self::OBJECT_APPOINTMENT,
            $appointment_id,
            [
                'client_id' => $client_id,
                'context'   => 'frontend_detail_page',
            ]
        );
    }

    /**
     * Log appointment save from admin.
     *
     * @param int      $post_id The post ID.
     * @param \WP_Post $post    The post object.
     * @param bool     $update  Whether this is an update.
     */
    public function log_appointment_save( int $post_id, \WP_Post $post, bool $update ) {
        // Skip autosaves and revisions
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        $action = $update ? self::ACTION_EDIT : self::ACTION_CREATE;
        $client_id = $post->post_author;

        self::log(
            $action,
            self::OBJECT_APPOINTMENT,
            $post_id,
            [
                'client_id'   => $client_id,
                'post_status' => $post->post_status,
                'context'     => 'admin_save',
            ]
        );
    }

    /**
     * Log appointment permanent deletion.
     *
     * @param int      $post_id The post ID.
     * @param \WP_Post $post    The post object.
     */
    public function log_appointment_delete( int $post_id, \WP_Post $post ) {
        if ( Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
            return;
        }

        self::log(
            self::ACTION_DELETE,
            self::OBJECT_APPOINTMENT,
            $post_id,
            [
                'client_id'    => $post->post_author,
                'context'      => 'permanent_delete',
                'deleted_date' => $post->post_date,
            ]
        );
    }

    /**
     * Log appointment trash (soft delete).
     *
     * @param int $post_id The post ID.
     */
    public function log_appointment_trash( int $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
            return;
        }

        self::log(
            self::ACTION_DELETE,
            self::OBJECT_APPOINTMENT,
            $post_id,
            [
                'client_id' => $post->post_author,
                'context'   => 'trash',
            ]
        );
    }

    /**
     * Log frontend manager edit.
     *
     * @param int   $appointment_id The appointment ID.
     * @param array $updated_fields The fields that were updated.
     */
    public function log_manager_edit( int $appointment_id, array $updated_fields = [] ) {
        $appointment = get_post( $appointment_id );
        $client_id = $appointment ? $appointment->post_author : 0;

        self::log(
            self::ACTION_EDIT,
            self::OBJECT_APPOINTMENT,
            $appointment_id,
            [
                'client_id'      => $client_id,
                'context'        => 'manager_dashboard',
                'updated_fields' => array_keys( $updated_fields ),
            ]
        );
    }

    /**
     * Log client profile view (by admin).
     *
     * @param \WP_User $user The user being viewed.
     */
    public function log_client_profile_view( \WP_User $user ) {
        // Only log if viewing another user's profile
        if ( get_current_user_id() === $user->ID ) {
            return;
        }

        // Only log if the user being viewed has appointments
        if ( ! $this->user_has_appointments( $user->ID ) ) {
            return;
        }

        self::log(
            self::ACTION_VIEW,
            self::OBJECT_CLIENT,
            $user->ID,
            [
                'context' => 'admin_profile_view',
            ]
        );
    }

    /**
     * Log own profile view (when user has PHI).
     *
     * @param \WP_User $user The user.
     */
    public function log_own_profile_view( \WP_User $user ) {
        // Only log if user has appointments (contains PHI)
        if ( ! $this->user_has_appointments( $user->ID ) ) {
            return;
        }

        self::log(
            self::ACTION_VIEW,
            self::OBJECT_CLIENT,
            $user->ID,
            [
                'context' => 'own_profile_view',
            ]
        );
    }

    /**
     * Log client profile edit.
     *
     * @param int   $user_id       The user ID.
     * @param array $old_user_data The old user data.
     */
    public function log_client_profile_edit( int $user_id, array $old_user_data ) {
        // Only log if user has appointments
        if ( ! $this->user_has_appointments( $user_id ) ) {
            return;
        }

        self::log(
            self::ACTION_EDIT,
            self::OBJECT_CLIENT,
            $user_id,
            [
                'context' => 'profile_update',
            ]
        );
    }

    /**
     * Log CSV export.
     *
     * @param string $export_type The type of export.
     * @param array  $export_data Export parameters/filters.
     */
    public function log_csv_export( string $export_type, array $export_data = [] ) {
        self::log(
            self::ACTION_EXPORT,
            self::OBJECT_REPORT,
            0,
            [
                'export_type'   => $export_type,
                'export_format' => 'csv',
                'filters'       => $export_data,
            ]
        );
    }

    /**
     * Log PDF export.
     *
     * @param string $export_type The type of export.
     * @param array  $export_data Export parameters.
     */
    public function log_pdf_export( string $export_type, array $export_data = [] ) {
        self::log(
            self::ACTION_EXPORT,
            self::OBJECT_REPORT,
            0,
            [
                'export_type'   => $export_type,
                'export_format' => 'pdf',
                'filters'       => $export_data,
            ]
        );
    }

    /**
     * Log report generation.
     *
     * @param string $report_type The type of report.
     * @param array  $report_params Report parameters.
     */
    public function log_report_generation( string $report_type, array $report_params = [] ) {
        self::log(
            self::ACTION_VIEW,
            self::OBJECT_REPORT,
            0,
            [
                'report_type' => $report_type,
                'params'      => $report_params,
            ]
        );
    }

    /**
     * Log HIPAA mode setting change.
     *
     * @param mixed $old_value The old setting value.
     * @param mixed $new_value The new setting value.
     */
    public function log_hipaa_setting_change( $old_value, $new_value ) {
        self::log(
            self::ACTION_EDIT,
            self::OBJECT_SETTINGS,
            0,
            [
                'setting'   => 'hipaa_mode_enabled',
                'old_value' => $old_value,
                'new_value' => $new_value,
            ]
        );
    }

    /**
     * Log anonymization setting change.
     *
     * @param mixed $old_value The old setting value.
     * @param mixed $new_value The new setting value.
     */
    public function log_anonymize_setting_change( $old_value, $new_value ) {
        self::log(
            self::ACTION_EDIT,
            self::OBJECT_SETTINGS,
            0,
            [
                'setting'   => 'anonymize_external_sync',
                'old_value' => $old_value,
                'new_value' => $new_value,
            ]
        );
    }

    /**
     * Log user login.
     *
     * @param string   $user_login The username.
     * @param \WP_User $user       The user object.
     */
    public function log_user_login( string $user_login, \WP_User $user ) {
        // Only log if user can access PHI
        if ( ! $this->user_can_access_phi( $user ) ) {
            return;
        }

        self::log(
            self::ACTION_LOGIN,
            self::OBJECT_CLIENT,
            $user->ID,
            [
                'context' => 'user_login',
            ]
        );
    }

    /**
     * Log user logout.
     *
     * @param int $user_id The user ID.
     */
    public function log_user_logout( int $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user || ! $this->user_can_access_phi( $user ) ) {
            return;
        }

        self::log(
            self::ACTION_LOGOUT,
            self::OBJECT_CLIENT,
            $user_id,
            [
                'context' => 'user_logout',
            ]
        );
    }

    /**
     * Log audit log access (meta-logging).
     *
     * @param int $viewer_user_id The user viewing the logs.
     */
    public function log_audit_log_access( int $viewer_user_id ) {
        self::log(
            self::ACTION_VIEW,
            self::OBJECT_AUDIT_LOG,
            0,
            [
                'context' => 'audit_log_viewer',
            ]
        );
    }

    // =========================================================================
    // QUERY METHODS
    // =========================================================================

    /**
     * Get audit log entries with filtering and pagination.
     *
     * @param array $args Query arguments.
     * @return array Array with 'logs' and 'total' keys.
     */
    public function get_logs( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'per_page'    => 50,
            'page'        => 1,
            'action'      => '',
            'object_type' => '',
            'object_id'   => 0,
            'user_id'     => 0,
            'date_from'   => '',
            'date_to'     => '',
            'search'      => '',
            'orderby'     => 'created_at',
            'order'       => 'DESC',
        ];

        $args = wp_parse_args( $args, $defaults );
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Build WHERE clauses
        $where = [ '1=1' ];
        $values = [];

        if ( ! empty( $args['action'] ) ) {
            $where[] = 'action = %s';
            $values[] = $args['action'];
        }

        if ( ! empty( $args['object_type'] ) ) {
            $where[] = 'object_type = %s';
            $values[] = $args['object_type'];
        }

        if ( ! empty( $args['object_id'] ) ) {
            $where[] = 'object_id = %d';
            $values[] = absint( $args['object_id'] );
        }

        if ( ! empty( $args['user_id'] ) ) {
            $where[] = 'user_id = %d';
            $values[] = absint( $args['user_id'] );
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[] = 'created_at >= %s';
            $values[] = $args['date_from'] . ' 00:00:00';
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[] = 'created_at <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }

        if ( ! empty( $args['search'] ) ) {
            $search_like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(username LIKE %s OR ip_address LIKE %s OR meta_data LIKE %s)';
            $values[] = $search_like;
            $values[] = $search_like;
            $values[] = $search_like;
        }

        $where_sql = implode( ' AND ', $where );

        // Validate orderby
        $allowed_orderby = [ 'log_id', 'user_id', 'action', 'object_type', 'object_id', 'created_at' ];
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        // Calculate offset
        $per_page = absint( $args['per_page'] );
        $page = absint( $args['page'] );
        $offset = ( $page - 1 ) * $per_page;

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $count_sql = $wpdb->prepare( $count_sql, $values );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $count_sql );

        // Get logs
        $sql = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = $per_page;
        $values[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $logs = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

        return [
            'logs'       => $logs ?: [],
            'total'      => $total,
            'pages'      => (int) ceil( $total / $per_page ),
            'page'       => $page,
            'per_page'   => $per_page,
        ];
    }

    /**
     * Get logs for a specific object.
     *
     * @param string $object_type The object type.
     * @param int    $object_id   The object ID.
     * @param int    $limit       Maximum number of logs to return.
     * @return array The log entries.
     */
    public function get_logs_for_object( string $object_type, int $object_id, int $limit = 50 ): array {
        $result = $this->get_logs( [
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'per_page'    => $limit,
        ] );

        return $result['logs'];
    }

    /**
     * Get logs for a specific user.
     *
     * @param int $user_id The user ID.
     * @param int $limit   Maximum number of logs to return.
     * @return array The log entries.
     */
    public function get_logs_for_user( int $user_id, int $limit = 50 ): array {
        $result = $this->get_logs( [
            'user_id'  => $user_id,
            'per_page' => $limit,
        ] );

        return $result['logs'];
    }

    // =========================================================================
    // MAINTENANCE METHODS
    // =========================================================================

    /**
     * Clean up old log entries based on retention policy.
     *
     * HIPAA requires retention of audit logs for 6 years minimum.
     * Default is 7 years (2555 days) to provide a safety buffer.
     */
    public function cleanup_old_logs() {
        global $wpdb;

        $retention_days = (int) get_option( 'clisyc_audit_log_retention_days', 2555 );
        
        // Safety check: Never delete logs less than 6 years old (HIPAA minimum)
        $min_retention = 2190; // ~6 years
        if ( $retention_days < $min_retention ) {
            $retention_days = $min_retention;
        }

        $cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Log the cleanup action itself
        self::log(
            self::ACTION_DELETE,
            self::OBJECT_AUDIT_LOG,
            0,
            [
                'context'        => 'scheduled_cleanup',
                'cutoff_date'    => $cutoff_date,
                'retention_days' => $retention_days,
            ]
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE created_at < %s",
                $cutoff_date
            )
        );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $deleted > 0 ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( "[CLISYC Audit] Cleaned up {$deleted} old audit log entries." );
        }

        return $deleted;
    }

    /**
     * Get audit log statistics.
     *
     * @return array Statistics about the audit log.
     */
    public function get_statistics(): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        if ( ! $this->table_exists() ) {
            return [
                'total_entries'    => 0,
                'entries_today'    => 0,
                'entries_week'     => 0,
                'entries_month'    => 0,
                'unique_users'     => 0,
                'oldest_entry'     => null,
                'newest_entry'     => null,
                'by_action'        => [],
                'by_object_type'   => [],
            ];
        }

        $stats = [];

        // Total entries
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats['total_entries'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

        // Entries today
        $today = gmdate( 'Y-m-d' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats['entries_today'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE DATE(created_at) = %s", $today )
        );

        // Entries this week
        $week_ago = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats['entries_week'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s", $week_ago . ' 00:00:00' )
        );

        // Entries this month
        $month_ago = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats['entries_month'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s", $month_ago . ' 00:00:00' )
        );

        // Unique users
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats['unique_users'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$table_name}" );

        // Date range
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats['oldest_entry'] = $wpdb->get_var( "SELECT MIN(created_at) FROM {$table_name}" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats['newest_entry'] = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table_name}" );

        // By action
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $by_action = $wpdb->get_results( "SELECT action, COUNT(*) as count FROM {$table_name} GROUP BY action", ARRAY_A );
        $stats['by_action'] = wp_list_pluck( $by_action, 'count', 'action' );

        // By object type
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $by_type = $wpdb->get_results( "SELECT object_type, COUNT(*) as count FROM {$table_name} GROUP BY object_type", ARRAY_A );
        $stats['by_object_type'] = wp_list_pluck( $by_type, 'count', 'object_type' );

        return $stats;
    }

    // =========================================================================
    // DATABASE METHODS
    // =========================================================================

    /**
     * Create the audit log table.
     *
     * This should be called during plugin activation.
     */
    public function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            username VARCHAR(60) NOT NULL DEFAULT '',
            action VARCHAR(50) NOT NULL,
            object_type VARCHAR(50) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(500) NOT NULL DEFAULT '',
            request_uri VARCHAR(2000) NOT NULL DEFAULT '',
            meta_data LONGTEXT,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (log_id),
            KEY idx_user_id (user_id),
            KEY idx_action (action),
            KEY idx_object (object_type, object_id),
            KEY idx_created_at (created_at),
            KEY idx_ip_address (ip_address)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Check if the audit log table exists.
     *
     * @return bool True if table exists.
     */
    public function table_exists(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Attempt a lightweight query against the table. This works for both
        // regular and temporary tables (SHOW TABLES / information_schema do
        // not reliably surface temporary tables created by the WP test suite).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $suppressed = $wpdb->suppress_errors( true );
        $result     = $wpdb->query( "SELECT 1 FROM {$table_name} LIMIT 1" );
        $wpdb->suppress_errors( $suppressed );

        return false !== $result;
    }

    /**
     * Drop the audit log table.
     *
     * WARNING: This permanently deletes all audit logs!
     * Only use during complete plugin uninstallation.
     */
    public function drop_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Check if audit logging is enabled.
     *
     * Audit logging is enabled when:
     * - HIPAA mode is active, OR
     * - Audit logging is explicitly enabled via option
     *
     * @return bool True if audit logging should be performed.
     */
    public function is_audit_logging_enabled(): bool {
        // Check if HIPAA mode is active
        if ( function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) 
            && \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode() ) {
            return true;
        }

        // Check explicit option
        return (bool) get_option( 'clisyc_audit_logging_enabled', false );
    }

    /**
     * Get the client's IP address.
     *
     * Handles proxied requests and common header formats.
     *
     * @return string The client IP address.
     */
    private function get_client_ip(): string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below with FILTER_VALIDATE_IP.
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
        $remote_addr = filter_var( $remote_addr, FILTER_VALIDATE_IP ) ?: '0.0.0.0';

        /**
         * Filters the list of trusted proxy IP addresses.
         *
         * Only when REMOTE_ADDR matches a trusted proxy will forwarded headers
         * (X-Forwarded-For, CF-Connecting-IP, etc.) be consulted. This prevents
         * IP spoofing by untrusted clients setting proxy headers directly.
         *
         * @param string[] $trusted_proxies Array of trusted proxy IP addresses.
         */
        $trusted_proxies = apply_filters( 'clisyc_trusted_proxy_ips', [] );

        if ( empty( $trusted_proxies ) || ! in_array( $remote_addr, $trusted_proxies, true ) ) {
            return $remote_addr;
        }

        // Only trust forwarded headers when behind a known proxy.
        $proxy_headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
        ];

        foreach ( $proxy_headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

                // Handle comma-separated IPs (X-Forwarded-For can have multiple)
                if ( strpos( $ip, ',' ) !== false ) {
                    $ips = explode( ',', $ip );
                    $ip = trim( $ips[0] );
                }

                // Validate IP format
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return $remote_addr;
    }

    /**
     * Check if a user has any appointments (and thus has PHI).
     *
     * @param int $user_id The user ID.
     * @return bool True if user has appointments.
     */
    private function user_has_appointments( int $user_id ): bool {
        $count = count_user_posts( $user_id, Constants::POST_TYPE_APPOINTMENT, true );
        return $count > 0;
    }

    /**
     * Check if a user can access PHI.
     *
     * @param \WP_User $user The user object.
     * @return bool True if user can access PHI.
     */
    private function user_can_access_phi( \WP_User $user ): bool {
        // Admins and editors can access PHI
        if ( $user->has_cap( 'edit_others_posts' ) ) {
            return true;
        }

        // Users with their own appointments can access their own PHI
        if ( $this->user_has_appointments( $user->ID ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get human-readable action label.
     *
     * @param string $action The action key.
     * @return string The human-readable label.
     */
    public static function get_action_label( string $action ): string {
        $labels = [
            self::ACTION_VIEW    => __( 'View', 'client-sync' ),
            self::ACTION_CREATE  => __( 'Create', 'client-sync' ),
            self::ACTION_EDIT    => __( 'Edit', 'client-sync' ),
            self::ACTION_DELETE  => __( 'Delete', 'client-sync' ),
            self::ACTION_EXPORT  => __( 'Export', 'client-sync' ),
            self::ACTION_LOGIN   => __( 'Login', 'client-sync' ),
            self::ACTION_LOGOUT  => __( 'Logout', 'client-sync' ),
            self::ACTION_DECRYPT => __( 'Decrypt', 'client-sync' ),
            self::ACTION_PRINT   => __( 'Print', 'client-sync' ),
        ];

        return $labels[ $action ] ?? ucfirst( $action );
    }

    /**
     * Get human-readable object type label.
     *
     * @param string $object_type The object type key.
     * @return string The human-readable label.
     */
    public static function get_object_type_label( string $object_type ): string {
        $labels = [
            self::OBJECT_APPOINTMENT => __( 'Appointment', 'client-sync' ),
            self::OBJECT_CLIENT      => __( 'Client', 'client-sync' ),
            self::OBJECT_SETTINGS    => __( 'Settings', 'client-sync' ),
            self::OBJECT_REPORT      => __( 'Report', 'client-sync' ),
            self::OBJECT_AUDIT_LOG   => __( 'Audit Log', 'client-sync' ),
        ];

        return $labels[ $object_type ] ?? ucfirst( $object_type );
    }
}

// =========================================================================
// GLOBAL HELPER FUNCTIONS
// =========================================================================

/**
 * Log an audit event (convenience function).
 *
 * @param string $action      The action performed.
 * @param string $object_type The type of object affected.
 * @param int    $object_id   The ID of the affected object.
 * @param array  $meta        Optional additional metadata.
 * @return int|false The log entry ID or false on failure.
 */
function clisyc_audit_log( string $action, string $object_type, int $object_id, array $meta = [] ) {
    return Audit_Logger::log( $action, $object_type, $object_id, $meta );
}

/**
 * Get the Audit Logger instance.
 *
 * @return Audit_Logger
 */
function clisyc_audit_logger(): Audit_Logger {
    return Audit_Logger::get_instance();
}