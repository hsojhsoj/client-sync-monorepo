<?php
/**
 * File: part-timeline-overview.php
 * Template part for the Timeline Overview tab
 * Location: src/shared/includes/admin/views/template-parts/part-timeline-overview.php
 *
 * UPDATED: Added resize handle support and zoom control localization
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DependentMedia\ClientSync\Constants;

// 1. Determine State (Fresh Load vs. Filtered)
// We check for 'view_mode' because it is a select dropdown that always sends a value on submit.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading display preference.
$clisyc_is_filtered_request = isset( $_GET['view_mode'] );

// 2. Logic for "Show All Dimensions"
if ( ! $clisyc_is_filtered_request ) {
    // FRESH LOAD: Default to TRUE
    $clisyc_show_all_dimensions = true;
} else {
    // SUBMITTED: Respect user choice (checkbox sends '1' if checked, nothing if unchecked)
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading display preference.
    $clisyc_show_all_dimensions = isset( $_GET['show_all_dimensions'] );
}

// 3. Logic for Container Height
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading display preference.
$clisyc_container_height = isset( $_GET['container_height'] ) ? absint( $_GET['container_height'] ) : 700;
// Ensure within bounds
$clisyc_container_height = max( \DependentMedia\ClientSync\Constants::TIMELINE_MIN_HEIGHT_PX, min( \DependentMedia\ClientSync\Constants::TIMELINE_MAX_HEIGHT_PX, $clisyc_container_height ) );

// Enqueue timeline assets
$clisyc_timeline_shortcode = new \DependentMedia\ClientSync\Shortcodes\Timeline_Shortcode();

// Get available dimension types for resource selection
$clisyc_registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
$clisyc_enabled_dimensions = [];

foreach ( $clisyc_registry['dimensions'] ?? [] as $clisyc_slug => $clisyc_settings ) {
	if ( ! empty( $clisyc_settings['enabled'] ) ) {
		$clisyc_cpt = get_post_type_object( $clisyc_slug );
		if ( $clisyc_cpt ) {
			$clisyc_enabled_dimensions[ $clisyc_slug ] = $clisyc_cpt->labels->name ?? ucfirst( str_replace( 'clisyc_', '', $clisyc_slug ) );
		}
	}
}

// Get primary dimension
$clisyc_primary_dimension = '';
foreach ( $clisyc_registry['dimensions'] ?? [] as $clisyc_slug => $clisyc_settings ) {
	if ( ! empty( $clisyc_settings['primary'] ) ) {
		$clisyc_primary_dimension = $clisyc_slug;
		break;
	}
}

// Default to staff or service if available
if ( empty( $clisyc_primary_dimension ) ) {
	if ( isset( $clisyc_enabled_dimensions['clisyc_staff'] ) ) {
		$clisyc_primary_dimension = 'clisyc_staff';
	} elseif ( isset( $clisyc_enabled_dimensions['clisyc_service'] ) ) {
		$clisyc_primary_dimension = 'clisyc_service';
	} else {
		$clisyc_primary_dimension = array_key_first( $clisyc_enabled_dimensions ) ?? '';
	}
}

?>

<div class="clisyc-timeline-admin-wrapper">
	<div class="clisyc-timeline-admin-header">
		<h2><?php esc_html_e( 'Timeline Overview', 'client-sync' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Visualize appointments across multiple resources with a multi-track timeline view, similar to video editing software. This view makes it easy to spot scheduling overlaps and availability gaps.', 'client-sync' ); ?>
		</p>
	</div>

	<?php if ( empty( $clisyc_enabled_dimensions ) ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: URL to dimensions page */
					esc_html__( 'No dimensions are currently enabled. Please configure your dimensions (Services, Staff, etc.) in the %s before using the timeline view.', 'client-sync' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-dimensions' ) ) . '">' . esc_html__( 'Dimensions page', 'client-sync' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php else : ?>
		
		<!-- Timeline Settings Panel -->
		<div class="clisyc-timeline-settings-panel">
			<details>
				<summary class="clisyc-timeline-settings-toggle">
					<span class="dashicons dashicons-admin-settings"></span>
					<?php esc_html_e( 'Timeline Settings', 'client-sync' ); ?>
				</summary>
				
				<div class="clisyc-timeline-settings-content">
					<form method="get" action="" id="clisyc-timeline-settings-form">
						<input type="hidden" name="page" value="clisyc-calendars" />
						<input type="hidden" name="tab" value="timeline" />
						
						<div class="clisyc-timeline-setting-row">
							<label>
                                <!-- UPDATED: Uses the calculated $show_all_dimensions variable -->
								<input type="checkbox" id="timeline-show-all" name="show_all_dimensions" value="1" <?php checked( $clisyc_show_all_dimensions ); ?> />
								<strong><?php esc_html_e( 'Show All Dimensions at Once', 'client-sync' ); ?></strong>
							</label>
							<p class="description">
								<?php esc_html_e( 'Display all dimension types simultaneously to visualize scheduling overlaps across services, staff, locations, etc.', 'client-sync' ); ?>
							</p>
						</div>
						
						<div class="clisyc-timeline-setting-row" id="timeline-single-resource-row">
							<label for="timeline-resource-type">
								<strong><?php esc_html_e( 'Display Resources:', 'client-sync' ); ?></strong>
							</label>
							<select id="timeline-resource-type" name="resource_type">
								<?php foreach ( $clisyc_enabled_dimensions as $clisyc_slug => $clisyc_label ) : ?>
									<option value="<?php echo esc_attr( $clisyc_slug ); ?>" <?php selected( $clisyc_primary_dimension, $clisyc_slug ); ?>>
										<?php echo esc_html( $clisyc_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Choose which type of resource to display as timeline tracks (when not showing all).', 'client-sync' ); ?>
							</p>
						</div>

						<script>
						(function() {
							const showAllCheckbox = document.getElementById('timeline-show-all');
							const singleResourceRow = document.getElementById('timeline-single-resource-row');
							
							function toggleResourceSelector() {
								if (showAllCheckbox && singleResourceRow) {
									singleResourceRow.style.opacity = showAllCheckbox.checked ? '0.5' : '1';
									document.getElementById('timeline-resource-type').disabled = showAllCheckbox.checked;
								}
							}
							
							if (showAllCheckbox) {
								showAllCheckbox.addEventListener('change', toggleResourceSelector);
								toggleResourceSelector(); // Run on load
							}
						})();
						</script>

						<div class="clisyc-timeline-setting-row">
							<label for="timeline-view-mode">
								<strong><?php esc_html_e( 'Default View:', 'client-sync' ); ?></strong>
							</label>
                            <?php 
                            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading display preference.
                            $clisyc_current_view = isset( $_GET['view_mode'] ) ? sanitize_key( $_GET['view_mode'] ) : 'week'; 
                            ?>
							<select id="timeline-view-mode" name="view_mode">
								<option value="day" <?php selected( $clisyc_current_view, 'day' ); ?>><?php esc_html_e( 'Day View', 'client-sync' ); ?></option>
								<option value="week" <?php selected( $clisyc_current_view, 'week' ); ?>><?php esc_html_e( 'Week View', 'client-sync' ); ?></option>
								<option value="month" <?php selected( $clisyc_current_view, 'month' ); ?>><?php esc_html_e( 'Month View', 'client-sync' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Select the default time range to display.', 'client-sync' ); ?>
							</p>
						</div>

						<div class="clisyc-timeline-setting-row">
							<label for="timeline-color-by">
								<strong><?php esc_html_e( 'Color Appointments By:', 'client-sync' ); ?></strong>
							</label>
                            <?php 
                            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading display preference.
                            $clisyc_current_color = isset( $_GET['color_by'] ) ? sanitize_key( $_GET['color_by'] ) : 'service'; 
                            ?>
							<select id="timeline-color-by" name="color_by">
								<option value="service" <?php selected( $clisyc_current_color, 'service' ); ?>><?php esc_html_e( 'Service', 'client-sync' ); ?></option>
								<option value="staff" <?php selected( $clisyc_current_color, 'staff' ); ?>><?php esc_html_e( 'Staff', 'client-sync' ); ?></option>
								<option value="status" <?php selected( $clisyc_current_color, 'status' ); ?>><?php esc_html_e( 'Status (Available/Booked/Blocked)', 'client-sync' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Choose how to color-code appointments on the timeline.', 'client-sync' ); ?>
							</p>
						</div>

						<div class="clisyc-timeline-setting-row">
							<label for="timeline-container-height">
								<strong><?php esc_html_e( 'Initial Timeline Height:', 'client-sync' ); ?></strong>
							</label>
							<div class="clisyc-timeline-height-input">
                                <!-- UPDATED: Changed step to "1" to allow specific pixel values -->
								<input 
									type="number" 
									id="timeline-container-height" 
									name="container_height" 
									min="300" 
									max="2000" 
									step="50"
									value="<?php echo esc_attr( $clisyc_container_height ); ?>"
								/>
								<span class="units">px</span>
							</div>
							<p class="description">
								<?php esc_html_e( 'Set the initial timeline height (300-2000 pixels). You can also drag the resize handle at the bottom of the timeline.', 'client-sync' ); ?>
							</p>
						</div>

						<div class="clisyc-timeline-setting-actions">
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Apply Settings', 'client-sync' ); ?>
							</button>
						</div>
					</form>
				</div>
			</details>
		</div>

		<!-- Shortcode Documentation Panel -->
		<div class="clisyc-timeline-settings-panel">
			<details>
				<summary class="clisyc-timeline-settings-toggle">
					<span class="dashicons dashicons-shortcode"></span>
					<?php esc_html_e( 'Use Timeline on Frontend', 'client-sync' ); ?>
				</summary>
				
				<div class="clisyc-timeline-settings-content">
					<p class="description">
						<?php esc_html_e( 'Use the shortcode below to display the timeline on any page or post:', 'client-sync' ); ?>
					</p>

					<div class="clisyc-shortcode-example">
						<code>[clisyc_timeline]</code>
						<button class="button button-small clisyc-copy-btn" data-shortcode="[clisyc_timeline]">
							<span class="dashicons dashicons-clipboard"></span>
							<?php esc_html_e( 'Copy', 'client-sync' ); ?>
						</button>
					</div>

					<div class="clisyc-shortcode-example">
						<code>[clisyc_timeline view="week" height="700" color_by="status"]</code>
						<button class="button button-small clisyc-copy-btn" data-shortcode='[clisyc_timeline view="week" height="700" color_by="status"]'>
							<span class="dashicons dashicons-clipboard"></span>
							<?php esc_html_e( 'Copy', 'client-sync' ); ?>
						</button>
					</div>

					<div class="clisyc-shortcode-params">
						<h4><?php esc_html_e( 'Available Parameters:', 'client-sync' ); ?></h4>
						<ul>
							<li><strong>view</strong> - <code>day</code>, <code>week</code>, or <code>month</code> (default: <code>week</code>)</li>
							<li><strong>resources</strong> - CPT slugs or post IDs (default: all dimension types)</li>
							<li><strong>height</strong> - Timeline height in pixels (default: <code>700</code>)</li>
							<li><strong>color_by</strong> - <code>service</code>, <code>staff</code>, or <code>status</code> (default: <code>service</code>)</li>
							<li><strong>show_controls</strong> - <code>true</code> or <code>false</code> (default: <code>true</code>)</li>
							<li><strong>allow_booking</strong> - <code>true</code> or <code>false</code> (default: <code>false</code>)</li>
						</ul>
					</div>
				</div>

				<script>
				jQuery(document).ready(function($) {
					$('.clisyc-copy-btn').on('click', function(e) {
						e.preventDefault();
						const shortcode = $(this).data('shortcode');
						const $btn = $(this);
						
						// Copy to clipboard
						navigator.clipboard.writeText(shortcode).then(function() {
							// Show success feedback
							$btn.html('<span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Copied!', 'client-sync' ); ?>');
							$btn.addClass('button-primary');
							
							// Reset after 2 seconds
							setTimeout(function() {
								$btn.html('<span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy', 'client-sync' ); ?>');
								$btn.removeClass('button-primary');
							}, 2000);
						});
					});
				});
				</script>
			</details>
		</div>

		<!-- Timeline Display -->
		<div class="clisyc-timeline-display-area">
			<?php
            // UPDATED: Use the PHP variables defined at the top
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading display preference.
			$clisyc_resource_type = isset( $_GET['resource_type'] ) ? sanitize_key( $_GET['resource_type'] ) : $clisyc_primary_dimension;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading display preference.
			$clisyc_view_mode = isset( $_GET['view_mode'] ) ? sanitize_key( $_GET['view_mode'] ) : 'week';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading display preference.
			$clisyc_color_by = isset( $_GET['color_by'] ) ? sanitize_key( $_GET['color_by'] ) : 'service';

			// Determine which resources to show
			if ( $clisyc_show_all_dimensions ) {
				// Get ALL post IDs from all enabled dimensions
				$clisyc_all_resource_ids = [];
				foreach ( array_keys( $clisyc_enabled_dimensions ) as $clisyc_dim_slug ) {
					$posts = get_posts( [
						'post_type'      => $clisyc_dim_slug,
						'posts_per_page' => -1,
						'post_status'    => 'publish',
						'fields'         => 'ids',
					] );
					$clisyc_all_resource_ids = array_merge( $clisyc_all_resource_ids, $posts );
				}
				$clisyc_resources_param = implode( ',', $clisyc_all_resource_ids );
			} else {
				// Get post IDs from single dimension type
				$posts = get_posts( [
					'post_type'      => $clisyc_resource_type,
					'posts_per_page' => -1,
					'post_status'    => 'publish',
					'fields'         => 'ids',
				] );
				$clisyc_resources_param = implode( ',', $posts );
			}

			// Render the timeline shortcode
			echo do_shortcode( sprintf(
				'[clisyc_timeline view="%s" resources="%s" height="%d" show_controls="true" start_time="08:00" end_time="20:00" color_by="%s" show_legend="true" allow_booking="false"]',
				esc_attr( $clisyc_view_mode ),
				esc_attr( $clisyc_resources_param ),
                esc_attr( $clisyc_container_height ), // Use the sanitized container height
				esc_attr( $clisyc_color_by )
			) );
			?>
		</div>

		<!-- Keyboard Shortcuts Help -->
		<div class="clisyc-timeline-shortcuts-hint">
			<span class="dashicons dashicons-info-outline"></span>
			<strong><?php esc_html_e( 'Shortcuts:', 'client-sync' ); ?></strong>
			<?php esc_html_e( 'Ctrl + Scroll to zoom in/out • Drag bottom edge to resize', 'client-sync' ); ?>
		</div>

		<style>
		.clisyc-timeline-admin-wrapper {
			background: #fff;
			padding: 20px;
			margin: 20px 0;
			border: 1px solid #ccd0d4;
			box-shadow: 0 1px 1px rgba(0,0,0,.04);
		}
		
		.clisyc-timeline-admin-header {
			margin-bottom: 20px;
		}
		
		.clisyc-timeline-admin-header h2 {
			margin: 0 0 10px 0;
			padding: 0;
			font-size: 23px;
			font-weight: 400;
			line-height: 29px;
		}
		
		.clisyc-timeline-settings-panel {
			background: #f9f9f9;
			border: 1px solid #e5e5e5;
			margin-bottom: 20px;
		}
		
		.clisyc-timeline-settings-toggle {
			padding: 12px 15px;
			cursor: pointer;
			font-weight: 600;
			font-size: 14px;
			display: flex;
			align-items: center;
			gap: 8px;
		}
		
		.clisyc-timeline-settings-toggle:hover {
			background: #f0f0f0;
		}
		
		.clisyc-timeline-settings-content {
			padding: 15px;
			border-top: 1px solid #e5e5e5;
		}
		
		.clisyc-timeline-setting-row {
			margin-bottom: 20px;
		}
		
		.clisyc-timeline-setting-row label {
			display: block;
			margin-bottom: 5px;
		}
		
		.clisyc-timeline-setting-row input[type="checkbox"] {
			margin-right: 5px;
		}
		
		.clisyc-timeline-setting-row select {
			min-width: 250px;
		}
		
		.clisyc-timeline-setting-row .description {
			margin: 5px 0 0 0;
			color: #646970;
		}
		
		.clisyc-timeline-display-area {
			margin: 20px 0 35px 0; /* Extra bottom margin for resize handle */
			background: #ffffff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			box-shadow: 0 1px 1px rgba(0,0,0,.04);
			overflow: visible; /* Allow resize handle to extend below */
			position: relative;
		}
		
		.clisyc-timeline-shortcuts-hint {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 10px 15px;
			background: #f0f6fc;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			font-size: 12px;
			color: #1d2327;
		}
		
		.clisyc-timeline-shortcuts-hint .dashicons {
			color: #2271b1;
			font-size: 16px;
			width: 16px;
			height: 16px;
		}
		
		.clisyc-timeline-height-input {
			display: flex;
			align-items: center;
			gap: 6px;
		}
		
		.clisyc-timeline-height-input input[type="number"] {
			width: 100px;
		}
		
		.clisyc-timeline-height-input .units {
			color: #646970;
			font-size: 13px;
		}
		
		.clisyc-timeline-setting-actions {
			margin-top: 20px;
			padding-top: 15px;
			border-top: 1px solid #e5e5e5;
		}
		
		.clisyc-shortcode-example {
			display: flex;
			align-items: center;
			gap: 10px;
			margin: 10px 0;
			padding: 10px;
			background: #fff;
			border: 1px solid #ddd;
			border-radius: 4px;
		}
		
		.clisyc-shortcode-example code {
			flex: 1;
			background: #f6f7f7;
			padding: 8px 12px;
			border-radius: 3px;
			font-size: 12px;
		}
		
		.clisyc-shortcode-params {
			margin-top: 15px;
			padding: 15px;
			background: #fff;
			border: 1px solid #ddd;
			border-radius: 4px;
		}
		
		.clisyc-shortcode-params h4 {
			margin: 0 0 10px 0;
		}
		
		.clisyc-shortcode-params ul {
			margin: 0;
			padding-left: 20px;
		}
		
		.clisyc-shortcode-params li {
			margin-bottom: 5px;
		}
		
		.clisyc-timeline-help-list {
			list-style: none;
			margin: 15px 0 0 0;
			padding: 0;
		}
		
		.clisyc-timeline-help-list li {
			padding: 12px 0;
			display: flex;
			gap: 10px;
			align-items: flex-start;
			border-bottom: 1px solid #e5e5e5;
		}

		.clisyc-timeline-help-list li:last-child {
			border-bottom: none;
		}
		
		.clisyc-timeline-help-list .dashicons {
			color: #2271b1;
			flex-shrink: 0;
			margin-top: 2px;
			font-size: 18px;
			width: 18px;
			height: 18px;
		}
		
		.clisyc-timeline-help-list li > div {
			flex: 1;
		}
		
		.clisyc-timeline-help-list strong {
			color: #1d2327;
			display: block;
			margin-bottom: 4px;
		}
		</style>


	<?php endif; ?>
</div>