/**
 * File: src/assets/js/clisyc-admin-wc-product-helper.js
 * Handles WooCommerce integration UI (Sync Price, Create Product, and Required Products Repeater)
 * 
 * FIX: This is now the SINGLE source of truth for all event handlers.
 *      The inline script in class-post-type-manager.php has been removed.
 */
jQuery(function($) {
    'use strict';

    // === GUARD: Prevent double-initialization ===
    if (window.clisycWcProductHelperInitialized) {
        console.warn('[CLISYC] WC Product Helper already initialized, skipping duplicate.');
        return;
    }
    window.clisycWcProductHelperInitialized = true;
    // === END GUARD ===

    if (typeof clisycWcProductHelper === 'undefined') {
        console.error('[CLISYC] Localization data missing.');
        return;
    }

    // Cache elements
    const $productSelect = $('#clisyc_wc_product_id');
    const $priceInput = $('#clisyc_price');
    const $syncPriceBtn = $('#clisyc-sync-wc-price');
    const $createBtn = $('#clisyc-create-wc-product-btn');
    const $actionsContainer = $('#clisyc-wc-product-actions');

    /**
     * Handle "Sync from Product" button logic
     */
    $syncPriceBtn.on('click', function(e) {
        e.preventDefault();
        
        // Get the Product ID from the Select2/Dropdown
        const productId = $productSelect.val();
        
        if (!productId || productId === '0') {
            alert('Please select and save a WooCommerce product first.');
            return;
        }

        const $btn = $(this);
        const originalHtml = $btn.html();
        
        $btn.prop('disabled', true).text('Syncing...');

        $.post(clisycWcProductHelper.ajax_url, {
            action: 'clisyc_get_wc_product_price',
            security: clisycWcProductHelper.nonce,
            product_id: productId
        })
        .done(function(response) {
            if (response.success) {
                const newPrice = response.data.price;
                if (newPrice !== "" && newPrice !== null) {
                    $priceInput.val(newPrice);
                    // Visual feedback: Flash green
                    $priceInput.css('outline', '2px solid #46b450');
                    setTimeout(() => $priceInput.css('outline', ''), 1000);
                } else {
                    alert('This product has no price set in WooCommerce.');
                }
            } else {
                alert('Error: ' + response.data.message);
            }
        })
        .fail(function() {
            alert('Request failed. Check that WooCommerce is active.');
        })
        .always(function() {
            $btn.prop('disabled', false).html(originalHtml);
        });
    });

    /**
     * Handle "Create & Link Product" button logic (Original functionality)
     */
    $createBtn.on('click', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const $spinner = $btn.siblings('.spinner');

        if (!confirm('Create a new "Simple/Virtual" product with this name?')) return;

        $spinner.addClass('is-active');
        $btn.prop('disabled', true);

        $.post(clisycWcProductHelper.ajax_url, {
            action: 'clisyc_create_wc_product',
            security: clisycWcProductHelper.nonce,
            post_title: $btn.data('post-title')
        })
        .done(function(response) {
            if (response.success) {
                // Add to Select2
                const newOption = new Option(response.data.formatted_name, response.data.new_product_id, true, true);
                $productSelect.append(newOption).trigger('change');

                // Replace button with Link
                const editUrl = clisycWcProductHelper.admin_url + 'post.php?action=edit&post=' + response.data.new_product_id;
                $actionsContainer.html(`
                    <a href="${editUrl}" class="button button-secondary">
                        View / Edit Product
                        <span class="dashicons dashicons-external" style="vertical-align: text-bottom;"></span>
                    </a>
                `);
            } else {
                alert('Error: ' + response.data.message);
                $btn.prop('disabled', false);
            }
        })
        .always(() => $spinner.removeClass('is-active'));
    });

    /**
     * Handle Required Products Repeater
     * Using delegation to prevent double-binding in Gutenberg
     * 
     * FIX: addonIndex now uses window.clisycAddonStartIndex from PHP
     *      to ensure correct indexing when rows already exist on page load.
     */
    $(document).off('click.clisycRepeater', '#clisyc-add-required-product').on('click.clisycRepeater', '#clisyc-add-required-product', function(e) {
        e.preventDefault();
        
        const $repeaterContainer = $('#clisyc-additional-products-list');
        $('.clisyc-no-addons-msg').hide();

        // Use the PHP-provided start index, or fall back to counting existing rows
        // Note: We increment the global so subsequent clicks get unique indices
        let addonIndex;
        if (typeof window.clisycAddonStartIndex !== 'undefined') {
            addonIndex = window.clisycAddonStartIndex;
            window.clisycAddonStartIndex++;
        } else {
            addonIndex = $repeaterContainer.find('.clisyc-additional-product-row').length;
        }
        
        // Get template and replace index
        const template = $('#clisyc-addon-row-template').html().replace(/{{index}}/g, addonIndex);
        const $newRow = $(template);
        
        // Append to container
        $repeaterContainer.append($newRow);
        
        /**
         * TRIGGER FIX:
         * Instead of triggering on the whole body, we only initialize 
         * the specific new dropdown we just created.
         */
        $newRow.find('.wc-product-search').each(function() {
            $(document.body).trigger('wc-enhanced-select-init');
        });
    });

    /**
     * Handle Row Removal
     * Using namespaced events for clean unbinding
     */
    $(document).off('click.clisycRepeater', '.clisyc-remove-product-row').on('click.clisycRepeater', '.clisyc-remove-product-row', function(e) {
        e.preventDefault();
        const $repeaterContainer = $('#clisyc-additional-products-list');
        $(this).closest('.clisyc-additional-product-row').remove();
        
        if ($repeaterContainer.find('.clisyc-additional-product-row').length === 0) {
            $('.clisyc-no-addons-msg').show();
        }
    });
});