/**
 * File: src/assets/js/clisyc-admin-master-schedule.js
 * Initializes the Master Schedule viewer on the Calendars admin page.
 */
document.addEventListener('DOMContentLoaded', function() {
    if (typeof FullCalendar === 'undefined' || typeof clisycMasterScheduleData === 'undefined') {
        console.error('Client Sync Master Schedule: Missing FullCalendar or localized data.');
        return;
    }

    const { ajaxurl, nonce, l10n, enabledViews } = clisycMasterScheduleData;
    const calendarEl = document.getElementById('clisyc-master-schedule-calendar');
    if (!calendarEl) return;

    // Dynamically build the toolbar views from the settings
    const defaultViews = ['dayGridMonth', 'timeGridWeek', 'timeGridDay'];
    const rightToolbar = enabledViews && enabledViews.length > 0 ? enabledViews.join(',') : defaultViews.join(',');

    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: rightToolbar
        },
        nowIndicator: true,
        editable: false,
        height: 'auto',
        allDaySlot: false,
        timeZone: 'local', // Display in the user's browser timezone
        events: function(fetchInfo, successCallback, failureCallback) {
            calendarEl.classList.add('loading');

            const params = new URLSearchParams({
                action: 'clisyc_get_master_schedule_data',
                security: nonce,
                start: fetchInfo.start.toISOString(),
                end: fetchInfo.end.toISOString()
            });

            fetch(`${ajaxurl}?${params.toString()}`)
                .then(response => {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(data => {
                    calendarEl.classList.remove('loading');
                    if (data.success && data.data.events) {
                        successCallback(data.data.events);
                    } else {
                        failureCallback(new Error(data.data.message || 'Failed to fetch master schedule events.'));
                    }
                })
                .catch(error => {
                    calendarEl.classList.remove('loading');
                    failureCallback(error);
                });
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault(); // Don't follow the href
            const props = info.event.extendedProps;
            if (props.isTemplate && props.editUrl) {
                window.open(props.editUrl, '_blank');
            } else if (props.appointmentId) {
                window.open(clisycMasterScheduleData.editUrlBase + props.appointmentId, '_blank');
            }
        },
        eventDidMount: function(info) {
            // Add tooltips — escape event title to prevent XSS.
            if (typeof jQuery.fn.tooltip !== 'undefined') {
                const $el = jQuery(info.el);
                const safeTitle = jQuery('<span>').text(info.event.title).html();
                let tooltipContent = safeTitle;
                if (info.event.extendedProps.isTemplate) {
                    tooltipContent += '<br><small>' + (l10n.clickToEdit || 'Click to edit source') + '</small>';
                }
                $el.tooltip({
                    content: tooltipContent,
                    track: true,
                    classes: { "ui-tooltip": "clisyc-calendar-tooltip" }
                });
            }
        },
        loading: function(isLoading) {
            calendarEl.style.opacity = isLoading ? 0.6 : 1;
        }
    });

    calendar.render();

    // Remove the "Loading..." placeholder text once the calendar is rendered.
    const placeholder = calendarEl.querySelector('p');
    if (placeholder) {
        placeholder.remove();
    }
});