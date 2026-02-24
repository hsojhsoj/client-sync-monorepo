/**
 * File: src/assets/js/clisyc-admin-booking-mode-toggle.js
 * Handles the show/hide logic for schedule meta boxes based on Booking Mode.
 */
(function($) {
    'use strict';

    function initializeScheduleToggles() {
        const $radios = $('input[name="clisyc_booking_mode"]');
        if (!$radios.length) return;

        const $scheduleMetaBoxes = $('#clisyc-service-schedule-metabox, #clisyc-schedule-pattern-metabox, #clisyc-schedule-overrides-metabox');
        const $timeSlotSettings = $('.clisyc-time-slot-setting');
        const $dateRangeSettings = $('.clisyc-date-range-setting');

        function toggleVisibility() {
            const mode = $('input[name="clisyc_booking_mode"]:checked').val();
            
            if (mode === 'date_range') {
                $scheduleMetaBoxes.closest('.postbox').hide();
                $timeSlotSettings.hide();
                $dateRangeSettings.show();
            } else {
                $scheduleMetaBoxes.closest('.postbox').show();
                $timeSlotSettings.show();
                $dateRangeSettings.hide();
            }
        }

        $radios.on('change', toggleVisibility);
        toggleVisibility(); // Run once on load
    }

    // Use MutationObserver to detect when the editor is ready
    $(document).ready(function() {
        // Try immediately
        initializeScheduleToggles();

        // If radios aren't there yet (Gutenberg), observe the DOM
        const observer = new MutationObserver(function(mutations) {
            if ($('input[name="clisyc_booking_mode"]').length > 0) {
                initializeScheduleToggles();
                observer.disconnect(); // Stop observing once initialized
            }
        });

        const editorContainer = document.querySelector('.interface-interface-skeleton__content') || document.body;
        if (editorContainer) {
            observer.observe(editorContainer, { childList: true, subtree: true });
        }
    });

})(jQuery);