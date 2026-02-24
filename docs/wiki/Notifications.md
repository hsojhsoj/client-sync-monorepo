# Notifications & Output Templates

Client Sync sends notifications when important events occur — appointment confirmations, reminders, cancellations, and more. The free plugin includes email notifications, while Pro adds SMS, webhooks, and a visual output template builder.

---

## Notification Channels

| Channel | Available In | Description |
|---------|-------------|-------------|
| **Email** | Free + Pro | HTML emails via WordPress `wp_mail()` |
| **SMS** | Pro | Text messages via Twilio |
| **Webhooks** | Pro | HTTP POST requests to external URLs |
| **Output Templates** | Pro | Visual template builder for custom notification layouts |

---

## Email Notifications

### Configuration

Go to **Client Sync > Settings > Notifications** to configure email settings.

#### General Settings

| Setting | Description |
|---------|-------------|
| From Name | Sender display name (defaults to your site name) |
| From Address | Sender email address (defaults to admin email) |
| Admin Recipients | Comma-separated list of email addresses that receive admin notifications |

#### Event Templates

For each appointment event, you can configure separate admin and client email templates:

| Event | Description |
|-------|-------------|
| Appointment Created | Sent when a new booking is made |
| Appointment Updated | Sent when an appointment is modified |
| Appointment Confirmed | Sent when an appointment status changes to confirmed |
| Appointment Cancelled | Sent when an appointment is cancelled |
| Appointment Completed | Sent when an appointment is marked as completed |
| Payment Received | Sent when payment is successfully processed |
| Reminder | Sent before the appointment at the configured interval |

Each template has:
- **Enable/Disable toggle** — Turn the notification on or off
- **Subject line** — The email subject (supports placeholders)
- **Body** — The email content (HTML editor, supports placeholders)

### Placeholders

Use these placeholders in email subjects and bodies. They are automatically replaced with real values:

| Placeholder | Description |
|-------------|-------------|
| `{client_name}` | Full name of the client |
| `{client_first_name}` | Client's first name |
| `{client_email}` | Client's email address |
| `{appointment_date}` | Appointment date (raw) |
| `{appointment_date_formatted}` | Appointment date (formatted per WordPress settings) |
| `{appointment_time}` | Appointment start time |
| `{appointment_duration}` | Duration in minutes |
| `{appointment_notes}` | Any notes added to the appointment |
| `{appointment_view_link}` | Frontend link for the client to view their appointment |
| `{appointment_edit_link_admin}` | Admin link to edit the appointment |
| `{site_name}` | Your website name |
| `{site_url}` | Your website URL |
| `{order_id}` | WooCommerce order ID (if applicable) |
| `{order_total}` | Order total amount |
| `{order_payment_method_title}` | Payment method used |

### Appointment Reminders

1. Go to **Client Sync > Settings > Notifications**
2. Enable **Appointment Reminders**
3. Set the reminder timing (e.g., "24 hours before")
4. Configure the reminder email template

Reminders run on WordPress cron. They check for upcoming appointments and send notifications at the configured interval.

---

## SMS Notifications (Pro)

### Setup

1. Create a [Twilio](https://www.twilio.com/) account
2. Get your Account SID, Auth Token, and a phone number
3. Go to **Client Sync > Settings > Integrations > Twilio SMS**
4. Enter your credentials
5. Click **Send Test SMS** to verify the connection

### Configuration

SMS templates are configured alongside email templates in **Settings > Notifications**. For each event, a "Client SMS" section appears where you can:
- Enable/disable the SMS notification
- Write the message body (supports the same placeholders as email)

> **Tip:** Keep SMS messages under 160 characters to avoid multi-part messages and higher costs.

---

## Webhooks (Pro)

Webhooks send HTTP POST requests with appointment data to external URLs when events occur.

### Setup

1. Go to **Client Sync > Settings > Integrations > Webhooks**
2. Add a webhook endpoint:
   - **URL:** The destination for the POST request
   - **Events:** Select which events trigger the webhook (Created, Updated, Cancelled)
3. Enable the webhook

### Payload

Webhook payloads include the full appointment data in JSON format, including:
- Appointment ID and status
- Client information
- Date, time, and duration
- Dimension selections
- Custom field values

Use webhooks to integrate with:
- Zapier or Make (Integromat)
- Custom CRM systems
- Slack or Teams notifications
- Google Sheets logging
- Any system that accepts HTTP webhooks

---

## Output Templates (Pro)

The Output Template Builder is a visual editor for creating custom notification layouts. Unlike the basic email templates in Settings, output templates support:

- **Drag-and-drop sections** — Build layouts visually
- **Multiple content blocks** — Text, headers, and dynamic fields
- **Trigger-based delivery** — Assign templates to specific events
- **Multi-channel output** — Templates can output to email and other channels
- **Preview mode** — See exactly how your template will look before sending

### Creating an Output Template

1. Go to **Client Sync > Output Templates** (or the equivalent Pro menu item)
2. Click **Add New**
3. Use the visual builder to add sections:
   - **Text blocks** — Static or dynamic content with placeholders
   - **Field blocks** — Automatically pull in appointment data
4. Configure **Triggers** — Which events should use this template:
   - New appointment (client)
   - New appointment (admin)
   - Appointment reminder
   - Appointment cancelled (admin)
   - Appointment cancelled (by client)
   - Payment successful (client)
5. Configure **Channels** — Where to send the output:
   - Email
6. Save and publish

### Template Placeholders

Output templates support the same placeholders as standard email templates (see the placeholders table above).

### Setup Templates with Output Templates

Several [onboarding templates](Onboarding-Wizard) include pre-configured output templates:

- **Booking Confirmation** — Sent to client and admin on new booking
- **Appointment Reminder** — Sent before the appointment
- **Cancellation Notice** — Sent on cancellation
- **Payment Receipt** — Sent after successful payment
- **Admin: New Booking Alert** — Admin notification for new bookings

---

## Best Practices

1. **Test all notifications** before going live. Make test bookings and verify emails arrive with correct content.
2. **Use meaningful From addresses** — Avoid `noreply@` when possible. Customers are more likely to open emails from a recognizable sender.
3. **Keep SMS short** — Under 160 characters to avoid multi-part messages.
4. **Set up admin notifications** — Always have at least one admin recipient so you know when bookings come in.
5. **Configure reminders** — Appointment reminders significantly reduce no-shows.
6. **Check spam folders** — If emails aren't arriving, consider using an SMTP plugin (like WP Mail SMTP) to improve deliverability.

---

## Related Pages

- [Settings Reference](Settings-Reference) — All notification settings
- [Stripe Payments & Subscriptions](Stripe-Payments) — Payment-related notifications
- [Pro Features Overview](Pro-Features) — SMS, webhooks, and output templates
