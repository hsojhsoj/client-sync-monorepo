/**
 * File: src/assets/js/clisyc-admin-shortcodes-page.js
 * Handles shortcode copy functionality with fallback for non-HTTPS environments.
 */
jQuery(function ($) {
    'use strict';

    $(document.body).on('click', '.clisyc-copy-shortcode-button', function (e) {
        e.preventDefault();
        const $button = $(this);
        const text = $button.data('shortcode');
        const $originalContent = $button.html();

        // 1. Try modern Clipboard API
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                showSuccess($button, $originalContent);
            }).catch(err => {
                console.error('Clipboard API failed, trying fallback', err);
                fallbackCopy(text, $button, $originalContent);
            });
        } else {
            // 2. Fallback for HTTP/Older Browsers
            fallbackCopy(text, $button, $originalContent);
        }
    });

    function fallbackCopy(text, $btn, originalHtml) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        
        // Ensure textarea is not visible but part of DOM
        textArea.style.position = "fixed";
        textArea.style.left = "-9999px";
        textArea.style.top = "0";
        document.body.appendChild(textArea);
        
        textArea.focus();
        textArea.select();

        try {
            document.execCommand('copy');
            showSuccess($btn, originalHtml);
        } catch (err) {
            alert('Manual copy required for this browser.');
        }

        document.body.removeChild(textArea);
    }

    function showSuccess($btn, originalHtml) {
        $btn.html('<span class="dashicons dashicons-yes-alt"></span> <span class="clisyc-copy-text">Copied!</span>');
        $btn.addClass('copied').prop('disabled', true);

        setTimeout(function () {
            $btn.html(originalHtml);
            $btn.removeClass('copied').prop('disabled', false);
        }, 2000);
    }
});