/**
 * File: src/assets/js/clisyc-admin-item-calendar.js
 * Renders a read-only availability calendar on the single Dimension Post Edit screen.
 * 
 * FIX: Added removal of "Loading..." placeholder after render.
 * FIX: Fixed eventContent to properly render available/blocked slots (was returning empty domNodes)
 */
document.addEventListener('DOMContentLoaded', function() {
    
    const calendarEl = document.getElementById('clisyc-admin-item-calendar');
    const containerEl = document.getElementById('clisyc-item-availability-calendar');
    
    if (!calendarEl || typeof FullCalendar === 'undefined' || typeof clisycItemCalendarData === 'undefined') {
        return;
    }

    const { postId, postType, restUrl, nonce, editUrlBase, slotMinTime, slotMaxTime } = clisycItemCalendarData;
    
    // Toggle visibility based on booking mode radio buttons
    function handleBookingModeVisibility() {
        const modeRadio = document.querySelector('input[name="clisyc_booking_mode"]:checked');
        // Check if Date Range mode is selected. 
        // Note: You might want to allow this for 'slot' mode too depending on preference,
        // but currently it shows/hides based on date_range as requested.
        if (modeRadio && modeRadio.value === 'date_range') {
            if(containerEl) containerEl.style.display = 'block';
            // Trigger resize to fix layout glitches when unhiding
            if (calendarInstance) setTimeout(() => calendarInstance.updateSize(), 200);
        } else {
            // Uncomment the line below if you want to hide it when NOT in date_range mode
            // if(containerEl) containerEl.style.display = 'none';
        }
    }
    
    // Listen for mode changes (Classic Editor / standard inputs)
    const radios = document.querySelectorAll('input[name="clisyc_booking_mode"]');
    radios.forEach(radio => radio.addEventListener('change', handleBookingModeVisibility));

    // Listen for mode changes (Block Editor / React) via event delegation if radios aren't direct DOM nodes yet
    document.addEventListener('change', function(e) {
        if(e.target && e.target.name === 'clisyc_booking_mode') {
            handleBookingModeVisibility();
        }
    });

    /**
     * Helper: Format a time string for display (e.g., "9:00 AM")
     */
    function formatTime(date) {
        if (!date) return '';
        return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    // Initialize Calendar
    const calendarInstance = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth', // Date range works best in Month view
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listMonth'
        },
        height: 'auto',
        timeZone: 'local',
        
        // Calendar time boundaries from settings
        slotMinTime: slotMinTime || '09:00:00',
        slotMaxTime: slotMaxTime || '17:00:00',
        
        // Fetch events filtered by THIS item
        events: function(fetchInfo, successCallback, failureCallback) {
            // Visual indicator that fetch is happening
            calendarEl.style.opacity = '0.5';
            
            const params = new URLSearchParams({
                start: fetchInfo.start.toISOString(),
                end: fetchInfo.end.toISOString(),
                _wpnonce: nonce,
                // Context determines read/write permissions in backend
                context: 'admin_editable',
                // DYNAMICALLY FILTER: Pass this CPT slug and ID as a filter
                [postType]: postId 
            });

            fetch(`${restUrl}?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    calendarEl.style.opacity = '1';
                    if (data.events) {
                        successCallback(data.events);
                    } else {
                        failureCallback(new Error('No events found'));
                    }
                })
                .catch(error => {
                    calendarEl.style.opacity = '1';
                    failureCallback(error);
                });
        },
        
        // Visual Styling
        eventClassNames: function(arg) {
            if (arg.event.extendedProps.isBooked) return ['clisyc-booked-event'];
            if (arg.event.extendedProps.is_block) return ['clisyc-blocked-slot'];
            return ['clisyc-available-slot'];
        },
        
        // Interaction: Click to Edit Appointment
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            const props = info.event.extendedProps;
            
            if (props.isBooked && props.appointmentId) {
                // Open appointment edit screen in new tab
                window.open(editUrlBase + props.appointmentId, '_blank');
            }
        },

        // Render content for all event types
        eventContent: function(arg) {
            const event = arg.event;
            const props = event.extendedProps || {};
            const title = event.title || '';
            const viewType = arg.view.type;
            
            // --- BOOKED EVENTS ---
            if (props.isBooked) {
                return { 
                    html: `<div class="fc-content">
                        <span class="dashicons dashicons-calendar-alt"></span> ${title}
                    </div>` 
                };
            }
            
            // --- BLOCKED SLOTS ---
            if (props.is_block) {
                const blockTitle = title || 'Blocked';
                return { 
                    html: `<div class="fc-content">
                        <span class="dashicons dashicons-lock"></span> ${blockTitle}
                    </div>` 
                };
            }
            
            // --- AVAILABLE SLOTS ---
            // For month view (dayGrid), show time range if available
            if (viewType === 'dayGridMonth') {
                // Build time range display
                let timeDisplay = '';
                if (event.start) {
                    timeDisplay = formatTime(event.start);
                    if (event.end) {
                        timeDisplay += ' - ' + formatTime(event.end);
                    }
                }
                
                // Use title if provided, otherwise show time or "Available"
                const displayText = title || timeDisplay || 'Available';
                
                return {
                    html: `<div class="fc-content fc-available-content">
                        <span class="fc-event-time">${timeDisplay ? timeDisplay : ''}</span>
                        ${title ? '<span class="fc-event-title">' + title + '</span>' : ''}
                    </div>`
                };
            }
            
            // For week/day time grid views, use FullCalendar's default rendering
            // which shows time and title appropriately in the time slots
            return null; // null tells FullCalendar to use its default rendering
        },

        // Display settings for events
        displayEventTime: true,
        displayEventEnd: true,
        eventTimeFormat: {
            hour: 'numeric',
            minute: '2-digit',
            meridiem: 'short'
        }
    });

    calendarInstance.render();

    // --- FIX: Remove the "Loading..." placeholder now that calendar is rendered ---
    const placeholder = calendarEl.querySelector('p');
    if (placeholder) {
        placeholder.remove();
    }
    
    // Run visibility check on load
    handleBookingModeVisibility();
});