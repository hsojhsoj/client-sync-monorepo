# Pro Features Overview

Client Sync Pro is a premium add-on that extends the free Client Sync plugin with advanced modules for growing businesses. It activates automatically when installed alongside the free plugin.

---

## Modules Included in Pro

### Membership Plans & Subscriptions
Create recurring subscription plans with Stripe billing. Define access rules that grant booking limits and percentage discounts to members.

- **Membership Plans CPT** — Create and manage plans with pricing, descriptions, and access rules
- **Stripe Subscriptions** — Recurring billing handled by Stripe
- **Frontend Pricing Cards** — Display plans with `[clisyc_membership_plans]` shortcode
- **Subscriber Benefits** — Automatic discount and booking limit enforcement at booking time
- **Starter Plan Templates** — One-click creation of common plan structures

**Learn more:** [Membership Plans](Membership-Plans)

---

### Subscribers Admin Page
A dedicated WP_List_Table page for monitoring all users with active Stripe subscriptions.

- **Subscriber overview** — Name, email, plan, status, renewal date, and Stripe subscription ID at a glance
- **Status badges** — Color-coded badges (Active, Trialing, Past Due, Canceled)
- **Filtering** — Filter by subscription status or specific plan
- **Search** — Find subscribers by name, email, or Stripe subscription ID
- **Stripe links** — Direct links to customer and subscription pages in Stripe Dashboard

**Learn more:** [Subscribers Admin Page](Subscribers-Admin-Page)

---

### Custom Forms & Lead Capture
Build custom forms for lead capture, contact inquiries, and intake forms. No third-party form plugin needed.

- **Visual Form Builder** — Drag-and-drop field editor with the `clisyc_form` CPT
- **Field Types** — Text, email, phone, textarea, select, checkbox, radio, and more
- **Lead Management** — Submissions are stored as leads with a dedicated admin list table
- **Frontend Rendering** — Display forms with `[clisyc_form id="123"]`
- **User Account Integration** — Forms can create or update user accounts on submission

**Admin pages:** Client Sync > Forms, Client Sync > Leads

---

### Output Template Builder
A visual notification template builder for creating professional email layouts.

- **Section-Based Builder** — Add text blocks, dynamic fields, and content sections
- **Placeholder Support** — All standard appointment and client placeholders
- **Trigger System** — Assign templates to specific events (new booking, reminder, cancellation, payment, etc.)
- **Multi-Channel** — Output to email (more channels planned)
- **Preview Mode** — Preview templates before they go live
- **Setup Template Integration** — Onboarding templates include pre-built notification templates

**Admin page:** Client Sync > Output Templates

---

### Seat Selection & Venue Maps
Interactive SVG-based venue maps for seat-specific bookings. Ideal for theaters, arenas, and event spaces.

- **Venue CPT** — Create venues with custom SVG seat maps
- **Interactive Seat Picker** — Customers click to select specific seats on a visual map
- **Seat Pricing** — Different price tiers for different seat zones
- **Real-Time Holds** — Temporary seat holds prevent double-booking during checkout
- **Seat Transfer** — Admin meta box for reassigning seats between customers
- **REST API** — Dedicated endpoints for seat availability and hold management

**Admin page:** Client Sync > Venues (within seat-selection module)

---

### Booking Packages
Pre-defined bundles of bookings sold as a single package.

- **Package CPT** — Create packages with included bookings, pricing, and expiry rules
- **Package Redemption** — Customers redeem package credits when booking

---

### SMS Notifications
Send text message notifications to clients via Twilio.

- **Event-Based SMS** — Configure SMS for any appointment event (confirmation, reminder, cancellation)
- **Placeholder Support** — Same placeholders as email templates
- **Test SMS** — Built-in tool to verify Twilio credentials

**Setup:** Client Sync > Settings > Integrations > Twilio SMS

---

### Webhook Notifications
Send appointment data to external services via HTTP webhooks.

- **Multiple Endpoints** — Configure as many webhook URLs as needed
- **Event Selection** — Choose which events trigger each webhook
- **JSON Payload** — Full appointment data in the webhook body
- **Integration Ready** — Works with Zapier, Make, n8n, and any webhook consumer

**Setup:** Client Sync > Settings > Integrations > Webhooks

---

### Video Conferencing
Auto-generate video meeting links for virtual appointments.

- **Google Meet** — Via Google Calendar integration
- **Zoom** — Via Zoom Server-to-Server OAuth app
- **Automatic Link Generation** — Meeting links created when appointments are confirmed
- **Client Notification** — Meeting links included in confirmation emails

**Setup:** Client Sync > Settings > Integrations > Video Conferencing

---

### Check-In System
Venue-based check-in interface for staff to mark client arrivals.

- **Check-In Dashboard** — Dedicated admin interface for managing arrivals
- **Status Updates** — Mark appointments as checked-in
- **Works with Seat Selection** — Validate seat assignments at check-in

**Admin page:** Client Sync > Check-In

---

### WooCommerce Subscriptions Integration
Bridge between WooCommerce Subscriptions plugin and Client Sync memberships.

- **Status Sync** — WooCommerce subscription status changes reflect in Client Sync
- **Renewal Handling** — Subscription renewals managed by WooCommerce
- **Existing Store Integration** — For businesses already using WooCommerce Subscriptions

**Learn more:** [WooCommerce Integration](WooCommerce-Integration)

---

## What's Included in Free vs Pro

| Feature | Free | Pro |
|---------|------|-----|
| Appointment booking & management | Yes | Yes |
| Custom dimensions (services, staff, rooms) | Yes | Yes |
| Weekly scheduling with time slots | Yes | Yes |
| 14 setup templates | Yes | Yes |
| Email notifications | Yes | Yes |
| Stripe direct payments | Yes | Yes |
| WooCommerce basic integration | Yes | Yes |
| Calendar views (8 types) | Yes | Yes |
| Client self-service | Yes | Yes |
| Waitlist management | Yes | Yes |
| Google Calendar sync | Yes | Yes |
| HIPAA compliance mode | Yes | Yes |
| 20 shortcodes | Yes | Yes |
| Membership plans & subscriptions | — | Yes |
| Subscribers admin page | — | Yes |
| Custom forms & lead capture | — | Yes |
| Output template builder | — | Yes |
| Seat selection & venue maps | — | Yes |
| Booking packages | — | Yes |
| SMS notifications (Twilio) | — | Yes |
| Webhook notifications | — | Yes |
| Video conferencing (Zoom, Meet) | — | Yes |
| Check-in system | — | Yes |
| WooCommerce Subscriptions | — | Yes |
| Resource dimensions | — | Yes |

---

## Activation

1. Purchase a Pro license from [dependentmedia.com/client-sync](https://dependentmedia.com/client-sync/)
2. Install the free Client Sync plugin (if not already active)
3. Upload and activate `client-sync-pro.zip`
4. Enter your license key at **Client Sync > License**
5. Pro modules activate automatically — no additional configuration needed

---

## Related Pages

- [Getting Started](Getting-Started) — Installation guide
- [Membership Plans](Membership-Plans) — Subscription plan setup
- [Subscribers Admin Page](Subscribers-Admin-Page) — Subscriber monitoring
- [Notifications & Output Templates](Notifications) — All notification channels
- [Stripe Payments & Subscriptions](Stripe-Payments) — Payment configuration
