<?php
/**
 * File: src/shared/includes/admin/views/view-automation-page.php -> client-sync/includes/admin/views/view-automation-page.php
 * View for the Automation Settings admin page.
 *
 * This file renders the settings page where administrators can configure
 * automatic time slot generation, cleanup, and other background tasks.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DependentMedia\ClientSync\Constants;

// *** REFACTORED ***: All options and hooks are now prefixed.
// Prepare data for the cron status display
$clisyc_next_scheduled_timestamp = wp_next_scheduled( Constants::ACTION_FREQUENT_MAINTENANCE );
$clisyc_last_run_timestamp       = get_option( Constants::OPTION_LAST_MAINTENANCE_TS, 0 );
$clisyc_wp_cron_disabled         = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
$clisyc_site_url                 = get_site_url();
?>
<div class="wrap clisyc-automation-settings">
	<h1><?php esc_html_e( 'Automation Settings', 'client-sync' ); ?></h1>

	<?php
	// Custom feedback messages (e.g., from manual trigger buttons) are handled via transients.
	$clisyc_feedback = get_transient( 'clisyc_automation_feedback' );
	if ( $clisyc_feedback ) {
		$clisyc_notice_class = isset( $clisyc_feedback['error'] ) ? 'notice-error' : 'notice-success';
		$clisyc_message      = isset( $clisyc_feedback['error'] ) ? $clisyc_feedback['error'] : $clisyc_feedback['message'];
		echo '<div class="notice ' . esc_attr( $clisyc_notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $clisyc_message ) . '</p></div>';
		delete_transient( 'clisyc_automation_feedback' );
	}
	?>

	<form method="post" action="options.php">
		<?php
		// *** REFACTORED ***: Updated settings group and page slug.
		settings_fields( 'clisyc_automation_settings_group' );
		do_settings_sections( 'clisyc-automation' );
		submit_button( __( 'Save Automation Settings', 'client-sync' ) );
		?>
	</form>
	
	<!-- START: NEW MANUAL TRIGGER SECTION -->
	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Manual Trigger', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<h4><?php esc_html_e( 'Force Frequent Maintenance Tasks', 'client-sync' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Manually executes all frequent tasks, including slot generation, reminder checks, and updating the "Smart Start Date" cache. This is useful for testing your schedules or forcing an immediate update.', 'client-sync' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clisyc-tool-form" style="margin-top: 1em;">
				<?php // This nonce and action are intentionally the same as the one on the testing page to reuse the same handler ?>
				<?php wp_nonce_field( 'clisyc_testing_run_frequent_cron', 'clisyc_testing_run_frequent_cron_nonce' ); ?>
				<?php wp_referer_field(); ?>
				<input type="hidden" name="action" value="clisyc_handle_testing_actions">
				<button type="submit" name="clisyc_test_action" value="force_run_frequent_maintenance" class="button button-secondary">
					<span class="dashicons dashicons-controls-repeat" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Force Run Maintenance Now', 'client-sync' ); ?>
				</button>
			</form>
		</div>
	</div>
	<!-- END: NEW MANUAL TRIGGER SECTION -->

	<!-- Cron Status Section -->
	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Automation Status', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<p><?php esc_html_e( 'This information helps you verify that the automated tasks are scheduled and running correctly.', 'client-sync' ); ?></p>
			<table class="form-table">
				<tbody>
					<?php // --- START: BATCH & MONITORING DISPLAY --- ?>
					<?php 
					$clisyc_gen_state  = get_option( Constants::OPTION_SLOT_GEN_STATE, [] );
					$clisyc_is_running = ! empty( $clisyc_gen_state['in_progress'] );
					$clisyc_cron_stats = get_transient( 'clisyc_cron_last_run_stats' );
					$clisyc_last_success_timestamp = get_option( Constants::OPTION_LAST_SUCCESS_TS, 0 );
					?>

					<!-- 0. Last Successful Run (Most Important) -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Last Successful Run', 'client-sync' ); ?></th>
						<td>
							<?php if ( $clisyc_last_success_timestamp ) : ?>
								<span style="color: #4CAF50;">&#10003;</span> 
								<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $clisyc_last_success_timestamp ) ); ?>
								<span class="description" style="margin-left: 8px;">(<?php echo esc_html( human_time_diff( $clisyc_last_success_timestamp, current_time( 'timestamp', true ) ) ); ?> <?php esc_html_e( 'ago', 'client-sync' ); ?>)</span>
								<?php 
								// Show slots generated if we have stats from this run
								if ( $clisyc_cron_stats && is_array( $clisyc_cron_stats ) && 'success' === ( $clisyc_cron_stats['status'] ?? '' ) ) : 
								?>
									<br><span class="description"><?php esc_html_e( 'Slots Generated:', 'client-sync' ); ?> <?php echo esc_html( number_format_i18n( $clisyc_cron_stats['slots_generated'] ?? 0 ) ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span style="color: #dba617;">&#9888; <?php esc_html_e( 'No successful runs yet', 'client-sync' ); ?></span>
								<p class="description"><?php esc_html_e( 'Click "Force Run Maintenance Now" below to run maintenance tasks for the first time.', 'client-sync' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>

					<!-- 1. Active Batch Status -->
					<?php if ( $clisyc_is_running ) : ?>
						<tr style="background-color: #fff8e5;">
							<th scope="row"><?php esc_html_e( 'Batch In Progress', 'client-sync' ); ?> <span class="dashicons dashicons-update clisyc-spin"></span></th>
							<td>
								<strong><?php esc_html_e( 'Current Date:', 'client-sync' ); ?></strong> <?php echo esc_html( $clisyc_gen_state['current_date_utc'] ?? 'Starting...' ); ?><br>
								<strong><?php esc_html_e( 'Service Index:', 'client-sync' ); ?></strong> <?php echo esc_html( $clisyc_gen_state['current_post_index'] ?? 0 ); ?><br>
								<strong><?php esc_html_e( 'Slots So Far:', 'client-sync' ); ?></strong> <?php echo esc_html( $clisyc_gen_state['stats']['inserted'] ?? 0 ); ?><br>
								<p class="description"><?php esc_html_e( 'The generator is processing huge schedules in chunks to prevent timeouts. It will resume automatically on the next cron tick.', 'client-sync' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>

					<!-- 2. Last Failed Run (only shows if last run failed) -->
					<?php if ( $clisyc_cron_stats && is_array( $clisyc_cron_stats ) && 'failed' === ( $clisyc_cron_stats['status'] ?? '' ) ) : ?>
						<tr style="background-color: #fef0f0;">
							<th scope="row" style="color: #d63638;"><?php esc_html_e( 'Last Run Failed', 'client-sync' ); ?></th>
							<td>
								<span style="color: #d63638;">&#10007; <?php esc_html_e( 'Failed', 'client-sync' ); ?></span> 
								<?php esc_html_e( 'at', 'client-sync' ); ?> 
								<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $clisyc_cron_stats['start_time'] ) ); ?>
								<?php if ( ! empty( $clisyc_cron_stats['error_message'] ) ) : ?>
									<br><strong><?php esc_html_e( 'Error:', 'client-sync' ); ?></strong> <em style="color: #d63638;"><?php echo esc_html( $clisyc_cron_stats['error_message'] ); ?></em>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Try clicking "Force Run Maintenance Now" to retry, or check the debug.log for more details.', 'client-sync' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
					<?php // --- END: BATCH & MONITORING DISPLAY --- ?>

					<!-- 3. Next Scheduled Run -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Next Scheduled Run', 'client-sync' ); ?></th>
						<td>
							<?php if ( $clisyc_next_scheduled_timestamp ) : ?>
								<?php
								$clisyc_formatted_date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $clisyc_next_scheduled_timestamp );
								$clisyc_time_diff      = human_time_diff( current_time( 'timestamp', true ), $clisyc_next_scheduled_timestamp );
								?>
								<span style="color: #4CAF50;">&#10003;</span> 
								<?php
								/* translators: 1: The formatted date and time. 2: A human-readable time difference (e.g., "10 minutes"). */
								printf( esc_html__( '%1$s (in %2$s)', 'client-sync' ), esc_html( $clisyc_formatted_date ), esc_html( $clisyc_time_diff ) );
								?>
							<?php else : ?>
								<span style="color: #d63638;"><strong>&#9888; <?php esc_html_e( 'Not currently scheduled', 'client-sync' ); ?></strong></span>
								<p class="description"><?php esc_html_e( 'Use the "Force Run Maintenance Now" button above to reschedule, or check the Cron Setup Guide below.', 'client-sync' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>

					<!-- 4. WP-Cron Status -->
					<tr>
						<th scope="row"><?php esc_html_e( 'WP-Cron Status', 'client-sync' ); ?></th>
						<td>
							<?php 
							// Check if cron appears to be working (successful run within the last hour)
							$clisyc_cron_appears_working = $clisyc_last_success_timestamp && ( time() - $clisyc_last_success_timestamp ) < HOUR_IN_SECONDS;
							?>
							<?php if ( $clisyc_wp_cron_disabled ) : ?>
								<?php if ( $clisyc_cron_appears_working ) : ?>
									<span style="color: #4CAF50;"><strong><?php esc_html_e( 'Server Cron Active', 'client-sync' ); ?></strong></span>
									<p class="description"><?php esc_html_e( 'DISABLE_WP_CRON is set to true and your server cron is working correctly.', 'client-sync' ); ?></p>
								<?php else : ?>
									<span style="color: #dba617;"><strong><?php esc_html_e( 'Server Cron Expected', 'client-sync' ); ?></strong></span>
									<p class="description"><?php esc_html_e( 'DISABLE_WP_CRON is set to true. Make sure you have a server-level cron job configured (see guide below).', 'client-sync' ); ?></p>
								<?php endif; ?>
							<?php else : ?>
								<?php if ( $clisyc_cron_appears_working ) : ?>
									<span style="color: #4CAF50;"><?php esc_html_e( 'Active (Default)', 'client-sync' ); ?></span>
									<p class="description"><?php esc_html_e( 'WP-Cron is triggering on page visits and working correctly.', 'client-sync' ); ?></p>
								<?php else : ?>
									<span style="color: #dba617;"><?php esc_html_e( 'Active (Default)', 'client-sync' ); ?></span>
									<p class="description"><?php esc_html_e( 'WP-Cron triggers on page visits. For busier sites or more reliable scheduling, consider setting up a server-level cron job.', 'client-sync' ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Cron Setup Guide Section -->
	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Cron Setup Guide', 'client-sync' ); ?></span></h2>
		<div class="inside">
			
			<!-- Understanding WP-Cron -->
			<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
				<h4 style="margin: 0 0 8px 0; color: #1d2327;"><?php esc_html_e( 'Understanding WP-Cron vs. Server Cron', 'client-sync' ); ?></h4>
				<p style="margin: 0; color: #50575e;">
					<?php esc_html_e( 'WordPress has its own pseudo-cron system (WP-Cron) that runs when someone visits your site. This works fine for most sites, but has limitations:', 'client-sync' ); ?>
				</p>
				<ul style="margin: 10px 0 0 20px; color: #50575e;">
					<li><?php esc_html_e( 'Low-traffic sites may have infrequent or delayed task execution', 'client-sync' ); ?></li>
					<li><?php esc_html_e( 'High-traffic sites may experience performance overhead', 'client-sync' ); ?></li>
					<li><?php esc_html_e( 'Tasks won\'t run if no one visits the site', 'client-sync' ); ?></li>
				</ul>
			</div>

			<!-- Server Cron Setup -->
			<h4><?php esc_html_e( 'Setting Up a Server-Level Cron Job (Recommended)', 'client-sync' ); ?></h4>
			<p><?php esc_html_e( 'For reliable, consistent task execution, set up a real server cron job:', 'client-sync' ); ?></p>

			<div style="background: #f6f7f7; padding: 16px; border-radius: 4px; margin: 16px 0;">
				<h5 style="margin: 0 0 12px 0;"><?php esc_html_e( 'Step 1: Disable WP-Cron', 'client-sync' ); ?></h5>
				<p style="margin: 0 0 8px 0;"><?php esc_html_e( 'Add this line to your wp-config.php file (before "That\'s all, stop editing!"):', 'client-sync' ); ?></p>
				<code style="display: block; background: #1d2327; color: #50fb7c; padding: 12px; border-radius: 4px; font-family: monospace; overflow-x: auto;">define('DISABLE_WP_CRON', true);</code>
			</div>

			<div style="background: #f6f7f7; padding: 16px; border-radius: 4px; margin: 16px 0;">
				<h5 style="margin: 0 0 12px 0;"><?php esc_html_e( 'Step 2: Add Server Cron Job', 'client-sync' ); ?></h5>
				<p style="margin: 0 0 8px 0;"><?php esc_html_e( 'Add a cron job to call wp-cron.php every 5 minutes. Access your server\'s crontab or hosting control panel and add:', 'client-sync' ); ?></p>
				<code style="display: block; background: #1d2327; color: #50fb7c; padding: 12px; border-radius: 4px; font-family: monospace; overflow-x: auto; word-break: break-all;">*/5 * * * * wget -q -O /dev/null "<?php echo esc_url( $clisyc_site_url ); ?>/wp-cron.php?doing_wp_cron" >/dev/null 2>&1</code>
				<p style="margin: 12px 0 0 0; font-size: 13px; color: #646970;">
					<?php esc_html_e( 'Alternative using curl:', 'client-sync' ); ?><br>
					<code style="background: #1d2327; color: #50fb7c; padding: 4px 8px; border-radius: 3px; font-size: 12px;">*/5 * * * * curl -s "<?php echo esc_url( $clisyc_site_url ); ?>/wp-cron.php?doing_wp_cron" >/dev/null 2>&1</code>
				</p>
				<p style="margin: 12px 0 0 0; font-size: 13px; color: #646970;">
					<?php esc_html_e( 'Alternative using URL only (for "Fetch a URL" task types like Plesk):', 'client-sync' ); ?><br>
					<code style="background: #1d2327; color: #50fb7c; padding: 4px 8px; border-radius: 3px; font-size: 12px;"><?php echo esc_url( $clisyc_site_url ); ?>/wp-cron.php?doing_wp_cron</code>
				</p>
			</div>

			<!-- Hosting-Specific Instructions -->
			<h4 style="margin-top: 24px;"><?php esc_html_e( 'Hosting-Specific Instructions', 'client-sync' ); ?></h4>
			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-top: 12px;">
				
				<div style="background: #fff; border: 1px solid #ddd; padding: 16px; border-radius: 4px;">
					<h5 style="margin: 0 0 8px 0;">
						<span class="dashicons dashicons-cloud" style="color: #2271b1;"></span> 
						<?php esc_html_e( 'cPanel / WHM', 'client-sync' ); ?>
					</h5>
					<ol style="margin: 0; padding-left: 20px; font-size: 13px;">
						<li><?php esc_html_e( 'Go to cPanel → Cron Jobs', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Set "Common Settings" to "Every 5 minutes"', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Paste the wget command above', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Click "Add New Cron Job"', 'client-sync' ); ?></li>
					</ol>
				</div>

				<div style="background: #fff; border: 1px solid #ddd; padding: 16px; border-radius: 4px;">
					<h5 style="margin: 0 0 8px 0;">
						<span class="dashicons dashicons-cloud" style="color: #52a6e5;"></span> 
						<?php esc_html_e( 'Plesk', 'client-sync' ); ?>
					</h5>
					<ol style="margin: 0; padding-left: 20px; font-size: 13px;">
						<li><?php esc_html_e( 'Go to Subscriptions → your domain → Scheduled Tasks', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Click "Add Task"', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Task type: Select "Fetch a URL"', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'URL: Paste your wp-cron.php URL', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Run: Select "Cron style", enter: */5 * * * *', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Notify: "Errors only" recommended', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Click "OK" to save', 'client-sync' ); ?></li>
					</ol>
				</div>

				<div style="background: #fff; border: 1px solid #ddd; padding: 16px; border-radius: 4px;">
					<h5 style="margin: 0 0 8px 0;">
						<span class="dashicons dashicons-cloud" style="color: #7b68ee;"></span> 
						<?php esc_html_e( 'Kinsta / WP Engine', 'client-sync' ); ?>
					</h5>
					<p style="margin: 0; font-size: 13px;">
						<?php esc_html_e( 'These managed hosts handle WP-Cron automatically with their own server-level cron. No configuration needed — it should work out of the box.', 'client-sync' ); ?>
					</p>
				</div>

				<div style="background: #fff; border: 1px solid #ddd; padding: 16px; border-radius: 4px;">
					<h5 style="margin: 0 0 8px 0;">
						<span class="dashicons dashicons-cloud" style="color: #ff6b35;"></span> 
						<?php esc_html_e( 'SiteGround', 'client-sync' ); ?>
					</h5>
					<ol style="margin: 0; padding-left: 20px; font-size: 13px;">
						<li><?php esc_html_e( 'Go to Site Tools → Devs → Cron Jobs', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Click "Create New Cron Job"', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Set interval and paste command', 'client-sync' ); ?></li>
					</ol>
				</div>

				<div style="background: #fff; border: 1px solid #ddd; padding: 16px; border-radius: 4px;">
					<h5 style="margin: 0 0 8px 0;">
						<span class="dashicons dashicons-admin-tools" style="color: #333;"></span> 
						<?php esc_html_e( 'Linux SSH Access', 'client-sync' ); ?>
					</h5>
					<ol style="margin: 0; padding-left: 20px; font-size: 13px;">
						<li><?php esc_html_e( 'SSH into your server', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Run: crontab -e', 'client-sync' ); ?></li>
						<li><?php esc_html_e( 'Add the cron line and save', 'client-sync' ); ?></li>
					</ol>
				</div>

			</div>

			<!-- Testing Your Setup -->
			<h4 style="margin-top: 24px;"><?php esc_html_e( 'Testing Your Cron Setup', 'client-sync' ); ?></h4>
			<ol style="margin-left: 20px;">
				<li><?php esc_html_e( 'Click "Force Run Maintenance Now" above to verify tasks execute properly', 'client-sync' ); ?></li>
				<li><?php esc_html_e( 'Check that "Last Completed Run" updates with a success status', 'client-sync' ); ?></li>
				<li><?php esc_html_e( 'Wait 5-10 minutes and refresh this page — "Next Scheduled Run" should update automatically', 'client-sync' ); ?></li>
				<li>
					<?php esc_html_e( 'For detailed debugging, enable WP_DEBUG_LOG in wp-config.php:', 'client-sync' ); ?>
					<code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 12px;">define('WP_DEBUG_LOG', true);</code>
				</li>
			</ol>

			<!-- Troubleshooting -->
			<div style="background: #fef8ee; border-left: 4px solid #dba617; padding: 12px 16px; margin-top: 20px;">
				<h4 style="margin: 0 0 8px 0; color: #1d2327;"><?php esc_html_e( 'Troubleshooting', 'client-sync' ); ?></h4>
				<ul style="margin: 0 0 0 20px; color: #50575e;">
					<li><strong><?php esc_html_e( 'Cron not running?', 'client-sync' ); ?></strong> <?php esc_html_e( 'Check if a security plugin or .htaccess is blocking wp-cron.php', 'client-sync' ); ?></li>
					<li><strong><?php esc_html_e( 'Tasks running slowly?', 'client-sync' ); ?></strong> <?php esc_html_e( 'Large schedules are processed in batches — check the "Batch In Progress" status', 'client-sync' ); ?></li>
					<li><strong><?php esc_html_e( '"Not currently scheduled" error?', 'client-sync' ); ?></strong> <?php esc_html_e( 'Click "Force Run Maintenance Now" to reschedule tasks', 'client-sync' ); ?></li>
					<li><strong><?php esc_html_e( 'Duplicate slots?', 'client-sync' ); ?></strong> <?php esc_html_e( 'Go to Testing → Clear All Plugin Caches, then force run maintenance', 'client-sync' ); ?></li>
				</ul>
			</div>

		</div>
	</div>

</div>
<style>
/* Spinning animation for batch in progress */
@keyframes clisyc-spin-animation {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}
.clisyc-spin {
	animation: clisyc-spin-animation 1s linear infinite;
}
</style>