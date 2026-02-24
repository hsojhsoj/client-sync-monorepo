/**
 * File: src/assets/src/faceted-booking/FacetedFilterPanel.js
 * FilterPanel with slot counts for each filter option.
 * 
 * FEATURES:
 * - Displays count of matching slots next to each option
 * - Grays out options with zero matches
 * - iOS-style colored checkboxes
 *
 * @package    ClientSync
 * @subpackage ClientSync/Assets/JS/FacetedBooking
 */
import React, { useCallback, useMemo } from 'react';

const FacetedFilterPanel = React.memo(({
    isLoading,
    options,
    selections,
    onSelect,
    onReset,
    slotCounts = {}
}) => {
    const { availabilityDimensions, filterOrder, l10n } = window.clisycFacetedBookingData || {
        availabilityDimensions: {},
        filterOrder: [],
        l10n: {}
    };

    const handleCheckboxChange = useCallback((filterKey, value) => {
        const currentSelection = selections[filterKey] || [];
        const newSelection = currentSelection.includes(value)
            ? currentSelection.filter(item => item !== value)
            : [...currentSelection, value];
        onSelect(filterKey, newSelection);
    }, [selections, onSelect]);

    const hasSelection = useMemo(() =>
        Object.values(selections).some(val => Array.isArray(val) && val.length > 0),
    [selections]);

    // Calculate total available slots across all filters (memoized)
    const totalSlots = useMemo(() => Object.values(slotCounts).reduce((total, dimCounts) => {
        const maxInDim = Math.max(...Object.values(dimCounts), 0);
        return Math.max(total, maxInDim);
    }, 0), [slotCounts]);

    return (
        <div className={`clisyc-availability-filters-container ${isLoading ? 'clisyc-filters-loading' : ''}`}>
            <div className="clisyc-filter-group-wrapper">
                {/* Total count header */}
                {totalSlots > 0 && (
                    <div className="clisyc-filter-total-count">
                        <span className="clisyc-total-number">{totalSlots}</span>
                        <span className="clisyc-total-label">
                            {totalSlots === 1 
                                ? (l10n?.appointmentAvailable || 'appointment available')
                                : (l10n?.appointmentsAvailable || 'appointments available')
                            }
                        </span>
                    </div>
                )}

                <div className="clisyc-reset-all-container">
                    <button 
                        type="button" 
                        className="clisyc-reset-all-filters-btn" 
                        onClick={onReset} 
                        style={{ display: hasSelection ? 'inline-flex' : 'none' }}
                    >
                        <span className="dashicons dashicons-update"></span>
                        <span className="clisyc-reset-text">{l10n?.resetAllFilters || 'Reset All Filters'}</span>
                    </button>
                </div>

                {filterOrder.map(key => {
                    const dim = availabilityDimensions[key];
                    const currentOptions = options[key] || [];
                    const hasAvailableOptions = currentOptions.length > 0;
                    const dimSlotCounts = slotCounts[key] || {};
                    
                    if (!dim) return null;

                    return (
                        <div 
                            key={key} 
                            className="clisyc-filter-group" 
                            style={{ display: hasAvailableOptions ? 'block' : 'none' }}
                        >
                            <h4 className="clisyc-filter-group-label">
                                <span className={`dashicons ${dim.icon || 'dashicons-admin-generic'}`}></span>
                                {dim.label}
                            </h4>
                            <div className="clisyc-filter-options-list">
                                {currentOptions.map(opt => {
                                    const isChecked = (selections[key] || []).includes(opt.value.toString());
                                    const count = dimSlotCounts[opt.value] || 0;
                                    const hasSlots = count > 0;
                                    
                                    return (
                                        <label 
                                            key={opt.value} 
                                            className={`clisyc-filter-option-label ${!hasSlots && !isChecked ? 'clisyc-filter-option-empty' : ''}`}
                                        >
                                            <input
                                                type="checkbox"
                                                value={opt.value}
                                                checked={isChecked}
                                                onChange={() => handleCheckboxChange(key, opt.value.toString())}
                                                disabled={!hasSlots && !isChecked}
                                            />
                                            {/* Colored checkbox indicator */}
                                            <span 
                                                className="clisyc-filter-checkbox-indicator"
                                                style={{ 
                                                    backgroundColor: opt.color || '#9ca3af',
                                                    opacity: isChecked ? 1 : (hasSlots ? 0.4 : 0.2)
                                                }}
                                            ></span>
                                            <span className="clisyc-filter-option-text">
                                                {opt.label.replace(/\(\d+\)$/, '').trim()}
                                            </span>
                                            {/* Slot count badge */}
                                            <span className={`clisyc-filter-count-badge ${!hasSlots ? 'clisyc-count-zero' : ''}`}>
                                                {count}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
});

FacetedFilterPanel.displayName = 'FacetedFilterPanel';

export default FacetedFilterPanel;