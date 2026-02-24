# Membership Plans

Membership plans let you offer recurring subscriptions with booking limits and percentage discounts. Customers subscribe via Stripe and automatically receive their plan benefits when booking.

> **Requires:** Client Sync Pro and Stripe integration configured.

---

## Overview

A membership plan defines:
- A display price (e.g., "$89/month")
- A description shown to customers
- One or more **access rules** that control what members get

Access rules can grant:
- **Booking Limits** — A set number of bookings per period (e.g., 2 per month, unlimited)
- **Percentage Discounts** — A discount on booking prices (e.g., 15% off all services)

Multiple rules can be combined. For example, a "Wellness Plan" might include 2 bookings per month AND 15% off additional bookings.

---

## Creating a Membership Plan

1. Go to **Client Sync > Memberships**
2. Click **Add New**
3. Fill in the plan details:

### Plan Settings

| Field | Description |
|-------|-------------|
| **Title** | Plan name displayed to customers (e.g., "Wellness Plan") |
| **Price Display** | Text shown on the pricing card (e.g., "$89/month"). This is a display-only field — actual billing is configured in Stripe. |
| **Description** | Short description of the plan's value proposition |

### Access Rules

Click **Add Rule** to create access rules for the plan:

| Rule Type | Fields | Description |
|-----------|--------|-------------|
| **Booking Limit** | Service (or "All Services"), Limit Value, Period | Grants a set number of bookings. Period options: per month, per week, unlimited. Set Service to "All Services" (ID 0) to apply globally. |
| **Discount Percent** | Service (or "All Services"), Discount Value | Grants a percentage discount on booking prices. Set Service to "All Services" to apply globally. |

**Examples:**

| Plan | Rule 1 | Rule 2 |
|------|--------|--------|
| Wellness Plan | Booking Limit: 2/month (all services) | Discount: 15% off (all services) |
| VIP Access | Booking Limit: Unlimited (all services) | Discount: 40% off (all services) |
| Starter | Booking Limit: 1/month (specific service) | — |

### Stripe Configuration

The plan must be linked to a Stripe subscription product:

1. Create a **Product** in your Stripe Dashboard with a recurring price
2. The Stripe product ID or price ID is associated with the plan during checkout
3. When a customer subscribes, Stripe handles all recurring billing

---

## Displaying Plans on the Frontend

Use the `[clisyc_membership_plans]` shortcode on any page:

```
[clisyc_membership_plans]
```

### Shortcode Attributes

| Attribute | Default | Description |
|-----------|---------|-------------|
| `columns` | `3` | Number of columns in the pricing grid |
| `highlight` | *(empty)* | Post ID of a plan to visually highlight as "featured" |

**Examples:**
```
[clisyc_membership_plans columns="2"]
[clisyc_membership_plans columns="3" highlight="42"]
```

### What Customers See

Each plan is displayed as a pricing card showing:
- Plan name
- Display price
- Description
- A "Subscribe" button

Clicking "Subscribe" redirects to Stripe Checkout for the subscription. After successful payment, the customer returns to your site with an active membership.

---

## Starter Plan Templates

When the Memberships admin screen has no plans yet, a **Starter Plans** banner appears offering one-click template creation:

- **Basic + Premium Duo** — Creates a basic plan with limited bookings and a premium plan with unlimited access and higher discounts
- Templates pre-populate all fields so you can customize from a working starting point

---

## How Membership Benefits Work

### At Booking Time

When a subscriber books an appointment:

1. Client Sync checks the user's active subscription and plan
2. It evaluates the plan's access rules against the booking:
   - **Booking limits:** Has the member used their allotted bookings for the period?
   - **Discounts:** What percentage discount applies to this service?
3. The discount is applied to the booking price automatically
4. Booking limit counters are updated

### Subscription Status Effects

| Status | Can Book? | Discounts Apply? |
|--------|-----------|-----------------|
| Active | Yes | Yes |
| Trialing | Yes | Yes |
| Past Due | Yes (grace period) | Yes |
| Canceled | No membership benefits | No |

---

## Managing Plans

### Editing a Plan
Go to **Client Sync > Memberships** and click on the plan to edit. Changes to rules take effect for future bookings. Existing subscriptions are not affected by rule changes.

### Publishing and Unpublishing
- **Published** plans appear on the frontend pricing page
- **Draft** plans are hidden from customers but existing subscribers keep their benefits
- Never delete a plan that has active subscribers — unpublish it instead

---

## Setup Templates with Plans

Several [onboarding templates](Onboarding-Wizard) include pre-configured membership plans:

| Template | Plans Included |
|----------|---------------|
| Hair Salon | Styling packages with booking limits and discounts |
| Fitness Class | Unlimited class passes with member pricing |
| Advanced Clinic | Wellness Plan ($89/mo) + Comprehensive Care ($249/mo) |
| Modern Consultant | Monthly Retainer ($199/mo) + VIP Access ($499/mo) |
| Event Venue | Season Pass ($199/mo) + Patron Circle ($499/mo) |

These templates also create a "Membership Plans" page with the `[clisyc_membership_plans columns="2"]` shortcode.

---

## Related Pages

- [Stripe Payments & Subscriptions](Stripe-Payments) — Setting up Stripe for payments
- [Subscribers Admin Page](Subscribers-Admin-Page) — Monitoring active subscribers
- [Shortcodes Reference](Shortcodes-Reference) — The `[clisyc_membership_plans]` shortcode
- [Onboarding Wizard](Onboarding-Wizard) — Templates with pre-configured plans
