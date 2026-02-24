<?php
/**
 * File: src/shared/includes/admin/views/template-parts/part-appointment-custom-fields.php -> client-sync/includes/admin/views/template-parts/part-appointment-custom-fields.php
 * Template part for displaying the Appointment Custom Fields management interface.
 *
 * UPDATED: Added "Sensitive/PHI Data" checkbox for HIPAA compliance encryption.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views/Template-Parts
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use DependentMedia\ClientSync\Constants;

// Data is prepared in view-custom-fields-page.php and is available here.
// We get a list of all services for the conditional logic builder.
$clisyc_registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
$clisyc_primary_dim_slug = null;
if ( ! empty( $clisyc_registry['dimensions'] ) ) {
    foreach ( $clisyc_registry['dimensions'] as $clisyc_slug => $clisyc_settings ) {
        if ( ! empty( $clisyc_settings['primary'] ) ) {
            $clisyc_primary_dim_slug = $clisyc_slug;
            break;
        }
    }
}

$clisyc_all_services = [];
if ( $clisyc_primary_dim_slug && post_type_exists( $clisyc_primary_dim_slug ) ) {
    $clisyc_all_services = get_posts([
        'post_type'      => $clisyc_primary_dim_slug,
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);
}
?>

<!-- Form for Adding/Editing Booking Fields -->
<div class="postbox">
    <h2 class="hndle"><span><?php esc_html_e( 'Add/Edit Booking Field', 'client-sync' ); ?></span></h2>
    <div class="inside">
        
        <!-- MENTAL MODEL INSTRUCTION BLOCK -->
        <div class="clisyc-admin-instructions">
            <p><strong><?php esc_html_e( 'Concept: Transaction Data Collection', 'client-sync' ); ?></strong></p>
            <ul style="list-style: disc; margin-left: 20px; margin-top: 5px;">
                <li><strong><?php esc_html_e( 'Storage:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Stored in the appointment itself (postmeta).', 'client-sync' ); ?></li>
                <li><strong><?php esc_html_e( 'Context:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Dynamic. This information is specific to *this specific* appointment.', 'client-sync' ); ?></li>
                <li><strong><?php esc_html_e( 'Direction:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Customer -> Admin. (The customer inputs this data)', 'client-sync' ); ?></li>
                <li><strong><?php esc_html_e( 'Use Case:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Use this to ask the customer questions. Examples: "Pet\'s Name", "Gate Code", "Reason for Visit".', 'client-sync' ); ?></li>
            </ul>
        </div>

        <p><?php esc_html_e( 'Define custom fields for individual bookings. Use Conditional Logic to show fields only for specific services.', 'client-sync' ); ?></p>
        
        <form method="post" id="clisyc-appointment-custom-field-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'clisyc_save_appointment_custom_field_action', 'clisyc_appointment_custom_field_nonce' ); ?>
            <input type="hidden" name="action" value="clisyc_save_appointment_custom_field">
            <input type="hidden" name="clisyc_sub_action" id="clisyc_appt_sub_action" value="add_field">
            <input type="hidden" name="edit_field_key" id="clisyc_appt_edit_field_key" value="">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="clisyc_appt_field_key_input"><?php esc_html_e('Field Key', 'client-sync'); ?></label></th>
                        <td>
                            <input name="field_key" type="text" id="clisyc_appt_field_key_input" value="" class="regular-text" required pattern="[a-z0-9_]+" title="<?php esc_attr_e('Lowercase letters, numbers, and underscores only.', 'client-sync'); ?>">
                            <p class="description"><?php esc_html_e('Unique identifier. Cannot be changed once created.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="clisyc_appt_field_label_input"><?php esc_html_e('Field Label', 'client-sync'); ?></label></th>
                        <td>
                            <input name="field_label" type="text" id="clisyc_appt_field_label_input" value="" class="regular-text" required>
                            <p class="description"><?php esc_html_e('Label shown on the booking form and appointment details.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="clisyc_appt_field_type_select"><?php esc_html_e('Field Type', 'client-sync'); ?></label></th>
                        <td>
                            <select name="field_type" id="clisyc_appt_field_type_select">
                                <option value="text"><?php esc_html_e('Text', 'client-sync'); ?></option>
                                <option value="number"><?php esc_html_e('Number', 'client-sync'); ?></option> <!-- ADDED -->
                                <option value="textarea"><?php esc_html_e('Textarea', 'client-sync'); ?></option>
                                <option value="select"><?php esc_html_e('Dropdown Select', 'client-sync'); ?></option>
                                <option value="multi_checkbox"><?php esc_html_e('Checkboxes (Allow Multiple)', 'client-sync'); ?></option>
                                <option value="radio"><?php esc_html_e('Radio Buttons (Select One)', 'client-sync'); ?></option>
                                <option value="custom_html"><?php esc_html_e('Custom HTML (Display Only)', 'client-sync'); ?></option>
                                
                                <?php if ( function_exists( 'clisyc_pro_is_license_active' ) && clisyc_pro_is_license_active() ) : ?>
                                    <option value="image_map"><?php esc_html_e('Image Map (Clickable)', 'client-sync'); ?></option>
                                <?php else : ?>
                                    <option value="image_map" disabled><?php esc_html_e('Image Map (Pro Feature)', 'client-sync'); ?></option>
                                <?php endif; ?>
                
                                <option value="readonly_filter_display"><?php esc_html_e('Read-Only Filter Display', 'client-sync'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="clisyc-options-row" style="display:none;">
                        <th scope="row"><label><?php esc_html_e('Field Options', 'client-sync'); ?></label></th>
                        <td>
                            <div class="clisyc-options-list-editor">
                                <ul class="clisyc-options-sortable-list"></ul>
                                <button type="button" class="button clisyc-add-option-button"><?php esc_html_e('Add Option', 'client-sync'); ?></button>
                                <input type="hidden" name="field_options" class="clisyc-field-options-hidden-input" value="">
                                <p class="description"><?php esc_html_e('Add, remove, or drag to reorder options. Click the color swatch to choose a color for calendar events (if applicable).', 'client-sync'); ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr class="clisyc-custom-html-row" style="display:none;">
                        <th scope="row"><label for="clisyc_appt_field_custom_html_editor"><?php esc_html_e('HTML Content', 'client-sync'); ?></label></th>
                        <td>
                            <?php
                            wp_editor(
                                '',
                                'clisyc_appt_field_custom_html_editor',
                                [
                                    'textarea_name' => 'field_custom_html',
                                    'textarea_rows' => 7,
                                    'media_buttons' => false,
                                    'tinymce'       => [ 'autoresize_on_init' => false ],
                                    'quicktags'     => true,
                                ]
                            );
                            ?>
                            <p class="description"><?php esc_html_e('Enter the HTML to be displayed on the booking form. Basic HTML tags are allowed.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr class="clisyc-image-map-row" style="display:none;">
                        <th scope="row"><label for="clisyc_appt_field_image_id_input"><?php esc_html_e('Background Image', 'client-sync'); ?></label></th>
                        <td>
                            <div class="clisyc-image-preview-wrapper" style="margin-bottom: 10px;"><img src="" alt="<?php esc_attr_e('Image Preview', 'client-sync'); ?>" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; display: none;"></div>
                            <input type="hidden" name="field_image_id" id="clisyc_appt_field_image_id_input" value="0" class="clisyc-image-id-input">
                            <button type="button" class="button clisyc-upload-image-button"><?php esc_html_e('Select Image', 'client-sync'); ?></button>
                            <button type="button" class="button clisyc-remove-image-button" style="display:none; margin-left: 5px; vertical-align: bottom;"><?php esc_html_e('Remove Image', 'client-sync'); ?></button>
                            <p class="description"><?php esc_html_e('Select the background image for users to click on.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                     <tr class="clisyc-layout-row" style="display:none;">
                        <th scope="row"><?php esc_html_e('Option Layout', 'client-sync'); ?></th>
                        <td>
                            <fieldset>
                                <label style="margin-right: 15px;"><input type="radio" name="field_layout" value="vertical" checked="checked"> <?php esc_html_e('Vertical', 'client-sync'); ?></label>
                                <label><input type="radio" name="field_layout" value="horizontal"> <?php esc_html_e('Horizontal', 'client-sync'); ?></label>
                            </fieldset>
                            <p class="description"><?php esc_html_e('Choose how checkbox or radio options are displayed.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Required', 'client-sync'); ?></th>
                        <td>
                            <label><input type="checkbox" name="field_required" id="clisyc_appt_field_required_checkbox" value="1"> <?php esc_html_e('Make this field mandatory?', 'client-sync'); ?></label>
                            <p class="description"><?php esc_html_e('If checked, users must fill this field during booking. (Not applicable for Custom HTML or Image Map types)', 'client-sync'); ?></p>
                        </td>
                    </tr>
                     <tr>
                        <th scope="row"><?php esc_html_e('Track Progress', 'client-sync'); ?></th>
                        <td>
                            <label><input type="checkbox" name="field_track_progress" value="1"> <?php esc_html_e('Track this field\'s value for client progress charts?', 'client-sync'); ?></label>
                            <p class="description"><?php esc_html_e('Only applies to Text (numeric values), Number, and Dropdown Select fields.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    
                    <!-- HIPAA Compliance: Sensitive Data Flag -->
                    <tr class="clisyc-sensitive-row">
                        <th scope="row"><?php esc_html_e('Sensitive Data', 'client-sync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="field_sensitive" id="clisyc_appt_field_sensitive_checkbox" value="1">
                                <?php esc_html_e('Contains sensitive/PHI data (encrypt when HIPAA mode is active)', 'client-sync'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('If checked, this field\'s data will be encrypted at rest when HIPAA mode is enabled. Use for health information, notes, or other Protected Health Information (PHI).', 'client-sync'); ?>
                            </p>
                            <?php if ( function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) && \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode() ) : ?>
                                <p class="description" style="color: #0073aa; margin-top: 5px;">
                                    <span class="dashicons dashicons-shield" style="font-size: 14px; vertical-align: text-bottom;"></span>
                                    <?php esc_html_e('HIPAA Mode is currently active. Sensitive fields will be encrypted.', 'client-sync'); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <!-- Conditional Logic Section -->
                    <tr>
                        <th scope="row"><?php esc_html_e('Conditional Logic', 'client-sync'); ?></th>
                        <td>
                            <label for="clisyc_appt_field_enable_conditional_logic">
                                <input type="checkbox" name="conditional_logic_enabled" id="clisyc_appt_field_enable_conditional_logic" value="1">
                                <?php esc_html_e('Enable Conditional Logic', 'client-sync'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Show or hide this field based on the selected Service.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div id="clisyc-conditional-logic-builder" style="display:none; margin-top: 1em; padding-left: 20px; border-left: 3px solid #7e8993;">
                 <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <?php esc_html_e('Show this field if', 'client-sync'); ?>
                                <select name="conditional_logic[condition]">
                                    <option value="all"><?php esc_html_e( 'all', 'client-sync' ); ?></option>
                                    <option value="any"><?php esc_html_e( 'any', 'client-sync' ); ?></option>
                                </select>
                                <?php esc_html_e('of the following match:', 'client-sync'); ?>
                            </th>
                            <td>
                                <div class="clisyc-logic-rules-container">
                                    <!-- Rules will be added here by JS -->
                                </div>
                                <button type="button" class="button clisyc-add-rule-button" style="margin-top:10px;"><?php esc_html_e('Add Rule', 'client-sync'); ?></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <p class="submit">
                <button type="submit" id="clisyc-appt-submit-button" class="button button-primary"><?php esc_html_e( 'Add Custom Field', 'client-sync' ); ?></button>
                <button type="button" id="clisyc-appt-cancel-edit-button" class="button button-secondary" style="display:none; margin-left: 10px;"><?php esc_html_e( 'Cancel Edit', 'client-sync' ); ?></button>
            </p>
        </form>
    </div>
</div>


<!-- Table of Existing Booking Fields -->
<div class="postbox">
    <h2 class="hndle"><span><?php esc_html_e( 'Existing Booking Fields', 'client-sync' ); ?></span></h2>
    <div class="inside">
        <p><em><?php esc_html_e( 'Drag and drop rows to reorder the fields.', 'client-sync' ); ?></em></p>
        <table class="wp-list-table widefat fixed striped custom-fields">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-drag-handle" style="width: 40px;"></th>
                    <th scope="col" class="manage-column"><?php esc_html_e( 'Field Key', 'client-sync' ); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e( 'Label', 'client-sync' ); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e( 'Type', 'client-sync' ); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e( 'Sensitive', 'client-sync' ); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e( 'Conditional', 'client-sync' ); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e( 'Actions', 'client-sync' ); ?></th>
                </tr>
            </thead>
            <tbody id="clisyc-appointment-fields-list" class="clisyc-sortable-fields" data-field-context="appointment">
                <?php
                if ( ! empty( $appointment_field_order ) && is_array( $appointment_custom_fields ) ) {
                    foreach ( $appointment_field_order as $clisyc_key ) {
                        if ( ! isset( $appointment_custom_fields[ $clisyc_key ] ) ) continue;
                        $clisyc_field = $appointment_custom_fields[ $clisyc_key ];
                        
                        $clisyc_options_json = isset($clisyc_field['options']) && is_array($clisyc_field['options']) ? wp_json_encode($clisyc_field['options']) : '[]';
                        $clisyc_conditional_logic_json = isset($clisyc_field['conditional_logic']) && is_array($clisyc_field['conditional_logic']) ? wp_json_encode($clisyc_field['conditional_logic']) : '{}';
                        $clisyc_html_content = $clisyc_field['html_content'] ?? '';
                        $clisyc_image_id = $clisyc_field['image_id'] ?? 0;
                        $clisyc_image_url = $clisyc_image_id ? wp_get_attachment_image_url($clisyc_image_id, 'medium') : '';
                        $clisyc_layout = $clisyc_field['layout'] ?? 'vertical';
                        $clisyc_sensitive = ! empty( $clisyc_field['sensitive'] ) ? '1' : '0';
                        ?>
                        <tr id="appt-field-<?php echo esc_attr( $clisyc_key ); ?>" data-field-key="<?php echo esc_attr( $clisyc_key ); ?>">
                            <td class="column-drag-handle"><span class="dashicons dashicons-menu"></span></td>
                            <td><strong><?php echo esc_html( $clisyc_key ); ?></strong></td>
                            <td><?php echo esc_html( $clisyc_field['label'] ?? '' ); ?></td>
                            <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $clisyc_field['type'] ?? 'text' ) ) ); ?></td>
                            <td>
                                <?php if ( ! empty( $clisyc_field['sensitive'] ) ) : ?>
                                    <span class="dashicons dashicons-shield" style="color: #0073aa;" title="<?php esc_attr_e( 'Encrypted when HIPAA mode is active', 'client-sync' ); ?>"></span>
                                    <?php esc_html_e( 'Yes', 'client-sync' ); ?>
                                <?php else : ?>
                                    <?php esc_html_e( 'No', 'client-sync' ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ! empty( $clisyc_field['conditional_logic']['enabled'] ) ? esc_html__( 'Yes', 'client-sync' ) : esc_html__( 'No', 'client-sync' ); ?></td>
                            <td>
                                <div class="row-actions">
                                    <span class="edit">
                                        <button type="button" class="button-link clisyc-edit-field" 
                                            data-key="<?php echo esc_attr( $clisyc_key ); ?>"
                                            data-label="<?php echo esc_attr( $clisyc_field['label'] ?? '' ); ?>"
                                            data-type="<?php echo esc_attr( $clisyc_field['type'] ?? 'text' ); ?>"
                                            data-options="<?php echo esc_attr( $clisyc_options_json ); ?>"
                                            data-html-content="<?php echo esc_attr( $clisyc_html_content ); ?>"
                                            data-image-id="<?php echo esc_attr( $clisyc_image_id ); ?>"
                                            data-image-url="<?php echo esc_url( $clisyc_image_url ); ?>"
                                            data-layout="<?php echo esc_attr( $clisyc_layout ); ?>"
                                            data-required="<?php echo ! empty( $clisyc_field['required'] ) ? '1' : '0'; ?>"
                                            data-track-progress="<?php echo ! empty( $clisyc_field['track_progress'] ) ? '1' : '0'; ?>"
                                            data-sensitive="<?php echo esc_attr( $clisyc_sensitive ); ?>"
                                            data-conditional-logic="<?php echo esc_attr( $clisyc_conditional_logic_json ); ?>"
                                        >
                                            <?php esc_html_e( 'Edit', 'client-sync' ); ?>
                                        </button> | 
                                    </span>
                                    <span class="delete">
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                            <?php wp_nonce_field( 'clisyc_save_appointment_custom_field_action', 'clisyc_appointment_custom_field_nonce' ); ?>
                                            <input type="hidden" name="action" value="clisyc_save_appointment_custom_field">
                                            <input type="hidden" name="clisyc_sub_action" value="delete_field">
                                            <input type="hidden" name="delete_field_key" value="<?php echo esc_attr( $clisyc_key ); ?>">
                                            <button type="submit" class="button-link delete-tag" onclick="return confirm('<?php esc_attr_e( 'Delete this field permanently?', 'client-sync' ); ?>');"><?php esc_html_e( 'Delete', 'client-sync' ); ?></button>
                                        </form>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php }
                } else { ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No appointment custom fields defined yet.', 'client-sync' ); ?></td></tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>