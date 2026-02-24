<?php
/**
 * File: src/shared/includes/admin/views/view-dashboard-page.php -> client-sync/includes/admin/views/view-dashboard-page.php
 * View for the main Dashboard admin page.
 * MODIFIED: Overhauled to include charts and an upcoming appointments widget.
 * MODIFIED V2: Re-added the Quick Setup & Management links at the bottom.
 * FIXED V3: Corrected HTML structure to remove unnecessary containers for better grid layout.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DependentMedia\ClientSync\Constants;

/**
 * Variables passed to this view from the render_dashboard_page method:
 * @var array $dashboard_stats
 * @var array $setup_milestones
 * @var array $system_status_checks
 * @var array $todays_appointments_widget_data
 * @var array $upcoming_appointments_widget_data
 * @var array $bookings_this_week_chart_data
 * @var array $busiest_services_chart_data
 */

// Calculate progress percentage for the setup checklist
$clisyc_completed_count                = 0;
$clisyc_total_milestones               = 0;
$clisyc_first_incomplete_milestone_key = null;

if ( is_array( $setup_milestones ) ) {
	foreach ( $setup_milestones as $clisyc_key => $clisyc_milestone ) {
		if ( ! ( $clisyc_milestone['is_optional'] ?? false ) ) {
			$clisyc_total_milestones++;
			if ( $clisyc_milestone['is_complete'] ) {
				$clisyc_completed_count++;
			} elseif ( ! $clisyc_first_incomplete_milestone_key ) {
				$clisyc_first_incomplete_milestone_key = $clisyc_key;
			}
		}
	}
}
$clisyc_progress_percentage = ( $clisyc_total_milestones > 0 ) ? round( ( $clisyc_completed_count / $clisyc_total_milestones ) * 100 ) : 100;
if ( $clisyc_progress_percentage > 100 ) {
	$clisyc_progress_percentage = 100;
}

?>
<div class="wrap clisyc-dashboard-page">
	<h1><?php esc_html_e( 'Client Sync Dashboard', 'client-sync' ); ?></h1>

	<?php if ( $clisyc_progress_percentage < 100 ) : ?>
		<div id="clisyc-setup-progress-section" class="postbox">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e( 'Finish Setup', 'client-sync' ); ?></h2>
			</div>
			<div class="inside">
				<p><?php
					/*
					* translators: %s: The completion percentage number, which will be wrapped in a <strong> tag.
					*/
					$clisyc_progress_text = __( 'You are <strong>%s%%</strong> of the way through the basic setup!', 'client-sync' );
					printf(
						wp_kses( $clisyc_progress_text, [ 'strong' => [] ] ),
						esc_html( $clisyc_progress_percentage )
					);
				?></p>
				<div class="client-sync-progress-bar-container">
					<div class="client-sync-progress-bar" style="width: <?php echo esc_attr( $clisyc_progress_percentage ); ?>%;"></div>
				</div>
				<p><?php esc_html_e( 'Complete the remaining steps to get your booking system fully online.', 'client-sync' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-guide' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Continue Setup Checklist', 'client-sync' ); ?>
				</a>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $system_status_checks ) ) : ?>
		<div id="clisyc-system-status-section" class="postbox">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e( 'System Status', 'client-sync' ); ?></h2>
			</div>
			<div class="inside">
				<ul class="clisyc-status-list">
					<?php
					$clisyc_status_map = [
						'critical' => [ 'icon' => 'dashicons-warning', 'class' => 'critical' ],
						'warning'  => [ 'icon' => 'dashicons-warning', 'class' => 'warning' ],
						'notice'   => [ 'icon' => 'dashicons-info-outline', 'class' => 'notice' ],
					];
					?>
					<?php foreach ( $system_status_checks as $clisyc_check ) : ?>
						<?php $clisyc_status_info = $clisyc_status_map[ $clisyc_check['status'] ] ?? $clisyc_status_map['notice']; ?>
						<li class="clisyc-status-item clisyc-status-<?php echo esc_attr( $clisyc_status_info['class'] ); ?>">
							<span class="dashicons <?php echo esc_attr( $clisyc_status_info['icon'] ); ?>"></span>
							<div class="clisyc-status-text">
								<p><?php echo wp_kses_post( $clisyc_check['message'] ); ?></p>
								<?php if ( ! empty( $clisyc_check['action_url'] ) && ! empty( $clisyc_check['action_text'] ) ) : ?>
									<a href="<?php echo esc_url( $clisyc_check['action_url'] ); ?>" class="button button-small button-secondary">
										<?php echo esc_html( $clisyc_check['action_text'] ); ?>
									</a>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	<?php endif; ?>

	<div id="dashboard-widgets-wrap">
		<div id="dashboard-widgets" class="metabox-holder">
			
			<!-- THE FIX: The .postbox-container divs have been removed. -->
			<!-- All .postbox divs are now direct children of #dashboard-widgets -->

			<!-- At a Glance Widget -->
			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'At a Glance', 'client-sync' ); ?></h2></div>
				<div class="inside">
					<div class="main">
						<ul class="clisyc-glance-list">
							<li class="clisyc-glance-highlight"><span><?php esc_html_e( 'Today\'s Appointments:', 'client-sync' ); ?></span><strong><?php echo esc_html( $dashboard_stats['todays_appointments'] ); ?></strong></li>
							<li><span><?php esc_html_e( 'Appointments This Month:', 'client-sync' ); ?></span><strong><?php echo esc_html( $dashboard_stats['appointments_this_month'] ); ?></strong></li>
							<li><span><?php esc_html_e( 'Income This Month:', 'client-sync' ); ?></span><strong><?php echo wp_kses_post( $dashboard_stats['income_this_month'] ); ?></strong></li>
							<li><span><?php esc_html_e( 'Total Upcoming:', 'client-sync' ); ?></span><strong><?php echo esc_html( $dashboard_stats['upcoming_appointments'] ); ?></strong></li>
							<li><span><?php esc_html_e( 'Total Clients:', 'client-sync' ); ?></span><strong><?php echo esc_html( $dashboard_stats['total_clients'] ); ?></strong></li>
							<li><span><?php esc_html_e( 'Busiest Day:', 'client-sync' ); ?></span><strong><?php echo esc_html( $dashboard_stats['busiest_day'] ); ?></strong></li>
						</ul>
					</div>
				</div>
			</div>

			<!-- Today's Schedule Widget -->
			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Today\'s Schedule', 'client-sync' ); ?></h2></div>
				<div class="inside">
					<?php if ( ! empty( $todays_appointments_widget_data ) ) : ?>
						<ul class="clisyc-todays-list">
							<?php foreach ( $todays_appointments_widget_data as $clisyc_today_appt ) : ?>
								<li>
									<div class="clisyc-todays-time">
										<span class="dashicons dashicons-clock"></span>
										<?php echo esc_html( $clisyc_today_appt['time'] ); ?>
									</div>
									<div class="clisyc-todays-main">
										<a href="<?php echo esc_url( $clisyc_today_appt['edit_link'] ); ?>" class="clisyc-todays-title"><?php echo esc_html( $clisyc_today_appt['title'] ); ?></a>
										<span class="clisyc-todays-client"><?php echo esc_html( $clisyc_today_appt['client_name'] ); ?></span>
									</div>
									<div class="clisyc-todays-status">
										<span class="clisyc-status-pill clisyc-status-pill--<?php echo esc_attr( sanitize_html_class( $clisyc_today_appt['status'] ) ); ?>"><?php echo esc_html( $clisyc_today_appt['status_label'] ); ?></span>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="clisyc-todays-empty"><?php esc_html_e( 'No appointments scheduled for today.', 'client-sync' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Bookings This Week Chart -->
			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Bookings This Week', 'client-sync' ); ?></h2></div>
				<div class="inside">
					<div class="clisyc-chart-container" style="height: 250px;">
						<canvas id="clisyc-bookings-this-week-chart"></canvas>
					</div>
				</div>
			</div>

			<!-- Upcoming Appointments Widget -->
			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Upcoming Appointments', 'client-sync' ); ?></h2></div>
				<div class="inside">
					<?php if ( ! empty( $upcoming_appointments_widget_data ) ) : ?>
						<ul class="clisyc-upcoming-list">
							<?php foreach ( $upcoming_appointments_widget_data as $clisyc_appt ) : ?>
								<li>
									<div class="clisyc-upcoming-main">
										<a href="<?php echo esc_url( $clisyc_appt['edit_link'] ); ?>" class="clisyc-upcoming-title"><?php echo esc_html( $clisyc_appt['title'] ); ?></a>
										<span class="clisyc-upcoming-client"><?php echo esc_html( $clisyc_appt['client_name'] ); ?></span>
									</div>
									<div class="clisyc-upcoming-time">
										<span title="<?php echo esc_attr( $clisyc_appt['date_time'] ); ?>"><?php echo esc_html( $clisyc_appt['relative_time'] ); ?></span>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p><?php esc_html_e( 'No upcoming appointments found.', 'client-sync' ); ?></p>
					<?php endif; ?>
					<div class="clisyc-widget-actions">
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Constants::POST_TYPE_APPOINTMENT ) ); ?>" class="button button-secondary"><?php esc_html_e( 'View All', 'client-sync' ); ?></a>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Constants::POST_TYPE_APPOINTMENT ) ); ?>" class="button button-primary"><?php esc_html_e( 'Add New', 'client-sync' ); ?></a>
					</div>
				</div>
			</div>

			<!-- Busiest Services Chart -->
			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Busiest Services (All Time)', 'client-sync' ); ?></h2></div>
				<div class="inside">
					<div class="clisyc-chart-container" style="height: 250px;">
						<canvas id="clisyc-busiest-services-chart"></canvas>
					</div>
				</div>
			</div>

		</div> <!-- end #dashboard-widgets -->
	</div> <!-- end #dashboard-widgets-wrap -->
	
	<div class="clisyc-quick-links-section">
		<h2><?php esc_html_e( 'Quick Setup & Management', 'client-sync' ); ?></h2>
		<p><?php esc_html_e( 'Use these shortcuts to quickly access common configuration areas.', 'client-sync' ); ?></p>
		<div class="clisyc-quick-links-container">
			<div class="clisyc-quick-link-card">
				<span class="dashicons dashicons-calendar"></span>
				<h3><?php esc_html_e( 'View Calendar', 'client-sync' ); ?></h3>
				<p><?php esc_html_e( 'See all upcoming and past appointments on a full-size calendar.', 'client-sync' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-calendars' ) ); ?>" class="button button-primary"><?php esc_html_e( 'View Calendar', 'client-sync' ); ?></a>
			</div>
			<div class="clisyc-quick-link-card">
				<span class="dashicons dashicons-plus-alt"></span>
				<h3><?php esc_html_e( 'Add New Appointment', 'client-sync' ); ?></h3>
				<p><?php esc_html_e( 'Manually book a new appointment on behalf of a client.', 'client-sync' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Constants::POST_TYPE_APPOINTMENT ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Add Appointment', 'client-sync' ); ?></a>
			</div>
			<div class="clisyc-quick-link-card">
				<span class="dashicons dashicons-list-view"></span>
				<h3><?php esc_html_e( 'View All Appointments', 'client-sync' ); ?></h3>
				<p><?php esc_html_e( 'See a list of all appointments with filtering and search options.', 'client-sync' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Constants::POST_TYPE_APPOINTMENT ) ); ?>" class="button button-secondary"><?php esc_html_e( 'View List', 'client-sync' ); ?></a>
			</div>
			<div class="clisyc-quick-link-card">
				<span class="dashicons dashicons-calendar-alt"></span>
				<h3><?php esc_html_e( 'Manage Availability', 'client-sync' ); ?></h3>
				<p><?php esc_html_e( 'Visually block off time or generate slots based on your schedule.', 'client-sync' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-calendars&tab=time-slots' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Go to Time Slots', 'client-sync' ); ?></a>
			</div>
			<div class="clisyc-quick-link-card">
				<span class="dashicons dashicons-networking"></span>
				<h3><?php esc_html_e( 'Relationship Graph', 'client-sync' ); ?></h3>
				<p><?php esc_html_e( 'Visually connect your services, staff, locations, and other dimensions.', 'client-sync' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-dimensions&tab=graph' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Edit Graph', 'client-sync' ); ?></a>
			</div>
			<div class="clisyc-quick-link-card">
				<span class="dashicons dashicons-screenoptions"></span>
				<h3><?php esc_html_e( 'Manage Dimensions', 'client-sync' ); ?></h3>
				<p><?php esc_html_e( 'Configure the core building blocks like Services and Staff.', 'client-sync' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-dimensions' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Configure Dimensions', 'client-sync' ); ?></a>
			</div>
			<div class="clisyc-quick-link-card">
				<span class="dashicons dashicons-admin-settings"></span>
				<h3><?php esc_html_e( 'Customize Fields', 'client-sync' ); ?></h3>
				<p><?php esc_html_e( 'Add or edit custom fields for your clients and appointments.', 'client-sync' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-custom-fields' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Manage Fields', 'client-sync' ); ?></a>
			</div>
			<div class="clisyc-quick-link-card">
				<span class="dashicons dashicons-email-alt"></span>
				<h3><?php esc_html_e( 'Configure Notifications', 'client-sync' ); ?></h3>
				<p><?php esc_html_e( 'Customize the email templates for reminders and confirmations.', 'client-sync' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=notifications' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Edit Emails', 'client-sync' ); ?></a>
			</div>
		</div>
	</div>

</div>