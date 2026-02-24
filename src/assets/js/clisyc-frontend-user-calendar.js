/**
 * File: src/assets/js/clisyc-frontend-user-calendar.js
 * Handles the "My Appointments" calendar.
 * Supports: 
 * 1. Main Calendar (Large view with full toolbar)
 * 2. Mini Calendar (Widget view with list details)
 */
document.addEventListener('DOMContentLoaded', function() {
    if (typeof FullCalendar === 'undefined' || typeof clisycUserCalendarData === 'undefined') return;

    // HTML escape helper to prevent XSS from API data.
    function escapeHtml(str) {
        if (typeof str !== 'string') return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    const mainEl = document.getElementById(clisycUserCalendarData.mainElementId);
    const miniEl = document.getElementById(clisycUserCalendarData.miniElementId);

    // ---------------------------------------------------------
    // 1. INITIALIZE MAIN CALENDAR (Existing Logic)
    // ---------------------------------------------------------
    if (mainEl) {
        // Use configured views or defaults
        const enabledViews = clisycUserCalendarData.enabledViews || ['dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek'];
        const rightToolbar = enabledViews.length > 0 ? enabledViews.join(',') : 'dayGridMonth';

        const calendar = new FullCalendar.Calendar(mainEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: rightToolbar
            },
            height: 'auto',
            timeZone: 'local', // Critical: Convert server UTC to browser local time
            
            // Shared Fetch Function
            events: fetchEventsSource, 
            
            eventClick: function(info) {
                if (info.event.extendedProps && info.event.extendedProps.viewUrl) {
                    window.location.href = info.event.extendedProps.viewUrl;
                }
            },
            eventDidMount: function(info) {
                info.el.classList.add('clisyc-booked-event');
                if (info.event.extendedProps.viewUrl) {
                    info.el.style.cursor = 'pointer';
                }
            },
            loading: function(isLoading) {
                mainEl.style.opacity = isLoading ? 0.6 : 1;
            }
        });
        
        calendar.render();

        // Remove initial loading text if present
        const placeholder = mainEl.querySelector('p');
        if (placeholder) placeholder.remove();
        
        // Bind Timezone Selector if present
        const timezoneSelect = document.getElementById('clisyc-user-calendar-timezone');
        if (timezoneSelect && jQuery && jQuery.fn.clisycCustomDropdown) {
            jQuery(timezoneSelect).clisycCustomDropdown({
                onChange: function(newTimezone) { calendar.setOption('timeZone', newTimezone); }
            });
        }
    }

    // ---------------------------------------------------------
    // 2. INITIALIZE MINI CALENDAR (New Logic)
    // ---------------------------------------------------------
    if (miniEl) {
        const detailsContainer = document.getElementById('clisyc-mini-calendar-details');
        
        const miniCalendar = new FullCalendar.Calendar(miniEl, {
            initialView: 'dayGridMonth',
            // Minimal Toolbar: Just arrows and title
            headerToolbar: {
                left: 'prev',
                center: 'title',
                right: 'next'
            },
            // Narrow days (M, T, W) instead of (Mon, Tue, Wed)
            dayHeaderFormat: { weekday: 'narrow' },
            height: 'auto',
            contentHeight: 'auto',
            timeZone: 'local',
            fixedWeekCount: false, // Don't force 6 rows, saves vertical space
            
            // Shared fetch source
            events: fetchEventsSource,

            // VISUALS: Show dots instead of bars
            eventDisplay: 'list-item', 
            displayEventTime: false, // Hide time in the dot view

            dateClick: function(info) {
                renderMiniDetails(info.date, miniCalendar.getEvents(), detailsContainer);
                
                // Visual feedback for selection
                document.querySelectorAll('.fc-daygrid-day').forEach(el => el.classList.remove('clisyc-day-selected'));
                info.dayEl.classList.add('clisyc-day-selected');
            },

            eventClick: function(info) {
                // If they click the dot directly, treat it like clicking the date
                renderMiniDetails(info.event.start, miniCalendar.getEvents(), detailsContainer);
            },

            loading: function(isLoading) {
                miniEl.style.opacity = isLoading ? 0.6 : 1;
            }
        });

        miniCalendar.render();

        // Remove initial loading text if present
        const placeholderMini = miniEl.querySelector('p');
        if (placeholderMini) placeholderMini.remove();
    }

    // ---------------------------------------------------------
    // SHARED HELPER FUNCTIONS
    // ---------------------------------------------------------
    
    function fetchEventsSource(fetchInfo, successCallback, failureCallback) {
        const params = new URLSearchParams({
            action: 'clisyc_get_calendar_events',
            security: clisycUserCalendarData.nonce,
            start: fetchInfo.start.toISOString(),
            end: fetchInfo.end.toISOString(),
            view_context: 'user_only',
        });

        // Add loading state classes manually if needed
        if (mainEl) mainEl.classList.add('loading');
        if (miniEl) miniEl.classList.add('loading');

        fetch(`${clisycUserCalendarData.ajaxurl}?${params.toString()}`)
            .then(response => {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(data => {
                if (mainEl) mainEl.classList.remove('loading');
                if (miniEl) miniEl.classList.remove('loading');

                if (data.success && data.data && data.data.events) {
                    successCallback(data.data.events);
                } else {
                    failureCallback(new Error('Failed.'));
                }
            })
            .catch(error => {
                if (mainEl) mainEl.classList.remove('loading');
                if (miniEl) miniEl.classList.remove('loading');
                failureCallback(error);
            });
    }

    function renderMiniDetails(date, allEvents, container) {
        if (!container) return;

        const selectedDateStr = date.toDateString();
        // Filter events for this day
        const dayEvents = allEvents.filter(e => {
            const eventDate = e.start; // FullCalendar events are Date objects here
            return eventDate.toDateString() === selectedDateStr;
        });

        // Safe fallback for localization
        const l10n = clisycUserCalendarData.l10n || { appointmentsFor: 'Appointments for', noEvents: 'No appointments found.' };

        const dateFormatter = new Intl.DateTimeFormat('default', { month: 'long', day: 'numeric', weekday: 'long' });
        let html = `<h4>${l10n.appointmentsFor} ${dateFormatter.format(date)}</h4>`;

        if (dayEvents.length === 0) {
            html += `<p class="clisyc-mini-empty">${escapeHtml(l10n.noEvents)}</p>`;
        } else {
            html += `<ul class="clisyc-mini-event-list">`;
            dayEvents.forEach(e => {
                // Format time
                const timeStr = e.start.toLocaleTimeString([], {hour: 'numeric', minute:'2-digit'});
                const rawUrl = e.extendedProps?.viewUrl || '#';
                // Validate URL protocol to prevent javascript: injection
                const url = (rawUrl.startsWith('http') || rawUrl.startsWith('/') || rawUrl === '#') ? rawUrl : '#';
                html += `
                    <li>
                        <a href="${escapeHtml(url)}" class="clisyc-mini-event-link">
                            <span class="clisyc-mini-time">${escapeHtml(timeStr)}</span>
                            <span class="clisyc-mini-title">${escapeHtml(e.title)}</span>
                        </a>
                    </li>`;
            });
            html += `</ul>`;
        }

        container.innerHTML = html;
    }
});