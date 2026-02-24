<?php
/**
 * File: src/shared/includes/admin/views/view-guide-page.php -> client-sync/includes/admin/views/view-guide-page.php
 * Standalone Guide page combining the Setup Checklist with educational content.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variables passed to this view from Dashboard_Manager::render_guide_page():
 * @var array $setup_milestones Data about setup progress, including completion status and URLs.
 */

// Calculate progress percentage for the setup checklist.
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
<div class="wrap clisyc-guide-page">
	<h1><?php esc_html_e( 'Client Sync Guide', 'client-sync' ); ?></h1>
	<p class="about-text">
		<?php esc_html_e( 'Your setup progress and a comprehensive walkthrough of Client Sync features.', 'client-sync' ); ?>
		<a href="https://github.com/hsojhsoj/client-sync-monorepo/wiki" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'View Full Documentation', 'client-sync' ); ?>
			<span class="dashicons dashicons-external" style="text-decoration: none;"></span>
		</a>
	</p>

	<?php // ============================================================== ?>
	<?php // PART A: SETUP CHECKLIST                                        ?>
	<?php // ============================================================== ?>

	<?php if ( $clisyc_progress_percentage >= 100 ) : ?>
		<div class="clisyc-guide-setup-complete">
			<span class="dashicons dashicons-yes-alt"></span>
			<strong><?php esc_html_e( 'Setup complete!', 'client-sync' ); ?></strong>
			<?php esc_html_e( 'All required steps have been finished. Refer to the guide below for advanced configuration.', 'client-sync' ); ?>
		</div>
	<?php else : ?>
		<div id="clisyc-setup-progress-section" class="postbox">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e( 'Setup Checklist', 'client-sync' ); ?></h2>
			</div>
			<div class="inside">
				<p><?php
					/*
					 * translators: %s: The completion percentage number, wrapped in a <strong> tag.
					 */
					$clisyc_progress_text = __( 'You are <strong>%s%%</strong> of the way through the basic setup!', 'client-sync' );
					printf(
						wp_kses(
							$clisyc_progress_text,
							[
								'strong' => [],
							]
						),
						esc_html( $clisyc_progress_percentage )
					);
				?></p>
				<div class="client-sync-progress-bar-container">
					<div class="client-sync-progress-bar" style="width: <?php echo esc_attr( $clisyc_progress_percentage ); ?>%;">
						<?php echo esc_html( $clisyc_progress_percentage ); ?>%
					</div>
				</div>

				<ul class="clisyc-milestone-list">
					<?php foreach ( $setup_milestones as $clisyc_key => $clisyc_milestone ) : ?>
						<li class="clisyc-milestone-item <?php echo $clisyc_milestone['is_complete'] ? 'completed' : 'pending'; ?>">
							<span class="dashicons <?php echo $clisyc_milestone['is_complete'] ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
							<strong><?php echo esc_html( $clisyc_milestone['label'] ); ?></strong>
							<?php if ( $clisyc_milestone['is_optional'] ?? false ) : ?>
								<em>(<?php esc_html_e( 'Optional', 'client-sync' ); ?>)</em>
							<?php endif; ?>

							<p class="description"><?php echo wp_kses_post( $clisyc_milestone['description'] ); ?></p>

							<div class="clisyc-milestone-actions">
								<?php if ( ! $clisyc_milestone['is_complete'] ) : ?>
									<a href="<?php echo esc_url( $clisyc_milestone['next_step_url'] ); ?>" class="button button-small <?php echo ( $clisyc_key === $clisyc_first_incomplete_milestone_key ) ? 'button-primary' : 'button-secondary'; ?>">
										<?php echo esc_html( $clisyc_milestone['next_step_label'] ); ?>
									</a>
									<?php if ( $clisyc_milestone['is_manual_completion'] ?? false ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-left: 5px;">
											<input type="hidden" name="action" value="clisyc_mark_milestone_complete">
											<input type="hidden" name="milestone_key" value="<?php echo esc_attr( $clisyc_key ); ?>">
											<?php wp_nonce_field( 'clisyc_mark_milestone_' . $clisyc_key ); ?>
											<button type="submit" class="button button-small">
												<?php esc_html_e( 'Mark as Done', 'client-sync' ); ?>
											</button>
										</form>
									<?php endif; ?>
								<?php elseif ( ! empty( $clisyc_milestone['settings_url'] ) ) : ?>
									<a href="<?php echo esc_url( $clisyc_milestone['settings_url'] ); ?>" class="button button-small button-secondary">
										<?php esc_html_e( 'View/Edit Settings', 'client-sync' ); ?>
									</a>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	<?php endif; ?>

	<?php // ============================================================== ?>
	<?php // PART B: EDUCATIONAL CONTENT                                    ?>
	<?php // ============================================================== ?>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Core Concept: The Dimension Architecture', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<p><?php esc_html_e( 'Client Sync is built on a flexible "Dimension" system. Think of dimensions as the building blocks of your booking system, like LEGO bricks. You define what each block represents.', 'client-sync' ); ?></p>
			<ul>
				<li>
					<strong><?php esc_html_e( 'What are Dimensions?', 'client-sync' ); ?></strong>
					<?php
					printf(
						/* translators: %s: HTML link to the System Setup page. */
						esc_html__( 'They are categories of things that affect a booking. Common examples are "Services", "Staff", "Locations", or "Rentable Equipment". You create these on the %s page.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-dimensions&tab=setup' ) ) . '">' . esc_html__( 'System Setup', 'client-sync' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'What is a Primary Dimension?', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'You must choose ONE dimension to be the primary source of truth for schedules. This is usually your main service or bookable item (e.g., "Services", "Properties", "Classes"). The system uses the schedule from an item in this dimension to generate initial availability.', 'client-sync' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'What are Resources? (Pro)', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'A Resource is any dimension that has its own limited availability and constrains the Primary Dimension. An "Employee" or "Room" is a perfect example. A booking is only possible if the Service is available AND the required Resource is also available at the same time.', 'client-sync' ); ?>
				</li>
			</ul>
		</div>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Recommended Setup Flow', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<ol>
				<li>
					<strong><?php esc_html_e( 'Run the Setup Wizard:', 'client-sync' ); ?></strong>
					<?php
					printf(
						/* translators: %s: HTML link to the Import / Export tab. */
						esc_html__( 'The easiest way to start is by installing a pre-configured template from the Setup Wizard. You can always access it from the %s tab. This creates the necessary Dimensions for you.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=import_export' ) ) . '">' . esc_html__( 'Import / Export', 'client-sync' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Create Your Items:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'Go to the newly created menu items under the main "Client Sync" menu (e.g., "Services", "Properties", "Stylists"). Add your specific items as new posts (e.g., create a "Standard Consultation" service or a "Downtown Loft" property).', 'client-sync' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Set Availability:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'Edit an item from your Primary Dimension (e.g., "Standard Consultation"). Find the "Base Weekly Schedule" box and visually draw the time blocks when this service is offered. Do the same for any Resource Dimensions (e.g., edit "Consulting Room 1" and set its hours).', 'client-sync' ); ?>
					<ul>
						<li>
							<strong><?php esc_html_e( 'Method 1 (Automatic):', 'client-sync' ); ?></strong>
							<?php esc_html_e( 'Draw large blocks of availability (e.g., 9am-5pm). Then, in the "Primary Attributes" box, set the "Appointment Duration" and "Padding / Cleanup Time". The system will automatically fill the large block with correctly sized and spaced slots.', 'client-sync' ); ?>
							<strong><?php esc_html_e( '(Recommended)', 'client-sync' ); ?></strong>
						</li>
						<li>
							<strong><?php esc_html_e( 'Method 2 (Manual):', 'client-sync' ); ?></strong>
							<?php esc_html_e( 'If you don\'t set a duration, you must draw each individual slot exactly as you want it to appear on the calendar (e.g., draw a slot from 9:00 to 9:45, another from 10:00 to 10:45, etc.).', 'client-sync' ); ?>
						</li>
					</ul>
				</li>
				<li>
					<strong><?php esc_html_e( 'Link Everything Together:', 'client-sync' ); ?></strong>
					<?php
					printf(
						/* translators: %s: HTML link to the Relationship Graph. */
						esc_html__( 'Go to %s. This is where you connect the dots. Drag a line from a staff member to the services they perform, or from a service to the room it requires. A booking is only possible if a valid path exists between all selected items.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-dimensions&tab=graph' ) ) . '"><strong>' . esc_html__( 'Dimensions > Relationship Graph', 'client-sync' ) . '</strong></a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Add the Booking Form to a Page:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'Edit a WordPress page (e.g., your "Book Now" page) and add the universal shortcode:', 'client-sync' ); ?>
					<code>[clisyc_booking_form]</code>
					<ul>
						<li>
							<?php
							printf(
								/* translators: %s: HTML link to the Behavior settings tab. */
								esc_html__( 'The default display (time-slot calendar vs. date-range search) can be configured under the %s settings tab.', 'client-sync' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=behavior' ) ) . '">' . esc_html__( 'Behavior', 'client-sync' ) . '</a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'You can force a mode on a specific page:', 'client-sync' ); ?>
							<code>[clisyc_booking_form mode="calendar"]</code> <?php esc_html_e( 'or', 'client-sync' ); ?>
							<code>[clisyc_booking_form mode="search"]</code>
						</li>
					</ul>
				</li>
				<li>
					<strong><?php esc_html_e( 'Set Up a Search Results Page (for Multi-Day Rentals):', 'client-sync' ); ?></strong>
					<?php
					printf(
						/* translators: 1: Shortcode. 2: HTML link to the Behavior settings tab. */
						esc_html__( 'If you offer multi-day rentals, create a new page (e.g., "Our Properties"). Add the %1$s shortcode to it. Then, go to %2$s and select this page in the "Search Results Page" dropdown.', 'client-sync' ),
						'<code>[clisyc_search_results]</code>',
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=behavior' ) ) . '"><strong>' . esc_html__( 'Settings > Behavior', 'client-sync' ) . '</strong></a>'
					);
					?>
				</li>
			</ol>
		</div>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Seat Selection & Venues (Pro)', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<p><?php esc_html_e( 'The Seat Selection module lets customers choose specific seats from an interactive SVG venue map during the booking process. This is ideal for theaters, event venues, classrooms, or any scenario requiring assigned seating.', 'client-sync' ); ?></p>
			<ol>
				<li>
					<strong><?php esc_html_e( 'Create a Venue:', 'client-sync' ); ?></strong>
					<?php
					if ( post_type_exists( 'clisyc_venue' ) ) {
						printf(
							/* translators: %s: HTML link to the Venues list. */
							esc_html__( 'Go to %s and click "Add New". Upload or paste an SVG floor plan created in a tool like Illustrator, Inkscape, or Figma. The plugin parses element naming to automatically detect sections, rows, and individual seats.', 'client-sync' ),
							'<a href="' . esc_url( admin_url( 'edit.php?post_type=clisyc_venue' ) ) . '"><strong>' . esc_html__( 'Client Sync > Venues', 'client-sync' ) . '</strong></a>'
						);
					} else {
						esc_html_e( 'Go to Client Sync > Venues and click "Add New". Upload or paste an SVG floor plan created in a tool like Illustrator, Inkscape, or Figma. The plugin parses element naming to automatically detect sections, rows, and individual seats.', 'client-sync' );
					}
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Choose a Parsing Mode:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'Two modes are available depending on how your SVG is structured:', 'client-sync' ); ?>
					<ul>
						<li><strong><?php esc_html_e( 'Flat IDs:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Seat elements use compound IDs with a delimiter (e.g., "sectionA-row1-seat5"). Best for simple, hand-coded SVGs.', 'client-sync' ); ?></li>
						<li><strong><?php esc_html_e( 'Nested Groups:', 'client-sync' ); ?></strong> <?php esc_html_e( 'The SVG uses nested layers for sections, rows, and seats. Best for files exported from Illustrator or Inkscape.', 'client-sync' ); ?></li>
					</ul>
				</li>
				<li>
					<strong><?php esc_html_e( 'Configure Pricing Tiers:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'On the venue edit screen, define pricing tiers by seat category (e.g., VIP, General, Balcony). Prices are added as line items to the booking total when WooCommerce payments are enabled.', 'client-sync' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Link a Venue to a Service:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'Edit a primary dimension item (e.g., an event or class). Use the "Seat Selection - Linked Venue" meta box in the sidebar to associate a venue. When customers book this item, the seat map will appear automatically.', 'client-sync' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Venue Location & Photo:', 'client-sync' ); ?></strong>
					<?php
					if ( post_type_exists( 'clisyc_venue' ) ) {
						printf(
							/* translators: %s: HTML link to the Venues list. */
							esc_html__( 'On the %s edit screen, fill in the "Venue Location" meta box with address, city, state, postal code, and country. You can also provide a Google Maps embed URL for an interactive map. Set a Featured Image for the venue to display a photo. Both the address and photo appear automatically on the appointment detail page for customers.', 'client-sync' ),
							'<a href="' . esc_url( admin_url( 'edit.php?post_type=clisyc_venue' ) ) . '">' . esc_html__( 'venue', 'client-sync' ) . '</a>'
						);
					} else {
						esc_html_e( 'On the venue edit screen, fill in the "Venue Location" meta box with address, city, state, postal code, and country. You can also provide a Google Maps embed URL for an interactive map. Set a Featured Image for the venue to display a photo. Both the address and photo appear automatically on the appointment detail page for customers.', 'client-sync' );
					}
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Seat Availability Heatmap:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'When customers open the booking form and view the overview SVG map, sections are colour-coded by how full they are: green for mostly available, amber for filling up, and red for nearly sold out. The colours animate in smoothly as availability data loads. A legend below the map explains the scale.', 'client-sync' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Try the Template:', 'client-sync' ); ?></strong>
					<?php
					printf(
						/* translators: %s: HTML link to the Import / Export tab. */
						esc_html__( 'The "Event Venue / Theater (Seat Selection)" template is available in the %s tab. It bootstraps a working configuration with sample events, a 14-seat venue, VIP and General pricing tiers, and output templates.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=import_export' ) ) . '">' . esc_html__( 'Import / Export', 'client-sync' ) . '</a>'
					);
					?>
				</li>
			</ol>
		</div>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Check-In & Event Management (Pro)', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<p>
				<?php
				printf(
					/* translators: %s: HTML link to the Check-In admin page. */
					esc_html__( 'The Check-In system provides a dedicated admin page for managing guest arrivals at events. Access it from the "Check-In" row action on any appointment, or from the %s submenu item under Client Sync.', 'client-sync' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-checkin' ) ) . '"><strong>' . esc_html__( 'Check-In', 'client-sync' ) . '</strong></a>'
				);
				?>
			</p>
			<ul>
				<li>
					<strong><?php esc_html_e( 'Event Filter & Live Clock:', 'client-sync' ); ?></strong>
					<?php
					printf(
						/* translators: %s: HTML link to the Check-In page. */
						esc_html__( 'The %s page features a live clock and an event filter dropdown. When multiple events fall on the same day, select one to scope the stats and guest list to just that event. The banner shows the event name, venue, start time, and a live countdown.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-checkin' ) ) . '">' . esc_html__( 'check-in', 'client-sync' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Dashboard Stats:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'Live stats at the top show Checked In, Expected, Remaining, and the check-in rate per hour. Stats auto-refresh every 15 seconds, update instantly after each check-in, and respect the event filter.', 'client-sync' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'QR Code Scanner:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'Use the built-in camera scanner to scan QR code tickets. Point your device camera at a guest\'s QR code and the system instantly identifies them, displays their details, and provides a one-tap check-in button.', 'client-sync' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Door List & Bulk Check-In:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'Toggle between the card view and a sortable door list table. The table supports selecting multiple guests via checkboxes and checking them all in at once. Use the toolbar buttons to export the guest list as a CSV file or print a clean, formatted door list.', 'client-sync' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Sound & Haptic Feedback:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'On check-in success, a confirmation chime plays and the device vibrates briefly (on supported devices). Error conditions produce a distinct buzz. Visual flash feedback also highlights the checked-in card. This is especially useful when scanning guests rapidly on a tablet at the door.', 'client-sync' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Undo Check-In:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'Checked someone in by mistake? Each checked-in guest shows a small "Undo Check-In" button. Clicking it reverses the check-in, restoring the appointment to Confirmed status so it can be checked in again later.', 'client-sync' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Seat Transfer & Reassignment:', 'client-sync' ); ?></strong>
					<?php
					printf(
						/* translators: %s: HTML link to the Appointments list. */
						esc_html__( 'On the %s edit screen, the "Seat Transfer" meta box in the sidebar lets you release individual seats or assign new ones. This is useful for handling last-minute seat changes, upgrades, or resolving double-bookings without cancelling the entire appointment.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'edit.php?post_type=clisyc_appointment' ) ) . '">' . esc_html__( 'appointment', 'client-sync' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Automatic Date Switching:', 'client-sync' ); ?></strong>
					<?php esc_html_e( 'If the check-in page is left open overnight (e.g., for a multi-day event), the date automatically advances at midnight so the guest list refreshes for the new day.', 'client-sync' ); ?>
				</li>
			</ul>
		</div>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'QR Tickets & Email Placeholders (Pro)', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<p>
				<?php
				printf(
					/* translators: %s: HTML link to the Notifications settings tab. */
					esc_html__( 'When the Seat Selection module is active, additional email placeholders become available for your %s templates.', 'client-sync' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=notifications' ) ) . '">' . esc_html__( 'notification', 'client-sync' ) . '</a>'
				);
				?>
			</p>
			<ul>
				<li>
					<strong><code>{ticket_qr_code}</code></strong> &mdash;
					<?php
					printf(
						/* translators: %s: HTML link to the Notifications settings tab. */
						esc_html__( 'Inserts a scannable QR code image into the email. The QR encodes the appointment\'s unique check-in token. Add this placeholder to your confirmation or reminder %s so guests receive a ticket they can show at the door. The QR image is generated as a PNG and cached on the server, so it works in all email clients.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=notifications' ) ) . '">' . esc_html__( 'email templates', 'client-sync' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><code>{venue_name}</code></strong> &mdash;
					<?php esc_html_e( 'The name of the venue linked to the appointment (e.g., "Grand Ballroom").', 'client-sync' ); ?>
				</li>
				<li>
					<strong><code>{venue_address}</code></strong> &mdash;
					<?php
					if ( post_type_exists( 'clisyc_venue' ) ) {
						printf(
							/* translators: %s: HTML link to the Venues list. */
							esc_html__( 'The full formatted address of the venue, including street, city, state, postal code, and country. Configure these fields in the "Venue Location" meta box when editing a %s.', 'client-sync' ),
							'<a href="' . esc_url( admin_url( 'edit.php?post_type=clisyc_venue' ) ) . '">' . esc_html__( 'venue', 'client-sync' ) . '</a>'
						);
					} else {
						esc_html_e( 'The full formatted address of the venue, including street, city, state, postal code, and country.', 'client-sync' );
					}
					?>
				</li>
				<li>
					<strong><code>{venue_map_link}</code></strong> &mdash;
					<?php esc_html_e( 'A Google Maps link that opens directions to the venue address. Useful in confirmation emails so guests can navigate directly.', 'client-sync' ); ?>
				</li>
			</ul>
			<p>
				<?php
				printf(
					/* translators: %s: HTML link to the Notifications settings tab. */
					esc_html__( 'Add these placeholders to your email templates under %s. They are available for all appointment-related events (confirmations, reminders, status changes, cancellations, and reschedules).', 'client-sync' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=notifications' ) ) . '">' . esc_html__( 'Settings > Notifications', 'client-sync' ) . '</a>'
				);
				?>
			</p>
		</div>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Recurring Subscriptions & Memberships (Pro)', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<p>
				<?php esc_html_e( 'Create membership plans with booking limits and discounts, then let your clients subscribe and pay automatically through Stripe. No WooCommerce required.', 'client-sync' ); ?>
			</p>
			<ol>
				<li>
					<strong><?php esc_html_e( 'Create Membership Plans', 'client-sync' ); ?></strong> &mdash;
					<?php
					if ( post_type_exists( 'clisyc_member_plan' ) ) {
						printf(
							/* translators: %s: HTML link to the Memberships list. */
							esc_html__( 'Go to %s and create your plans. Each plan can have booking limits (e.g., 4 bookings per month) and discounts (e.g., 10%% off all services). Use the "Create Starter Plans" button for ready-made templates.', 'client-sync' ),
							'<a href="' . esc_url( admin_url( 'edit.php?post_type=clisyc_member_plan' ) ) . '">' . esc_html__( 'Memberships', 'client-sync' ) . '</a>'
						);
					} else {
						esc_html_e( 'Go to Memberships and create your plans. Each plan can have booking limits and discounts. Requires the Client Sync Pro plugin.', 'client-sync' );
					}
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Connect Stripe', 'client-sync' ); ?></strong> &mdash;
					<?php
					printf(
						/* translators: %s: HTML link to the Payment settings. */
						esc_html__( 'Enter your Stripe API keys under %s. Enable the Stripe Direct integration and configure your webhook endpoint.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=payments' ) ) . '">' . esc_html__( 'Payment Settings', 'client-sync' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Link Stripe Price IDs', 'client-sync' ); ?></strong> &mdash;
					<?php esc_html_e( 'In your Stripe Dashboard, create a recurring Product with a Price for each plan. Copy the Price ID (price_xxx) and paste it into the "Stripe Subscription Link" meta box when editing each membership plan.', 'client-sync' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Display Plans on Your Site', 'client-sync' ); ?></strong> &mdash;
					<?php
					printf(
						/* translators: %s: the shortcode tag. */
						esc_html__( 'Add the %s shortcode to any page. It renders a responsive pricing card grid where visitors can compare plans and subscribe directly via Stripe Checkout.', 'client-sync' ),
						'<code>[clisyc_membership_plans]</code>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Configure Stripe Webhooks', 'client-sync' ); ?></strong> &mdash;
					<?php esc_html_e( 'In the Stripe Dashboard, add a webhook endpoint pointing to the URL shown on your Payment Settings page. Include the subscription and invoice events so the plugin can automatically activate, renew, and cancel memberships.', 'client-sync' ); ?>
				</li>
			</ol>
			<p>
				<?php
				printf(
					/* translators: %s: the shortcode tag. */
					esc_html__( 'Once subscribed, your clients can view their membership status, usage, and manage their subscription from the %s shortcode on your account page.', 'client-sync' ),
					'<code>[clisyc_user_account]</code>'
				);
				?>
			</p>
		</div>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Advanced Configuration & Pro Features', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<ul>
				<li>
					<strong><?php esc_html_e( 'Payments:', 'client-sync' ); ?></strong>
					<?php
					printf(
						/* translators: %s: HTML link to the Payments settings tab. */
						esc_html__( 'To accept payments, enable the WooCommerce integration under the %s tab. Then, create a "Simple" and "Virtual" product in WooCommerce for each of your primary services and link them in the service\'s edit screen.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=payments' ) ) . '">' . esc_html__( 'Payments', 'client-sync' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Custom Fields:', 'client-sync' ); ?></strong>
					<?php
					printf(
						/* translators: %s: HTML link to the Custom Fields page. */
						esc_html__( 'Add custom fields for your clients or for specific bookings under the %s page. Use conditional logic to show booking fields only for certain services.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-custom-fields' ) ) . '">' . esc_html__( 'Custom Fields', 'client-sync' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Manager Dashboards:', 'client-sync' ); ?></strong>
					<?php
					printf(
						/* translators: %s: HTML link to the Shortcodes settings tab. */
						esc_html__( 'Use the manager shortcodes (found in the %s tab) to create private pages for your staff to manage appointments from the frontend.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=shortcodes' ) ) . '">' . esc_html__( 'Shortcodes', 'client-sync' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Google Calendar Sync (Pro):', 'client-sync' ); ?></strong>
					<?php
					printf(
						/* translators: %s: HTML link to the System Setup page. */
						esc_html__( 'To enable two-way sync, first go to the %s page. Mark the dimension that represents your staff (e.g., "Employees", "Practitioners") as a "Resource" and also select it as the "Personnel" dimension. Then, edit an individual staff member, link them to a WordPress user, and follow the prompts to connect their Google account.', 'client-sync' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-dimensions&tab=setup' ) ) . '">' . esc_html__( 'System Setup', 'client-sync' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Seat Selection (Pro):', 'client-sync' ); ?></strong>
					<?php
					if ( post_type_exists( 'clisyc_venue' ) ) {
						printf(
							/* translators: %s: HTML link to the Venues list. */
							esc_html__( 'Create interactive venue maps with assigned seating. See the dedicated section above, or go directly to %s.', 'client-sync' ),
							'<a href="' . esc_url( admin_url( 'edit.php?post_type=clisyc_venue' ) ) . '">' . esc_html__( 'Venues', 'client-sync' ) . '</a>'
						);
					} else {
						esc_html_e( 'Create interactive venue maps with assigned seating. Requires the Client Sync Pro plugin with the Seat Selection module active.', 'client-sync' );
					}
					?>
				</li>
			</ul>
		</div>
	</div>
</div>
