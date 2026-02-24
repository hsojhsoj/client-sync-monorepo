<?php
/**
 * File: src/shared/includes/frontend/views/view-user-account-form.php
 * View template for the [clisyc_user_account] shortcode.
 *
 * Renders the form for a logged-in user to update their profile information.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Variables passed to this view from Shortcodes::render_user_account_form():
 * @var \DependentMedia\ClientSync\Services\FormRenderer $form_renderer
 * @var int    $user_id
 * @var array  $custom_fields
 * @var \WP_User $current_user_data
 * @var array|null $membership_details  <-- This is our new data!
 * @var array|null $credits
 * @var string|null $billing_portal_url
 */

if ( ! empty( $membership_details ) ) : ?>
<div class="clisyc-container clisyc-membership-dashboard clisyc-form-section">
    <h3><?php esc_html_e( 'My Membership', 'client-sync' ); ?></h3>
    <table class="clisyc-details-table">
        <tr>
            <th><?php esc_html_e( 'Plan', 'client-sync' ); ?></th>
            <td><?php echo esc_html( $membership_details['plan_name'] ); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Status', 'client-sync' ); ?></th>
            <td><?php echo esc_html( $membership_details['status'] ); ?></td>
        </tr>
        <?php if ( $membership_details['next_payment'] ) : ?>
        <tr>
            <th><?php esc_html_e( 'Next Payment', 'client-sync' ); ?></th>
            <td><?php echo esc_html( $membership_details['next_payment'] ); ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <?php if ( ! empty( $membership_details['usage'] ) ) : ?>
        <h4 style="margin-top: 1.5em;"><?php esc_html_e( 'Benefit Usage', 'client-sync' ); ?></h4>
        <ul class="clisyc-usage-list">
            <?php foreach ( $membership_details['usage'] as $clisyc_usage_item ) : ?>
                <li>
                    <strong><?php echo esc_html( $clisyc_usage_item['service_name'] ); ?>:</strong>
                    <?php
                    /* translators: 1: Number of bookings used, 2: Booking limit, 3: Time period (e.g. "this month") */
                    $clisyc_usage_format = __( '%1$s of %2$s used %3$s', 'client-sync' );
                    
                    echo esc_html( sprintf(
                        $clisyc_usage_format,
                        $clisyc_usage_item['used'],
                        $clisyc_usage_item['limit'],
                        $clisyc_usage_item['period']
                    ) );
                    ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ( $membership_details['manage_url'] ) : ?>
        <p style="margin-top: 1em;">
            <a href="<?php echo esc_url( $membership_details['manage_url'] ); ?>" class="button button-secondary">
                <?php esc_html_e( 'Manage Subscription', 'client-sync' ); ?>
            </a>
        </p>
    <?php endif; ?>
</div>
<?php endif;

if ( ! empty( $credits ) && is_array( $credits ) ) : ?>
<div class="clisyc-container clisyc-packages-dashboard clisyc-form-section">
    <h3><?php esc_html_e( 'My Packages & Credits', 'client-sync' ); ?></h3>
    <table class="clisyc-details-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Service', 'client-sync' ); ?></th>
                <th><?php esc_html_e( 'Credits Remaining', 'client-sync' ); ?></th>
                <th><?php esc_html_e( 'Expires On', 'client-sync' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $credits as $clisyc_key => $clisyc_data ) :
                if ( ($clisyc_data['credits_remaining'] ?? 0) <= 0 ) continue;
                $clisyc_service_id = str_replace( 'service_', '', $clisyc_key );
                $clisyc_service_name = get_the_title( $clisyc_service_id );
            ?>
            <tr>
                <td><?php echo esc_html( $clisyc_service_name ); ?></td>
                <td><?php echo esc_html( $clisyc_data['credits_remaining'] ); ?></td>
                <td>
                    <?php 
                    if ( !empty($clisyc_data['expires_at']) ) {
                        echo esc_html( wp_date( get_option('date_format'), $clisyc_data['expires_at'] ) );
                    } else {
                        esc_html_e( 'Never', 'client-sync' );
                    }
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ( ! empty( $billing_portal_url ) ) : ?>
<div class="clisyc-container clisyc-billing-portal clisyc-form-section">
    <h3><?php esc_html_e( 'Billing & Payments', 'client-sync' ); ?></h3>
    <p><?php esc_html_e( 'View your invoices, update payment methods, and manage billing details.', 'client-sync' ); ?></p>
    <p>
        <a href="<?php echo esc_url( $billing_portal_url ); ?>" class="button button-secondary">
            <?php esc_html_e( 'Manage Billing', 'client-sync' ); ?>
        </a>
    </p>
</div>
<?php endif; ?>
<?php

// *** REFACTORED: Updated option name ***
$clisyc_field_order = get_option( 'clisyc_custom_fields_order', array_keys( $custom_fields ) );
if ( ! is_array( $clisyc_field_order ) ) {
    $clisyc_field_order = [];
}
$clisyc_field_order = array_unique( array_merge( $clisyc_field_order, array_keys( $custom_fields ) ) );

?>
<?php // *** REFACTORED: Updated CSS classes *** ?>
<div class="clisyc-container clisyc-user-account-form">
    <form method="post" action="<?php echo esc_url( remove_query_arg( [ 'clisyc_message_type', 'clisyc_message_text' ] ) ); ?>">
        <?php // *** REFACTORED: Updated form field names *** ?>
        <input type="hidden" name="clisyc_update_account_action" value="1">
        <?php wp_nonce_field( 'clisyc_update_user_account_nonce', 'clisyc_update_account_nonce' ); ?>

        <!-- Standard Information Section -->
        <div class="clisyc-form-section">
            <h3><?php esc_html_e( 'Standard Information', 'client-sync' ); ?></h3>
            <div class="clisyc-form-field">
                <label for="clisyc_first_name"><?php esc_html_e( 'First Name', 'client-sync' ); ?></label>
                <input type="text" id="clisyc_first_name" name="clisyc_first_name" value="<?php echo esc_attr( $current_user_data->first_name ); ?>">
            </div>
            <div class="clisyc-form-field">
                <label for="clisyc_last_name"><?php esc_html_e( 'Last Name', 'client-sync' ); ?></label>
                <input type="text" id="clisyc_last_name" name="clisyc_last_name" value="<?php echo esc_attr( $current_user_data->last_name ); ?>">
            </div>
            <div class="clisyc-form-field">
                <label for="clisyc_email"><?php esc_html_e( 'Email', 'client-sync' ); ?> <span class="required">*</span></label>
                <input type="email" id="clisyc_email" name="clisyc_email" value="<?php echo esc_attr( $current_user_data->user_email ); ?>" required>
            </div>
            <div class="clisyc-form-field">
                <label for="clisyc_phone_number"><?php esc_html_e( 'Phone Number', 'client-sync' ); ?></label>
                <input type="tel" id="clisyc_phone_number" name="clisyc_phone_number"
                       value="<?php echo esc_attr( get_user_meta( $user_id, 'phone_number', true ) ); ?>"
                       placeholder="+1 (555) 123-4567">
                <p class="description"><?php esc_html_e( 'Used for SMS appointment reminders. Include country code.', 'client-sync' ); ?></p>
            </div>
        </div>

        <?php
        // SMS Preferences — only show when Pro is active and Twilio credentials are configured.
        $clisyc_sms_creds = get_option( 'clisyc_sms_credentials', [] );
        if ( function_exists( 'clisyc_pro_is_license_active' ) && clisyc_pro_is_license_active() && ! empty( $clisyc_sms_creds['twilio_sid'] ) ) : ?>
        <div class="clisyc-form-section">
            <h3><?php esc_html_e( 'SMS Preferences', 'client-sync' ); ?></h3>
            <div class="clisyc-form-field">
                <label>
                    <input type="checkbox" name="clisyc_sms_opt_out" value="1"
                           <?php checked( get_user_meta( $user_id, 'clisyc_sms_opt_out', true ), '1' ); ?>>
                    <?php esc_html_e( 'Opt out of SMS text message notifications', 'client-sync' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'Uncheck to receive appointment reminders and updates via text.', 'client-sync' ); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Custom Information Section -->
        <?php if ( ! empty( $custom_fields ) ) : ?>
            <div class="clisyc-form-section">
                <h3><?php esc_html_e( 'Custom Information', 'client-sync' ); ?></h3>
                <?php
                foreach ( $clisyc_field_order as $clisyc_field_key ) {
                    if ( ! isset( $custom_fields[ $clisyc_field_key ] ) ) {
                        continue;
                    }
                    $clisyc_field_definition = $custom_fields[ $clisyc_field_key ];
                    $clisyc_current_value    = get_user_meta( $user_id, $clisyc_field_key, true );

                    // The FormRenderer will output the complete field HTML.
                    $form_renderer->render_frontend_custom_field( $clisyc_field_key, $clisyc_field_definition, $clisyc_current_value, 'user_account' );
                }
                ?>
            </div>
        <?php endif; ?>
        
        <div class="clisyc-form-field">
            <button type="submit" class="clisyc-submit-button button button-primary"><?php esc_html_e( 'Update Account', 'client-sync' ); ?></button>
        </div>

    </form>
</div>