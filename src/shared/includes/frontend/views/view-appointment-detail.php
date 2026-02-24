<?php
/**
 * File: src/shared/includes/frontend/views/view-appointment-detail.php
 * View template for the redesigned [clisyc_appointment_detail] shortcode.
 *
 * UPDATED: Added HIPAA compliance features:
 * - Audit logging for appointment views
 * - Encryption/decryption support for sensitive fields
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend/Views
 */

if (!defined('ABSPATH')) {
    exit;
}

use DependentMedia\ClientSync\Constants;

/**
 * Variables passed from Shortcodes::render_appointment_detail():
 * @var \WP_Post $appointment_post
 * @var string   $formatted_date
 * @var string   $formatted_time
 * @var string   $title
 * @var \WP_Post_Status $status_obj
 * @var array    $all_custom_fields
 * @var array    $field_order
 * @var string   $notes
 * @var string   $export_url
 * @var \DependentMedia\ClientSync\Services\FormRenderer $form_renderer
 */

// =========================================================================
// HIPAA COMPLIANCE: Log appointment view
// =========================================================================
/**
 * Fires before the appointment detail is rendered.
 * The Audit_Logger class listens for this hook to log the view action.
 *
 * @param int $appointment_id The ID of the appointment being viewed.
 */
do_action( 'clisyc_before_appointment_detail_render', $appointment_post->ID );

// =========================================================================
// HIPAA COMPLIANCE: Initialize encryption service for field decryption
// =========================================================================
$clisyc_encryption_available = class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' );
$clisyc_encryption = $clisyc_encryption_available 
    ? \DependentMedia\ClientSync\Services\Encryption_Service::get_instance() 
    : null;

// Helper function to get a friendly status class.
// Guarded to prevent fatal error if shortcode is used multiple times on one page.
if ( ! function_exists( 'clisyc_get_status_class' ) ) {
    function clisyc_get_status_class( $status ) {
        switch ( $status ) {
            case 'publish':
            case Constants::STATUS_CONFIRMED:
            case Constants::STATUS_PAID_ON_DAY:
            case Constants::STATUS_CHECKED_IN:
            case 'wc-processing':
            case 'wc-completed':
                return 'clisyc-status-confirmed';
            case Constants::STATUS_PENDING:
            case Constants::STATUS_PENDING_PAYMENT:
                return 'clisyc-status-pending';
            case 'trash':
            case Constants::STATUS_CANCELLED:
                return 'clisyc-status-cancelled';
            case Constants::STATUS_WAITLISTED:
                return 'clisyc-status-waitlisted';
            case 'draft':
                return 'clisyc-status-completed';
            default:
                return 'clisyc-status-pending';
        }
    }
}
$clisyc_status_class = clisyc_get_status_class($appointment_post->post_status);

// Cancellation manager for action buttons
$clisyc_cancellation_manager = new \DependentMedia\ClientSync\Core\Cancellation_Manager($appointment_post->ID);
$clisyc_can_manage = $clisyc_cancellation_manager->can_be_managed();
?>

<div class="clisyc-appointment-detail-card">

    <div class="clisyc-detail-header">
        <div class="clisyc-detail-status-badge <?php echo esc_attr($clisyc_status_class); ?>">
            <?php echo $status_obj ? esc_html($status_obj->label) : esc_html(get_post_status($appointment_post->ID)); ?>
        </div>
        <h2><?php echo esc_html($title); ?></h2>
    </div>

    <div class="clisyc-core-info">
        <div class="clisyc-info-item">
            <div class="clisyc-info-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
            <div class="clisyc-info-text">
                <strong><?php esc_html_e('Date', 'client-sync'); ?></strong>
                <span><?php echo esc_html($formatted_date); ?></span>
            </div>
        </div>
        <div class="clisyc-info-item">
            <div class="clisyc-info-icon"><span class="dashicons dashicons-clock"></span></div>
            <div class="clisyc-info-text">
                <strong><?php esc_html_e('Time', 'client-sync'); ?></strong>
                <span><?php echo esc_html($formatted_time); ?></span>
            </div>
        </div>
    </div>

    <?php if ( Constants::STATUS_WAITLISTED === $appointment_post->post_status ) :
        $clisyc_waitlist_position = get_post_meta( $appointment_post->ID, Constants::META_WAITLIST_POSITION, true );
    ?>
        <div class="clisyc-detail-section clisyc-waitlist-info" style="background: #eff6ff; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
            <h3 style="margin-top: 0;"><?php esc_html_e( 'Waitlist Information', 'client-sync' ); ?></h3>
            <p style="margin: 0;">
                <?php
                printf(
                    /* translators: %s: waitlist position number */
                    esc_html__( 'You are #%s on the waitlist. You will be notified automatically if a spot opens up.', 'client-sync' ),
                    '<strong>' . esc_html( $clisyc_waitlist_position ) . '</strong>'
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php
    $clisyc_dimensions = get_post_meta($appointment_post->ID, Constants::META_SLOT_DIMENSIONS, true);
    if (!empty($clisyc_dimensions) && is_array($clisyc_dimensions)) :
        $clisyc_custom_types = get_option(Constants::OPTION_CUSTOM_DIMENSION_TYPES, []);
    ?>
        <div class="clisyc-detail-section">
            <h3><?php esc_html_e('Appointment Details', 'client-sync'); ?></h3>
            <ul class="clisyc-details-list">
                <?php foreach ($clisyc_dimensions as $clisyc_slug => $post_id) : ?>
                    <?php
                    $clisyc_cpt_label = $clisyc_custom_types[$clisyc_slug]['singular'] ?? ucfirst(str_replace(['clisyc_', '_'], ['', ' '], $clisyc_slug));
                    $clisyc_item_title = get_the_title($post_id);
                    $clisyc_dim_icon  = $clisyc_custom_types[ $clisyc_slug ]['icon'] ?? '';

                    // Dashicons are stored as "dashicons-clipboard" and need the
                    // base "dashicons" class prepended. FontAwesome icons are stored
                    // with both classes already, e.g. "fas fa-briefcase".
                    $clisyc_icon_classes = '';
                    if ( ! empty( $clisyc_dim_icon ) ) {
                        if ( 0 === strpos( $clisyc_dim_icon, 'dashicons-' ) ) {
                            $clisyc_icon_classes = 'dashicons ' . $clisyc_dim_icon;
                        } else {
                            $clisyc_icon_classes = $clisyc_dim_icon;
                        }
                    }
                    ?>
                    <li>
                        <?php if ( ! empty( $clisyc_icon_classes ) ) : ?>
                            <span class="clisyc-dim-icon"><span class="<?php echo esc_attr( $clisyc_icon_classes ); ?>"></span></span>
                        <?php endif; ?>
                        <span class="label"><?php echo esc_html($clisyc_cpt_label); ?></span>
                        <span class="value"><?php echo $clisyc_item_title ? esc_html($clisyc_item_title) : '<em>' . esc_html__('N/A', 'client-sync') . '</em>'; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $venue_location_data ) ) : ?>
        <div class="clisyc-detail-section clisyc-venue-location">
            <h3>
                <span class="dashicons dashicons-location" style="margin-right:6px;vertical-align:middle;"></span>
                <?php esc_html_e( 'Venue & Location', 'client-sync' ); ?>
            </h3>

            <?php if ( ! empty( $venue_location_data['venue_photo'] ) ) : ?>
                <div class="clisyc-venue-location__photo">
                    <img src="<?php echo esc_url( $venue_location_data['venue_photo'] ); ?>"
                         alt="<?php echo esc_attr( $venue_location_data['venue_name'] ?? '' ); ?>"
                         loading="lazy" />
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $venue_location_data['venue_name'] ) ) : ?>
                <div class="clisyc-venue-location__name"><?php echo esc_html( $venue_location_data['venue_name'] ); ?></div>
            <?php endif; ?>

            <?php
            // Build multi-line address.
            $clisyc_address_parts = array_filter( [
                $venue_location_data['address'] ?? '',
                implode( ', ', array_filter( [
                    $venue_location_data['city'] ?? '',
                    $venue_location_data['state'] ?? '',
                    $venue_location_data['postal_code'] ?? '',
                ] ) ),
                $venue_location_data['country'] ?? '',
            ] );
            if ( ! empty( $clisyc_address_parts ) ) : ?>
                <address class="clisyc-venue-location__address">
                    <?php echo esc_html( implode( "\n", $clisyc_address_parts ) ); ?>
                </address>
            <?php endif; ?>

            <?php if ( ! empty( $venue_location_data['map_embed'] ) ) : ?>
                <div class="clisyc-venue-location__map">
                    <iframe
                        src="<?php echo esc_url( $venue_location_data['map_embed'] ); ?>"
                        width="100%"
                        height="300"
                        style="border:0; border-radius:8px;"
                        allowfullscreen=""
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                    ></iframe>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $seat_details ) ) : ?>
        <div class="clisyc-detail-section clisyc-seat-details">
            <h3><?php esc_html_e( 'Your Seats', 'client-sync' ); ?></h3>
            <div class="clisyc-seat-details__grid">
                <?php foreach ( $seat_details as $seat ) : ?>
                    <div class="clisyc-seat-details__card">
                        <div class="clisyc-seat-details__icon">
                            <span class="dashicons dashicons-tickets-alt"></span>
                        </div>
                        <div class="clisyc-seat-details__info">
                            <?php if ( ! empty( $seat['section'] ) ) : ?>
                                <span class="clisyc-seat-details__section"><?php echo esc_html( $seat['section'] ); ?></span>
                            <?php endif; ?>
                            <span class="clisyc-seat-details__row-seat">
                                <?php
                                if ( ! empty( $seat['row'] ) && ! empty( $seat['seat'] ) ) {
                                    echo esc_html( $seat['row'] . ' · ' . $seat['seat'] );
                                } elseif ( ! empty( $seat['seat'] ) ) {
                                    echo esc_html( $seat['seat'] );
                                } else {
                                    echo esc_html( $seat['seat_id'] );
                                }
                                ?>
                            </span>
                            <?php if ( ! empty( $seat['category'] ) ) : ?>
                                <span class="clisyc-seat-details__category"><?php echo esc_html( ucfirst( $seat['category'] ) ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $venue_map_data['svg_markup'] ) ) : ?>
        <div class="clisyc-detail-section clisyc-venue-map">
            <h3><?php esc_html_e( 'Venue Map', 'client-sync' ); ?></h3>
            <div class="clisyc-venue-map__container"
                 id="clisyc-venue-map"
                 data-all-seats="<?php echo esc_attr( wp_json_encode( $venue_map_data['all_seat_element_ids'] ) ); ?>"
                 data-selected-seats="<?php echo esc_attr( wp_json_encode( $venue_map_data['selected_seat_element_ids'] ) ); ?>">
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG markup is sanitised on upload.
                echo $venue_map_data['svg_markup'];
                ?>
            </div>
            <p class="clisyc-venue-map__legend">
                <span class="clisyc-venue-map__legend-item clisyc-venue-map__legend-item--selected">
                    <span class="clisyc-venue-map__legend-swatch"></span>
                    <?php esc_html_e( 'Your seats', 'client-sync' ); ?>
                </span>
                <span class="clisyc-venue-map__legend-item clisyc-venue-map__legend-item--other">
                    <span class="clisyc-venue-map__legend-swatch"></span>
                    <?php esc_html_e( 'Other seats', 'client-sync' ); ?>
                </span>
            </p>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $qr_code_data ) ) : ?>
        <div class="clisyc-detail-section clisyc-qr-ticket">
            <h3><?php esc_html_e( 'Your Ticket', 'client-sync' ); ?></h3>
            <div class="clisyc-qr-ticket__wrap">
                <div class="clisyc-qr-ticket__code" id="clisyc-qr-code"
                     data-qr-payload="<?php echo esc_attr( $qr_code_data ); ?>">
                </div>
                <p class="clisyc-qr-ticket__hint">
                    <?php esc_html_e( 'Show this QR code at the venue for check-in.', 'client-sync' ); ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($field_order) && !empty($all_custom_fields)) : ?>
        <div class="clisyc-detail-section">
            <h3><?php esc_html_e('Additional Information', 'client-sync'); ?></h3>
            <ul class="clisyc-details-list">
                <?php
                foreach ($field_order as $clisyc_field_key) {
                    if (!isset($all_custom_fields[$clisyc_field_key])) continue;
                    $clisyc_field = $all_custom_fields[$clisyc_field_key];
                    if ('custom_html' === ($clisyc_field['type'] ?? '')) continue; // Skip HTML blocks in this list
                    
                    $clisyc_current_value = get_post_meta($appointment_post->ID, $clisyc_field_key, true);
                    
                    // =========================================================================
                    // HIPAA COMPLIANCE: Decrypt encrypted field values
                    // =========================================================================
                    if ( $clisyc_encryption && ! empty( $clisyc_current_value ) && is_string( $clisyc_current_value ) ) {
                        $clisyc_current_value = $clisyc_encryption->maybe_decrypt( $clisyc_current_value, $clisyc_field_key );
                    }
                    
                    $clisyc_display_value = '<em>' . esc_html__('Not Provided', 'client-sync') . '</em>';
                    
                    if (!empty($clisyc_current_value)) {
                        $clisyc_display_value = is_array($clisyc_current_value) ? implode(', ', $clisyc_current_value) : nl2br(esc_html($clisyc_current_value));
                    }
                    ?>
                    <li>
                        <span class="label"><?php echo esc_html($clisyc_field['label']); ?></span>
                        <span class="value"><?php echo wp_kses_post($clisyc_display_value); ?></span>
                    </li>
                <?php } ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="clisyc-detail-actions">
        <?php if ( Constants::STATUS_WAITLISTED === $appointment_post->post_status ) : ?>
            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'clisyc_self_service_action' => 'leave_waitlist', 'appointment_id' => $appointment_post->ID ] ), 'clisyc_leave_waitlist_appt_' . $appointment_post->ID ) ); ?>" class="button clisyc-button-cancel" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to leave the waitlist?', 'client-sync' ); ?>');">
                <span class="dashicons dashicons-no"></span> <?php esc_html_e( 'Leave Waitlist', 'client-sync' ); ?>
            </a>
        <?php else : ?>
            <?php if (!empty($export_url)) : ?>
                <a href="<?php echo esc_url($export_url); ?>" class="button clisyc-button-ical" download>
                    <span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e('Add to Calendar', 'client-sync'); ?>
                </a>
            <?php endif; ?>

            <?php if ($clisyc_can_manage) : ?>
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['clisyc_self_service_action' => 'cancel', 'appointment_id' => $appointment_post->ID]), 'clisyc_cancel_appt_' . $appointment_post->ID)); ?>" class="button clisyc-button-cancel" onclick="return confirm('<?php esc_attr_e('Are you sure you want to cancel this appointment?', 'client-sync'); ?>');">
                    <span class="dashicons dashicons-no"></span> <?php esc_html_e('Cancel Appointment', 'client-sync'); ?>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>