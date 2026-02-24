<?php
/**
 * File: src/shared/includes/integrations/class-stripe-integration.php
 * Provides direct Stripe Checkout payment integration without WooCommerce.
 *
 * Uses Stripe's Checkout Sessions API (server-side via cURL, no SDK dependency)
 * and a REST webhook endpoint to confirm payments.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Integrations
 */

namespace DependentMedia\ClientSync\Integrations;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Security_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stripe_Integration {

	/** @var string Stripe API base. */
	private const API_BASE = 'https://api.stripe.com/v1';

	/**
	 * Returns true when the Stripe integration is fully configured.
	 */
	public static function is_active(): bool {
		return (bool) get_option( Constants::OPTION_STRIPE_ENABLED, false )
			&& ! empty( get_option( Constants::OPTION_STRIPE_KEY_SECRET, '' ) )
			&& ! empty( get_option( Constants::OPTION_STRIPE_KEY_PUBLIC, '' ) );
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		// REST webhook endpoint.
		add_action( 'rest_api_init', [ $this, 'register_webhook_route' ] );
	}

	// =====================================================================
	// REST Webhook
	// =====================================================================

	/**
	 * Registers the public webhook endpoint for Stripe events.
	 */
	public function register_webhook_route(): void {
		register_rest_route( 'clisyc/v1', '/stripe-webhook', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_webhook' ],
			'permission_callback' => '__return_true', // Stripe signs requests; verified below.
		] );
	}

	/**
	 * Handles an incoming Stripe webhook event.
	 *
	 * Dispatches to event-specific handlers for:
	 * - checkout.session.completed       → confirm appointment (or subscription)
	 * - payment_intent.payment_failed    → mark appointment as failed
	 * - charge.refunded                  → mark appointment as refunded / cancelled
	 * - customer.subscription.created    → fire hook for Pro subscription module
	 * - customer.subscription.updated    → fire hook for Pro subscription module
	 * - customer.subscription.deleted    → fire hook for Pro subscription module
	 * - invoice.payment_succeeded        → fire hook for Pro subscription module
	 * - invoice.payment_failed           → fire hook for Pro subscription module
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		// Rate-limit: max 120 requests per 60 seconds per IP.
		$rate_check = Security_Helper::check_rate_limit( 'stripe_webhook', Constants::RATE_LIMIT_STRIPE_HOOK, Constants::RATE_LIMIT_WINDOW_SECS );
		if ( is_wp_error( $rate_check ) ) {
			return new \WP_REST_Response( [ 'error' => $rate_check->get_error_message() ], 429 );
		}

		$payload   = $request->get_body();
		$sig       = $request->get_header( 'Stripe-Signature' );
		$secret    = get_option( Constants::OPTION_STRIPE_WEBHOOK_SECRET, '' );

		if ( empty( $secret ) || empty( $sig ) ) {
			return new \WP_REST_Response( [ 'error' => 'Missing signature or secret.' ], 400 );
		}

		// Verify signature.
		$event = $this->verify_signature( $payload, $sig, $secret );
		if ( is_wp_error( $event ) ) {
			return new \WP_REST_Response( [ 'error' => $event->get_error_message() ], 400 );
		}

		$event_type = $event['type'] ?? '';
		$object     = $event['data']['object'] ?? [];

		switch ( $event_type ) {
			case 'checkout.session.completed':
				$mode = $object['mode'] ?? 'payment';
				if ( 'subscription' === $mode ) {
					/**
					 * Fires when a subscription checkout session completes.
					 *
					 * @param array $event  Full Stripe event.
					 * @param array $object The checkout.session object.
					 */
					do_action( 'clisyc_stripe_subscription_checkout_completed', $event, $object );
					return new \WP_REST_Response( [ 'received' => true ], 200 );
				}
				return $this->handle_checkout_completed( $event, $object );

			case 'payment_intent.payment_failed':
				return $this->handle_payment_failed( $event, $object );

			case 'charge.refunded':
				return $this->handle_charge_refunded( $event, $object );

			case 'customer.subscription.created':
			case 'customer.subscription.updated':
			case 'customer.subscription.deleted':
				/**
				 * Fires when a Stripe subscription lifecycle event occurs.
				 *
				 * @param string $event_type One of created|updated|deleted.
				 * @param array  $event      Full Stripe event.
				 * @param array  $object     The subscription object.
				 */
				do_action( 'clisyc_stripe_subscription_event', $event_type, $event, $object );
				return new \WP_REST_Response( [ 'received' => true ], 200 );

			case 'invoice.payment_succeeded':
			case 'invoice.payment_failed':
				/**
				 * Fires when a Stripe invoice event occurs (subscription renewals).
				 *
				 * @param string $event_type One of invoice.payment_succeeded|invoice.payment_failed.
				 * @param array  $event      Full Stripe event.
				 * @param array  $object     The invoice object.
				 */
				do_action( 'clisyc_stripe_invoice_event', $event_type, $event, $object );
				return new \WP_REST_Response( [ 'received' => true ], 200 );

			default:
				return new \WP_REST_Response( [ 'received' => true ], 200 );
		}
	}

	/**
	 * Handles a successful checkout session.
	 *
	 * @param array $event   Full Stripe event.
	 * @param array $session The checkout.session object.
	 * @return \WP_REST_Response
	 */
	private function handle_checkout_completed( array $event, array $session ): \WP_REST_Response {
		$appointment_id = (int) ( $session['metadata']['appointment_id'] ?? 0 );

		if ( ! $appointment_id || Constants::POST_TYPE_APPOINTMENT !== get_post_type( $appointment_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid appointment.' ], 400 );
		}

		// Idempotency guard: if this appointment is already confirmed, skip re-processing.
		// Stripe delivers webhooks with at-least-once semantics, so duplicates are possible.
		$current_status = get_post_status( $appointment_id );
		if ( 'publish' === $current_status ) {
			return new \WP_REST_Response( [ 'received' => true, 'already_confirmed' => true ], 200 );
		}

		// Also guard against exact duplicate Stripe events by checking the event ID.
		$stripe_event_id    = sanitize_text_field( $event['id'] ?? '' );
		$processed_event_id = get_post_meta( $appointment_id, '_clisyc_stripe_event_id', true );
		if ( ! empty( $stripe_event_id ) && $stripe_event_id === $processed_event_id ) {
			return new \WP_REST_Response( [ 'received' => true, 'duplicate_event' => true ], 200 );
		}

		// Confirm the appointment.
		wp_update_post( [
			'ID'          => $appointment_id,
			'post_status' => 'publish',
		] );

		update_post_meta( $appointment_id, Constants::META_PAYMENT_STATUS, 'paid_via_stripe' );
		update_post_meta( $appointment_id, Constants::META_STRIPE_SESSION_ID, sanitize_text_field( $session['id'] ?? '' ) );
		update_post_meta( $appointment_id, Constants::META_STRIPE_PAYMENT_INTENT, sanitize_text_field( $session['payment_intent'] ?? '' ) );

		// Persist the Stripe Customer ID on the WordPress user for future reuse.
		$stripe_customer_id = sanitize_text_field( $session['customer'] ?? '' );
		if ( ! empty( $stripe_customer_id ) ) {
			$author_id = (int) get_post_field( 'post_author', $appointment_id );
			if ( $author_id > 0 ) {
				update_user_meta( $author_id, Constants::META_STRIPE_CUSTOMER_ID, $stripe_customer_id );
			}
		}

		// Store the Stripe event ID to prevent reprocessing exact duplicate deliveries.
		if ( ! empty( $stripe_event_id ) ) {
			update_post_meta( $appointment_id, '_clisyc_stripe_event_id', $stripe_event_id );
		}

		/**
		 * Fires after an appointment is confirmed via Stripe payment.
		 *
		 * @param int   $appointment_id The confirmed appointment post ID.
		 * @param array $session        The Stripe Checkout Session object.
		 */
		do_action( 'clisyc_appointment_confirmed', $appointment_id, $session );

		return new \WP_REST_Response( [ 'received' => true ], 200 );
	}

	/**
	 * Handles a failed payment intent.
	 *
	 * Looks up the appointment via the payment intent's metadata (forwarded from
	 * the Checkout Session) and marks it with a failed payment status.
	 *
	 * @param array $event          Full Stripe event.
	 * @param array $payment_intent The payment_intent object.
	 * @return \WP_REST_Response
	 */
	private function handle_payment_failed( array $event, array $payment_intent ): \WP_REST_Response {
		// The payment intent inherits metadata from the checkout session.
		$appointment_id = (int) ( $payment_intent['metadata']['appointment_id'] ?? 0 );

		// Fallback: look up appointment by stored payment intent ID.
		if ( ! $appointment_id ) {
			$pi_id = sanitize_text_field( $payment_intent['id'] ?? '' );
			if ( ! empty( $pi_id ) ) {
				$appointment_id = $this->find_appointment_by_payment_intent( $pi_id );
			}
		}

		if ( ! $appointment_id || Constants::POST_TYPE_APPOINTMENT !== get_post_type( $appointment_id ) ) {
			// Not an error — the PI may not relate to this site.
			return new \WP_REST_Response( [ 'received' => true ], 200 );
		}

		// Only update if appointment is still in a pending-payment state.
		$current_status = get_post_status( $appointment_id );
		if ( Constants::STATUS_PENDING_PAYMENT !== $current_status ) {
			return new \WP_REST_Response( [ 'received' => true, 'skipped' => true ], 200 );
		}

		wp_update_post( [
			'ID'          => $appointment_id,
			'post_status' => Constants::STATUS_FAILED_ON_DAY,
		] );

		$failure_message = $payment_intent['last_payment_error']['message'] ?? '';
		update_post_meta( $appointment_id, Constants::META_PAYMENT_STATUS, 'failed' );
		if ( ! empty( $failure_message ) ) {
			update_post_meta( $appointment_id, '_clisyc_stripe_failure_reason', sanitize_text_field( $failure_message ) );
		}

		/**
		 * Fires when a Stripe payment fails for an appointment.
		 *
		 * @param int    $appointment_id The appointment post ID.
		 * @param array  $payment_intent The Stripe PaymentIntent object.
		 * @param string $failure_message Human-readable failure reason from Stripe.
		 */
		do_action( 'clisyc_payment_failed', $appointment_id, $payment_intent, $failure_message );

		return new \WP_REST_Response( [ 'received' => true ], 200 );
	}

	/**
	 * Handles a refunded charge.
	 *
	 * Updates the appointment payment status to 'refunded' and optionally cancels
	 * the appointment if the charge was fully refunded.
	 *
	 * @param array $event  Full Stripe event.
	 * @param array $charge The charge object.
	 * @return \WP_REST_Response
	 */
	private function handle_charge_refunded( array $event, array $charge ): \WP_REST_Response {
		$pi_id = sanitize_text_field( $charge['payment_intent'] ?? '' );
		if ( empty( $pi_id ) ) {
			return new \WP_REST_Response( [ 'received' => true ], 200 );
		}

		$appointment_id = $this->find_appointment_by_payment_intent( $pi_id );
		if ( ! $appointment_id ) {
			return new \WP_REST_Response( [ 'received' => true ], 200 );
		}

		$is_full_refund = (bool) ( $charge['refunded'] ?? false );

		update_post_meta( $appointment_id, Constants::META_PAYMENT_STATUS, $is_full_refund ? 'refunded' : 'partially_refunded' );

		// Store the latest refund ID for reference.
		$refunds = $charge['refunds']['data'] ?? [];
		if ( ! empty( $refunds[0]['id'] ) ) {
			update_post_meta( $appointment_id, Constants::META_STRIPE_REFUND_ID, sanitize_text_field( $refunds[0]['id'] ) );
		}

		// Cancel the appointment on full refund.
		if ( $is_full_refund && 'publish' === get_post_status( $appointment_id ) ) {
			wp_update_post( [
				'ID'          => $appointment_id,
				'post_status' => 'cancelled',
			] );

			/**
			 * Fires when an appointment is cancelled due to a Stripe refund.
			 *
			 * @param int   $appointment_id The appointment post ID.
			 * @param array $charge         The Stripe Charge object.
			 */
			do_action( 'clisyc_appointment_refund_cancelled', $appointment_id, $charge );
		}

		/**
		 * Fires when a Stripe refund is processed for an appointment.
		 *
		 * @param int   $appointment_id The appointment post ID.
		 * @param array $charge         The Stripe Charge object.
		 * @param bool  $is_full_refund Whether this was a complete refund.
		 */
		do_action( 'clisyc_payment_refunded', $appointment_id, $charge, $is_full_refund );

		return new \WP_REST_Response( [ 'received' => true ], 200 );
	}

	// =====================================================================
	// Checkout Session
	// =====================================================================

	/**
	 * Creates a Stripe Checkout Session for an appointment.
	 *
	 * If the appointment author is a logged-in WordPress user, a persistent
	 * Stripe Customer object is created (or reused), enabling future features
	 * like saved payment methods and subscriptions.
	 *
	 * @param int    $appointment_id The appointment post ID.
	 * @param int    $amount_cents   Total amount in the smallest currency unit (e.g. cents).
	 * @param string $currency       Three-letter ISO currency code (default: site WC currency or 'usd').
	 * @param string $description    Line-item name shown on the Stripe checkout page.
	 * @return array{ url: string, session_id: string }|\WP_Error
	 */
	public function create_checkout_session( int $appointment_id, int $amount_cents, string $currency = '', string $description = '' ) {
		$secret_key = get_option( Constants::OPTION_STRIPE_KEY_SECRET, '' );
		if ( empty( $secret_key ) ) {
			return new \WP_Error( 'stripe_not_configured', __( 'Stripe secret key is not configured.', 'client-sync' ) );
		}

		if ( empty( $currency ) ) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? strtolower( get_woocommerce_currency() ) : 'usd';
		}

		if ( empty( $description ) ) {
			$description = get_the_title( $appointment_id );
		}

		$success_page_id = absint( get_option( Constants::OPTION_BOOKING_SUCCESS_PAGE, 0 ) );
		$success_url     = $success_page_id > 0
			? add_query_arg( [ 'appointment_id' => $appointment_id, 'clisyc_stripe' => '1' ], get_permalink( $success_page_id ) )
			: add_query_arg( [ 'appointment_id' => $appointment_id, 'clisyc_stripe' => '1' ], home_url() );
		$cancel_url      = wp_get_referer() ?: home_url();

		$body = [
			'mode'                 => 'payment',
			'success_url'          => $success_url,
			'cancel_url'           => $cancel_url,
			'line_items[0][price_data][currency]'             => $currency,
			'line_items[0][price_data][unit_amount]'           => $amount_cents,
			'line_items[0][price_data][product_data][name]'    => $description,
			'line_items[0][quantity]'                           => 1,
			'metadata[appointment_id]'                         => $appointment_id,
			'metadata[site_url]'                               => home_url(),
		];

		// Attach a persistent Stripe Customer object (or fall back to email).
		$author_id = (int) get_post_field( 'post_author', $appointment_id );
		if ( $author_id > 0 ) {
			$user = get_user_by( 'id', $author_id );
			if ( $user && is_email( $user->user_email ) ) {
				$customer_id = $this->get_or_create_customer( $user, $secret_key );
				if ( ! is_wp_error( $customer_id ) ) {
					$body['customer'] = $customer_id;
				} else {
					// Graceful fallback: use email-only mode if Customer creation fails.
					$body['customer_email'] = $user->user_email;
				}
			}
		}

		$response = wp_remote_post( self::API_BASE . '/checkout/sessions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $secret_key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'    => $body,
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error( 'stripe_api_error', __( 'Invalid response from Stripe API.', 'client-sync' ) );
		}

		if ( $status < 200 || $status >= 300 || empty( $data['url'] ) ) {
			$msg = $data['error']['message'] ?? __( 'Stripe API request failed.', 'client-sync' );
			return new \WP_Error( 'stripe_api_error', $msg );
		}

		// Store session ID on the appointment for webhook reconciliation.
		update_post_meta( $appointment_id, Constants::META_STRIPE_SESSION_ID, sanitize_text_field( $data['id'] ) );

		return [
			'url'        => $data['url'],
			'session_id' => $data['id'],
		];
	}

	// =====================================================================
	// Stripe Customer Management
	// =====================================================================

	/**
	 * Retrieves an existing Stripe Customer ID for a WordPress user, or creates one.
	 *
	 * The Customer ID is cached in user meta for reuse across sessions, enabling
	 * saved payment methods and future subscription billing.
	 *
	 * @param \WP_User $user       The WordPress user.
	 * @param string   $secret_key Stripe secret key.
	 * @return string|\WP_Error Stripe Customer ID (cus_xxx) on success.
	 */
	public function get_or_create_customer( \WP_User $user, string $secret_key = '' ) {
		if ( empty( $secret_key ) ) {
			$secret_key = get_option( Constants::OPTION_STRIPE_KEY_SECRET, '' );
			if ( empty( $secret_key ) ) {
				return new \WP_Error( 'stripe_not_configured', __( 'Stripe secret key is not configured.', 'client-sync' ) );
			}
		}

		// Check for an existing stored Customer ID.
		$customer_id = get_user_meta( $user->ID, Constants::META_STRIPE_CUSTOMER_ID, true );
		if ( ! empty( $customer_id ) ) {
			return $customer_id;
		}

		// Create a new Stripe Customer.
		$body = [
			'email'           => $user->user_email,
			'name'            => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
			'metadata[wp_user_id]' => $user->ID,
			'metadata[site_url]'   => home_url(),
		];

		$response = wp_remote_post( self::API_BASE . '/customers', [
			'headers' => [
				'Authorization' => 'Bearer ' . $secret_key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'    => $body,
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error( 'stripe_customer_error', __( 'Invalid response from Stripe Customers API.', 'client-sync' ) );
		}

		if ( $status < 200 || $status >= 300 || empty( $data['id'] ) ) {
			$msg = $data['error']['message'] ?? __( 'Failed to create Stripe customer.', 'client-sync' );
			return new \WP_Error( 'stripe_customer_error', $msg );
		}

		// Persist for future sessions.
		update_user_meta( $user->ID, Constants::META_STRIPE_CUSTOMER_ID, sanitize_text_field( $data['id'] ) );

		return $data['id'];
	}

	// =====================================================================
	// Refund
	// =====================================================================

	/**
	 * Creates a Stripe refund for an appointment.
	 *
	 * @param int $appointment_id The appointment post ID.
	 * @param int $amount_cents   Amount to refund in cents. 0 = full refund.
	 * @return array|\WP_Error Stripe refund data on success.
	 */
	public function create_refund( int $appointment_id, int $amount_cents = 0 ) {
		$secret_key = get_option( Constants::OPTION_STRIPE_KEY_SECRET, '' );
		if ( empty( $secret_key ) ) {
			return new \WP_Error( 'stripe_not_configured', __( 'Stripe secret key is not configured.', 'client-sync' ) );
		}

		$payment_intent = get_post_meta( $appointment_id, Constants::META_STRIPE_PAYMENT_INTENT, true );
		if ( empty( $payment_intent ) ) {
			return new \WP_Error( 'no_payment_intent', __( 'No Stripe payment intent found for this appointment.', 'client-sync' ) );
		}

		$body = [
			'payment_intent' => $payment_intent,
			'metadata[appointment_id]' => $appointment_id,
			'metadata[site_url]'       => home_url(),
		];

		// Partial refund: specify amount. Omitting = full refund.
		if ( $amount_cents > 0 ) {
			$body['amount'] = $amount_cents;
		}

		$response = wp_remote_post( self::API_BASE . '/refunds', [
			'headers' => [
				'Authorization' => 'Bearer ' . $secret_key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'    => $body,
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error( 'stripe_refund_error', __( 'Invalid response from Stripe Refunds API.', 'client-sync' ) );
		}

		if ( $status < 200 || $status >= 300 || empty( $data['id'] ) ) {
			$msg = $data['error']['message'] ?? __( 'Stripe refund request failed.', 'client-sync' );
			return new \WP_Error( 'stripe_refund_error', $msg );
		}

		// Store refund ID and update payment status.
		update_post_meta( $appointment_id, Constants::META_STRIPE_REFUND_ID, sanitize_text_field( $data['id'] ) );

		$is_full = ( 'succeeded' === ( $data['status'] ?? '' ) ) && ( $amount_cents === 0 );
		update_post_meta( $appointment_id, Constants::META_PAYMENT_STATUS, $is_full ? 'refunded' : 'partially_refunded' );

		return $data;
	}

	// =====================================================================
	// Billing Portal
	// =====================================================================

	/**
	 * Creates a Stripe Billing Portal session so a customer can manage
	 * their payment methods, invoices, and subscriptions.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $return_url URL to redirect to after the portal session.
	 * @return array{ url: string }|\WP_Error Portal session URL on success.
	 */
	public function create_billing_portal_session( int $user_id, string $return_url = '' ) {
		$secret_key = get_option( Constants::OPTION_STRIPE_KEY_SECRET, '' );
		if ( empty( $secret_key ) ) {
			return new \WP_Error( 'stripe_not_configured', __( 'Stripe secret key is not configured.', 'client-sync' ) );
		}

		$customer_id = get_user_meta( $user_id, Constants::META_STRIPE_CUSTOMER_ID, true );
		if ( empty( $customer_id ) ) {
			return new \WP_Error( 'no_customer', __( 'No Stripe customer record found for this account.', 'client-sync' ) );
		}

		if ( empty( $return_url ) ) {
			$return_url = get_permalink();
			if ( empty( $return_url ) ) {
				$return_url = home_url();
			}
		}

		$response = wp_remote_post( self::API_BASE . '/billing_portal/sessions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $secret_key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'    => [
				'customer'   => $customer_id,
				'return_url' => $return_url,
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error( 'stripe_portal_error', __( 'Invalid response from Stripe Billing Portal API.', 'client-sync' ) );
		}

		if ( $status < 200 || $status >= 300 || empty( $data['url'] ) ) {
			$msg = $data['error']['message'] ?? __( 'Failed to create billing portal session.', 'client-sync' );
			return new \WP_Error( 'stripe_portal_error', $msg );
		}

		return [ 'url' => $data['url'] ];
	}

	// =====================================================================
	// Subscriptions
	// =====================================================================

	/**
	 * Creates a Stripe Checkout Session in subscription mode.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $price_id    Stripe Price ID (price_xxx).
	 * @param int    $plan_id     Membership plan post ID (for metadata).
	 * @param int    $trial_days  Trial period in days (0 = none).
	 * @param string $success_url URL to redirect to after successful checkout.
	 * @param string $cancel_url  URL to redirect to on cancellation.
	 * @return array{ url: string, session_id: string }|\WP_Error
	 */
	public function create_subscription_checkout_session(
		int $user_id,
		string $price_id,
		int $plan_id,
		int $trial_days = 0,
		string $success_url = '',
		string $cancel_url = ''
	) {
		$secret_key = get_option( Constants::OPTION_STRIPE_KEY_SECRET, '' );
		if ( empty( $secret_key ) ) {
			return new \WP_Error( 'stripe_not_configured', __( 'Stripe secret key is not configured.', 'client-sync' ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'invalid_user', __( 'User not found.', 'client-sync' ) );
		}

		$customer_id = $this->get_or_create_customer( $user, $secret_key );
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		if ( empty( $success_url ) ) {
			$success_url = home_url();
		}
		if ( empty( $cancel_url ) ) {
			$cancel_url = wp_get_referer() ?: home_url();
		}

		$body = [
			'mode'                                => 'subscription',
			'customer'                            => $customer_id,
			'line_items[0][price]'                => $price_id,
			'line_items[0][quantity]'              => 1,
			'success_url'                         => $success_url,
			'cancel_url'                          => $cancel_url,
			'metadata[membership_plan_id]'        => $plan_id,
			'metadata[wp_user_id]'                => $user_id,
			'metadata[site_url]'                  => home_url(),
		];

		if ( $trial_days > 0 ) {
			$body['subscription_data[trial_period_days]'] = $trial_days;
		}

		$response = wp_remote_post( self::API_BASE . '/checkout/sessions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $secret_key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'    => $body,
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error( 'stripe_api_error', __( 'Invalid response from Stripe API.', 'client-sync' ) );
		}

		if ( $status < 200 || $status >= 300 || empty( $data['url'] ) ) {
			$msg = $data['error']['message'] ?? __( 'Stripe API request failed.', 'client-sync' );
			return new \WP_Error( 'stripe_api_error', $msg );
		}

		return [
			'url'        => $data['url'],
			'session_id' => $data['id'],
		];
	}

	/**
	 * Retrieves a Stripe Subscription by ID.
	 *
	 * @param string $subscription_id Stripe Subscription ID (sub_xxx).
	 * @return array|\WP_Error Subscription data on success.
	 */
	public function retrieve_subscription( string $subscription_id ) {
		$secret_key = get_option( Constants::OPTION_STRIPE_KEY_SECRET, '' );
		if ( empty( $secret_key ) ) {
			return new \WP_Error( 'stripe_not_configured', __( 'Stripe secret key is not configured.', 'client-sync' ) );
		}

		$response = wp_remote_get( self::API_BASE . '/subscriptions/' . urlencode( $subscription_id ), [
			'headers' => [
				'Authorization' => 'Bearer ' . $secret_key,
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error( 'stripe_sub_error', __( 'Invalid response from Stripe Subscriptions API.', 'client-sync' ) );
		}

		if ( $status < 200 || $status >= 300 || empty( $data['id'] ) ) {
			$msg = $data['error']['message'] ?? __( 'Failed to retrieve subscription.', 'client-sync' );
			return new \WP_Error( 'stripe_sub_error', $msg );
		}

		return $data;
	}

	/**
	 * Cancels a Stripe Subscription at the end of the current billing period.
	 *
	 * @param string $subscription_id Stripe Subscription ID (sub_xxx).
	 * @param bool   $immediately     Cancel immediately instead of at period end.
	 * @return array|\WP_Error Updated subscription data on success.
	 */
	public function cancel_subscription( string $subscription_id, bool $immediately = false ) {
		$secret_key = get_option( Constants::OPTION_STRIPE_KEY_SECRET, '' );
		if ( empty( $secret_key ) ) {
			return new \WP_Error( 'stripe_not_configured', __( 'Stripe secret key is not configured.', 'client-sync' ) );
		}

		if ( $immediately ) {
			// DELETE cancels immediately.
			$response = wp_remote_request( self::API_BASE . '/subscriptions/' . urlencode( $subscription_id ), [
				'method'  => 'DELETE',
				'headers' => [
					'Authorization' => 'Bearer ' . $secret_key,
				],
				'timeout' => 15,
			] );
		} else {
			// PATCH with cancel_at_period_end = true.
			$response = wp_remote_post( self::API_BASE . '/subscriptions/' . urlencode( $subscription_id ), [
				'headers' => [
					'Authorization' => 'Bearer ' . $secret_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body'    => [
					'cancel_at_period_end' => 'true',
				],
				'timeout' => 15,
			] );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error( 'stripe_cancel_error', __( 'Invalid response from Stripe API.', 'client-sync' ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			$msg = $data['error']['message'] ?? __( 'Failed to cancel subscription.', 'client-sync' );
			return new \WP_Error( 'stripe_cancel_error', $msg );
		}

		return $data;
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	/**
	 * Finds an appointment post ID by its stored Stripe Payment Intent ID.
	 *
	 * @param string $payment_intent_id Stripe Payment Intent ID (pi_xxx).
	 * @return int Appointment post ID, or 0 if not found.
	 */
	private function find_appointment_by_payment_intent( string $payment_intent_id ): int {
		global $wpdb;

		// Direct meta query — indexed on meta_key + meta_value.
		$appointment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value = %s
				 LIMIT 1",
				Constants::META_STRIPE_PAYMENT_INTENT,
				$payment_intent_id
			)
		);

		return (int) $appointment_id;
	}

	// =====================================================================
	// Signature Verification
	// =====================================================================

	/**
	 * Verifies a Stripe webhook signature (v1 scheme).
	 *
	 * @param string $payload Raw request body.
	 * @param string $sig     Stripe-Signature header value.
	 * @param string $secret  Webhook signing secret.
	 * @return array|\WP_Error Decoded event on success.
	 */
	private function verify_signature( string $payload, string $sig, string $secret ) {
		$elements = [];
		foreach ( explode( ',', $sig ) as $part ) {
			[ $key, $value ] = array_pad( explode( '=', $part, 2 ), 2, '' );
			$elements[ trim( $key ) ][] = $value;
		}

		$timestamp  = $elements['t'][0] ?? '';
		$signatures = $elements['v1'] ?? [];

		if ( empty( $timestamp ) || empty( $signatures ) ) {
			return new \WP_Error( 'stripe_sig', __( 'Invalid signature format.', 'client-sync' ) );
		}

		// Reject events older than 5 minutes to prevent replay attacks.
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return new \WP_Error( 'stripe_sig', __( 'Timestamp too old.', 'client-sync' ) );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		$valid = false;
		foreach ( $signatures as $s ) {
			if ( hash_equals( $expected, $s ) ) {
				$valid = true;
				break;
			}
		}

		if ( ! $valid ) {
			return new \WP_Error( 'stripe_sig', __( 'Signature verification failed.', 'client-sync' ) );
		}

		$event = json_decode( $payload, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $event ) ) {
			return new \WP_Error( 'stripe_sig', __( 'Invalid event payload.', 'client-sync' ) );
		}

		return $event;
	}
}
