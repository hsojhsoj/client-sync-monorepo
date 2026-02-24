/**
 * File: /src/pro/includes/modules/memberships/js/admin-memberships.js
 */

jQuery(function ($) {
    'use strict';

    function toggleRuleOptions(ruleRow) {
        const selectedType = $(ruleRow).find('.clisyc-rule-type-select').val();
        $(ruleRow).find('.clisyc-rule-option').hide();
        $(ruleRow).find('.clisyc-rule-option[data-rule-type="' + selectedType + '"]').show();
    }

    function initMembershipBuilder() {
        const container = $('#clisyc-membership-rules-container');
        if (!container.length) return;

        // Initial setup for existing rows
        container.find('.clisyc-membership-rule-row').each(function () {
            toggleRuleOptions(this);
        });

        // Event handler for adding a new rule
        $('#clisyc-add-rule-btn').on('click', function () {
            $('#clisyc-no-rules-message').hide();
            const template = $('#clisyc-rule-template').html();
            const newIndex = new Date().getTime(); // Unique index
            const newRow = template.replace(/__INDEX__/g, newIndex);
            container.append(newRow);
            toggleRuleOptions(container.find('.clisyc-membership-rule-row').last());
        });

        // Delegated event handlers for dynamic elements
        container.on('click', '.clisyc-remove-rule-btn', function () {
            $(this).closest('.clisyc-membership-rule-row').remove();
            if (container.find('.clisyc-membership-rule-row').length === 0) {
                $('#clisyc-no-rules-message').show();
            }
        });

        container.on('change', '.clisyc-rule-type-select', function () {
            toggleRuleOptions($(this).closest('.clisyc-membership-rule-row'));
        });
    }

    initMembershipBuilder();

    // ── Starter Plans Banner ──
    $('#clisyc-create-starter-plans-btn').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin" style="margin-top:4px;"></span> ' + 'Creating\u2026');

        $.post(ajaxurl, {
            action: 'clisyc_create_starter_plans',
            nonce: $btn.data('nonce')
        }, function (response) {
            if (response.success) {
                window.location.href = response.data.redirect;
            } else {
                alert(response.data.message || 'Error creating plans.');
                $btn.prop('disabled', false).text('Create Starter Plans');
            }
        }).fail(function () {
            alert('Connection error. Please try again.');
            $btn.prop('disabled', false).text('Create Starter Plans');
        });
    });

    $('#clisyc-dismiss-starter-plans').on('click', function (e) {
        e.preventDefault();
        var $banner = $('#clisyc-starter-plans-banner');

        $.post(ajaxurl, {
            action: 'clisyc_dismiss_starter_plans',
            nonce: $(this).data('nonce')
        });

        $banner.fadeOut(300, function () { $banner.remove(); });
    });
});