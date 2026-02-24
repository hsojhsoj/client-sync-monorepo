/**
 * File: src/assets/src/booking-form/components/AnalogClock.js
 * Simple analog clock face with animated hands.
 *
 * Accepts an optional `timezone` prop (IANA string or 'local').
 * Ticks every second via its own internal timer.
 */
import React, { useState, useEffect } from 'react';

const AnalogClock = ({ timezone }) => {
    const [time, setTime] = useState(new Date());

    useEffect(() => {
        const timer = setInterval(() => setTime(new Date()), 1000);
        return () => clearInterval(timer);
    }, []);

    // When a timezone prop is provided, derive hours/minutes in that zone.
    let hours, minutes;
    if (timezone && timezone !== 'local') {
        try {
            const parts = new Intl.DateTimeFormat('en-US', {
                timeZone: timezone,
                hour: 'numeric',
                minute: 'numeric',
                hour12: false,
            }).formatToParts(time);
            hours = parseInt(parts.find(p => p.type === 'hour')?.value || '0', 10) % 12;
            minutes = parseInt(parts.find(p => p.type === 'minute')?.value || '0', 10);
        } catch {
            // Fall back to local time on invalid timezone
            hours = time.getHours() % 12;
            minutes = time.getMinutes();
        }
    } else {
        hours = time.getHours() % 12;
        minutes = time.getMinutes();
    }

    // Calculate rotation angles
    const hourDeg = (hours * 30) + (minutes * 0.5); // 30 deg per hour + adjustment for minutes
    const minuteDeg = minutes * 6; // 6 deg per minute

    return (
        <div className="clisyc-clock-icon-container">
            <div
                className="clisyc-clock-hand clisyc-clock-hand-hour"
                style={{ transform: `rotate(${hourDeg}deg)` }}
            />
            <div
                className="clisyc-clock-hand clisyc-clock-hand-minute"
                style={{ transform: `rotate(${minuteDeg}deg)` }}
            />
            <div className="clisyc-clock-center-dot" />
        </div>
    );
};

export default AnalogClock;
