/**
 * File: src/assets/js/clisyc-admin-custom-fields.js -> client-sync/assets/js/clisyc-admin-custom-fields.js
 * Handles interactivity for the Custom Fields admin page (Client, Booking, and Dimension tabs).
 *
 * @package    ClientSync
 * @subpackage ClientSync/Assets/JS
 */

jQuery(function($) { // <--- START of the main wrapper

    // --- Data from wp_localize_script ---
    const l10n = (typeof clisycAdminCustomFieldData !== 'undefined') ? clisycAdminCustomFieldData : {};
    const ajax_url = l10n.ajax_url || null;
    const reorderNonce = l10n.reorderNonce || null;

    /**
     * Toggles visibility of form rows and manages input states based on the selected field type.
     * @param {jQuery} $form - The jQuery object for the form being modified.
     */
    function toggleConditionalRows($form) {

        if (!$form.length) return;

        const selectedType = $form.find('select[name="field_type"]').val();
        const formId = $form.attr('id');

        const optionTypes = ['select', 'multi_checkbox', 'radio'];
        const layoutTypes = ['multi_checkbox', 'radio'];

        // --- Show/Hide primary rows based on field type ---
        if (optionTypes.includes(selectedType)) {
            $form.find('.clisyc-options-row').show();
        } else {
            $form.find('.clisyc-options-row').hide();
        }

        if (layoutTypes.includes(selectedType)) {
            $form.find('.clisyc-layout-row').show();
        } else {
            $form.find('.clisyc-layout-row').hide();
        }

        if (selectedType === 'custom_html') {
            $form.find('.clisyc-custom-html-row').show();
        } else {
            $form.find('.clisyc-custom-html-row').hide();
        }

        if (selectedType === 'image_map') {
            $form.find('.clisyc-image-map-row').show();
        } else {
            $form.find('.clisyc-image-map-row').hide();
        }

        // Handle TinyMCE visibility and resize for custom HTML fields
        if (selectedType === 'custom_html') {
            const editorId = (formId === 'clisyc-appointment-custom-field-form') ? 'clisyc_appt_field_custom_html_editor' : 'clisyc_client_field_custom_html_editor';
            setTimeout(() => {
                const editor = tinymce.get(editorId);
                if (editor) {
                    editor.show();
                    editor.execCommand('mceAutoResize');
                }
            }, 50); // Small delay ensures the row is fully visible before adjusting
        }

        // Toggle color picker visibility within the options row (only for 'select' type)
        if (optionTypes.includes(selectedType)) {
            if (selectedType === 'select') {
                $form.find('.clisyc-options-row .wp-picker-container').show();
            } else {
                $form.find('.clisyc-options-row .wp-picker-container').hide();
            }
        }

        // --- Handle Appointment Field Specific Logic ---
        if (formId === 'clisyc-appointment-custom-field-form') {
            const conditionalLogicEnabled = $('#clisyc_appt_field_enable_conditional_logic').is(':checked');

            if (conditionalLogicEnabled) {
                $('#clisyc-conditional-logic-builder').show();
            } else {
                $('#clisyc-conditional-logic-builder').hide();
            }
            
            // ADDED: 'number' to the trackable list
            const isTrackable = ['text', 'select', 'number'].includes(selectedType);
            $form.find('input[name="field_track_progress"]').prop('disabled', !isTrackable);
            if (!isTrackable) {
                $form.find('input[name="field_track_progress"]').prop('checked', false);
            }
        } else if (formId === 'clisyc-dimension-custom-field-form') {
            const optionTypes = ['select'];
            if (optionTypes.includes(selectedType)) {
                $form.find('.clisyc-dim-options-row').show();
            } else {
                $form.find('.clisyc-dim-options-row').hide();
            }
        }

        // --- Handle 'Required' field status ---
        const isActionableForRequired = !['custom_html', 'image_map'].includes(selectedType);
        $form.find('input[name="field_required"]').prop('disabled', !isActionableForRequired);
        if (!isActionableForRequired) {
            $form.find('input[name="field_required"]').prop('checked', false);
        }
    }

    // --- Options List Editor Logic ---
    const optionsListSelector = '.clisyc-options-sortable-list';
    const addOptionButtonSelector = '.clisyc-add-option-button';
    const deleteOptionButtonSelector = '.clisyc-delete-option-item';
    const optionItemSelector = '.clisyc-field-option-item';
    const optionValueInputSelector = '.clisyc-option-value-input';
    const hiddenOptionsInputSelector = '.clisyc-field-options-hidden-input';
    const dragHandleClass = 'clisyc-option-drag-handle';
    const colorPickerClass = 'clisyc-option-color-picker';

    function esc_html_js(str) {
        if (typeof str !== 'string') return '';
        return str.replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function createOptionListItem(value = '', color = '') {
        const initialColor = (color && color.startsWith('#')) ? color : (color ? '#' + color.replace(/[^0-9a-fA-F]/g, '') : '');
        const randomIdSuffix = Math.random().toString(36).substr(2, 5);

        return `
        <li class="${optionItemSelector.substring(1)}">
            <span class="${dragHandleClass} dashicons dashicons-menu"></span>
            <input type="text" class="${optionValueInputSelector.substring(1)}" value="${esc_html_js(value)}" placeholder="${l10n.optionPlaceholder || 'Option Value'}">
            <label class="screen-reader-text" for="clisyc-option-color-picker-instance-${randomIdSuffix}">${l10n.optionColorLabel || 'Option Color'}</label>
            <input type="text" id="clisyc-option-color-picker-instance-${randomIdSuffix}" class="${colorPickerClass}" value="${esc_html_js(initialColor)}" data-default-color="#ffffff">
            <button type="button" class="button-link ${deleteOptionButtonSelector.substring(1)}"><span class="dashicons dashicons-trash"></span></button>
        </li>
    `;
    }

    function buildOptionsList(optionsData, $listContainer) {
        $listContainer.empty();
        let optionsArray = [];

        try {
            const parsed = typeof optionsData === 'string' ? JSON.parse(optionsData) : optionsData;
            if (Array.isArray(parsed) && parsed.every(item => typeof item === 'object' && 'value' in item)) {
                optionsArray = parsed;
            }
        } catch (e) {
            if (typeof optionsData === 'string') {
                optionsArray = optionsData.split(',').map(opt => ({
                    value: opt.trim(),
                    color: ''
                })).filter(opt => opt.value);
            }
        }

        optionsArray.forEach(opt => {
            $listContainer.append(createOptionListItem(opt.value, opt.color || ''));
        });

        $listContainer.find('.' + colorPickerClass + ':not(.wp-color-picker)').wpColorPicker();
    }

    function serializeOptionsForSubmission($form) {
        const $listContainer = $form.find(optionsListSelector);
        const options = [];
        $listContainer.find(optionItemSelector).each(function() {
            const $item = $(this);
            const val = $item.find(optionValueInputSelector).val().trim();
            const color = $item.find('.' + colorPickerClass).val().trim();
            if (val) {
                options.push({
                    value: val,
                    color: color
                });
            }
        });
        $form.find(hiddenOptionsInputSelector).val(JSON.stringify(options));
    }

    // --- Conditional Logic Builder ---
    const logicContainer = $('#clisyc-conditional-logic-builder');
    const rulesContainer = logicContainer.find('.clisyc-logic-rules-container');

    $('#clisyc_appt_field_enable_conditional_logic').on('change', function() {
        toggleConditionalRows($(this).closest('form'));
    });

    function addRuleRow(rule = null) {
        const templateHtml = l10n.conditionalRuleTemplate || '';
        if (!templateHtml) {
            console.error('Conditional logic rule template not found in localized data.');
            return;
        }

        const newRow = $(templateHtml);
        rulesContainer.append(newRow);

        if (rule && rule.field) {
            const $newRowAppended = rulesContainer.find('.clisyc-logic-rule-row').last();
            $newRowAppended.find('.clisyc-rule-field').val(rule.field);
            $newRowAppended.find('.clisyc-rule-operator').val(rule.operator);
            $newRowAppended.find('.clisyc-rule-value').val(rule.value);
        }
    }

    function buildConditionalLogicUI(fieldData) {
        const logicData = (typeof fieldData['conditional_logic'] === 'string') ?
            JSON.parse(fieldData['conditional_logic'] || '{}') :
            (fieldData['conditional_logic'] || {});

        rulesContainer.empty();

        if (logicData && logicData.enabled) {
            $('#clisyc_appt_field_enable_conditional_logic').prop('checked', true);
            logicContainer.find('select[name="conditional_logic[condition]"]').val(logicData.condition || 'all');

            if (logicData.rules && Array.isArray(logicData.rules)) {
                logicData.rules.forEach(rule => addRuleRow(rule));
            }
        } else {
            $('#clisyc_appt_field_enable_conditional_logic').prop('checked', false);
            logicContainer.find('select[name="conditional_logic[condition]"]').val('all');
        }
    }

    function serializeConditionalLogic() {
        if (!$('#clisyc_appt_field_enable_conditional_logic').is(':checked')) {
            $('input[name="conditional_logic_json"]').remove();
            return;
        }

        const logicData = {
            enabled: true,
            condition: logicContainer.find('select[name="conditional_logic[condition]"]').val(),
            rules: []
        };

        rulesContainer.find('.clisyc-logic-rule-row').each(function() {
            const $row = $(this);
            const field = $row.find('.clisyc-rule-field').val();
            if (field) {
                logicData.rules.push({
                    field: field,
                    operator: $row.find('.clisyc-rule-operator').val(),
                    value: $row.find('.clisyc-rule-value').val()
                });
            }
        });

        let $hiddenInput = $('input[name="conditional_logic_json"]');
        if (!$hiddenInput.length) {
            $hiddenInput = $('<input type="hidden" name="conditional_logic_json">').appendTo('#clisyc-appointment-custom-field-form');
        }
        $hiddenInput.val(JSON.stringify(logicData));
    }

    logicContainer.on('click', '.clisyc-add-rule-button', () => addRuleRow());
    logicContainer.on('click', '.clisyc-remove-rule-button', function() {
        $(this).closest('.clisyc-logic-rule-row').remove();
    });
    
    /**
     * Initializes a single custom field form (Client, Appointment, or Dimension).
     * @param {jQuery} $form - The jQuery object for the form to initialize.
     */
    function initializeForm($form) {
        if (!$form.length) return;
        const formId = $form.attr('id');

        const $list = $form.find('.clisyc-options-sortable-list');
        if ($list.length && typeof $list.sortable === 'function') {
            $list.sortable({
                handle: '.' + dragHandleClass,
                placeholder: 'clisyc-option-sortable-placeholder',
                axis: 'y',
            });
        }

        $form.on('click.clisyc-add', addOptionButtonSelector, function() {
            const $newItem = $(createOptionListItem());
            $list.append($newItem);
            $newItem.find('.' + colorPickerClass).wpColorPicker();
            const selectedType = $form.find('select[name="field_type"]').val();
            if (selectedType !== 'select') {
                $newItem.find('.wp-picker-container').hide();
            }
        });

        $list.on('click.clisyc-delete', deleteOptionButtonSelector, function() {
            $(this).closest(optionItemSelector).remove();
        });

        $form.on('submit.clisyc', function() {
            serializeOptionsForSubmission($form);
            if (formId === 'clisyc-appointment-custom-field-form') {
                serializeConditionalLogic();
            }
        });

        $form.find('select[name="field_type"]').on('change.clisyc-toggle', function() {
            toggleConditionalRows($form);
        });
        
        // START: NEW LOGIC FOR DIMENSION FORM
        if (formId === 'clisyc-dimension-custom-field-form') {
            $form.find('select[name="field_type"]').on('change', function() {
                if ($(this).val() === 'number') {
                    $form.find('.clisyc-dim-comparison-row').show();
                } else {
                    $form.find('.clisyc-dim-comparison-row').hide();
                }
            }).trigger('change');
        }
        // END: NEW LOGIC FOR DIMENSION FORM
        
        toggleConditionalRows($form);
    }

    $('.custom-fields').on('click', '.clisyc-edit-field', function(e) {
        e.preventDefault();
        const $button = $(this);
        const $form = $button.closest('.clisyc-fields-tab-pane').find('form');

        $form.find('input[name="field_key"]').val($button.data('key')).prop('readonly', true);
        $form.find('input[name="field_label"]').val($button.data('label'));
        $form.find('select[name="field_type"]').val($button.data('type'));
        $form.find('input[name="field_required"]').prop('checked', $button.data('required') == 1);
        $form.find('input[name="field_layout"][value="' + ($button.data('layout') || 'vertical') + '"]').prop('checked', true);

        buildOptionsList($button.data('options'), $form.find(optionsListSelector));
        serializeOptionsForSubmission($form);

        const editorId = $form.attr('id').includes('appt') ? 'clisyc_appt_field_custom_html_editor' : 'clisyc_client_field_custom_html_editor';
        const editorInstance = typeof tinymce !== 'undefined' ? tinymce.get(editorId) : null;
        if (editorInstance) {
            editorInstance.setContent($button.data('html-content') || '');
        } else {
            $form.find(`textarea[name="field_custom_html"]`).val($button.data('html-content') || '');
        }

        const imageId = $button.data('image-id') || 0;
        const imageUrl = $button.data('image-url') || '';
        const $previewImg = $form.find('.clisyc-image-preview-wrapper img');
        const $removeBtn = $form.find('.clisyc-remove-image-button');
        $form.find('input.clisyc-image-id-input').val(imageId);
        if (imageUrl) {
            $previewImg.attr('src', imageUrl).show();
            $removeBtn.show();
        } else {
            $previewImg.hide().attr('src', '');
            $removeBtn.hide();
        }

        if ($form.attr('id') === 'clisyc-appointment-custom-field-form') {
            $form.find('input[name="field_track_progress"]').prop('checked', $button.data('track-progress') == 1);
            buildConditionalLogicUI($button.data());
        }

        toggleConditionalRows($form);
        $form.find('input[name*="sub_action"]').val('update_field');
        $form.find('input[name="edit_field_key"]').val($button.data('key'));
        $form.find('button[type="submit"]').text(l10n.updateText);
        $form.find('button[id*="-cancel-edit"]').show();
        $('html, body').animate({
            scrollTop: $form.offset().top - 50
        }, 300);
    });

    $('button[id*="-cancel-edit"]').on('click', function() {
        const $form = $(this).closest('form');
        $form[0].reset();

        $form.find('input[name="field_key"]').prop('readonly', false);
        $form.find(optionsListSelector).empty();
        $form.find(hiddenOptionsInputSelector).val('[]');

        const editorId = $form.attr('id').includes('appt') ? 'clisyc_appt_field_custom_html_editor' : 'clisyc_client_field_custom_html_editor';
        const editorInstance = typeof tinymce !== 'undefined' ? tinymce.get(editorId) : null;
        if (editorInstance) editorInstance.setContent('');

        $form.find('.clisyc-image-preview-wrapper img').hide().attr('src', '');
        $form.find('.clisyc-remove-image-button').hide();

        if ($form.attr('id') === 'clisyc-appointment-custom-field-form') {
            $('#clisyc_appt_field_enable_conditional_logic').prop('checked', false);
            rulesContainer.empty();
        }

        toggleConditionalRows($form);

        $form.find('input[name*="sub_action"]').val('add_field');
        $form.find('input[name="edit_field_key"]').val('');
        $form.find('button[type="submit"]').text(l10n.addText);
        $(this).hide();
    });

    // Event handler for Dimension Attribute "Edit" buttons
    $('.custom-fields').on('click', '.clisyc-dim-edit-field', function(e) {
        e.preventDefault();
        const $button = $(this);
        const $form = $('#clisyc-dimension-custom-field-form');

        $form.find('input[name="field_key"]').val($button.data('key')).prop('readonly', true);
        $form.find('input[name="field_label"]').val($button.data('label'));
        $form.find('select[name="field_type"]').val($button.data('type')).trigger('change');
        $form.find('textarea[name="field_options"]').val($button.data('options'));
        $form.find('input[name="field_filterable"]').prop('checked', $button.data('filterable') == 1);
        $form.find('select[name="field_comparison_operator"]').val($button.data('comparison-operator'));

        $form.find('input[name="field_applies_to[]"]').prop('checked', false);
        const appliesTo = $button.data('applies-to') || [];
        if (Array.isArray(appliesTo)) {
            appliesTo.forEach(slug => {
                $form.find('input[name="field_applies_to[]"][value="' + slug + '"]').prop('checked', true);
            });
        }

        $form.find('input[name="clisyc_sub_action"]').val('update_field');
        $form.find('input[name="edit_field_key"]').val($button.data('key'));
        $form.find('input[type="submit"]').val(l10n.updateText || 'Update Attribute');
        $('#clisyc-dim-cancel-edit-button').show();

        $('html, body').animate({
            scrollTop: $form.offset().top - 50
        }, 300);
    });

    $('#clisyc-dim-cancel-edit-button').on('click', function() {
        const $form = $('#clisyc-dimension-custom-field-form');
        $form[0].reset();
        $form.find('input[name="field_key"]').prop('readonly', false);
        $form.find('select[name="field_comparison_operator"]').val('=');
        $form.find('select[name="field_type"]').trigger('change');
        $form.find('input[name="clisyc_sub_action"]').val('add_field');
        $form.find('input[name="edit_field_key"]').val('');
        $form.find('input[type="submit"]').val(l10n.addText || 'Add Attribute');
        $(this).hide();
    });

    $(document).on('click', '.clisyc-upload-image-button', function(e) {
        e.preventDefault();
        const $button = $(this);
        const mediaFrame = wp.media({
            title: l10n.selectImage,
            button: {
                text: l10n.useImage
            },
            library: {
                type: 'image'
            },
            multiple: false
        });
        mediaFrame.on('select', function() {
            const attachment = mediaFrame.state().get('selection').first().toJSON();
            if (attachment && attachment.id) {
                $button.siblings('.clisyc-image-id-input').val(attachment.id);
                $button.siblings('.clisyc-image-preview-wrapper').find('img').attr('src', attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url).show();
                $button.siblings('.clisyc-remove-image-button').show();
            }
        }).open();
    });

    $(document).on('click', '.clisyc-remove-image-button', function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.siblings('.clisyc-image-id-input').val(0);
        $button.siblings('.clisyc-image-preview-wrapper').find('img').hide().attr('src', '');
        $button.hide();
    });

    $('input[name="field_key"]').on('input', function() {
        if (!$(this).prop('readonly')) {
            $(this).val($(this).val().toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, ''));
        }
    });

    // --- Initializations ---
    initializeForm($('#clisyc-client-custom-field-form'));
    initializeForm($('#clisyc-appointment-custom-field-form'));
    initializeForm($('#clisyc-dimension-custom-field-form'));

    if (ajax_url && reorderNonce) {
        $('.clisyc-sortable-fields').sortable({
            items: 'tr',
            cursor: 'move',
            axis: 'y',
            handle: '.column-drag-handle',
            opacity: 0.6,
            placeholder: 'clisyc-sortable-placeholder',
            helper: (e, ui) => {
                ui.children().each(function() {
                    $(this).width($(this).width());
                });
                return ui;
            },
            update: function(event, ui) {
                const $tbody = $(this);
                const context = $tbody.data('field-context');
                let ajaxAction;

                switch (context) {
                    case 'client':
                        ajaxAction = 'clisyc_update_client_field_order';
                        break;
                    case 'dimension':
                        ajaxAction = 'clisyc_update_dimension_field_order';
                        break;
                    default:
                        ajaxAction = 'clisyc_update_appointment_field_order';
                        break;
                }

                $.post(ajax_url, {
                    action: ajaxAction,
                    security: reorderNonce,
                    field_order: $tbody.sortable('toArray', {
                        attribute: 'data-field-key'
                    })
                });
            }
        }).disableSelection();
    }

}); // <--- END of the main wrapper