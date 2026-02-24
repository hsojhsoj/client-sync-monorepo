<?php
/**
 * File: src/shared/includes/admin/views/view-audit-logs-page.php
 * Admin page for viewing HIPAA audit logs.
 *
 * This page displays a searchable, filterable list of all audit log entries
 * for HIPAA compliance review and incident investigation.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Check permissions
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'client-sync' ) );
}

// Get the audit logger instance
if ( ! class_exists( '\DependentMedia\ClientSync\Services\Audit_Logger' ) ) {
    echo '<div class="notice notice-error"><p>' . esc_html__( 'Audit Logger service is not available.', 'client-sync' ) . '</p></div>';
    return;
}

$audit_logger = \DependentMedia\ClientSync\Services\Audit_Logger::get_instance();

// Log that this page is being viewed (meta-logging)
do_action( 'clisyc_audit_log_viewed', get_current_user_id() );

// Get filter parameters
$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 50;
$action_filter = isset( $_GET['action_filter'] ) ? sanitize_key( $_GET['action_filter'] ) : '';
$object_type_filter = isset( $_GET['object_type'] ) ? sanitize_key( $_GET['object_type'] ) : '';
$user_filter = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
$date_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : '';
$search    = isset( $_GET['s'] )          ? sanitize_text_field( wp_unslash( $_GET['s'] ) )          : '';

// Build query args
$query_args = [
    'page'        => $current_page,
    'per_page'    => $per_page,
    'action'      => $action_filter,
    'object_type' => $object_type_filter,
    'user_id'     => $user_filter,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
    'search'      => $search,
];

// Get logs
$result = $audit_logger->get_logs( $query_args );
$logs = $result['logs'];
$total = $result['total'];
$total_pages = $result['pages'];

// Get statistics
$stats = $audit_logger->get_statistics();

// Get users for filter dropdown
$users_with_logs = [];
global $wpdb;
$table_name = $wpdb->prefix . \DependentMedia\ClientSync\Services\Audit_Logger::TABLE_NAME;
if ( $audit_logger->table_exists() ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $user_ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table_name} WHERE user_id > 0 ORDER BY user_id" );
    foreach ( $user_ids as $uid ) {
        $user = get_userdata( $uid );
        if ( $user ) {
            $users_with_logs[ $uid ] = $user->display_name . ' (' . $user->user_login . ')';
        }
    }
}

// Build pagination URL
$base_url = admin_url( 'admin.php?page=clisyc-audit-logs' );
$filter_params = array_filter( [
    'action_filter' => $action_filter,
    'object_type'   => $object_type_filter,
    'user_id'       => $user_filter,
    'date_from'     => $date_from,
    'date_to'       => $date_to,
    's'             => $search,
    'per_page'      => $per_page !== 50 ? $per_page : '',
] );
?>

<div class="wrap clisyc-audit-logs-page">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-shield" style="font-size: 28px; margin-right: 10px; color: #0073aa;"></span>
        <?php esc_html_e( 'HIPAA Audit Logs', 'client-sync' ); ?>
    </h1>
    
    <p class="description">
        <?php esc_html_e( 'This log tracks all access to and modifications of Protected Health Information (PHI) as required by HIPAA regulations.', 'client-sync' ); ?>
    </p>

    <!-- Statistics Summary -->
    <div class="clisyc-audit-stats" style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
        <div class="clisyc-stat-box" style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #0073aa; padding: 15px 20px; min-width: 150px;">
            <div class="stat-value" style="font-size: 24px; font-weight: 600; color: #1d2327;"><?php echo esc_html( number_format( $stats['total_entries'] ) ); ?></div>
            <div class="stat-label" style="color: #646970; font-size: 13px;"><?php esc_html_e( 'Total Entries', 'client-sync' ); ?></div>
        </div>
        <div class="clisyc-stat-box" style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #00a32a; padding: 15px 20px; min-width: 150px;">
            <div class="stat-value" style="font-size: 24px; font-weight: 600; color: #1d2327;"><?php echo esc_html( number_format( $stats['entries_today'] ) ); ?></div>
            <div class="stat-label" style="color: #646970; font-size: 13px;"><?php esc_html_e( 'Today', 'client-sync' ); ?></div>
        </div>
        <div class="clisyc-stat-box" style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #dba617; padding: 15px 20px; min-width: 150px;">
            <div class="stat-value" style="font-size: 24px; font-weight: 600; color: #1d2327;"><?php echo esc_html( number_format( $stats['entries_week'] ) ); ?></div>
            <div class="stat-label" style="color: #646970; font-size: 13px;"><?php esc_html_e( 'This Week', 'client-sync' ); ?></div>
        </div>
        <div class="clisyc-stat-box" style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #826eb4; padding: 15px 20px; min-width: 150px;">
            <div class="stat-value" style="font-size: 24px; font-weight: 600; color: #1d2327;"><?php echo esc_html( number_format( $stats['unique_users'] ) ); ?></div>
            <div class="stat-label" style="color: #646970; font-size: 13px;"><?php esc_html_e( 'Unique Users', 'client-sync' ); ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="clisyc-audit-filters" style="background: #fff; border: 1px solid #c3c4c7; padding: 15px; margin-bottom: 20px;">
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="clisyc-audit-logs">
            
            <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                <!-- Search -->
                <div>
                    <label for="audit-search" class="screen-reader-text"><?php esc_html_e( 'Search', 'client-sync' ); ?></label>
                    <input type="search" id="audit-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search logs...', 'client-sync' ); ?>" style="min-width: 200px;">
                </div>

                <!-- Action Filter -->
                <div>
                    <label for="action-filter" style="display: block; font-size: 12px; margin-bottom: 3px;"><?php esc_html_e( 'Action', 'client-sync' ); ?></label>
                    <select id="action-filter" name="action_filter">
                        <option value=""><?php esc_html_e( 'All Actions', 'client-sync' ); ?></option>
                        <option value="view" <?php selected( $action_filter, 'view' ); ?>><?php esc_html_e( 'View', 'client-sync' ); ?></option>
                        <option value="create" <?php selected( $action_filter, 'create' ); ?>><?php esc_html_e( 'Create', 'client-sync' ); ?></option>
                        <option value="edit" <?php selected( $action_filter, 'edit' ); ?>><?php esc_html_e( 'Edit', 'client-sync' ); ?></option>
                        <option value="delete" <?php selected( $action_filter, 'delete' ); ?>><?php esc_html_e( 'Delete', 'client-sync' ); ?></option>
                        <option value="export" <?php selected( $action_filter, 'export' ); ?>><?php esc_html_e( 'Export', 'client-sync' ); ?></option>
                        <option value="login" <?php selected( $action_filter, 'login' ); ?>><?php esc_html_e( 'Login', 'client-sync' ); ?></option>
                        <option value="logout" <?php selected( $action_filter, 'logout' ); ?>><?php esc_html_e( 'Logout', 'client-sync' ); ?></option>
                    </select>
                </div>

                <!-- Object Type Filter -->
                <div>
                    <label for="object-type-filter" style="display: block; font-size: 12px; margin-bottom: 3px;"><?php esc_html_e( 'Object Type', 'client-sync' ); ?></label>
                    <select id="object-type-filter" name="object_type">
                        <option value=""><?php esc_html_e( 'All Types', 'client-sync' ); ?></option>
                        <option value="appointment" <?php selected( $object_type_filter, 'appointment' ); ?>><?php esc_html_e( 'Appointment', 'client-sync' ); ?></option>
                        <option value="client" <?php selected( $object_type_filter, 'client' ); ?>><?php esc_html_e( 'Client', 'client-sync' ); ?></option>
                        <option value="settings" <?php selected( $object_type_filter, 'settings' ); ?>><?php esc_html_e( 'Settings', 'client-sync' ); ?></option>
                        <option value="report" <?php selected( $object_type_filter, 'report' ); ?>><?php esc_html_e( 'Report', 'client-sync' ); ?></option>
                        <option value="audit_log" <?php selected( $object_type_filter, 'audit_log' ); ?>><?php esc_html_e( 'Audit Log', 'client-sync' ); ?></option>
                    </select>
                </div>

                <!-- User Filter -->
                <div>
                    <label for="user-filter" style="display: block; font-size: 12px; margin-bottom: 3px;"><?php esc_html_e( 'User', 'client-sync' ); ?></label>
                    <select id="user-filter" name="user_id">
                        <option value=""><?php esc_html_e( 'All Users', 'client-sync' ); ?></option>
                        <?php foreach ( $users_with_logs as $uid => $display_name ) : ?>
                            <option value="<?php echo esc_attr( $uid ); ?>" <?php selected( $user_filter, $uid ); ?>>
                                <?php echo esc_html( $display_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date From -->
                <div>
                    <label for="date-from" style="display: block; font-size: 12px; margin-bottom: 3px;"><?php esc_html_e( 'From Date', 'client-sync' ); ?></label>
                    <input type="date" id="date-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
                </div>

                <!-- Date To -->
                <div>
                    <label for="date-to" style="display: block; font-size: 12px; margin-bottom: 3px;"><?php esc_html_e( 'To Date', 'client-sync' ); ?></label>
                    <input type="date" id="date-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
                </div>

                <!-- Per Page -->
                <div>
                    <label for="per-page" style="display: block; font-size: 12px; margin-bottom: 3px;"><?php esc_html_e( 'Per Page', 'client-sync' ); ?></label>
                    <select id="per-page" name="per_page">
                        <option value="25" <?php selected( $per_page, 25 ); ?>>25</option>
                        <option value="50" <?php selected( $per_page, 50 ); ?>>50</option>
                        <option value="100" <?php selected( $per_page, 100 ); ?>>100</option>
                        <option value="250" <?php selected( $per_page, 250 ); ?>>250</option>
                    </select>
                </div>

                <!-- Submit -->
                <div>
                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'client-sync' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-audit-logs' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'client-sync' ); ?></a>
                </div>
            </div>
        </form>
    </div>

    <!-- Results Count -->
    <div class="tablenav top">
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php
                printf(
                    /* translators: %s: Number of items */
                    esc_html( _n( '%s item', '%s items', $total, 'client-sync' ) ),
                    esc_html( number_format( $total ) )
                );
                ?>
            </span>
            
            <?php if ( $total_pages > 1 ) : ?>
                <span class="pagination-links">
                    <?php
                    // First page
                    if ( $current_page > 1 ) {
                        $first_url = add_query_arg( array_merge( $filter_params, [ 'paged' => 1 ] ), $base_url );
                        echo '<a class="first-page button" href="' . esc_url( $first_url ) . '"><span class="screen-reader-text">' . esc_html__( 'First page', 'client-sync' ) . '</span><span aria-hidden="true">&laquo;</span></a> ';
                        
                        $prev_url = add_query_arg( array_merge( $filter_params, [ 'paged' => $current_page - 1 ] ), $base_url );
                        echo '<a class="prev-page button" href="' . esc_url( $prev_url ) . '"><span class="screen-reader-text">' . esc_html__( 'Previous page', 'client-sync' ) . '</span><span aria-hidden="true">&lsaquo;</span></a> ';
                    } else {
                        echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span> ';
                        echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span> ';
                    }

                    // Page X of Y
                    echo '<span class="paging-input">';
                    printf(
                        /* translators: 1: Current page, 2: Total pages */
                        esc_html_x( '%1$s of %2$s', 'paging', 'client-sync' ),
                        '<span class="tablenav-paging-text">' . esc_html( $current_page ) . '</span>',
                        '<span class="total-pages">' . esc_html( $total_pages ) . '</span>'
                    );
                    echo '</span> ';

                    // Next/Last page
                    if ( $current_page < $total_pages ) {
                        $next_url = add_query_arg( array_merge( $filter_params, [ 'paged' => $current_page + 1 ] ), $base_url );
                        echo '<a class="next-page button" href="' . esc_url( $next_url ) . '"><span class="screen-reader-text">' . esc_html__( 'Next page', 'client-sync' ) . '</span><span aria-hidden="true">&rsaquo;</span></a> ';
                        
                        $last_url = add_query_arg( array_merge( $filter_params, [ 'paged' => $total_pages ] ), $base_url );
                        echo '<a class="last-page button" href="' . esc_url( $last_url ) . '"><span class="screen-reader-text">' . esc_html__( 'Last page', 'client-sync' ) . '</span><span aria-hidden="true">&raquo;</span></a>';
                    } else {
                        echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span> ';
                        echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
                    }
                    ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Audit Log Table -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" style="width: 150px;"><?php esc_html_e( 'Date/Time (UTC)', 'client-sync' ); ?></th>
                <th scope="col" style="width: 150px;"><?php esc_html_e( 'User', 'client-sync' ); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e( 'Action', 'client-sync' ); ?></th>
                <th scope="col" style="width: 100px;"><?php esc_html_e( 'Object', 'client-sync' ); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e( 'Object ID', 'client-sync' ); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e( 'IP Address', 'client-sync' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Details', 'client-sync' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $logs ) ) : ?>
                <tr>
                    <td colspan="7">
                        <p style="text-align: center; padding: 20px; color: #646970;">
                            <?php esc_html_e( 'No audit log entries found.', 'client-sync' ); ?>
                        </p>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $logs as $log ) : ?>
                    <?php
                    $meta_data = ! empty( $log['meta_data'] ) ? json_decode( $log['meta_data'], true ) : [];
                    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $meta_data ) ) {
                        $meta_data = [];
                    }
                    $action_class = 'action-' . esc_attr( $log['action'] );
                    
                    // Action badge colors
                    $action_colors = [
                        'view'   => '#0073aa',
                        'create' => '#00a32a',
                        'edit'   => '#dba617',
                        'delete' => '#d63638',
                        'export' => '#826eb4',
                        'login'  => '#2271b1',
                        'logout' => '#646970',
                    ];
                    $action_color = $action_colors[ $log['action'] ] ?? '#646970';
                    ?>
                    <tr class="<?php echo esc_attr( $action_class ); ?>">
                        <td>
                            <span title="<?php echo esc_attr( $log['created_at'] ); ?>">
                                <?php
                                $dt = new DateTime( $log['created_at'], new DateTimeZone( 'UTC' ) );
                                echo esc_html( $dt->format( 'Y-m-d H:i:s' ) );
                                ?>
                            </span>
                        </td>
                        <td>
                            <?php if ( $log['user_id'] > 0 ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $log['user_id'] ) ); ?>">
                                    <?php echo esc_html( $log['username'] ); ?>
                                </a>
                                <br><small style="color: #646970;">ID: <?php echo esc_html( $log['user_id'] ); ?></small>
                            <?php else : ?>
                                <span style="color: #646970;"><?php esc_html_e( 'Anonymous', 'client-sync' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="clisyc-action-badge" style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; color: #fff; background: <?php echo esc_attr( $action_color ); ?>;">
                                <?php echo esc_html( \DependentMedia\ClientSync\Services\Audit_Logger::get_action_label( $log['action'] ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo esc_html( \DependentMedia\ClientSync\Services\Audit_Logger::get_object_type_label( $log['object_type'] ) ); ?>
                        </td>
                        <td>
                            <?php if ( $log['object_id'] > 0 ) : ?>
                                <?php if ( $log['object_type'] === 'appointment' ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $log['object_id'] . '&action=edit' ) ); ?>">
                                        #<?php echo esc_html( $log['object_id'] ); ?>
                                    </a>
                                <?php elseif ( $log['object_type'] === 'client' ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $log['object_id'] ) ); ?>">
                                        #<?php echo esc_html( $log['object_id'] ); ?>
                                    </a>
                                <?php else : ?>
                                    #<?php echo esc_html( $log['object_id'] ); ?>
                                <?php endif; ?>
                            <?php else : ?>
                                <span style="color: #646970;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code style="font-size: 11px;"><?php echo esc_html( $log['ip_address'] ); ?></code>
                        </td>
                        <td>
                            <?php if ( ! empty( $meta_data ) ) : ?>
                                <details>
                                    <summary style="cursor: pointer; color: #0073aa;"><?php esc_html_e( 'View Details', 'client-sync' ); ?></summary>
                                    <div style="margin-top: 10px; padding: 10px; background: #f6f7f7; border-radius: 3px; font-size: 12px;">
                                        <?php foreach ( $meta_data as $key => $value ) : ?>
                                            <div style="margin-bottom: 5px;">
                                                <strong><?php echo esc_html( $key ); ?>:</strong>
                                                <?php
                                                if ( is_array( $value ) ) {
                                                    echo '<pre style="margin: 5px 0; font-size: 11px;">' . esc_html( wp_json_encode( $value, JSON_PRETTY_PRINT ) ) . '</pre>';
                                                } else {
                                                    echo esc_html( $value );
                                                }
                                                ?>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <?php if ( ! empty( $log['request_uri'] ) ) : ?>
                                            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
                                                <strong><?php esc_html_e( 'Request URI:', 'client-sync' ); ?></strong>
                                                <code style="font-size: 10px; word-break: break-all;"><?php echo esc_html( $log['request_uri'] ); ?></code>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php elseif ( ! empty( $log['request_uri'] ) ) : ?>
                                <code style="font-size: 10px; color: #646970;"><?php echo esc_html( substr( $log['request_uri'], 0, 50 ) ); ?>...</code>
                            <?php else : ?>
                                <span style="color: #646970;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Bottom Pagination -->
    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                    printf(
                        /* translators: %s: Number of items */
                        esc_html( _n( '%s item', '%s items', $total, 'client-sync' ) ),
                        esc_html( number_format( $total ) )
                    );
                    ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Export Options -->
    <div class="clisyc-audit-export" style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #c3c4c7;">
        <h3 style="margin-top: 0;"><?php esc_html_e( 'Export Audit Logs', 'client-sync' ); ?></h3>
        <p class="description"><?php esc_html_e( 'Export audit logs for compliance reporting or external analysis.', 'client-sync' ); ?></p>
        
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: flex; gap: 10px; align-items: center; margin-top: 10px;">
            <?php wp_nonce_field( 'clisyc_export_audit_logs', 'clisyc_audit_export_nonce' ); ?>
            <input type="hidden" name="action" value="clisyc_export_audit_logs">
            
            <!-- Pass current filters to export -->
            <input type="hidden" name="action_filter" value="<?php echo esc_attr( $action_filter ); ?>">
            <input type="hidden" name="object_type" value="<?php echo esc_attr( $object_type_filter ); ?>">
            <input type="hidden" name="user_id" value="<?php echo esc_attr( $user_filter ); ?>">
            <input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
            <input type="hidden" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
            <input type="hidden" name="search" value="<?php echo esc_attr( $search ); ?>">
            
            <select name="export_format">
                <option value="csv"><?php esc_html_e( 'CSV (Spreadsheet)', 'client-sync' ); ?></option>
                <option value="json"><?php esc_html_e( 'JSON (Data)', 'client-sync' ); ?></option>
            </select>
            
            <button type="submit" class="button button-secondary">
                <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                <?php esc_html_e( 'Export Filtered Logs', 'client-sync' ); ?>
            </button>
        </form>
    </div>

    <!-- Retention Info -->
    <div class="clisyc-audit-info" style="margin-top: 20px; padding: 15px; background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #72aee6;">
        <h4 style="margin-top: 0;">
            <span class="dashicons dashicons-info" style="color: #72aee6;"></span>
            <?php esc_html_e( 'About HIPAA Audit Logs', 'client-sync' ); ?>
        </h4>
        <ul style="margin-bottom: 0;">
            <li>
                <?php
                $retention_days = (int) get_option( 'clisyc_audit_log_retention_days', 2555 );
                $retention_years = round( $retention_days / 365, 1 );
                printf(
                    /* translators: %s: Number of years */
                    esc_html__( 'Logs are retained for %s years per HIPAA requirements.', 'client-sync' ),
                    esc_html( $retention_years )
                );
                ?>
            </li>
            <li><?php esc_html_e( 'Audit logs are immutable and cannot be edited or deleted manually.', 'client-sync' ); ?></li>
            <li><?php esc_html_e( 'All times are displayed in UTC for consistency.', 'client-sync' ); ?></li>
            <?php if ( ! empty( $stats['oldest_entry'] ) ) : ?>
                <li>
                    <?php
                    printf(
                        /* translators: %s: Date of oldest entry */
                        esc_html__( 'Oldest log entry: %s', 'client-sync' ),
                        esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $stats['oldest_entry'] ) ) )
                    );
                    ?>
                </li>
            <?php endif; ?>
        </ul>
    </div>

</div>

<style>
.clisyc-audit-logs-page .wp-list-table td {
    vertical-align: top;
    padding: 10px 8px;
}
.clisyc-audit-logs-page .wp-list-table th {
    font-weight: 600;
}
.clisyc-audit-logs-page details summary {
    outline: none;
}
.clisyc-audit-logs-page details summary::-webkit-details-marker {
    display: none;
}
.clisyc-audit-logs-page details[open] summary {
    margin-bottom: 5px;
}
@media screen and (max-width: 782px) {
    .clisyc-audit-stats {
        flex-direction: column;
    }
    .clisyc-stat-box {
        min-width: auto !important;
        width: 100%;
    }
}
</style>