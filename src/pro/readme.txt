=== Client Sync Pro ===
Contributors: hsojhsoj
Tags: add-on, client sync, pro features, resource scheduling, license
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.6.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Client Sync Pro is an add-on that unlocks advanced features for the free Client Sync plugin.

== Description ==

Client Sync Pro is an add-on for the free Client Sync plugin and requires the core Client Sync plugin to be installed and active. It unlocks a suite of powerful features designed for professionals and growing businesses by activating a valid license key.

Supercharge your booking system with advanced capabilities.

= Pro Features Unlocked =

*   **Resource Scheduling:** Create intersecting schedules for dimensions like "Rooms" or "Equipment". A time slot is only bookable if the service, practitioner, AND the required resource are all available at the same time.
*   **Advanced Custom Fields:** Access premium field types, such as the **Image Map** field, which allows clients to click on an image to mark specific points (e.g., pain points on a body diagram).
*   **Seat Selection:** Create venues with interactive SVG seat maps for reserved-seat booking. Supports sections, rows, pricing tiers, and real-time seat holds to prevent double-booking during checkout.

== Installation ==

1.  Ensure you have the free **Client Sync** plugin installed and activated from the WordPress.org repository.
2.  In your WordPress dashboard, navigate to **Plugins > Add New > Upload Plugin**.
3.  Upload the `client-sync-pro.zip` file you received after purchase.
4.  Click **Install Now**, then **Activate Plugin**.
5.  Navigate to **Client Sync > License** in your admin menu and enter your license key to unlock the Pro features.

== Frequently Asked Questions ==

= Where do I find my license key? =
You can find your license key in the purchase confirmation email you received or by logging into your account on our website.

= How do I know the Pro features are working? =
After activating your license, navigate to areas like **Client Sync > Dimensions > System Setup**. You will see that the "(Pro)" labels are gone and features like the "Is Resource" checkbox are now enabled. Similarly, the "Image Map" field type will become available under **Booking Fields**.

= Do I need to keep the free Client Sync plugin active? =
Yes. Client Sync Pro is an add-on and is not a standalone plugin. It relies on the core functionality provided by the free version. Both plugins must be active for the Pro features to work.

== Changelog ==

= 1.6.0 =
*   **New Feature: Check-In Dashboard Stats.** Live stats bar on the Check-In page showing Checked In, Expected, Remaining, and Per Hour counters with a progress bar. Auto-refreshes every 15 seconds.
*   **New Feature: Door List & Bulk Check-In.** Card/List view toggle on the Check-In page. Door list shows a sortable table with bulk check-in, CSV export, and print support. Server-side pagination for large events.
*   **New Feature: Venue Location in Emails.** New `{venue_name}`, `{venue_address}`, and `{venue_map_link}` email placeholders auto-populated from the venue address fields.
*   **New Feature: Email QR Code Tickets.** Inline QR code PNG images embedded in confirmation and reminder emails via the `{ticket_qr_code}` placeholder. Uses `chillerlan/php-qrcode` with server-side PNG caching.
*   **New Feature: Seat Availability Heatmap.** Overview SVG sections now colour-coded by fill percentage (green → red) with animated fade-in when availability data loads. Includes heatmap legend.
*   **New Feature: Seat Transfer / Reassignment.** Admin meta box on appointment edit screen for releasing and reassigning individual seats with real-time AJAX updates.
*   **Enhancement: Check-In Sound & Haptic Feedback.** Success chime and vibration on successful check-in, error buzz on failures. Visual green/red flash animation on cards and rows.
*   **Enhancement: Midnight Date Auto-Switch.** Check-In page date picker automatically advances to the new day at midnight, keeping the interface current for overnight events.
*   **Enhancement: Venue Photo Display.** Venue CPT now supports featured images. Photos display on the appointment detail page above the venue address and map.
*   Compatibility update for Client Sync 3.7.0.

= 1.5.2 =
*   **New Feature: Dedicated Check-In Page.** Added a new "Check In" admin page (Client Sync > Check In) with appointment search, filters, and a live camera-based QR scanner — designed for on-site tablet use at events.
*   **New Feature: QR Code Generation.** Unique QR tokens are generated per seat booking and rendered as SVG QR codes on the frontend detail page. Existing bookings retroactively receive tokens on first view.
*   **New Feature: Seat Details Filter.** Pro module now populates seat section, row, seat, and category details on the appointment detail page via the `clisyc_appointment_seat_details` filter.
*   **New Feature: Venue Map Display.** The appointment detail page shows the full venue SVG with booked seats highlighted in blue (with pulse animation) and other seats dimmed in grey, via the `clisyc_appointment_venue_map` filter.
*   **Enhancement: Check-In Row Action.** Added a green "Check In" row action to the admin appointment list for quick one-click status changes with confirmation dialog.
*   Compatibility update for Client Sync 3.6.2.

= 1.5.1 =
*   **Enhancement: Seat Selection Expanded View.** Added a fullscreen/expanded mode toggle to the SeatMapPicker component with viewport-relative SVG sizing (50vh normal, 75vh expanded), body scroll lock, and Escape key support.
*   **Enhancement: Seat Selection Details.** Selected seats now show section, row, and seat number as compact blue pill tags in both normal and expanded views.
*   **Enhancement: Seat Selection in All Booking Shortcodes.** Added seat selection support to `[clisyc_hybrid_booking]` and `[clisyc_booking_wizard]` shortcodes, matching the existing `[clisyc_booking_form]` integration.
*   **Fix: Seat Map Padding.** Fixed asymmetric padding caused by the inner SVG container inheriting outer wrapper styles. Renamed inner container to `clisyc-seat-map__svg-wrap` and excluded expanded state from modal-specific CSS rules.
*   Compatibility update for Client Sync 3.6.1.

= 1.5.0 =
*   **New Feature: Seat Selection Module.** Full reserved-seat booking system with interactive SVG venue maps, section/row/seat discovery, configurable pricing tiers, real-time seat holds with automatic expiry, and seamless booking flow integration.
*   **New Feature: Venue Custom Post Type.** Manage venues under Client Sync > Venues. Each venue stores an SVG seat map, parsed layout data, and pricing tier configuration via a dedicated admin meta box.
*   **New Feature: Venue REST API.** New endpoints for seat availability queries and seat hold management, enabling the frontend seat picker to reserve and release seats in real time.
*   **New Feature: Venue Link Meta Box.** Link venues to services (primary dimension items) so the seat picker appears automatically during booking for venue-enabled services.
*   **Fix: License Validation for Perpetual Licenses.** Licenses with a `0000-00-00` expiry date (no next due date in WHMCS) are no longer incorrectly flagged as expired.
*   **Enhancement: Venue Admin Help Tabs.** Dedicated contextual help content for the Venues list page covering SVG upload, pricing tiers, and seat holds.
*   Compatibility update for Client Sync 3.6.0.

= 1.3.0 =
*   **Security: Package Credit Race Condition.** Credit grants now use database-level row locking with transaction rollback on failure, preventing concurrent purchases from overwriting each other.
*   **Security: Form Submission Rate Limiting.** Unauthenticated form submissions are now rate-limited to 5 per minute per IP.
*   **Security: User Creation Error Handling.** WordPress error details from failed user creation are now logged server-side instead of exposed to end users.
*   **Security: Form Validation XSS Fix.** Validation error output is now escaped to prevent reflected XSS.
*   **Fix: License Manager Hardening.** Added expiry date verification, HMAC signature validation, and weekly revalidation cron.
*   **Fix: Form Submission Reliability.** Auto-login cookie is now set before notification dispatch; notification failures no longer block the login flow.
*   **Fix: Email Validation.** Added `is_email()` checks before all `wp_mail()` calls in form notification handler.
*   **Fix: Form Field Label Collision.** Duplicate field labels now get a disambiguating suffix instead of silently overwriting submission data.
*   **Enhancement: SMS and Webhook Notifications.** Pro notification channels (SMS, Webhook) now benefit from the core plugin's try-catch resilience and Action Scheduler retry.
*   Compatibility update for Client Sync 3.4.0.

= 1.2.1 =
*   Compatibility update for Client Sync 3.3.1 (payment status bug fix, security hardening).

= 1.2.0 =
*   Compatibility update for Client Sync 3.3.0.
*   Tested with WordPress 6.9 and PHP 8.3.

= 1.0.0 =
*   Initial release.
*   Adds the license management system for feature activation.
*   Unlocks Resource Scheduling capabilities.
*   Unlocks the "Image Map" custom field type for booking forms.