# Stripe Payments & Subscriptions

Client Sync supports direct Stripe integration for one-time appointment payments and recurring membership subscriptions. No WooCommerce required — Stripe Checkout handles the payment flow securely.

---

## Overview

Client Sync offers two payment paths:

1. **Stripe Direct** — Redirect customers to Stripe Checkout for a one-time payment when booking an appointment. No additional plugins needed.
2. **Stripe Subscriptions (Pro)** — Recurring billing for membership plans. Customers subscribe to a plan and receive booking limits and discounts.

Both can run simultaneously. You might use subscriptions for membership plans while also accepting one-time payments for non-members.

---

## Setting Up Stripe

### 1. Get Your API Keys

1. Log in to your [Stripe Dashboard](https://dashboard.stripe.com/)
2. Navigate to **Developers > API Keys**
3. Copy your **Publishable key** and **Secret key**
4. For testing, use the test mode keys (starting with `pk_test_` and `sk_test_`)

### 2. Configure in WordPress

1. Go to **Client Sync > Settings > Payments**
2. Check **Enable Stripe Payments**
3. Enter your:
   - **Publishable Key** (`pk_test_...` or `pk_live_...`)
   - **Secret Key** (`sk_test_...` or `sk_live_...`)

### 3. Set Up the Webhook

Stripe sends events (payment success, failure, refunds) to your site via webhooks. You need to configure this in Stripe:

1. In Stripe Dashboard, go to **Developers > Webhooks**
2. Click **Add endpoint**
3. Set the endpoint URL to: `https://yoursite.com/wp-json/clisyc/v1/stripe-webhook`
4. Select the following events:
   - `checkout.session.completed`
   - `payment_intent.payment_failed`
   - `charge.refunded`
   - `customer.subscription.created` (for memberships)
   - `customer.subscription.updated` (for memberships)
   - `customer.subscription.deleted` (for memberships)
   - `invoice.payment_succeeded` (for memberships)
   - `invoice.payment_failed` (for memberships)
5. After creating the endpoint, copy the **Signing Secret** (`whsec_...`)
6. Back in WordPress, paste it in the **Webhook Signing Secret** field

### 4. Test the Integration

While using test mode keys:

1. Create a dimension item with a price set
2. Visit your booking page and make a test booking
3. You'll be redirected to Stripe Checkout
4. Use Stripe's [test card numbers](https://stripe.com/docs/testing#cards):
   - `4242 4242 4242 4242` — Successful payment
   - `4000 0000 0000 0002` — Declined payment
5. After payment, you should be redirected to your Booking Confirmed page
6. Check the appointment in WordPress — it should be marked as confirmed/paid

### 5. Go Live

When ready for real payments:

1. Switch to live mode in Stripe Dashboard
2. Copy your live API keys (`pk_live_...`, `sk_live_...`)
3. Update the keys in **Client Sync > Settings > Payments**
4. Create a new webhook endpoint with your live signing secret
5. Ensure your site has a valid SSL certificate (HTTPS)

---

## Payment Flow

### One-Time Payments (Booking)

1. Customer selects a service and time slot on the booking form
2. Customer fills in their details and clicks "Book Now"
3. An appointment is created with **Pending Payment** status
4. Customer is redirected to Stripe Checkout
5. After successful payment, Stripe sends a `checkout.session.completed` webhook
6. Client Sync updates the appointment to **Confirmed** status
7. Customer is redirected to the Booking Confirmed page

If payment fails:
- The appointment status is updated to reflect the failure
- Customer can retry from their account page

### Subscription Payments (Memberships)

1. Customer visits the membership plans page (`[clisyc_membership_plans]`)
2. Customer clicks "Subscribe" on a plan
3. Customer is redirected to Stripe Checkout for the subscription
4. After successful payment, Stripe sends subscription webhooks
5. Client Sync stores the subscription data on the user's profile:
   - Subscription ID
   - Plan ID
   - Status (active, trialing, past_due, canceled)
   - Current period end date
6. The customer now receives their plan benefits (booking limits, discounts)

---

## Test Mode vs Live Mode

Client Sync automatically detects whether you're using test or live keys:

- **Test keys** (`sk_test_...`): All Stripe Dashboard links in the admin point to the test dashboard
- **Live keys** (`sk_live_...`): Links point to the live dashboard

You can safely develop and test with test keys. No real charges are made. Switch to live keys only when ready for production.

---

## Webhook Events

| Event | What Client Sync Does |
|-------|----------------------|
| `checkout.session.completed` | Confirms the appointment and marks it as paid |
| `payment_intent.payment_failed` | Updates the appointment to payment-failed status |
| `charge.refunded` | Processes the refund and updates appointment status |
| `customer.subscription.created` | Stores subscription data on the user profile |
| `customer.subscription.updated` | Updates subscription status (e.g., active to past_due) |
| `customer.subscription.deleted` | Marks subscription as canceled |
| `invoice.payment_succeeded` | Updates the subscription period end date |
| `invoice.payment_failed` | Marks subscription as past_due |

---

## Stripe Dashboard Links

The admin interface provides direct links to Stripe for quick access:

- **Subscribers page:** Each subscriber row links to their Stripe customer profile and subscription
- **User profile:** Stripe customer ID and subscription ID are shown in the user's WordPress profile
- Links automatically use the test or live dashboard URL based on your API key prefix

---

## Troubleshooting

### Webhooks Not Working
- Verify the webhook URL is correct: `https://yoursite.com/wp-json/clisyc/v1/stripe-webhook`
- Ensure the signing secret matches what Stripe shows
- Check that your site's REST API is accessible (test by visiting `/wp-json/` in a browser)
- Look at Stripe Dashboard > Developers > Webhooks for failed delivery attempts

### Payments Not Confirming
- Check that the `checkout.session.completed` event is included in your webhook
- Verify the webhook signing secret is correct
- Check WordPress debug logs for any errors during webhook processing

### Test Cards Not Working
- Make sure you're using test mode keys (`pk_test_`, `sk_test_`)
- Use the exact test card number: `4242 4242 4242 4242` with any future expiry date and any 3-digit CVC

---

## Related Pages

- [Membership Plans](Membership-Plans) — Creating subscription plans
- [Subscribers Admin Page](Subscribers-Admin-Page) — Monitoring subscription statuses
- [WooCommerce Integration](WooCommerce-Integration) — Alternative payment method
- [Settings Reference](Settings-Reference) — All payment settings
