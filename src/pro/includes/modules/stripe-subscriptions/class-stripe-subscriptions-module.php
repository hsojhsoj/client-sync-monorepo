<?php
/**
 * File: src/pro/includes/modules/stripe-subscriptions/class-stripe-subscriptions-module.php
 * Main controller for the Stripe Subscriptions module.
 *
 * Enables recurring membership billing via Stripe Subscriptions without
 * requiring WooCommerce. Coexists with the WC Subscriptions path — admin
 * chooses one billing backend per membership plan.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Stripe_Subscriptions
 */

namespace ClientSyncPro\Modules\Stripe_Subscriptions;

use ClientSyncPro\Module_Interface;
use DependentMedia\ClientSync\Integrations\Stripe_Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stripe_Subscriptions_Module implements Module_Interface {

	/**
	 * Initializes the Stripe Subscriptions module.
	 *
	 * Only loads when the Pro license is active AND the Stripe integration
	 * is fully configured (keys + enabled).
	 *
	 * {@inheritdoc}
	 */
	public function initialize() {
		if ( ! function_exists( 'clisyc_pro_is_license_active' ) || ! \clisyc_pro_is_license_active() ) {
			return;
		}

		if ( ! Stripe_Integration::is_active() ) {
			return;
		}

		// Load module classes.
		require_once __DIR__ . '/class-subscription-user-meta.php';
		require_once __DIR__ . '/class-membership-stripe-link.php';
		require_once __DIR__ . '/class-stripe-subscription-webhooks.php';
		require_once __DIR__ . '/class-stripe-subscription-manager.php';

		// Meta box for linking plans to Stripe Price IDs.
		$stripe_link = new Membership_Stripe_Link();
		$stripe_link->register_hooks();

		// Webhook lifecycle handlers.
		$webhooks = new Stripe_Subscription_Webhooks();
		$webhooks->register_hooks();

		// Subscription manager (checkout, retrieval, cancellation).
		$manager = new Stripe_Subscription_Manager();
		$manager->register_hooks();
	}
}
