/**
 * File: src/assets/js/clisyc-frontend-date-range-booking.js
 * REFACTORED: Replaced TomSelect with the lightweight, custom jQuery dropdown.
 * This completes the removal of the TomSelect dependency from the plugin.
 */
(function($) {
'use strict';

$(function() {
    if (typeof clisycDateRangeData === 'undefined') {
        return;
    }

    const { restUrl, restNonce, availabilityDimensions, filterOrder, primaryServiceDimKey, l10n, preselected_id, wc_integration_enabled } = clisycDateRangeData;

    // Race condition guard: incremented on each async call, stale responses are discarded.
    let syncRequestId = 0;

    // DOM Elements
    const appointmentForm = document.getElementById('clisyc_appointment_form');
    const filtersContainer = document.getElementById('clisyc-availability-filters');
    const datePickerContainer = document.getElementById('clisyc-date-range-picker-container');
    const datePickerEl = document.getElementById('clisyc-inline-datepicker');
    const hiddenInputsContainer = document.getElementById('clisyc-hidden-inputs-container');
    const startDateHidden = document.getElementById('clisyc_start_date_hidden');
    const endDateHidden = document.getElementById('clisyc_end_date_hidden');
    const summaryEl = document.getElementById('clisyc-date-range-summary');
    const summaryStartDate = document.getElementById('clisyc-summary-start-date');
    const summaryEndDate = document.getElementById('clisyc-summary-end-date');
    const summaryNights = document.getElementById('clisyc-summary-nights');
    const priceContainer = document.getElementById('clisyc-dynamic-price-container');
    const scheduleButton = document.getElementById('schedule_button');
    const serviceDetailsContainer = document.getElementById('clisyc-service-details-container');

    // State
    let currentFilterSelections = {};
    let customDropdownInstances = {};
    let availabilityRules = { minStay: 1, maxStay: 0, checkinDays: [], checkoutDays: [] };
    let disabledDatesForPicker = [];
    let isProgrammaticChange = false;
    let lastFetchedServiceDetails = null;
    let litepickerInstance = null;
    
    const clearServiceDetails = () => {
        lastFetchedServiceDetails = null;
        if (serviceDetailsContainer) {
            serviceDetailsContainer.innerHTML = '';
            $(serviceDetailsContainer).hide();
        }
    };

    // HTML escape helper to prevent XSS from API data.
    const escapeHtml = (str) => {
        if (typeof str !== 'string') return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    };

    const displayServiceDetails = (details) => {
        if (!serviceDetailsContainer || !details || !details.title) {
            clearServiceDetails();
            return;
        }
        lastFetchedServiceDetails = details;

        // SECURITY: Escape all API-sourced values to prevent XSS.
        let html = `<h3>${escapeHtml(l10n.details || 'Details')}</h3>`;
        html += `<p><strong>${escapeHtml(details.title)}</strong></p>`;
        if (details.description) {
            html += `<p>${escapeHtml(details.description)}</p>`;
        }
        if (details.price && parseFloat(details.price) > 0) {
            html += `<p class="clisyc-service-price"><strong>${escapeHtml(l10n.basePricePerNight || 'Base Price / Night')}:</strong> ${escapeHtml(details.price)}</p>`;
        }

        serviceDetailsContainer.innerHTML = html;
        $(serviceDetailsContainer).show();
    };
    
    const filterManager = {
        selects: {},
        build() {
            if (!filtersContainer) return;
            const filterWrapper = document.createElement('div');
            filterWrapper.className = 'clisyc-filter-group-wrapper';
            filterOrder.forEach(key => {
                const dim = availabilityDimensions[key];
                if (dim) {
                    const groupEl = document.createElement('div');
                    groupEl.className = 'clisyc-filter-group clisyc-form-field';
                    const labelEl = document.createElement('label');
                    labelEl.htmlFor = `clisyc_filter_${key}`;
                    labelEl.textContent = dim.label + ':';
                    const selectEl = document.createElement('select');
                    selectEl.id = `clisyc_filter_${key}`;
                    selectEl.dataset.filterKey = key;
                    groupEl.appendChild(labelEl);
                    groupEl.appendChild(selectEl);
                    filterWrapper.appendChild(groupEl);
                    this.selects[key] = selectEl;
                }
            });
            filtersContainer.insertBefore(filterWrapper, document.getElementById('clisyc-filter-status'));
        },
        initializeCustomDropdowns() {
            filterOrder.forEach(key => {
                const selectEl = this.selects[key];
                if (selectEl) {
                    $(selectEl).clisycCustomDropdown({
                        onChange: (value) => {
                            this.onFilterChange();
                        }
                    });
                    customDropdownInstances[key] = $(selectEl).data('clisycCustomDropdown');
                }
            });
        },
        async onFilterChange() {
            if (isProgrammaticChange) return;
            const newSelections = {};
            filterOrder.forEach(key => {
                const value = $(this.selects[key]).val();
                if (value) {
                    newSelections[key] = value;
                }
            });
            currentFilterSelections = newSelections;
            await this.syncOptions(currentFilterSelections);
        },
        async initialLoad() {
            if (!filtersContainer) return;
            $(filtersContainer).addClass('clisyc-filters-loading');
            try {
                const firstFilterKey = filterOrder[0];
                if (firstFilterKey) {
                    const postsUrl = `${restUrl.replace('/slots', '/dimensions/posts')}?type=${firstFilterKey}`;
                    const response = await fetch(postsUrl);
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    const options = await response.json();
                    
                    isProgrammaticChange = true;
                    this.updateDropdown(firstFilterKey, options, null);
                    isProgrammaticChange = false;

                    let initialSelection = {};
                    if (options.length > 0) {
                        const firstOptionValue = options[0].value;
                        customDropdownInstances[firstFilterKey]?.setValue(firstOptionValue);
                        initialSelection[firstFilterKey] = firstOptionValue;
                    }
                    
                    currentFilterSelections = initialSelection;
                    await this.syncOptions(initialSelection);
                }
            } catch(error) {
                console.error("Error during initial filter load:", error);
            } finally {
                $(filtersContainer).removeClass('clisyc-filters-loading');
            }
        },
        async syncOptions(selections) {
            if (filtersContainer) $(filtersContainer).addClass('clisyc-filters-loading');
            const myRequestId = ++syncRequestId;
            try {
                const queryParams = new URLSearchParams({ _wpnonce: restNonce });
                Object.entries(selections).forEach(([key, value]) => {
                    if (value) queryParams.append(key, value);
                });
                const optionsRestUrl = restUrl.replace('/slots', '/filter-options');
                const response = await fetch(`${optionsRestUrl}?${queryParams.toString()}`);
                if (!response.ok) throw new Error('HTTP ' + response.status);
                // Race condition guard: discard stale responses from earlier requests.
                if (myRequestId !== syncRequestId) return;
                const finalData = await response.json();
                const selectedServiceDetails = finalData.selectedServiceDetails || null;
                const primaryDimId = selections[primaryServiceDimKey];

                if (primaryDimId && finalData[primaryServiceDimKey]) {
                    const serviceOption = finalData[primaryServiceDimKey].find(opt => opt.value == primaryDimId);
                    if (serviceOption) {
                        availabilityRules.minStay = serviceOption.min_stay || 1;
                        availabilityRules.maxStay = serviceOption.max_stay || 0;
                    }
                }
                
                isProgrammaticChange = true;
                filterOrder.forEach((slug, index) => {
                    if (index > 0) this.updateDropdown(slug, finalData[slug], selections[slug]);
                });
                isProgrammaticChange = false;

                displayServiceDetails(selectedServiceDetails);
                initializeDatepicker();
                updateHiddenFilterInputs();
            } finally {
                if (filtersContainer) $(filtersContainer).removeClass('clisyc-filters-loading');
            }
        },
        updateDropdown(key, options, selectedValue) {
            const selectEl = this.selects[key];
            const customDropdown = customDropdownInstances[key];
            if (!selectEl || !customDropdown) return;

            $(selectEl).empty();
            $(selectEl).append($('<option>', { value: '', text: `-- Select a ${availabilityDimensions[key]?.label || key} --` }));
            
            if (options && options.length) {
                options.forEach(opt => {
                    const $option = $('<option>', { value: opt.value, text: opt.label });
                    if (opt.color) $option.data('color', opt.color);
                    $(selectEl).append($option);
                });
                selectEl.disabled = false;
            } else {
                selectEl.disabled = true;
            }

            $(selectEl).val(selectedValue || '');
            customDropdown.populateOptions();
        }
    };

    const initializeDatepicker = async () => {
        const primaryDimId = currentFilterSelections[primaryServiceDimKey];
        if (!primaryDimId) {
            $(datePickerContainer).hide();
            return;
        }
        $(datePickerContainer).show();
        $(datePickerEl).empty();

        const bookedDatesUrl = `${restUrl}slots/booked-dates?dimension_id=${primaryDimId}&start=${new Date().toISOString()}&end=${new Date(new Date().setFullYear(new Date().getFullYear() + 2)).toISOString()}`;
        try {
            const response = await fetch(bookedDatesUrl);
            if (response.ok) {
                const data = await response.json();
                disabledDatesForPicker = Array.isArray(data) ? data : [];
            } else {
                disabledDatesForPicker = [];
            }
        } catch (error) {
            console.error('Error fetching booked dates:', error);
            disabledDatesForPicker = [];
        }

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        if (litepickerInstance) {
            litepickerInstance.destroy();
        }
        
        litepickerInstance = new Litepicker({
            element: datePickerEl,
            inlineMode: true,
            singleMode: false,
            numberOfMonths: window.innerWidth < 768 ? 1 : 2,
            numberOfColumns: window.innerWidth < 768 ? 1 : 2,
            minDate: today,
            lockDaysFilter: (date) => {
                const dateString = date.format('YYYY-MM-DD');
                return disabledDatesForPicker.includes(dateString);
            },
            setup: (picker) => {
                picker.on('selected', (date1, date2) => {
                    updateSummary(date1, date2);
                });
            }
        });
    };

    const updateSummary = (startDateObj, endDateObj) => {
        if (startDateObj) {
            if (startDateHidden) startDateHidden.value = startDateObj.format('YYYY-MM-DD');
            if (summaryStartDate) summaryStartDate.textContent = startDateObj.format('MMM D, YYYY');
            $(summaryEl).show();

            if (endDateObj) {
                if (endDateHidden) endDateHidden.value = endDateObj.format('YYYY-MM-DD');
                if (summaryEndDate) summaryEndDate.textContent = endDateObj.format('MMM D, YYYY');
                const diffTime = Math.abs(endDateObj.toJSDate() - startDateObj.toJSDate());
                if (summaryNights) summaryNights.textContent = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            } else {
                if (endDateHidden) endDateHidden.value = '';
                if (summaryEndDate) summaryEndDate.textContent = '...';
                if (summaryNights) summaryNights.textContent = '...';
            }
        } else {
            if (startDateHidden) startDateHidden.value = '';
            if (endDateHidden) endDateHidden.value = '';
            if (summaryStartDate) summaryStartDate.textContent = '';
            if (summaryEndDate) summaryEndDate.textContent = '...';
            if (summaryNights) summaryNights.textContent = '...';
            $(summaryEl).hide();
        }
        updatePriceDisplay();
        updateButtonState();
    };

    const updatePriceDisplay = async () => {
        if (!wc_integration_enabled || !priceContainer) {
            return;
        }

        const startDate = startDateHidden?.value || '';
        const endDate = endDateHidden?.value || '';
        const primaryDimId = currentFilterSelections[primaryServiceDimKey];
        const productId = lastFetchedServiceDetails?.wc_product_id || primaryDimId;
        const numberOfNights = summaryNights ? parseInt(summaryNights.textContent, 10) : 0;

        if (!startDate || !endDate || !productId || isNaN(numberOfNights) || numberOfNights <= 0) {
            priceContainer.style.display = 'none';
            return;
        }

        priceContainer.style.display = 'block';
        priceContainer.innerHTML = `<strong>${l10n.price || 'Price'}:</strong> <em>${l10n.calculating || 'Calculating...'}</em>`;

        try {
            const priceUrl = `${restUrl.replace('/slots', '/price')}?product_id=${productId}&start_date=${startDate}&end_date=${endDate}`;
            const response = await fetch(priceUrl);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Error fetching price.');
            }

            const perNightPrice = parseFloat(data.total);
            if (isNaN(perNightPrice)) {
                 throw new Error('Invalid price data received.');
            }
            
            const totalPrice = perNightPrice * numberOfNights;
            const formattedTotal = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(totalPrice); // Assumes USD, can be improved later.
            priceContainer.innerHTML = `<strong>${l10n.price || 'Price'}:</strong> ${formattedTotal}`;

        } catch (error) {
            priceContainer.innerHTML = `<strong>${l10n.price || 'Price'}:</strong> <em style="color:red;">${l10n.priceError || 'Could not calculate price.'}</em>`;
            console.error('Price calculation error:', error);
        }
    };

    const updateButtonState = () => {
        if (!scheduleButton) return;
        const hasStartDate = startDateHidden.value && startDateHidden.value.length > 0;
        const hasEndDate = endDateHidden.value && endDateHidden.value.length > 0;
        scheduleButton.disabled = !(currentFilterSelections[primaryServiceDimKey] && hasStartDate && hasEndDate);
    };

    const updateHiddenFilterInputs = () => {
        if (!hiddenInputsContainer) return;

        hiddenInputsContainer.innerHTML = '';
        Object.entries(currentFilterSelections).forEach(([key, value]) => {
            if (value) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                hiddenInputsContainer.appendChild(input);
            }
        });
    };

    if (appointmentForm) {
        appointmentForm.addEventListener('submit', (e) => {
            updateHiddenFilterInputs();

            if (!currentFilterSelections[primaryServiceDimKey] || !startDateHidden?.value || !endDateHidden?.value) {
                e.preventDefault();
                alert('Please select a rental and a valid date range before booking.');
                return;
            }

            if (scheduleButton) {
                scheduleButton.disabled = true;
                scheduleButton.classList.add('clisyc-button-processing');
                scheduleButton.textContent = l10n.processing || 'Processing...';
            }
        });
    }

    // --- MAIN ENTRY POINT ---
    if (preselected_id) {
        if (filtersContainer) {
            $(filtersContainer).hide();
        }
        currentFilterSelections = { [primaryServiceDimKey]: preselected_id };
        updateHiddenFilterInputs();
        filterManager.syncOptions(currentFilterSelections);
    } else {
        filterManager.build();
        filterManager.initializeCustomDropdowns();
        filterManager.initialLoad();
    }
});

})(jQuery);