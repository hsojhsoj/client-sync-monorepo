# WooCommerce Integration

Client Sync integrates with WooCommerce to handle appointment payments through your existing WooCommerce checkout flow. This is an alternative to the direct Stripe integration — use one or the other based on your needs.

---

## When to Use WooCommerce vs Stripe Direct

| Feature | WooCommerce | Stripe Direct |
|---------|-------------|---------------|
| **Setup complexity** | Requires WooCommerce + payment gateway | Just Stripe API keys |
| **Payment gateways** | Any WooCommerce gateway (PayPal, Stripe, Square, etc.) | Stripe only |
| **Order management** | Full WooCommerce orders with invoices | Minimal — managed in Stripe Dashboard |
| **Tax handling** | WooCommerce tax rules apply | Not included |
| **Coupons** | WooCommerce coupons work | Not supported |
| **Subscriptions** | Requires WooCommerce Subscriptions plugin | Built-in via Stripe |
| **Best for** | Stores already using WooCommerce | Simpler setups, subscription-focused businesses |

---

## Setup

### Prerequisites

- WooCommerce plugin installed and activated
- At least one WooCommerce payment gateway configured
- A WooCommerce product to link to appointments

### Configuration

1. Go to **Client Sync > Settings > Payments**
2. Check **Enable WooCommerce Payments**
3. The plugin will prompt you to create or link a WooCommerce product

### Linking an Appointment Product

Client Sync uses a WooCommerce product as the "appointment item" added to the cart. You can:

- **Create a new product** — Click the "Create Product" button in settings. A simple product is created automatically.
- **Link an existing product** — Select an existing WooCommerce product from the dropdown.

The product price should match your appointment pricing, or you can let Client Sync override the price dynamically based on the selected service.

---

## Booking Flow with WooCommerce

1. Customer selects a service and time slot on the booking form
2. Customer fills in their details and clicks "Book Now"
3. An appointment is created with **Pending Payment** status
4. The appointment product is added to the WooCommerce cart with the correct price
5. Customer proceeds through the standard WooCommerce checkout
6. After successful payment, the appointment is confirmed automatically
7. Customer is redirected to the Booking Confirmed page

---

## WooCommerce Subscriptions (Pro)

If you have the [WooCommerce Subscriptions](https://woo.com/products/woocommerce-subscriptions/) plugin, Client Sync Pro can integrate with it for recurring membership billing:

- Membership plan subscriptions can be managed through WooCommerce Subscriptions
- Subscription status changes (active, on-hold, cancelled) sync with Client Sync membership status
- Renewal payments are handled by WooCommerce Subscriptions

> **Note:** For most new setups, the built-in Stripe subscription support is simpler. The WooCommerce Subscriptions integration is primarily for stores that already use WooCommerce Subscriptions for other products.

---

## Refunds

When WooCommerce refunds are processed:

1. The order is refunded through WooCommerce's standard refund flow
2. Client Sync detects the refund via WooCommerce hooks
3. The linked appointment status is updated accordingly

You can configure the refund policy in **Client Sync > Settings > Behavior > Client Self-Service**:
- **No Refund** — Cancellations don't trigger refunds
- **Attempt Full Refund via WooCommerce** — Automatic refund when client cancels within the allowed window

---

## Troubleshooting

### Appointments Not Confirming After Payment
- Ensure the WooCommerce product is correctly linked in Client Sync settings
- Check that WooCommerce order status changes to "Completed" or "Processing" after payment
- Verify that no conflicting plugins are interfering with WooCommerce hooks

### Cart Issues
- Clear the WooCommerce cart if old appointment items are stuck
- Ensure the linked product is published and visible

### Price Mismatches
- The appointment price is set on the primary dimension item (service)
- If using dynamic pricing, ensure the dimension item has the correct price field filled in

---

## Related Pages

- [Stripe Payments & Subscriptions](Stripe-Payments) — Alternative payment method with Stripe Direct
- [Membership Plans](Membership-Plans) — Subscription plans
- [Settings Reference](Settings-Reference) — Payment settings
