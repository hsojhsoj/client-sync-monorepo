/**
 * File: src/assets/src/booking-form/RecurringOptions.js
 * Recurring series toggle and options for the BookingModal.
 * Only rendered when Pro is active and licensed.
 */
import React from 'react';

const RecurringOptions = ({
    isRecurring,
    onToggle,
    frequency,
    onFrequencyChange,
    count,
    onCountChange,
}) => {
    return (
        <div className="clisyc-recurring-section" style={{ marginTop: '1rem', borderTop: '1px solid #eee', paddingTop: '1rem' }}>
            <label className="clisyc-recurring-toggle-label" style={{
                display: 'flex',
                alignItems: 'center',
                gap: '0.5rem',
                cursor: 'pointer',
                fontSize: '0.9rem',
                fontWeight: 500,
            }}>
                <input
                    type="checkbox"
                    checked={isRecurring}
                    onChange={(e) => onToggle(e.target.checked)}
                    style={{ margin: 0 }}
                />
                Book a recurring series?
            </label>

            {isRecurring && (
                <div className="clisyc-recurring-options" style={{
                    marginTop: '0.75rem',
                    padding: '0.75rem',
                    background: '#f7f7f7',
                    borderRadius: '6px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '0.5rem',
                    flexWrap: 'wrap',
                    fontSize: '0.875rem',
                }}>
                    <span>Repeat</span>
                    <select
                        value={frequency}
                        onChange={(e) => onFrequencyChange(e.target.value)}
                        style={{
                            padding: '0.35rem 0.5rem',
                            borderRadius: '4px',
                            border: '1px solid #d1d5db',
                            fontSize: '0.875rem',
                        }}
                    >
                        <option value="weekly">Weekly</option>
                        <option value="biweekly">Every 2 Weeks</option>
                    </select>
                    <span>for</span>
                    <input
                        type="number"
                        value={count}
                        min={2}
                        max={52}
                        onChange={(e) => onCountChange(Math.max(2, Math.min(52, parseInt(e.target.value, 10) || 2)))}
                        style={{
                            width: '60px',
                            padding: '0.35rem 0.5rem',
                            borderRadius: '4px',
                            border: '1px solid #d1d5db',
                            fontSize: '0.875rem',
                            textAlign: 'center',
                        }}
                    />
                    <span>appointments</span>
                </div>
            )}
        </div>
    );
};

export default RecurringOptions;
