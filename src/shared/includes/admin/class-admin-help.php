<?php
/**
 * File: src/shared/includes/admin/class-admin-help.php
 * Provides contextual help documentation for each Client Sync admin page.
 *
 * Since the fullscreen layout hides the native WordPress Help tab,
 * this class stores help content that is rendered into a custom
 * slide-down panel in the fullscreen header.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin_Help {

    /**
     * Get the help tabs for a given admin page slug.
     *
     * Each tab is an associative array with keys:
     *   - id      (string) Unique tab identifier.
     *   - title   (string) Tab label.
     *   - content (string) HTML content for the tab body.
     *
     * @param string $page_slug The admin page slug.
     * @return array Array of help tab arrays.
     */
    public static function get_help_content( string $page_slug ): array {

        switch ( $page_slug ) {

            // -----------------------------------------------------------------
            // Dashboard
            // -----------------------------------------------------------------
            case 'client-sync':
                return array(
                    array(
                        'id'      => 'dashboard-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Dashboard Overview', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'The Dashboard provides a snapshot of your booking system at a glance.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'View upcoming appointments, recent activity, and key metrics.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Use the summary cards at the top to quickly assess your schedule.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'dashboard-quick-actions',
                        'title'   => __( 'Quick Actions', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Quick Actions', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'From the Dashboard you can navigate to any area of Client Sync.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Use the sidebar navigation to access Calendars, Settings, Reports, and more.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Calendars & Schedules
            // -----------------------------------------------------------------
            case 'clisyc-calendars':
                return array(
                    array(
                        'id'      => 'calendars-managing',
                        'title'   => __( 'Managing Calendars', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Managing Calendars', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'The Calendars page is your command center for managing availability.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Use the "Manage Time Slots" tab to create, edit, and block individual time slots.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Use the "Global Blocked Periods" tab to block entire date ranges (holidays, closures).', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'calendars-global-blocked',
                        'title'   => __( 'Global Blocked Periods', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Global Blocked Periods', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Global Blocked Periods are master rules for broad date ranges where your entire business is closed.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Examples: Christmas break, renovations, vacations.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'When a date falls within a blocked period, all slots are marked unavailable regardless of the slot database.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'calendars-slot-vs-global',
                        'title'   => __( 'Slot-Level vs Global Blocking', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Slot-Level vs Global Blocking', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Client Sync has two blocking methods — choose the right one:', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li><strong>' . __( 'Global Blocked Periods:', 'client-sync' ) . '</strong> '
                                . __( 'For broad date ranges (days/weeks). Master override for the whole system.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Slot-Level Blocks:', 'client-sync' ) . '</strong> '
                                . __( 'For granular, one-off changes (specific hours). Example: blocking 2-3 PM next Tuesday.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // All Time Slots
            // -----------------------------------------------------------------
            case 'clisyc-available-slots-list':
                return array(
                    array(
                        'id'      => 'slots-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'All Time Slots', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'This screen provides a raw database view of every time slot in the system.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Includes both "Available" slots (bookable) and "Blocked" slots (admin overrides).', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Use this to audit your schedule or bulk-delete specific slots.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'slots-filtering',
                        'title'   => __( 'Filtering', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Filtering', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Use the filters above the table to narrow the list:', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li><strong>' . __( 'Dates:', 'client-sync' ) . '</strong> '
                                . __( 'Select a start and end date for a specific range.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Dimensions:', 'client-sync' ) . '</strong> '
                                . __( 'Filter by Service, Staff member, Room, etc.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'slots-cleanup',
                        'title'   => __( 'Cleanup', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Cleanup', 'client-sync' ) . '</h3>'
                            . '<ul>'
                            . '<li><strong>' . __( 'Cleanup Past Available Slots:', 'client-sync' ) . '</strong> '
                                . __( 'Deletes all "Available" slots in the past.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Does NOT delete Blocked slots or Booked Appointments (stored separately).', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Useful for keeping your database lean.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Dimensions
            // -----------------------------------------------------------------
            case 'clisyc-dimensions':
                return array(
                    array(
                        'id'      => 'dimensions-what',
                        'title'   => __( 'What are Dimensions?', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'What are Dimensions?', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Dimensions are the building blocks of your booking system.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Each dimension type represents a category like Staff, Services, Rooms, or Equipment.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Enable/disable dimension types and configure their properties from this page.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'dimensions-creating',
                        'title'   => __( 'Creating Dimensions', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Creating Dimensions', 'client-sync' ) . '</h3>'
                            . '<ul>'
                            . '<li>' . __( 'Enable a dimension type, then add individual items (e.g., "Dr. Smith" under Staff).', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Each dimension item can have a color, icon, and custom fields.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Dimensions are assigned to time slots to define what each slot offers.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Booking Fields (Custom Fields)
            // -----------------------------------------------------------------
            case 'clisyc-custom-fields':
                return array(
                    array(
                        'id'      => 'custom-fields-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Booking Fields', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Custom Fields let you collect additional information during the booking process.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Create text fields, dropdowns, checkboxes, or file uploads.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Fields appear on the frontend booking form for clients to fill out.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'custom-fields-types',
                        'title'   => __( 'Field Types', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Field Types', 'client-sync' ) . '</h3>'
                            . '<ul>'
                            . '<li><strong>' . __( 'Text:', 'client-sync' ) . '</strong> '
                                . __( 'Single-line text input.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Textarea:', 'client-sync' ) . '</strong> '
                                . __( 'Multi-line text area.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Select:', 'client-sync' ) . '</strong> '
                                . __( 'Dropdown menu with predefined options.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Checkbox:', 'client-sync' ) . '</strong> '
                                . __( 'Yes/no toggle.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'File Upload:', 'client-sync' ) . '</strong> '
                                . __( 'Allow clients to attach files.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Reports
            // -----------------------------------------------------------------
            case 'clisyc-reports':
                return array(
                    array(
                        'id'      => 'reports-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Reports', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'The Reports page provides analytics and insights about your booking activity.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'View appointment trends, revenue summaries, and utilization rates.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Use date range filters to analyze specific periods.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Guide
            // -----------------------------------------------------------------
            case 'clisyc-guide':
                return array(
                    array(
                        'id'      => 'guide-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Guide', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'The Guide page combines your setup progress checklist with educational documentation for Client Sync.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'The Setup Checklist at the top tracks your onboarding milestones — mark items as done as you complete them.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'The sections below explain the core concepts, recommended setup flow, and advanced features.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Settings
            // -----------------------------------------------------------------
            case 'clisyc-settings':
                return array(
                    array(
                        'id'      => 'settings-overview',
                        'title'   => __( 'Settings Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Settings Overview', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Client Sync settings are organized into tabs for easy navigation.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li><strong>' . __( 'Appearance:', 'client-sync' ) . '</strong> '
                                . __( 'Customize colors, calendar display, and visual styling.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Behavior:', 'client-sync' ) . '</strong> '
                                . __( 'Configure booking rules, self-service options, and spam protection.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Notifications:', 'client-sync' ) . '</strong> '
                                . __( 'Set up email and SMS templates for booking confirmations and reminders.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'settings-more',
                        'title'   => __( 'More Settings', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'More Settings', 'client-sync' ) . '</h3>'
                            . '<ul>'
                            . '<li><strong>' . __( 'Payments:', 'client-sync' ) . '</strong> '
                                . __( 'Configure payment gateways (Stripe, PayPal, etc.).', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Automation:', 'client-sync' ) . '</strong> '
                                . __( 'Set up automated workflows and triggers.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Integrations:', 'client-sync' ) . '</strong> '
                                . __( 'Connect with Google Calendar, Zoom, and other services.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Import/Export:', 'client-sync' ) . '</strong> '
                                . __( 'Backup or migrate your booking data.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'HIPAA Compliance:', 'client-sync' ) . '</strong> '
                                . __( 'Enable HIPAA mode for healthcare practices.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Testing Tools
            // -----------------------------------------------------------------
            case 'clisyc-testing':
                return array(
                    array(
                        'id'      => 'testing-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Testing Tools', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Testing Tools help you verify your booking system is working correctly.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Available only when WP_DEBUG is enabled.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Use these tools to test notifications, API endpoints, and system diagnostics.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Appointments (post type list table)
            // -----------------------------------------------------------------
            case 'edit.php?post_type=clisyc_appointment':
                return array(
                    array(
                        'id'      => 'appointments-managing',
                        'title'   => __( 'Managing Appointments', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Managing Appointments', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'View and manage all booked appointments.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Use the list filters to find appointments by status, date, or client.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Click any appointment to view its full details and history.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'appointments-statuses',
                        'title'   => __( 'Appointment Statuses', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Appointment Statuses', 'client-sync' ) . '</h3>'
                            . '<ul>'
                            . '<li><strong>' . __( 'Confirmed:', 'client-sync' ) . '</strong> '
                                . __( 'The appointment is confirmed and scheduled.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Pending:', 'client-sync' ) . '</strong> '
                                . __( 'Awaiting confirmation from admin or payment.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Cancelled:', 'client-sync' ) . '</strong> '
                                . __( 'The appointment has been cancelled.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Completed:', 'client-sync' ) . '</strong> '
                                . __( 'The appointment has taken place.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Membership Plans (Pro)
            // -----------------------------------------------------------------
            case 'edit.php?post_type=clisyc_member_plan':
                return array(
                    array(
                        'id'      => 'memberships-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Membership Plans', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Membership Plans let you offer subscription-based access to your booking services.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Create plans that grant clients special booking privileges, discounts, or priority access.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Plans can restrict which services or time slots are available to members.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'memberships-rules',
                        'title'   => __( 'Plan Rules', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Plan Rules', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Each plan can include rules that control member access:', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li><strong>' . __( 'Booking Limits:', 'client-sync' ) . '</strong> '
                                . __( 'Set how many appointments a member can book per period.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Service Access:', 'client-sync' ) . '</strong> '
                                . __( 'Restrict which services are available to plan members.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Discount Rules:', 'client-sync' ) . '</strong> '
                                . __( 'Apply automatic discounts for plan members.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'memberships-woocommerce',
                        'title'   => __( 'WooCommerce', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'WooCommerce Integration', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Membership Plans can be linked to WooCommerce Subscriptions for automated billing and renewal.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Link a plan to a WooCommerce Subscription product for automatic enrollment.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'When a subscription is cancelled or expires, the member\'s access is automatically revoked.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Forms (Pro)
            // -----------------------------------------------------------------
            case 'edit.php?post_type=clisyc_form':
                return array(
                    array(
                        'id'      => 'forms-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Forms', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Create standalone intake forms and lead capture pages for your business.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Build custom forms with a drag-and-drop field editor.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Embed forms on any page using shortcodes or the block editor.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Submissions are stored as Leads that you can review and convert to appointments.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'forms-leads',
                        'title'   => __( 'Leads', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Managing Leads', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'When a visitor submits a form, a Lead is created automatically.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'View all leads from the Leads submenu under Forms.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Track lead status, follow up with clients, and convert leads to booked appointments.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Packages (Pro)
            // -----------------------------------------------------------------
            case 'edit.php?post_type=clisyc_package':
                return array(
                    array(
                        'id'      => 'packages-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Packages', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Packages let you bundle multiple appointments or services into a single offering.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Create packages with a fixed number of sessions (e.g., "10 Session Pack").', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Clients purchase a package and then book individual sessions as needed.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Track remaining sessions and package expiration dates automatically.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Output Templates (Pro)
            // -----------------------------------------------------------------
            case 'edit.php?post_type=clisyc_output_tmpl':
                return array(
                    array(
                        'id'      => 'outputs-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Output Templates', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Output Templates control how booking data is displayed on the frontend.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Customize the layout and styling of booking calendars, appointment lists, and confirmation pages.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Use the template editor to design pixel-perfect output for your brand.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'outputs-preview',
                        'title'   => __( 'Preview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Template Preview', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Use the Preview tab to see how your template will look before publishing.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'The preview renders with sample data so you can verify layout and styling.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Switch between the template list and preview using the tabs at the top of the page.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Venues (Pro — Seat Selection module)
            // -----------------------------------------------------------------
            case 'edit.php?post_type=clisyc_venue':
                return array(
                    array(
                        'id'      => 'venues-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Venues', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Venues represent physical locations with seat maps for reserved-seat booking.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Each venue has an SVG-based seat map that defines sections, rows, and individual seats.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Upload an SVG floor plan and the system will automatically discover seat elements.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Link a venue to a service (dimension item) so clients can pick seats when booking.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'venues-seat-maps',
                        'title'   => __( 'Seat Maps', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Seat Maps & Pricing', 'client-sync' ) . '</h3>'
                            . '<ul>'
                            . '<li><strong>' . __( 'SVG Upload:', 'client-sync' ) . '</strong> '
                                . __( 'Upload an SVG file with seat elements. The parser reads the structure to build an interactive seat map.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Pricing Tiers:', 'client-sync' ) . '</strong> '
                                . __( 'Assign pricing tiers (e.g., Orchestra, Mezzanine, Balcony) to groups of seats for tiered pricing.', 'client-sync' ) . '</li>'
                            . '<li><strong>' . __( 'Seat Holds:', 'client-sync' ) . '</strong> '
                                . __( 'When a client selects a seat, it is temporarily held to prevent double-booking during checkout.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // License (Pro)
            // -----------------------------------------------------------------
            case 'client-sync-pro-license':
                return array(
                    array(
                        'id'      => 'license-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'License Management', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Manage your Client Sync Pro license key to receive updates and access premium features.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Enter your license key to activate Pro features on this site.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Your license key was provided in your purchase confirmation email.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Deactivate your license here before transferring it to a different site.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Audit Logs (HIPAA)
            // -----------------------------------------------------------------
            case 'clisyc-audit-logs':
                return array(
                    array(
                        'id'      => 'audit-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Audit Logs', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'Audit Logs track all actions taken within Client Sync for compliance and accountability.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Every appointment creation, update, deletion, and data access is logged.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Use the filters to search logs by date, user, action type, or record.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                    array(
                        'id'      => 'audit-hipaa',
                        'title'   => __( 'HIPAA Compliance', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'HIPAA Compliance', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'When HIPAA mode is enabled, audit logs become a critical part of your compliance documentation.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Logs are retained according to your configured retention period.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Export logs for review by your compliance officer or during audits.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Template Preview (Pro — hidden page)
            // -----------------------------------------------------------------
            case 'clisyc-template-preview':
                return array(
                    array(
                        'id'      => 'preview-overview',
                        'title'   => __( 'Overview', 'client-sync' ),
                        'content' =>
                            '<h3>' . __( 'Template Preview', 'client-sync' ) . '</h3>'
                            . '<p>' . __( 'This page shows a live preview of your output template rendered with sample data.', 'client-sync' ) . '</p>'
                            . '<ul>'
                            . '<li>' . __( 'Use this to verify layout, styling, and content placement before publishing.', 'client-sync' ) . '</li>'
                            . '<li>' . __( 'Return to the template list using the tabs at the top of the page.', 'client-sync' ) . '</li>'
                            . '</ul>',
                    ),
                );

            // -----------------------------------------------------------------
            // Unknown / unsupported page — check for dimension CPT fallback.
            // -----------------------------------------------------------------
            default:
                return self::get_dimension_cpt_help( $page_slug );
        }
    }

    /**
     * Get generic help content for dimension CPT list/edit pages.
     *
     * Dimension post types are dynamically registered (e.g. clisyc_staff,
     * clisyc_service, clisyc_room) so they cannot be hard-coded as switch cases.
     * This method checks if the slug matches a dimension CPT pattern and returns
     * generic dimension help content.
     *
     * @param string $page_slug The admin page slug.
     * @return array Array of help tab arrays, or empty array.
     */
    private static function get_dimension_cpt_help( string $page_slug ): array {
        // Match slugs like "edit.php?post_type=clisyc_staff".
        if ( preg_match( '/^edit\.php\?post_type=clisyc_/', $page_slug ) ) {
            return array(
                array(
                    'id'      => 'dimension-items-overview',
                    'title'   => __( 'Overview', 'client-sync' ),
                    'content' =>
                        '<h3>' . __( 'Dimension Items', 'client-sync' ) . '</h3>'
                        . '<p>' . __( 'This page lists all items for this dimension type. Each item represents a bookable resource in your system.', 'client-sync' ) . '</p>'
                        . '<ul>'
                        . '<li>' . __( 'Click "Add New" to create a new item for this dimension.', 'client-sync' ) . '</li>'
                        . '<li>' . __( 'Each item can have its own color, icon, description, and custom fields.', 'client-sync' ) . '</li>'
                        . '<li>' . __( 'Items are assigned to time slots to define what each slot offers.', 'client-sync' ) . '</li>'
                        . '</ul>',
                ),
                array(
                    'id'      => 'dimension-items-usage',
                    'title'   => __( 'Usage', 'client-sync' ),
                    'content' =>
                        '<h3>' . __( 'How Dimensions Work', 'client-sync' ) . '</h3>'
                        . '<ul>'
                        . '<li>' . __( 'Dimension items appear as filter options on the frontend booking form.', 'client-sync' ) . '</li>'
                        . '<li>' . __( 'Clients can filter available time slots by selecting dimension values (e.g., a specific practitioner or service).', 'client-sync' ) . '</li>'
                        . '<li>' . __( 'To manage which dimension types are enabled, visit the Dimensions page.', 'client-sync' ) . '</li>'
                        . '</ul>',
                ),
            );
        }

        return array();
    }

    /**
     * Get the help sidebar HTML for a given admin page slug.
     *
     * @param string $page_slug The admin page slug.
     * @return string Sidebar HTML.
     */
    public static function get_help_sidebar( string $page_slug ): string {

        return '<p><strong>' . __( 'Need Help?', 'client-sync' ) . '</strong></p>'
            . '<p><a href="https://dependentmedia.com/" target="_blank">' . __( 'Documentation', 'client-sync' ) . '</a></p>'
            . '<p><a href="https://dependentmedia.com/support/" target="_blank">' . __( 'Support', 'client-sync' ) . '</a></p>';
    }
}
