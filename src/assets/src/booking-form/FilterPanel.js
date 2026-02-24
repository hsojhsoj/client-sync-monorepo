/**
 * File: src/assets/src/booking-form/FilterPanel.js
 * Google Calendar-inspired collapsible sidebar with filter toggles.
 * 
 * REDESIGNED:
 * - Collapsible sidebar with hamburger toggle (like Google Calendar)
 * - Collapsible filter groups with chevron indicators
 * - Cleaner checkbox styling with colored indicators
 * - Removed "all selected by default" pattern - now shows all, select to filter
 * - Smooth CSS transitions
 * - Mini calendar integration for quick date navigation
 * - Added Price Modifiers display from dimension metadata
 */
import React, { useState, useCallback, useMemo } from 'react';

const FilterPanel = React.memo(({
    isLoading, 
    options, 
    selections, 
    onSelect, 
    onReset,
    bookingMode = 'slot',
    showBooked = false,
    onToggleShowBooked = null,
    isSidebarCollapsed = false,
    onToggleSidebar = null,
    miniCalendar = null  // Optional mini calendar component from App.js
}) => {
    const { availabilityDimensions, filterOrder, l10n, currencySymbol } = window.clisycBookingFormData || {};
    const symbol = currencySymbol || '$';
    const isDateRangeMode = bookingMode === 'date_range';

    // Track which filter groups are expanded (all expanded by default)
    const [expandedGroups, setExpandedGroups] = useState(() => {
        const initial = {};
        filterOrder?.forEach(key => { initial[key] = true; });
        initial['viewOptions'] = true;
        return initial;
    });

    const toggleGroup = useCallback((groupKey) => {
        setExpandedGroups(prev => ({ ...prev, [groupKey]: !prev[groupKey] }));
    }, []);

    // For slot mode: checkbox (multi-select)
    const handleCheckboxChange = useCallback((filterKey, value) => {
        const currentSelection = selections[filterKey] || [];
        const newSelection = currentSelection.includes(value)
            ? currentSelection.filter(item => item !== value)
            : [...currentSelection, value];

        onSelect(filterKey, newSelection);
    }, [selections, onSelect]);

    // For date_range mode: radio button (single-select)
    const handleRadioChange = useCallback((filterKey, value) => {
        onSelect(filterKey, [value]);
    }, [onSelect]);

    // Check if any filters are actively selected
    const hasActiveFilters = useMemo(() => Object.entries(selections).some(([key, val]) => {
        const totalOptions = options[key]?.length || 0;
        return Array.isArray(val) && val.length > 0 && val.length < totalOptions;
    }), [selections, options]);

    // Get primary dimension key (first in order)
    const primaryDimKey = filterOrder?.[0];

    // Count active filters per group (only if not all selected)
    const getActiveCount = (key) => {
        const selected = selections[key] || [];
        const total = options[key]?.length || 0;
        // Only show count if some (but not all) are selected
        if (selected.length === 0 || selected.length === total) return null;
        return selected.length;
    };

    // Hamburger Icon SVG
    const HamburgerIcon = () => (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
    );

    // Chevron Icon SVG
    const ChevronIcon = ({ isExpanded }) => (
        <svg 
            className={`clisyc-sidebar-chevron ${isExpanded ? 'clisyc-sidebar-chevron--expanded' : ''}`}
            width="16" 
            height="16" 
            viewBox="0 0 24 24" 
            fill="none" 
            stroke="currentColor" 
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <polyline points="6 9 12 15 18 9"></polyline>
        </svg>
    );

    // Reset Icon SVG
    const ResetIcon = () => (
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
            <path d="M3 3v5h5"></path>
        </svg>
    );

    // Checkmark Icon SVG
    const CheckIcon = () => (
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
            <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
    );

    return (
        <aside className={`clisyc-sidebar ${isSidebarCollapsed ? 'clisyc-sidebar--collapsed' : ''} ${isLoading ? 'clisyc-sidebar--loading' : ''} ${isDateRangeMode ? 'clisyc-sidebar--date-range' : ''}`}>
            
            {/* Collapsed State: Just show hamburger button */}
            {isSidebarCollapsed && onToggleSidebar && (
                <div className="clisyc-sidebar-collapsed-strip">
                    <button 
                        type="button"
                        className="clisyc-sidebar-toggle"
                        onClick={onToggleSidebar}
                        aria-label={l10n?.showFilters || 'Show filters'}
                        title={l10n?.showFilters || 'Show filters'}
                    >
                        <HamburgerIcon />
                    </button>
                </div>
            )}

            {/* Expanded State: Full sidebar content */}
            {!isSidebarCollapsed && (
                <div className="clisyc-sidebar-inner">
                    {/* Sidebar Header */}
                    <div className="clisyc-sidebar-header">
                        {onToggleSidebar && (
                            <button 
                                type="button"
                                className="clisyc-sidebar-toggle"
                                onClick={onToggleSidebar}
                                aria-label={l10n?.hideFilters || 'Hide filters'}
                                title={l10n?.hideFilters || 'Hide filters'}
                            >
                                <HamburgerIcon />
                            </button>
                        )}
                        
                        {/* Reset button - only show when filters are active */}
                        {hasActiveFilters && (
                            <button 
                                type="button" 
                                className="clisyc-sidebar-reset" 
                                onClick={onReset}
                                title={l10n?.resetAllFilters || 'Reset All Filters'}
                            >
                                <ResetIcon />
                                <span>{l10n?.resetFilters || 'Reset'}</span>
                            </button>
                        )}
                    </div>

                    {/* Mini Calendar (slot mode only) */}
                    {miniCalendar && (
                        <div className="clisyc-sidebar-mini-calendar">
                            {miniCalendar}
                        </div>
                    )}

                    {/* Filter Groups */}
                    <nav className="clisyc-sidebar-nav">
                        {filterOrder?.map((key) => {
                            const dim = availabilityDimensions?.[key];
                            const currentOptions = options[key];
                            
                            if (!dim) return null;

                            // For date_range mode, only show primary dimension
                            const isPrimaryDimension = key === primaryDimKey;
                            if (isDateRangeMode && !isPrimaryDimension) return null;

                            const isExpanded = expandedGroups[key];
                            const activeCount = getActiveCount(key);

                            return (
                                <div key={key} className={`clisyc-sidebar-group ${isPrimaryDimension ? 'clisyc-sidebar-group--primary' : ''}`}>
                                    {/* Group Header - Clickable to expand/collapse */}
                                    <button 
                                        type="button"
                                        className="clisyc-sidebar-group-header"
                                        onClick={() => toggleGroup(key)}
                                        aria-expanded={isExpanded}
                                    >
                                        <ChevronIcon isExpanded={isExpanded} />
                                        <span className="clisyc-sidebar-group-title">
                                            {dim.label}
                                        </span>
                                        {activeCount && (
                                            <span className="clisyc-sidebar-filter-badge">{activeCount}</span>
                                        )}
                                    </button>
                                    
                                    {/* Group Options - Collapsible */}
                                    <div className={`clisyc-sidebar-group-content ${isExpanded ? 'clisyc-sidebar-group-content--expanded' : ''}`}>
                                        {currentOptions ? currentOptions.map(opt => {
                                            const isSelected = (selections[key] || []).includes(opt.value.toString());
                                            const itemColor = opt.color || '#4285f4';

                                            // Access metadata from dimension configuration
                                            const meta = dim?.options_meta?.[opt.value] || {};
                                            const hasPrice = meta.price > 0;
                                            
                                            // Format price hint
                                            let priceHint = '';
                                            if (hasPrice) {
                                                const typeLabel = meta.calc_type === 'per_night' ? '/night' : (meta.calc_type === 'per_hour' ? '/hr' : '');
                                                priceHint = ` +${symbol}${meta.price}${typeLabel}`;
                                            }
                                            
                                            return (
                                                <label 
                                                    key={opt.value} 
                                                    className={`clisyc-sidebar-item ${isSelected ? 'clisyc-sidebar-item--selected' : ''}`}
                                                >
                                                    <input
                                                        type={isDateRangeMode ? 'radio' : 'checkbox'}
                                                        name={isDateRangeMode ? `clisyc_filter_${key}` : undefined}
                                                        value={opt.value}
                                                        checked={isSelected}
                                                        onChange={() => isDateRangeMode 
                                                            ? handleRadioChange(key, opt.value.toString())
                                                            : handleCheckboxChange(key, opt.value.toString())
                                                        }
                                                        className="clisyc-sidebar-input sr-only"
                                                    />
                                                    {/* Google Calendar style colored checkbox */}
                                                    <span 
                                                        className="clisyc-sidebar-checkbox"
                                                        style={{
                                                            borderColor: itemColor,
                                                            backgroundColor: isSelected ? itemColor : 'transparent'
                                                        }}
                                                        aria-hidden="true"
                                                    >
                                                        {isSelected && <CheckIcon />}
                                                    </span>
                                                    <span className="clisyc-sidebar-item-label">
                                                        {opt.label}
                                                        {hasPrice && (
                                                            <span className="clisyc-price-hint" style={{ 
                                                                fontSize: '0.85em', 
                                                                color: '#16a34a', 
                                                                marginLeft: '6px', 
                                                                fontWeight: '500' 
                                                            }}>
                                                                {priceHint}
                                                            </span>
                                                        )}
                                                    </span>
                                                    {/* Show base price for date range mode if available directly on option */}
                                                    {isDateRangeMode && opt.price > 0 && (
                                                        <span className="clisyc-sidebar-item-price">
                                                            {symbol}{opt.price}
                                                        </span>
                                                    )}
                                                </label>
                                            );
                                        }) : (
                                            <div className="clisyc-sidebar-loading">
                                                <span className="clisyc-loading-dot"></span>
                                                <span className="clisyc-loading-dot"></span>
                                                <span className="clisyc-loading-dot"></span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}

                        {/* View Options Section (Show Booked Toggle) - Slot mode only */}
                        {!isDateRangeMode && onToggleShowBooked && (
                            <div className="clisyc-sidebar-group clisyc-sidebar-group--options">
                                <button 
                                    type="button"
                                    className="clisyc-sidebar-group-header"
                                    onClick={() => toggleGroup('viewOptions')}
                                    aria-expanded={expandedGroups['viewOptions']}
                                >
                                    <ChevronIcon isExpanded={expandedGroups['viewOptions']} />
                                    <span className="clisyc-sidebar-group-title">
                                        {l10n?.viewOptions || 'View Options'}
                                    </span>
                                </button>
                                
                                <div className={`clisyc-sidebar-group-content ${expandedGroups['viewOptions'] ? 'clisyc-sidebar-group-content--expanded' : ''}`}>
                                    <label className={`clisyc-sidebar-item ${showBooked ? 'clisyc-sidebar-item--selected' : ''}`}>
                                        <input
                                            type="checkbox"
                                            checked={showBooked}
                                            onChange={(e) => onToggleShowBooked(e.target.checked)}
                                            className="clisyc-sidebar-input sr-only"
                                        />
                                        <span 
                                            className="clisyc-sidebar-checkbox"
                                            style={{
                                                borderColor: '#dc2626',
                                                backgroundColor: showBooked ? '#dc2626' : 'transparent'
                                            }}
                                            aria-hidden="true"
                                        >
                                            {showBooked && <CheckIcon />}
                                        </span>
                                        <span className="clisyc-sidebar-item-label">
                                            {l10n?.showBookedSlots || 'Show booked slots'}
                                        </span>
                                    </label>
                                </div>
                            </div>
                        )}
                    </nav>

                    {/* Info text for date range mode */}
                    {isDateRangeMode && (
                        <div className="clisyc-sidebar-info">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="16" x2="12" y2="12"></line>
                                <line x1="12" y1="8" x2="12.01" y2="8"></line>
                            </svg>
                            <span>{l10n?.dateRangeInfo || 'Select an option to view availability'}</span>
                        </div>
                    )}
                </div>
            )}
        </aside>
    );
});

FilterPanel.displayName = 'FilterPanel';

export default FilterPanel;