/**
 * File: src/assets/js/clisyc-frontend-appointment-calendar.js
 * REFACTORED: Replaced dropdowns with radio button filter UI.
 * FINAL FIX: Corrected the initialization order and added null checks for DOM elements to prevent errors when
 * the full booking form is not present on the page.
 * ENHANCEMENTS: Integrated ToastManager, EmptyStateManager, ProgressTracker, and other UX improvements.
 * FINAL TWEAKS: Added more contextual toast notifications for clearing, resetting, and errors.
 * LATEST UPDATE: Added color-coded filter indicators that match calendar event colors for better visual continuity.
 */

(function($) {
'use strict';

if (typeof FullCalendar === 'undefined' || typeof clisycAppointmentData === 'undefined') {
    console.error('CRITICAL ERROR: FullCalendar or clisycAppointmentData is missing.');
    return;
}

// HTML escape helper to prevent XSS from API data injected into innerHTML.
const escapeHtml = (str) => {
    if (str == null) return '';
    if (typeof str !== 'string') str = String(str);
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
};

// ============================================================================
// 1. TOAST NOTIFICATION SYSTEM
// ============================================================================

const ToastManager = {
    container: null,
    
    init() {
        // Create toast container if it doesn't exist
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = 'clisyc-toast-container';
            this.container.setAttribute('aria-live', 'polite');
            this.container.setAttribute('aria-atomic', 'true');
            this.container.style.cssText = `
                position: fixed;
                bottom: 24px;
                right: 24px;
                z-index: 10000;
                display: flex;
                flex-direction: column;
                gap: 12px;
            `;
            document.body.appendChild(this.container);
        }
    },
    
    show(message, type = 'info', duration = 3000) {
        this.init();
        
        const toast = document.createElement('div');
        toast.className = `clisyc-toast clisyc-toast-${type}`;
        toast.style.cssText = `
            padding: 16px 24px;
            background: ${this.getBackgroundColor(type)};
            color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            max-width: 320px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        `;
        
        // Add icon based on type
        const icon = document.createElement('span');
        icon.textContent = this.getIcon(type);
        icon.style.fontSize = '20px';
        toast.appendChild(icon);
        
        // Add message
        const messageSpan = document.createElement('span');
        messageSpan.textContent = message;
        toast.appendChild(messageSpan);
        
        this.container.appendChild(toast);
        
        // Trigger animation
        setTimeout(() => {
            toast.style.transform = 'translateY(0)';
            toast.style.opacity = '1';
        }, 10);
        
        // Remove after duration
        setTimeout(() => {
            toast.style.transform = 'translateY(100px)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },
    
    getBackgroundColor(type) {
        const colors = {
            'success': '#22c55e',
            'error': '#ef4444',
            'warning': '#f59e0b',
            'info': '#3b82f6'
        };
        return colors[type] || colors.info;
    },
    
    getIcon(type) {
        const icons = {
            'success': '✓',
            'error': '✕',
            'warning': '⚠',
            'info': 'ℹ'
        };
        return icons[type] || icons.info;
    }
};

// ============================================================================
// 2. SKELETON LOADER (NOT USED, ORIGINAL LOADING CLASS PREFERRED)
// ============================================================================

// SkeletonLoader object provided for reference, but the existing .clisyc-filters-loading class is used for simplicity.

// ============================================================================
// 3. SMOOTH SCROLL TO CALENDAR
// ============================================================================

function scrollToCalendar() {
    const calendar = document.getElementById('clisyc-calendar-and-form-fields');
    if (calendar) {
        calendar.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
            inline: 'nearest'
        });
    }
}

// ============================================================================
// 4. EMPTY STATE HANDLER
// ============================================================================

const EmptyStateManager = {
    show(container, type = 'no-slots') {
        const messages = {
            'no-slots': {
                icon: '📅',
                title: 'No Available Slots',
                description: 'Try adjusting your filters or selecting a different date range.'
            },
            'no-results': {
                icon: '🔍',
                title: 'No Results Found',
                description: 'Try different filter combinations.'
            },
            'select-filters': {
                icon: '⚙️',
                title: 'Select Your Options',
                description: 'Please select a service and other options to view availability.'
            }
        };
        
        const message = messages[type] || messages['no-slots'];
        
        container.innerHTML = `
            <div class="clisyc-empty-state" style="
                text-align: center;
                padding: 80px 20px;
                color: #6b7280;
            ">
                <div class="clisyc-empty-state-icon" style="
                    font-size: 4em;
                    margin-bottom: 20px;
                    opacity: 0.5;
                ">${message.icon}</div>
                <div class="clisyc-empty-state-message" style="
                    font-size: 1.25em;
                    font-weight: 600;
                    margin-bottom: 12px;
                    color: #374151;
                ">${message.title}</div>
                <div class="clisyc-empty-state-description" style="
                    font-size: 1em;
                    line-height: 1.6;
                    max-width: 400px;
                    margin: 0 auto;
                ">${message.description}</div>
            </div>
        `;
    }
};


// ============================================================================
// 5. MOBILE FILTER DRAWER
// ============================================================================

const MobileFilterDrawer = {
    init() {
        if (window.innerWidth > 768) return;
        
        const filterContainer = document.getElementById('clisyc-availability-filters');
        const filterToggle = document.getElementById('clisyc-mobile-filter-toggle');
        
        if (!filterContainer || !filterToggle) return;

        filterToggle.addEventListener('click', () => this.toggle(filterContainer));
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && filterContainer.classList.contains('mobile-open')) {
                this.close(filterContainer);
            }
        });
    },
    
    toggle(container) {
        container.classList.contains('mobile-open') ? this.close(container) : this.open(container);
    },
    
    open(container) {
        container.classList.add('mobile-open');
        document.body.style.overflow = 'hidden';
        
        const overlay = document.createElement('div');
        overlay.className = 'clisyc-mobile-overlay';
        overlay.style.cssText = `
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5); z-index: 999;
        `;
        overlay.addEventListener('click', () => this.close(container));
        document.body.appendChild(overlay);
    },
    
    close(container) {
        container.classList.remove('mobile-open');
        document.body.style.overflow = '';
        
        const overlay = document.querySelector('.clisyc-mobile-overlay');
        if (overlay) overlay.remove();
    }
};

// ============================================================================
// 6. PROGRESS TRACKER
// ============================================================================

const ProgressTracker = {
    steps: ['select-options', 'choose-time', 'confirm-details'],
    currentStep: 0,
    
    init() {
        this.currentStep = 0;
        this.render();
    },
    
    setStep(stepIndex) {
        if (stepIndex >= 0 && stepIndex < this.steps.length) {
            this.currentStep = stepIndex;
            this.render();
        }
    },
    
    render() {
        const progressBar = document.querySelector('.client-sync-progress-bar');
        if (!progressBar) return;
        
        const steps = progressBar.querySelectorAll('.client-sync-progress-step');
        steps.forEach((step, index) => {
            step.classList.remove('active', 'completed');
            if (index < this.currentStep) {
                step.classList.add('completed');
            } else if (index === this.currentStep) {
                step.classList.add('active');
            }
        });
    }
};

// ============================================================================
// 7. KEYBOARD NAVIGATION FOR FILTER CARDS
// ============================================================================

function enhanceKeyboardNavigation() {
    const radioContainers = document.querySelectorAll('.clisyc-filter-radio-container');
    
    radioContainers.forEach(container => {
        const labels = container.querySelectorAll('.clisyc-radio-option label');
        labels.forEach((label, index) => {
            label.setAttribute('tabindex', '0');
            
            label.addEventListener('keydown', (e) => {
                const input = document.getElementById(label.htmlFor);
                
                if (e.key === ' ' || e.key === 'Enter') {
                    e.preventDefault();
                    if(input) input.click();
                }
                
                if ((e.key === 'ArrowDown' || e.key === 'ArrowRight') && labels[index + 1]) {
                    e.preventDefault();
                    labels[index + 1].focus();
                }
                
                if ((e.key === 'ArrowUp' || e.key === 'ArrowLeft') && labels[index - 1]) {
                    e.preventDefault();
                    labels[index - 1].focus();
                }
            });
        });
    });
}

// ============================================================================
// 8. SCREEN READER ANNOUNCEMENTS
// ============================================================================

const ScreenReaderAnnouncer = {
    announce(message, priority = 'polite') {
        let announcer = document.getElementById('clisyc-sr-announcer');
        if (!announcer) {
            announcer = document.createElement('div');
            announcer.id = 'clisyc-sr-announcer';
            announcer.setAttribute('aria-live', 'polite');
            announcer.style.cssText = `
                position: absolute; width: 1px; height: 1px; padding: 0;
                margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0);
                white-space: nowrap; border-width: 0;
            `;
            document.body.appendChild(announcer);
        }
        
        announcer.setAttribute('aria-live', priority);
        announcer.textContent = message;

        setTimeout(() => {
             announcer.textContent = '';
        }, 5000);
    }
};

const BookingApp = {
    // --- State ---
    state: {
        currentFilterSelections: {},
        lastFetchedServiceDetails: null,
        currentBookingMode: 'slot',
        isRecurringBooking: false,
        disabledDatesForPicker: [],
        availabilityRules: { minStay: 1, maxStay: 0, checkinDays: [], checkoutDays: [] },
        initialLoadComplete: false,
        isProgrammaticChange: false,
        lastUserInteractedFilter: null,
        primaryServiceDimKey: 'clisyc_service',
    },

    // --- DOM Elements ---
    el: {
        appointmentForm: null,
        validationErrorContainer: null,
        selectedSlotDisplayEl: null,
        selectedSlotTextEl: null,
        availabilityFiltersContainer: null,
        bookingInterfaceContainer: null,
        calendarAndFormFields: null,
        dateRangePickerContainer: null,
        startDateInput: null,
        endDateInput: null,
        recurringToggle: null,
        recurringOptions: null,
        filterStatusEl: null,
        serviceDetailsContainer: null,
        formInputs: {},
    },

    // --- Main Initializer ---
    init() {
        this.cacheDOMElements();
        if (!this.el.appointmentForm) {
            return;
        }
        this.determinePrimaryDimKey();
        
        // --- START: CORRECTED INITIALIZATION ORDER ---
        // 1. Initialize all manager objects so they have a reference to the app.
        FilterManager.init(this);
        CalendarManager.init(this);
        FormManager.init(this);
        ServiceDetailsManager.init(this);
        TimezoneManager.init(this);
        
        // 2. Bind events that allow managers to communicate.
        this.bindEvents();

        // 3. Process initial state (URL params, etc.) which will trigger the first data load.
        this.processInitialState();
        // --- END: CORRECTED INITIALIZATION ORDER ---

        // Initialize UI enhancements
        ToastManager.init();
        MobileFilterDrawer.init();
        ProgressTracker.init();
    },

    cacheDOMElements() {
        this.el.appointmentForm = document.getElementById('clisyc_appointment_form');
        this.el.validationErrorContainer = document.getElementById('clisyc-client-side-validation-errors');
        this.el.selectedSlotDisplayEl = document.getElementById('clisyc_selected_slot_display');
        this.el.selectedSlotTextEl = document.getElementById('clisyc_selected_slot');
        this.el.availabilityFiltersContainer = document.getElementById('clisyc-availability-filters');
        this.el.bookingInterfaceContainer = document.getElementById('clisyc-booking-interface-container');
        this.el.calendarAndFormFields = document.getElementById('clisyc-calendar-and-form-fields');
        this.el.dateRangePickerContainer = document.getElementById('clisyc-date-range-picker-container');
        this.el.startDateInput = document.getElementById('clisyc_start_date_picker');
        this.el.endDateInput = document.getElementById('clisyc_end_date_picker');
        this.el.inlineDatePickerEl = document.getElementById('clisyc-inline-datepicker');
        this.el.summaryEl = document.getElementById('clisyc-date-range-summary');
        this.el.summaryStartDate = document.getElementById('clisyc-summary-start-date');
        this.el.summaryEndDate = document.getElementById('clisyc-summary-end-date');
        this.el.summaryNights = document.getElementById('clisyc-summary-nights');
        this.el.recurringToggle = document.getElementById('clisyc-recurring-toggle') || document.getElementById('clisyc-recurring-toggle-loggedin');
        this.el.recurringOptions = document.getElementById('clisyc-recurring-options') || document.getElementById('clisyc-recurring-options-loggedin');
        this.el.filterStatusEl = document.getElementById('clisyc-filter-status');
        this.el.serviceDetailsContainer = document.getElementById('clisyc-service-details-container');

        if (!this.el.appointmentForm) {
            return;
        }

        this.el.formInputs = {
            appointmentDate: this.el.appointmentForm.querySelector('#appointment_date'),
            timeSlot: this.el.appointmentForm.querySelector('#time_slot'),
            startDate: this.el.appointmentForm.querySelector('input[name="start_date"]'),
            endDate: this.el.appointmentForm.querySelector('input[name="end_date"]'),
            bookingMode: this.el.appointmentForm.querySelector('input[name="booking_mode"]'),
            duration: this.el.appointmentForm.querySelector('#appointment_duration_minutes'),
            attendees: this.el.appointmentForm.querySelector('#clisyc_attendees'),
            attendeesWrapper: this.el.appointmentForm.querySelector('#clisyc-attendees-wrapper'),
            scheduleButton: this.el.appointmentForm.querySelector('#schedule_button'),
            paymentOptions: this.el.appointmentForm.querySelector('#clisyc_payment_options'),
            filterInputsContainer: document.getElementById('clisyc-hidden-inputs-container'),
            guestRegFields: {
                firstName: this.el.appointmentForm.querySelector('#clisyc_reg_first_name'),
                lastName: this.el.appointmentForm.querySelector('#clisyc_reg_last_name'),
                email: this.el.appointmentForm.querySelector('#clisyc_reg_email'),
                password: this.el.appointmentForm.querySelector('#clisyc_reg_password'),
            },
        };
    },
    
    determinePrimaryDimKey() {
        const { filterOrder } = clisycAppointmentData;
        if (filterOrder && filterOrder.length > 0) {
            this.state.primaryServiceDimKey = filterOrder[0];
        }
    },

    bindEvents() {
        $(document).on('clisyc:filtersSynced', (e, data) => this.onFiltersSynced(data));
        $(document).on('clisyc:filtersChanged', () => this.onFiltersChanged());
        $(document).on('clisyc:slotSelected', (e, data) => this.onSlotSelected(data));
        $(document).on('clisyc:slotCleared', (e, clearServiceDetails) => this.onSlotCleared(clearServiceDetails));
        $(document).on('clisyc:dateRangeSelected', () => this.onDateRangeSelected());
    },

    processInitialState() {
        const { postedData } = clisycAppointmentData;
        const urlParams = new URLSearchParams(window.location.search);

        FilterManager.filterOrder.forEach(slug => {
            if (!this.state.currentFilterSelections[slug] && urlParams.has(slug)) {
                this.state.currentFilterSelections[slug] = urlParams.get(slug);
            }
        });

        if (postedData && postedData.filters) {
            Object.assign(this.state.currentFilterSelections, postedData.filters);
        }

        FilterManager.syncOptions(this.state.currentFilterSelections).then(() => {
            FilterManager.filterOrder.forEach(key => {
                const valueFromRadio = $(`input[name="clisyc_filter_radio_${key}"]:checked`).val();
                if (valueFromRadio) {
                    this.state.currentFilterSelections[key] = valueFromRadio;
                }
            });
            this.onFiltersChanged();
            this.state.initialLoadComplete = true;
        }).catch(err => { console.error('[ClientSync] Initial filter sync failed:', err); });
    },

    onFiltersSynced(data) {
        const { newSelections, serviceDetails, bookingMode, availabilityRules } = data;
        this.state.currentFilterSelections = newSelections;
        this.state.lastFetchedServiceDetails = serviceDetails;
        this.state.availabilityRules = availabilityRules;
        this.switchBookingModeUI(bookingMode, newSelections[this.state.primaryServiceDimKey]);
    },

    onFiltersChanged() {
        const { showOverviewAvailability } = clisycAppointmentData.calendarOptions;
        const isOverviewMode = showOverviewAvailability && showOverviewAvailability !== 'none';

        if (!isOverviewMode && !this.state.currentFilterSelections[this.state.primaryServiceDimKey]) {
            $(this.el.bookingInterfaceContainer).slideUp(200);
            ServiceDetailsManager.clear();
            ProgressTracker.setStep(0);
            return;
        }

        $(this.el.bookingInterfaceContainer).slideDown(200);
        ProgressTracker.setStep(1);
        setTimeout(scrollToCalendar, 300);

        if (this.state.currentBookingMode === 'date_range') {
            FormManager.updateHiddenFilterInputs();
            FormManager.applyConditionalLogic();
            ServiceDetailsManager.updateReadOnlyFilterDisplays(null);
            FormManager.updateButtonState();
            return;
        }

        CalendarManager.clearSlotSelection(false);
        FormManager.updateHiddenFilterInputs();
        FormManager.applyConditionalLogic();
        ServiceDetailsManager.updateReadOnlyFilterDisplays(null);

        if (!CalendarManager.instance) {
            CalendarManager.initializeInstance();
        } else {
            CalendarManager.refetchEvents();
        }
    },
    
    onSlotSelected(data) {
        const { event } = data;
        const props = event.extendedProps;
        this.state.isRecurringBooking = false;
        
        ServiceDetailsManager.display(props.serviceDetails || this.state.lastFetchedServiceDetails, event);
        ServiceDetailsManager.updateReadOnlyFilterDisplays(event);
        FormManager.applyConditionalLogic();
        
        ToastManager.show('Slot selected!', 'success', 2000);
        ProgressTracker.setStep(2);
        const announcement = `Time slot selected: ${CalendarManager.instance.formatDate(data.event.start, { weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })}. Please review your booking details.`;
        ScreenReaderAnnouncer.announce(announcement);

        const { isGuest } = clisycAppointmentData;
        const nextSectionSelector = isGuest ? '#clisyc-appointment-details-section-guest' : '#clisyc-appointment-details-section';
        const nextSectionEl = document.querySelector(nextSectionSelector);
        if (nextSectionEl && nextSectionEl.closest('.clisyc-form-section')) {
            nextSectionEl.closest('.clisyc-form-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        
        setTimeout(() => { FormManager.updateButtonState(); }, 10);
    },

    onSlotCleared(clearServiceDetails = true) {
        if (clearServiceDetails) {
            ServiceDetailsManager.clear();
            if (!FilterManager.areAllFiltersSelected()) {
                this.state.lastFetchedServiceDetails = null;
            }
        }
        ServiceDetailsManager.updateReadOnlyFilterDisplays(null);
        FormManager.updateButtonState();
        ProgressTracker.setStep(1);
    },

    onDateRangeSelected() {
        FormManager.updateHiddenFilterInputs();
        FormManager.updateButtonState();
        ServiceDetailsManager.updatePriceDisplay();
    },

    switchBookingModeUI(mode, primaryDimId) {
        this.state.currentBookingMode = mode;
        if (this.el.formInputs.bookingMode) this.el.formInputs.bookingMode.value = mode;

        if (mode === 'date_range') {
            $(this.el.calendarAndFormFields).hide();
            $(this.el.dateRangePickerContainer).show();
            CalendarManager.clearSlotSelection(true);
            CalendarManager.initializeDateRangePicker(primaryDimId);
        } else {
            $(this.el.dateRangePickerContainer).hide();
            $(this.el.calendarAndFormFields).show();
            CalendarManager.clearDateRangeSelection();
        }
    },
};

const FilterManager = {
    app: null,
    selects: {},
    filterOrder: [],
    hasVisibleFilters: false,

    init(app) {
        this.app = app;
        this.build();
        this.initializeListeners();
        this.setupListeners();
    },
    
    setupListeners() {
        document.body.addEventListener('click', (event) => {
            const filterButton = event.target.closest('.clisyc-service-filter-key-item');
            if (!filterButton) return;
            event.preventDefault();
            if (filterButton.classList.contains('clisyc-reset-filter-button')) {
                this.resetAll();
            } else {
                const serviceId = filterButton.dataset.serviceId;
                if (serviceId) {
                    this.app.state.isProgrammaticChange = true;
                    this.app.state.currentFilterSelections = { [this.app.state.primaryServiceDimKey]: serviceId };
                    this.syncOptions(this.app.state.currentFilterSelections).then(() => {
                        $(document).trigger('clisyc:filtersChanged');
                        this.app.state.isProgrammaticChange = false;
                    }).catch(err => { console.error('[ClientSync] Filter sync failed:', err); });
                    const calendarEl = document.getElementById('clisyc-calendar');
                    if (calendarEl) {
                        calendarEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            }
        });
    },

    build() {
        const { availabilityDimensions, l10n, filterOrder } = clisycAppointmentData;
        if (!this.app.el.availabilityFiltersContainer) return;
        if (!availabilityDimensions || Object.keys(availabilityDimensions).length === 0) {
            $(this.app.el.availabilityFiltersContainer).hide();
            return;
        }
    
        this.filterOrder = filterOrder || [];
        const filterWrapper = document.createElement('div');
        filterWrapper.className = 'clisyc-filter-group-wrapper';
    
        const resetBtnContainer = document.createElement('div');
        resetBtnContainer.className = 'clisyc-reset-all-container';
        const resetBtn = document.createElement('button');
        resetBtn.type = 'button';
        resetBtn.className = 'clisyc-reset-all-filters-btn';
        resetBtn.innerHTML = `<span class="dashicons dashicons-update"></span><span class="clisyc-reset-text">${l10n.resetAllFilters || 'Reset All Filters'}</span>`;
        resetBtn.title = l10n.resetFilters || 'Reset Filters';
        resetBtn.style.display = 'none'; // Initially hidden
        resetBtn.addEventListener('click', () => this.resetAll());
        resetBtnContainer.appendChild(resetBtn);
        filterWrapper.appendChild(resetBtnContainer);
        this.selects.resetBtn = resetBtn; // Store a reference
        
        this.filterOrder.forEach(key => {
            const dim = availabilityDimensions[key];
            if (dim) {
                this.hasVisibleFilters = true;
    
                const groupEl = document.createElement('div');
                groupEl.className = 'clisyc-filter-group clisyc-form-field';
                groupEl.dataset.filterKey = key;
    
                const labelEl = document.createElement('h4');
                labelEl.className = 'clisyc-filter-group-label';
                labelEl.textContent = dim.label;
                
                const radioContainer = document.createElement('div');
                radioContainer.className = 'clisyc-filter-radio-container';
                radioContainer.id = `clisyc_filter_container_${key}`;
    
                groupEl.appendChild(labelEl);
                groupEl.appendChild(radioContainer);
                filterWrapper.appendChild(groupEl);
                
                this.selects[key] = radioContainer; 
            }
        });
        this.app.el.availabilityFiltersContainer.insertBefore(filterWrapper, this.app.el.filterStatusEl);
    },

    initializeListeners() {
        $(this.app.el.availabilityFiltersContainer).on('click', 'input[type="radio"]', (e) => {
            const radio = e.target;
            const groupEl = radio.closest('.clisyc-filter-group');
            if (groupEl) {
                const key = groupEl.dataset.filterKey;
                this.app.state.lastUserInteractedFilter = key;
                
                this.app.state.currentFilterSelections[key] = radio.value;
    
                const currentIndex = this.filterOrder.indexOf(key);
                this.filterOrder.forEach((filterKey, index) => {
                    if (index > currentIndex) {
                        delete this.app.state.currentFilterSelections[filterKey];
                    }
                });
    
                void this.handleFilterChange();
            }
        });
    },

    async handleFilterChange() {
        if (this.app.state.isProgrammaticChange || !this.app.state.initialLoadComplete) return;

        await this.syncOptions(this.app.state.currentFilterSelections);
        $(document).trigger('clisyc:filtersChanged');
    },

    async syncOptions(selections) {
        $(this.app.el.availabilityFiltersContainer).addClass('clisyc-filters-loading');
        this.setLoading(true);

        try {
            const { restUrl, restNonce } = clisycAppointmentData;
            let currentSelections = { ...selections };
            let madeAutoSelection, finalData;

            do {
                madeAutoSelection = false;
                const queryParams = new URLSearchParams({ _wpnonce: restNonce });
                Object.entries(currentSelections).forEach(([key, value]) => { if (value) queryParams.append(key, value); });
                const optionsRestUrl = restUrl.replace('/slots', '/filter-options');
                const response = await fetch(`${optionsRestUrl}?${queryParams.toString()}`);
                if (!response.ok) throw new Error('Network response not ok');
                finalData = await response.json();
                for (const slug of this.filterOrder) {
                    if (finalData[slug] && finalData[slug].length === 1 && !currentSelections[slug]) {
                        currentSelections[slug] = finalData[slug][0].value;
                        madeAutoSelection = true;
                        break;
                    }
                }
            } while (madeAutoSelection);
            
            const primaryDimId = currentSelections[this.app.state.primaryServiceDimKey];
            let serviceBookingMode = 'slot', rules = { minStay: 1, maxStay: 0, checkinDays: [], checkoutDays: [] };
            if (primaryDimId && finalData[this.app.state.primaryServiceDimKey]) {
                const selectedService = finalData[this.app.state.primaryServiceDimKey].find(opt => opt.value == primaryDimId);
                if (selectedService) {
                    serviceBookingMode = selectedService.booking_mode || 'slot';
                    rules.minStay = selectedService.min_stay || 1;
                    rules.maxStay = selectedService.max_stay || 0;
                    rules.checkinDays = selectedService.checkin_days || [];
                    rules.checkoutDays = selectedService.checkout_days || [];
                }
            }

            this.app.state.isProgrammaticChange = true;
            this.filterOrder.forEach(slug => { this.updateDropdown(slug, finalData[slug], currentSelections[slug]); });
            this.app.state.isProgrammaticChange = false;
            
            ToastManager.show('Filters updated.', 'success', 2000);
            ScreenReaderAnnouncer.announce('Filter options have been updated.');

            $(document).trigger('clisyc:filtersSynced', [{ newSelections: currentSelections, serviceDetails: finalData.selectedServiceDetails, bookingMode: serviceBookingMode, availabilityRules: rules }]);
        } catch (error) {
            console.error('Failed to sync filter options:', error);
            ToastManager.show('Failed to update filters.', 'error');
            ScreenReaderAnnouncer.announce('Error updating filters.', 'assertive');
        } finally {
            $(this.app.el.availabilityFiltersContainer).removeClass('clisyc-filters-loading');
            this.setLoading(false);
            this.updateResetButtonVisibility();
        }
    },

    resetAll() {
        this.app.state.currentFilterSelections = {};
        this.syncOptions({}).then(() => {
            $(document).trigger('clisyc:filtersChanged');
            ToastManager.show('All filters reset', 'info', 2000);
        }).catch(err => { console.error('[ClientSync] Filter reset failed:', err); });
    },

    updateDropdown(key, options, selectedValue) {
        const radioContainer = this.selects[key];
        if (!radioContainer) return;
    
        $(radioContainer).empty();
    
        const hasOptions = options && options.length > 0;
        const parentGroup = radioContainer.closest('.clisyc-filter-group');
    
        if (parentGroup) {
            parentGroup.style.display = hasOptions ? 'block' : 'none';
        }
    
        if (hasOptions) {
            options.forEach(opt => {
                const wrapper = document.createElement('div');
                wrapper.className = 'clisyc-radio-option';
                
                const input = document.createElement('input');
                input.type = 'radio';
                input.name = `clisyc_filter_radio_${key}`;
                input.id = `clisyc_filter_option_${key}_${opt.value}`;
                input.value = opt.value;
                input.checked = (opt.value == selectedValue);
                
                // Store color data if available
                if (opt.color) {
                    input.dataset.color = opt.color;
                }
    
                const label = document.createElement('label');
                label.htmlFor = input.id;
                
                // Add label text
                const labelText = document.createTextNode(opt.label);
                label.appendChild(labelText);
                
                // Add color indicator if color is available
                if (opt.color && opt.color.trim() !== '') {
                    const colorIndicator = document.createElement('span');
                    colorIndicator.className = 'clisyc-filter-color-indicator';
                    colorIndicator.style.backgroundColor = opt.color;
                    colorIndicator.title = `Color: ${opt.color}`;
                    label.appendChild(colorIndicator);
                }
                
                wrapper.appendChild(input);
                wrapper.appendChild(label);
                radioContainer.appendChild(wrapper);
            });
        }
        enhanceKeyboardNavigation();
    },

    setLoading(isLoading) {
        $(this.app.el.availabilityFiltersContainer).find('input[type="radio"]').prop('disabled', isLoading);
    },
    
    updateResetButtonVisibility() {
        if (!this.selects.resetBtn) return;
        const hasSelection = Object.values(this.app.state.currentFilterSelections).some(val => val);
        this.selects.resetBtn.style.display = hasSelection ? 'inline-flex' : 'none';
    },

    areAllFiltersSelected() {
        if (!this.hasVisibleFilters) return true;
        const visibleFilterKeys = this.filterOrder.filter(key => {
            const groupEl = this.selects[key]?.closest('.clisyc-filter-group');
            return groupEl && groupEl.style.display !== 'none';
        });
        if (visibleFilterKeys.length === 0) return true;
        return visibleFilterKeys.every(key => this.app.state.currentFilterSelections[key] && this.app.state.currentFilterSelections[key] !== '');
    },
};

const CalendarManager = {
    app: null,
    instance: null,
    litepickerInstance: null,
    currentSelectedEventEl: null,

    init(app) {
        this.app = app;
    },

    refetchEvents() {
        this.instance?.refetchEvents();
    },

    clearSlotSelection(clearServiceDetails = true) {
        if (this.currentSelectedEventEl) {
            this.currentSelectedEventEl.classList.remove('fc-event-selected');
            this.currentSelectedEventEl = null;
        }

        if (this.app.el.selectedSlotTextEl) {
            this.app.el.selectedSlotTextEl.textContent = clisycAppointmentData.l10n.none || 'None';
        }

        if (this.app.el.selectedSlotDisplayEl) {
            this.app.el.selectedSlotDisplayEl.classList.add('none');
        }
        
        const { formInputs, recurringToggle, recurringOptions } = this.app.el;
        if (formInputs.appointmentDate) formInputs.appointmentDate.value = '';
        if (formInputs.timeSlot) formInputs.timeSlot.value = '';
        if (formInputs.duration) formInputs.duration.value = '';
        
        if (recurringToggle) recurringToggle.checked = false;
        if (recurringOptions) $(recurringOptions).slideUp(200);
        this.app.state.isRecurringBooking = false;

        $(document).trigger('clisyc:slotCleared', clearServiceDetails);
        if (clearServiceDetails) {
            ToastManager.show('Selection cleared', 'info', 2000);
        }
    },

    clearDateRangeSelection() {
        if (this.litepickerInstance) {
            this.litepickerInstance.clearSelection();
        }
        const { formInputs } = this.app.el;
        if(formInputs.startDate) formInputs.startDate.value = '';
        if(formInputs.endDate) formInputs.endDate.value = '';
        if (this.app.el.summaryEl) {
            this.app.el.summaryStartDate.textContent = '';
            this.app.el.summaryEndDate.textContent = '...';
            this.app.el.summaryNights.textContent = '...';
            $(this.app.el.summaryEl).hide();
        }
        FormManager.updateButtonState();
    },

    async initializeDateRangePicker(dimensionId) {
        const { restUrl } = clisycAppointmentData;
        const { inlineDatePickerEl, formInputs, summaryEl, summaryStartDate, summaryEndDate, summaryNights } = this.app.el;

        if (!inlineDatePickerEl) {
            return;
        }
        
        inlineDatePickerEl.innerHTML = '<p><em>Loading availability...</em></p>';

        if (this.litepickerInstance) {
            this.litepickerInstance.destroy();
        }

        if (!dimensionId) {
            this.app.state.disabledDatesForPicker = [];
        } else {
             try {
                const bookedDatesUrl = `${restUrl.replace('/slots', '/slots/booked-dates')}?dimension_id=${dimensionId}&start=${new Date().toISOString()}&end=${new Date(new Date().setFullYear(new Date().getFullYear() + 2)).toISOString()}`;
                const response = await fetch(bookedDatesUrl);
                if (!response.ok) throw new Error('Failed to fetch booked dates');
                this.app.state.disabledDatesForPicker = await response.json();
            } catch (error) {
                console.error('Error fetching disabled dates:', error);
                this.app.state.disabledDatesForPicker = [];
            }
        }
        
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        this.litepickerInstance = new Litepicker({
            element: inlineDatePickerEl,
            inlineMode: true,
            singleMode: false,
            allowRepick: true,
            minDate: today,
            format: 'YYYY-MM-DD',
            numberOfMonths: window.innerWidth < 768 ? 1 : 2,
            numberOfColumns: window.innerWidth < 768 ? 1 : 2,
            lockDays: this.app.state.disabledDatesForPicker,
            setup: (picker) => {
                picker.on('selected', (date1, date2) => {
                    if (date1) {
                        formInputs.startDate.value = date1.format('YYYY-MM-DD');
                        summaryStartDate.textContent = date1.format('MMM D, YYYY');
                        $(summaryEl).show();

                        if (date2) {
                            formInputs.endDate.value = date2.format('YYYY-MM-DD');
                            summaryEndDate.textContent = date2.format('MMM D, YYYY');
                            const diffTime = Math.abs(date2.toJSDate() - date1.toJSDate());
                            summaryNights.textContent = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                        } else {
                            formInputs.endDate.value = '';
                            summaryEndDate.textContent = '...';
                            summaryNights.textContent = '...';
                        }
                    } else {
                        formInputs.startDate.value = '';
                        formInputs.endDate.value = '';
                        $(summaryEl).hide();
                    }
                    $(document).trigger('clisyc:dateRangeSelected');
                });
            }
        });
    },

    initializeInstance() {
        if (this.instance) {
            this.refetchEvents();
            return;
        }

        const { calendarOptions, nextAvailableDate, restUrl, restNonce, hideBooked, l10n, spinnerUrl, colorSettings } = clisycAppointmentData;
        const { showOverviewAvailability, ...nativeCalendarOptions } = calendarOptions;
        const calendarEl = document.getElementById('clisyc-calendar');
        if (!calendarEl) return;
        
        const timezoneSelector = document.getElementById('clisyc-calendar-timezone-selector');
        
        // Detect screen size for responsive behavior
        const isMobile = window.innerWidth < 768;
        
        // Dynamically build the toolbar views from the settings
        const defaultViews = ['dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek'];
        const enabledViews = calendarOptions.enabledViews || defaultViews;
        const rightToolbarDesktop = enabledViews.length > 0 ? enabledViews.join(',') : 'timeGridWeek';

        calendarEl.classList.add('fc-initializing');
        this.instance = new FullCalendar.Calendar(calendarEl, {
            ...nativeCalendarOptions,
            firstDay: calendarOptions.startOfWeek, // <-- ADD THIS LINE
            eventStartEditable: false,
            eventDurationEditable: false,
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: isMobile ? 'timeGridDay,listWeek' : rightToolbarDesktop
            },
            // Use day view on mobile, week view on tablet+
            initialView: isMobile ? 'timeGridDay' : (calendarOptions.initialView || 'timeGridWeek'),
            height: 'auto',
            nowIndicator: true,
            timeZone: timezoneSelector ? timezoneSelector.value : (calendarOptions.timeZone || 'local'),
            initialDate: nextAvailableDate || null,
            events: (fetchInfo, successCallback, failureCallback) => {
                const isOverviewMode = (showOverviewAvailability || 'none') !== 'none';
                
                if (!Object.values(this.app.state.currentFilterSelections).some(val => val) && !isOverviewMode) {
                    successCallback([]);
                    EmptyStateManager.show(calendarEl, 'select-filters');
                    ServiceDetailsManager.clear();
                    calendarEl.classList.remove('loading');
                    if (spinnerUrl) calendarEl.style.backgroundImage = 'none';
                    return;
                }

                calendarEl.classList.add('loading');
                if (spinnerUrl) calendarEl.style.backgroundImage = `url('${spinnerUrl}')`;
                
                const queryParams = new URLSearchParams({
                    start: fetchInfo.start.toISOString(),
                    end: fetchInfo.end.toISOString(),
                    _wpnonce: restNonce
                });
                
                Object.entries(this.app.state.currentFilterSelections).forEach(([key, value]) => {
                    if (value) queryParams.append(key, value);
                });

                if (isOverviewMode && !Object.values(this.app.state.currentFilterSelections).some(val => val)) {
                    queryParams.append('overview_mode', showOverviewAvailability);
                }
                
                if (hideBooked) queryParams.append('hide_booked', 'true');
                
                fetch(`${restUrl}?${queryParams.toString()}`)
                    .then(response => response.ok ? response.json() : Promise.reject('Network response was not ok.'))
                    .then(data => {
                        calendarEl.classList.remove('loading');
                        if (spinnerUrl) calendarEl.style.backgroundImage = 'none';
                        if (data && data.events) {
                            if (data.events.length > 0) {
                                successCallback(data.events);
                            } else {
                                successCallback([]);
                                EmptyStateManager.show(calendarEl, 'no-slots');
                                ToastManager.show('No available slots found', 'warning', 3000);
                            }
                            this.app.state.lastFetchedServiceDetails = data.selectedServiceDetails || null;
                            if (FilterManager.areAllFiltersSelected()) {
                                ServiceDetailsManager.display(this.app.state.lastFetchedServiceDetails);
                            }
                        } else {
                            successCallback([]);
                            EmptyStateManager.show(calendarEl, 'no-slots');
                            ToastManager.show('No available slots found', 'warning', 3000);
                        }
                    })
                    .catch(error => {
                        calendarEl.classList.remove('loading');
                        if (spinnerUrl) calendarEl.style.backgroundImage = 'none';
                        failureCallback(error);
                        console.error('Error fetching calendar events:', error);
                    });
            },
            eventClick: (info) => {
                const props = info.event.extendedProps;
                const currentOverviewSetting = showOverviewAvailability || 'none';
            
                if (props.isOverview && currentOverviewSetting === 'overview_bookable' && !FilterManager.areAllFiltersSelected()) {
                    this.app.state.isProgrammaticChange = true;
                    FilterManager.syncOptions({ ...(props.dimensions || {}) }).then(() => {
                       this.app.state.isProgrammaticChange = false;
                       $(document).trigger('clisyc:filtersChanged');
                    }).catch(err => { console.error('[ClientSync] Overview filter sync failed:', err); });
                    return;
                }
            
                if (this.app.el.recurringToggle) this.app.el.recurringToggle.checked = false;
                if (this.app.el.recurringOptions) $(this.app.el.recurringOptions).hide();
                this.app.state.isRecurringBooking = false;

                this.clearSlotSelection(false);

                const { attendeesWrapper, attendees } = this.app.el.formInputs;
                if (attendeesWrapper) {
                    if (props.capacity > 1) {
                        attendees.value = 1;
                        attendees.max = props.spotsLeft;
                        attendeesWrapper.style.display = 'block';
                    } else {
                        attendees.value = 1;
                        attendeesWrapper.style.display = 'none';
                    }
                }
            
                if (props?.isBookable && props?.isBookableForUser && !props?.isBooked && !props?.is_block && !props?.isPastOrTooSoon) {
                    const { formInputs } = this.app.el;
                    info.el.classList.add('fc-event-selected');
                    this.currentSelectedEventEl = info.el;
                    if (formInputs.appointmentDate) formInputs.appointmentDate.value = info.event.start.toISOString().split('T')[0];
                    if (formInputs.timeSlot) formInputs.timeSlot.value = props.slotIdentifier;
                    if (formInputs.duration) formInputs.duration.value = props.durationMinutes;
                    if (this.app.el.selectedSlotTextEl) {
                        this.app.el.selectedSlotTextEl.textContent = `${this.instance.formatDate(info.event.start, { weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })}`;
                    }
                    if (this.app.el.selectedSlotDisplayEl) {
                        this.app.el.selectedSlotDisplayEl.classList.remove('none');
                    }
                    
                    FormManager.updateHiddenFilterInputs();
                    
                    const { userCredits, paymentRequired } = clisycAppointmentData;
                    const serviceId = props.serviceDetails.id;
                    const serviceCreditKey = `service_${serviceId}`;
                    const creditSection = document.getElementById('clisyc-credit-redemption-section');
                    const paymentSection = document.getElementById('clisyc_payment_options');
                    const useCreditFlag = document.getElementById('clisyc-use-credit-flag');

                    if (creditSection && paymentSection && useCreditFlag && userCredits?.[serviceCreditKey]?.credits_remaining > 0) {
                        $(paymentSection).hide();
                        $('#clisyc-credit-message').text(`You have ${userCredits[serviceCreditKey].credits_remaining} credits available for this service.`);
                        $(creditSection).slideDown();
                        useCreditFlag.value = '1';
                    } else if (useCreditFlag) {
                        $(creditSection).hide();
                        useCreditFlag.value = '0';
                        if (paymentRequired) $(paymentSection).show();
                    }

                    $(document).trigger('clisyc:slotSelected', { event: info.event, el: info.el });

                } else {
                    this.clearSlotSelection(true);
                }
            },
            eventDataTransform: (eventData) => ({
                ...eventData,
                id: eventData.extendedProps.slotIdentifier || eventData.id,
                startEditable: false,
                durationEditable: false
            }),
            eventContent: (arg) => {
                const props = arg.event.extendedProps;
                const details = props.serviceDetails || {};
                let html = '';

                const buildLabelWithDot = (label, color) => {
                    if (!label) return '';
                    const safeColor = color && /^#[0-9a-fA-F]{3,8}$/.test(color.trim()) ? color.trim() : '';
                    const colorDot = safeColor ? `<span class="clisyc-event-color-dot" style="background-color: ${safeColor};"></span>` : '';
                    return `<div class="clisyc-event-label-group">${colorDot}${escapeHtml(label)}</div>`;
                };

                if (arg.view.type.startsWith('list')) {
                    let listContentHtml = (props.isBooked || props.is_block || props.isPastOrTooSoon) ?
                        `<div class="clisyc-event-label-group">${arg.event.title}</div>` : (props.isBookable ?
                        Object.keys(details).filter(k => k.endsWith('_name')).map(k => buildLabelWithDot(details[k], details[k.replace('_name', '_color')])).join('') :
                        `<div class="clisyc-event-label-group">${arg.event.title}</div>`);
                    html = `<div class="fc-list-event-title-container">${listContentHtml}</div>`;
                } else {
                    const timeText = arg.timeText || (arg.event.start ? this.instance.formatDate(arg.event.start, { hour: 'numeric', minute: '2-digit', hour12: true }) : '');
                    let line1Text = '', secondaryContent = '';

                    if (props.isBooked || props.is_block || props.isPastOrTooSoon) {
                        line1Text = props.isBooked ? (l10n.bookedSlot || 'Booked') : (l10n.unavailable || 'Unavailable');
                    } else if (props.isBookable) {
                        line1Text = timeText;
                        if (props.bookingRestrictionMessage) secondaryContent += `<div class="clisyc-membership-notice">${escapeHtml(props.bookingRestrictionMessage)}</div>`;
                        let primaryLabel = buildLabelWithDot(details.service_name, details.service_color);
                        if (props.capacity > 1) {
                            primaryLabel += ` <small>(${props.spotsLeft} ${l10n.spotsLeft || 'spots left'})</small>`;
                        }
                        
                        const detailsToShow = Object.keys(details).filter(k => k.endsWith('_name') && `clisyc_${k.replace('_name', '')}` !== this.app.state.primaryServiceDimKey)
                            .map(k => buildLabelWithDot(details[k], details[k.replace('_name', '_color')]));
                        secondaryContent += primaryLabel + detailsToShow.join('');
                    } else {
                        line1Text = arg.event.title;
                    }

                    let durationHtml = '';
                    if (props.isBookable && props.durationMinutes > 0) {
                        durationHtml = `<div class="clisyc-event-duration"><span class="dashicons dashicons-clock"></span> ${props.durationMinutes} min</div>`;
                    }

                    html = `<div class="fc-event-main-frame">`;
                    if (line1Text) html += `<div class="fc-event-line-1">${line1Text}</div>`;
                    if (secondaryContent) html += `<div class="fc-event-line-2">${secondaryContent}</div>`;
                    html += durationHtml; // <-- This is the new line we're adding
                    html += `</div>`;
                }

                return { html: html };
            },
            eventDidMount: (info) => {
                const props = info.event.extendedProps;
                const el = info.el;
                let style = {};

                if (props.isBookable && !props.isBookableForUser) el.classList.add('clisyc-membership-restricted');

                if (colorSettings.font_size) style.fontSize = colorSettings.font_size + 'px';
                if (props.isBooked) {
                    if (colorSettings.booked_bg) style.backgroundColor = style.borderColor = colorSettings.booked_bg;
                    if (colorSettings.booked_text) style.color = colorSettings.booked_text;
                } else if (props.is_block) {
                    if (colorSettings.blocked_bg) style.backgroundColor = style.borderColor = colorSettings.blocked_bg;
                    if (colorSettings.blocked_text) style.color = colorSettings.blocked_text;
                } else if (props.isBookable) {
                    if (colorSettings.available_bg) style.backgroundColor = colorSettings.available_bg;
                    if (colorSettings.available_text) style.color = colorSettings.available_text;
                } else if (props.isOverview) {
                    if (colorSettings.overview_bg) style.backgroundColor = colorSettings.overview_bg;
                    if (colorSettings.overview_text) style.color = colorSettings.overview_text;
                }

                for (const prop in style) {
                    el.style.setProperty(prop.replace(/([A-Z])/g, '-$1').toLowerCase(), style[prop], 'important');
                }

                if (typeof $.fn.tooltip !== 'undefined') {
                    const details = props.serviceDetails || {};
                    let tooltipContent = FilterManager.filterOrder
                        .map(slug => {
                            const jsKey = slug.replace('clisyc_', '');
                            if (details[jsKey + '_name']) {
                                const label = l10n[slug] || clisycAppointmentData.availabilityDimensions[slug]?.label || jsKey;
                                return `<strong>${escapeHtml(label)}:</strong> ${escapeHtml(details[jsKey + '_name'])}<br>`;
                            }
                            return '';
                        }).join('');

                    if (details.duration) tooltipContent += `<strong>${escapeHtml(l10n.duration || 'Duration')}:</strong> ${escapeHtml(details.duration)} ${escapeHtml(l10n.minutes || 'minutes')}<br>`;
                    if (details.price > 0) tooltipContent += `<strong>${escapeHtml(l10n.price || 'Price')}:</strong> ${escapeHtml(ServiceDetailsManager.formatPrice(details.price))}`;
                    
                    if (tooltipContent) $(el).tooltip({ content: tooltipContent, track: true, show: { duration: 100 }, hide: { duration: 100 }, classes: { "ui-tooltip": "clisyc-calendar-tooltip" }});
                }
            },
            // Destroy tooltips when events are removed to prevent memory leaks.
            eventWillUnmount: (info) => {
                const $el = $(info.el);
                if ($el.data('ui-tooltip')) {
                    $el.tooltip('destroy');
                }
            }
        });

        this.instance.render();
        calendarEl.classList.remove('fc-initializing');

        // Add resize listener to handle responsive changes
        if (!this.resizeListenerAttached) {
            const handleResize = () => {
                if (!this.instance) return;

                const isMobile = window.innerWidth < 768;
                
                // Change view based on screen size on resize
                const newView = isMobile ? 'timeGridDay' : (calendarOptions.initialView || 'timeGridWeek');
                if (this.instance.view.type !== newView) {
                    this.instance.changeView(newView);
                }

                // Also update the toolbar dynamically on resize
                const rightToolbarDesktopResize = (calendarOptions.enabledViews || ['dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek']).join(',');
                this.instance.setOption('headerToolbar', {
                    left: 'prev,next today',
                    center: 'title',
                    right: isMobile ? 'timeGridDay,listWeek' : rightToolbarDesktopResize
                });
            };

            window.addEventListener('resize', handleResize);
            this.resizeListenerAttached = true;
            this._handleResize = handleResize; // Store reference for cleanup.
        }
    },

    /** Remove resize listener to prevent memory leaks. */
    destroy() {
        if (this._handleResize) {
            window.removeEventListener('resize', this._handleResize);
            this._handleResize = null;
            this.resizeListenerAttached = false;
        }
    },
};

const ServiceDetailsManager = {
    app: null,
    
    init(app) {
        this.app = app;
    },

    display(details, event = null) {
        const { l10n } = clisycAppointmentData;
        const container = this.app.el.serviceDetailsContainer;
        if (!container || !details || Object.keys(details).length === 0) {
            this.clear();
            return;
        }

        // Every dynamic interpolation below is escapeHtml-wrapped. The l10n
        // strings are static WordPress translations but pass through the
        // `gettext` filter, so a rogue translation file could otherwise
        // inject HTML; formatDate() output is safe intrinsically but we
        // wrap it anyway for consistency.
        let html = `<h3>${escapeHtml(l10n.serviceDetails || 'Service Details')}</h3>`;
        if (event?.start && CalendarManager.instance) {
            html += `<p class="clisyc-service-date"><strong>${escapeHtml(l10n.date || 'Date')}:</strong> ${escapeHtml(CalendarManager.instance.formatDate(event.start, { month: 'long', day: 'numeric', year: 'numeric' }))}</p>`;
            html += `<p class="clisyc-service-time"><strong>${escapeHtml(l10n.time || 'Time')}:</strong> ${escapeHtml(CalendarManager.instance.formatDate(event.start, { hour: 'numeric', minute: '2-digit', hour12: true }))}</p>`;
        }
        
        FilterManager.filterOrder.forEach(slug => {
            const jsKey = slug.replace('clisyc_', '');
            if (details[jsKey + '_name']) {
                const label = l10n[slug] || clisycAppointmentData.availabilityDimensions[slug]?.label || jsKey;
                html += `<p class="clisyc-service-detail"><strong>${escapeHtml(label)}:</strong> ${escapeHtml(details[jsKey + '_name'])}</p>`;
            }
        });

        if (details.price > 0) html += `<p id="clisyc-dynamic-price-container" class="clisyc-service-price"><strong>${escapeHtml(l10n.price || 'Price')}:</strong> ${escapeHtml(this.formatPrice(details.price))}</p>`;
        if (details.duration > 0) html += `<p class="clisyc-service-duration"><strong>${escapeHtml(l10n.duration || 'Duration')}:</strong> ${escapeHtml(details.duration)} ${escapeHtml(l10n.minutes || 'minutes')}</p>`;
        if (details.description) html += `<p class="clisyc-service-description">${escapeHtml(details.description)}</p>`;

        container.innerHTML = html;
        container.style.display = 'block';
        this.updatePriceDisplay();
    },
    
    clear() {
        const container = this.app.el.serviceDetailsContainer;
        if (container) {
            container.innerHTML = '';
            container.style.display = 'none';
        }
    },

    async updatePriceDisplay() {
        const priceContainer = document.getElementById('clisyc-dynamic-price-container');
        if (!priceContainer) return;

        const { formInputs } = this.app.el;
        const { currentBookingMode, lastFetchedServiceDetails } = this.app.state;
        const { l10n, restUrl } = clisycAppointmentData;
        const startDate = formInputs.startDate.value;
        const endDate = formInputs.endDate.value;
        const product = lastFetchedServiceDetails?.wc_product_id;

        if (currentBookingMode !== 'date_range' || !startDate || !endDate || !product) {
            priceContainer.style.display = 'none';
            return;
        }

        priceContainer.style.display = 'block';
        priceContainer.innerHTML = `<strong>${l10n.price || 'Price'}:</strong> <em>${l10n.calculating || 'Calculating...'}</em>`;

        try {
            const priceUrl = `${restUrl.replace('/slots', '/price')}?product_id=${product}&start_date=${startDate}&end_date=${endDate}`;
            const response = await fetch(priceUrl);
            const data = await response.json();
            if (response.ok) {
                priceContainer.innerHTML = `<strong>${l10n.price || 'Price'}:</strong> ${data.formatted}`;
            } else {
                throw new Error(data.message || 'Error');
            }
        } catch (error) {
            priceContainer.innerHTML = `<strong>${l10n.price || 'Price'}:</strong> <em style="color:red;">${l10n.priceError || 'Could not calculate price.'}</em>`;
        }
    },
    
    updateReadOnlyFilterDisplays(event = null) {
        const displayContainers = document.querySelectorAll('.clisyc-readonly-filter-display-container');
        if (!displayContainers.length) return;
        
        const { currentFilterSelections } = this.app.state;
        const { availabilityDimensions, l10n } = clisycAppointmentData;
        let html = '';
        
        // dimInfo.label and option.label originate from admin-controlled
        // dimension config / CPT post titles (e.g. "Initial Consultation",
        // "Dr. Carter"). An attacker with post-authoring rights could set
        // a title like `<img src=x onerror=...>` and land it here unescaped.
        // Escape every dynamic interpolation.
        FilterManager.filterOrder.forEach(slug => {
            const value = currentFilterSelections[slug];
            if (value && availabilityDimensions[slug]) {
                const dimInfo = availabilityDimensions[slug];
                const option = dimInfo.options?.find(opt => opt.value == value);
                const valueLabel = option ? option.label.replace(/\s*\(\d+\)$/, '').trim() : value;
                html += `<p><label>${escapeHtml(dimInfo.label)}:</label> <span class="clisyc-readonly-filter-value">${escapeHtml(valueLabel)}</span></p>`;
            }
        });

        if (event?.start && CalendarManager.instance) {
            const details = event.extendedProps.serviceDetails || {};
            const instance = CalendarManager.instance;
            html += `<p><label>${escapeHtml(l10n.date || 'Date')}:</label> <span class="clisyc-readonly-filter-value">${escapeHtml(instance.formatDate(event.start, { month: 'long', day: 'numeric', year: 'numeric' }))}</span></p>`;
            html += `<p><label>${escapeHtml(l10n.dayOfWeek || 'Day of the week')}:</label> <span class="clisyc-readonly-filter-value">${escapeHtml(instance.formatDate(event.start, { weekday: 'long' }))}</span></p>`;
            html += `<p><label>${escapeHtml(l10n.time || 'Time')}:</label> <span class="clisyc-readonly-filter-value">${escapeHtml(instance.formatDate(event.start, { hour: 'numeric', minute: '2-digit', hour12: true }))}</span></p>`;
            if (details.price > 0) html += `<p><label>${escapeHtml(l10n.price || 'Price')}:</label> <span class="clisyc-readonly-filter-value">${escapeHtml(this.formatPrice(details.price))}</span></p>`;
            if (details.duration > 0) html += `<p><label>${escapeHtml(l10n.duration || 'Duration')}:</label> <span class="clisyc-readonly-filter-value">${escapeHtml(details.duration)} ${escapeHtml(l10n.minutes || 'minutes')}</span></p>`;
        }

        displayContainers.forEach(c => { c.innerHTML = html || `<p><em>${escapeHtml(l10n.selectAvailability || 'Please make selections to view calendar.')}</em></p>`; });
    },

    formatPrice(price) {
        const numPrice = parseFloat(price);
        if (isNaN(numPrice)) return price;
        return (clisycAppointmentData.currencySymbol || '$') + numPrice.toFixed(2);
    }
};

const FormManager = {
    app: null,

    init(app) {
        this.app = app;
        this.setupListeners();
    },

    setupListeners() {
        const { appointmentForm, recurringToggle, recurringOptions, formInputs, startDateInput, endDateInput } = this.app.el;
        
        if (appointmentForm) {
            appointmentForm.addEventListener('submit', (e) => this.handleSubmit(e));
        }
        
        if (recurringToggle && recurringOptions) {
            recurringToggle.addEventListener('change', () => {
                $(recurringOptions).slideToggle(recurringToggle.checked);
                this.updateButtonState();
            });
        }
        
        if (formInputs.paymentOptions) {
            formInputs.paymentOptions.addEventListener('click', (e) => {
                if (e.target.matches('input[name="clisyc_wc_payment_token_id"]')) this.updateButtonState();
            });
        }
        
        if (clisycAppointmentData.isGuest) {
            Object.values(formInputs.guestRegFields).forEach(input => {
                if (input) input.addEventListener('input', () => this.updateButtonState());
            });
        }

        if (startDateInput) $(startDateInput).on('change', () => this.updateButtonState());
        if (endDateInput) $(endDateInput).on('change', () => this.updateButtonState());
    },
    
    handleSubmit(e) {
        e.preventDefault();
        const { l10n } = clisycAppointmentData;
        
        this.app.state.isRecurringBooking = this.app.el.recurringToggle ? this.app.el.recurringToggle.checked : false;

        if (this.app.state.isRecurringBooking) {
            const recurringFreqEl = document.getElementById('clisyc-recurring-frequency') || document.getElementById('clisyc-recurring-frequency-loggedin');
            const recurringCountEl = document.getElementById('clisyc-recurring-count') || document.getElementById('clisyc-recurring-count-loggedin');
            if (!recurringFreqEl || !recurringCountEl) {
                ToastManager.show(l10n.recurringFieldsMissing || 'Recurring booking fields are not available.', 'error', 5000);
                return;
            }
            const payload = {
                initial_slot_identifier: this.app.el.formInputs.timeSlot.value,
                dimensions: this.app.state.currentFilterSelections,
                frequency: recurringFreqEl.value,
                count: parseInt(recurringCountEl.value, 10),
                custom_fields: {}
            };
            $(this.app.el.appointmentForm).find('[name^="clisyc_custom_field"]').each(function() {
                const name = $(this).attr('name');
                const match = name.match(/\[(.*?)\]/);
                if (!match) return;
                const key = match[1];
                if (($(this).is(':checkbox') || $(this).is(':radio')) && !$(this).is(':checked')) return;
                payload.custom_fields[key] = $(this).val();
            });

            const scheduleButton = this.app.el.formInputs.scheduleButton;
            scheduleButton.disabled = true;
            scheduleButton.classList.add('clisyc-button-processing');
            scheduleButton.textContent = l10n.validating || 'Validating...';
            ToastManager.show('Validating recurring series...', 'info', 2000);

            wp.apiFetch({ path: '/client-sync-pro/v1/appointments/series', method: 'POST', data: payload })
              .then((response) => {
                  if (response.redirect_url && response.payment_required) {
                      // WooCommerce checkout redirect.
                      window.location.href = response.redirect_url;
                  } else {
                      const successUrl = new URL(clisycAppointmentData.successPageUrl || window.location.href);
                      successUrl.searchParams.set('clisyc_booking_status', 'success_series');
                      if (response.appointment_ids && response.appointment_ids[0]) {
                          successUrl.searchParams.set('appt_id', response.appointment_ids[0]);
                      }
                      window.location.href = successUrl.toString();
                  }
              })
              .catch(error => {
                  ToastManager.show('Booking Failed: ' + error.message, 'error', 5000);
                  scheduleButton.disabled = false;
                  scheduleButton.classList.remove('clisyc-button-processing');
                  this.updateButtonState();
              });

        } else {
            if (this.app.el.validationErrorContainer) {
                this.app.el.validationErrorContainer.innerHTML = '';
                $(this.app.el.validationErrorContainer).hide();
            }

            const validationErrors = this.validateForm();

            if (validationErrors.length > 0) {
                ToastManager.show(validationErrors[0], 'error', 5000); // Show first error in toast
                if (this.app.el.validationErrorContainer) {
                    this.app.el.validationErrorContainer.innerHTML = '<ul>' + validationErrors.map(err => `<li>${escapeHtml(err)}</li>`).join('') + '</ul>';
                    $(this.app.el.validationErrorContainer).slideDown();
                    $('html, body').animate({ scrollTop: $(this.app.el.validationErrorContainer).offset().top - 100 }, 500);
                }
            } else {
                this.updateHiddenFilterInputs();
                const scheduleButton = this.app.el.formInputs.scheduleButton;
                if (scheduleButton) {
                    scheduleButton.disabled = true;
                    scheduleButton.classList.add('clisyc-button-processing');
                    scheduleButton.textContent = l10n.processing || 'Processing...';
                }
                this.app.el.appointmentForm.submit();
            }
        }
    },

    validateForm() {
        const errors = [];
        const { currentBookingMode } = this.app.state;
        const { formInputs } = this.app.el;
        const { l10n, customFieldDefinitions, isGuest } = clisycAppointmentData;
        
        $('.clisyc-form-field.clisyc-field-error').removeClass('clisyc-field-error');

        if (currentBookingMode === 'slot' && !formInputs.timeSlot.value.trim()) {
            errors.push(l10n.selectSlotToBook || 'Please select an available time slot from the calendar.');
            $('#clisyc-calendar-and-form-fields').addClass('clisyc-field-error');
        } else if (currentBookingMode === 'date_range' && (!formInputs.startDate.value.trim() || !formInputs.endDate.value.trim())) {
            errors.push('Please select a check-in and check-out date.');
            $('#clisyc-date-range-picker-container').addClass('clisyc-field-error');
        }

        if (customFieldDefinitions) {
            for (const fieldKey in customFieldDefinitions) {
                const fieldDef = customFieldDefinitions[fieldKey];
                const $fieldWrapper = $('.clisyc-form-field-' + fieldKey.replace(/_/g, '-'));
                if (fieldDef.required && $fieldWrapper.is(':visible')) {
                    const $input = $(`[name^="clisyc_custom_field[${fieldKey}]"]`);
                    const value = ($input.is(':checkbox') || $input.is(':radio')) ? $(`[name^="clisyc_custom_field[${fieldKey}]"]:checked`).val() : $input.val();
                    if (value == null || String(value).trim() === '') {
                        errors.push(`Field "${fieldDef.label}" is required.`);
                        $fieldWrapper.addClass('clisyc-field-error');
                    }
                }
            }
        }
        
        if (isGuest) {
            Object.entries(formInputs.guestRegFields).forEach(([key, inputEl]) => {
                if (inputEl && !inputEl.value.trim()) {
                    const label = $(inputEl).prev('label').text().replace('*','').trim();
                    errors.push(`Field "${label}" is required.`);
                    $(inputEl).closest('.clisyc-form-field').addClass('clisyc-field-error');
                }
            });
        }
        return errors;
    },

    updateHiddenFilterInputs() {
        const container = this.app.el.formInputs.filterInputsContainer;
        if (!container) return;
        container.innerHTML = '';
        Object.entries(this.app.state.currentFilterSelections).forEach(([key, value]) => {
            if (value) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                container.appendChild(input);
            }
        });
    },

    updateButtonState() {
        const { scheduleButton, timeSlot, startDate, endDate, guestRegFields, paymentOptions } = this.app.el.formInputs;
        const { l10n, isGuest, paymentRequired } = clisycAppointmentData;
        const { currentBookingMode } = this.app.state;

        if (!scheduleButton) return;
        scheduleButton.disabled = true;
        this.app.state.isRecurringBooking = this.app.el.recurringToggle ? this.app.el.recurringToggle.checked : false;

        const isDateRangeSelected = currentBookingMode === 'date_range' && startDate?.value && endDate?.value;
        const isSlotSelected = currentBookingMode === 'slot' && timeSlot?.value.trim() !== '';

        if (!isDateRangeSelected && !isSlotSelected) {
            scheduleButton.innerHTML = l10n.selectSlotToBook || 'Select Availability to Book';
            return;
        }

        if (this.app.state.isRecurringBooking) {
            scheduleButton.innerHTML = l10n.checkSeriesAvailability || 'Check Series Availability';
            scheduleButton.disabled = false;
            return;
        }
        
        if (isGuest) {
            const { firstName, lastName, email } = guestRegFields;
            if (firstName?.value.trim() && lastName?.value.trim() && email?.value.trim()) {
                scheduleButton.innerHTML = l10n.registerAndBook || 'Register & Book Appointment';
                scheduleButton.disabled = false;
            } else {
                scheduleButton.innerHTML = l10n.completeRegistration || 'Complete Registration Details';
            }
            return;
        }
        
        if (paymentRequired) {
            const paymentSelectedRadio = paymentOptions?.querySelector('input[name="clisyc_wc_payment_token_id"]:checked');
            if (paymentSelectedRadio) {
                const paymentValue = paymentSelectedRadio.value;
                if (paymentValue === 'checkout') scheduleButton.innerHTML = l10n.proceedToCheckout || 'Proceed to Checkout';
                else if (paymentValue === 'pay_on_day') scheduleButton.innerHTML = l10n.confirmPayLater || 'Confirm Appointment (Pay Later)';
                else scheduleButton.innerHTML = l10n.bookPayNow || 'Book & Pay Now';
                scheduleButton.disabled = false;
            } else {
                scheduleButton.innerHTML = l10n.selectPaymentBook || 'Select Payment and Book';
            }
        } else {
            scheduleButton.innerHTML = l10n.scheduleAppointment || 'Schedule Appointment';
            scheduleButton.disabled = false;
        }
    },

    applyConditionalLogic() {
        const { customFieldDefinitions } = clisycAppointmentData;
        const { currentFilterSelections } = this.app.state;
        if (!customFieldDefinitions) return;
        
        const detailsSection = document.getElementById('clisyc-appointment-details-section') || document.getElementById('clisyc-appointment-details-section-guest');
        if (!detailsSection) return;
        
        const evaluateRule = (rule) => {
            const selectedValue = currentFilterSelections[rule.field];
            if (selectedValue == null) return false;
            if (rule.operator === 'is') return String(selectedValue) === String(rule.value);
            if (rule.operator === 'is_not') return String(selectedValue) !== String(rule.value);
            return false;
        };
        
        for (const fieldKey in customFieldDefinitions) {
            const logic = customFieldDefinitions[fieldKey].conditional_logic;
            const $fieldWrapper = $(detailsSection).find('.clisyc-form-field-' + fieldKey.replace(/_/g, '-'));
            if (!$fieldWrapper.length) continue;
            
            if (!logic?.enabled || !logic?.rules?.length) {
                $fieldWrapper.slideDown(200);
                continue;
            }
            
            const showField = (logic.condition === 'any') ? logic.rules.some(evaluateRule) : logic.rules.every(evaluateRule);
            $fieldWrapper.slideToggle(showField, 200);
        }
    }
};

const TimezoneManager = {
    app: null,
    liveClockInterval: null,
    
    init(app) {
        this.app = app;
        this.setupTimezonePicker();
    },

    setupTimezonePicker() {
        const selector = document.getElementById('clisyc-calendar-timezone-selector');
        if (!selector) return;

        const commonTimezones = ['local', 'UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/London', 'Europe/Paris', 'Asia/Tokyo'];
        commonTimezones.forEach(tz => {
            const option = document.createElement('option');
            option.value = tz;
            option.textContent = tz.replace(/_/g, ' ');
            selector.appendChild(option);
        });

        $(selector).clisycCustomDropdown({
            onChange: (value) => {
                if (CalendarManager.instance) {
                    CalendarManager.instance.setOption('timeZone', value);
                }
                this.updateTimezoneNote(value);
                this.manageLiveClock(value);
            }
        });
        
        this.updateTimezoneNote('local');
        this.manageLiveClock('local');
    },

    updateTimezoneNote(tz) {
        const noteEl = document.getElementById('clisyc-timezone-note');
        if (!noteEl) return;
        const { l10n } = clisycAppointmentData;
        noteEl.textContent = (tz === 'local') ?
            (l10n.tzNoteLocal || 'Calendar is currently showing times in your local timezone.') :
            (l10n.tzNoteSelected || 'Calendar is currently showing times in: %s').replace('%s', tz.replace(/_/g, ' '));
    },

    manageLiveClock(timezone) {
        const clockEl = document.getElementById('clisyc-live-clock');
        if (!clockEl) return;

        if (this.liveClockInterval) clearInterval(this.liveClockInterval);

        const updateClock = () => {
            try {
                clockEl.textContent = new Date().toLocaleTimeString('en-US', {
                    timeZone: timezone === 'local' ? undefined : timezone,
                    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
                });
            } catch (e) {
                clockEl.textContent = 'Invalid Timezone';
                clearInterval(this.liveClockInterval);
            }
        };

        updateClock();
        this.liveClockInterval = setInterval(updateClock, 1000);
    },
};

// Kick off the application
$(function() {
    BookingApp.init();
});

})(jQuery);