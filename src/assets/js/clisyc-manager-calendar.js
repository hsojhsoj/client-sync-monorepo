/**
 * File: src/assets/js/clisyc-manager-calendar.js
 * Overhauled to support Timezone Selection, Debugging, and Manual Render Triggers
 * FIXED: Removed custom dropdown dependency, uses native styled select for reliability
 */
document.addEventListener('DOMContentLoaded', function() {
    // --- 1. PREREQUISITE CHECKS ---
    if (typeof FullCalendar === 'undefined' || typeof clisycManagerCalendarData === 'undefined') {
        console.error('[CLISYC Manager] Prerequisites missing (FullCalendar or clisycManagerCalendarData).');
        return;
    }

    const calendarEl = document.getElementById('clisyc-manager-calendar');
    const loader = document.getElementById('clisyc-mgmt-loader');
    
    if (!calendarEl) {
        console.error('[CLISYC Manager] Container #clisyc-manager-calendar not found.');
        return;
    }

    const tzSelector = document.getElementById('clisyc-manager-timezone-selector');
    const clockEl = document.getElementById('clisyc-manager-live-clock');
    const tzNoteEl = document.getElementById('clisyc-manager-timezone-note');
    const tzLabelEl = document.getElementById('clisyc-active-tz-label');

    let liveClockInterval = null;

    // --- 2. CLOCK & TZ UTILS ---
    const updateLiveClock = (timezone) => {
        if (!clockEl) return;
        if (liveClockInterval) clearInterval(liveClockInterval);
        
        const update = () => {
            try {
                const now = new Date();
                const options = {
                    timeZone: timezone === 'local' ? undefined : timezone,
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit', 
                    hour12: true
                };
                clockEl.textContent = now.toLocaleTimeString('en-US', options);
                
                // Update the region label
                if (tzLabelEl) {
                    const partsOptions = { ...options, timeZoneName: 'short' };
                    const parts = new Intl.DateTimeFormat('en-US', partsOptions).formatToParts(now);
                    const tzName = parts.find(p => p.type === 'timeZoneName')?.value || 'Local';
                    tzLabelEl.textContent = `Region: ${tzName}`;
                }
            } catch (e) { 
                clockEl.textContent = 'Invalid TZ';
                console.warn('[CLISYC Manager] Clock update error:', e);
            }
        };
        update();
        liveClockInterval = setInterval(update, 1000);
    };

    const updateTzNote = (tz) => {
        if (!tzNoteEl) return;
        const { l10n } = clisycManagerCalendarData;
        if (!l10n) return;
        
        if (tz === 'local') {
            tzNoteEl.textContent = l10n.tzNoteLocal;
        } else {
            const tzDisplay = tz.replace(/_/g, ' ').replace('America/', '').replace('Europe/', '');
            tzNoteEl.textContent = l10n.tzNoteSelected.replace('%s', tzDisplay);
        }
    };

    // --- 3. CALENDAR INIT ---
    const views = clisycManagerCalendarData.enabledViews;
    const headerRight = (Array.isArray(views) && views.length > 0) 
        ? views.join(',') 
        : 'timeGridWeek,dayGridMonth';

    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: headerRight
        },
        height: 'auto',
        nowIndicator: true,
        timeZone: 'local',
        
        // Remove the loader once the first set of dates/events is rendered
        datesSet: function() {
            if (loader) {
                loader.remove();
            }
        },

        events: function(fetchInfo, successCallback, failureCallback) {
            const params = new URLSearchParams({
                action: 'clisyc_get_calendar_events',
                security: clisycManagerCalendarData.nonce,
                start: fetchInfo.start.toISOString(),
                end: fetchInfo.end.toISOString(),
                view_context: 'all_booked',
            });

            fetch(`${clisycManagerCalendarData.ajaxurl}?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data.events) {
                        successCallback(data.data.events);
                    } else {
                        console.error('[CLISYC Manager] API Error:', data);
                        successCallback([]); 
                    }
                })
                .catch(err => {
                    console.error('[CLISYC Manager] Fetch Exception:', err);
                    failureCallback(err);
                });
        },

        eventClick: function(info) {
            const props = info.event.extendedProps;
            if (props.appointmentId && clisycManagerCalendarData.viewUrl) {
                const url = new URL(clisycManagerCalendarData.viewUrl);
                url.searchParams.set('view_id', props.appointmentId);
                window.location.href = url.toString();
            }
        },

        loading: function(isLoading) {
            calendarEl.style.opacity = isLoading ? 0.6 : 1;
        }
    });

    calendar.render();

    // --- 4. BIND TIMEZONE SELECTOR (Native Select - No Custom Plugin) ---
    if (tzSelector) {
        // Ensure the select is visible and properly styled
        tzSelector.style.display = 'block';
        tzSelector.style.visibility = 'visible';
        
        // Handle timezone changes
        tzSelector.addEventListener('change', function(e) {
            const value = e.target.value;
            // Update FullCalendar timezone
            calendar.setOption('timeZone', value);
            
            // Update note and clock display
            updateTzNote(value);
            updateLiveClock(value);
        });

        // Optional: Add visual feedback for select wrapper
        const selectWrapper = tzSelector.closest('.clisyc-select-wrapper');
        if (selectWrapper) {
            tzSelector.addEventListener('focus', () => selectWrapper.classList.add('is-focused'));
            tzSelector.addEventListener('blur', () => selectWrapper.classList.remove('is-focused'));
        }
    }

    // --- 5. CLEANUP: Remove any accidentally injected list elements ---
    // Some custom dropdown plugins inject UL/LI elements; clean them up
    const cleanupDropdownArtifacts = () => {
        const wrapper = document.getElementById('clisyc-manager-dashboard-wrapper');
        if (wrapper) {
            const orphanedLists = wrapper.querySelectorAll('.clisyc-select-wrapper ul, .clisyc-timezone-selector-group ul');
            orphanedLists.forEach(list => {
                list.remove();
            });
        }
    };
    
    // Run cleanup after a short delay to catch any late-injected elements
    setTimeout(cleanupDropdownArtifacts, 100);

    // --- 6. INITIALIZE DISPLAY STATE ---
    updateLiveClock('local');
    updateTzNote('local');

    // --- 7. PAGE TEARDOWN ---
    // Clear the live-clock interval when navigating away to prevent leaked timers.
    window.addEventListener('pagehide', function() {
        if (liveClockInterval) {
            clearInterval(liveClockInterval);
            liveClockInterval = null;
        }
    });

});