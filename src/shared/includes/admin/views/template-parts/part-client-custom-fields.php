<?php
/**
 * File: src/shared/includes/admin/views/template-parts/part-client-custom-fields.php -> client-sync/includes/admin/views/template-parts/part-client-custom-fields.php
 * Template part for displaying the Client Custom Fields management interface.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views/Template-Parts
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<!-- Form for Adding/Editing Client Fields -->
<div class="postbox">
    <h2 class="hndle"><span><?php esc_html_e( 'Add/Edit Client Custom Field', 'client-sync' ); ?></span></h2>
    <div class="inside">
        
        <!-- MENTAL MODEL INSTRUCTION BLOCK -->
        <div class="clisyc-admin-instructions">
            <p><strong><?php esc_html_e( 'Concept: User Profile Data', 'client-sync' ); ?></strong></p>
            <ul style="list-style: disc; margin-left: 20px; margin-top: 5px;">
                <li><strong><?php esc_html_e( 'Storage:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Stored permanently in the user\'s profile (usermeta).', 'client-sync' ); ?></li>
                <li><strong><?php esc_html_e( 'Context:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Static. This data belongs to the person, regardless of how many appointments they book.', 'client-sync' ); ?></li>
                <li><strong><?php esc_html_e( 'Use Case:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Use this for "Who are you?" questions. Examples: Phone Number, Age, Mailing Address.', 'client-sync' ); ?></li>
            </ul>
        </div>

        <p><?php esc_html_e( 'Define custom fields to store extra information about your clients (users). These fields will appear on the user profile page and the registration form shortcode.', 'client-sync' ); ?></p>
        
        <form method="post" id="clisyc-client-custom-field-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'clisyc_save_client_custom_field_action', 'clisyc_custom_field_nonce' ); ?>
            <input type="hidden" name="action" value="clisyc_save_client_custom_field">
            <input type="hidden" name="clisyc_sub_action" id="clisyc_client_sub_action" value="add_field">
            <input type="hidden" name="edit_field_key" id="clisyc_client_edit_field_key" value="">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="clisyc_client_field_key_input"><?php esc_html_e('Field Key', 'client-sync'); ?></label></th>
                        <td>
                            <input name="field_key" type="text" id="clisyc_client_field_key_input" value="" class="regular-text" required pattern="[a-z0-9_]+" title="<?php esc_attr_e('Lowercase letters, numbers, and underscores only.', 'client-sync'); ?>">
                            <p class="description"><?php esc_html_e('Unique identifier (meta key). Cannot be changed once created.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="clisyc_client_field_label_input"><?php esc_html_e('Field Label', 'client-sync'); ?></label></th>
                        <td>
                            <input name="field_label" type="text" id="clisyc_client_field_label_input" value="" class="regular-text" required>
                            <p class="description"><?php esc_html_e('Label shown on the user profile and registration forms.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="clisyc_client_field_type_select"><?php esc_html_e('Field Type', 'client-sync'); ?></label></th>
                        <td>
                            <select name="field_type" id="clisyc_client_field_type_select">
                                <option value="text"><?php esc_html_e('Text', 'client-sync'); ?></option>
                                <option value="number"><?php esc_html_e('Number', 'client-sync'); ?></option> <!-- ADDED -->
                                <option value="textarea"><?php esc_html_e('Textarea', 'client-sync'); ?></option>
                                <option value="phone"><?php esc_html_e('Phone Number', 'client-sync'); ?></option>
                                <option value="select"><?php esc_html_e('Dropdown Select', 'client-sync'); ?></option>
                                <option value="multi_checkbox"><?php esc_html_e('Checkboxes (Allow Multiple)', 'client-sync'); ?></option>
                                <option value="radio"><?php esc_html_e('Radio Buttons (Select One)', 'client-sync'); ?></option>
                                <option value="custom_html"><?php esc_html_e('Custom HTML (Display Only)', 'client-sync'); ?></option>
                                <option value="image_map"><?php esc_html_e('Image Map (Clickable)', 'client-sync'); ?></option>
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
                        <th scope="row"><label for="clisyc_client_field_custom_html_editor"><?php esc_html_e('HTML Content', 'client-sync'); ?></label></th>
                        <td>
                            <?php
                            wp_editor(
                                '',
                                'clisyc_client_field_custom_html_editor',
                                [
                                    'textarea_name' => 'field_custom_html',
                                    'textarea_rows' => 7,
                                    'media_buttons' => false,
                                    'tinymce'       => [ 'autoresize_on_init' => false ],
                                    'quicktags'     => true,
                                ]
                            );
                            ?>
                            <p class="description"><?php esc_html_e('Enter the HTML to be displayed on the profile/registration form. Basic HTML tags are allowed.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr class="clisyc-image-map-row" style="display:none;">
                        <th scope="row"><label for="clisyc_client_field_image_id_input"><?php esc_html_e('Background Image', 'client-sync'); ?></label></th>
                        <td>
                            <div class="clisyc-image-preview-wrapper" style="margin-bottom: 10px;"><img src="" alt="<?php esc_attr_e('Image Preview', 'client-sync'); ?>" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; display: none;"></div>
                            <input type="hidden" name="field_image_id" id="clisyc_client_field_image_id_input" value="0" class="clisyc-image-id-input">
                            <button type="button" class="button clisyc-upload-image-button"><?php esc_html_e('Select Image', 'client-sync'); ?></button>
                            <button type="button" class="button clisyc-remove-image-button" style="display:none; margin-left: 5px; vertical-align: bottom;"><?php esc_html_e('Remove Image', 'client-sync'); ?></button>
                            <p class="description"><?php esc_html_e('Select the background image for users to click on.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr class="clisyc-layout-row" style="display:none;">
                        <th scope="row"><?php esc_html_e('Option Layout', 'client-sync'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span><?php esc_html_e('Option Layout', 'client-sync'); ?></span></legend>
                                <label style="margin-right: 15px;"><input type="radio" name="field_layout" value="vertical" checked="checked"> <?php esc_html_e('Vertical', 'client-sync'); ?></label>
                                <label><input type="radio" name="field_layout" value="horizontal"> <?php esc_html_e('Horizontal', 'client-sync'); ?></label>
                            </fieldset>
                            <p class="description"><?php esc_html_e('Choose how checkbox or radio options are displayed.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Required', 'client-sync'); ?></th>
                        <td>
                            <label for="clisyc_client_field_required_checkbox">
                                <input type="checkbox" name="field_required" id="clisyc_client_field_required_checkbox" value="1">
                                <?php esc_html_e('Make this field mandatory?', 'client-sync'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('If checked, users must fill this field during registration and profile updates. (Not applicable for Custom HTML or Image Map types)', 'client-sync'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <p class="submit">
                <button type="submit" id="clisyc-client-submit-button" class="button button-primary"><?php esc_html_e( 'Add Custom Field', 'client-sync' ); ?></button>
                <button type="button" id="clisyc-client-cancel-edit-button" class="button button-secondary" style="display:none; margin-left: 10px;"><?php esc_html_e( 'Cancel Edit', 'client-sync' ); ?></button>
            </p>
        </form>
    </div>
</div>

<!-- Table of Existing Client Fields -->
<div class="postbox">
    <h2 class="hndle"><span><?php esc_html_e( 'Existing Client Custom Fields', 'client-sync' ); ?></span></h2>
    <div class="inside">
        <p><em><?php esc_html_e( 'Drag and drop rows to reorder the fields.', 'client-sync' ); ?></em></p>
        <?php if ( ! empty( $client_field_order ) && is_array( $client_custom_fields ) ) : ?>
            <table class="wp-list-table widefat fixed striped custom-fields">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column column-drag-handle" style="width: 40px;"> </th>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Field Key', 'client-sync' ); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Label', 'client-sync' ); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Type', 'client-sync' ); ?></th>
                        <th scope="col" class="manage-column" style="width: 70px;"><?php esc_html_e( 'Required', 'client-sync' ); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Actions', 'client-sync' ); ?></th>
                    </tr>
                </thead>
                <tbody id="clisyc-client-fields-list" class="clisyc-sortable-fields" data-field-context="client">
                    <?php
                    // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables, not true globals.
                    foreach ( $client_field_order as $clisyc_key ) :
                        if ( ! isset( $client_custom_fields[ $clisyc_key ] ) ) continue;
                        $clisyc_field = $client_custom_fields[ $clisyc_key ];
                        
                        $clisyc_options_json = isset($clisyc_field['options']) && is_array($clisyc_field['options']) ? wp_json_encode($clisyc_field['options']) : '[]';
                        $clisyc_html_content = $clisyc_field['html_content'] ?? '';
                        $clisyc_image_id = $clisyc_field['image_id'] ?? 0;
                        $clisyc_image_url = $clisyc_image_id ? wp_get_attachment_image_url($clisyc_image_id, 'medium') : '';
                        $clisyc_layout = $clisyc_field['layout'] ?? 'vertical';
                    // phpcs:enable
                        ?>
                        <tr id="client-field-<?php echo esc_attr( $clisyc_key ); ?>" data-field-key="<?php echo esc_attr( $clisyc_key ); ?>">
                            <td class="column-drag-handle"><span class="dashicons dashicons-menu"></span></td>
                            <td><strong><?php echo esc_html( $clisyc_key ); ?></strong></td>
                            <td><?php echo esc_html( $clisyc_field['label'] ?? '' ); ?></td>
                            <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $clisyc_field['type'] ?? 'text' ) ) ); ?></td>
                            <td><?php echo ! empty( $clisyc_field['required'] ) ? esc_html__( 'Yes', 'client-sync' ) : esc_html__( 'No', 'client-sync' ); ?></td>
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
                                        >
                                            <?php esc_html_e( 'Edit', 'client-sync' ); ?>
                                        </button> | 
                                    </span>
                                    <span class="delete">
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                            <?php wp_nonce_field( 'clisyc_save_client_custom_field_action', 'clisyc_custom_field_nonce' ); ?>
                                            <input type="hidden" name="action" value="clisyc_save_client_custom_field">
                                            <input type="hidden" name="clisyc_sub_action" value="delete_field">
                                            <input type="hidden" name="delete_field_key" value="<?php echo esc_attr( $clisyc_key ); ?>">
                                            <button type="submit" class="button-link delete-tag" onclick="return confirm('<?php esc_attr_e( 'Delete this field permanently? This will NOT delete existing user data for this field.', 'client-sync' ); ?>');"><?php esc_html_e( 'Delete', 'client-sync' ); ?></button>
                                        </form>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><?php esc_html_e( 'No client custom fields defined yet.', 'client-sync' ); ?></p>
        <?php endif; ?>
    </div>
</div>

<?php
// Client Fields List Table Column Display Settings
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variable.
$clisyc_client_list_column_field = get_option('clisyc_client_list_custom_field_column', '');
?>
<div class="postbox">
    <h2 class="hndle"><span><?php esc_html_e('List Table Column Display', 'client-sync'); ?></span></h2>
    <div class="inside">
        <p><?php esc_html_e('Choose which client custom field appears as a column on the main WordPress Users list.', 'client-sync'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('clisyc_save_client_custom_field_action', 'clisyc_custom_field_nonce'); ?>
            <input type="hidden" name="action" value="clisyc_save_client_custom_field">
            <input type="hidden" name="clisyc_sub_action" value="save_list_table_settings">

            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="clisyc_client_list_column_field"><?php esc_html_e('Users List Column', 'client-sync'); ?></label></th>
                    <td>
                        <select name="client_list_custom_field_column" id="clisyc_client_list_column_field">
                            <option value="" <?php selected($clisyc_client_list_column_field, ''); ?>><?php esc_html_e('-- None --', 'client-sync'); ?></option>
                            <?php if (!empty($client_custom_fields)) : ?>
                                <?php
                                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local foreach variables.
                                foreach ($client_custom_fields as $clisyc_key => $clisyc_field) :
                                    if (in_array($clisyc_field['type'], ['text', 'number', 'select', 'radio'], true)) : // ADDED 'number'
                                ?>
                                    <option value="<?php echo esc_attr($clisyc_key); ?>" <?php selected($clisyc_client_list_column_field, $clisyc_key); ?>>
                                        <?php echo esc_html($clisyc_field['label'] . ' (' . $clisyc_key . ')'); ?>
                                    </option>
                                <?php endif; endforeach; ?>
                            <?php else : ?>
                                <option value="" disabled><?php esc_html_e('No suitable custom fields defined', 'client-sync'); ?></option>
                            <?php endif; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Select one field to display as a custom column on the main WordPress Users list table.', 'client-sync'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save User List Setting', 'client-sync')); ?>
        </form>
    </div>
</div>