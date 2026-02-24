/**
 * File: src/assets/js/clisyc-manager-appointment-list.js
 * Handles the dynamic, AJAX-powered manager appointment dashboard.
 * UPGRADED: Improved action buttons with proper icons and status badges.
 * FIXED: Restored Service column to match backend data structure (6 columns total)
 */
jQuery(function($) {
    'use strict';

    const container = $('#clisyc-manager-appointments-dashboard');
    if (!container.length) return;

    const config = clisycManagerAppointmentData || {};
    const l10n = config.l10n || {};

    const elements = {
        tableHead: container.find('#clisyc-manager-appointments-table thead'),
        tableBody: container.find('#clisyc-manager-appointments-table tbody'),
        loadingOverlay: container.find('.clisyc-loading-overlay'),
        searchInput: container.find('#clisyc-manager-appointment-search'),
        statusFilter: container.find('#clisyc-manager-appointment-status-filter'),
        serviceFilter: container.find('#clisyc-manager-service-filter'),
        paginationContainer: container.find('.clisyc-pagination-container'),
        exportQueryArgsInput: container.find('#clisyc-export-query-args'),
    };

    let state = {
        currentPage: 1,
        searchTerm: '',
        statusFilter: 'upcoming',
        serviceFilter: 'all',
        orderBy: 'clisyc_time_slot',
        order: 'ASC'
    };

    /**
     * Convert status string to CSS-friendly class suffix
     */
    const statusToClass = (status) => {
        return status.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
    };

    /**
     * Format status with badge styling
     */
    const formatStatusBadge = (status) => {
        const statusClass = statusToClass(status);
        const displayText = status.replace(/_/g, ' ');
        return `<span class="clisyc-status-badge clisyc-status-badge--${statusClass}">${displayText}</span>`;
    };

    const fetchAppointments = () => {
        elements.loadingOverlay.show();
        const data = {
            action: 'clisyc_get_manager_appointments_list',
            security: config.nonce,
            paged: state.currentPage,
            search: state.searchTerm,
            status: state.statusFilter,
            service: state.serviceFilter,
            orderby: state.orderBy,
            order: state.order
        };

        $.post(config.ajaxurl, data, function(response) {
            if (response.success && elements.exportQueryArgsInput.length) {
                elements.exportQueryArgsInput.val(response.data.queryArgs);
            }

            if (response.success) {
                renderTable(response.data.appointments);
                renderPagination(response.data.pagination);
                updateTableHeaders();
            } else {
                elements.tableBody.html(`<tr><td colspan="6">${response.data.message || l10n.error}</td></tr>`);
                elements.paginationContainer.empty();
            }
        }).fail(function() {
            elements.tableBody.html(`<tr><td colspan="6">${l10n.error}</td></tr>`);
            elements.paginationContainer.empty();
        }).always(function() {
            elements.loadingOverlay.hide();
        });
    };

    const renderTable = (appointments) => {
        elements.tableBody.empty();
        if (appointments.length === 0) {
            elements.tableBody.html(`<tr><td colspan="6">${l10n.noAppointments}</td></tr>`);
            return;
        }
        $.each(appointments, function(index, appt) {
            // Build action buttons with proper SVG icons
            let actionsHtml = '<div class="clisyc-appointment-actions">';
            const isUpcoming = appt.status.toLowerCase() !== 'draft' && 
                               appt.status.toLowerCase() !== 'trash' &&
                               appt.status.toLowerCase() !== 'checked_in' &&
                               appt.status.toLowerCase() !== 'no_show' &&
                               appt.status.toLowerCase() !== 'completed' &&
                               appt.status.toLowerCase() !== 'cancelled';

            if (isUpcoming) {
                // Check-In Button with checkmark icon
                actionsHtml += `
                    <button class="clisyc-quick-action-btn"
                            data-appt-id="${appt.id}"
                            data-new-status="checked_in"
                            title="${l10n.checkIn || 'Check In'}"
                            aria-label="${l10n.checkIn || 'Check In'}">
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </button>`;

                // No-Show Button with X icon
                actionsHtml += `
                    <button class="clisyc-quick-action-btn"
                            data-appt-id="${appt.id}"
                            data-new-status="no_show"
                            title="${l10n.noShow || 'Mark No-Show'}"
                            aria-label="${l10n.noShow || 'Mark No-Show'}">
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>`;
            }

            // Details Button - using info/eye icon
            if (appt.actions.editDetails) {
                actionsHtml += `
                    <a href="${appt.actions.editDetails}" class="clisyc-action-btn clisyc-action-btn--details" title="${l10n.edit || 'Details'}" aria-label="${l10n.edit || 'Details'}">
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                    </a>`;
            }

            // Admin Edit Button with icon
            if (appt.actions.editAdmin) {
                actionsHtml += `
                    <a href="${appt.actions.editAdmin}" class="clisyc-action-btn clisyc-action-btn--admin" title="${l10n.editAdmin || 'Edit in Admin'}" target="_blank">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                    </a>`;
            }
            
            actionsHtml += '</div>';

            // Format the status with badge styling
            const statusBadge = formatStatusBadge(appt.status);
            
            // FIXED: Now includes all 6 columns - Client, Service, Date, Time, Status, Actions
            const row = `
                <tr data-appt-id="${appt.id}">
                    <td data-colname="${l10n.colClient}">${appt.clientName || ''}</td>
                    <td data-colname="${l10n.colService}">${appt.service || ''}</td>
                    <td data-colname="${l10n.colDate}">${appt.date || ''}</td>
                    <td data-colname="${l10n.colTime}">${appt.time || ''}</td>
                    <td class="clisyc-status-cell" data-colname="${l10n.colStatus}">${statusBadge}</td>
                    <td data-colname="${l10n.colActions}">${actionsHtml}</td>
                </tr>`;
            elements.tableBody.append(row);
        });
    };

    const renderPagination = (pagination) => {
        elements.paginationContainer.html(pagination.links || '');
    };

    // FIXED: Now includes all 6 column headers
    const updateTableHeaders = () => {
        elements.tableHead.empty();
        const headers = `
            <tr>
                <th scope="col" class="manage-column clisyc-sortable-column" data-orderby="author">${l10n.colClient}</th>
                <th scope="col" class="manage-column">${l10n.colService}</th>
                <th scope="col" class="manage-column clisyc-sortable-column" data-orderby="clisyc_time_slot">${l10n.colDate}</th>
                <th scope="col" class="manage-column">${l10n.colTime}</th>
                <th scope="col" class="manage-column">${l10n.colStatus}</th>
                <th scope="col" class="manage-column">${l10n.colActions}</th>
            </tr>`;
        elements.tableHead.html(headers);
        elements.tableHead.find('.clisyc-sortable-column').each(function() {
            const $th = $(this);
            if ($th.data('orderby') === state.orderBy) {
                $th.addClass('sorted ' + state.order.toLowerCase());
            }
        });
    };
    
    // Event Handlers
    elements.searchInput.on('keyup', _.debounce(() => {
        state.searchTerm = elements.searchInput.val();
        state.currentPage = 1;
        fetchAppointments();
    }, 300));

    elements.statusFilter.on('change', () => {
        state.statusFilter = elements.statusFilter.val();
        state.currentPage = 1;
        fetchAppointments();
    });

    if (elements.serviceFilter.length) {
        elements.serviceFilter.on('change', () => {
            state.serviceFilter = elements.serviceFilter.val();
            state.currentPage = 1;
            fetchAppointments();
        });
    }

    elements.paginationContainer.on('click', '.page-numbers', (e) => {
        e.preventDefault();
        const url = new URL($(e.currentTarget).attr('href'));
        state.currentPage = url.searchParams.get('paged') || 1;
        fetchAppointments();
    });

    elements.tableHead.on('click', '.clisyc-sortable-column', (e) => {
        e.preventDefault();
        const $clickedHeader = $(e.currentTarget);
        const newOrderBy = $clickedHeader.data('orderby');
        state.order = (state.orderBy === newOrderBy && state.order === 'ASC') ? 'DESC' : 'ASC';
        state.orderBy = newOrderBy;
        fetchAppointments();
    });

    // Quick Action Button Handler
    elements.tableBody.on('click', '.clisyc-quick-action-btn', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const appointmentId = $btn.data('appt-id');
        const newStatus = $btn.data('new-status');
        
        const confirmMessage = newStatus === 'checked_in' 
            ? (l10n.confirmCheckIn || 'Mark this appointment as checked in?')
            : (l10n.confirmNoShow || 'Mark this appointment as no-show?');
            
        if (!confirm(confirmMessage)) {
            return;
        }

        // Disable all action buttons in the row
        const $row = $btn.closest('tr');
        const $allBtns = $row.find('.clisyc-quick-action-btn');
        $allBtns.prop('disabled', true);
        $row.css('opacity', 0.6);

        $.post(config.ajaxurl, {
            action: 'clisyc_update_appointment_status',
            security: config.update_status_nonce,
            appointment_id: appointmentId,
            new_status: newStatus
        })
        .done(function(response) {
            if (response.success) {
                // Update the status badge
                const newBadge = formatStatusBadge(response.data.newStatusLabel || newStatus);
                $row.find('.clisyc-status-cell').html(newBadge);
                
                // Remove the quick action buttons since status has changed
                $allBtns.fadeOut(200, function() {
                    $(this).remove();
                });
                
                // If no-show, optionally fade out the entire row after a delay
                if (newStatus === 'no_show' && state.statusFilter === 'upcoming') {
                    setTimeout(() => {
                        $row.slideUp(300, function() { 
                            $(this).remove();
                            // Check if table is now empty
                            if (elements.tableBody.find('tr').length === 0) {
                                elements.tableBody.html(`<tr><td colspan="6">${l10n.noAppointments}</td></tr>`);
                            }
                        });
                    }, 800);
                }
            } else {
                alert(l10n.error + ': ' + (response.data.message || 'Unknown error'));
                $allBtns.prop('disabled', false);
            }
        })
        .fail(function() {
            alert(l10n.serverError || 'An unexpected server error occurred.');
            $allBtns.prop('disabled', false);
        })
        .always(function() {
            $row.css('opacity', 1);
        });
    });

    // Initial Load
    fetchAppointments();
});