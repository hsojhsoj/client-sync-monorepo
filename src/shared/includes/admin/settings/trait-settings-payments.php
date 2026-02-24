<?php
/**
 * File: src/shared/includes/admin/settings/trait-settings-payments.php
 * Payment settings registration and callbacks (WooCommerce + Stripe).
 *
 * Extracted from Settings_Manager to keep each settings group focused.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Settings
 */

namespace DependentMedia\ClientSync\Admin\Settings;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Settings_Payments {

	/**
	 * Register payment-related settings, sections, and fields.
	 */
	protected function register_payment_settings(): void {
		$group = 'clisyc_payments_settings_group';
		$page  = 'clisyc-payments';

		// --- WooCommerce ---
		register_setting( $group, Constants::OPTION_WC_ENABLED, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );
		register_setting( $group, Constants::OPTION_WC_PRODUCT_ID, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );

		add_settings_section( 'clisyc_payments_wc_section', __( 'WooCommerce Integration', 'client-sync' ), null, $page );
		add_settings_field( Constants::OPTION_WC_ENABLED, __( 'Enable WooCommerce Payments', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_payments_wc_section', [ 'id' => Constants::OPTION_WC_ENABLED, 'type' => 'checkbox', 'desc' => __( 'Require payment for appointments via WooCommerce.', 'client-sync' ) ] );

		// --- Stripe Direct ---
		register_setting( $group, Constants::OPTION_STRIPE_ENABLED, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );
		register_setting( $group, Constants::OPTION_STRIPE_KEY_PUBLIC, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( $group, Constants::OPTION_STRIPE_KEY_SECRET, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( $group, Constants::OPTION_STRIPE_WEBHOOK_SECRET, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );

		add_settings_section( 'clisyc_payments_stripe_section', __( 'Stripe Direct Integration', 'client-sync' ), [ $this, 'render_stripe_section_header' ], $page );
		add_settings_field( Constants::OPTION_STRIPE_ENABLED, __( 'Enable Stripe Payments', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_payments_stripe_section', [ 'id' => Constants::OPTION_STRIPE_ENABLED, 'type' => 'checkbox', 'desc' => __( 'Collect payments directly via Stripe Checkout (no WooCommerce required).', 'client-sync' ) ] );
		add_settings_field( Constants::OPTION_STRIPE_KEY_PUBLIC, __( 'Publishable Key', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_payments_stripe_section', [ 'id' => Constants::OPTION_STRIPE_KEY_PUBLIC, 'type' => 'text', 'class' => 'regular-text', 'desc' => __( 'Starts with pk_test_ or pk_live_.', 'client-sync' ) ] );
		add_settings_field( Constants::OPTION_STRIPE_KEY_SECRET, __( 'Secret Key', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_payments_stripe_section', [ 'id' => Constants::OPTION_STRIPE_KEY_SECRET, 'type' => 'password', 'class' => 'regular-text', 'desc' => __( 'Starts with sk_test_ or sk_live_. Never shared publicly.', 'client-sync' ) ] );
		add_settings_field( Constants::OPTION_STRIPE_WEBHOOK_SECRET, __( 'Webhook Signing Secret', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_payments_stripe_section', [ 'id' => Constants::OPTION_STRIPE_WEBHOOK_SECRET, 'type' => 'password', 'class' => 'regular-text', 'desc' => __( 'Starts with whsec_. Found in your Stripe Dashboard under Webhooks.', 'client-sync' ) ] );
	}

	// -----------------------------------------------------------------
	// Section Callbacks
	// -----------------------------------------------------------------

	public function render_stripe_section_header() {
		echo '<p>' . esc_html__( 'Accept payments directly through Stripe Checkout without WooCommerce.', 'client-sync' ) . '</p>';
		$webhook_url = rest_url( 'clisyc/v1/stripe-webhook' );
		echo '<p class="description">' . sprintf(
			/* translators: %s: Webhook URL */
			esc_html__( 'Webhook URL (paste into Stripe Dashboard → Webhooks → Add endpoint): %s', 'client-sync' ),
			'<code>' . esc_html( $webhook_url ) . '</code>'
		) . '</p>';
		echo '<p class="description">' . esc_html__( 'Required webhook events: checkout.session.completed, payment_intent.payment_failed, charge.refunded.', 'client-sync' );
		if ( function_exists( 'clisyc_pro_is_license_active' ) && \clisyc_pro_is_license_active() ) {
			echo ' ' . esc_html__( 'For Stripe Subscriptions (Pro): customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, invoice.payment_succeeded, invoice.payment_failed.', 'client-sync' );
		}
		echo '</p>';
	}
}
