/**
 * File: src/assets/js/clisyc-calendar-admin.js -> client-sync/assets/js/clisyc-calendar-admin.js
 * Initializes the main Admin Appointment Calendar using the REST API.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 */
document.addEventListener('DOMContentLoaded', function() {
    // *** REFACTORED: Updated JS data object name ***
    if (typeof clisycCalendarData === 'undefined' || typeof FullCalendar === 'undefined') {
        return;
    }

    // HTML escape helper to prevent XSS from API data injected into event HTML.
    function escapeHtml(str) {
        if (typeof str !== 'string') return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // *** REFACTORED: Updated reference to the calendar config object ***
    const calendarConfig = clisycCalendarData.calendars[0];
    if (!calendarConfig) return;

    // *** REFACTORED: Updated element ID to match the new prefixed ID in the view file ***
    const calendarEl = document.getElementById('clisyc-admin-appointment-calendar');
    if (!calendarEl) return;

    // Dynamically build the toolbar views from the settings
    const enabledViews = calendarConfig.options.enabledViews || ['dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek'];
    const rightToolbar = enabledViews.length > 0 ? enabledViews.join(',') : 'timeGridWeek'; // Default to week view if none are enabled

    try {
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'timeGridWeek',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: rightToolbar
            },
            navLinks: true,
            nowIndicator: true,
            height: 'auto',
            // CRITICAL TIMEZONE FIX: Remove explicit timeZone option
            // FullCalendar will use browser's local timezone, which is correct for the user
            // The timeZone option requires timezone plugin or causes display issues
            // timeZone: calendarConfig.options.timeZone,
            // Spread the config options first
            ...calendarConfig.options,
            // Remove timeZone from spread to prevent override
            timeZone: 'local',  // Explicitly use browser's local timezone
            // Then explicitly override to ensure calendar is NOT editable
            // CRITICAL: All editability flags must be set AFTER spread to override any config
            editable: false,
            selectable: false,
            selectMirror: false,
            unselectAuto: true,
            eventResizableFromStart: false,
            eventDurationEditable: false,
            eventStartEditable: false,
            eventResourceEditable: false,
            droppable: false,
            
            // Additional event handlers to prevent any editing attempts
            eventAllow: function() {
                return false; // Block all event modifications
            },
            selectAllow: function() {
                return false; // Block all selections
            },

            events: function(fetchInfo, successCallback, failureCallback) {
                calendarEl.classList.add('loading');
                
                // *** REFACTORED: Updated nonce and URL references ***
                // CRITICAL FIX: Changed context from 'admin_editable' to 'admin' for read-only view
                const params = new URLSearchParams({
                    start: fetchInfo.start.toISOString(),
                    end: fetchInfo.end.toISOString(),
                    context: 'admin',
                    _wpnonce: clisycCalendarData.nonce
                });
                
                fetch(`${clisycCalendarData.restUrl}?${params.toString()}`)
                    .then(response => {
                        if (!response.ok) throw new Error('HTTP ' + response.status);
                        return response.json();
                    })
                    .then(data => {
                        calendarEl.classList.remove('loading');
                        if (data && data.events) {
                            const processedEvents = data.events.map(event => {
                                // Force all events to be non-editable
                                event.editable = false;
                                event.startEditable = false;
                                event.durationEditable = false;

                                // Ensure times are proper ISO strings
                                try {
                                    event.start = new Date(event.start).toISOString();
                                    event.end = new Date(event.end).toISOString();
                                } catch (error) {
                                    // Keep original values if conversion fails
                                }

                                return event;
                            });

                            successCallback(processedEvents);
                        } else {
                            failureCallback(new Error(data.message || 'Failed to fetch events.'));
                        }
                    })
                    .catch(error => {
                        calendarEl.classList.remove('loading');
                        failureCallback(error);
                    });
            },

            eventClassNames: function(arg) {
                const props = arg.event.extendedProps;
                // *** REFACTORED: CSS classes updated ***
                if (props.isBooked) return ['clisyc-booked-event'];
                if (props.is_block) return ['clisyc-blocked-slot'];
                return ['clisyc-available-slot'];
            },

            eventContent: function(arg) {
                const props = arg.event.extendedProps || {};
                const timeText = escapeHtml(arg.timeText);
                let title = escapeHtml(arg.event.title || 'Event');
                let dimensionsHtml = '';
                const details = props.serviceDetails || {};

                const detailsToShow = [];

                // --- START: DYNAMIC LOGIC ---
                // *** REFACTORED: Updated data object reference ***
                const dimensionLabels = clisycCalendarData.dimensionLabels || {};

                if (props.isBooked) {
                    title = escapeHtml(arg.event.title); // Already formatted as "Service - Client"

                    for (const key in details) {
                        if (key.endsWith('_name')) {
                            // *** REFACTORED: Updated CPT prefix for slug creation ***
                            const slug = 'clisyc_' + key.replace('_name', '');
                            const label = dimensionLabels[slug] || key.replace('_name', '').replace(/_/g, ' ');
                            // Avoid repeating the primary service name if it's already in the main title
                            if (slug !== 'clisyc_service') {
                                detailsToShow.push(`<div><small>${escapeHtml(label)}: ${escapeHtml(details[key])}</small></div>`);
                            }
                        }
                    }
                } else { // Available or Blocked slots
                    for (const key in details) {
                        if (key.endsWith('_name')) {
                            // *** REFACTORED: Updated CPT prefix for slug creation ***
                            const slug = 'clisyc_' + key.replace('_name', '');
                            const label = dimensionLabels[slug] || key.replace('_name', '').replace(/_/g, ' ');
                            detailsToShow.push(`<div><small>${escapeHtml(label)}: ${escapeHtml(details[key])}</small></div>`);
                        }
                    }

                    if (props.is_block) {
                        title = '<strong>Blocked</strong>';
                    } else {
                        const primaryDimKey = Object.keys(dimensionLabels)[0] || 'service';
                        title = `<strong>${escapeHtml(details[primaryDimKey + '_name'] || 'Available')}</strong>`;
                    }
                }

                dimensionsHtml = `<div class="fc-event-dimensions">${detailsToShow.join('')}</div>`;
                // --- END: DYNAMIC LOGIC ---

                return {
                    html: `
                        <div class="fc-event-main-frame">
                            <div class="fc-event-time">${timeText}</div>
                            <div class="fc-event-title-container">
                                <div class="fc-event-title fc-sticky">${title}</div>
                                ${dimensionsHtml}
                            </div>
                        </div>`
                };
            },

            eventClick: function(info) {
                const props = info.event.extendedProps;
                info.jsEvent.preventDefault();
                
                // *** REFACTORED: Updated data object and query param prefixes ***
                if (props.isBooked && props.appointmentId && clisycCalendarData.config.editUrlBase) {
                    window.location.href = clisycCalendarData.config.editUrlBase + props.appointmentId;
                } else if (!props.isBooked && !props.is_block) {
                    const newApptUrl = new URL(clisycCalendarData.config.addNewApptUrlBase);
                    
                    if (props.slotIdentifier) {
                        newApptUrl.searchParams.set('clisyc_slot', props.slotIdentifier);
                    }
                    
                    if (props.dimensions && typeof props.dimensions === 'object') {
                        for (const key in props.dimensions) {
                            newApptUrl.searchParams.set(`clisyc_dim_${key}`, props.dimensions[key]);
                        }
                    }

                    window.location.href = newApptUrl.toString();
                }
            },

            loading: function(isLoading) {
                calendarEl.style.opacity = isLoading ? 0.6 : 1;
            }
        });

        calendar.render();
        const placeholder = calendarEl.querySelector('p');
        if (placeholder) placeholder.remove();

    } catch (e) {
        console.error('clisyc Admin Calendar: CRITICAL ERROR during FullCalendar initialization:', e);
        if (calendarEl) calendarEl.innerHTML = '<p style="color:red; text-align:center;">Fatal error initializing calendar. Check console.</p>';
    }
});