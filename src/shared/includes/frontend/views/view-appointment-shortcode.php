<?php
/**
 * File: src/shared/includes/frontend/views/view-appointment-shortcode.php
 * View template for the main [clisyc_appointment] shortcode.
 *
 * FIXED: Restructured HTML to use a single, all-encompassing <form> tag. This ensures
 * the submit event fires correctly for both time-slot and date-range booking modes,
 * resolving the primary dimension submission bug.
 * MODIFIED: Added a client-side validation error container.
 * REFACTORED: Prefixed variables to comply with WordPress standards.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend/Views
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use DependentMedia\ClientSync\Constants;

/**
 * Variables passed to this view from Shortcodes::render_appointment_form():
 * @var array    $services
 * @var bool     $payment_required
 * @var array    $initial_form_values
 * @var array    $appointment_custom_fields
 * @var array    $appointment_field_order
 * @var \DependentMedia\ClientSync\Services\FormRenderer $form_renderer
 * @var bool     $show_filters
 * @var bool     $hide_booked
 * @var int|null $preselected_service_id
 * @var string   $filter_layout ('vertical' or 'horizontal')
 */

$clisyc_hide_booked = $hide_booked ?? false;
?>

<?php if ( ! is_user_logged_in() ): ?>

    <!-- GUEST FORM -->
    <div class="clisyc-guest-booking-section">
        <?php // *** REFACTORED: Updated all form/element IDs and field names *** ?>
        <form method="post" id="clisyc_appointment_form" action="<?php echo esc_url( get_permalink() ); ?>" novalidate>
            <div class="clisyc-faceted-search-layout">
                <input type="hidden" name="_clisyc_frontend_booking_submit" value="1">
                <input type="hidden" name="clisyc_guest_registration" value="1">
                <input type="hidden" name="booking_mode" value="slot"> <!-- Default value, JS will update -->
                <input type="hidden" name="start_date" value="">
                <input type="hidden" name="end_date" value="">
                <input type="hidden" name="appointment_date" id="appointment_date" required value="">
                <input type="hidden" name="time_slot" id="time_slot" required value="">
                <input type="hidden" name="appointment_duration_minutes" id="appointment_duration_minutes" required value="">

                <?php if ( ! $show_filters && $preselected_service_id ) : ?>
                    <input type="hidden" name="clisyc_service_id" id="service_id" value="<?php echo esc_attr($preselected_service_id); ?>">
                <?php else: ?>
                    <input type="hidden" name="clisyc_service_id" id="service_id" value="<?php echo esc_attr($initial_form_values['service_id'] ?? ''); ?>">
                <?php endif; ?>

                <?php wp_nonce_field( Constants::POST_TYPE_APPOINTMENT, 'clisyc_appointment_nonce' ); ?>

                <!-- START: NEW DEDICATED CONTAINER FOR HIDDEN INPUTS -->
                <div id="clisyc-hidden-inputs-container"></div>
                <!-- END: NEW DEDICATED CONTAINER -->

                <div style="position: absolute; left: -9999px;" aria-hidden="true">
                    <label for="contact_email_hp"><?php esc_html_e( 'Please leave this field blank.', 'client-sync' ); ?></label>
                    <input type="text" name="contact_email_hp" id="contact_email_hp" tabindex="-1" autocomplete="off">
                </div>
                <input type="hidden" name="clisyc_timestamp" value="<?php echo esc_attr( time() ); ?>">
                <input type="hidden" name="g-recaptcha-response" id="recaptcha_response">

                <div id="clisyc-availability-filters" class="clisyc-availability-filters-container clisyc-form-section clisyc-filter-layout-<?php echo esc_attr( $filter_layout ); ?>" <?php if ( !$show_filters ) echo 'style="display:none;"'; ?>>
                    <h3><?php esc_html_e('1. Select Your Options', 'client-sync'); ?></h3>
                    
                    <div class="clisyc-time-selection-wrapper">
                        <div class="clisyc-timezone-filter-wrapper clisyc-form-field">
                            <label for="clisyc-calendar-timezone-selector"><?php esc_html_e('Your Timezone:', 'client-sync'); ?></label>
                            <select id="clisyc-calendar-timezone-selector"></select>
                            <div id="clisyc-timezone-note" class="clisyc-timezone-note"></div>
                        </div>
                        <div class="clisyc-live-clock-wrapper">
                            <label><?php esc_html_e('Current Selected Time', 'client-sync'); ?></label>
                            <div id="clisyc-live-clock" class="clisyc-live-clock">--:--:-- --</div>
                        </div>
                    </div>

                    <div id="clisyc-filter-status" class="clisyc-filter-status-message"></div>
                </div>

                <div class="clisyc-main-content-area">
                    <div id="clisyc-service-details-container" class="clisyc-service-details clisyc-form-section" style="display:none;"></div>

                    <!-- This container now holds BOTH booking UIs and will be hidden by default -->
                    <div id="clisyc-booking-interface-container" style="display: none;">
                        <!-- Date Range Picker UI -->
                        <div id="clisyc-date-range-picker-container" class="clisyc-form-section">
                            <h3><?php echo $show_filters ? '2.' : '1.'; ?> <?php esc_html_e('Choose Your Dates', 'client-sync'); ?></h3>
                            <div id="clisyc-inline-datepicker"><p><em><?php esc_html_e('Loading availability...', 'client-sync'); ?></em></p></div>
                            <div id="clisyc-date-range-summary" style="margin-top: 1em; padding: 10px; background: #f0f8ff; border: 1px solid #d1e9ff; border-radius: 4px; display:none;">
                                <p style="margin:0;">
                                    <strong><?php esc_html_e('Check-in:', 'client-sync'); ?></strong> <span id="clisyc-summary-start-date"></span><br>
                                    <strong><?php esc_html_e('Check-out:', 'client-sync'); ?></strong> <span id="clisyc-summary-end-date"></span><br>
                                    <strong><?php esc_html_e('Total Nights:', 'client-sync'); ?></strong> <span id="clisyc-summary-nights"></span>
                                </p>
                                <div id="clisyc-dynamic-price-container" class="clisyc-service-price" style="margin-top: 5px;"></div>
                            </div>
                        </div>

                        <!-- Time Slot Calendar UI -->
                        <div id="clisyc-calendar-and-form-fields" class="clisyc-form-section">
                            <h3><?php echo $show_filters ? '2.' : '1.'; ?> <?php esc_html_e('Choose Date & Time', 'client-sync'); ?></h3>
                            <div id="clisyc-calendar" class="fc-initializing" data-hide-booked="<?php echo $clisyc_hide_booked ? 'true' : 'false'; ?>"></div>
                            <div id="clisyc_selected_slot_display" class="none">
                                <?php esc_html_e('Selected Slot:', 'client-sync'); ?> <span id="clisyc_selected_slot"></span>
                                
                                <?php if ( function_exists( 'clisyc_pro_is_license_active' ) && clisyc_pro_is_license_active() ) : ?>
                                <div class="clisyc-recurring-toggle-wrapper" style="margin-top: 10px; font-size: 0.9em;">
                                    <label>
                                        <input type="checkbox" id="clisyc-recurring-toggle">
                                        <?php esc_html_e( 'Book a recurring series?', 'client-sync' ); ?>
                                    </label>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div id="clisyc-recurring-options" class="clisyc-form-section" style="display:none; margin-top: 1em; padding: 10px; background: #f7f7f7; border-radius: 4px;">
                                <strong><?php esc_html_e( 'Repeat this booking:', 'client-sync' ); ?></strong>
                                <select name="_clisyc_recurring_frequency" id="clisyc-recurring-frequency" style="margin: 0 5px;">
                                    <option value="weekly"><?php esc_html_e( 'Weekly', 'client-sync' ); ?></option>
                                    <option value="biweekly"><?php esc_html_e( 'Every 2 Weeks', 'client-sync' ); ?></option>
                                </select>
                                <span><?php esc_html_e( 'for the next', 'client-sync' ); ?></span>
                                <input type="number" name="_clisyc_recurring_count" id="clisyc-recurring-count" value="4" min="2" max="52" style="width: 60px; margin: 0 5px;">
                                <span><?php esc_html_e( 'appointments.', 'client-sync' ); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="clisyc-form-section" id="clisyc-attendees-wrapper" style="display:none;">
                        <h3><?php esc_html_e('Number of Spots', 'client-sync'); ?></h3>
                        <div class="clisyc-form-field">
                            <label for="clisyc_attendees"><?php esc_html_e('Number of Attendees/Spots', 'client-sync'); ?></label>
                            <input type="number" id="clisyc_attendees" name="clisyc_attendees" value="1" min="1" step="1">
                        </div>
                    </div>

                    <div class="clisyc-form-section" id="clisyc-appointment-details-section-guest">
                        <h3><?php echo $show_filters ? '3.' : '2.'; ?> <?php esc_html_e('Your Details (New Account)', 'client-sync'); ?></h3>
                        <p class="clisyc-form-field">
                            <label for="clisyc_reg_first_name"><?php esc_html_e('First Name', 'client-sync'); ?> <span class="required">*</span></label>
                            <input type="text" name="clisyc_reg_first_name" id="clisyc_reg_first_name" value="<?php echo esc_attr($initial_form_values['clisyc_reg_first_name'] ?? ''); ?>" required>
                        </p>
                        <p class="clisyc-form-field">
                            <label for="clisyc_reg_last_name"><?php esc_html_e('Last Name', 'client-sync'); ?> <span class="required">*</span></label>
                            <input type="text" name="clisyc_reg_last_name" id="clisyc_reg_last_name" value="<?php echo esc_attr($initial_form_values['clisyc_reg_last_name'] ?? ''); ?>" required>
                        </p>
                        <p class="clisyc-form-field">
                            <label for="clisyc_reg_email"><?php esc_html_e('Email', 'client-sync'); ?> <span class="required">*</span></label>
                            <input type="email" name="clisyc_reg_email" id="clisyc_reg_email" value="<?php echo esc_attr($initial_form_values['clisyc_reg_email'] ?? ''); ?>" required>
                        </p>
                        <p class="clisyc-form-field">
                            <label for="clisyc_reg_password"><?php esc_html_e('Password', 'client-sync'); ?> <span class="required">*</span></label>
                            <input type="password" name="clisyc_reg_password" id="clisyc_reg_password" required>
                             <small><?php esc_html_e('Minimum 8 characters.', 'client-sync'); ?></small>
                        </p>
                    </div>

                    <!-- START: NEW SECTION FOR CREDIT REDEMPTION -->
                    <div id="clisyc-credit-redemption-section" class="clisyc-form-section" style="display:none; background: #e0f2fe; border-left: 3px solid #0ea5e9; padding: 15px;">
                        <h3 style="margin-top:0;"><?php esc_html_e( 'Use Package Credit', 'client-sync' ); ?></h3>
                        <p id="clisyc-credit-message"></p>
                        <input type="hidden" name="use_credit" id="clisyc-use-credit-flag" value="0">
                    </div>
                    <!-- END: NEW SECTION FOR CREDIT REDEMPTION -->

                    <!-- START: NEW Client-Side Validation Error Container -->
                    <div id="clisyc-client-side-validation-errors" class="clisyc-form-section clisyc-error-message" style="display:none;"></div>
                    <!-- END: NEW Client-Side Validation Error Container -->
                    <p style="margin-top: 1.5em;">
                        <button type="submit" id="schedule_button" class="clisyc-submit-button button button-primary" disabled><?php esc_html_e('Select Availability to Book', 'client-sync'); ?></button>
                    </p>
                </div> <!-- .clisyc-main-content-area -->
            </div> <!-- .clisyc-faceted-search-layout -->
        </form>
    </div>

<?php else: // User is logged in ?>

    <!-- LOGGED-IN USER FORM -->
    <div id="clisyc-appointment-calendar-container" class="clisyc-appointment-form">
        <form method="post" id="clisyc_appointment_form" action="<?php echo esc_url(get_permalink()); ?>" novalidate>
            <div class="clisyc-faceted-search-layout">
                <input type="hidden" name="_clisyc_frontend_booking_submit" value="1">
                <input type="hidden" name="booking_mode" value="slot"> <!-- Default value, JS will update -->
                <input type="hidden" name="start_date" value="">
                <input type="hidden" name="end_date" value="">
                <input type="hidden" name="appointment_date" id="appointment_date" required value="">
                <input type="hidden" name="time_slot" id="time_slot" required value="">
                <input type="hidden" name="appointment_duration_minutes" id="appointment_duration_minutes" required value="">

                <?php if ( ! $show_filters && $preselected_service_id ) : ?>
                    <input type="hidden" name="clisyc_service_id" id="service_id" value="<?php echo esc_attr($preselected_service_id); ?>">
                <?php else: ?>
                    <input type="hidden" name="clisyc_service_id" id="service_id" value="<?php echo esc_attr($initial_form_values['service_id'] ?? ''); ?>">
                <?php endif; ?>

                <?php wp_nonce_field( Constants::POST_TYPE_APPOINTMENT, 'clisyc_appointment_nonce' ); ?>

                <!-- START: NEW DEDICATED CONTAINER FOR HIDDEN INPUTS -->
                <div id="clisyc-hidden-inputs-container"></div>
                <!-- END: NEW DEDICATED CONTAINER -->

                <div style="position: absolute; left: -9999px;" aria-hidden="true">
                    <label for="contact_email_hp"><?php esc_html_e( 'Please leave this field blank.', 'client-sync' ); ?></label>
                    <input type="text" name="contact_email_hp" id="contact_email_hp" tabindex="-1" autocomplete="off">
                </div>
                <input type="hidden" name="clisyc_timestamp" value="<?php echo esc_attr( time() ); ?>">
                <input type="hidden" name="g-recaptcha-response" id="recaptcha_response">

                <div id="clisyc-availability-filters" class="clisyc-availability-filters-container clisyc-form-section clisyc-filter-layout-<?php echo esc_attr( $filter_layout ); ?>" <?php if ( !$show_filters ) echo 'style="display:none;"'; ?>>
                     <h3><?php esc_html_e('1. Select Your Options', 'client-sync'); ?></h3>
                     
                     <div class="clisyc-time-selection-wrapper">
                         <div class="clisyc-timezone-filter-wrapper clisyc-form-field">
                             <label for="clisyc-calendar-timezone-selector"><?php esc_html_e('Your Timezone:', 'client-sync'); ?></label>
                             <select id="clisyc-calendar-timezone-selector"></select>
                             <div id="clisyc-timezone-note" class="clisyc-timezone-note"></div>
                         </div>
                         <div class="clisyc-live-clock-wrapper">
                             <label><?php esc_html_e('Current Selected Time', 'client-sync'); ?></label>
                             <div id="clisyc-live-clock" class="clisyc-live-clock">--:--:-- --</div>
                         </div>
                     </div>

                     <div id="clisyc-filter-status" class="clisyc-filter-status-message"></div>
                </div>

                <div class="clisyc-main-content-area">
                    <div id="clisyc-service-details-container" class="clisyc-service-details clisyc-form-section" style="display:none;"></div>
                    
                    <!-- This container now holds BOTH booking UIs and will be hidden by default -->
                    <div id="clisyc-booking-interface-container" style="display: none;">
                        <!-- Date Range Picker UI -->
                        <div id="clisyc-date-range-picker-container" class="clisyc-form-section">
                            <h3><?php echo $show_filters ? '2.' : '1.'; ?> <?php esc_html_e('Choose Your Dates', 'client-sync'); ?></h3>
                            <div id="clisyc-inline-datepicker"><p><em><?php esc_html_e('Loading availability...', 'client-sync'); ?></em></p></div>
                            <div id="clisyc-date-range-summary" style="margin-top: 1em; padding: 10px; background: #f0f8ff; border: 1px solid #d1e9ff; border-radius: 4px; display:none;">
                                <p style="margin:0;">
                                    <strong><?php esc_html_e('Check-in:', 'client-sync'); ?></strong> <span id="clisyc-summary-start-date"></span><br>
                                    <strong><?php esc_html_e('Check-out:', 'client-sync'); ?></strong> <span id="clisyc-summary-end-date"></span><br>
                                    <strong><?php esc_html_e('Total Nights:', 'client-sync'); ?></strong> <span id="clisyc-summary-nights"></span>
                                </p>
                                <div id="clisyc-dynamic-price-container" class="clisyc-service-price" style="margin-top: 5px;"></div>
                            </div>
                        </div>

                        <!-- Time Slot Calendar UI -->
                        <div id="clisyc-calendar-and-form-fields" class="clisyc-form-section">
                            <h3><?php echo $show_filters ? '2.' : '1.'; ?> <?php esc_html_e('Choose Date & Time', 'client-sync'); ?></h3>
                            <div id="clisyc-calendar" class="fc-initializing" data-hide-booked="<?php echo $clisyc_hide_booked ? 'true' : 'false'; ?>"></div>
                            <!-- START: THIS ENTIRE BLOCK WAS MISSING/INCORRECT FOR LOGGED-IN USERS -->
                            <div id="clisyc_selected_slot_display" class="none">
                                <?php esc_html_e('Selected Slot:', 'client-sync'); ?> <span id="clisyc_selected_slot"><?php esc_html_e( 'None', 'client-sync' ); ?></span>
                                
                                <?php if ( function_exists( 'clisyc_pro_is_license_active' ) && clisyc_pro_is_license_active() ) : ?>
                                <div class="clisyc-recurring-toggle-wrapper" style="margin-top: 10px; font-size: 0.9em;">
                                    <label>
                                        <input type="checkbox" id="clisyc-recurring-toggle-loggedin">
                                        <?php esc_html_e( 'Book a recurring series?', 'client-sync' ); ?>
                                    </label>
                                </div>
                                <?php endif; ?>
                            </div>
                            <!-- END: BLOCK TO ADD -->

                            <div id="clisyc-recurring-options-loggedin" class="clisyc-form-section" style="display:none; margin-top: 1em; padding: 10px; background: #f7f7f7; border-radius: 4px;">
                                <strong><?php esc_html_e( 'Repeat this booking:', 'client-sync' ); ?></strong>
                                <select name="_clisyc_recurring_frequency" id="clisyc-recurring-frequency-loggedin" style="margin: 0 5px;">
                                    <option value="weekly"><?php esc_html_e( 'Weekly', 'client-sync' ); ?></option>
                                    <option value="biweekly"><?php esc_html_e( 'Every 2 Weeks', 'client-sync' ); ?></option>
                                </select>
                                <span><?php esc_html_e( 'for the next', 'client-sync' ); ?></span>
                                <input type="number" name="_clisyc_recurring_count" id="clisyc-recurring-count-loggedin" value="4" min="2" max="52" style="width: 60px; margin: 0 5px;">
                                <span><?php esc_html_e( 'appointments.', 'client-sync' ); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="clisyc-form-section" id="clisyc-attendees-wrapper" style="display:none;">
                        <h3><?php esc_html_e('Number of Spots', 'client-sync'); ?></h3>
                        <div class="clisyc-form-field">
                            <label for="clisyc_attendees"><?php esc_html_e('Number of Attendees/Spots', 'client-sync'); ?></label>
                            <input type="number" id="clisyc_attendees" name="clisyc_attendees" value="1" min="1" step="1">
                        </div>
                    </div>

                    <div class="clisyc-form-section" id="clisyc-appointment-details-section">
                        <h3><?php echo $show_filters ? '3.' : '2.'; ?> <?php esc_html_e('Appointment Details', 'client-sync'); ?></h3>
                        <?php if (!empty($appointment_field_order) && !empty($appointment_custom_fields)) : ?>
                            <?php
                            foreach ($appointment_field_order as $clisyc_field_key) {
                                if (!isset($appointment_custom_fields[$clisyc_field_key])) continue;

                                $clisyc_field = $appointment_custom_fields[$clisyc_field_key];
                                $clisyc_posted_value = $initial_form_values['clisyc_custom_field'][$clisyc_field_key] ?? null;
                                $form_renderer->render_frontend_custom_field($clisyc_field_key, $clisyc_field, $clisyc_posted_value, 'appointment');
                            }
                            ?>
                        <?php else: ?>
                            <p><?php esc_html_e('No additional details are required for this appointment.', 'client-sync'); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- START: NEW SECTION FOR CREDIT REDEMPTION -->
                    <div id="clisyc-credit-redemption-section" class="clisyc-form-section" style="display:none; background: #e0f2fe; border-left: 3px solid #0ea5e9; padding: 15px;">
                        <h3 style="margin-top:0;"><?php esc_html_e( 'Use Package Credit', 'client-sync' ); ?></h3>
                        <p id="clisyc-credit-message"></p>
                        <input type="hidden" name="use_credit" id="clisyc-use-credit-flag" value="0">
                    </div>
                    <!-- END: NEW SECTION FOR CREDIT REDEMPTION -->

                    <?php if ($payment_required) :
                        // Payment options rendering would go here.
                    endif; ?>

                    <!-- START: NEW Client-Side Validation Error Container -->
                    <div id="clisyc-client-side-validation-errors" class="clisyc-form-section clisyc-error-message" style="display:none;"></div>
                    <!-- END: NEW Client-Side Validation Error Container -->
                    <p style="margin-top: 1.5em;">
                        <button type="submit" id="schedule_button" class="clisyc-submit-button button button-primary" disabled>
                            <?php echo $payment_required ? esc_html__('Select Payment and Book', 'client-sync') : esc_html__('Schedule Appointment', 'client-sync'); ?>
                        </button>
                    </p>
                </div> <!-- .clisyc-main-content-area -->
            </div> <!-- .clisyc-faceted-search-layout -->
        </form>
    </div>
<?php endif; ?>