/**
 * File: src/assets/js/clisyc-admin-appointment-slot-selector.js
 * ENHANCED: Added dimension display in slot list and auto-title generation.
 * Updated for WordPress Block Editor (Gutenberg) compatibility via MutationObserver.
 */
jQuery(function ($) {
    'use strict';

    if (typeof clisycSlotSelectorData === 'undefined') {
        console.warn('[Client Sync] clisycSlotSelectorData is missing. Script cannot initialize.');
        return;
    }

    // Store slot data for later use (title generation, etc.)
    let currentSlotsData = [];
    let selectedSlotData = null;

    // --- HELPER FUNCTIONS ---

    /**
     * Generate an appointment title from dimensions and date
     */
    const generateTitle = (dimensions, date) => {
        const parts = [];
        
        // Get dimension order from config or use default order
        const dimensionOrder = clisycSlotSelectorData.dimensionOrder || [];
        
        // Primary dimension first (usually service)
        const primarySlug = clisycSlotSelectorData.primaryDimension || '';
        
        if (primarySlug && dimensions[primarySlug]) {
            parts.push(dimensions[primarySlug].title);
        }
        
        // Then other dimensions in order
        dimensionOrder.forEach(slug => {
            if (slug !== primarySlug && dimensions[slug]) {
                parts.push(dimensions[slug].title);
            }
        });
        
        // Add any remaining dimensions not in the order
        Object.keys(dimensions).forEach(slug => {
            if (slug !== primarySlug && !dimensionOrder.includes(slug) && dimensions[slug]) {
                parts.push(dimensions[slug].title);
            }
        });
        
        // Add formatted date
        if (date) {
            try {
                const dateObj = new Date(date + 'T00:00:00');
                const options = { month: 'short', day: 'numeric', year: 'numeric' };
                parts.push(dateObj.toLocaleDateString('en-US', options));
            } catch (e) {
                parts.push(date);
            }
        }
        
        return parts.filter(p => p).join(' - ');
    };

    /**
     * Update the WordPress post title (works with both Classic and Gutenberg editors)
     */
    const updatePostTitle = (newTitle) => {
        if (!newTitle) return;
        
        // Check if current title is empty or auto-draft
        const currentTitle = getPostTitle();
        if (currentTitle && currentTitle !== '' && currentTitle !== 'Auto Draft') {
            // Don't override user-entered titles
            return;
        }
        
        // Gutenberg editor
        if (wp && wp.data && wp.data.dispatch) {
            try {
                wp.data.dispatch('core/editor').editPost({ title: newTitle });
            } catch (e) {
                // Gutenberg API unavailable, fall through to classic editor
            }
        }
        
        // Classic editor fallback
        const $classicTitle = $('#title');
        if ($classicTitle.length && (!$classicTitle.val() || $classicTitle.val() === 'Auto Draft')) {
            $classicTitle.val(newTitle);
        }
    };

    /**
     * Get current post title from editor
     */
    const getPostTitle = () => {
        // Gutenberg
        if (wp && wp.data && wp.data.select) {
            try {
                return wp.data.select('core/editor').getEditedPostAttribute('title') || '';
            } catch (e) {
                // Fallback
            }
        }
        
        // Classic editor
        return $('#title').val() || '';
    };

    /**
     * Update hidden dimension fields
     */
    const updateDimensionHiddenFields = (dimensions) => {
        // Remove old dimension hidden fields (only the ones we manage dynamically)
        $('#clisyc-time-slot-selector input.clisyc-dynamic-dim-field').remove();
        
        // Add new ones
        const $container = $('#clisyc-time-slot-selector');
        if ($container.length && dimensions) {
            Object.keys(dimensions).forEach(slug => {
                const dimData = dimensions[slug];
                if (dimData && dimData.id) {
                    $container.append(
                        $('<input type="hidden" class="clisyc-dynamic-dim-field">')
                            .attr('name', `clisyc_slot_dimensions[${slug}]`)
                            .val(dimData.id)
                    );
                }
            });
        }
    };

    const loadAvailableSlots = (selectedDate, currentIdentifier = null) => {
        const $slotsContainer = $('#clisyc-available-slots-container');
        const $spinner = $('#clisyc-time-slot-selector .spinner');
        
        $slotsContainer.html(`<p><em>${clisycSlotSelectorData.text_loading_slots}</em></p>`);
        $spinner.addClass('is-active');

        if (!currentIdentifier) {
             $('#clisyc-selected-slot-time').text(clisycSlotSelectorData.text_none || 'None');
        }

        $.ajax({
            url: clisycSlotSelectorData.ajax_url,
            type: 'POST',
            data: {
                action: 'clisyc_get_available_slots',
                security: clisycSlotSelectorData.nonce,
                selected_date: selectedDate,
                post_id: clisycSlotSelectorData.current_post_id
            },
            dataType: 'json'
        }).done(response => {
            if (response.success && response.data.slots && response.data.slots.length > 0) {
                currentSlotsData = response.data.slots;
                displaySlots(response.data.slots, selectedDate, currentIdentifier);
            } else {
                currentSlotsData = [];
                $slotsContainer.html(`<p><em>${response.data?.message || clisycSlotSelectorData.text_no_slots}</em></p>`);
            }
        }).fail(() => {
            currentSlotsData = [];
            $slotsContainer.html(`<p><em style="color: red;">${clisycSlotSelectorData.text_error}</em></p>`);
        }).always(() => {
            $spinner.removeClass('is-active');
        });
    };

    const displaySlots = (slots, selectedDate, currentIdentifier) => {
        const $slotsContainer = $('#clisyc-available-slots-container');
        $slotsContainer.empty();

        slots.forEach((slot, index) => {
            const startTimeFormatted = slot.start; 
            const endTimeFormatted = slot.end;
            
            // Use unique_id for radio button ID to handle multiple slots at same time
            const uniqueId = slot.unique_id || slot.identifier;
            const slotId = `slot-${uniqueId.replace(/[^a-zA-Z0-9]/g, '-')}-${index}`;
            
            const isSelected = (slot.identifier === currentIdentifier);

            const $radio = $('<input type="radio" name="clisyc_selected_slot_radio" required>')
                .attr('id', slotId)
                .val(slot.identifier)
                .data({ 
                    date: selectedDate, 
                    start: slot.start, 
                    duration: slot.duration,
                    dimensions: slot.dimensions || {},
                    dimensionLabel: slot.dimension_label || '',
                    slotIndex: index
                })
                .prop('checked', isSelected);

            // Build display text with dimension info
            let displayText = `${startTimeFormatted} - ${endTimeFormatted} (${slot.duration}m)`;
            if (slot.dimension_label) {
                displayText += ` — ${slot.dimension_label}`;
            }

            const $label = $('<label>')
                .attr('for', slotId)
                .css({ 
                    display: 'block', 
                    marginBottom: '8px', 
                    cursor: 'pointer',
                    padding: '8px 10px',
                    border: '1px solid #ddd',
                    borderRadius: '4px',
                    backgroundColor: '#fff',
                    transition: 'all 0.2s ease'
                })
                .hover(
                    function() { $(this).css('backgroundColor', '#f0f6fc'); },
                    function() { 
                        if (!$(this).prev('input').is(':checked')) {
                            $(this).css('backgroundColor', '#fff'); 
                        }
                    }
                );
            
            // Add dimension details as sub-text
            if (slot.dimensions && Object.keys(slot.dimensions).length > 0) {
                const $mainText = $('<div>')
                    .css({ fontWeight: '500' })
                    .text(`${startTimeFormatted} - ${endTimeFormatted} (${slot.duration}m)`);
                
                const $dimText = $('<div>')
                    .css({ fontSize: '12px', color: '#666', marginTop: '2px' });
                
                const dimParts = [];
                Object.keys(slot.dimensions).forEach(slug => {
                    const dim = slot.dimensions[slug];
                    if (dim && dim.title) {
                        dimParts.push(`${dim.label}: ${dim.title}`);
                    }
                });
                $dimText.text(dimParts.join(' • '));
                
                $label.append($mainText, $dimText);
            } else {
                $label.text(displayText);
            }
            
            const $wrapper = $('<div>').css({ marginBottom: '4px' }).append($radio, $label);
            $slotsContainer.append($wrapper);
            
            // Style selected state
            if (isSelected) {
                $label.css({ backgroundColor: '#f0f6fc', borderColor: '#2271b1' });
                selectedSlotData = slot;
                updateSelection(startTimeFormatted, slot.identifier, selectedDate, slot.duration, slot.dimensions || {});
            }
        });

        // Handle selection changes
        $slotsContainer.off('change').on('change', 'input[name="clisyc_selected_slot_radio"]', function () {
            const $selectedRadio = $(this);
            const slotIndex = $selectedRadio.data('slotIndex');
            const slot = currentSlotsData[slotIndex] || {};
            
            // Reset all label styles
            $slotsContainer.find('label').css({ backgroundColor: '#fff', borderColor: '#ddd' });
            
            // Highlight selected
            $selectedRadio.next('label').css({ backgroundColor: '#f0f6fc', borderColor: '#2271b1' });
            
            selectedSlotData = slot;
            updateSelection(
                $selectedRadio.data('start'), 
                $selectedRadio.val(), 
                $selectedRadio.data('date'), 
                $selectedRadio.data('duration'),
                $selectedRadio.data('dimensions') || {}
            );
        });
    };

    const updateSelection = (timeText, identifier, date, duration, dimensions) => {
        $('#clisyc-selected-slot-time').text(timeText);
        $('#clisyc_selected_slot_identifier_hidden').val(identifier).trigger('change');
        $('#clisyc_selected_slot_date_hidden').val(date);
        $('#clisyc_selected_slot_duration_hidden').val(duration);
        
        // Update dimension hidden fields
        updateDimensionHiddenFields(dimensions);
        
        // Auto-generate title
        if (dimensions && Object.keys(dimensions).length > 0) {
            const newTitle = generateTitle(dimensions, date);
            updatePostTitle(newTitle);
        }
    };

    // Build the available dates set client-side from a compact range.
    const availableDatesLookup = (function() {
        const range = clisycSlotSelectorData.availableDateRange;
        if (!range || !range.startDate) {
            return new Set(clisycSlotSelectorData.availableDates || []);
        }
        const dates = new Set();
        const d = new Date(range.startDate + 'T00:00:00');
        for (let i = 0; i < (range.dayCount || 90); i++) {
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            dates.add(y + '-' + m + '-' + day);
            d.setDate(d.getDate() + 1);
        }
        return dates;
    })();

    function initSlotSelector() {
        const $dateInput = $('#clisyc_slot_selection_date');
        
        if ($dateInput.length === 0 || $dateInput.hasClass('hasDatepicker')) {
            return;
        }

        $dateInput.datepicker({
            dateFormat: 'yy-mm-dd', 
            minDate: 0,
            beforeShowDay: (date) => {
                const dateString = $.datepicker.formatDate('yy-mm-dd', date);
                return [true, availableDatesLookup.has(dateString) ? 'has-clisyc-slots' : '', ''];
            },
            onSelect: (dateText) => {
                if (dateText) {
                    loadAvailableSlots(dateText);
                    $('#clisyc_selected_slot_date_hidden').val(dateText).trigger('change');
                }
            }
        });

        // Check for pre-filled data
        const initialDate = $('#clisyc_selected_slot_date_hidden').val() || $dateInput.val();
        const initialIdentifier = $('#clisyc_selected_slot_identifier_hidden').val();

        if (initialDate) {
            $dateInput.val(initialDate);
            loadAvailableSlots(initialDate, initialIdentifier);
        }
        
        // Try to generate initial title from existing dimension data (from URL params)
        tryInitialTitleGeneration();
    }

    /**
     * Try to generate a title on initial page load from URL-passed dimensions
     */
    function tryInitialTitleGeneration() {
        // Wait a moment for Gutenberg to fully initialize
        setTimeout(() => {
            const currentTitle = getPostTitle();
            if (currentTitle && currentTitle !== '' && currentTitle !== 'Auto Draft') {
                return; // Already has a title
            }
            
            // Check for pre-existing dimension data from hidden fields
            const dimensions = {};
            const dimensionLabels = clisycSlotSelectorData.dimensionLabels || {};
            
            $('input[name^="clisyc_slot_dimensions["]').each(function() {
                const name = $(this).attr('name');
                const match = name.match(/clisyc_slot_dimensions\[([^\]]+)\]/);
                if (match) {
                    const slug = match[1];
                    const id = parseInt($(this).val(), 10);
                    if (id > 0) {
                        // Try to get title from labels passed from PHP
                        const title = dimensionLabels[slug + '_' + id] || dimensionLabels[id] || '';
                        const label = (clisycSlotSelectorData.dimensionTypeLabels || {})[slug] || slug;
                        dimensions[slug] = {
                            id: id,
                            title: title,
                            label: label
                        };
                    }
                }
            });
            
            if (Object.keys(dimensions).length > 0) {
                const date = $('#clisyc_selected_slot_date_hidden').val() || '';
                
                // If we have titles, generate; otherwise wait for AJAX to load them
                const hasTitles = Object.values(dimensions).some(d => d.title);
                if (hasTitles) {
                    const newTitle = generateTitle(dimensions, date);
                    updatePostTitle(newTitle);
                }
            }
        }, 500);
    }

    // 1. Immediate Init
    initSlotSelector();

    // 2. MutationObserver for Gutenberg
    const observer = new MutationObserver(function(mutations) {
        if ($('#clisyc_slot_selection_date').length > 0) {
            initSlotSelector();
        }
    });

    observer.observe(document.body, { childList: true, subtree: true });
});