# Settings Reference

All plugin settings are managed from **Client Sync > Settings**. Settings are organized into tabs covering appearance, behavior, notifications, payments, automation, and integrations.

---

## Appearance

Controls how the booking calendar and UI elements look.

### Calendar Display

| Setting | Default | Description |
|---------|---------|-------------|
| Calendar Start Time | `08:00` | Earliest hour shown on the calendar |
| Calendar End Time | `18:00` | Latest hour shown on the calendar |
| Enabled Calendar Views | All | Which calendar views are available to users: Month Grid, Week Grid, Day Grid, Week Time Grid, Day Time Grid, Week List, Day List, Month List |
| Default Initial View | Week Time Grid | Which view loads when the calendar first opens |
| Calendar Slot Height | 48px/hour | Vertical size of time slots. Options: 48px, 60px, 80px, 100px, 120px, 150px per hour |

### Calendar Event Colors

| Setting | Default | Description |
|---------|---------|-------------|
| Available Slot Background | Emerald green | Background color for bookable slots |
| Available Slot Text | White | Text color for bookable slots |
| Booked Slot Background | Red | Background color for already-booked slots |
| Booked Slot Text | White | Text color for booked slots |
| Blocked Slot Background | Gray | Background color for admin-blocked periods |
| Blocked Slot Text | White | Text color for blocked periods |

### UI Accent Colors

| Setting | Default | Description |
|---------|---------|-------------|
| Button Background | Blue | Normal state button background color |
| Button Text | White | Normal state button text color |
| Button Hover Background | Dark blue | Hover state button background |
| Button Hover Text | White | Hover state button text |
| Icon Background | Blue | Icon container background |
| Icon Color | White | Icon color |

### Text Size

| Setting | Default | Description |
|---------|---------|-------------|
| Calendar Text Size | Medium | Global text scale: Small, Medium, Large, or Extra Large |

---

## Behavior

Controls booking rules, frontend pages, self-service options, and advanced features.

### Booking Rules

| Setting | Default | Description |
|---------|---------|-------------|
| Minimum Booking Notice | 60 minutes | How far in advance a booking must be made. Prevents last-minute bookings. |
| Buffer Time Before | 0 minutes | Dead time reserved before each appointment (setup/prep time) |
| Buffer Time After | 0 minutes | Dead time reserved after each appointment (cleanup time) |
| Universal Shortcode Default | Time-Slot Calendar | Default interface for `[clisyc_booking_form]`: "Time-Slot Calendar" or "Date Range Search Form" |
| Smart Start Date | Disabled | When enabled, the calendar opens to the week containing the next available slot, skipping empty weeks |

### Frontend Links & Pages

| Setting | Description |
|---------|-------------|
| Booking Page | Page containing `[clisyc_booking_form]` |
| Custom Login Page URL | Optional custom login redirect URL |
| Appointment Detail Page | Page with `[clisyc_appointment_detail]` |
| Booking Success Page | Redirect destination after successful booking |
| Search Results Page | Page with `[clisyc_search_results]` |
| Manager Edit Appointment Page | Staff appointment editing page |
| Contact Page | Fallback page shown when no availability exists |

Each page setting includes a **Create Page** button that automatically generates a new page with the correct shortcode.

### Client Self-Service

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Self-Service | Disabled | Allow logged-in clients to cancel or reschedule their own appointments |
| Cancellation/Reschedule Cutoff | — | How far in advance (hours or days) clients must cancel/reschedule |
| Refund Policy on Cancellation | No Refund | What happens when a paid appointment is cancelled: "No Refund" or "Attempt Full Refund via WooCommerce" |

### Waitlist

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Waitlist | Disabled | Allow clients to join a waitlist when slots are full |
| Max Waitlist Size Per Slot | 0 (unlimited) | Maximum number of people who can waitlist for a single slot |
| Auto-Promote on Cancellation | Disabled | Automatically confirm the next waitlisted person when a cancellation opens a spot |

### Spam Protection

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Honeypot Field | Disabled | Add a hidden field to catch automated bot submissions |
| Enable Timing Check | Disabled | Reject form submissions that happen too quickly (bot detection) |

### HIPAA Compliance

| Setting | Default | Description |
|---------|---------|-------------|
| Enable HIPAA Mode | Disabled | Enable HIPAA compliance features (audit logging, data protection). Can be locked via the `CLISYC_HIPAA_MODE` PHP constant. |
| Audit Log Retention | 2555 days (~7 years) | How long to retain audit logs. HIPAA requires at least 6 years. |
| Anonymize External Sync | Disabled | Send only "Busy - Appt #ID" to Google Calendar instead of client names |

### Advanced

| Setting | Default | Description |
|---------|---------|-------------|
| MySQL Timezone Method | Auto-Detect | How timezone conversion is handled: Auto-Detect, Force MySQL CONVERT_TZ, or Force PHP Conversion |

---

## Notifications

Controls email and SMS notification delivery. See [Notifications & Output Templates](Notifications) for detailed configuration.

### General Email Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Email "From" Name | Blog name | Sender display name for all plugin emails |
| Email "From" Address | Admin email | Sender email address |
| Admin Notification Recipients | Admin email | Comma-separated list of admin email addresses |

### Appointment Reminders

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Reminders | Disabled | Send automatic reminder emails before appointments |
| Send Reminder Before | 24 hours | How far in advance to send the reminder (value + unit: hours or days) |

### Event Notifications

For each appointment event (created, updated, cancelled, completed, etc.), you can configure:

- **Admin Email:** Enable/disable, subject line, body template
- **Client Email:** Enable/disable, subject line, body template
- **Client SMS (Pro):** Enable/disable, message body

All templates support placeholder variables like `{client_name}`, `{appointment_date}`, `{appointment_time}`, etc.

---

## Payments

### WooCommerce Integration

| Setting | Default | Description |
|---------|---------|-------------|
| Enable WooCommerce Payments | Disabled | Require payment for bookings via a linked WooCommerce product |

### Stripe Direct Integration

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Stripe Payments | Disabled | Accept payments directly via Stripe Checkout (no WooCommerce needed) |
| Publishable Key | — | Your Stripe publishable key (`pk_test_` or `pk_live_`) |
| Secret Key | — | Your Stripe secret key (`sk_test_` or `sk_live_`) |
| Webhook Signing Secret | — | Stripe webhook secret (`whsec_`) for verifying webhook signatures |

See [Stripe Payments & Subscriptions](Stripe-Payments) for detailed setup instructions.

---

## Automation

### Slot Generation

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Auto-Generation | Disabled | Automatically generate future available time slots based on dimension schedules |
| Generation Lookahead | 14 days | How many days ahead to generate slots |

When enabled, a WordPress cron job runs regularly to create slots for the configured lookahead period.

---

## Integrations

### Google Calendar

| Setting | Description |
|---------|-------------|
| Client ID | OAuth 2.0 Client ID from Google Cloud Console |
| Client Secret | OAuth 2.0 Client Secret |

Once configured, appointments can sync bidirectionally with Google Calendar.

### Twilio SMS (Pro)

| Setting | Description |
|---------|-------------|
| Account SID | Twilio account identifier |
| Auth Token | Twilio authentication token |
| From Number | Twilio phone number (e.g., `+1234567890`) |

Includes a **Send Test SMS** button to verify your credentials.

### Video Conferencing (Pro)

| Setting | Description |
|---------|-------------|
| Video Provider | None, Google Meet (via Google Calendar), or Zoom |
| Zoom Account ID | Server-to-Server OAuth account ID |
| Zoom Client ID | OAuth Client ID |
| Zoom Client Secret | OAuth Client Secret |

### Webhooks

Configure one or more webhook endpoints:

| Setting | Description |
|---------|-------------|
| Enable | Toggle the webhook on/off |
| Webhook URL | Destination URL for event payloads |
| Trigger on Events | Which events fire the webhook: Appointment Created, Updated, Cancelled |

---

## Import / Export

- **Export:** Download your current configuration as a `.json` file
- **Import:** Upload a `.json` file to restore settings, dimensions, and schedules

Useful for migrating between environments or backing up before changes.

---

## Shortcodes Tab

A reference page listing all available shortcodes with usage examples. This is a read-only reference, not a settings page.

---

## Related Pages

- [Getting Started](Getting-Started) — Initial configuration walkthrough
- [Stripe Payments & Subscriptions](Stripe-Payments) — Detailed Stripe setup
- [Notifications & Output Templates](Notifications) — Notification configuration
- [WooCommerce Integration](WooCommerce-Integration) — WooCommerce payment setup
