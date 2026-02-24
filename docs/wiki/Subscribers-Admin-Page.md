# Subscribers Admin Page

The Subscribers page provides a centralized admin view of all users who have active Stripe subscriptions through your membership plans.

> **Requires:** Client Sync Pro with Memberships module and Stripe integration configured.

---

## Accessing the Page

Navigate to **Client Sync > Subscribers** in the WordPress admin sidebar. The page appears directly below "Memberships" in the menu.

---

## Table Columns

| Column | Description |
|--------|-------------|
| **Subscriber** | User's display name and email address. Row actions: "Edit User" (links to WordPress profile) and "View in Stripe" (links to Stripe customer page). |
| **Plan** | The membership plan name, linked to the plan's edit screen in WordPress. |
| **Status** | Colored status badge indicating the subscription state. |
| **Renewal Date** | The next billing date (current period end). Formatted using your WordPress date format setting. |
| **Subscription ID** | The Stripe subscription ID (`sub_xxx`), linked to the subscription page in Stripe Dashboard. |

---

## Status Badges

| Status | Color | Meaning |
|--------|-------|---------|
| **Active** | Green | Subscription is current and fully paid |
| **Trialing** | Blue | Subscriber is in a free trial period |
| **Past Due** | Yellow | Payment failed; Stripe is retrying |
| **Canceled** | Red | Subscription has been canceled |

---

## Filtering

Use the dropdown filters above the table to narrow results:

### Status Filter
Select a specific status to show only subscribers in that state:
- All Statuses (default)
- Active
- Trialing
- Past Due
- Canceled

### Plan Filter
Select a specific membership plan to show only its subscribers:
- All Plans (default)
- Each published membership plan appears in the dropdown

### Applying Filters
1. Select your desired filters
2. Click **Filter**
3. To clear filters, click the **Clear Filters** link that appears

---

## Searching

Use the search box to find subscribers by:
- **Name or email:** Type a name or email address to search user accounts
- **Subscription ID:** Type a Stripe subscription ID (starting with `sub_`) to find a specific subscription

---

## Sorting

Click column headers to sort the table:

| Column | Sort By |
|--------|---------|
| Subscriber | Display name (alphabetical) |
| Status | Subscription status |
| Renewal Date | Next billing date |

Click once for ascending, click again for descending.

---

## Stripe Dashboard Links

The Subscribers page includes direct links to Stripe for quick access:

- **View in Stripe** (row action on Subscriber column) — Opens the customer's profile in Stripe Dashboard
- **Subscription ID** link — Opens the specific subscription in Stripe Dashboard

Links automatically point to the correct dashboard:
- **Test mode:** If your Stripe secret key starts with `sk_test_`, links go to `dashboard.stripe.com/test/`
- **Live mode:** If using live keys, links go to `dashboard.stripe.com/`

---

## Empty State

When no subscribers exist yet, the page displays a centered message:
- "No subscribers found."
- "Subscribers will appear here once users sign up for a membership plan through Stripe."

---

## Common Tasks

### Check on a Past-Due Subscription
1. Filter by **Past Due** status
2. Click the Subscription ID to view it in Stripe
3. In Stripe, you can see payment retry attempts and update the billing method

### Find a Specific Customer's Subscription
1. Search by the customer's name or email
2. View their plan, status, and renewal date at a glance
3. Click "Edit User" to view their full WordPress profile with all subscription metadata

### See Who's on a Specific Plan
1. Use the Plan filter dropdown to select the plan
2. The table shows only subscribers on that plan with their statuses

---

## Related Pages

- [Membership Plans](Membership-Plans) — Creating and managing plans
- [Stripe Payments & Subscriptions](Stripe-Payments) — Stripe setup and webhook configuration
- [Pro Features Overview](Pro-Features) — All Pro module features
