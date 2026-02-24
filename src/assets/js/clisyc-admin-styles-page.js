/** 
 * File: src/assets/js/clisyc-admin-styles-page.js -> client-sync/assets/js/clisyc-admin-styles-page.js
 * Handles interactivity for the Style & Behavior admin settings page.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 */
jQuery(document).ready(function($) {

    // *** REFACTORED: Updated JS data object name ***
    if (typeof clisycStylesPageData === 'undefined') {
        return;
    }

    // *** REFACTORED: Updated JS data object name ***
    const l10n = clisycStylesPageData.l10n || {};
    const nonce = clisycStylesPageData.nonce || '';

    /**
     * Handle CONVERT_TZ detection reset
     */
    $('#clisyc-reset-detection-link').on('click', function(e) {
        e.preventDefault();

        if (!confirm(l10n.confirmReset)) {
            return;
        }

        const $link = $(this);
        const originalText = $link.text();
        $link.text(l10n.resetting);

        // *** REFACTORED: Updated element ID ***
        const $statusSpan = $('#clisyc-detected-convert-tz-status');
        const originalStatusText = $statusSpan.text();
        $statusSpan.text('...');

        $.post(ajaxurl, {
            // *** REFACTORED: Updated AJAX action name ***
            action: 'clisyc_reset_convert_tz_detection',
            security: nonce
        })
        .done(function(response) {
            if (response.success) {
                $statusSpan.text(response.data.new_status || l10n.unknown);
                // *** REFACTORED: Updated CSS class selector ***
                const noticeHtml = `<div class="notice notice-success is-dismissible"><p>${response.data.message || l10n.statusReset}</p></div>`;
                $('.clisyc-styles-behavior-settings h1').after(noticeHtml);
            } else {
                $statusSpan.text(originalStatusText);
                // *** REFACTORED: Updated CSS class selector ***
                const errorNoticeHtml = `<div class="notice notice-error is-dismissible"><p>Error: ${response.data.message || l10n.couldNotReset}</p></div>`;
                $('.clisyc-styles-behavior-settings h1').after(errorNoticeHtml);
            }
        })
        .fail(function() {
            $statusSpan.text(originalStatusText);
            // *** REFACTORED: Updated CSS class selector ***
            const ajaxErrorNoticeHtml = `<div class="notice notice-error is-dismissible"><p>${l10n.ajaxFailed}</p></div>`;
            $('.clisyc-styles-behavior-settings h1').after(ajaxErrorNoticeHtml);
        })
        .always(function() {
            $link.text(originalText);
            // *** REFACTORED: Updated CSS class selector ***
            $('.clisyc-styles-behavior-settings .notice').delay(5000).fadeOut();
        });
    });

    /**
     * Handle Visibility Audit Scan
     */
    $('#clisyc-run-visibility-check').on('click', function(e) {
        e.preventDefault();
        
        const $btn = $(this);
        const $results = $('#clisyc-visibility-check-results');
        const $spinner = $('#clisyc-visibility-spinner');

        // UI Feedback: Disable button and start spinner
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $results.html('Scanning database...').css('color', '#666');

        $.post(ajaxurl, {
            action: 'clisyc_audit_slot_visibility',
            security: nonce // Use the nonce from the localized data object
        })
        .done(function(response) {
            if (response.success) {
                $results.html(response.data.message);
                // Color code based on status: red for warning/error, green for success/okay
                $results.css('color', response.data.status === 'warning' ? '#d63638' : '#46b450');
            } else {
                $results.html('Error running audit.').css('color', '#d63638');
            }
        })
        .fail(function() {
            $results.html('Network error.').css('color', '#d63638');
        })
        .always(function() {
            // Restore UI state
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });
});