/**
 * File: src/assets/js/clisyc-admin-schedule-overrides.js -> client-sync/assets/js/clisyc-admin-schedule-overrides.js
 * Manages the date-specific schedule overrides meta box on the primary dimension's edit screen.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 */
jQuery(function($) {
    'use strict';

    // *** REFACTORED: Updated JS data object name ***
    if (typeof clisycScheduleOverridesData === 'undefined') {
        return;
    }

    // *** REFACTORED: Updated JS data object name ***
    const l10n = clisycScheduleOverridesData.l10n || {};
    let calendarInstance = null;
    let scheduleOverrides = {};
    let selectedDate = '';

    // *** REFACTORED: Updated all element IDs ***
    const $datepicker = $('#clisyc-override-datepicker');
    const $jsonTextarea = $('#clisyc_schedule_overrides_json');
    const $dialog = $('#clisyc-override-dialog');
    const $dialogDate = $('#clisyc-override-dialog-date');
    const $customHoursEditor = $('#clisyc-override-custom-hours-editor');
    const $overrideCalendarContainer = $('#clisyc-override-calendar-instance');

    /**
     * Load initial override data from the hidden textarea.
     */
    function loadOverrides() {
        try {
            const data = JSON.parse($jsonTextarea.val());
            scheduleOverrides = ($.isPlainObject(data)) ? data : {};
        } catch (e) {
            scheduleOverrides = {};
        }
    }

    /**
     * Highlights dates on the datepicker that have an override.
     */
    function highlightDays(date) {
        const dateString = $.datepicker.formatDate('yy-mm-dd', date);
        const hasOverride = scheduleOverrides[dateString] && scheduleOverrides[dateString].type !== 'default';
        // *** REFACTORED: Updated CSS class name ***
        const cssClass = hasOverride ? 'has-clisyc-schedule-override' : '';
        const tooltip = hasOverride ? `Override: ${scheduleOverrides[dateString].type}` : '';
        return [true, cssClass, tooltip];
    }

    /**
     * Opens the dialog to edit an override for a specific date.
     */
    function openOverrideDialog(dateString) {
        selectedDate = dateString;
        const override = scheduleOverrides[selectedDate] || { type: 'default' };

        $dialogDate.text(dateString);
        $('input[name="override_type"]').prop('checked', false);
        $('#override_type_' + override.type).prop('checked', true).trigger('change');

        if (calendarInstance) {
            calendarInstance.destroy();
            calendarInstance = null;
        }

        if (override.type === 'custom') {
            initCustomHoursCalendar(override.slots || []);
        }

        $dialog.dialog('open');
    }

    /**
     * Initializes the FullCalendar instance inside the dialog.
     */
    function initCustomHoursCalendar(slots = []) {
        const calendarEl = $overrideCalendarContainer[0];
        $(calendarEl).html(''); // Clear loading message

        const calendarEvents = slots.map(slot => ({
            // *** REFACTORED: Updated JS data object name ***
            start: clisycScheduleOverridesData.dummyDate + 'T' + slot.start + ':00',
            end: clisycScheduleOverridesData.dummyDate + 'T' + slot.end + ':00',
            // *** REFACTORED: Updated CSS class name ***
            classNames: ['clisyc-standard-slot']
        }));

        calendarInstance = new FullCalendar.Calendar(calendarEl, {
            // *** REFACTORED: Updated JS data object name ***
            ...clisycScheduleOverridesData.calendarOptions,
            events: calendarEvents,
            selectable: true,
            editable: true,
            select: (info) => {
                // *** REFACTORED: Updated CSS class name ***
                calendarInstance.addEvent({
                    start: info.startStr, end: info.endStr, classNames: ['clisyc-standard-slot']
                });
                calendarInstance.unselect();
            },
            eventContent: (arg) => {
                const startIso = arg.event.startStr;
                return { html: `<div class="fc-event-main-frame"><div class="fc-event-time">${arg.timeText}</div><span class="clisyc-delete-event" data-start="${startIso}" title="${l10n.confirmDelete || 'Delete'}">×</span></div>` };
            },
        });

        // Use event delegation on the container so a single listener handles
        // all delete-event clicks, avoiding listener accumulation from eventDidMount.
        $(calendarEl).off('click.clisycDelete').on('click.clisycDelete', '.clisyc-delete-event', function (e) {
            e.stopPropagation();
            if (confirm(l10n.confirmDelete || 'Are you sure?')) {
                const startIso = $(this).data('start');
                if (calendarInstance && startIso) {
                    const fcEvent = calendarInstance.getEvents().find(ev => ev.startStr === startIso);
                    if (fcEvent) fcEvent.remove();
                }
            }
        });

        setTimeout(() => calendarInstance.render(), 50);
    }

    /**
     * Saves the override from the dialog back to the main JSON object and textarea.
     */
    function saveOverride() {
        const type = $('input[name="override_type"]:checked').val();
        if (type === 'default') {
            delete scheduleOverrides[selectedDate];
        } else {
            scheduleOverrides[selectedDate] = { type: type };
            if (type === 'custom' && calendarInstance) {
                const slots = calendarInstance.getEvents().map(event => ({
                    start: event.start.toTimeString().substring(0, 5),
                    end: event.end.toTimeString().substring(0, 5)
                })).sort((a, b) => a.start.localeCompare(b.start));
                scheduleOverrides[selectedDate].slots = slots;
            }
        }
        $jsonTextarea.val(JSON.stringify(scheduleOverrides)).trigger('change');
        $datepicker.datepicker('refresh');
    }

    // --- Initial Setup ---
    if ($datepicker.length) {
        loadOverrides();

        $datepicker.datepicker({
            dateFormat: 'yy-mm-dd',
            beforeShowDay: highlightDays,
            onSelect: openOverrideDialog
        });

        $dialog.dialog({
            autoOpen: false,
            modal: true,
            width: 'auto',
            buttons: {
                "Save Override": () => {
                    saveOverride();
                    $dialog.dialog("close");
                },
                "Cancel": () => $dialog.dialog("close")
            },
            close: () => {
                if (calendarInstance) {
                    calendarInstance.destroy();
                    calendarInstance = null;
                }
            }
        });

        $('input[name="override_type"]').on('change', function() {
            if ($(this).val() === 'custom') {
                $customHoursEditor.slideDown(200, function() {
                    initCustomHoursCalendar(scheduleOverrides[selectedDate]?.slots || []);
                });
            } else {
                $customHoursEditor.slideUp(200);
                if (calendarInstance) {
                    calendarInstance.destroy();
                    calendarInstance = null;
                }
            }
        });
    }
});