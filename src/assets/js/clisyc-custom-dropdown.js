/**
 * File: src/assets/js/clisyc-custom-dropdown.js
 * A simple, reusable module for creating custom dropdowns with color dots,
 * replacing the need for the TomSelect library on the frontend.
 *
 * SECURITY: All label/color values use jQuery DOM methods to prevent XSS.
 * ACCESSIBILITY: ARIA roles, keyboard navigation, focus management.
 * MEMORY: Namespaced document listener with destroy() cleanup.
 */
(function($) {
    'use strict';

    var instanceCounter = 0;

    // Validate CSS color to prevent style-attribute injection.
    function safeColor(color) {
        if (!color || color === 'transparent') return 'transparent';
        if (/^(#[0-9a-f]{3,8}|rgb\(|rgba\(|hsl\(|hsla\(|transparent|[a-z]+)$/i.test(color)) return color;
        return 'transparent';
    }

    function CustomDropdown(selectElement, options) {
        this.$originalSelect = $(selectElement);
        this.options = options || {};
        this.onChangeCallback = this.options.onChange || function() {};
        this.instanceId = ++instanceCounter;
        this.isOpen = false;

        this._createUI();
        this._bindEvents();

        this.$originalSelect.hide();
    }

    CustomDropdown.prototype._createUI = function() {
        var listId = 'clisyc-dropdown-list-' + this.instanceId;

        this.$container = $('<div class="clisyc-custom-dropdown"></div>');
        this.$selected = $('<div class="clisyc-custom-dropdown-selected"></div>')
            .attr({
                'role': 'combobox',
                'tabindex': '0',
                'aria-haspopup': 'listbox',
                'aria-expanded': 'false',
                'aria-owns': listId
            });
        this.$optionsContainer = $('<div class="clisyc-custom-dropdown-options"></div>');
        var $ul = $('<ul></ul>').attr({ 'role': 'listbox', 'id': listId });
        this.$optionsContainer.append($ul);

        this.$container.append(this.$selected).append(this.$optionsContainer);
        this.$originalSelect.after(this.$container);

        this.populateOptions();
        this.updateSelectedDisplay();
    };

    CustomDropdown.prototype._buildColorDot = function(color) {
        return $('<span class="clisyc-filter-option-color-dot"></span>')
            .css('background-color', safeColor(color));
    };

    CustomDropdown.prototype.populateOptions = function(newOptions) {
        var self = this;
        var $ul = this.$optionsContainer.find('ul');
        $ul.empty();

        var optionsSource = newOptions ? newOptions : this.$originalSelect.find('option');

        optionsSource.each(function(index, el) {
            var $el = $(el);
            var value = $el.val();
            var label = $el.text();
            var color = $el.data('color') || 'transparent';

            // SECURITY: Build DOM elements instead of HTML string concatenation.
            var $li = $('<li></li>')
                .attr({
                    'data-value': value,
                    'role': 'option',
                    'tabindex': '-1'
                })
                .append(self._buildColorDot(color))
                .append($('<span>').text(label));

            if (value === "") {
                $li.addClass('clisyc-custom-dropdown-placeholder');
            }

            $ul.append($li);
        });
        this.updateSelectedDisplay();
    };

    CustomDropdown.prototype.updateSelectedDisplay = function() {
        var selectedValue = this.$originalSelect.val();
        var $selectedOption = this.$originalSelect.find('option:selected');
        var label = $selectedOption.text();
        var color = $selectedOption.data('color') || 'transparent';

        // SECURITY: Build via DOM methods, not HTML interpolation.
        this.$selected.empty()
            .append(this._buildColorDot(color))
            .append($('<span>').text(label));

        if (selectedValue === "") {
            this.$selected.addClass('clisyc-custom-dropdown-placeholder');
        } else {
            this.$selected.removeClass('clisyc-custom-dropdown-placeholder');
        }
    };

    CustomDropdown.prototype._setOpen = function(open) {
        this.isOpen = open;
        this.$optionsContainer.toggle(open);
        this.$selected.attr('aria-expanded', open ? 'true' : 'false');
    };

    CustomDropdown.prototype._bindEvents = function() {
        var self = this;

        // Toggle on click
        this.$selected.on('click', function(e) {
            e.stopPropagation();
            self._setOpen(!self.isOpen);
        });

        // Keyboard navigation on the combobox trigger
        this.$selected.on('keydown', function(e) {
            switch (e.key) {
                case 'Enter':
                case ' ':
                case 'ArrowDown':
                    e.preventDefault();
                    self._setOpen(true);
                    // Focus first option
                    self.$optionsContainer.find('li:first').focus();
                    break;
                case 'Escape':
                    self._setOpen(false);
                    break;
            }
        });

        // Option click
        this.$optionsContainer.on('click', 'li', function(e) {
            var value = $(e.currentTarget).data('value');
            self.setValue(value);
            self._setOpen(false);
            self.$selected.focus();
        });

        // Keyboard navigation within options
        this.$optionsContainer.on('keydown', 'li', function(e) {
            var $focused = $(e.currentTarget);
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    $focused.next('li').focus();
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    var $prev = $focused.prev('li');
                    if ($prev.length) {
                        $prev.focus();
                    } else {
                        self.$selected.focus();
                    }
                    break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    $focused.trigger('click');
                    break;
                case 'Escape':
                    e.preventDefault();
                    self._setOpen(false);
                    self.$selected.focus();
                    break;
            }
        });

        // Close on outside click — use namespaced event for proper cleanup.
        $(document).on('click.clisycDropdown' + this.instanceId, function() {
            self._setOpen(false);
        });
    };

    CustomDropdown.prototype.setValue = function(value) {
        if (this.$originalSelect.val() !== value) {
            this.$originalSelect.val(value);
            this.updateSelectedDisplay();
            this.onChangeCallback(value);
        }
    };

    CustomDropdown.prototype.clear = function() {
        this.setValue("");
    };

    // Cleanup method to prevent memory leaks when dropdowns are destroyed/recreated.
    CustomDropdown.prototype.destroy = function() {
        $(document).off('click.clisycDropdown' + this.instanceId);
        this.$container.remove();
        this.$originalSelect.show();
    };

    // Expose the constructor on the jQuery object
    $.fn.clisycCustomDropdown = function(options) {
        return this.each(function() {
            if (!$(this).data('clisycCustomDropdown')) {
                $(this).data('clisycCustomDropdown', new CustomDropdown(this, options));
            }
        });
    };

})(jQuery);
