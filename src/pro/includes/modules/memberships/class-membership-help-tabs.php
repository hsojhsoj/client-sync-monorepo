<?php
/**
 * File: src/pro/includes/modules/memberships/class-membership-help-tabs.php
 * Adds contextual help documentation to the Membership Plans admin screens.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Memberships
 */

namespace ClientSyncPro\Modules\Memberships;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Membership_Help_Tabs
 *
 * Registers WordPress Help tabs for the Membership Plans CPT screens.
 */
class Membership_Help_Tabs {

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'current_screen', [ $this, 'add_help_tabs' ] );
	}

	/**
	 * Add help tabs to the membership plan screens.
	 *
	 * @param \WP_Screen $screen The current screen object.
	 */
	public function add_help_tabs( $screen ) {
		// Only add to membership plan screens.
		if ( ! in_array( $screen->id, [ 'clisyc_member_plan', 'edit-clisyc_member_plan' ], true ) ) {
			return;
		}

		// Different tabs for list view vs edit view.
		if ( 'edit-clisyc_member_plan' === $screen->id ) {
			$this->add_list_view_help_tabs( $screen );
		} else {
			$this->add_edit_view_help_tabs( $screen );
		}

		// Add sidebar to both views.
		$screen->set_help_sidebar( $this->get_help_sidebar() );
	}

	/**
	 * Add help tabs for the membership plans list view.
	 *
	 * @param \WP_Screen $screen The current screen object.
	 */
	private function add_list_view_help_tabs( $screen ) {
		$screen->add_help_tab( [
			'id'      => 'clisyc_membership_overview',
			'title'   => __( 'Overview', 'client-sync-pro' ),
			'content' => $this->get_overview_content(),
		] );

		$screen->add_help_tab( [
			'id'      => 'clisyc_membership_how_it_works',
			'title'   => __( 'How It Works', 'client-sync-pro' ),
			'content' => $this->get_how_it_works_content(),
		] );

		// Only show WooCommerce tab if the plugins are active.
		if ( class_exists( 'WooCommerce' ) && class_exists( 'WC_Subscriptions' ) ) {
			$screen->add_help_tab( [
				'id'      => 'clisyc_membership_woocommerce',
				'title'   => __( 'WooCommerce Integration', 'client-sync-pro' ),
				'content' => $this->get_woocommerce_content(),
			] );
		}
	}

	/**
	 * Add help tabs for the membership plan edit view.
	 *
	 * @param \WP_Screen $screen The current screen object.
	 */
	private function add_edit_view_help_tabs( $screen ) {
		$screen->add_help_tab( [
			'id'      => 'clisyc_membership_creating_plan',
			'title'   => __( 'Creating a Plan', 'client-sync-pro' ),
			'content' => $this->get_creating_plan_content(),
		] );

		$screen->add_help_tab( [
			'id'      => 'clisyc_membership_rule_types',
			'title'   => __( 'Rule Types', 'client-sync-pro' ),
			'content' => $this->get_rule_types_content(),
		] );

		$screen->add_help_tab( [
			'id'      => 'clisyc_membership_examples',
			'title'   => __( 'Examples', 'client-sync-pro' ),
			'content' => $this->get_examples_content(),
		] );

		// Only show WooCommerce tab if the plugins are active.
		if ( class_exists( 'WooCommerce' ) && class_exists( 'WC_Subscriptions' ) ) {
			$screen->add_help_tab( [
				'id'      => 'clisyc_membership_wc_link',
				'title'   => __( 'Subscription Link', 'client-sync-pro' ),
				'content' => $this->get_subscription_link_content(),
			] );
		}
	}

	/**
	 * Get the help sidebar content.
	 *
	 * @return string HTML content for the sidebar.
	 */
	private function get_help_sidebar() {
		$content  = '<p><strong>' . __( 'For more information:', 'client-sync-pro' ) . '</strong></p>';
		$content .= '<p><a href="https://developer.clientsync.dev/docs/memberships" target="_blank">' . __( 'Membership Documentation', 'client-sync-pro' ) . '</a></p>';
		$content .= '<p><a href="https://developer.clientsync.dev/docs/woocommerce" target="_blank">' . __( 'WooCommerce Setup', 'client-sync-pro' ) . '</a></p>';
		$content .= '<p><a href="https://developer.clientsync.dev/support" target="_blank">' . __( 'Get Support', 'client-sync-pro' ) . '</a></p>';
		return $content;
	}

	/**
	 * Overview tab content.
	 *
	 * @return string HTML content.
	 */
	private function get_overview_content() {
		$content  = '<h3>' . __( 'Membership Plans', 'client-sync-pro' ) . '</h3>';
		$content .= '<p>' . __( 'Membership Plans allow you to offer tiered access to your booking services with booking limits, discounts, and automated billing through WooCommerce Subscriptions.', 'client-sync-pro' ) . '</p>';
		
		$content .= '<h4>' . __( 'Key Features', 'client-sync-pro' ) . '</h4>';
		$content .= '<ul>';
		$content .= '<li><strong>' . __( 'Booking Limits', 'client-sync-pro' ) . '</strong> — ' . __( 'Restrict how many appointments a member can book per week, month, or year.', 'client-sync-pro' ) . '</li>';
		$content .= '<li><strong>' . __( 'Percentage Discounts', 'client-sync-pro' ) . '</strong> — ' . __( 'Offer members automatic percentage discounts on services.', 'client-sync-pro' ) . '</li>';
		$content .= '<li><strong>' . __( 'Fixed Amount Discounts', 'client-sync-pro' ) . '</strong> — ' . __( 'Reduce prices by a fixed dollar amount.', 'client-sync-pro' ) . '</li>';
		$content .= '<li><strong>' . __( 'Service-Specific Rules', 'client-sync-pro' ) . '</strong> — ' . __( 'Apply different rules to different services within a single plan.', 'client-sync-pro' ) . '</li>';
		$content .= '<li><strong>' . __( 'Automated Billing', 'client-sync-pro' ) . '</strong> — ' . __( 'Link plans to WooCommerce Subscription products for automatic membership management.', 'client-sync-pro' ) . '</li>';
		$content .= '</ul>';

		return $content;
	}

	/**
	 * How It Works tab content.
	 *
	 * @return string HTML content.
	 */
	private function get_how_it_works_content() {
		$content  = '<h3>' . __( 'How Membership Plans Work', 'client-sync-pro' ) . '</h3>';
		
		$content .= '<h4>' . __( '1. Create a Plan', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( 'Click "Add New Plan" and define access rules. Each rule specifies a limit or discount that applies to specific services or all services.', 'client-sync-pro' ) . '</p>';

		$content .= '<h4>' . __( '2. Assign Members', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( 'Members can be assigned to a plan in two ways:', 'client-sync-pro' ) . '</p>';
		$content .= '<ul>';
		$content .= '<li><strong>' . __( 'Manual Assignment', 'client-sync-pro' ) . '</strong> — ' . __( 'Go to Users → Edit User and select a plan from the "Client Sync Membership" section.', 'client-sync-pro' ) . '</li>';
		$content .= '<li><strong>' . __( 'Automatic via WooCommerce', 'client-sync-pro' ) . '</strong> — ' . __( 'Link the plan to a WooCommerce Subscription product. When a customer subscribes, they are automatically assigned.', 'client-sync-pro' ) . '</li>';
		$content .= '</ul>';

		$content .= '<h4>' . __( '3. Automatic Enforcement', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( 'When a member visits the booking form:', 'client-sync-pro' ) . '</p>';
		$content .= '<ul>';
		$content .= '<li>' . __( 'The system checks their plan rules against the selected service.', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( 'If a discount applies, the price is automatically reduced and displayed.', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( 'If they have reached their booking limit, they see a "Limit Reached" message.', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( 'Members see their usage (e.g., "3 of 5 used this month").', 'client-sync-pro' ) . '</li>';
		$content .= '</ul>';

		return $content;
	}

	/**
	 * WooCommerce Integration tab content.
	 *
	 * @return string HTML content.
	 */
	private function get_woocommerce_content() {
		$content  = '<h3>' . __( 'WooCommerce Subscriptions Integration', 'client-sync-pro' ) . '</h3>';
		
		$content .= '<p>' . __( 'When linked to a WooCommerce Subscription product, membership status is automatically managed based on the subscription lifecycle.', 'client-sync-pro' ) . '</p>';

		$content .= '<h4>' . __( 'Requirements', 'client-sync-pro' ) . '</h4>';
		$content .= '<ul>';
		$content .= '<li>' . __( 'WooCommerce plugin (active)', 'client-sync-pro' ) . ' ✓</li>';
		$content .= '<li>' . __( 'WooCommerce Subscriptions extension (active)', 'client-sync-pro' ) . ' ✓</li>';
		$content .= '<li>' . __( 'At least one "Simple subscription" product created', 'client-sync-pro' ) . '</li>';
		$content .= '</ul>';

		$content .= '<h4>' . __( 'Automatic Status Updates', 'client-sync-pro' ) . '</h4>';
		$content .= '<table class="widefat striped" style="margin-top: 10px;">';
		$content .= '<thead><tr><th>' . __( 'Subscription Status', 'client-sync-pro' ) . '</th><th>' . __( 'Membership Action', 'client-sync-pro' ) . '</th></tr></thead>';
		$content .= '<tbody>';
		$content .= '<tr><td><strong>' . __( 'Active', 'client-sync-pro' ) . '</strong></td><td>' . __( 'Member is assigned to the linked plan', 'client-sync-pro' ) . '</td></tr>';
		$content .= '<tr><td><strong>' . __( 'On-hold', 'client-sync-pro' ) . '</strong></td><td>' . __( 'Membership is removed (benefits paused)', 'client-sync-pro' ) . '</td></tr>';
		$content .= '<tr><td><strong>' . __( 'Cancelled', 'client-sync-pro' ) . '</strong></td><td>' . __( 'Membership is removed', 'client-sync-pro' ) . '</td></tr>';
		$content .= '<tr><td><strong>' . __( 'Expired', 'client-sync-pro' ) . '</strong></td><td>' . __( 'Membership is removed', 'client-sync-pro' ) . '</td></tr>';
		$content .= '</tbody></table>';

		return $content;
	}

	/**
	 * Creating a Plan tab content (edit screen).
	 *
	 * @return string HTML content.
	 */
	private function get_creating_plan_content() {
		$content  = '<h3>' . __( 'Creating a Membership Plan', 'client-sync-pro' ) . '</h3>';

		$content .= '<h4>' . __( 'Plan Title', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( 'Give your plan a descriptive name that members will recognize, such as "Basic Monthly" or "Premium Annual".', 'client-sync-pro' ) . '</p>';

		$content .= '<h4>' . __( 'Membership Access Rules', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( 'Rules define what benefits this plan provides. Click "Add Rule" to create a new rule. You can add multiple rules to create flexible plans.', 'client-sync-pro' ) . '</p>';

		$content .= '<h4>' . __( 'Rule Fields', 'client-sync-pro' ) . '</h4>';
		$content .= '<ul>';
		$content .= '<li><strong>' . __( 'Apply To Service', 'client-sync-pro' ) . '</strong> — ' . __( 'Select a specific service or "All Services".', 'client-sync-pro' ) . '</li>';
		$content .= '<li><strong>' . __( 'Rule Type', 'client-sync-pro' ) . '</strong> — ' . __( 'Choose Booking Limit, Discount (%), or Discount (Fixed Amount).', 'client-sync-pro' ) . '</li>';
		$content .= '<li><strong>' . __( 'Value/Period', 'client-sync-pro' ) . '</strong> — ' . __( 'Configure the limit count and period, or the discount amount.', 'client-sync-pro' ) . '</li>';
		$content .= '</ul>';

		$content .= '<p><em>' . __( 'Tip: Service-specific rules take priority over "All Services" rules.', 'client-sync-pro' ) . '</em></p>';

		return $content;
	}

	/**
	 * Rule Types tab content (edit screen).
	 *
	 * @return string HTML content.
	 */
	private function get_rule_types_content() {
		$content  = '<h3>' . __( 'Rule Types Explained', 'client-sync-pro' ) . '</h3>';

		// Booking Limit.
		$content .= '<h4>' . __( 'Booking Limit', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( 'Restricts the number of appointments a member can book within a time period. When the limit is reached, the booking form shows a "Limit Reached" message.', 'client-sync-pro' ) . '</p>';
		$content .= '<p><strong>' . __( 'Period Options:', 'client-sync-pro' ) . '</strong></p>';
		$content .= '<ul>';
		$content .= '<li><strong>' . __( 'Per Week', 'client-sync-pro' ) . '</strong> — ' . __( 'Resets every Monday (based on WordPress week start setting).', 'client-sync-pro' ) . '</li>';
		$content .= '<li><strong>' . __( 'Per Month', 'client-sync-pro' ) . '</strong> — ' . __( 'Resets on the 1st of each month.', 'client-sync-pro' ) . '</li>';
		$content .= '<li><strong>' . __( 'Per Year', 'client-sync-pro' ) . '</strong> — ' . __( 'Resets on January 1st.', 'client-sync-pro' ) . '</li>';
		$content .= '<li><strong>' . __( 'Unlimited', 'client-sync-pro' ) . '</strong> — ' . __( 'No limit; the service is included free with the membership.', 'client-sync-pro' ) . '</li>';
		$content .= '</ul>';

		// Discount Percent.
		$content .= '<h4>' . __( 'Discount (%)', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( 'Applies a percentage discount to the service price. Enter a value from 0-100.', 'client-sync-pro' ) . '</p>';
		$content .= '<ul>';
		$content .= '<li>' . __( 'Example: 25% discount on a $100 service = $75 final price.', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( '100% discount makes the service free.', 'client-sync-pro' ) . '</li>';
		$content .= '</ul>';

		// Discount Amount.
		$content .= '<h4>' . __( 'Discount (Fixed Amount)', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( 'Subtracts a fixed amount from the service price.', 'client-sync-pro' ) . '</p>';
		$content .= '<ul>';
		$content .= '<li>' . __( 'Example: $20 discount on a $100 service = $80 final price.', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( 'If the discount exceeds the price, the service becomes free ($0).', 'client-sync-pro' ) . '</li>';
		$content .= '</ul>';

		// Priority note.
		$content .= '<h4>' . __( 'Multiple Rules', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( 'If multiple rules could apply to the same service, the most specific rule wins:', 'client-sync-pro' ) . '</p>';
		$content .= '<ol>';
		$content .= '<li>' . __( 'A rule targeting the specific service', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( 'A rule targeting "All Services"', 'client-sync-pro' ) . '</li>';
		$content .= '</ol>';

		return $content;
	}

	/**
	 * Examples tab content (edit screen).
	 *
	 * @return string HTML content.
	 */
	private function get_examples_content() {
		$content  = '<h3>' . __( 'Example Plans', 'client-sync-pro' ) . '</h3>';

		// Basic.
		$content .= '<h4>' . __( 'Basic Plan — $29/month', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( '4 appointments per month + 10% off additional bookings', 'client-sync-pro' ) . '</p>';
		$content .= '<table class="widefat striped" style="margin: 10px 0;">';
		$content .= '<thead><tr><th>' . __( 'Service', 'client-sync-pro' ) . '</th><th>' . __( 'Rule Type', 'client-sync-pro' ) . '</th><th>' . __( 'Value', 'client-sync-pro' ) . '</th></tr></thead>';
		$content .= '<tbody>';
		$content .= '<tr><td>' . __( 'All Services', 'client-sync-pro' ) . '</td><td>' . __( 'Booking Limit', 'client-sync-pro' ) . '</td><td>' . __( '4 per month', 'client-sync-pro' ) . '</td></tr>';
		$content .= '<tr><td>' . __( 'All Services', 'client-sync-pro' ) . '</td><td>' . __( 'Discount (%)', 'client-sync-pro' ) . '</td><td>10%</td></tr>';
		$content .= '</tbody></table>';

		// Premium.
		$content .= '<h4>' . __( 'Premium Plan — $79/month', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( '12 appointments per month + 25% off', 'client-sync-pro' ) . '</p>';
		$content .= '<table class="widefat striped" style="margin: 10px 0;">';
		$content .= '<thead><tr><th>' . __( 'Service', 'client-sync-pro' ) . '</th><th>' . __( 'Rule Type', 'client-sync-pro' ) . '</th><th>' . __( 'Value', 'client-sync-pro' ) . '</th></tr></thead>';
		$content .= '<tbody>';
		$content .= '<tr><td>' . __( 'All Services', 'client-sync-pro' ) . '</td><td>' . __( 'Booking Limit', 'client-sync-pro' ) . '</td><td>' . __( '12 per month', 'client-sync-pro' ) . '</td></tr>';
		$content .= '<tr><td>' . __( 'All Services', 'client-sync-pro' ) . '</td><td>' . __( 'Discount (%)', 'client-sync-pro' ) . '</td><td>25%</td></tr>';
		$content .= '</tbody></table>';

		// VIP.
		$content .= '<h4>' . __( 'VIP Unlimited — $149/month', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( 'Unlimited bookings + 50% off', 'client-sync-pro' ) . '</p>';
		$content .= '<table class="widefat striped" style="margin: 10px 0;">';
		$content .= '<thead><tr><th>' . __( 'Service', 'client-sync-pro' ) . '</th><th>' . __( 'Rule Type', 'client-sync-pro' ) . '</th><th>' . __( 'Value', 'client-sync-pro' ) . '</th></tr></thead>';
		$content .= '<tbody>';
		$content .= '<tr><td>' . __( 'All Services', 'client-sync-pro' ) . '</td><td>' . __( 'Booking Limit', 'client-sync-pro' ) . '</td><td>' . __( 'Unlimited', 'client-sync-pro' ) . '</td></tr>';
		$content .= '<tr><td>' . __( 'All Services', 'client-sync-pro' ) . '</td><td>' . __( 'Discount (%)', 'client-sync-pro' ) . '</td><td>50%</td></tr>';
		$content .= '</tbody></table>';

		// Annual.
		$content .= '<h4>' . __( 'Annual Pass — $499/year', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( '50 appointments per year (great for seasonal businesses)', 'client-sync-pro' ) . '</p>';
		$content .= '<table class="widefat striped" style="margin: 10px 0;">';
		$content .= '<thead><tr><th>' . __( 'Service', 'client-sync-pro' ) . '</th><th>' . __( 'Rule Type', 'client-sync-pro' ) . '</th><th>' . __( 'Value', 'client-sync-pro' ) . '</th></tr></thead>';
		$content .= '<tbody>';
		$content .= '<tr><td>' . __( 'All Services', 'client-sync-pro' ) . '</td><td>' . __( 'Booking Limit', 'client-sync-pro' ) . '</td><td>' . __( '50 per year', 'client-sync-pro' ) . '</td></tr>';
		$content .= '</tbody></table>';

		return $content;
	}

	/**
	 * Subscription Link tab content (edit screen).
	 *
	 * @return string HTML content.
	 */
	private function get_subscription_link_content() {
		$content  = '<h3>' . __( 'WooCommerce Subscription Link', 'client-sync-pro' ) . '</h3>';

		$content .= '<p>' . __( 'The "WooCommerce Subscription Link" box (in the sidebar) connects this plan to a subscription product for automated billing and membership management.', 'client-sync-pro' ) . '</p>';

		$content .= '<h4>' . __( 'Setup Steps', 'client-sync-pro' ) . '</h4>';
		$content .= '<ol>';
		$content .= '<li>' . __( 'In WooCommerce, create a "Simple subscription" product (Products → Add New).', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( 'Set the subscription price and billing period (e.g., $29/month).', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( 'Publish the product.', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( 'Return here and search for the product in the "WooCommerce Subscription Link" dropdown.', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( 'Save/Update this membership plan.', 'client-sync-pro' ) . '</li>';
		$content .= '</ol>';

		$content .= '<h4>' . __( 'What Happens When a Customer Subscribes', 'client-sync-pro' ) . '</h4>';
		$content .= '<ul>';
		$content .= '<li>' . __( 'They are automatically assigned to this membership plan.', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( 'All plan rules immediately apply to their booking experience.', 'client-sync-pro' ) . '</li>';
		$content .= '<li>' . __( 'If they cancel or their subscription expires, the membership is automatically removed.', 'client-sync-pro' ) . '</li>';
		$content .= '</ul>';

		$content .= '<h4>' . __( 'Manual Assignment Alternative', 'client-sync-pro' ) . '</h4>';
		$content .= '<p>' . __( 'If you prefer not to use WooCommerce, you can manually assign users to plans via their User Profile page (Users → Edit User → Client Sync Membership section).', 'client-sync-pro' ) . '</p>';

		return $content;
	}
}