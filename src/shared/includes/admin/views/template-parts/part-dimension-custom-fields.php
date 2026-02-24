<?php
/**
 * File: src/shared/includes/admin/views/template-parts/part-dimension-custom-fields.php -> client-sync/includes/admin/views/template-parts/part-dimension-custom-fields.php
 * Template part for the Dimension Custom Attributes management interface.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views/Template-Parts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use DependentMedia\ClientSync\Constants;

// Get all enabled dimension CPTs to list in the "Applies To" section
$clisyc_registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
$clisyc_enabled_dimensions = array_filter( $clisyc_registry['dimensions'] ?? [], fn($dim) => !empty($dim['enabled']) );
$clisyc_dimension_cpts = [];
foreach ( array_keys($clisyc_enabled_dimensions) as $clisyc_slug ) {
    $clisyc_cpt_obj = get_post_type_object($clisyc_slug);
    if ($clisyc_cpt_obj) {
        $clisyc_dimension_cpts[$clisyc_slug] = $clisyc_cpt_obj->labels->name;
    }
}
asort($clisyc_dimension_cpts);

// Data for the table of existing fields
$clisyc_dimension_fields = get_option( Constants::OPTION_DIMENSION_FIELDS, [] );
?>

<!-- Form for Adding/Editing Dimension Attributes -->
<div class="postbox">
    <h2 class="hndle"><span><?php esc_html_e( 'Add/Edit Dimension Attribute', 'client-sync' ); ?></span></h2>
    <div class="inside">
        
        <!-- MENTAL MODEL INSTRUCTION BLOCK -->
        <div class="clisyc-admin-instructions">
            <p><strong><?php esc_html_e( 'Concept: Resource Metadata', 'client-sync' ); ?></strong></p>
            <ul style="list-style: disc; margin-left: 20px; margin-top: 5px;">
                <li><strong><?php esc_html_e( 'Storage:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Stored in the dimension item post (postmeta).', 'client-sync' ); ?></li>
                <li><strong><?php esc_html_e( 'Context:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Static. This describes the item itself (e.g., the Room or the Boat), not the person booking it.', 'client-sync' ); ?></li>
                <li><strong><?php esc_html_e( 'Direction:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Admin -> Customer. You input this data when creating your items.', 'client-sync' ); ?></li>
                <li><strong><?php esc_html_e( 'Use Case:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Use this for search filters. Examples: "Number of Bedrooms", "Max Speed", "Wheelchair Accessible".', 'client-sync' ); ?></li>
            </ul>
        </div>

        <p><?php esc_html_e( 'Define attributes (e.g., Bedrooms, Manufacturer) that can be attached to your dimension items and used as filters in the availability search.', 'client-sync' ); ?></p>
        
        <form method="post" id="clisyc-dimension-custom-field-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'clisyc_save_dimension_custom_field_action', 'clisyc_dimension_custom_field_nonce' ); ?>
            <input type="hidden" name="action" value="clisyc_save_dimension_custom_field">
            <input type="hidden" name="clisyc_sub_action" value="add_field">
            <input type="hidden" name="edit_field_key" value="">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="dim_field_key"><?php esc_html_e('Field Key', 'client-sync'); ?></label></th>
                        <td>
                            <input name="field_key" type="text" id="dim_field_key" class="regular-text" required pattern="[a-z0-9_]+">
                            <p class="description"><?php esc_html_e('Unique identifier (e.g., `num_bedrooms`). Cannot be changed.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dim_field_label"><?php esc_html_e('Field Label', 'client-sync'); ?></label></th>
                        <td>
                            <input name="field_label" type="text" id="dim_field_label" class="regular-text" required>
                            <p class="description"><?php esc_html_e('Label shown on the search form (e.g., `Number of Bedrooms`).', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dim_field_type"><?php esc_html_e('Field Type', 'client-sync'); ?></label></th>
                        <td>
                            <select name="field_type" id="dim_field_type">
                                <option value="text"><?php esc_html_e('Text', 'client-sync'); ?></option>
                                <option value="number"><?php esc_html_e('Number', 'client-sync'); ?></option>
                                <option value="select"><?php esc_html_e('Dropdown Select', 'client-sync'); ?></option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr class="clisyc-dim-comparison-row" style="display:none;">
                        <th scope="row"><label for="dim_field_comparison_operator"><?php esc_html_e('Comparison Logic', 'client-sync'); ?></label></th>
                        <td>
                            <select name="field_comparison_operator" id="dim_field_comparison_operator">
                                <option value="="><?php esc_html_e( 'Equals (=)', 'client-sync' ); ?></option>
                                <option value=">="><?php esc_html_e( 'Greater Than or Equal To (>=)', 'client-sync' ); ?></option>
                                <option value="<="><?php esc_html_e( 'Less Than or Equal To (<=)', 'client-sync' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Comparison logic for numeric fields. For "Bathrooms", you likely want "Greater Than or Equal To".', 'client-sync'); ?></p>
                        </td>
                    </tr>

                    <?php // START: NEW ICON PICKER ROW ?>
                    <tr>
                        <th scope="row"><label for="dim_field_icon"><?php esc_html_e('Icon', 'client-sync'); ?></label></th>
                        <td>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <input name="field_icon" id="dim_field_icon" type="text" value="dashicons-admin-generic" style="flex-grow:1;">
                                <button type="button" id="clisyc-dim-attr-icon-picker-btn" class="button"><?php esc_html_e('Choose Icon', 'client-sync'); ?></button>
                            </div>
                            <p class="description"><?php esc_html_e('Select a Dashicon to represent this attribute on the search results page.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <?php // END: NEW ICON PICKER ROW ?>

                    <tr class="clisyc-dim-options-row" style="display:none;">
                        <th scope="row"><label for="dim_field_options"><?php esc_html_e('Options', 'client-sync'); ?></label></th>
                        <td>
                            <textarea name="field_options" id="dim_field_options" class="large-text" rows="3" placeholder="Option 1, Option 2, Option 3"></textarea>
                            <p class="description"><?php esc_html_e('Comma-separated values for the Dropdown Select type.', 'client-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Applies To', 'client-sync'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php esc_html_e('Select which dimensions this attribute applies to.', 'client-sync'); ?></legend>
                                <?php if ( ! empty( $clisyc_dimension_cpts ) ) : ?>
                                    <?php foreach ( $clisyc_dimension_cpts as $clisyc_slug => $clisyc_label ) : ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox" name="field_applies_to[]" value="<?php echo esc_attr( $clisyc_slug ); ?>"> <?php echo esc_html( $clisyc_label ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <p><em><?php esc_html_e( 'No enabled dimensions found.', 'client-sync' ); ?></em></p>
                                <?php endif; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Filterable', 'client-sync'); ?></th>
                        <td>
                            <label><input type="checkbox" name="field_filterable" value="1"> <?php esc_html_e('Make this attribute a filter on the Availability Search form?', 'client-sync'); ?></label>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button( __( 'Save Attribute', 'client-sync' ) ); ?>
<button type="button" id="clisyc-dim-cancel-edit-button" class="button button-secondary" style="display: none; vertical-align: middle; margin-left: 5px;"><?php esc_html_e( 'Cancel Edit', 'client-sync' ); ?></button>
        </form>
    </div>
</div>

<!-- Table of Existing Dimension Attributes -->
<div class="postbox">
    <h2 class="hndle"><span><?php esc_html_e( 'Existing Dimension Attributes', 'client-sync' ); ?></span></h2>
    <div class="inside">
        <table class="wp-list-table widefat fixed striped custom-fields">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Key', 'client-sync' ); ?></th>
                    <th><?php esc_html_e( 'Label', 'client-sync' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'client-sync' ); ?></th>
                    <th><?php esc_html_e( 'Filterable', 'client-sync' ); ?></th>
                    <th><?php esc_html_e( 'Applies To', 'client-sync' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'client-sync' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $clisyc_dimension_fields ) ) : ?>
                    <?php foreach ( $clisyc_dimension_fields as $clisyc_key => $clisyc_field ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $clisyc_key ); ?></strong>
                            </td>
                            <td><?php echo esc_html( $clisyc_field['label'] ); ?></td>
                            <td><?php echo esc_html( ucfirst( $clisyc_field['type'] ) ); ?></td>
                            <td><?php echo ! empty( $clisyc_field['filterable'] ) ? 'Yes' : 'No'; ?></td>
                            <td>
                                <?php
                                $clisyc_applies_to_labels = array_map(
                                    function($slug) use ($clisyc_dimension_cpts) {
                                        return $clisyc_dimension_cpts[ $slug ] ?? $slug;
                                    },
                                    $clisyc_field['applies_to'] ?? []
                                );
                                echo esc_html( implode( ', ', $clisyc_applies_to_labels ) );
                                ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <span class="edit">
                                        <button type="button" class="button-link clisyc-dim-edit-field"
                                            data-key="<?php echo esc_attr( $clisyc_key ); ?>"
                                            data-label="<?php echo esc_attr( $clisyc_field['label'] ?? '' ); ?>"
                                            data-type="<?php echo esc_attr( $clisyc_field['type'] ?? 'text' ); ?>"
                                            data-options="<?php echo esc_attr( $clisyc_field['options'] ?? '' ); ?>"
                                            data-filterable="<?php echo ! empty( $clisyc_field['filterable'] ) ? '1' : '0'; ?>"
                                            data-applies-to="<?php echo esc_attr( wp_json_encode( $clisyc_field['applies_to'] ?? [] ) ); ?>"
                                            data-icon="<?php echo esc_attr( $clisyc_field['icon'] ?? 'dashicons-admin-generic' ); ?>"
                                            data-comparison-operator="<?php echo esc_attr( $clisyc_field['comparison_operator'] ?? '=' ); ?>"
                                        >
                                            <?php esc_html_e( 'Edit', 'client-sync' ); ?>
                                        </button> |
                                    </span>
                                    <span class="delete">
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                            <?php wp_nonce_field( 'clisyc_save_dimension_custom_field_action', 'clisyc_dimension_custom_field_nonce' ); ?>
                                            <input type="hidden" name="action" value="clisyc_save_dimension_custom_field">
                                            <input type="hidden" name="clisyc_sub_action" value="delete_field">
                                            <input type="hidden" name="delete_field_key" value="<?php echo esc_attr( $clisyc_key ); ?>">
                                            <button type="submit" class="button-link delete-tag" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this attribute?', 'client-sync' ); ?>');">
                                                <?php esc_html_e( 'Delete', 'client-sync' ); ?>
                                            </button>
                                        </form>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No dimension attributes defined yet.', 'client-sync' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>