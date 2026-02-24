# Client Sync - Documentation

**Client Sync** is a WordPress plugin for managing client registrations, appointments, payments, and appointment notes from a single, integrated system. It supports flexible scheduling with custom dimensions (services, staff, rooms, etc.), Stripe and WooCommerce payments, membership plans with recurring subscriptions, email and SMS notifications, and much more.

---

## Quick Links

| Topic | Description |
|-------|-------------|
| [Getting Started](Getting-Started) | Installation, activation, and initial setup |
| [Onboarding Wizard & Setup Templates](Onboarding-Wizard) | Pre-configured templates to get running in minutes |
| [Dimensions & Scheduling](Dimensions-and-Scheduling) | How services, staff, rooms, and resources work |
| [Shortcodes Reference](Shortcodes-Reference) | Every shortcode with attributes and examples |
| [Settings Reference](Settings-Reference) | All configuration options explained |
| [Stripe Payments & Subscriptions](Stripe-Payments) | Accepting payments directly with Stripe |
| [Membership Plans](Membership-Plans) | Creating and managing recurring membership subscriptions |
| [Subscribers Admin Page](Subscribers-Admin-Page) | Monitoring subscribers and subscription statuses |
| [Notifications & Output Templates](Notifications) | Email, SMS, and webhook notification setup |
| [WooCommerce Integration](WooCommerce-Integration) | Using WooCommerce for payment processing |
| [Pro Features Overview](Pro-Features) | Everything included in Client Sync Pro |

---

## Plugin Architecture

Client Sync is distributed as two plugins:

- **Client Sync (Free)** — The core booking and scheduling engine, available on WordPress.org. Includes calendar views, appointment management, custom dimensions, email notifications, Stripe direct checkout, and 14 pre-configured setup templates.

- **Client Sync Pro** — A lightweight add-on that unlocks advanced modules: custom forms, lead capture, membership plans with Stripe subscriptions, output template builder, seat selection with SVG venue maps, booking packages, SMS notifications, webhooks, WooCommerce Subscriptions integration, and more.

Both plugins share a common codebase. Pro features are loaded automatically when the Pro add-on is activated alongside the free plugin.

---

## Key Concepts

### Dimensions
Dimensions are the building blocks of your booking system. A dimension is any entity that participates in a booking: services, staff members, rooms, equipment, etc. You define which dimensions matter for your business and how they relate to each other. See [Dimensions & Scheduling](Dimensions-and-Scheduling).

### Appointments
An appointment is a confirmed booking for a specific time slot, optionally linked to one or more dimensions. Appointments flow through statuses: Pending, Confirmed, Completed, Cancelled, No-Show, and payment-related statuses when payments are enabled.

### Membership Plans
Plans allow you to offer recurring subscriptions with booking limits and percentage discounts. Plans are managed via the Membership Plans CPT and displayed on the frontend with the `[clisyc_membership_plans]` shortcode. See [Membership Plans](Membership-Plans).

### Shortcodes
Client Sync provides 20 shortcodes to build your frontend booking experience. From full booking calendars to user account pages to conditional content blocks, shortcodes let you assemble a complete client-facing interface. See [Shortcodes Reference](Shortcodes-Reference).

---

## System Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- MySQL 5.7 or higher / MariaDB 10.3 or higher
- For Stripe payments: SSL certificate (HTTPS)
- For WooCommerce payments: WooCommerce 7.0 or higher
- For SMS notifications (Pro): Twilio account
- For video conferencing (Pro): Google Meet or Zoom account

---

## Support

- **Plugin Homepage:** [dependentmedia.com/client-sync](https://dependentmedia.com/client-sync/)
- **WordPress.org:** [wordpress.org/plugins/client-sync](https://wordpress.org/plugins/client-sync/)

---

*Client Sync is developed by [Dependent Media](https://dependentmedia.com/).*
