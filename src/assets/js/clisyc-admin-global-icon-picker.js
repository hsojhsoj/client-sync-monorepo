/**
 * File: src/assets/js/clisyc-admin-global-icon-picker.js -> client-sync/assets/js/clisyc-admin-global-icon-picker.js
 * Defines the reusable, global icon picker object.
 * It is initialized by an inline script in the admin footer.
 */
'use strict';

// Create the object on the window so it's globally accessible.
window.ClisycGlobalIconPicker = (function($) {

    const picker = {
        modal: null,
        grid: null,
        search: null,
        activeTargetInput: null,
        allIcons: [],
        activeIconSet: 'fa',

        init: function (icons) {
            this.allIcons = icons;
            this.modal = $('#clisyc-global-icon-picker-modal');
            this.grid = $('#clisyc-global-icon-picker-grid');
            this.search = $('#clisyc-global-icon-picker-search');

            if (!this.modal.length) return; // Safety check

            this.modal.dialog({
                autoOpen: false,
                modal: true,
                width: 800,
                height: Math.min(800, $(window).height() - 100),
                buttons: { Close: () => this.modal.dialog('close') }
            });

            this.bindEvents();
        },

        open: function (targetInput) {
            this.activeTargetInput = $(targetInput);
            if (this.activeTargetInput.length) {
                this.modal.find('.nav-tab[data-icon-set="fa"]').trigger('click');
                this.modal.dialog('open');
            }
        },

        renderGrid: function () {
            const filter = this.search.val().toLowerCase().trim();
            this.grid.empty();

            const iconsToRender = this.allIcons.filter(iconClass => {
                const isDashicon = iconClass.startsWith('dashicons-');
                if (this.activeIconSet === 'fa' && isDashicon) return false;
                if (this.activeIconSet === 'dashicons' && !isDashicon) return false;

                if (filter) {
                    const searchableText = iconClass.replace(/^(dashicons-|fas fa-|far fa-|fab fa-)/, '').replace(/-/g, ' ');
                    return searchableText.includes(filter);
                }
                return true;
            });

            if (iconsToRender.length === 0) {
                this.grid.append('<p>No icons found.</p>');
                return;
            }

            iconsToRender.forEach(iconClass => {
                const $item = $('<div class="clisyc-icon-picker-item" tabindex="0"></div>').data('icon', iconClass);
                const $iconWrapper = $('<span class="clisyc-icon-picker-icon-wrapper"></span>');
                // SECURITY: Use .addClass() instead of string concatenation to prevent class injection.
                const $iconEl = $('<span></span>').addClass(iconClass);
                $iconWrapper.append($iconEl);
                $item.append($iconWrapper);
                const labelText = iconClass.replace(/^(dashicons-|fas fa-|far fa-|fab fa-)/, '').replace(/-/g, ' ');
                $item.append($('<span class="clisyc-icon-picker-label"></span>').text(labelText));
                this.grid.append($item);
            });
        },

        bindEvents: function () {
            // Event handlers for the modal itself
            this.modal.find('.nav-tab').on('click', (e) => {
                e.preventDefault();
                const $tab = $(e.currentTarget);
                this.activeIconSet = $tab.data('icon-set');
                $tab.addClass('nav-tab-active').siblings().removeClass('nav-tab-active');
                this.search.val('');
                this.renderGrid();
            });

            this.search.on('keyup', _.debounce(() => this.renderGrid(), 300));

            this.grid.on('click keypress', '.clisyc-icon-picker-item', (e) => {
                if ((e.type === 'keypress' && e.which !== 13) || !this.activeTargetInput) return;
                this.activeTargetInput.val($(e.currentTarget).data('icon')).trigger('change');
                this.modal.dialog('close');
            });

            // Delegated event handlers for the trigger buttons
            $(document.body).on('click', '#clisyc-open-icon-picker', (e) => {
                e.preventDefault();
                this.open('#dim_icon');
            });

            $(document.body).on('click', '#clisyc-dim-attr-icon-picker-btn', (e) => {
                e.preventDefault();
                this.open('#dim_field_icon');
            });
        }
    };
    
    return picker;

})(jQuery);