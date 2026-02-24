/**
 * File: src/assets/src/booking-form/components/MiniCalendar.js
 * Google Calendar-style mini month picker for quick date navigation.
 *
 * Props: currentDate, onDateSelect
 */
import React, { useState, useEffect, useMemo, useCallback } from 'react';

const MiniCalendar = React.memo(({ currentDate, onDateSelect }) => {
    const [displayMonth, setDisplayMonth] = useState(() => {
        const d = currentDate ? new Date(currentDate) : new Date();
        return new Date(d.getFullYear(), d.getMonth(), 1);
    });

    // Update display month when currentDate changes significantly
    useEffect(() => {
        if (currentDate) {
            const current = new Date(currentDate);
            const displayed = displayMonth;
            if (current.getMonth() !== displayed.getMonth() ||
                current.getFullYear() !== displayed.getFullYear()) {
                setDisplayMonth(new Date(current.getFullYear(), current.getMonth(), 1));
            }
        }
    }, [currentDate]);

    const goToPrevMonth = () => {
        setDisplayMonth(prev => new Date(prev.getFullYear(), prev.getMonth() - 1, 1));
    };

    const goToNextMonth = () => {
        setDisplayMonth(prev => new Date(prev.getFullYear(), prev.getMonth() + 1, 1));
    };

    const generateCalendarDays = () => {
        const year = displayMonth.getFullYear();
        const month = displayMonth.getMonth();

        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const startDayOfWeek = firstDay.getDay();
        const daysInMonth = lastDay.getDate();

        const days = [];

        const prevMonthLastDay = new Date(year, month, 0).getDate();
        for (let i = startDayOfWeek - 1; i >= 0; i--) {
            days.push({
                date: new Date(year, month - 1, prevMonthLastDay - i),
                isCurrentMonth: false,
                day: prevMonthLastDay - i
            });
        }

        for (let day = 1; day <= daysInMonth; day++) {
            days.push({
                date: new Date(year, month, day),
                isCurrentMonth: true,
                day
            });
        }

        const remainingDays = 42 - days.length;
        for (let day = 1; day <= remainingDays; day++) {
            days.push({
                date: new Date(year, month + 1, day),
                isCurrentMonth: false,
                day
            });
        }

        return days;
    };

    // Memoize calendar day generation to avoid recalculation on every render.
    const days = useMemo(() => generateCalendarDays(), [displayMonth]);

    const today = useMemo(() => {
        const d = new Date();
        d.setHours(0, 0, 0, 0);
        return d;
    }, []);

    const selectedDate = useMemo(() => {
        if (!currentDate) return null;
        const d = new Date(currentDate);
        d.setHours(0, 0, 0, 0);
        return d;
    }, [currentDate]);

    const monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];

    const dayNames = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

    const handleDayClick = useCallback((dayInfo) => {
        if (onDateSelect) {
            onDateSelect(dayInfo.date);
        }
        if (!dayInfo.isCurrentMonth) {
            setDisplayMonth(new Date(dayInfo.date.getFullYear(), dayInfo.date.getMonth(), 1));
        }
    }, [onDateSelect]);

    return (
        <div className="clisyc-mini-calendar">
            <div className="clisyc-mini-cal-header">
                <button
                    type="button"
                    className="clisyc-mini-cal-nav"
                    onClick={goToPrevMonth}
                    aria-label="Previous month"
                >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </button>
                <span className="clisyc-mini-cal-title">
                    {monthNames[displayMonth.getMonth()]} {displayMonth.getFullYear()}
                </span>
                <button
                    type="button"
                    className="clisyc-mini-cal-nav"
                    onClick={goToNextMonth}
                    aria-label="Next month"
                >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            </div>

            <div className="clisyc-mini-cal-weekdays">
                {dayNames.map((name, i) => (
                    <span key={i} className="clisyc-mini-cal-weekday">{name}</span>
                ))}
            </div>

            <div className="clisyc-mini-cal-grid">
                {days.map((dayInfo, index) => {
                    const dayDate = new Date(dayInfo.date);
                    dayDate.setHours(0, 0, 0, 0);

                    const isToday = dayDate.getTime() === today.getTime();
                    const isSelected = selectedDate && dayDate.getTime() === selectedDate.getTime();

                    return (
                        <button
                            key={index}
                            type="button"
                            className={`clisyc-mini-cal-day ${!dayInfo.isCurrentMonth ? 'clisyc-mini-cal-day--other' : ''} ${isToday ? 'clisyc-mini-cal-day--today' : ''} ${isSelected ? 'clisyc-mini-cal-day--selected' : ''}`}
                            onClick={() => handleDayClick(dayInfo)}
                        >
                            {dayInfo.day}
                        </button>
                    );
                })}
            </div>
        </div>
    );
});

MiniCalendar.displayName = 'MiniCalendar';

export default MiniCalendar;
