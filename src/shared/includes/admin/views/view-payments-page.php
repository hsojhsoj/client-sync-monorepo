<?php
/**
 * File: src/shared/includes/admin/views/view-payments-page.php -> client-sync/includes/admin/views/view-payments-page.php
 * View for the Payments Settings admin page.
 *
 * This file renders the settings page where administrators can configure
 * the integration with WooCommerce for handling appointment payments.
 * MODIFIED: This view has been updated to reflect the new per-service product
 * linking architecture and remove the deprecated global product ID field.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wrap clisyc-payments-settings">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<p><?php esc_html_e( 'Configure how payments for appointments are collected. You can use WooCommerce, Stripe Checkout, or accept bookings without payment.', 'client-sync' ); ?></p>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'clisyc_payments_settings_group' );
		do_settings_sections( 'clisyc-payments' );
		submit_button( __( 'Save Payment Settings', 'client-sync' ) );
		?>
	</form>

	<hr>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Setup Instructions for Paid Bookings', 'client-sync' ); ?></span></h2>
		<div class="inside">
			<p><?php esc_html_e( 'Client Sync now links each individual service (or other primary dimension item) directly to its own WooCommerce product. This allows for unique pricing, inventory management, and reporting for each service you offer.', 'client-sync' ); ?></p>
			
			<div class="clisyc-admin-instructions">
				<h4><?php esc_html_e( 'Step-by-Step Setup', 'client-sync' ); ?></h4>
				<ol>
					<li><?php echo wp_kses_post( __( 'Ensure the <strong>Enable WooCommerce Payments</strong> checkbox above is checked and saved.', 'client-sync' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Go to <strong>WooCommerce > Products > Add New</strong>.', 'client-sync' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Create a new product. It is crucial that you set it as a <strong>Simple</strong> and <strong>Virtual</strong> product.', 'client-sync' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'In the "General" tab of the Product Data, set the <strong>Regular price</strong>. This will be the base price for your service (e.g., the price for a single day/night for multi-day bookings).', 'client-sync' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Publish the WooCommerce product.', 'client-sync' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Navigate to your Primary Dimension (e.g., <strong>Client Sync > Services</strong>) and edit the service you want to link.', 'client-sync' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'In the "WooCommerce Integration" box, search for and select the product you just created.', 'client-sync' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Update the service. It is now a bookable, paid item!', 'client-sync' ) ); ?></li>
				</ol>
			</div>

			<h4 style="margin-top: 1.5em;"><?php esc_html_e( 'Advanced Pricing', 'client-sync' ); ?></h4>
			<p><?php echo wp_kses_post( __( 'For multi-day bookings, you can set up advanced pricing rules (like weekend surcharges or weekly discounts) directly on the <strong>WooCommerce Product edit screen</strong> in the new "Client Sync: Dynamic Pricing Rules" panel.', 'client-sync' ) ); ?></p>
		</div>
	</div>
</div>