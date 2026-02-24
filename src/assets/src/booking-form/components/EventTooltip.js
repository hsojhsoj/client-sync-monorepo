/**
 * File: src/assets/src/booking-form/components/EventTooltip.js
 * Floating tooltip shown on event hover in the calendar.
 *
 * Props: visible, x, y, content
 */
import React from 'react';

const EventTooltip = React.memo(({ visible, x, y, content }) => {
    if (!visible || !content) return null;

    // Adjust Y position to not go off screen
    const adjustedY = Math.max(10, Math.min(y - 60, window.innerHeight - 180));

    return (
        <div
            className="clisyc-event-tooltip"
            style={{
                left: `${x}px`,
                top: `${adjustedY}px`,
                opacity: visible ? 1 : 0,
                visibility: visible ? 'visible' : 'hidden'
            }}
        >
            <div className="clisyc-event-tooltip-arrow" />
            <div className="clisyc-event-tooltip-content">
                {/* Service/Title */}
                {content.title && (
                    <div className="clisyc-event-tooltip-title">
                        {content.service && (
                            <span
                                className="clisyc-event-tooltip-dot"
                                style={{ backgroundColor: content.serviceColor }}
                            />
                        )}
                        {content.title}
                    </div>
                )}

                {/* Practitioner */}
                {content.practitioner && (
                    <div className="clisyc-event-tooltip-row">
                        <span
                            className="clisyc-event-tooltip-dot"
                            style={{ backgroundColor: content.practitionerColor }}
                        />
                        <span className="clisyc-event-tooltip-label">{content.practitioner}</span>
                    </div>
                )}

                {/* Room */}
                {content.room && (
                    <div className="clisyc-event-tooltip-row">
                        <span
                            className="clisyc-event-tooltip-dot"
                            style={{ backgroundColor: content.roomColor }}
                        />
                        <span className="clisyc-event-tooltip-label">{content.room}</span>
                    </div>
                )}

                {/* Time */}
                {content.time && (
                    <div className="clisyc-event-tooltip-time">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                        {content.time}
                    </div>
                )}
            </div>
        </div>
    );
});

EventTooltip.displayName = 'EventTooltip';

export default EventTooltip;
