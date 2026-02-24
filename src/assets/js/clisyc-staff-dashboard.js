/**
 * File: src/assets/js/clisyc-staff-dashboard.js
 * Handles the AJAX-powered staff/practitioner appointment dashboard.
 * Modeled on clisyc-manager-appointment-list.js with staff-specific additions:
 *   - "Today" tab (default)
 *   - Notes modal (inline AJAX editing)
 *   - Staff-scoped queries via staff_id parameter
 */
jQuery(function ($) {
    'use strict';

    const container = $('#clisyc-staff-dashboard');
    if (!container.length) return;

    const config = window.clisycStaffDashboardData || {};
    const l10n   = config.l10n || {};

    // ── DOM references ──────────────────────────────────────────────
    const elements = {
        tableHead:    container.find('#clisyc-staff-appointments-table thead'),
        tableBody:    container.find('#clisyc-staff-appointments-table tbody'),
        loading:      container.find('.clisyc-loading-overlay'),
        searchInput:  container.find('#clisyc-staff-search'),
        tabs:         container.find('.clisyc-staff-tab'),
        pagination:   container.find('.clisyc-staff-pagination'),
        notesModal:   container.find('#clisyc-staff-notes-modal'),
        notesEditor:  container.find('#clisyc-staff-notes-editor'),
        notesApptId:  container.find('#clisyc-notes-appointment-id'),
        saveNotesBtn: container.find('#clisyc-save-notes'),
    };

    // ── State ────────────────────────────────────────────────────────
    const state = {
        currentPage:  1,
        searchTerm:   '',
        statusFilter: 'today',
        orderBy:      'clisyc_time_slot',
        order:        'ASC',
    };

    // ── Helpers ──────────────────────────────────────────────────────
    const escapeHtml = (str) => {
        if (str == null) return '';
        if (typeof str !== 'string') str = String(str);
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    };

    const statusToClass = (status) =>
        String(status).toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');

    const formatStatusBadge = (status) => {
        const cls  = statusToClass(status);
        const text = escapeHtml(String(status).replace(/_/g, ' '));
        return `<span class="clisyc-status-badge clisyc-status-badge--${cls}">${text}</span>`;
    };

    // ── Debounce utility ─────────────────────────────────────────────
    let searchTimeout;
    const debounce = (fn, delay) => {
        return function () {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(fn, delay);
        };
    };

    // ── AJAX: Fetch Appointments ────────────────────────────────────
    const fetchAppointments = () => {
        elements.loading.show();

        const data = {
            action:   'clisyc_get_manager_appointments_list',
            security: config.list_nonce,
            staff_id: config.staff_id,
            paged:    state.currentPage,
            search:   state.searchTerm,
            status:   state.statusFilter,
            service:  'all',
            orderby:  state.orderBy,
            order:    state.order,
        };

        $.post(config.ajaxurl, data, function (response) {
            if (response.success) {
                renderTable(response.data.appointments);
                renderPagination(response.data.pagination);
                updateTableHeaders();
            } else {
                elements.tableBody.html(
                    `<tr><td colspan="6">${response.data.message || l10n.error}</td></tr>`
                );
                elements.pagination.empty();
            }
        })
        .fail(function () {
            elements.tableBody.html(
                `<tr><td colspan="6">${l10n.serverError}</td></tr>`
            );
            elements.pagination.empty();
        })
        .always(function () {
            elements.loading.hide();
        });
    };

    // ── Render Table ────────────────────────────────────────────────
    const renderTable = (appointments) => {
        elements.tableBody.empty();

        if (!appointments || appointments.length === 0) {
            elements.tableBody.html(
                `<tr><td colspan="6">${l10n.noAppointments}</td></tr>`
            );
            return;
        }

        $.each(appointments, function (_, appt) {
            let actionsHtml = '<div class="clisyc-appointment-actions">';

            const isActionable =
                appt.status.toLowerCase() !== 'draft' &&
                appt.status.toLowerCase() !== 'trash' &&
                appt.status.toLowerCase() !== 'completed' &&
                appt.status.toLowerCase() !== 'cancelled';

            if (isActionable && (state.statusFilter === 'today' || state.statusFilter === 'upcoming')) {
                // Check-In
                actionsHtml += `
                    <button class="clisyc-quick-action-btn clisyc-staff-action-checkin"
                            data-appt-id="${appt.id}"
                            data-new-status="checked_in"
                            title="${l10n.checkIn || 'Check In'}"
                            aria-label="${l10n.checkIn || 'Check In'}">
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </button>`;

                // No-Show
                actionsHtml += `
                    <button class="clisyc-quick-action-btn clisyc-staff-action-noshow"
                            data-appt-id="${appt.id}"
                            data-new-status="no_show"
                            title="${l10n.noShow || 'No-Show'}"
                            aria-label="${l10n.noShow || 'No-Show'}">
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>`;
            }

            // Notes button (always available)
            actionsHtml += `
                <button class="clisyc-action-btn clisyc-staff-action-notes"
                        data-appt-id="${appt.id}"
                        data-notes="${$('<div>').text(appt.notes || '').html()}"
                        title="${l10n.editNotes || 'Notes'}"
                        aria-label="${l10n.editNotes || 'Notes'}">
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                </button>`;

            actionsHtml += '</div>';

            const statusBadge = formatStatusBadge(appt.status);

            const row = `
                <tr data-appt-id="${escapeHtml(appt.id)}">
                    <td data-colname="${escapeHtml(l10n.colClient)}">${escapeHtml(appt.clientName || '')}</td>
                    <td data-colname="${escapeHtml(l10n.colService)}">${escapeHtml(appt.service || '')}</td>
                    <td data-colname="${escapeHtml(l10n.colDate)}">${escapeHtml(appt.date || '')}</td>
                    <td data-colname="${escapeHtml(l10n.colTime)}">${escapeHtml(appt.time || '')}</td>
                    <td class="clisyc-status-cell" data-colname="${escapeHtml(l10n.colStatus)}">${statusBadge}</td>
                    <td data-colname="${escapeHtml(l10n.colActions)}">${actionsHtml}</td>
                </tr>`;
            elements.tableBody.append(row);
        });
    };

    // ── Render Pagination ───────────────────────────────────────────
    const renderPagination = (pagination) => {
        elements.pagination.html(pagination ? (pagination.links || '') : '');
    };

    // ── Update Table Headers ────────────────────────────────────────
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

        elements.tableHead.find('.clisyc-sortable-column').each(function () {
            const $th = $(this);
            if ($th.data('orderby') === state.orderBy) {
                $th.addClass('sorted ' + state.order.toLowerCase());
            }
        });
    };

    // ═══════════════════════════════════════════════════════════════
    //  EVENT HANDLERS
    // ═══════════════════════════════════════════════════════════════

    // ── Tab clicks ──────────────────────────────────────────────────
    elements.tabs.on('click', function () {
        const $tab = $(this);
        elements.tabs.removeClass('active');
        $tab.addClass('active');
        state.statusFilter = $tab.data('status');
        state.currentPage  = 1;
        fetchAppointments();
    });

    // ── Search ──────────────────────────────────────────────────────
    elements.searchInput.on('keyup', debounce(function () {
        state.searchTerm  = elements.searchInput.val();
        state.currentPage = 1;
        fetchAppointments();
    }, 300));

    // ── Pagination ──────────────────────────────────────────────────
    elements.pagination.on('click', '.page-numbers', function (e) {
        e.preventDefault();
        const url = new URL($(this).attr('href'), window.location.href);
        state.currentPage = url.searchParams.get('paged') || 1;
        fetchAppointments();
    });

    // ── Sort headers ────────────────────────────────────────────────
    elements.tableHead.on('click', '.clisyc-sortable-column', function (e) {
        e.preventDefault();
        const newOrderBy = $(this).data('orderby');
        state.order   = (state.orderBy === newOrderBy && state.order === 'ASC') ? 'DESC' : 'ASC';
        state.orderBy = newOrderBy;
        fetchAppointments();
    });

    // ── Quick action (Check-In / No-Show) ───────────────────────────
    elements.tableBody.on('click', '.clisyc-quick-action-btn', function (e) {
        e.preventDefault();
        const $btn          = $(this);
        const appointmentId = $btn.data('appt-id');
        const newStatus     = $btn.data('new-status');

        const confirmMsg = newStatus === 'checked_in'
            ? (l10n.confirmCheckIn || 'Mark this appointment as checked in?')
            : (l10n.confirmNoShow || 'Mark this appointment as no-show?');

        if (!confirm(confirmMsg)) return;

        const $row     = $btn.closest('tr');
        const $allBtns = $row.find('.clisyc-quick-action-btn');
        $allBtns.prop('disabled', true);
        $row.css('opacity', 0.6);

        $.post(config.ajaxurl, {
            action:         'clisyc_staff_update_status',
            security:       config.status_nonce,
            appointment_id: appointmentId,
            new_status:     newStatus,
        })
        .done(function (response) {
            if (response.success) {
                // Update badge.
                const newBadge = formatStatusBadge(response.data.newStatusLabel || newStatus);
                $row.find('.clisyc-status-cell').html(newBadge);

                // Remove quick action buttons.
                $allBtns.fadeOut(200, function () { $(this).remove(); });

                // Update summary counters.
                updateSummaryCounters(newStatus);

                // Slide out no-show rows when on "today" tab.
                if (newStatus === 'no_show' && state.statusFilter === 'today') {
                    setTimeout(() => {
                        $row.slideUp(300, function () {
                            $(this).remove();
                            if (elements.tableBody.find('tr').length === 0) {
                                elements.tableBody.html(
                                    `<tr><td colspan="6">${l10n.noAppointments}</td></tr>`
                                );
                            }
                        });
                    }, 800);
                }
            } else {
                alert(l10n.error + ': ' + (response.data.message || ''));
                $allBtns.prop('disabled', false);
            }
        })
        .fail(function () {
            alert(l10n.serverError);
            $allBtns.prop('disabled', false);
        })
        .always(function () {
            $row.css('opacity', 1);
        });
    });

    // ── Notes modal open ────────────────────────────────────────────
    elements.tableBody.on('click', '.clisyc-staff-action-notes', function (e) {
        e.preventDefault();
        const $btn   = $(this);
        const apptId = $btn.data('appt-id');
        // Decode HTML entities that were stored in the data attribute.
        const notes  = $('<textarea>').html($btn.data('notes') || '').val();

        elements.notesApptId.val(apptId);
        elements.notesEditor.val(notes);
        elements.notesModal.fadeIn(200);
        elements.notesEditor.focus();
    });

    // ── Notes modal close ───────────────────────────────────────────
    elements.notesModal.on('click', '.clisyc-close-notes, .clisyc-notes-modal-overlay', function () {
        elements.notesModal.fadeOut(200);
    });

    // Close on Escape key.
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && elements.notesModal.is(':visible')) {
            elements.notesModal.fadeOut(200);
        }
    });

    // ── Save notes ──────────────────────────────────────────────────
    elements.saveNotesBtn.on('click', function () {
        const $btn   = $(this);
        const apptId = elements.notesApptId.val();
        const notes  = elements.notesEditor.val();

        $btn.prop('disabled', true).text(l10n.loading || 'Saving…');

        $.post(config.ajaxurl, {
            action:         'clisyc_staff_update_notes',
            security:       config.notes_nonce,
            appointment_id: apptId,
            notes:          notes,
        })
        .done(function (response) {
            if (response.success) {
                // Update the data attribute on the notes button in the table.
                const $notesBtn = elements.tableBody
                    .find(`tr[data-appt-id="${apptId}"] .clisyc-staff-action-notes`);
                $notesBtn.data('notes', response.data.notes || '');

                elements.notesModal.fadeOut(200);
            } else {
                alert(l10n.notesSaveFailed + ' ' + (response.data.message || ''));
            }
        })
        .fail(function () {
            alert(l10n.serverError);
        })
        .always(function () {
            $btn.prop('disabled', false).html(
                '<span class="dashicons dashicons-yes"></span> ' + (l10n.saveNotes || 'Save Notes')
            );
        });
    });

    // ── Update summary counters (optimistic) ────────────────────────
    const updateSummaryCounters = (newStatus) => {
        const $checkedIn = $('#clisyc-summary-checked-in');
        const $remaining = $('#clisyc-summary-remaining');

        if (newStatus === 'checked_in') {
            const ci = parseInt($checkedIn.text(), 10) || 0;
            const rm = parseInt($remaining.text(), 10) || 0;
            $checkedIn.text(ci + 1);
            $remaining.text(Math.max(0, rm - 1));
        } else if (newStatus === 'no_show') {
            const total = parseInt($('#clisyc-summary-total').text(), 10) || 0;
            const ci    = parseInt($checkedIn.text(), 10) || 0;
            $('#clisyc-summary-total').text(Math.max(0, total - 1));
            $remaining.text(Math.max(0, total - 1 - ci));
        }
    };

    // ── Initial load ────────────────────────────────────────────────
    fetchAppointments();
});
