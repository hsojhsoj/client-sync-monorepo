<?php
/**
 * File: src/shared/includes/admin/views/view-directions-page.php -> client-sync/includes/admin/views/view-directions-page.php
 * View for the Directions & Setup Guide tab in the settings.
 * MODIFIED: Added instructions for designating a Personnel Dimension for Google Sync.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap clisyc-directions-page">
	<h1><?php esc_html_e( 'Client Sync Directions & Setup Guide', 'client-sync' ); ?></h1>
	<p class="about-text"><?php esc_html_e( 'Follow this guide to understand the core concepts and the recommended workflow for setting up your booking system.', 'client-sync' ); ?></p>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Core Concept: The Dimension Architecture', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<p><?php esc_html_e( 'Client Sync is built on a flexible "Dimension" system. Think of dimensions as the building blocks of your booking system, like LEGO® bricks. You define what each block represents.', 'client-sync' ); ?></p>
			<ul>
				<li><strong>What are Dimensions?</strong> They are categories of things that affect a booking. Common examples are "Services", "Staff", "Locations", or "Rentable Equipment". You create these in the "System Setup" tab on the Dimensions page.</li>
				<li><strong>What is a Primary Dimension?</strong> You must choose ONE dimension to be the primary source of truth for schedules. This is usually your main service or bookable item (e.g., "Services", "Properties", "Classes"). The system uses the schedule from an item in this dimension to generate initial availability.</li>
				<li><strong>What are Resources? (Pro)</strong> A Resource is any dimension that has its own limited availability and constrains the Primary Dimension. An "Employee" or "Room" is a perfect example. A booking is only possible if the Service is available AND the required Resource is also available at the same time.</li>
			</ul>
		</div>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Recommended Setup Flow', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<ol>
				<li>
					<strong>Run the Setup Wizard:</strong> The easiest way to start is by installing a pre-configured template from the Setup Wizard. You can always access it from the "Import / Export" tab. This creates the necessary Dimensions for you.
				</li>
				<li>
					<strong>Create Your Items:</strong> Go to the newly created menu items under the main "Client Sync" menu (e.g., "Services", "Properties", "Stylists"). Add your specific items as new posts (e.g., create a "Standard Consultation" service or a "Downtown Loft" property).
				</li>
				<li>
					<strong>Set Availability:</strong> Edit an item from your **Primary Dimension** (e.g., "Standard Consultation"). Find the "Base Weekly Schedule" box and visually draw the time blocks when this service is offered. Do the same for any **Resource Dimensions** (e.g., edit "Consulting Room 1" and set its hours).
					<ul>
						<li><strong>Method 1 (Automatic):</strong> Draw large blocks of availability (e.g., 9am-5pm). Then, in the "Primary Attributes" box, set the "Appointment Duration" and "Padding / Cleanup Time". The system will automatically fill the large block with correctly sized and spaced slots. <strong>(Recommended)</strong></li>
						<li><strong>Method 2 (Manual):</strong> If you don't set a duration, you must draw each individual slot exactly as you want it to appear on the calendar (e.g., draw a slot from 9:00 to 9:45, another from 10:00 to 10:45, etc.).</li>
					</ul>
				</li>
				<li>
					<strong>Link Everything Together:</strong> Go to <strong>Client Sync > Dimensions > Relationship Graph</strong>. This is where you connect the dots. Drag a line from a staff member to the services they perform, or from a service to the room it requires. A booking is only possible if a valid path exists between all selected items.
				</li>
				<li>
					<strong>Add the Booking Form to a Page:</strong> Edit a WordPress page (e.g., your "Book Now" page) and add the universal shortcode: <code>[clisyc_booking_form]</code>.
					<ul>
						<li>The default display (time-slot calendar vs. date-range search) can be configured under the <strong>Style & Behavior</strong> settings tab.</li>
						<li>You can force a mode on a specific page: <code>[clisyc_booking_form mode="calendar"]</code> or <code>[clisyc_booking_form mode="search"]</code></li>
					</ul>
				</li>
				<li>
					<strong>Set Up a Search Results Page (for Multi-Day Rentals):</strong> If you offer multi-day rentals, create a new page (e.g., "Our Properties"). Add the `[clisyc_search_results]` shortcode to it. Then, go to <strong>Client Sync > Settings > Style & Behavior</strong> and select this page in the "Search Results Page" dropdown.
				</li>
			</ol>
		</div>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Advanced Configuration & Pro Features', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<ul>
				<li><strong>Payments:</strong> To accept payments, enable the WooCommerce integration under the <a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=payments' ) ); ?>">Payments</a> tab. Then, create a "Simple" and "Virtual" product in WooCommerce for each of your primary services and link them in the service's edit screen.</li>
				<li><strong>Custom Fields:</strong> Add custom fields for your clients or for specific bookings under the <a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-custom-fields' ) ); ?>">Custom Fields</a> page. Use conditional logic to show booking fields only for certain services.</li>
                <li><strong>Memberships (Pro):</strong> Create recurring revenue streams. Go to <strong>Client Sync > Forms</strong>, click "Add New" and select "Membership Plan". Define rules like booking limits or discounts for specific services. Then, go to a user's profile in WordPress and assign them the plan you created. The system will automatically enforce the rules on the booking calendar.</li>
				<li><strong>Manager Dashboards:</strong> Use the manager shortcodes (found in the <a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=shortcodes' ) ); ?>">Shortcodes</a> tab) to create private pages for your staff to manage appointments from the frontend.</li>
				<li><strong>Google Calendar Sync (Pro):</strong> To enable two-way sync, first go to the <a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-dimensions&tab=setup' ) ); ?>">System Setup</a> page. Mark the dimension that represents your staff (e.g., "Employees", "Practitioners") as a "Resource" and also select it as the "Personnel" dimension. Then, edit an individual staff member, link them to a WordPress user, and follow the prompts to connect their Google account.</li>
			</ul>
		</div>
	</div>
</div>