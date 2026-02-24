<?php
/**
 * File: src/pro/includes/modules/stripe-subscriptions/class-stripe-subscription-webhooks.php
 * Handles Stripe subscription lifecycle webhook events.
 *
 * Listens to hooks fired by Stripe_Integration::handle_webhook() and maps
 * them to membership activation/deactivation, mirroring the WC Subscriptions
 * integration pattern.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Stripe_Subscriptions
 */

namespace ClientSyncPro\Modules\Stripe_Subscriptions;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stripe_Subscription_Webhooks {

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'clisyc_stripe_subscription_checkout_completed', [ $this, 'handle_checkout_completed' ], 10, 2 );
		add_action( 'clisyc_stripe_subscription_event', [ $this, 'handle_subscription_event' ], 10, 3 );
		add_action( 'clisyc_stripe_invoice_event', [ $this, 'handle_invoice_event' ], 10, 3 );
	}

	/**
	 * Handles a subscription-mode checkout.session.completed event.
	 *
	 * Extracts the subscription ID from the completed session and stores it
	 * on the user. The actual activation happens via customer.subscription.created.
	 *
	 * @param array $event   Full Stripe event.
	 * @param array $session The checkout.session object.
	 */
	public function handle_checkout_completed( array $event, array $session ): void {
		$user_id         = (int) ( $session['metadata']['wp_user_id'] ?? 0 );
		$subscription_id = sanitize_text_field( $session['subscription'] ?? '' );
		$customer_id     = sanitize_text_field( $session['customer'] ?? '' );

		if ( ! $user_id || empty( $subscription_id ) ) {
			return;
		}

		// Persist the Stripe Customer ID (may already exist, but update to be safe).
		if ( ! empty( $customer_id ) ) {
			update_user_meta( $user_id, Constants::META_STRIPE_CUSTOMER_ID, $customer_id );
		}

		// Store the subscription ID immediately so it's available when
		// customer.subscription.created fires (which may arrive before or after).
		update_user_meta( $user_id, Constants::META_STRIPE_SUBSCRIPTION_ID, $subscription_id );
	}

	/**
	 * Handles customer.subscription.created|updated|deleted events.
	 *
	 * @param string $event_type Full event type string.
	 * @param array  $event      Full Stripe event.
	 * @param array  $subscription The subscription object.
	 */
	public function handle_subscription_event( string $event_type, array $event, array $subscription ): void {
		$subscription_id = sanitize_text_field( $subscription['id'] ?? '' );
		$status          = sanitize_key( $subscription['status'] ?? '' );
		$customer_id     = sanitize_text_field( $subscription['customer'] ?? '' );

		if ( empty( $subscription_id ) ) {
			return;
		}

		// Resolve the WordPress user.
		$user_id = Subscription_User_Meta::find_user_by_subscription( $subscription_id );
		if ( ! $user_id && ! empty( $customer_id ) ) {
			$user_id = $this->find_user_by_customer( $customer_id );
		}
		if ( ! $user_id ) {
			// Try metadata from the subscription itself.
			$user_id = (int) ( $subscription['metadata']['wp_user_id'] ?? 0 );
		}
		if ( ! $user_id ) {
			return;
		}

		// Resolve the membership plan from the subscription's price.
		$plan_id = $this->resolve_plan_from_subscription( $subscription );

		switch ( $event_type ) {
			case 'customer.subscription.created':
				$this->on_subscription_created( $user_id, $plan_id, $subscription_id, $status, $subscription );
				break;

			case 'customer.subscription.updated':
				$this->on_subscription_updated( $user_id, $plan_id, $subscription_id, $status, $subscription );
				break;

			case 'customer.subscription.deleted':
				$this->on_subscription_deleted( $user_id, $plan_id, $subscription_id );
				break;
		}
	}

	/**
	 * Handles invoice.payment_succeeded and invoice.payment_failed events.
	 *
	 * @param string $event_type Full event type string.
	 * @param array  $event      Full Stripe event.
	 * @param array  $invoice    The invoice object.
	 */
	public function handle_invoice_event( string $event_type, array $event, array $invoice ): void {
		$subscription_id = sanitize_text_field( $invoice['subscription'] ?? '' );
		if ( empty( $subscription_id ) ) {
			return;
		}

		$user_id = Subscription_User_Meta::find_user_by_subscription( $subscription_id );
		if ( ! $user_id ) {
			return;
		}

		if ( 'invoice.payment_succeeded' === $event_type ) {
			$this->on_invoice_paid( $user_id, $invoice );
		} elseif ( 'invoice.payment_failed' === $event_type ) {
			$this->on_invoice_failed( $user_id, $invoice );
		}
	}

	// =====================================================================
	// Subscription Lifecycle Handlers
	// =====================================================================

	/**
	 * Subscription created: activate membership if status is active/trialing.
	 */
	private function on_subscription_created( int $user_id, int $plan_id, string $subscription_id, string $status, array $subscription ): void {
		if ( ! $plan_id ) {
			return;
		}

		$period_end = (int) ( $subscription['current_period_end'] ?? 0 );

		if ( in_array( $status, [ 'active', 'trialing' ], true ) ) {
			Subscription_User_Meta::activate( $user_id, $plan_id, $subscription_id, $status, $period_end );
		} else {
			// Store the subscription ID and status even if not immediately active.
			update_user_meta( $user_id, Constants::META_STRIPE_SUBSCRIPTION_ID, $subscription_id );
			Subscription_User_Meta::update_status( $user_id, $status );
			update_user_meta( $user_id, Constants::META_STRIPE_SUB_PLAN_ID, $plan_id );
		}
	}

	/**
	 * Subscription updated: sync status. Handle activation, grace periods, and cancellation.
	 */
	private function on_subscription_updated( int $user_id, int $plan_id, string $subscription_id, string $status, array $subscription ): void {
		$period_end = (int) ( $subscription['current_period_end'] ?? 0 );

		// Check for plan change (price changed mid-subscription).
		$stored_plan_id = Subscription_User_Meta::get_plan_id( $user_id );
		if ( $plan_id && $plan_id !== $stored_plan_id ) {
			// Plan changed — update plan and clear usage counts.
			update_user_meta( $user_id, '_clisyc_active_membership_plan_id', $plan_id );
			update_user_meta( $user_id, Constants::META_STRIPE_SUB_PLAN_ID, $plan_id );
			delete_user_meta( $user_id, '_clisyc_membership_usage_counts' );
		}

		switch ( $status ) {
			case 'active':
			case 'trialing':
				// (Re-)activate if not already active.
				$effective_plan = $plan_id ?: $stored_plan_id;
				if ( $effective_plan ) {
					Subscription_User_Meta::activate( $user_id, $effective_plan, $subscription_id, $status, $period_end );
				}
				break;

			case 'past_due':
				// Grace period: keep membership active but flag status.
				Subscription_User_Meta::update_status( $user_id, 'past_due' );
				if ( $period_end > 0 ) {
					Subscription_User_Meta::update_period_end( $user_id, $period_end );
				}
				break;

			case 'unpaid':
				// Payment exhausted all retries — deactivate immediately.
				$effective_plan = $plan_id ?: $stored_plan_id;
				if ( $effective_plan ) {
					Subscription_User_Meta::deactivate( $user_id, $effective_plan );
				}
				break;

			case 'canceled':
				// Stripe sets cancel_at_period_end. The subscription remains active
				// until the period ends, at which point .deleted fires.
				$cancel_at_period_end = ! empty( $subscription['cancel_at_period_end'] );
				if ( $cancel_at_period_end ) {
					// Keep active until period end; just flag the status.
					Subscription_User_Meta::update_status( $user_id, 'canceled' );
				} else {
					// Immediate cancellation.
					$effective_plan = $plan_id ?: $stored_plan_id;
					if ( $effective_plan ) {
						Subscription_User_Meta::deactivate( $user_id, $effective_plan );
					}
				}
				break;

			default:
				Subscription_User_Meta::update_status( $user_id, $status );
				break;
		}
	}

	/**
	 * Subscription deleted: deactivate membership and clear usage.
	 */
	private function on_subscription_deleted( int $user_id, int $plan_id, string $subscription_id ): void {
		$effective_plan = $plan_id ?: Subscription_User_Meta::get_plan_id( $user_id );
		if ( $effective_plan ) {
			Subscription_User_Meta::deactivate( $user_id, $effective_plan );
		}
	}

	/**
	 * Invoice paid: update period end and clear any warning flags.
	 */
	private function on_invoice_paid( int $user_id, array $invoice ): void {
		// Stripe includes period data on the invoice lines.
		$period_end = 0;
		$lines = $invoice['lines']['data'] ?? [];
		foreach ( $lines as $line ) {
			$line_period_end = (int) ( $line['period']['end'] ?? 0 );
			if ( $line_period_end > $period_end ) {
				$period_end = $line_period_end;
			}
		}

		if ( $period_end > 0 ) {
			Subscription_User_Meta::update_period_end( $user_id, $period_end );
		}

		// If status was past_due, restore to active.
		if ( 'past_due' === Subscription_User_Meta::get_status( $user_id ) ) {
			Subscription_User_Meta::update_status( $user_id, 'active' );
		}
	}

	/**
	 * Invoice failed: flag the subscription as past_due.
	 */
	private function on_invoice_failed( int $user_id, array $invoice ): void {
		Subscription_User_Meta::update_status( $user_id, 'past_due' );

		/**
		 * Fires when a subscription invoice payment fails.
		 *
		 * @param int   $user_id WordPress user ID.
		 * @param array $invoice The Stripe invoice object.
		 */
		do_action( 'clisyc_subscription_payment_failed', $user_id, $invoice );
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	/**
	 * Resolves a membership plan ID from a subscription's line items.
	 *
	 * Extracts the price ID from the subscription's items and looks up the
	 * corresponding clisyc_member_plan post.
	 *
	 * @param array $subscription Stripe subscription object.
	 * @return int Plan post ID, or 0 if not found.
	 */
	private function resolve_plan_from_subscription( array $subscription ): int {
		// First check metadata.
		$meta_plan_id = (int) ( $subscription['metadata']['membership_plan_id'] ?? 0 );
		if ( $meta_plan_id > 0 && 'publish' === get_post_status( $meta_plan_id ) ) {
			return $meta_plan_id;
		}

		// Fall back to price ID lookup.
		$items = $subscription['items']['data'] ?? [];
		foreach ( $items as $item ) {
			$price_id = $item['price']['id'] ?? '';
			if ( ! empty( $price_id ) ) {
				$plan_id = Subscription_User_Meta::find_plan_by_price_id( $price_id );
				if ( $plan_id > 0 ) {
					return $plan_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Finds a WordPress user by their stored Stripe Customer ID.
	 *
	 * @param string $customer_id Stripe Customer ID (cus_xxx).
	 * @return int User ID, or 0 if not found.
	 */
	private function find_user_by_customer( string $customer_id ): int {
		global $wpdb;

		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta}
				 WHERE meta_key = %s AND meta_value = %s
				 LIMIT 1",
				Constants::META_STRIPE_CUSTOMER_ID,
				$customer_id
			)
		);

		return (int) $user_id;
	}
}
