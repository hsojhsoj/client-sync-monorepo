/**
 * File: src/assets/js/clisyc-admin-dimensions-page.js -> client-sync/assets/js/clisyc-admin-dimensions-page.js
 * Handles interactivity on the PHP-driven parts of the Dimension Manager page,
 * specifically the edit-in-place form and sortable table.
 * The Icon Picker logic has been moved to clisyc-admin-global-icon-picker.js
 */
jQuery(function ($) {
    'use strict';

    // Scope script to only run on the correct page
    if (!$('.clisyc-dimension-manager-page').length || typeof clisycDimensionPageData === 'undefined') {
        return;
    }

    // --- Localized Data & Cached Elements ---
    const l10n = clisycDimensionPageData.l10n || {};
    const ajax_url = clisycDimensionPageData.ajax_url || null;
    const reorder_nonce = clisycDimensionPageData.reorder_nonce || null;

    const $form = $('#clisyc-dimension-type-form');
    const $formTitle = $('#clisyc-dimension-form-title');
    const $pluralInput = $('#dim_plural_name');
    const $singularInput = $('#dim_singular_name');
    const $slugInput = $('#dim_slug');
    const $iconInput = $('#dim_icon');
    const $actionInput = $('#clisyc_dimension_action');
    const $editSlugInput = $('#edit_dimension_slug');
    const $cancelButton = $('#clisyc-cancel-edit-dimension');
    const $submitButton = $form.find('input[type="submit"]');

    // Slug validation elements
    const slugPrefix = 'clisyc_';
    const slugMaxLength = 20;
    $slugInput.parent().find('.description').after('<div id="clisyc-slug-error" class="cs-slug-error" style="display:none; color: #d63638; margin-top: 5px;"></div>');
    const $slugError = $('#clisyc-slug-error');

    // --- Functions ---
    function validateSlug() {
        const slugValue = $slugInput.val();
        const fullSlug = slugPrefix + slugValue;
        
        if (fullSlug.length > slugMaxLength) {
            $slugError.text(`Error: Full slug "${fullSlug}" (${fullSlug.length} chars) exceeds the 20-character limit.`).show();
            $submitButton.prop('disabled', true);
            return false;
        } else {
            $slugError.hide().text('');
            $submitButton.prop('disabled', false);
            return true;
        }
    }
    
    function resetForm() {
        $pluralInput.val('');
        $singularInput.val('');
        $slugInput.val('').prop('readonly', false);
        $iconInput.val('dashicons-admin-generic');
        
        $formTitle.text(l10n.addTitle);
        $submitButton.val(l10n.addButton);
        $actionInput.val('add');
        $editSlugInput.val('');
        $cancelButton.hide();
        validateSlug(); // Ensure form is valid after reset
    }

    // --- NEW: Functions to manage the "Is Resource" checkbox state ---
    function updateResourceCheckboxStates() {
        const $primaryRadio = $('input[name="clisyc_dimension_registry[primary_dimension]"]:checked');
        if (!$primaryRadio.length) {
            // If nothing is primary, enable all resource checkboxes
            $('input[name*="[is_resource]"]').prop('disabled', false);
            return;
        }
        
        const $primaryRow = $primaryRadio.closest('tr');
        const $resourceCheckboxInPrimaryRow = $primaryRow.find('input[name*="[is_resource]"]');

        // Reset all: enable checkboxes and remove any hidden inputs from previous primary
        $('input[name*="[is_resource]"]').prop('disabled', false);
        $('input[type="hidden"][name*="[is_resource]"]').remove();

        // Check and disable the one in the primary row — scheduling is mandatory for the primary dimension.
        $resourceCheckboxInPrimaryRow
            .prop('disabled', true)
            .prop('checked', true)
            .attr('title', 'Scheduling is always enabled for the primary dimension.');

        // Disabled checkboxes don't submit their value, so add a hidden input
        // to ensure the is_resource=1 value is sent for the primary dimension.
        const resourceName = $resourceCheckboxInPrimaryRow.attr('name');
        if (resourceName && !$primaryRow.find('input[type="hidden"][name="' + resourceName + '"]').length) {
            $resourceCheckboxInPrimaryRow.after(
                $('<input>', { type: 'hidden', name: resourceName, value: '1' })
            );
        }
    }

    // --- Event Handlers ---
    $slugInput.on('keyup change', _.debounce(validateSlug, 300));

    $form.on('submit', function(e) {
        if (!validateSlug()) {
            e.preventDefault();
            alert('Please fix the errors before submitting.');
        }
    });

    $('.clisyc-dimension-manager-page').on('click', '.clisyc-edit-dimension-type', function(e) {
        e.preventDefault();
        const $btn = $(this);
        
        $formTitle.text(l10n.editTitle);
        $pluralInput.val($btn.data('plural'));
        $singularInput.val($btn.data('singular'));
        $slugInput.val($btn.data('slug').replace('clisyc_', '')).prop('readonly', true);
        $iconInput.val($btn.data('icon'));
        
        const publicCheckbox = $('input[name="clisyc_dimension_registry[dimensions][' + $btn.data('slug') + '][public]"]');
        if (publicCheckbox.length) {
            publicCheckbox.prop('checked', $btn.data('public') == 1);
        }
        
        $actionInput.val('update');
        $editSlugInput.val($btn.data('slug'));

        $submitButton.val(l10n.updateButton);
        $cancelButton.show();
        
        // When editing, the slug is readonly and cannot be invalid.
        $slugError.hide().text('');
        $submitButton.prop('disabled', false);

        $('html, body').animate({ scrollTop: $form.offset().top - 50 }, 300);
    });

    $cancelButton.on('click', function(e) {
        e.preventDefault();
        resetForm();
    });

    $('input[name="clisyc_dimension_registry[primary_dimension]"]').on('change', function() {
        updateResourceCheckboxStates();
    });

    // --- Sortable Table for Filter Order ---
    const $sortableTable = $('#clisyc-dimensions-table-body');
    if ($sortableTable.length && typeof $.fn.sortable === 'function') {
        $sortableTable.sortable({
            items: 'tr',
            cursor: 'move',
            axis: 'y',
            handle: '.clisyc-drag-handle',
            opacity: 0.6,
            placeholder: 'clisyc-sortable-placeholder',
            helper: function(e, ui) {
                ui.children().each(function() { $(this).width($(this).width()); });
                return ui;
            },
            update: function(event, ui) {
                const newOrder = $sortableTable.sortable('toArray', { attribute: 'data-slug' });
                if (ajax_url && reorder_nonce) {
                    $.post(ajax_url, {
                        action: 'clisyc_reorder_dimensions',
                        security: reorder_nonce,
                        order: newOrder
                    });
                }
            }
        }).disableSelection();
    }

    // --- Initial Setup ---
    updateResourceCheckboxStates();

    // --- Table Sorting ---
    const $table = $('table.wp-list-table');
    const $tbody = $table.find('tbody');
    const $formSettings = $('form[action="options.php"]');  // The settings form

    function renumberOrders() {
        let count = 1;
        $tbody.find('tr').each(function() {
            const $tr = $(this);
            const $enabledCheckbox = $tr.find('input[name*="\\[enabled\\]"]');
            const enabled = $enabledCheckbox.is(':checked');
            $tr.find('.order-number').text(enabled ? count++ : '—');
            $tr.find('.sort-handle .dashicons').toggle(enabled);
        });
    }

    function updateHiddenOrder() {
        const $hiddenContainer = $('#filter-order-hidden');
        $hiddenContainer.empty();
        $tbody.find('tr').each(function() {
            const $tr = $(this);
            const enabled = $tr.find('input[name*="\\[enabled\\]"]').is(':checked');
            if (enabled) {
                const slug = $tr.data('slug');
                $('<input>').attr({
                    type: 'hidden',
                    name: 'clisyc_dimension_registry[filter_order][]',
                    value: slug
                }).appendTo($hiddenContainer);
            }
        });
    }

    // Initialize sortable
    $tbody.sortable({
        items: 'tr:has(input[name*="\\[enabled\\]"]:checked)',  // Only enabled rows
        handle: '.sort-handle',
        placeholder: 'sortable-placeholder',
        axis: 'y',
        tolerance: 'pointer',
        update: function(event, ui) {
            renumberOrders();
            updateHiddenOrder();
        }
    });

    // On enable/disable change
    $table.on('change', 'input[name*="\\[enabled\\]"]', function() {
        renumberOrders();
        updateHiddenOrder();
        $tbody.sortable('refresh');  // Refresh sortable items
    });

    // Initial setup
    renumberOrders();
    updateHiddenOrder();
});