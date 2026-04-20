/**
 * File: src/assets/src/compact-timeslot-grid/CompactTimeSlotGrid.js
 * A compact grid-based time slot selector.
 * MODIFIED: Overhauled to use a CSS grid layout with a time gutter and a modal for slot selection.
 * UPDATED: Grouping logic changed to hour-based blocks rather than exact start times.
 * UPDATED: Added showBooked and showBlocked options, and slot counting for filters.
 */
import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import apiFetch from '@wordpress/api-fetch';

const CompactTimeSlotGrid = ({
    selectedFilters,
    restUrl,
    restNonce,
    onSlotSelect,
    onSlotDeselect,
    onSlotCountsUpdate,
    selectedSlot,
    dateRange,
    isMobileView,
    filterOrder = [],
    calendarOptions = {},
    l10n = {},
    showBooked = false,
    showBlocked = true
}) => {
    const [slotsByDay, setSlotsByDay] = useState({});
    const [bookedSlotsByDay, setBookedSlotsByDay] = useState({});
    const [isLoading, setIsLoading] = useState(false);
    const [hasFetched, setHasFetched] = useState(false); // Track if initial fetch completed
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [modalData, setModalData] = useState({ time: '', slots: [] });
    const [indicatorTop, setIndicatorTop] = useState(null);
    
    const timeGutterRef = useRef(null);
    const firstDayHeaderRef = useRef(null);
    const firstDaySlotContainerRef = useRef(null);

    const gridStartHour = parseInt(calendarOptions.slotMinTime?.split(':')[0] || '8', 10);
    const gridEndHour = parseInt(calendarOptions.slotMaxTime?.split(':')[0] || '19', 10);
    const totalHours = gridEndHour - gridStartHour;
    const totalMinutes = totalHours * 60;

    const timeLabels = Array.from({ length: totalHours }, (_, i) => {
        const hour = gridStartHour + i;
        return new Date(1970, 0, 1, hour).toLocaleTimeString([], { hour: 'numeric' });
    });

    useEffect(() => {
        const updateIndicator = () => {
            const now = new Date();
            const currentHour = now.getHours();
            const currentMinute = now.getMinutes();
            const minutesFromMidnight = currentHour * 60 + currentMinute;
            const gridStartMinutes = gridStartHour * 60;
            const gridEndMinutes = gridEndHour * 60;

            if (minutesFromMidnight < gridStartMinutes) {
                setIndicatorTop(0);
            } else if (minutesFromMidnight > gridEndMinutes) {
                setIndicatorTop(totalMinutes);
            } else {
                setIndicatorTop(minutesFromMidnight - gridStartMinutes);
            }
        };

        updateIndicator();
        const interval = setInterval(updateIndicator, 60000);
        return () => clearInterval(interval);
    }, [gridStartHour, gridEndHour]);

    const getSlotStyle = (start, end) => {
        const startMinutes = start.getHours() * 60 + start.getMinutes();
        const endMinutes = end.getHours() * 60 + end.getMinutes();
        const gridStartMinutes = gridStartHour * 60;
        const gridEndMinutes = gridEndHour * 60;

        const clampedStart = Math.max(startMinutes, gridStartMinutes);
        const clampedEnd = Math.min(endMinutes, gridEndMinutes);

        const topOffsetMinutes = clampedStart - gridStartMinutes;
        const durationMinutes = Math.max(0, clampedEnd - clampedStart);

        return { top: `${topOffsetMinutes}px`, height: `${durationMinutes}px` };
    };

    // Calculate slot counts for filters whenever slotsByDay changes
    // Only report counts after the initial fetch has completed to avoid false "no availability"
    useEffect(() => {
        if (!onSlotCountsUpdate) return;
        if (!hasFetched) return; // Don't report counts until first fetch completes
        
        const allSlots = Object.values(slotsByDay).flat();
        const availableSlots = allSlots.filter(s => {
            if (s.is_block) return false;
            if (s.isBooked) return false;
            if (s.spotsLeft === 0 || s.spots_left === 0) return false;
            return s.slotIdentifier || s.start || s.timeText;
        });
        
        // Count slots by each filter dimension
        const byFilter = {};
        filterOrder.forEach(filterKey => {
            byFilter[filterKey] = {};
            availableSlots.forEach(slot => {
                const details = slot.serviceDetails || {};
                const jsKey = filterKey.replace('clisyc_', '');
                const dimensionId = details[`${jsKey}_id`]?.toString();
                
                if (dimensionId) {
                    byFilter[filterKey][dimensionId] = (byFilter[filterKey][dimensionId] || 0) + 1;
                }
            });
        });
        
        onSlotCountsUpdate({
            total: availableSlots.length,
            byFilter
        });
        
    }, [slotsByDay, filterOrder, onSlotCountsUpdate, hasFetched]);

    useEffect(() => {
        if (!dateRange.start || !dateRange.end) return;
        const fetchSlots = async () => {
            setIsLoading(true);
            
            try {
                const queryParams = new URLSearchParams({
                    start: dateRange.start.toISOString(),
                    end: dateRange.end.toISOString(),
                    _wpnonce: restNonce,
                });
                
                // Add flag to include booked slots if showBooked is enabled
                if (showBooked) {
                    queryParams.append('include_booked', 'true');
                }
                
                Object.entries(selectedFilters).forEach(([key, value]) => {
                    if (Array.isArray(value)) value.forEach(v => queryParams.append(`${key}[]`, v));
                });
                
                const response = await apiFetch({ path: `/clisyc/v1/slots?${queryParams.toString()}` });
                const organizedSlots = {};
                const organizedBookedSlots = {};
                
                if (response && response.events) {
                    response.events.forEach(event => {
                        const startLocal = new Date(event.start);
                        const dateKey = startLocal.toISOString().split('T')[0];
                        
                        const slotData = {
                            ...event.extendedProps,
                            start: startLocal,
                            end: new Date(event.end),
                            timeText: startLocal.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
                            title: event.title
                        };
                        
                        // Separate booked appointments from available slots
                        if (event.extendedProps?.isBooked) {
                            if (!organizedBookedSlots[dateKey]) organizedBookedSlots[dateKey] = [];
                            organizedBookedSlots[dateKey].push(slotData);
                        } else {
                            if (!organizedSlots[dateKey]) organizedSlots[dateKey] = [];
                            organizedSlots[dateKey].push(slotData);
                        }
                    });
                }
                setSlotsByDay(organizedSlots);
                setBookedSlotsByDay(organizedBookedSlots);
            } catch (error) {
                console.error('Error fetching slots:', error);
            } finally {
                setIsLoading(false);
                setHasFetched(true); // Mark that first fetch has completed
            }
        };
        fetchSlots();
    }, [dateRange, selectedFilters, restNonce, showBooked]);

    const getDatesInRange = () => {
        if (!dateRange.start || !dateRange.end) return [];
        const dates = [];
        const current = new Date(dateRange.start);
        while (current < dateRange.end) {
            dates.push(new Date(current));
            current.setDate(current.getDate() + 1);
        }
        return dates;
    };
    
    const handleSlotGroupClick = (slots) => {
        const validSlots = slots.filter(slot => {
            if (slot.isBooked) return false;
            if (slot.spotsLeft === 0 || slot.spots_left === 0) return false;
            return slot.slotIdentifier || slot.start || slot.timeText;
        });

        if (validSlots.length === 0) {
            return;
        }

        // Save the currently focused element so we can restore it on close.
        previousFocusRef.current = document.activeElement;

        setModalData({ time: validSlots[0].timeText, slots: validSlots });
        setIsModalOpen(true);
    };

    const getFullSlotDescription = (slot) => {
        const details = slot.serviceDetails || {};
        
        const descriptionParts = filterOrder
            .map(slug => {
                const jsKey = slug.replace('clisyc_', '');
                return details[`${jsKey}_name`];
            })
            .filter(Boolean);
        
        if (descriptionParts.length === 0) {
            const anyNames = Object.keys(details)
                .filter(key => key.endsWith('_name') && details[key])
                .map(key => details[key]);
            if (anyNames.length > 0) return anyNames.join(' with ');
            if (slot.timeText) return `Available at ${slot.timeText}`;
            return 'Available Slot';
        }
        
        return descriptionParts.join(' with ');
    };

    const handleModalSlotSelect = (slot) => {
        const fullDescription = getFullSlotDescription(slot);
        const displayTime = `${new Date(slot.start).toLocaleDateString([], {weekday: 'long', month: 'long', day: 'numeric'})} at ${slot.timeText}`;
        onSlotSelect?.({ ...slot, extendedProps: slot, displayTime, fullDescription });
        setIsModalOpen(false);
    };

    const modalRef = useRef(null);
    const previousFocusRef = useRef(null);

    const handleModalClose = useCallback(() => {
        setIsModalOpen(false);
        // Restore focus to the element that opened the modal.
        if (previousFocusRef.current) {
            previousFocusRef.current.focus();
            previousFocusRef.current = null;
        }
    }, []);

    // Focus trap and keyboard handler for the modal.
    useEffect(() => {
        if (!isModalOpen || !modalRef.current) return;

        const modalEl = modalRef.current;
        const focusableSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
        const focusableElements = modalEl.querySelectorAll(focusableSelector);
        const firstFocusable = focusableElements[0];
        const lastFocusable = focusableElements[focusableElements.length - 1];

        // Focus the first focusable element inside the modal.
        if (firstFocusable) firstFocusable.focus();

        const handleKeyDown = (e) => {
            if (e.key === 'Escape') {
                handleModalClose();
                return;
            }
            if (e.key === 'Tab') {
                if (focusableElements.length === 0) {
                    e.preventDefault();
                    return;
                }
                if (e.shiftKey) {
                    if (document.activeElement === firstFocusable) {
                        e.preventDefault();
                        lastFocusable.focus();
                    }
                } else {
                    if (document.activeElement === lastFocusable) {
                        e.preventDefault();
                        firstFocusable.focus();
                    }
                }
            }
        };

        modalEl.addEventListener('keydown', handleKeyDown);
        return () => modalEl.removeEventListener('keydown', handleKeyDown);
    }, [isModalOpen, handleModalClose]);
    
    /**
     * Merge consecutive blocked slots into single visual blocks.
     */
    const mergeConsecutiveBlocks = (blocks) => {
        if (blocks.length === 0) return [];
        
        const sorted = [...blocks].sort((a, b) => new Date(a.start) - new Date(b.start));
        const merged = [];
        let current = sorted[0];
        
        for (let i = 1; i < sorted.length; i++) {
            const next = sorted[i];
            const currentEnd = new Date(current.end);
            const nextStart = new Date(next.start);
            
            const gap = Math.abs(currentEnd.getTime() - nextStart.getTime());
            if (gap <= 60000) { 
                current = {
                    ...current,
                    end: next.end,
                    slotIdentifier: current.slotIdentifier + '+' + next.slotIdentifier
                };
            } else {
                merged.push(current);
                current = next;
            }
        }
        merged.push(current);
        return merged;
    };
    
    const dates = getDatesInRange();

    return (
        <div className="clisyc-compact-timeslot-grid-container">
            {isLoading ? (
                // Same pattern as HybridApp's outer loader — the SkeletonTable
                // collapses to a narrow vertical column in this container
                // because the grid-template-columns `auto-fit` fallback doesn't
                // know the intended column count. Use a standard spinner
                // instead, matching the outer loader so the two loading
                // states feel continuous.
                <div className="clisyc-compact-grid-loading" role="status" aria-label="Loading time slots">
                    <div className="clisyc-spinner" aria-hidden="true"></div>
                    <span className="screen-reader-text">Loading time slots</span>
                </div>
            ) : (
                <div className={`clisyc-compact-grid-layout ${isMobileView ? 'mobile-view' : ''}`}>
                    <div className="clisyc-compact-time-gutter" ref={timeGutterRef} style={{ height: `${totalHours * 60}px` }}>
                        {timeLabels.map(label => <div key={label} className="clisyc-compact-time-label">{label}</div>)}
                    </div>
                    {dates.map((date, index) => {
                        const dateKey = date.toISOString().split('T')[0];
                        const daySlots = slotsByDay[dateKey] || [];
                        const dayBookedSlots = bookedSlotsByDay[dateKey] || [];
                        const isToday = date.toDateString() === new Date().toDateString();

                        return (
                            <div key={dateKey} className="clisyc-compact-grid-day-column">
                                <div 
                                    ref={index === 0 ? firstDayHeaderRef : null}
                                    className={`clisyc-compact-grid-day-header ${isToday ? 'clisyc-compact-today' : ''}`}
                                >
                                    <div className="clisyc-compact-day-name">{date.toLocaleDateString([], { weekday: 'short' }).toUpperCase()}</div>
                                    <div className="clisyc-compact-day-date">{date.toLocaleDateString([], { month: 'numeric', day: 'numeric' })}</div>
                                </div>
                                <div 
                                    ref={index === 0 ? firstDaySlotContainerRef : null}
                                    className="clisyc-compact-grid-day-slots" 
                                    style={{ height: `${totalHours * 60}px` }}
                                >
                                    {isToday && indicatorTop !== null && (
                                        <div className="clisyc-compact-time-indicator" style={{ top: `${indicatorTop}px` }}></div>
                                    )}
                                    
                                    {(() => {
                                        const rawBlockedSlots = daySlots.filter(s => s.is_block);
                                        const blockedSlots = showBlocked ? mergeConsecutiveBlocks(rawBlockedSlots) : [];
                                        const bookableSlots = daySlots.filter(s => !s.is_block);
                                        
                                        const slotsByHour = bookableSlots.reduce((acc, slot) => {
                                            const slotDate = new Date(slot.start);
                                            const hour = slotDate.getHours();
                                            
                                            if (hour < gridStartHour || hour >= gridEndHour) return acc;

                                            if (!acc[hour]) {
                                                const groupStart = new Date(slotDate);
                                                groupStart.setMinutes(0, 0, 0);
                                                const groupEnd = new Date(groupStart);
                                                groupEnd.setHours(groupEnd.getHours() + 1);

                                                acc[hour] = {
                                                    hourLabel: groupStart.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }).replace(':00', ':00'),
                                                    start: groupStart,
                                                    end: groupEnd,
                                                    slots: [],
                                                    hasAvailability: false 
                                                };
                                            }
                                            acc[hour].slots.push(slot);
                                            acc[hour].hasAvailability = true;
                                            return acc;
                                        }, {});

                                        return (
                                            <>
                                                {/* Render blocked slots */}
                                                {blockedSlots.map((slot, idx) => {
                                                    const slotStart = new Date(slot.start);
                                                    const slotEnd = new Date(slot.end);
                                                    const durationMinutes = (slotEnd - slotStart) / (1000 * 60);
                                                    const durationHours = durationMinutes / 60;
                                                    
                                                    if (slotStart.getHours() < gridStartHour || slotStart.getHours() >= gridEndHour) return null;
                                                    
                                                    let durationText = durationHours >= 1 ? `(${durationHours}h)` : `(${durationMinutes}m)`;
                                                    
                                                    return (
                                                        <div
                                                            key={`blocked-${idx}`}
                                                            className="clisyc-compact-slot-item clisyc-compact-slot-blocked"
                                                            style={getSlotStyle(slotStart, slotEnd)}
                                                        >
                                                            <div className="clisyc-compact-slot-inner">
                                                                <span className="clisyc-compact-slot-time">
                                                                    {slotStart.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}
                                                                </span>
                                                                <span className="clisyc-compact-booked-label">
                                                                    {l10n?.blocked || 'Blocked'} {durationText}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                                
                                                {/* Render booked appointments (if showBooked is true) */}
                                                {showBooked && dayBookedSlots.map((slot, idx) => {
                                                    const slotStart = new Date(slot.start);
                                                    const slotEnd = new Date(slot.end);
                                                    const durationMinutes = (slotEnd - slotStart) / (1000 * 60);
                                                    
                                                    if (slotStart.getHours() < gridStartHour || slotStart.getHours() >= gridEndHour) return null;
                                                    
                                                    return (
                                                        <div
                                                            key={`booked-${idx}`}
                                                            className="clisyc-compact-slot-item clisyc-compact-slot-customer-booked"
                                                            style={getSlotStyle(slotStart, slotEnd)}
                                                            title={slot.title || 'Booked Appointment'}
                                                        >
                                                            <div className="clisyc-compact-slot-inner">
                                                                <span className="clisyc-compact-slot-time">
                                                                    {slotStart.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}
                                                                </span>
                                                                <span className="clisyc-compact-booked-label">
                                                                    {l10n?.booked || 'Booked'}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                                
                                                {/* Render available slots by hour */}
                                                {Object.entries(slotsByHour).map(([hourKey, group]) => {
                                                    const availableSlots = group.slots.filter(s => {
                                                        if (s.isBooked) return false;
                                                        if (s.spotsLeft === 0 || s.spots_left === 0) return false;
                                                        return s.slotIdentifier || s.start || s.timeText;
                                                    });
                                                    
                                                    if (availableSlots.length === 0) {
                                                        return (
                                                            <div
                                                                key={hourKey}
                                                                className="clisyc-compact-slot-item clisyc-compact-slot-booked"
                                                                style={getSlotStyle(group.start, group.end)}
                                                            >
                                                                <div className="clisyc-compact-slot-inner">
                                                                    <span className="clisyc-compact-slot-time">
                                                                        {group.hourLabel}
                                                                    </span>
                                                                    <span className="clisyc-compact-booked-label">{l10n?.booked || 'Booked'}</span>
                                                                </div>
                                                            </div>
                                                        );
                                                    }

                                                    const isGroupSelected = selectedSlot && availableSlots.some(s => s.slotIdentifier === selectedSlot.identifier);
                                            
                                                    const uniqueColors = Array.from(new Set(
                                                        availableSlots.flatMap(slot => {
                                                            const details = slot.serviceDetails || {};
                                                            return Object.keys(details)
                                                                .filter(key => key.endsWith('_color') && details[key])
                                                                .map(key => details[key]);
                                                        })
                                                    ));

                                                    return (
                                                        <button 
                                                            key={hourKey} 
                                                            type="button" 
                                                            className={`clisyc-compact-slot-item clisyc-compact-slot-available ${isGroupSelected ? 'clisyc-compact-slot-selected' : ''}`}
                                                            style={getSlotStyle(group.start, group.end)} 
                                                            onClick={() => handleSlotGroupClick(availableSlots)}
                                                        >
                                                            <div className="clisyc-compact-slot-inner">
                                                                <div style={{ display: 'flex', justifyContent: 'space-between', width: '100%', alignItems: 'center' }}>
                                                                    <span className="clisyc-compact-slot-time">
                                                                        {group.hourLabel} 
                                                                    </span>
                                                                    {availableSlots.length > 1 && (
                                                                        <span className="clisyc-compact-more-badge">
                                                                            <span className="dashicons dashicons-plus"></span>
                                                                            {availableSlots.length - 1}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                {uniqueColors.length > 0 && (
                                                                    <div className="clisyc-compact-slot-dots">
                                                                        {uniqueColors.map(color => (
                                                                            <span 
                                                                                key={color} 
                                                                                className="clisyc-compact-slot-dot" 
                                                                                style={{ backgroundColor: color }}
                                                                                title="Resource Indicator"
                                                                            ></span>
                                                                        ))}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </button>
                                                    );
                                                })}
                                            </>
                                        );
                                    })()}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
            {isModalOpen && (
                <div
                    className="clisyc-compact-modal-overlay"
                    onClick={handleModalClose}
                    role="presentation"
                >
                    <div
                        ref={modalRef}
                        className="clisyc-compact-modal-content"
                        role="dialog"
                        aria-modal="true"
                        aria-label={`Select a time slot for ${modalData.time}`}
                        onClick={e => e.stopPropagation()}
                    >
                        <h3 id="clisyc-compact-modal-title">Select an option for {new Date(modalData.slots[0].start).toLocaleDateString([], {weekday: 'long', month: 'long', day: 'numeric'})} at {modalData.time}</h3>
                        <div className="clisyc-compact-modal-slots-list" role="listbox" aria-labelledby="clisyc-compact-modal-title">
                            {modalData.slots.map(slot => {
                                const isSelected = selectedSlot && slot.slotIdentifier === selectedSlot.identifier;
                                return (
                                    <button
                                        key={slot.slotIdentifier}
                                        role="option"
                                        aria-selected={isSelected}
                                        className={`clisyc-compact-time-slot-button ${isSelected ? 'clisyc-compact-slot-selected' : ''}`}
                                        onClick={() => handleModalSlotSelect(slot)}
                                    >
                                        <div className="clisyc-compact-slot-dots">
                                            {Array.from(new Set(Object.keys(slot.serviceDetails || {}).filter(key => key.endsWith('_color') && slot.serviceDetails[key]).map(key => slot.serviceDetails[key]))).map(color => (
                                                <span key={color} className="clisyc-compact-slot-dot" style={{ backgroundColor: color }} aria-hidden="true"></span>
                                            ))}
                                        </div>
                                        <div className="clisyc-compact-filter-label-content">
                                            <span className="clisyc-compact-filter-label-text">{getFullSlotDescription(slot)}</span>
                                            {slot.durationMinutes > 0 && <span className="clisyc-compact-filter-duration">{slot.durationMinutes} min</span>}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                        <button className="clisyc-compact-modal-close" onClick={handleModalClose}>{l10n.close || 'Close'}</button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default CompactTimeSlotGrid;