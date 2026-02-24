# Shortcodes Reference

Client Sync provides 20 shortcodes to build your frontend booking experience. Place these in any WordPress page or post using the standard `[shortcode]` syntax.

---

## Booking Shortcodes

### `[clisyc_booking_form]`
The main booking interface. Displays a calendar or search form where customers can find available slots and book appointments.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `mode` | `auto` | Interface mode: `auto` (smart selection), `calendar` (calendar view), or `search` (date-range search) |
| `show_booked_toggle` | `false` | Show a toggle to reveal already-booked slots |
| `show_booked_default` | `false` | Whether the booked-slots toggle starts enabled |

**Examples:**
```
[clisyc_booking_form]
[clisyc_booking_form mode="calendar"]
[clisyc_booking_form mode="search" show_booked_toggle="true"]
```

---

### `[clisyc_booking_wizard]`
A step-by-step, dimension-based booking wizard. Guides customers through selecting each dimension before choosing a time slot.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `dimensions` | *(empty)* | Comma-separated dimension keys to include. Empty = all enabled dimensions |
| `show_prices` | `true` | Show prices alongside dimension items |
| `show_descriptions` | `true` | Show descriptions for dimension items |

**Examples:**
```
[clisyc_booking_wizard]
[clisyc_booking_wizard dimensions="clisyc_service,clisyc_practitioner" show_prices="true"]
```

---

### `[clisyc_hybrid_booking]`
A hybrid calendar + search interface with dimension filters. Combines the flexibility of both views.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `initial_view` | `week` | Initial calendar view to display |
| `show_filters` | `true` | Show dimension filter dropdowns |
| `show_booked` | `false` | Show already-booked appointments |
| `show_blocked` | `true` | Show admin-blocked time periods |

**Examples:**
```
[clisyc_hybrid_booking]
[clisyc_hybrid_booking initial_view="month" show_filters="true"]
```

---

### `[clisyc_dimensions_faceted_booking]`
A faceted search booking form with date-range selection (Litepicker). Best for rental and accommodation businesses.

*No attributes — uses global dimension registry settings.*

```
[clisyc_dimensions_faceted_booking]
```

---

### `[clisyc_booking_confirmation]`
Displays a booking confirmation page with appointment details, calendar export links, and action buttons.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `appointment_id` | `0` | Specific appointment ID to display |
| `order_id` | `0` | WooCommerce order ID to find the appointment |
| `timeout` | `10` | Minutes to look back for recent appointments |
| `show_details` | `true` | Show the appointment details card |
| `show_calendar_links` | `true` | Show Google/Outlook/Apple calendar links |
| `show_confetti` | `true` | Play confetti animation on load |
| `show_actions` | `true` | Show action buttons (View Appointments, Return Home) |
| `success_title` | `Booking Confirmed!` | Custom heading text |
| `success_message` | `Your appointment has been successfully scheduled.` | Custom message text |
| `fallback_content` | *(empty)* | Content shown if no appointment found |
| `style` | `card` | Display style for the confirmation |

**Examples:**
```
[clisyc_booking_confirmation]
[clisyc_booking_confirmation show_confetti="false" success_title="You're All Set!"]
```

---

### `[clisyc_search_results]`
Displays filtered search results for the primary dimension. Typically used as the destination for search-mode bookings.

*No attributes — reads filter parameters from the URL.*

```
[clisyc_search_results]
```

---

## Calendar & Appointment Shortcodes

### `[clisyc_user_appointments_calendar]`
A full calendar view showing the logged-in user's appointments. Requires user login.

*No attributes.*

```
[clisyc_user_appointments_calendar]
```

---

### `[clisyc_user_mini_calendar]`
A compact mini calendar widget. Click a date to see that day's appointment details. Requires user login.

*No attributes.*

```
[clisyc_user_mini_calendar]
```

---

### `[clisyc_appointments_cards]`
Displays the user's appointments as a filterable card grid. Requires user login.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `title` | `Your Appointments` | Section heading |
| `show_search` | `true` | Enable search functionality |
| `show_filter` | `true` | Enable filter dropdowns |

**Examples:**
```
[clisyc_appointments_cards]
[clisyc_appointments_cards title="My Bookings" show_filter="true"]
```

---

### `[clisyc_appointment_detail]`
Shows the detailed view of a single appointment. Requires login and a `view_id` URL parameter.

*No attributes — reads `view_id` from the URL.*

```
[clisyc_appointment_detail]
```

---

### `[clisyc_timeline]`
An interactive timeline visualization of appointments. Best for staff/admin views. Requires staff capability.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `view` | `week` | Initial view: `day`, `week`, or `month` |
| `resources` | `clisyc_service,clisyc_practitioner,clisyc_room` | Comma-separated dimension types or post IDs for rows |
| `height` | `700` | Container height in pixels |
| `show_controls` | `true` | Show navigation and view controls |
| `start_time` | `08:00` | Timeline start time (HH:MM) |
| `end_time` | `18:00` | Timeline end time (HH:MM) |
| `color_by` | `service` | Dimension to use for appointment colors |
| `show_legend` | `true` | Display a color legend |
| `allow_booking` | `false` | Allow creating bookings directly from the timeline |

**Examples:**
```
[clisyc_timeline]
[clisyc_timeline view="day" height="500" start_time="07:00" end_time="20:00"]
```

---

## User Account Shortcodes

### `[clisyc_registration]`
Displays a user registration form with custom fields. Shows a login prompt if the user is already logged in.

*No attributes.*

```
[clisyc_registration]
```

---

### `[clisyc_user_account]`
User account and profile management page. Includes custom fields and Stripe billing portal link (if subscriptions are active). Requires login.

*No attributes.*

```
[clisyc_user_account]
```

---

## Staff Shortcodes

### `[clisyc_staff_dashboard]`
A frontend dashboard for staff and practitioners. Allows viewing today's appointments, checking in clients, and editing appointment notes. Requires staff-level login.

*No attributes.*

```
[clisyc_staff_dashboard]
```

---

### `[clisyc_view_notes]`
Displays appointment notes for the current user's bookings. Requires login.

*No attributes.*

```
[clisyc_view_notes]
```

---

## Membership Shortcodes

### `[clisyc_membership_plans]`
Displays membership plan pricing cards with Stripe checkout integration. Requires the Memberships module (Pro).

| Attribute | Default | Description |
|-----------|---------|-------------|
| `columns` | `3` | Number of columns in the pricing grid |
| `highlight` | *(empty)* | Post ID of a plan to highlight as "featured" |

**Examples:**
```
[clisyc_membership_plans]
[clisyc_membership_plans columns="2"]
[clisyc_membership_plans columns="2" highlight="42"]
```

---

## Conditional Shortcodes

### `[clisyc_if_recent_booking]...[/clisyc_if_recent_booking]`
A container shortcode that only displays its content if the user has a recent booking within the timeout window.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `timeout` | `10` | Minutes to look back for recent bookings |

**Example:**
```
[clisyc_if_recent_booking timeout="15"]
  <p>Thanks for booking! We'll see you soon.</p>
[/clisyc_if_recent_booking]
```

---

### `[clisyc_if_no_recent_booking]...[/clisyc_if_no_recent_booking]`
A container shortcode that only displays its content if the user does NOT have a recent booking.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `timeout` | `10` | Minutes to look back for recent bookings |

**Example:**
```
[clisyc_if_no_recent_booking]
  <p>Ready to book? <a href="/booking">Schedule your appointment now</a>.</p>
[/clisyc_if_no_recent_booking]
```

---

## Pro Shortcodes

### `[clisyc_form]` (Pro)
Renders a custom form built with the Client Sync Pro Form Builder. Used for lead capture, contact forms, and intake forms.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | `0` | **Required.** The form post ID (from the Forms CPT) |

**Example:**
```
[clisyc_form id="123"]
```

---

### `[clisyc_debug_appointments]`
A diagnostic shortcode that displays raw appointment data. Useful during development and testing.

*No attributes.*

```
[clisyc_debug_appointments]
```

---

## Related Pages

- [Getting Started](Getting-Started) — Pages created by the setup wizard
- [Dimensions & Scheduling](Dimensions-and-Scheduling) — The data displayed in booking forms
- [Membership Plans](Membership-Plans) — Plans displayed by `[clisyc_membership_plans]`
- [Settings Reference](Settings-Reference) — Appearance and behavior settings that affect shortcodes
