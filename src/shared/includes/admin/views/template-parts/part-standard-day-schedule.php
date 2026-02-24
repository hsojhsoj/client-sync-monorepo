<?php
/**
 * File: src/shared/includes/admin/views/template-parts/part-standard-day-schedule.php -> client-sync/includes/admin/views/template-parts/part-standard-day-schedule.php
 * Template part for the "Standard Day Schedule" tab.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views/Template-Parts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use DependentMedia\ClientSync\Constants;

// --- Data Fetching for View (REFACTORED with new prefixes) ---
$clisyc_availability_dimension_keys = get_option( Constants::OPTION_AVAILABILITY_DIM_FIELDS, [] );
$clisyc_appointment_custom_fields   = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
$clisyc_schedules_by_dimension      = get_option( 'clisyc_standard_schedules_by_dimension', [] );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading navigational URL parameters is safe here.
$clisyc_unslashed_get = wp_unslash( $_GET );
$clisyc_current_dimension_key   = isset( $clisyc_unslashed_get['dimension_key'] ) ? sanitize_key( $clisyc_unslashed_get['dimension_key'] ) : '';
$clisyc_current_dimension_value = isset( $clisyc_unslashed_get['dimension_value'] ) ? sanitize_text_field( $clisyc_unslashed_get['dimension_value'] ) : '';


$clisyc_selectable_dimensions = [];
if ( is_array( $clisyc_availability_dimension_keys ) && is_array( $clisyc_appointment_custom_fields ) ) {
    foreach ( $clisyc_availability_dimension_keys as $clisyc_key ) {
        if ( isset( $clisyc_appointment_custom_fields[ $clisyc_key ] ) && 
             isset( $clisyc_appointment_custom_fields[ $clisyc_key ]['type'] ) &&
             $clisyc_appointment_custom_fields[ $clisyc_key ]['type'] === 'select' && 
             ! empty( $clisyc_appointment_custom_fields[ $clisyc_key ]['options'] ) 
        ) {
            $clisyc_option_values_for_selectable = [];
            $clisyc_options_data_from_db = $clisyc_appointment_custom_fields[ $clisyc_key ]['options'];

            if (is_array($clisyc_options_data_from_db)) {
                foreach ($clisyc_options_data_from_db as $clisyc_option_item) {
                    if (isset($clisyc_option_item['value'])) {
                        $clisyc_option_values_for_selectable[] = trim($clisyc_option_item['value']);
                    }
                }
            } elseif (is_string($clisyc_options_data_from_db) && !empty($clisyc_options_data_from_db)) { 
                $clisyc_option_values_for_selectable = array_map( 'trim', explode( ',', $clisyc_options_data_from_db ) );
            }

            if (!empty($clisyc_option_values_for_selectable)) {
                $clisyc_selectable_dimensions[ $clisyc_key ] = [
                    'label'   => $clisyc_appointment_custom_fields[ $clisyc_key ]['label'] ?? $clisyc_key,
                    'options' => $clisyc_option_values_for_selectable,
                ];
            }
        }
    }
}

// --- Data Preparation for JavaScript ---
$clisyc_schedule_data_for_js = [];
if ( $clisyc_current_dimension_key && $clisyc_current_dimension_value !== '' && isset( $clisyc_schedules_by_dimension[ $clisyc_current_dimension_key ][ $clisyc_current_dimension_value ] ) ) {
    $clisyc_schedule_data_for_js = $clisyc_schedules_by_dimension[ $clisyc_current_dimension_key ][ $clisyc_current_dimension_value ];
}

$clisyc_schedule_data_for_js = wp_parse_args( $clisyc_schedule_data_for_js, [
    'workday_start' => get_option('clisyc_standard_schedule_calendar_start_time', '09:00'),
    'workday_end'   => get_option('clisyc_standard_schedule_calendar_end_time', '17:00'),
    'slot_duration' => 30,
    'break_start'   => '',
    'break_end'     => '',
    'slots'         => [],
    'buffer_before' => get_option( Constants::OPTION_GLOBAL_BUFFER_BEFORE, 0 ),
    'buffer_after'  => get_option( Constants::OPTION_GLOBAL_BUFFER_AFTER, 0 ),
]);

$clisyc_consistent_dummy_date_for_calendar = '1970-01-05'; // A Monday

$clisyc_calendar_events = [];
if ( ! empty( $clisyc_schedule_data_for_js['slots'] ) && is_array( $clisyc_schedule_data_for_js['slots'] ) ) {
    foreach ( $clisyc_schedule_data_for_js['slots'] as $clisyc_slot ) {
        if ( isset( $clisyc_slot['start'], $clisyc_slot['end'] ) && preg_match('/^\d{2}:\d{2}$/', $clisyc_slot['start']) && preg_match('/^\d{2}:\d{2}$/', $clisyc_slot['end']) ) {
            $clisyc_calendar_events[] = [
                'start'      => $clisyc_consistent_dummy_date_for_calendar . 'T' . $clisyc_slot['start'] . ':00',
                'end'        => $clisyc_consistent_dummy_date_for_calendar . 'T' . $clisyc_slot['end'] . ':00',
                'editable'   => true,
                'classNames' => [ 'clisyc-standard-slot' ],
            ];
        }
    }
}

if ( ! empty( $clisyc_schedule_data_for_js['break_start'] ) && ! empty( $clisyc_schedule_data_for_js['break_end'] ) &&
     preg_match('/^\d{2}:\d{2}$/', $clisyc_schedule_data_for_js['break_start']) &&
     preg_match('/^\d{2}:\d{2}$/', $clisyc_schedule_data_for_js['break_end']) &&
     $clisyc_schedule_data_for_js['break_start'] < $clisyc_schedule_data_for_js['break_end'] ) {
    $clisyc_calendar_events[] = [
        'id'          => 'break',
        'start'       => $clisyc_consistent_dummy_date_for_calendar . 'T' . $clisyc_schedule_data_for_js['break_start'] . ':00',
        'end'         => $clisyc_consistent_dummy_date_for_calendar . 'T' . $clisyc_schedule_data_for_js['break_end'] . ':00',
        'display'     => 'background',
        'color'       => '#e9ecef',
        'editable'    => false,
        'title'       => __('Break', 'client-sync'),
    ];
}

$clisyc_start_time_setting = get_option( 'clisyc_standard_schedule_calendar_start_time', '09:00' );
$clisyc_end_time_setting   = get_option( 'clisyc_standard_schedule_calendar_end_time', '17:00' );

if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $clisyc_start_time_setting ) ) {
    $clisyc_start_time_setting = '09:00';
}
if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $clisyc_end_time_setting ) && '24:00' !== $clisyc_end_time_setting ) {
    $clisyc_end_time_setting = '17:00';
}

$clisyc_slot_max_time_fc_std = ($clisyc_end_time_setting === '23:59' || $clisyc_end_time_setting === '24:00') ? '24:00:00' : $clisyc_end_time_setting . ':00';

wp_localize_script(
    'clisyc-admin-standard-schedule-fc', // Enqueued on 'time-slots' tab
    'clisycStandardScheduleData',
    [
        'ajax_url'              => admin_url( 'admin-ajax.php' ),
        'nonce'                 => wp_create_nonce( 'clisyc_save_standard_slots' ),
        'current_dimension_key'   => $clisyc_current_dimension_key,
        'current_dimension_value' => $clisyc_current_dimension_value,
        'calendarOptions'       => [
            'initialView'   => 'timeGridDay',
            'initialDate'   => $clisyc_consistent_dummy_date_for_calendar,
            'headerToolbar' => false,
            'allDaySlot'    => false,
            'slotMinTime'   => $clisyc_start_time_setting . ':00',
            'slotMaxTime'   => $clisyc_slot_max_time_fc_std,
            'events'        => $clisyc_calendar_events,
            'selectable'    => true,
            'selectMirror'  => true,
            'selectOverlap' => false,
            'height'        => 'auto',
        ],
        'locale'                => substr( get_locale(), 0, 2 ),
        'l10n'                  => [
            'addSlotTitle'        => __( 'New Available Slot', 'client-sync' ),
            'confirmDelete'       => __( 'Are you sure you want to delete this time slot?', 'client-sync' ),
            'saveSuccess'         => __( 'Standard schedule saved successfully.', 'client-sync' ),
            'saveError'           => __( 'Error saving schedule.', 'client-sync' ),
            'noChanges'           => __( 'No changes detected in visual slots or buffers.', 'client-sync' ),
            'saving'              => __( 'Saving...', 'client-sync' ),
            'selectDimensionValuePrompt' => __( 'Please select a dimension and value above to begin editing its schedule.', 'client-sync' ),
            'errorOverlapBreak'   => __( 'Cannot create a slot that overlaps with the defined break time.', 'client-sync' ),
            'visualEditorTitle'   => __( 'Standard Day Template', 'client-sync' ),
            'breakTimeDefaultTitle' => __('Break', 'client-sync'),
        ],
    ]
);
?>
<div class="clisyc-section postbox" style="margin-bottom: 20px;">
    <h2 class="hndle"><span><?php esc_html_e( 'Select Dimension & Value to Edit Schedule', 'client-sync' ); ?></span></h2>
    <div class="inside">
        <p><?php esc_html_e( 'Standard Day Schedules are defined per "Availability Filtering" dimension. First, select which dimension and which value you want to create or edit a schedule for.', 'client-sync' ); ?></p>
        <?php if ( ! empty( $clisyc_selectable_dimensions ) ) : ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="dimension_key_select"><?php esc_html_e( 'Dimension', 'client-sync' ); ?></label></th>
                    <td>
                        <select id="dimension_key_select">
                            <option value=""><?php esc_html_e( '-- Select Dimension --', 'client-sync' ); ?></option>
                            <?php foreach ( $clisyc_selectable_dimensions as $clisyc_key => $clisyc_data ) : ?>
                                <option value="<?php echo esc_attr( $clisyc_key ); ?>" <?php selected( $clisyc_current_dimension_key, $clisyc_key ); ?>>
                                    <?php echo esc_html( $clisyc_data['label'] ); ?> (<?php echo esc_html( $clisyc_key ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top" id="dimension_value_row" style="<?php echo empty( $clisyc_current_dimension_key ) ? 'display:none;' : ''; ?>">
                    <th scope="row"><label for="dimension_value_select"><?php esc_html_e( 'Value', 'client-sync' ); ?></label></th>
                    <td>
                        <select id="dimension_value_select">
                            <option value=""><?php esc_html_e( '-- Select Value --', 'client-sync' ); ?></option>
                            <?php
                            if ( ! empty( $clisyc_current_dimension_key ) && isset( $clisyc_selectable_dimensions[ $clisyc_current_dimension_key ]['options'] ) ) {
                                foreach ( $clisyc_selectable_dimensions[ $clisyc_current_dimension_key ]['options'] as $clisyc_option ) {
                                    echo '<option value="' . esc_attr( $clisyc_option ) . '" ' . selected( $clisyc_current_dimension_value, $clisyc_option, false ) . '>' . esc_html( $clisyc_option ) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </td>
                </tr>
            </table>

        <?php else : ?>
            <p><em><?php 
                /* translators: %s: URL to the appointment custom fields settings page. */
                $clisyc_no_fields_string = __( 'No filterable "Dropdown Select" custom fields found. Please <a href="%s">create a filterable appointment custom field</a> first to define standard schedules.', 'client-sync' );
                printf(
                    wp_kses(
                        $clisyc_no_fields_string,
                        [ 'a' => [ 'href' => [] ] ]
                    ),
                    esc_url( admin_url( 'admin.php?page=clisyc-custom-fields&cf_tab=appointments-cf' ) )
                ); 
            ?></em></p>
        <?php endif; ?>
    </div>
</div>

<div class="clisyc-section postbox">
    <h2 class="hndle">
        <span>
            <?php
            if ( $clisyc_current_dimension_key && $clisyc_current_dimension_value !== '' ) {
                $clisyc_dim_label = $clisyc_selectable_dimensions[ $clisyc_current_dimension_key ]['label'] ?? $clisyc_current_dimension_key;
                /* translators: 1: The label of the dimension (e.g., "Service Type"), 2: The value of the dimension (e.g., "Consulting"). */
                $clisyc_schedule_title_string = esc_html__( 'Visually Edit Schedule for %1$s: %2$s', 'client-sync' );
                printf(
                    wp_kses( $clisyc_schedule_title_string, [ 'strong' => [] ] ),
                    '<strong>' . esc_html( $clisyc_dim_label ) . '</strong>',
                    '<strong>' . esc_html( $clisyc_current_dimension_value ) . '</strong>'
                );
            } else {
                esc_html_e( 'Visual Schedule Editor', 'client-sync' );
            }
            ?>
        </span>
    </h2>
    <div class="inside">
        <p>
                <?php esc_html_e( 'Visually define a reusable template for a typical working day. This is not tied to a specific day of the week.', 'client-sync' ); ?>
                <br>
                <?php esc_html_e( 'Click and drag on the calendar to create new available slots. Drag or resize existing slots. Click the (×) to delete. This schedule will be used when you "Generate Slots" for this specific dimension/value.', 'client-sync' ); ?>
            </p>        
        <form id="clisyc-schedule-settings-form">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="clisyc_schedule_break_start"><?php esc_html_e( 'Define Break Time (Optional)', 'client-sync' ); ?></label></th>
                    <td>
                        <input type="time" id="clisyc_schedule_break_start" name="schedule_break_start" value="<?php echo esc_attr( $clisyc_schedule_data_for_js['break_start'] ); ?>" <?php disabled( empty( $clisyc_current_dimension_key ) || $clisyc_current_dimension_value === '' ); ?>>
                        <?php esc_html_e('to', 'client-sync'); ?>
                        <input type="time" id="clisyc_schedule_break_end" name="schedule_break_end" value="<?php echo esc_attr( $clisyc_schedule_data_for_js['break_end'] ); ?>" <?php disabled( empty( $clisyc_current_dimension_key ) || $clisyc_current_dimension_value === '' ); ?>>
                        <p class="description"><?php esc_html_e( 'Define a break during which no slots can be created. This break is specific to this schedule.', 'client-sync' ); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="clisyc_buffer_before_std_schedule"><?php esc_html_e( 'Appointment Buffers (Minutes)', 'client-sync' ); ?></label></th>
                    <td>
                        <label for="clisyc_buffer_before_std_schedule" style="margin-right:15px;"><?php esc_html_e( 'Before:', 'client-sync' ); ?>
                            <input type="number" step="1" min="0" id="clisyc_buffer_before_std_schedule" name="clisyc_buffer_before_std_schedule" value="<?php echo esc_attr( $clisyc_schedule_data_for_js['buffer_before'] ); ?>" class="small-text" <?php disabled( empty( $clisyc_current_dimension_key ) || $clisyc_current_dimension_value === '' ); ?>>
                        </label>
                        <label for="clisyc_buffer_after_std_schedule"><?php esc_html_e( 'After:', 'client-sync' ); ?>
                            <input type="number" step="1" min="0" id="clisyc_buffer_after_std_schedule" name="clisyc_buffer_after_std_schedule" value="<?php echo esc_attr( $clisyc_schedule_data_for_js['buffer_after'] ); ?>" class="small-text" <?php disabled( empty( $clisyc_current_dimension_key ) || $clisyc_current_dimension_value === '' ); ?>>
                        </label>
                        <p class="description"><?php esc_html_e( 'Set buffer times specific to this schedule. Overrides the global setting. These values are saved when you click "Save Visually Edited Schedule".', 'client-sync' ); ?></p>
                    </td>
                </tr>
            </table>
        </form>
        
        <div id="clisyc-standard-schedule-calendar-container" style="margin-top: 1em;">
            <div id="clisyc-standard-schedule-calendar">
                <p style="text-align:center; padding:50px;"><?php esc_html_e( 'Loading Calendar... Select a dimension and value above to start.', 'client-sync' ); ?></p>
            </div>
            <div style="margin-top: 10px; text-align: right;">
                <button type="button" id="save-visual-schedule" class="button button-primary" <?php disabled( empty( $clisyc_current_dimension_key ) || $clisyc_current_dimension_value === '' ); ?>>
                    <?php esc_html_e( 'Save Visually Edited Schedule & Buffers', 'client-sync' ); ?>
                </button>
                <span id="clisyc-schedule-save-spinner" class="spinner" style="float: none; vertical-align: middle;"></span>
            </div>
            <p id="clisyc-schedule-save-status" style="text-align: right; min-height: 22px; opacity: 0; transition: opacity 0.3s;"></p>
        </div>
        
        <?php if ( ! empty( $clisyc_schedule_data_for_js['break_start'] ) && ! empty( $clisyc_schedule_data_for_js['break_end'] ) && $clisyc_current_dimension_key && $clisyc_current_dimension_value !== '' ) : ?>
            <p><em><?php
                /* translators: 1: Break start time, 2: Break end time. */
                $clisyc_note_text = __( 'Note: The break time from %1$s to %2$s (greyed out area) is currently applied to this schedule.', 'client-sync' );
                echo esc_html(
                    sprintf(
                        $clisyc_note_text,
                        wp_date( get_option( 'time_format' ), strtotime( $clisyc_schedule_data_for_js['break_start'] ) ),
                        wp_date( get_option( 'time_format' ), strtotime( $clisyc_schedule_data_for_js['break_end'] ) )
                    )
                );
            ?></em></p>
        <?php endif; ?>
    </div>
</div>

<div class="clisyc-section postbox">
    <h2 class="hndle"><span><?php esc_html_e( 'Visual Editor Display Settings', 'client-sync' ); ?></span></h2>
    <div class="inside">
        <p><?php esc_html_e( 'Set the earliest and latest times shown on the visual schedule editor above. This only affects the editor, not the actual schedule times.', 'client-sync' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="clisyc_save_standard_schedule_times_action">
            <?php wp_nonce_field( 'clisyc_save_standard_schedule_times', 'clisyc_standard_schedule_times_nonce' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="clisyc_standard_schedule_calendar_start_time"><?php esc_html_e( 'Visible Start Time:', 'client-sync' ); ?></label></th>
                    <td><input type="time" id="clisyc_standard_schedule_calendar_start_time" name="clisyc_standard_schedule_calendar_start_time" value="<?php echo esc_attr( get_option( 'clisyc_standard_schedule_calendar_start_time', '09:00' ) ); ?>" required></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="clisyc_standard_schedule_calendar_end_time"><?php esc_html_e( 'Visible End Time:', 'client-sync' ); ?></label></th>
                    <td><input type="time" id="clisyc_standard_schedule_calendar_end_time" name="clisyc_standard_schedule_calendar_end_time" value="<?php echo esc_attr( get_option( 'clisyc_standard_schedule_calendar_end_time', '17:00' ) ); ?>" required></td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Display Times', 'client-sync' ) ); ?>
        </form>
    </div>
</div>