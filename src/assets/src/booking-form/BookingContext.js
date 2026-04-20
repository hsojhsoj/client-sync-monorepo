/**
 * File: src/assets/src/booking-form/BookingContext.js
 * Centralized state management for the Frontend Booking Form.
 *
 * - Reads window.clisycBookingFormData ONCE at module level
 * - Provides a reducer-based store via React Context
 * - Exports BookingProvider, useBooking hook, and bookingConfig
 */
import React, { createContext, useContext, useReducer, useMemo } from 'react';

// ---------------------------------------------------------------------------
// Module-level config – read from PHP exactly once
// ---------------------------------------------------------------------------
const bookingConfig = (() => {
    const raw = window.clisycBookingFormData || {};
    return {
        restUrl: raw.restUrl,
        restNonce: raw.restNonce,
        filterOrder: raw.filterOrder,
        formNonce: raw.formNonce,
        waitlistNonce: raw.waitlistNonce,
        isGuest: raw.isGuest,
        customFieldDefinitions: raw.customFieldDefinitions,
        successPageUrl: raw.successPageUrl,
        bookingMode: raw.bookingMode,
        dateRangeSettings: raw.dateRangeSettings,
        currencySymbol: raw.currencySymbol,
        availabilityDimensions: raw.availabilityDimensions,
        preselectedServiceId: raw.preselectedServiceId,
        primaryItemName: raw.primaryItemName,
        showBookedToggle: raw.showBookedToggle,
        showBookedDefault: raw.showBookedDefault,
        isAdmin: raw.isAdmin,
        calendarOptions: raw.calendarOptions,
        contactPageUrl: raw.contactPageUrl,
        colorSettings: raw.colorSettings || {},
        l10n: raw.l10n || {},
        smartStartDateEnabled: raw.smartStartDateEnabled || false,
        linkedVenueId: raw.linkedVenueId || 0,
    };
})();

// ---------------------------------------------------------------------------
// Helper – safe localStorage read
// ---------------------------------------------------------------------------
function readLocalStorageBool(key, defaultValue) {
    try {
        const saved = localStorage.getItem(key);
        if (saved === null) return defaultValue;
        return saved === 'true';
    } catch {
        return defaultValue;
    }
}

// ---------------------------------------------------------------------------
// Initial state
// ---------------------------------------------------------------------------
const initialState = {
    selectedSlot: null,
    selectedDateRange: null,
    isLoadingFilters: true,
    allFilterOptions: {},
    selectedFilters: {},
    isBookingModalOpen: false,
    isSmartDateModalOpen: false,
    isNoAvailabilityModalOpen: false,
    smartDateModalMessage: '',
    timezone: 'local',
    currentTime: '--:--:-- --',
    selectedPrimaryItem: null,
    basket: { total: 0, formatted: '', items: [] },
    isCalculatingPrice: false,
    tooltip: { visible: false, x: 0, y: 0, content: null },
    isSidebarCollapsed: readLocalStorageBool('clisyc_sidebar_collapsed', false),
    isHeaderCollapsed: readLocalStorageBool('clisyc_header_collapsed', true),
    mainCalendarDate: new Date(),
    showBooked: bookingConfig.showBookedDefault || false,
    selectedSeats: [],
    seatHoldExpiry: 0,
};

// ---------------------------------------------------------------------------
// Action types
// ---------------------------------------------------------------------------
const ActionTypes = {
    SET_SELECTED_SLOT: 'SET_SELECTED_SLOT',
    SET_SELECTED_DATE_RANGE: 'SET_SELECTED_DATE_RANGE',
    SET_IS_LOADING_FILTERS: 'SET_IS_LOADING_FILTERS',
    SET_ALL_FILTER_OPTIONS: 'SET_ALL_FILTER_OPTIONS',
    SET_SELECTED_FILTERS: 'SET_SELECTED_FILTERS',
    UPDATE_SELECTED_FILTER: 'UPDATE_SELECTED_FILTER',
    SET_IS_BOOKING_MODAL_OPEN: 'SET_IS_BOOKING_MODAL_OPEN',
    SET_IS_SMART_DATE_MODAL_OPEN: 'SET_IS_SMART_DATE_MODAL_OPEN',
    SET_IS_NO_AVAILABILITY_MODAL_OPEN: 'SET_IS_NO_AVAILABILITY_MODAL_OPEN',
    SET_SMART_DATE_MODAL_MESSAGE: 'SET_SMART_DATE_MODAL_MESSAGE',
    SET_TIMEZONE: 'SET_TIMEZONE',
    SET_CURRENT_TIME: 'SET_CURRENT_TIME',
    SET_SELECTED_PRIMARY_ITEM: 'SET_SELECTED_PRIMARY_ITEM',
    SET_BASKET: 'SET_BASKET',
    SET_IS_CALCULATING_PRICE: 'SET_IS_CALCULATING_PRICE',
    SET_TOOLTIP: 'SET_TOOLTIP',
    SET_IS_SIDEBAR_COLLAPSED: 'SET_IS_SIDEBAR_COLLAPSED',
    SET_IS_HEADER_COLLAPSED: 'SET_IS_HEADER_COLLAPSED',
    SET_MAIN_CALENDAR_DATE: 'SET_MAIN_CALENDAR_DATE',
    SET_SHOW_BOOKED: 'SET_SHOW_BOOKED',
    SET_SELECTED_SEATS: 'SET_SELECTED_SEATS',
    SET_SEAT_HOLD_EXPIRY: 'SET_SEAT_HOLD_EXPIRY',
    RESET_SELECTIONS: 'RESET_SELECTIONS',
};

// ---------------------------------------------------------------------------
// Reducer
// ---------------------------------------------------------------------------
function bookingReducer(state, action) {
    switch (action.type) {
        case ActionTypes.SET_SELECTED_SLOT:
            return { ...state, selectedSlot: action.payload };
        case ActionTypes.SET_SELECTED_DATE_RANGE:
            return { ...state, selectedDateRange: action.payload };
        case ActionTypes.SET_IS_LOADING_FILTERS:
            return { ...state, isLoadingFilters: action.payload };
        case ActionTypes.SET_ALL_FILTER_OPTIONS:
            return { ...state, allFilterOptions: action.payload };
        case ActionTypes.SET_SELECTED_FILTERS:
            return { ...state, selectedFilters: action.payload };
        case ActionTypes.UPDATE_SELECTED_FILTER:
            return {
                ...state,
                selectedFilters: {
                    ...state.selectedFilters,
                    [action.payload.key]: action.payload.value,
                },
            };
        case ActionTypes.SET_IS_BOOKING_MODAL_OPEN:
            return { ...state, isBookingModalOpen: action.payload };
        case ActionTypes.SET_IS_SMART_DATE_MODAL_OPEN:
            return { ...state, isSmartDateModalOpen: action.payload };
        case ActionTypes.SET_IS_NO_AVAILABILITY_MODAL_OPEN:
            return { ...state, isNoAvailabilityModalOpen: action.payload };
        case ActionTypes.SET_SMART_DATE_MODAL_MESSAGE:
            return { ...state, smartDateModalMessage: action.payload };
        case ActionTypes.SET_TIMEZONE:
            return { ...state, timezone: action.payload };
        case ActionTypes.SET_CURRENT_TIME:
            return { ...state, currentTime: action.payload };
        case ActionTypes.SET_SELECTED_PRIMARY_ITEM:
            return { ...state, selectedPrimaryItem: action.payload };
        case ActionTypes.SET_BASKET:
            return { ...state, basket: action.payload };
        case ActionTypes.SET_IS_CALCULATING_PRICE:
            return { ...state, isCalculatingPrice: action.payload };
        case ActionTypes.SET_TOOLTIP:
            return { ...state, tooltip: action.payload };
        case ActionTypes.SET_IS_SIDEBAR_COLLAPSED:
            return { ...state, isSidebarCollapsed: action.payload };
        case ActionTypes.SET_IS_HEADER_COLLAPSED:
            return { ...state, isHeaderCollapsed: action.payload };
        case ActionTypes.SET_MAIN_CALENDAR_DATE:
            return { ...state, mainCalendarDate: action.payload };
        case ActionTypes.SET_SHOW_BOOKED:
            return { ...state, showBooked: action.payload };
        case ActionTypes.SET_SELECTED_SEATS:
            return { ...state, selectedSeats: action.payload };
        case ActionTypes.SET_SEAT_HOLD_EXPIRY:
            return { ...state, seatHoldExpiry: action.payload };
        case ActionTypes.RESET_SELECTIONS:
            return {
                ...state,
                selectedSlot: null,
                selectedDateRange: null,
                selectedPrimaryItem: null,
                basket: { total: 0, formatted: '', items: [] },
                selectedSeats: [],
                seatHoldExpiry: 0,
            };
        default:
            return state;
    }
}

// ---------------------------------------------------------------------------
// Context
// ---------------------------------------------------------------------------
const BookingContext = createContext(null);

/**
 * BookingProvider wraps the application and exposes state + dispatch via context.
 */
const BookingProvider = ({ children }) => {
    const [state, dispatch] = useReducer(bookingReducer, initialState);

    const value = useMemo(() => ({ state, dispatch }), [state]);

    return (
        <BookingContext.Provider value={value}>
            {children}
        </BookingContext.Provider>
    );
};

/**
 * useBooking – convenience hook for consuming components.
 * Returns { state, dispatch }.
 */
function useBooking() {
    const context = useContext(BookingContext);
    if (!context) {
        throw new Error('useBooking must be used within a BookingProvider');
    }
    return context;
}

export { BookingProvider, useBooking, bookingConfig, ActionTypes };
export default BookingContext;
