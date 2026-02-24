<?php
/**
 * File: src/pro/includes/modules/stripe-subscriptions/class-stripe-subscription-manager.php
 * Business logic for Stripe subscription operations.
 *
 * Provides the AJAX endpoint for initiating subscription checkout and
 * coordinates between the Stripe_Integration API methods and the
 * Subscription_User_Meta helper.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Stripe_Subscriptions
 */

namespace ClientSyncPro\Modules\Stripe_Subscriptions;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Integrations\Stripe_Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stripe_Subscription_Manager {

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_clisyc_subscribe', [ $this, 'ajax_handle_subscribe' ] );
	}

	/**
	 * AJAX handler: initiates a Stripe subscription checkout session.
	 *
	 * Expects POST: { plan_id: int, nonce: string }
	 * Returns JSON: { success: true, data: { url: string } }
	 */
	public function ajax_handle_subscribe(): void {
		$plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;

		if ( ! $plan_id || ! check_ajax_referer( 'clisyc_subscribe_' . $plan_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'client-sync-pro' ) ] );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'You must be logged in to subscribe.', 'client-sync-pro' ) ] );
		}

		$user_id = get_current_user_id();

		// Verify the plan exists and has a Stripe Price ID.
		$plan = get_post( $plan_id );
		if ( ! $plan || 'clisyc_member_plan' !== $plan->post_type || 'publish' !== $plan->post_status ) {
			wp_send_json_error( [ 'message' => __( 'Membership plan not found.', 'client-sync-pro' ) ] );
		}

		$price_id = get_post_meta( $plan_id, Constants::META_LINKED_STRIPE_PRICE_ID, true );
		if ( empty( $price_id ) ) {
			wp_send_json_error( [ 'message' => __( 'This plan is not configured for Stripe billing.', 'client-sync-pro' ) ] );
		}

		// Check if user already has an active subscription.
		if ( Subscription_User_Meta::is_active( $user_id ) ) {
			$existing_plan_id = Subscription_User_Meta::get_plan_id( $user_id );
			if ( $existing_plan_id === $plan_id ) {
				wp_send_json_error( [ 'message' => __( 'You already have an active subscription to this plan.', 'client-sync-pro' ) ] );
			}
		}

		$trial_days = (int) get_post_meta( $plan_id, Constants::META_STRIPE_TRIAL_DAYS, true );

		// Build success/cancel URLs.
		$account_page_id = absint( get_option( Constants::OPTION_MY_APPOINTMENTS_PAGE, 0 ) );
		$success_url     = $account_page_id > 0
			? add_query_arg( 'clisyc_subscription', 'success', get_permalink( $account_page_id ) )
			: add_query_arg( 'clisyc_subscription', 'success', home_url() );
		$cancel_url      = wp_get_referer() ?: home_url();

		$stripe = new Stripe_Integration();
		$result = $stripe->create_subscription_checkout_session(
			$user_id,
			$price_id,
			$plan_id,
			$trial_days,
			$success_url,
			$cancel_url
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [ 'url' => $result['url'] ] );
	}
}
