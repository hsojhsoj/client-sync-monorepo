<?php
/**
 * File: src/shared/includes/admin/views/view-shortcodes-page.php -> client-sync/includes/admin/views/view-shortcodes-page.php
 * View for the Shortcodes reference tab in the settings.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$clisyc_shortcodes = [
    'Conversion & Conditional Content' => [
        'clisyc_booking_confirmation' => [
            'title' => '[clisyc_booking_confirmation]',
            'purpose' => 'Displays a high-impact success message and appointment summary. It only appears if a booking was made within the specified timeout, allowing you to use one page for both "Booking Success" and "My Appointments".',
            'attributes' => [
                'timeout' => 'Optional. Minutes after booking to show the message. Default: "10".',
                'style' => 'Optional. UI style. Values: "card" (default), "banner", "minimal".',
                'success_title' => 'Optional. The main heading text.',
                'success_message' => 'Optional. The secondary description text.',
                'show_details' => 'Optional. Show the full appointment summary card. Values: "true" (default), "false".',
                'show_confetti' => 'Optional. Trigger celebration animation. Values: "true" (default), "false".',
                'fallback_content' => 'Optional. Content to show if no recent booking is detected.',
            ],
            'example' => '[clisyc_booking_confirmation timeout="30" style="card" success_title="High Five!"]',
        ],
        'clisyc_if_recent_booking' => [
            'title' => '[clisyc_if_recent_booking]...[/clisyc_if_recent_booking]',
            'purpose' => 'A wrapper shortcode that only displays the enclosed content if the user has completed a booking recently. Perfect for "Next Steps" instructions or special post-booking offers.',
            'attributes' => [
                'timeout' => 'Minutes after booking to keep content visible. Default: "10".',
            ],
            'example' => '[clisyc_if_recent_booking]Thanks! Please check your email for the intake form.[/clisyc_if_recent_booking]',
        ],
        'clisyc_if_no_recent_booking' => [
            'title' => '[clisyc_if_no_recent_booking]...[/clisyc_if_no_recent_booking]',
            'purpose' => 'The opposite of the "if_recent" shortcode. Enclosed content is only visible if the user has NOT made a booking recently. Use this to show a "Book Now" CTA that disappears after they convert.',
            'attributes' => [
                'timeout' => 'Minutes after booking to keep content hidden. Default: "10".',
            ],
            'example' => '[clisyc_if_no_recent_booking]<a href="/book">Schedule your first session!</a>[/clisyc_if_no_recent_booking]',
        ],
    ],
    'Booking Forms & Search' => [
        'clisyc_booking_form' => [
            'title' => '[clisyc_booking_form]',
            'purpose' => 'The universal booking entry point. It intelligently displays either the Time Slot Calendar or the Date Range Search form based on global settings or page context.',
            'attributes' => [
                'mode' => 'Optional. Force a specific layout. Values: "auto" (default), "calendar" (Time Slots), "search" (Date Range).',
                'show_booked_toggle' => 'Optional. Displays a checkbox in the filter panel allowing users to show already booked slots. Default: "false".',
            ],
            'example' => '[clisyc_booking_form mode="calendar" show_booked_toggle="true"]',
        ],
        'clisyc_booking_wizard' => [
            'title' => '[clisyc_booking_wizard]',
            'purpose' => 'A step-by-step wizard interface for booking. It dynamically adapts to your configured dimensions (e.g., Service -> Practitioner -> Time), offering a guided flow instead of a single dashboard.',
            'attributes' => [
                'dimensions' => 'Optional. Comma-separated list of dimension slugs to include in the flow. Default: All visible dimensions.',
                'show_prices' => 'Optional. Show prices on selection cards. Values: "true" (default), "false".',
                'show_descriptions' => 'Optional. Show descriptions on selection cards. Values: "true" (default), "false".',
            ],
            'example' => '[clisyc_booking_wizard show_prices="true"]',
        ],
        'clisyc_hybrid_booking' => [
            'title' => '[clisyc_hybrid_booking]',
            'purpose' => 'A sophisticated Faceted Search Interface. It allows users to perform multi-criteria filtering across all enabled dimensions to view an aggregated grid of availability.',
        ],
        'clisyc_compact_booking_form' => [
            'title' => '[clisyc_compact_booking_form]',
            'purpose' => 'Displays availability in a streamlined, time-gutter grid. Ideal for wide layouts where showing a 7-day overview at once is preferred over a standard calendar.',
        ],
        'clisyc_availability_search' => [
            'title' => '[clisyc_availability_search]',
            'purpose' => 'Renders a standalone "Check Availability" search bar (Check-in/Check-out). Useful for homepages or headers to direct users to a results page.',
        ],
        'clisyc_search_results' => [
            'title' => '[clisyc_search_results]',
            'purpose' => 'Used specifically on the "Search Results Page" to display items matching a Date Range search.',
        ],
    ],
    'Timeline Visualization' => [
        'clisyc_timeline' => [
            'title' => '[clisyc_timeline]',
            'purpose' => 'Displays an interactive multi-track timeline visualization showing appointments and availability across multiple resources (Services, Practitioners, Rooms).',
            'attributes' => [
                'view' => 'Timeline view mode. Values: "day", "week" (default), "month".',
                'resources' => 'Comma-separated list of CPT slugs. Default: "clisyc_service,clisyc_practitioner,clisyc_room".',
                'color_by' => 'Color code appointments by: "service", "staff", or "status".',
            ],
            'example' => '[clisyc_timeline view="day" resources="clisyc_practitioner" color_by="status"]',
        ],
    ],
    'Client Dashboards' => [
        'clisyc_appointments_cards' => [
            'title' => '[clisyc_appointments_cards]',
            'purpose' => 'A modern, responsive grid view of the logged-in user\'s appointments with search and quick actions.',
        ],
        'clisyc_user_appointments_calendar' => [
            'title' => '[clisyc_user_appointments_calendar]',
            'purpose' => 'Renders a full-sized monthly calendar of the user\'s booked appointments.',
        ],
        'clisyc_user_mini_calendar' => [
            'title' => '[clisyc_user_mini_calendar]',
            'purpose' => 'A compact "widget-style" calendar. Shows dots for appointments; clicking a day reveals details below.',
        ],
        'clisyc_user_account' => [
            'title' => '[clisyc_user_account]',
            'purpose' => 'Displays the user\'s profile form, allowing them to update info and view membership details.',
        ],
        'clisyc_appointment_detail' => [
            'title' => '[clisyc_appointment_detail]',
            'purpose' => 'Displays full details of a single appointment. Usually targeted via the "view_id" URL parameter.',
        ],
        'clisyc_registration' => [
            'title' => '[clisyc_registration]',
            'purpose' => 'Displays a user registration form including your defined Client Custom Fields.',
        ],
    ],
    'Manager & Staff Tools' => [
        'clisyc_manager_appointments' => [
            'title' => '[clisyc_manager_appointments]',
            'purpose' => 'Frontend dashboard for staff to view, filter, and export appointments for ALL clients. Requires "edit_others_posts" capability.',
        ],
        'clisyc_manager_calendar' => [
            'title' => '[clisyc_manager_calendar]',
            'purpose' => 'Master MASTER calendar for staff showing every appointment in the system.',
        ],
        'clisyc_manager_edit_appointment' => [
            'title' => '[clisyc_manager_edit_appointment]',
            'purpose' => 'Displays detailed appointment information with an edit form for custom fields. Used as the "Details" link destination from the Manager Appointments dashboard. Requires "edit_others_posts" capability.',
            'attributes' => [
                'appointment_id' => 'Optional. The appointment ID to display. Usually passed via URL parameter (?appointment_id=123).',
            ],
            'example' => '[clisyc_manager_edit_appointment]',
        ],
        'clisyc_manager_edit_notes' => [
            'title' => '[clisyc_manager_edit_notes]',
            'purpose' => 'Provides a dedicated interface for staff to view and edit appointment notes. Shows a list of appointments with note excerpts and links to edit each one.',
        ],
    ],
    'Pro Features' => [
        'clisyc_membership_plans' => [
            'title' => '[clisyc_membership_plans]',
            'purpose' => '(Pro) Displays your published Membership Plans as a responsive pricing card grid with subscribe buttons. Integrates with Stripe for recurring subscription checkout.',
            'attributes' => [
                'columns' => 'Optional. Number of grid columns (2, 3, or 4). Default: "3".',
                'highlight' => 'Optional. The Plan ID to visually highlight as "Recommended".',
            ],
            'example' => '[clisyc_membership_plans columns="3" highlight="123"]',
        ],
        'clisyc_form' => [
            'title' => '[clisyc_form]',
            'purpose' => '(Pro) Renders a custom form created with the Drag & Drop Form Builder.',
            'attributes' => [
                'id' => 'Required. The numeric ID of the form post.',
            ],
        ],
        'clisyc_membership_info' => [
            'title' => '[clisyc_membership_info]',
            'purpose' => '(Pro) Displays detailed usage statistics for the user\'s active membership plan (e.g., "2 of 5 bookings used").',
        ],
    ],
];
?>

<div class="wrap clisyc-shortcodes-page">
    <h1><?php esc_html_e( 'Shortcode Reference', 'client-sync' ); ?></h1>
    <p class="about-text"><?php esc_html_e( 'Copy and paste these shortcodes into any WordPress page, post, or widget to display Client Sync features.', 'client-sync' ); ?></p>

    <?php foreach ( $clisyc_shortcodes as $clisyc_group_title => $clisyc_group_shortcodes ) : ?>
        <h2 style="margin-top: 2em; border-bottom: 1px solid #ccc; padding-bottom: 10px;"><?php echo esc_html( $clisyc_group_title ); ?></h2>
        <?php foreach ( $clisyc_group_shortcodes as $clisyc_shortcode_data ) : ?>
            <div class="postbox">
                <div class="postbox-header">
                    <h2 class="hndle" style="display: flex; align-items: center; gap: 10px;">
                        <code><?php echo esc_html( $clisyc_shortcode_data['title'] ); ?></code>
                        <button type="button" class="button button-secondary button-small clisyc-copy-shortcode-button" data-shortcode="<?php echo esc_attr( $clisyc_shortcode_data['title'] ); ?>" title="<?php esc_attr_e( 'Copy to clipboard', 'client-sync' ); ?>">
                            <span class="dashicons dashicons-admin-page"></span>
                            <span class="clisyc-copy-text"><?php esc_html_e( 'Copy', 'client-sync' ); ?></span>
                        </button>
                    </h2>
                </div>
                <div class="inside">
                    <p><strong><?php esc_html_e( 'Purpose:', 'client-sync' ); ?></strong> <?php echo esc_html( $clisyc_shortcode_data['purpose'] ); ?></p>
                    <?php if ( ! empty( $clisyc_shortcode_data['attributes'] ) ) : ?>
                        <div style="background: #f9f9f9; padding: 10px; border-radius: 4px; margin-top: 10px;">
                            <strong><?php esc_html_e( 'Attributes:', 'client-sync' ); ?></strong>
                            <ul style="list-style: disc; margin-left: 20px; margin-top: 5px;">
                                <?php foreach ( $clisyc_shortcode_data['attributes'] as $clisyc_attr => $clisyc_desc ) : ?>
                                    <li><code><?php echo esc_html( $clisyc_attr ); ?></code>: <?php echo esc_html( $clisyc_desc ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $clisyc_shortcode_data['example'] ) ) : ?>
                        <p style="margin-top: 10px;"><strong><?php esc_html_e( 'Example:', 'client-sync' ); ?></strong> <code><?php echo esc_html( $clisyc_shortcode_data['example'] ); ?></code></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>