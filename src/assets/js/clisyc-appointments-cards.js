/**
 * File: src/assets/js/clisyc-appointments-cards.js
 * Handles the dynamic rendering and interaction for the [clisyc_appointments_cards] shortcode.
 * 
 * IMPROVED: Added debugging, better error handling, and fallback logic.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Assets/JS
 */
(function($) {
    'use strict';

    const ClisycAppointmentsCards = {
        container: null,
        grid: null,
        loadingOverlay: null,
        emptyState: null,
        errorState: null,
        pagination: null,
        searchInput: null,
        statusFilter: null,
        currentPage: 1,
        debounceTimer: null,
        currentRequest: null,
        debug: false,

        log: function(...args) {
            if (this.debug) {
                console.log('[CLISYC Cards]', ...args);
            }
        },

        init: function() {
            this.log('Initializing appointments cards...');
            
            this.container = $('#clisyc-appointments-cards-dashboard');
            if (!this.container.length) {
                this.log('Container not found, exiting');
                return;
            }

            this.grid = this.container.find('.clisyc-appointments-cards-grid');
            this.loadingOverlay = this.container.find('.clisyc-cards-loading-overlay');
            this.emptyState = this.container.find('.clisyc-cards-empty-state');
            this.errorState = this.container.find('.clisyc-cards-error-message');
            this.pagination = this.container.find('.clisyc-cards-pagination');
            this.searchInput = this.container.find('#clisyc-cards-search');
            this.statusFilter = this.container.find('#clisyc-cards-status-filter');

            // Check for localized data
            if (typeof clisycAppointmentsCardsData === 'undefined') {
                this.log('ERROR: clisycAppointmentsCardsData not found!');
                this.showError('Configuration error. Please contact support.');
                return;
            }

            this.log('Config:', clisycAppointmentsCardsData);

            this.setupEventListeners();
            this.loadAppointments();
        },

        setupEventListeners: function() {
            const self = this;

            // Search input with debounce
            this.searchInput.on('keyup', function() {
                clearTimeout(self.debounceTimer);
                self.debounceTimer = setTimeout(function() {
                    self.currentPage = 1;
                    self.loadAppointments();
                }, 300);
            });

            // Status filter
            this.statusFilter.on('change', function() {
                self.log('Status filter changed to:', $(this).val());
                self.currentPage = 1;
                self.loadAppointments();
            });

            // Delegated click handler for pagination
            this.pagination.on('click', '.page-numbers', function(e) {
                e.preventDefault();
                const href = $(this).attr('href');
                if (href && href !== '#') {
                    const pageMatch = href.match(/#(\d+)/);
                    if (pageMatch) {
                        self.currentPage = parseInt(pageMatch[1]) || 1;
                    }
                }
                self.log('Pagination clicked, page:', self.currentPage);
                self.loadAppointments();
            });

            // Delegated click handler for cancellation
            this.grid.on('click', '.clisyc-action-cancel', function(e) {
                if (!confirm('Are you sure you want to cancel this appointment?')) {
                    e.preventDefault();
                }
            });
        },

        loadAppointments: function() {
            const self = this;

            // Abort any previous ongoing request
            if (self.currentRequest) {
                self.currentRequest.abort();
            }

            this.showLoading();
            this.errorState.hide();

            const requestData = {
                action: 'clisyc_get_appointments_cards',
                nonce: clisycAppointmentsCardsData.nonce,
                search: this.searchInput.val() || '',
                status: this.statusFilter.val() || 'upcoming',
                page: this.currentPage
            };

            this.log('Sending AJAX request:', requestData);

            self.currentRequest = $.post(clisycAppointmentsCardsData.ajaxUrl, requestData)
                .done(function(response) {
                    self.log('AJAX response:', response);
                    
                    if (response.success) {
                        self.renderAppointments(response.data.appointments);
                        self.pagination.html(response.data.pagination || '');
                        
                        // Debug: Show total count
                        if (response.data.debug) {
                            self.log('Debug info:', response.data.debug);
                        }
                    } else {
                        self.log('AJAX returned error:', response.data);
                        self.showError(response.data?.message || 'Failed to load appointments.');
                    }
                })
                .fail(function(xhr, status, error) {
                    if (status !== 'abort') {
                        self.log('AJAX failed:', status, error, xhr.responseText);
                        self.showError('Failed to load appointments. Please refresh the page.');
                    }
                })
                .always(function() {
                    self.hideLoading();
                    self.currentRequest = null;
                });
        },

        renderAppointments: function(appointments) {
            this.grid.empty();
            this.emptyState.hide();
            this.errorState.hide();

            this.log('Rendering', appointments?.length || 0, 'appointments');

            if (!appointments || appointments.length === 0) {
                this.showEmptyState();
                return;
            }

            const self = this;
            appointments.forEach(function(appointment) {
                const card = self.createCard(appointment);
                self.grid.append(card);
            });
        },

        createCard: function(appointment) {
            const statusLabel = this.getStatusLabel(appointment.status);
            const statusClass = this.getStatusClass(appointment.status);

            let actionsHtml = '';
            if (appointment.view_url && appointment.view_url !== '#') {
                actionsHtml += `<a href="${appointment.view_url}" class="button button-primary">View Details</a>`;
            }
            if (appointment.can_cancel && appointment.cancel_url && appointment.cancel_url !== '#') {
                actionsHtml += `<a href="${appointment.cancel_url}" class="button clisyc-action-cancel">Cancel</a>`;
            }

            // Build dimension details HTML
            let dimensionsHtml = '';
            if (appointment.dimensions && appointment.dimensions.length > 0) {
                const dimItems = appointment.dimensions.map(dim => {
                    const iconHtml = dim.icon_classes
                        ? `<span class="clisyc-dim-icon"><span class="${this.escapeHtml(dim.icon_classes)}"></span></span>`
                        : '';
                    return `<li>${iconHtml}<span class="clisyc-card-dim-label">${this.escapeHtml(dim.label)}</span><span class="clisyc-card-dim-value">${this.escapeHtml(dim.value)}</span></li>`;
                }).join('');
                dimensionsHtml = `<ul class="clisyc-card-dimensions">${dimItems}</ul>`;
            }

            const cardHtml = `
                <div class="clisyc-appointment-card" data-appointment-id="${appointment.id}">
                    <div class="clisyc-card-status-badge ${statusClass}">${statusLabel}</div>
                    <div class="clisyc-card-header">
                        <h3>${this.escapeHtml(appointment.title)}</h3>
                    </div>
                    <div class="clisyc-card-datetime">
                        <div class="clisyc-card-datetime-item">
                            <div class="clisyc-card-datetime-icon">
                                <span class="dashicons dashicons-calendar-alt"></span>
                            </div>
                            <div class="clisyc-card-datetime-text">
                                <strong>Date</strong>
                                <span>${this.escapeHtml(appointment.date) || 'Not set'}</span>
                            </div>
                        </div>
                        <div class="clisyc-card-datetime-item">
                            <div class="clisyc-card-datetime-icon">
                                <span class="dashicons dashicons-clock"></span>
                            </div>
                            <div class="clisyc-card-datetime-text">
                                <strong>Time</strong>
                                <span>${this.escapeHtml(appointment.time) || 'Not set'}</span>
                            </div>
                        </div>
                    </div>
                    ${dimensionsHtml}
                    ${actionsHtml ? `<div class="clisyc-card-actions">${actionsHtml}</div>` : ''}
                </div>`;
            
            return $(cardHtml);
        },

        escapeHtml: function(text) {
            if (!text) return '';
            // Create a textarea to decode HTML entities, then escape for display
            const textarea = document.createElement('textarea');
            textarea.innerHTML = text;
            const decoded = textarea.value;
            // Now escape any actual HTML tags for safety
            const div = document.createElement('div');
            div.textContent = decoded;
            return div.innerHTML;
        },

        getStatusClass: function(status) {
            const statusMap = {
                'publish': 'clisyc-status-confirmed',
                'confirmed': 'clisyc-status-confirmed',
                'clisyc_paid_on_day': 'clisyc-status-confirmed',
                'wc-processing': 'clisyc-status-confirmed',
                'wc-completed': 'clisyc-status-confirmed',
                'pending': 'clisyc-status-pending',
                'clisyc_pending_pay': 'clisyc-status-pending',
                'draft': 'clisyc-status-pending',
                'trash': 'clisyc-status-cancelled',
                'cancelled': 'clisyc-status-cancelled'
            };
            return statusMap[status] || 'clisyc-status-pending';
        },

        getStatusLabel: function(status) {
            const labelMap = {
                'publish': 'Confirmed',
                'confirmed': 'Confirmed',
                'clisyc_paid_on_day': 'Confirmed',
                'wc-processing': 'Processing',
                'wc-completed': 'Completed',
                'pending': 'Pending',
                'clisyc_pending_pay': 'Awaiting Payment',
                'draft': 'Draft',
                'trash': 'Cancelled',
                'cancelled': 'Cancelled'
            };
            return labelMap[status] || (status ? status.charAt(0).toUpperCase() + status.slice(1).replace(/-/g, ' ') : 'Unknown');
        },

        showLoading: function() {
            this.loadingOverlay.addClass('is-active');
        },
        
        hideLoading: function() {
            this.loadingOverlay.removeClass('is-active');
        },

        showEmptyState: function() {
            const filterValue = this.statusFilter.val();
            let message = 'No appointments found.';
            
            if (filterValue === 'upcoming') {
                message = 'No upcoming appointments found. Try selecting "All" to see all appointments.';
            } else if (filterValue === 'past') {
                message = 'No past appointments found.';
            }
            
            this.grid.empty();
            this.pagination.empty();
            this.emptyState.find('p').text(message);
            this.emptyState.show();
        },

        showError: function(message) {
            this.grid.empty();
            this.pagination.empty();
            this.emptyState.hide();
            this.errorState.empty().append(
                $('<p>').append(
                    $('<span>').addClass('dashicons dashicons-warning'),
                    document.createTextNode(' ' + message)
                )
            ).show();
        }
    };

    $(document).ready(function() {
        if ($('#clisyc-appointments-cards-dashboard').length) {
            ClisycAppointmentsCards.init();
        }
    });

})(jQuery);