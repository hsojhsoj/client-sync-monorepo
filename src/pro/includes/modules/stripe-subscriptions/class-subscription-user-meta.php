<?php
/**
 * File: src/pro/includes/modules/stripe-subscriptions/class-subscription-user-meta.php
 * Helper for reading and writing Stripe subscription user meta.
 *
 * Centralises all subscription-related user meta operations in one place
 * so that webhook handlers, the manager, and the rules engine all share
 * the same storage conventions.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Stripe_Subscriptions
 */

namespace ClientSyncPro\Modules\Stripe_Subscriptions;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Subscription_User_Meta {

	/**
	 * Activates a Stripe subscription for a user.
	 *
	 * Mirrors the WC Subscriptions pattern: sets the active membership plan
	 * and stores Stripe-specific subscription metadata.
	 *
	 * @param int    $user_id         WordPress user ID.
	 * @param int    $plan_id         Membership plan post ID.
	 * @param string $subscription_id Stripe Subscription ID (sub_xxx).
	 * @param string $status          Subscription status (active|trialing).
	 * @param int    $period_end      Current period end as Unix timestamp.
	 */
	public static function activate( int $user_id, int $plan_id, string $subscription_id, string $status = 'active', int $period_end = 0 ): void {
		update_user_meta( $user_id, '_clisyc_active_membership_plan_id', $plan_id );
		update_user_meta( $user_id, Constants::META_STRIPE_SUBSCRIPTION_ID, sanitize_text_field( $subscription_id ) );
		update_user_meta( $user_id, Constants::META_STRIPE_SUB_STATUS, sanitize_key( $status ) );
		update_user_meta( $user_id, Constants::META_STRIPE_SUB_PLAN_ID, $plan_id );

		if ( $period_end > 0 ) {
			update_user_meta( $user_id, Constants::META_SUBSCRIPTION_PERIOD_END, $period_end );
		}
	}

	/**
	 * Deactivates a Stripe subscription for a user.
	 *
	 * Only removes the membership if the stored plan ID matches, preventing
	 * cross-contamination when multiple billing backends coexist.
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $plan_id Membership plan post ID to verify.
	 */
	public static function deactivate( int $user_id, int $plan_id ): void {
		$current_plan = (int) get_user_meta( $user_id, '_clisyc_active_membership_plan_id', true );

		if ( $plan_id && $plan_id === $current_plan ) {
			delete_user_meta( $user_id, '_clisyc_active_membership_plan_id' );
			delete_user_meta( $user_id, '_clisyc_membership_expiry_date' );
			delete_user_meta( $user_id, '_clisyc_membership_usage_counts' );
		}

		update_user_meta( $user_id, Constants::META_STRIPE_SUB_STATUS, 'canceled' );
	}

	/**
	 * Updates the subscription status without changing the active membership.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $status  New status string.
	 */
	public static function update_status( int $user_id, string $status ): void {
		update_user_meta( $user_id, Constants::META_STRIPE_SUB_STATUS, sanitize_key( $status ) );
	}

	/**
	 * Updates the current billing period end date.
	 *
	 * @param int $user_id    WordPress user ID.
	 * @param int $period_end Unix timestamp.
	 */
	public static function update_period_end( int $user_id, int $period_end ): void {
		update_user_meta( $user_id, Constants::META_SUBSCRIPTION_PERIOD_END, $period_end );
	}

	/**
	 * Returns the Stripe Subscription ID for a user, or empty string if none.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function get_subscription_id( int $user_id ): string {
		return (string) get_user_meta( $user_id, Constants::META_STRIPE_SUBSCRIPTION_ID, true );
	}

	/**
	 * Returns the current subscription status, or empty string if none.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string One of: active, past_due, canceled, trialing, or empty.
	 */
	public static function get_status( int $user_id ): string {
		return (string) get_user_meta( $user_id, Constants::META_STRIPE_SUB_STATUS, true );
	}

	/**
	 * Returns the membership plan ID linked to the user's Stripe subscription.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Plan post ID, or 0 if none.
	 */
	public static function get_plan_id( int $user_id ): int {
		return (int) get_user_meta( $user_id, Constants::META_STRIPE_SUB_PLAN_ID, true );
	}

	/**
	 * Returns the current billing period end as Unix timestamp, or 0 if none.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int
	 */
	public static function get_period_end( int $user_id ): int {
		return (int) get_user_meta( $user_id, Constants::META_SUBSCRIPTION_PERIOD_END, true );
	}

	/**
	 * Checks whether a user has an active (or trialing) Stripe subscription.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function is_active( int $user_id ): bool {
		$status = self::get_status( $user_id );
		return in_array( $status, [ 'active', 'trialing' ], true );
	}

	/**
	 * Clears all Stripe subscription meta for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function clear_all( int $user_id ): void {
		delete_user_meta( $user_id, Constants::META_STRIPE_SUBSCRIPTION_ID );
		delete_user_meta( $user_id, Constants::META_STRIPE_SUB_STATUS );
		delete_user_meta( $user_id, Constants::META_STRIPE_SUB_PLAN_ID );
		delete_user_meta( $user_id, Constants::META_SUBSCRIPTION_PERIOD_END );
	}

	/**
	 * Finds the WordPress user ID associated with a Stripe Subscription ID.
	 *
	 * @param string $subscription_id Stripe Subscription ID (sub_xxx).
	 * @return int User ID, or 0 if not found.
	 */
	public static function find_user_by_subscription( string $subscription_id ): int {
		global $wpdb;

		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta}
				 WHERE meta_key = %s AND meta_value = %s
				 LIMIT 1",
				Constants::META_STRIPE_SUBSCRIPTION_ID,
				$subscription_id
			)
		);

		return (int) $user_id;
	}

	/**
	 * Finds the membership plan linked to a Stripe Price ID.
	 *
	 * @param string $price_id Stripe Price ID (price_xxx).
	 * @return int Plan post ID, or 0 if not found.
	 */
	public static function find_plan_by_price_id( string $price_id ): int {
		global $wpdb;

		$plan_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value = %s
				 LIMIT 1",
				Constants::META_LINKED_STRIPE_PRICE_ID,
				$price_id
			)
		);

		return (int) $plan_id;
	}
}
