<?php
/**
 * File: src/shared/includes/admin/views/view-reports-page.php -> client-sync/includes/admin/views/view-reports-page.php
 * View for the Reports admin page.
 * Version: 3.0 - Added KPI summary, revenue, service breakdown, retention, and cancellation reports.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Variables passed from render_reports_page():
 * @var array  $appointments_per_month
 * @var array  $appointments_by_status
 * @var array  $popular_times
 * @var array  $popular_days
 * @var array  $kpi_summary
 * @var array  $revenue_per_month
 * @var array  $appointments_by_service
 * @var array  $client_retention
 * @var array  $cancellation_trends
 * @var string $start_date_filter
 * @var string $end_date_filter
 */
?>
<div class="wrap clisyc-reports-page">
    <h1><?php esc_html_e( 'Client Sync Reports', 'client-sync' ); ?></h1>
    <p><?php esc_html_e( 'View booking trends and statistics for your appointments.', 'client-sync' ); ?></p>

    <!-- Date Range Filter Form -->
    <div class="clisyc-filters-container postbox">
        <div class="inside">
            <form method="get">
                <input type="hidden" name="page" value="clisyc-reports">
                <label for="start_date"><?php esc_html_e( 'Start Date:', 'client-sync' ); ?></label>
                <input type="date" id="start_date" name="start_date" value="<?php echo esc_attr( $start_date_filter ); ?>">
                <label for="end_date"><?php esc_html_e( 'End Date:', 'client-sync' ); ?></label>
                <input type="date" id="end_date" name="end_date" value="<?php echo esc_attr( $end_date_filter ); ?>">
                <input type="submit" value="<?php esc_attr_e( 'Filter', 'client-sync' ); ?>" class="button button-secondary">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-reports' ) ); ?>" class="button"><?php esc_html_e( 'Reset to Default', 'client-sync' ); ?></a>
            </form>
        </div>
    </div>

    <p class="description">
        <?php
        /* translators: 1: The formatted start date of the filter range. 2: The formatted end date of the filter range. */
        $clisyc_showing_reports_text = __( 'Showing reports for the period: <strong>%1$s</strong> to <strong>%2$s</strong>', 'client-sync' );
        printf(
            wp_kses_post( $clisyc_showing_reports_text ),
            esc_html( wp_date( get_option( 'date_format' ), strtotime( $start_date_filter ) ) ),
            esc_html( wp_date( get_option( 'date_format' ), strtotime( $end_date_filter ) ) )
        );
        ?>
    </p>

    <div id="clisyc-reports-container" class="metabox-holder">

        <!-- Row 0: KPI Summary -->
        <div class="clisyc-kpi-row">
            <div class="clisyc-kpi-card">
                <span class="clisyc-kpi-value"><?php echo esc_html( $kpi_summary['total_appointments'] ); ?></span>
                <span class="clisyc-kpi-label"><?php esc_html_e( 'Total Appointments', 'client-sync' ); ?></span>
            </div>
            <?php if ( $kpi_summary['wc_active'] ) : ?>
            <div class="clisyc-kpi-card">
                <span class="clisyc-kpi-value"><?php echo wp_kses_post( $kpi_summary['revenue'] ); ?></span>
                <span class="clisyc-kpi-label"><?php esc_html_e( 'Revenue', 'client-sync' ); ?></span>
            </div>
            <?php endif; ?>
            <div class="clisyc-kpi-card">
                <span class="clisyc-kpi-value"><?php echo esc_html( $kpi_summary['unique_clients'] ); ?></span>
                <span class="clisyc-kpi-label"><?php esc_html_e( 'Unique Clients', 'client-sync' ); ?></span>
            </div>
            <div class="clisyc-kpi-card">
                <span class="clisyc-kpi-value"><?php echo esc_html( $kpi_summary['cancellation_rate'] ); ?>%</span>
                <span class="clisyc-kpi-label"><?php esc_html_e( 'Cancellation Rate', 'client-sync' ); ?></span>
            </div>
            <div class="clisyc-kpi-card">
                <span class="clisyc-kpi-value"><?php echo esc_html( $kpi_summary['avg_per_day'] ); ?></span>
                <span class="clisyc-kpi-label"><?php esc_html_e( 'Avg Bookings/Day', 'client-sync' ); ?></span>
            </div>
            <div class="clisyc-kpi-card clisyc-kpi-card-export">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="clisyc_export_report">
                    <input type="hidden" name="report_id" value="kpi_summary">
                    <input type="hidden" name="start_date" value="<?php echo esc_attr( $start_date_filter ); ?>">
                    <input type="hidden" name="end_date" value="<?php echo esc_attr( $end_date_filter ); ?>">
                    <?php wp_nonce_field( 'clisyc_export_report_nonce', 'clisyc_export_nonce' ); ?>
                    <button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Export CSV', 'client-sync' ); ?></button>
                </form>
            </div>
        </div>

        <!-- Row 1: Appointments per Month + Appointments by Status -->
        <div class="clisyc-reports-row">
            <!-- Appointments per Month -->
            <div class="postbox clisyc-report-box clisyc-report-box-half">
                <div class="postbox-header">
                    <h2 class="hndle"><span><?php esc_html_e( 'Appointments per Month', 'client-sync' ); ?></span></h2>
                    <div class="handle-actions">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="clisyc_export_report">
                            <input type="hidden" name="report_id" value="appointments_per_month">
                            <input type="hidden" name="start_date" value="<?php echo esc_attr( $start_date_filter ); ?>">
                            <input type="hidden" name="end_date" value="<?php echo esc_attr( $end_date_filter ); ?>">
                            <?php wp_nonce_field( 'clisyc_export_report_nonce', 'clisyc_export_nonce' ); ?>
                            <button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Export CSV', 'client-sync' ); ?></button>
                        </form>
                    </div>
                </div>
                <div class="inside">
                    <?php if ( ! empty( $appointments_per_month ) ) : ?>
                        <div class="clisyc-chart-container"><canvas id="clisycAppointmentsPerMonthChart"></canvas></div>
                        <table class="wp-list-table widefat fixed striped">
                            <thead><tr><th><?php esc_html_e( 'Month', 'client-sync' ); ?></th><th><?php esc_html_e( 'Count', 'client-sync' ); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ( $appointments_per_month as $clisyc_month => $clisyc_count ) : ?>
                                    <tr><td><?php echo esc_html( $clisyc_month ); ?></td><td><?php echo esc_html( $clisyc_count ); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e( 'No appointment data available to display for this report.', 'client-sync' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Appointments by Status -->
            <div class="postbox clisyc-report-box clisyc-report-box-half">
                <div class="postbox-header">
                    <h2 class="hndle"><span><?php esc_html_e( 'Appointments by Status', 'client-sync' ); ?></span></h2>
                    <div class="handle-actions">
                         <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="clisyc_export_report">
                            <input type="hidden" name="report_id" value="appointments_by_status">
                            <input type="hidden" name="start_date" value="<?php echo esc_attr( $start_date_filter ); ?>">
                            <input type="hidden" name="end_date" value="<?php echo esc_attr( $end_date_filter ); ?>">
                            <?php wp_nonce_field( 'clisyc_export_report_nonce', 'clisyc_export_nonce' ); ?>
                            <button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Export CSV', 'client-sync' ); ?></button>
                        </form>
                    </div>
                </div>
                <div class="inside">
                    <?php if ( ! empty( $appointments_by_status ) ) : ?>
                        <div class="clisyc-chart-container"><canvas id="clisycAppointmentsByStatusChart"></canvas></div>
                        <table class="wp-list-table widefat fixed striped">
                            <thead><tr><th><?php esc_html_e( 'Status', 'client-sync' ); ?></th><th><?php esc_html_e( 'Count', 'client-sync' ); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ( $appointments_by_status as $clisyc_status => $clisyc_count ) : ?>
                                    <tr><td><?php echo esc_html( $clisyc_status ); ?></td><td><?php echo esc_html( $clisyc_count ); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e( 'No appointment data available to display for this report.', 'client-sync' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- .clisyc-reports-row -->

        <!-- Row 2: Revenue by Month + Appointments by Service -->
        <div class="clisyc-reports-row">
            <?php if ( class_exists( 'WooCommerce' ) ) : ?>
            <!-- Revenue by Month -->
            <div class="postbox clisyc-report-box clisyc-report-box-half">
                <div class="postbox-header">
                    <h2 class="hndle"><span><?php esc_html_e( 'Revenue by Month', 'client-sync' ); ?></span></h2>
                    <div class="handle-actions">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="clisyc_export_report">
                            <input type="hidden" name="report_id" value="revenue_per_month">
                            <input type="hidden" name="start_date" value="<?php echo esc_attr( $start_date_filter ); ?>">
                            <input type="hidden" name="end_date" value="<?php echo esc_attr( $end_date_filter ); ?>">
                            <?php wp_nonce_field( 'clisyc_export_report_nonce', 'clisyc_export_nonce' ); ?>
                            <button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Export CSV', 'client-sync' ); ?></button>
                        </form>
                    </div>
                </div>
                <div class="inside">
                    <?php if ( ! empty( $revenue_per_month ) ) : ?>
                        <div class="clisyc-chart-container"><canvas id="clisycRevenuePerMonthChart"></canvas></div>
                        <table class="wp-list-table widefat fixed striped">
                            <thead><tr><th><?php esc_html_e( 'Month', 'client-sync' ); ?></th><th><?php esc_html_e( 'Revenue', 'client-sync' ); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ( $revenue_per_month as $clisyc_month => $clisyc_revenue ) : ?>
                                    <tr><td><?php echo esc_html( $clisyc_month ); ?></td><td><?php echo esc_html( wc_price( $clisyc_revenue ) ); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e( 'No revenue data available to display for this report.', 'client-sync' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Appointments by Service -->
            <div class="postbox clisyc-report-box clisyc-report-box-half">
                <div class="postbox-header">
                    <h2 class="hndle"><span><?php esc_html_e( 'Appointments by Service', 'client-sync' ); ?></span></h2>
                    <div class="handle-actions">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="clisyc_export_report">
                            <input type="hidden" name="report_id" value="appointments_by_service">
                            <input type="hidden" name="start_date" value="<?php echo esc_attr( $start_date_filter ); ?>">
                            <input type="hidden" name="end_date" value="<?php echo esc_attr( $end_date_filter ); ?>">
                            <?php wp_nonce_field( 'clisyc_export_report_nonce', 'clisyc_export_nonce' ); ?>
                            <button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Export CSV', 'client-sync' ); ?></button>
                        </form>
                    </div>
                </div>
                <div class="inside">
                    <?php if ( ! empty( $appointments_by_service ) ) : ?>
                        <div class="clisyc-chart-container"><canvas id="clisycAppointmentsByServiceChart"></canvas></div>
                        <table class="wp-list-table widefat fixed striped">
                            <thead><tr><th><?php esc_html_e( 'Service', 'client-sync' ); ?></th><th><?php esc_html_e( 'Count', 'client-sync' ); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ( $appointments_by_service as $clisyc_service => $clisyc_count ) : ?>
                                    <tr><td><?php echo esc_html( $clisyc_service ); ?></td><td><?php echo esc_html( $clisyc_count ); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e( 'No service data available to display for this report.', 'client-sync' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- .clisyc-reports-row -->

        <!-- Row 3: Popular Times + Popular Days -->
        <div class="clisyc-reports-row">
            <!-- Popular Appointment Times -->
            <div class="postbox clisyc-report-box clisyc-report-box-half">
                 <div class="postbox-header">
                    <h2 class="hndle"><span><?php esc_html_e( 'Popular Appointment Times', 'client-sync' ); ?></span></h2>
                    <div class="handle-actions">
                         <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="clisyc_export_report">
                            <input type="hidden" name="report_id" value="popular_times">
                            <input type="hidden" name="start_date" value="<?php echo esc_attr( $start_date_filter ); ?>">
                            <input type="hidden" name="end_date" value="<?php echo esc_attr( $end_date_filter ); ?>">
                            <?php wp_nonce_field( 'clisyc_export_report_nonce', 'clisyc_export_nonce' ); ?>
                            <button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Export CSV', 'client-sync' ); ?></button>
                        </form>
                    </div>
                </div>
                <div class="inside">
                    <?php if ( ! empty( $popular_times ) ) : ?>
                        <div class="clisyc-chart-container"><canvas id="clisycPopularTimesChart"></canvas></div>
                        <table class="wp-list-table widefat fixed striped">
                            <thead><tr><th><?php esc_html_e( 'Time Slot', 'client-sync' ); ?></th><th><?php esc_html_e( 'Count', 'client-sync' ); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ( $popular_times as $clisyc_time => $clisyc_count ) : ?>
                                    <tr><td><?php echo esc_html( wp_date( get_option('time_format'), strtotime($clisyc_time) ) ); ?></td><td><?php echo esc_html( $clisyc_count ); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e( 'No appointment data available to display for this report.', 'client-sync' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Popular Days of the Week -->
            <div class="postbox clisyc-report-box clisyc-report-box-half">
                <div class="postbox-header">
                    <h2 class="hndle"><span><?php esc_html_e( 'Popular Days of the Week', 'client-sync' ); ?></span></h2>
                     <div class="handle-actions">
                         <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="clisyc_export_report">
                            <input type="hidden" name="report_id" value="popular_days">
                            <input type="hidden" name="start_date" value="<?php echo esc_attr( $start_date_filter ); ?>">
                            <input type="hidden" name="end_date" value="<?php echo esc_attr( $end_date_filter ); ?>">
                            <?php wp_nonce_field( 'clisyc_export_report_nonce', 'clisyc_export_nonce' ); ?>
                            <button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Export CSV', 'client-sync' ); ?></button>
                        </form>
                    </div>
                </div>
                <div class="inside">
                    <?php if ( ! empty( $popular_days ) ) : ?>
                        <div class="clisyc-chart-container"><canvas id="clisycPopularDaysChart"></canvas></div>
                        <table class="wp-list-table widefat fixed striped">
                            <thead><tr><th><?php esc_html_e( 'Day of Week', 'client-sync' ); ?></th><th><?php esc_html_e( 'Count', 'client-sync' ); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ( $popular_days as $clisyc_day => $clisyc_count ) : ?>
                                    <tr><td><?php echo esc_html( $clisyc_day ); ?></td><td><?php echo esc_html( $clisyc_count ); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e( 'No appointment data available to display for this report.', 'client-sync' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- .clisyc-reports-row -->

        <!-- Row 4: Client Retention + Cancellation Trends -->
        <div class="clisyc-reports-row">
            <!-- Client Retention -->
            <div class="postbox clisyc-report-box clisyc-report-box-half">
                <div class="postbox-header">
                    <h2 class="hndle"><span><?php esc_html_e( 'Client Retention', 'client-sync' ); ?></span></h2>
                    <div class="handle-actions">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="clisyc_export_report">
                            <input type="hidden" name="report_id" value="client_retention">
                            <input type="hidden" name="start_date" value="<?php echo esc_attr( $start_date_filter ); ?>">
                            <input type="hidden" name="end_date" value="<?php echo esc_attr( $end_date_filter ); ?>">
                            <?php wp_nonce_field( 'clisyc_export_report_nonce', 'clisyc_export_nonce' ); ?>
                            <button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Export CSV', 'client-sync' ); ?></button>
                        </form>
                    </div>
                </div>
                <div class="inside">
                    <?php if ( ! empty( $client_retention ) ) : ?>
                        <div class="clisyc-chart-container"><canvas id="clisycClientRetentionChart"></canvas></div>
                        <table class="wp-list-table widefat fixed striped">
                            <thead><tr><th><?php esc_html_e( 'Month', 'client-sync' ); ?></th><th><?php esc_html_e( 'New Clients', 'client-sync' ); ?></th><th><?php esc_html_e( 'Returning Clients', 'client-sync' ); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ( $client_retention as $clisyc_month => $clisyc_data ) : ?>
                                    <tr><td><?php echo esc_html( $clisyc_month ); ?></td><td><?php echo esc_html( $clisyc_data['new'] ); ?></td><td><?php echo esc_html( $clisyc_data['returning'] ); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e( 'No client retention data available to display for this report.', 'client-sync' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cancellation & No-Show Trends -->
            <div class="postbox clisyc-report-box clisyc-report-box-half">
                <div class="postbox-header">
                    <h2 class="hndle"><span><?php esc_html_e( 'Cancellation & No-Show Trends', 'client-sync' ); ?></span></h2>
                    <div class="handle-actions">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="clisyc_export_report">
                            <input type="hidden" name="report_id" value="cancellation_trends">
                            <input type="hidden" name="start_date" value="<?php echo esc_attr( $start_date_filter ); ?>">
                            <input type="hidden" name="end_date" value="<?php echo esc_attr( $end_date_filter ); ?>">
                            <?php wp_nonce_field( 'clisyc_export_report_nonce', 'clisyc_export_nonce' ); ?>
                            <button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Export CSV', 'client-sync' ); ?></button>
                        </form>
                    </div>
                </div>
                <div class="inside">
                    <?php if ( ! empty( $cancellation_trends ) ) : ?>
                        <div class="clisyc-chart-container"><canvas id="clisycCancellationTrendsChart"></canvas></div>
                        <table class="wp-list-table widefat fixed striped">
                            <thead><tr><th><?php esc_html_e( 'Month', 'client-sync' ); ?></th><th><?php esc_html_e( 'Cancelled', 'client-sync' ); ?></th><th><?php esc_html_e( 'No-Show', 'client-sync' ); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ( $cancellation_trends as $clisyc_month => $clisyc_data ) : ?>
                                    <tr><td><?php echo esc_html( $clisyc_month ); ?></td><td><?php echo esc_html( $clisyc_data['cancelled'] ); ?></td><td><?php echo esc_html( $clisyc_data['noShow'] ); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e( 'No cancellation data available to display for this report.', 'client-sync' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- .clisyc-reports-row -->

    </div><!-- .metabox-holder -->
</div><!-- .wrap -->
