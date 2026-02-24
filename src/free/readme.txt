=== Client Sync ===
Contributors: hsojhsoj
Donate link: https://dependentmedia.com/
Tags: appointment, booking, calendar, scheduling, woocommerce
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 3.7.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage client registrations, appointments, payments, and appointment notes from a single, integrated system.

== Description ==

📖 **[Full Documentation](https://github.com/hsojhsoj/client-sync-monorepo/wiki)** — Setup guides, shortcode reference, payment configuration, and more.

Client Sync transforms your WordPress site into a complete client and appointment management powerhouse. Designed for service-based businesses, clinics, consultants, and professionals, this plugin provides a robust, scalable, and flexible system to handle the entire client journey.

Whether you need simple one-on-one appointment booking, multi-day resource rentals, or complex group classes, Client Sync's unique "Dimension" architecture and a single, powerful `[clisyc_booking_form]` shortcode can handle it all intelligently. The system automatically detects whether to show a time-slot calendar, a multi-day rental search, or a single property's booking calendar based on the context of the page.

Built on a high-performance architecture using custom database tables, Client Sync ensures your site remains fast and responsive, even with complex schedules and high appointment volumes.

= Core Features =

*   **Flexible Booking Modes:**
    *   **Time Slots:** Perfect for standard appointments, coaching calls, and consultations.
    *   **Group Bookings (Multi-Capacity):** Easily manage classes, workshops, or tours by setting a capacity for any service. The system automatically tracks spots left.
    *   **Multi-Day Bookings:** Ideal for rentals (equipment, rooms, vehicles) where clients select a start and end date. Features an intuitive drag-to-select date range picker.

*   **Powerful & Intersecting Schedules:**
    *   **Availability Dimensions:** Create schedules based on service type, location, or practitioner for dynamic booking systems.
    *   **Resource Scheduling (Pro):** Designate dimensions like "Rooms" or "Equipment" as Resources. A time slot is only bookable if the service, practitioner, AND the required resource are all available at the same time.
    *   **Visual Availability Management:** "Paint" available or blocked time on an admin calendar for one-off changes and overrides.

*   **Availability Search Tool:**
    *   Add a powerful search form to your site with the `[clisyc_availability_search]` shortcode.
    *   Allows users to search for available items (rooms, boats, properties) across a specific date range, ensuring they only see results that are open for their entire trip.

*   **Dynamic Conditional Fields:** Show or hide appointment fields based on the service selected. If a "House Rental" service is chosen, show "Bedrooms". If "Car Rental" is chosen, show "Vehicle Type". This creates a clean, relevant booking experience for any service.

*   **Flexible Payment Options:**
    *   **Stripe Direct Integration:** Accept payments directly through Stripe Checkout — no WooCommerce required. Just add your API keys and you're ready to go.
    *   **Deep WooCommerce Integration:** Link each service to a unique WooCommerce product for accurate pricing, dynamic pricing rules (weekend surcharges, weekly discounts), and automatic totals for group and multi-day bookings.
    *   **No Payment Mode:** Accept bookings without any payment gateway for free consultations or internal scheduling.

*   **Extensible Custom Fields:**
    *   Unlimited fields for clients and appointments, including text, dropdowns, checkboxes, and a unique **Image Map** field (Pro).

*   **Client & Manager Dashboards:**
    *   Clients view appointment history and update details via a dedicated account page.
    *   Manager shortcodes create frontend dashboards for staff to view/edit appointments without full admin access.

*   **Automation & Reminders:**
    *   Auto-generate future availability from templates.
    *   Reduce no-shows with configurable email reminders.

*   **iCal Export:** One-click `.ics` file downloads for adding appointments to Google Calendar, Outlook, or Apple Calendar.

== Installation ==

1. Navigate to **Plugins > Add New** in your WordPress dashboard.
2. Search for **Client Sync**.
3. Click **Install Now**, then **Activate**.
4. Follow the Setup Wizard to choose a business template, create essential pages, and configure settings.

For detailed setup instructions, see the [Getting Started guide](https://github.com/hsojhsoj/client-sync-monorepo/wiki/Getting-Started).

== Frequently Asked Questions ==

= How do I add a booking form to my site? =
Simply add the universal shortcode to any page:
`[clisyc_booking_form]`

The plugin is smart and will automatically display the correct interface:
*   **On a standard page:** It will show the filterable calendar for time-slot appointments, or an availability search form if you primarily offer multi-day rentals.
*   **On an individual property/rental page:** It will automatically detect the context and show the booking calendar for that specific item.

= How do I set up a multi-day rental like a hotel room or vacation property? =
1.  In `Client Sync > Dimensions`, create a "Properties" dimension and set it as your **Primary Dimension**.
2.  Create your individual properties (e.g., "The Beach House") as posts within that dimension.
3.  On the edit screen for each property, find the "Primary Attributes" box and change the **Booking Mode** to **"Date Range"**.
4.  Set the **Capacity** to `1` for unique rentals, or more if you have multiple identical items (like "Standard Queen Room").
5.  Link the property to a **WooCommerce Product** to enable payments.
6.  Add the `[clisyc_booking_form]` shortcode to a general "Search" page, and also to the content of each individual property page. The shortcode will automatically show the right view in each location.

= How do I set up a group class like a yoga workshop? =
1.  When you create or edit your "Class" service (as a Primary Dimension item), find the **Capacity** field in the "Primary Attributes" box.
2.  Set the capacity to the number of students you can accommodate (e.g., `15`).
3.  That's it! The booking form will now show a "Number of Attendees" field for that service.

= How does Resource Scheduling work? (Pro) =
This powerful feature allows you to create intersecting schedules. A time is only bookable if all required people, places, and things are available.

1.  In `Client Sync > Dimensions > System Setup`, create your dimensions (e.g., "Practitioners", "Services", "Rooms").
2.  Check the **"Is Resource"** box for any dimension that has its own limited availability, like "Rooms". (This is a Pro feature).
3.  Edit a specific Room (e.g., "X-Ray Suite") and set its availability in its "Resource Availability Schedule" meta box (e.g., only available Tuesday mornings).
4.  Edit a "Service" (your Primary Dimension) and set its general availability (e.g., available all week).
5.  Link the service to the room in the Relationship Graph.

Now, a time slot for that service will only appear on the frontend calendar if it falls within **both** the Service's schedule AND the X-Ray Suite's schedule.

= How do I create different forms for different services? =
Client Sync uses **Conditional Logic**. Instead of creating separate forms, you create all the fields you might need in the **Appointment Custom Fields** tab. Then, for each field, you enable Conditional Logic and set rules like "Show this field ONLY IF Service Type is House Rental". The booking form will dynamically show the correct fields as the user makes their selection.

== External Services ==

This plugin includes optional integrations with the following third-party services.

= Stripe Payments =

*   **Purpose:** This service provides direct payment processing through Stripe Checkout. When enabled, clients are redirected to a secure Stripe-hosted payment page to complete their booking payment. This feature is **disabled by default** and must be explicitly enabled and configured by the site administrator in the plugin's Payments settings tab.

*   **Data Sent:**
    *   **Backend (Your Server):** When a booking requires payment, your server sends the appointment details (amount, currency, description, and client email) to Stripe's API to create a Checkout Session. A webhook endpoint receives payment confirmation events from Stripe, which are verified using HMAC-SHA256 signature validation.

*   **Service Policies:**
    *   Use of Stripe is subject to the Stripe Services Agreement and Privacy Policy.
    *   **Privacy Policy:** https://stripe.com/privacy
    *   **Terms of Service:** https://stripe.com/legal/ssa

= Google reCAPTCHA v3 =

*   **Purpose:** This service analyzes user interactions to distinguish between human users and automated bots, helping to prevent spam submissions. This feature is **disabled by default** and must be explicitly enabled and configured by the site administrator in the plugin's settings (Client Sync > Settings > Style & Behavior > Spam Protection).

*   **Data Sent:**
    *   **Frontend (User's Browser):** When reCAPTCHA is enabled, a JavaScript file is loaded from Google's servers. The user's browser sends hardware and software information, including device and application data and the results of integrity checks, to Google for analysis. The user's IP address is also collected.
    *   **Backend (Your Server):** When a user submits a form, a verification token generated on the frontend is sent from your server to Google's API for validation. This request includes the user's IP address.

*   **Service Policies:**
    *   Use of Google reCAPTCHA is subject to the Google Privacy Policy and Terms of Use.
    *   **Privacy Policy:** https://policies.google.com/privacy
    *   **Terms of Use:** https://policies.google.com/terms

== Changelog ==

= 3.7.0 =
*   **New Feature: Venue Photo Display.** Venue featured images (photos) now display on the appointment detail page above the address and map, giving clients a visual preview of the venue.
*   **Enhancement: Venue Location Section.** The appointment detail page venue section now shows venue photo, name, formatted address, and embedded map in a polished layout.
*   Compatibility update for Client Sync Pro 1.6.0.

= 3.6.2 =
*   **New Feature: Appointment Check-In System.** Added a custom `Checked In` post status and a one-click "Check In" row action on the admin appointment list for quick status updates at events.
*   **New Feature: QR Code Tickets.** Appointments with seat bookings now display a unique QR code on the frontend detail page. Staff can scan QR codes to instantly check in guests.
*   **New Feature: Seat Details on Appointment Page.** The `[clisyc_appointment_detail]` shortcode now shows booked seat information (section, row, seat, category) in styled cards.
*   **New Feature: Venue Map on Appointment Page.** The appointment detail page now displays the venue SVG map with the user's booked seats highlighted in blue and other seats dimmed, with a colour legend.
*   Compatibility update for Client Sync Pro 1.5.2.

= 3.6.1 =
*   **Enhancement: Seat Selection Expanded View.** Added a fullscreen/expanded mode toggle to the seat selection component. Users can click "Expand" to view the seat map at full viewport size, improving usability for large venues with many seats.
*   **Enhancement: Seat Selection Details.** Selected seats now display detailed tags showing section, row, and seat number for each selection.
*   **Enhancement: Wider Booking Modals.** Booking and hybrid booking modals automatically widen from 480px/400px to 640px when seat selection is present, giving more room for the seat map.
*   **Fix: Seat Map Padding.** Fixed asymmetric left/right padding in the seat map caused by duplicate CSS class inheritance on the inner SVG container.
*   Compatibility update for Client Sync Pro 1.5.1.

= 3.6.0 =
*   **New Feature: Guide Page.** Extracted the Setup Checklist and Directions tab from the Settings page into a dedicated Guide submenu item. Combines onboarding progress tracking with educational documentation, including a new Seat Selection & Venues section. Settings page reduced from 11 to 9 tabs.
*   **New Feature: Event Venue / Theater Setup Template.** Added the "Event Venue / Theater (Seat Selection)" template to the Setup Wizard, providing a complete starting configuration for reserved-seat booking with venue maps.
*   **Enhancement: Contextual Help Tabs.** Added help tooltips for the new Guide page and Venues admin page. Updated the Settings help tab to reflect the reduced tab set.
*   **Fix: HTML Entity Display in Booking Modal.** Fixed `&#039;` (apostrophe) rendering as raw text in frontend notice messages by using `__()` instead of `esc_html__()` for strings passed to JavaScript via `wp_localize_script`.
*   **Fix: Booking Modal Padding.** Added missing left/right padding to the slot summary and recurring series sections of the booking confirmation modal.
*   Compatibility update for Client Sync Pro 1.5.0.

= 3.4.0 =
*   **Security: Encryption Upgraded to AES-256-GCM.** Replaced AES-256-CBC with AES-256-GCM for authenticated encryption with HKDF key derivation. Legacy CBC-encrypted data is auto-decrypted transparently.
*   **Security: Booking Race Condition Eliminated.** Slot reservation now uses database-level row locking (`SELECT ... FOR UPDATE`) within a transaction to prevent double-bookings under concurrent requests.
*   **Security: IDOR Fix on Booked-Dates Endpoint.** The public REST endpoint now validates that the requested dimension ID belongs to a registered dimension CPT, preventing unauthorized data access.
*   **Security: Rate Limiting on Public Endpoints.** Form submissions and REST API slot queries are now rate-limited per IP with a fixed-window algorithm.
*   **Security: Content-Disposition Header Injection.** Fixed unquoted filenames in export download headers (RFC 6266 compliance).
*   **Security: XSS Prevention in Form Validation.** Validation error messages are now escaped before rendering to prevent reflected XSS via crafted field labels.
*   **Security: Template Import Path Traversal Guard.** Added `realpath()` validation to prevent directory traversal when loading setup templates.
*   **Security: Stripe Webhook Idempotency.** Duplicate Stripe webhook deliveries are now detected and ignored via event ID tracking.
*   **Fix: Appointment Duration Meta Key Inconsistency.** Unified 12 references across 11 files that used two different meta keys (`clisyc_appointment_duration` vs `_clisyc_appointment_duration`) for the same data. All now use the constant `META_APPOINTMENT_DURATION`.
*   **Fix: Notification Channel Resilience.** A failing SMS or webhook channel no longer halts the entire notification loop. Failed channels are retried via Action Scheduler with exponential backoff.
*   **Fix: Waitlist Promotion Trigger.** Cancellation status list for waitlist promotion is now dynamically built to match the configured self-service cancellation status.
*   **Fix: Cache Invalidation After Bookings.** Slot cache is now cleared immediately after every booking creation and cancellation.
*   **Fix: Cancellation Manager Transaction Safety.** Booking entry deletion and slot count decrement are now wrapped in a database transaction.
*   **Fix: Slots Query Performance.** Booked appointment queries now use a `BETWEEN` date-range meta query at the database level instead of loading all appointments into memory and filtering in PHP.
*   **Fix: Importer Memory Usage.** The data wipe step now processes posts in batches of 100 instead of loading all posts at once.
*   **Fix: Rate Limiter Fixed-Window Algorithm.** Replaced fragile direct `_transient_` option writes with a proper fixed-window approach that stores count and expiry together.
*   **Enhancement: HIPAA Audit Logging for Decryption.** Viewing encrypted appointment fields now generates an audit log entry for compliance tracking.
*   **Enhancement: Booking Modal Accessibility.** Added focus trap, keyboard navigation (Tab/Shift+Tab cycling, Escape to close), and programmatic focus management on modal open.
*   **Enhancement: WooCommerce Refund Handling.** Refunded orders now automatically cancel the associated appointment and restore the booking slot.
*   **Enhancement: Google Calendar Sync.** Added retry with exponential backoff, pagination for large event sets, and stale slot unblocking.
*   **Enhancement: PostType_Manager Decomposition.** Extracted meta box rendering, meta saving, and admin column management into dedicated classes, reducing the god class from ~1900 lines.
*   **Enhancement: React Frontend Architecture.** Introduced BookingContext with useReducer, extracted inline components (AnalogClock, EventTooltip, MiniCalendar), added ErrorBoundary, and replaced CSS polling with ResizeObserver.
*   **Enhancement: Cron Query Batching.** Appointment reminder queries now process 100 appointments per batch instead of unbounded queries.

= 3.3.2 =
*   **Enhancement: Output Templates Tabbed Interface.** Combined the "Output Templates" list and "Template Preview" pages into a unified tabbed interface with "All Templates" and "Preview" tabs, reducing menu clutter while keeping both views fully functional.
*   **Enhancement: Template Preview Cards.** Improved preview card layout with human-readable trigger labels, "(Untitled)" fallback for unnamed templates, Draft template support with visual badge, and responsive grid layout.
*   **Fix: Admin Assets on Plugin Pages.** Broadened the admin page detection to correctly enqueue plugin CSS/JS on all `clisyc-*` prefixed pages and `clisyc_*` custom post type screens.
*   **Fix: Hidden Submenu Page Pattern.** Added a reusable pattern in the Menu Manager that preserves plugin pages not in the main menu order (for tabbed sub-pages) while keeping them accessible and maintaining correct parent menu state.

= 3.3.1 =
*   **Bug Fix: Payment Status Database Error.** Fixed the custom post status `clisyc_pending_payment` (22 characters) which exceeded MySQL's `varchar(20)` limit on the `post_status` column, causing silent database failures when setting appointments to "Pending Payment." Renamed to `clisyc_pending_pay` (18 characters). Includes an automatic one-time migration for any existing rows.
*   **Security: Rate-Limited Stripe Webhook.** The `/clisyc/v1/stripe-webhook` endpoint is now rate-limited to 120 requests per 60 seconds per IP, preventing abuse.
*   **Security: XSS Fix in HTML Entity Decoder.** Replaced the `innerHTML`-based `decodeHTMLEntities()` utility with a safe regex and static entity map approach, eliminating a potential cross-site scripting vector in the booking form.
*   **Enhancement: Webpack Code Splitting.** Extracted FullCalendar and ReactFlow into shared vendor chunks, reducing total JS bundle size by eliminating duplication across entry points.
*   **Enhancement: Centralised Status Constants.** All custom post statuses (`clisyc_pending_pay`, `clisyc_paid_on_day`, `clisyc_failed_on_day`) are now defined as constants in the `Constants` class, replacing 27 hardcoded string references.
*   **Enhancement: Standardised React Imports.** Replaced direct `react-dom/client` imports with `@wordpress/element` across all entry points to share WordPress's bundled React.
*   **Code Quality: Removed Debug Logging.** Stripped all `console.log` and `console.error` debug statements from production JavaScript.
*   **Code Quality: New Test Coverage.** Added 20 new PHPUnit tests (9 for the Booking AJAX Handler, 11 for Stripe Integration), bringing the total to 84 tests with 189 assertions.

= 3.3.0 =
*   **New Feature: Styled Email Notifications.** All outgoing emails are now wrapped in a professional, responsive HTML template with a branded header, clean body, and footer. Uses inline CSS for maximum compatibility across Gmail, Outlook, and Apple Mail. Fully filterable via the `clisyc_email_template` hook.
*   **New Feature: Admin Dashboard Widget.** A new "Client Sync — Today" widget appears on the WordPress Dashboard (`wp-admin/index.php`), showing today's appointment count, this week's total, upcoming appointments, active clients, and a quick-glance list of the next 5 appointments.
*   **New Feature: Appointment Export (CSV & ICS).** Export appointments as spreadsheet (CSV) or calendar (ICS) files from the Import/Export settings tab. Supports date range filtering and is HIPAA-aware (anonymises client data when HIPAA mode is active).
*   **New Feature: Stripe Direct Integration.** Accept payments directly through Stripe Checkout without requiring WooCommerce. Configure API keys in the Payments settings tab; the plugin handles Checkout Session creation, webhook signature verification, and automatic appointment confirmation on successful payment.
*   **New Feature: REST API Appointments Endpoint.** Full CRUD REST API at `/clisyc/v1/appointments` for listing (with pagination, date range, status, and search filters), viewing, updating, and cancelling appointments. Enables headless and third-party integrations.
*   **Security: Hardened AJAX Input Validation.** Comprehensive audit of all 26 AJAX handlers. Added missing nonce verification to the WooCommerce cart data endpoint, replaced inconsistent `wp_die()` responses with proper JSON in the HIPAA notice handler, and fixed boolean sanitisation in the HIPAA migration tool.
*   **Enhancement: Cache-Busted Admin Assets.** Admin JavaScript and CSS files now use `filemtime()` for cache-busting version strings, ensuring browsers always load the latest assets after plugin updates.

= 3.2.1 =
*   Version bump.

= 3.1.0 =
*   **New Feature: Appointment Duration & Padding.** Admins can now set a specific duration (in minutes) for a service. The system will automatically subdivide large availability blocks (e.g., 9am-5pm) into precise, bookable slots of that duration.
*   **New Feature: Padding / Cleanup Time.** A "Padding" time (in minutes) can be set for each service. This automatically creates an unbookable gap after each appointment, perfect for cleanup, travel, or note-taking, without affecting the client-facing appointment duration.
*   **Enhancement:** The "Export Database" tool on the Testing page now exports all plugin options for a more complete debugging snapshot.

= 3.0.0 =
*   **Major Refactor: Monorepo Architecture.** Plugin restructured to support a Free and Pro version from a single codebase.
*   **Major Feature: Group Bookings (Multi-Capacity).** Admins can now set a "Capacity" for any service, allowing multiple clients to book the same time slot (e.g., for classes, workshops, or tours). The frontend calendar automatically displays the number of spots remaining.
*   **Major Feature: Multi-Day Bookings.** A new "Date Range" booking mode can be enabled for services, ideal for multi-day rentals of rooms, equipment, or vehicles. The frontend displays a date-range picker instead of a time-slot calendar for these services.
*   **Pro Feature: Resource Scheduling (Intersecting Availability).** Dimensions (like "Rooms" or "Equipment") can be designated as "Resources," each with its own independent weekly schedule. A time slot is only considered available if it exists in the schedule of the Primary Dimension AND any linked Resource Dimensions, allowing for complex constraint-based booking.
*   **Enhancement: WooCommerce Direct Product Linking.** The old global WooCommerce product ID has been replaced. Admins can now link each individual service directly to its own unique WooCommerce product. This provides accurate, per-service pricing, better reporting, and native support for quantity-based pricing for group bookings.
*   **Enhancement: Setup Wizard Templates.** Added new templates to the Setup Wizard to demonstrate the new Group Booking and Resource Rental features, providing users with powerful starting points.
*   **Enhancement (Admin UI):** The "Base Weekly Schedule" editor now includes visual dot indicators on the day-of-the-week tabs to show which days have availability configured, improving administrative workflow.
*   **Fix:** Resolved numerous JavaScript timing issues in the admin area, particularly on the Gutenberg post edit screen, to ensure UI elements like the schedule editor and its toggles load reliably.
*   **Dev:** Full audit and hardening of all code to resolve PHP 8.1+ deprecation notices.

= 2.2.0 =
* **Security & Stability:** Major refactor to meet WordPress.org plugin repository standards.
* **Prefixing:** All functions, classes, constants, options, hooks, CPTs, and asset handles have been prefixed with `clisyc_` or `DependentMedia\ClientSync` for uniqueness and to prevent conflicts with other plugins.
* **Security:** Added and verified nonce checks for all state-changing actions. Refactored form handlers to separate processing logic from rendering logic.
* **Security:** Improved data sanitization on input and escaping on output across the entire plugin.
* **Performance:** Refactored asset (CSS/JS) loading to only enqueue files on pages where they are needed, improving site-wide performance.

= 2.1.0 =
* **Feature:** Added Conditional Logic for Appointment Custom Fields.
* **Feature:** New dedicated "Service & Availability Dimensions" admin page.
* **Enhancement:** Streamlined admin menu structure.
* **Enhancement:** Added AJAX functionality to the new Dimensions admin page.

= 2.0.0 =
* Major refactor: Migrated availability slots to custom database tables.
* Added Setup Wizard for streamlined onboarding.

= 1.5.0 =
* Added iCal export functionality.
* Implemented appointment reminder system.

= 1.4.0 =
* Added "Pay on Day" WooCommerce functionality.

= 1.3.0 =
* Introduced "Availability Dimensions" for multi-faceted scheduling.

= 1.0.0 =
* Initial release.

== Copyright ==

Client Sync uses the following third-party libraries:

*   **FullCalendar Scheduler**
    *   Source: https://fullcalendar.io/scheduler
    *   License: GNU General Public License v3 (GPLv3)
    *   Copyright: 2023 Adam Shaw