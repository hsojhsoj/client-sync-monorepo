/**
 * File: src/assets/js/clisyc-frontend-appointment-list.js -> client-sync/assets/js/clisyc-frontend-appointment-list.js
 * Handles the dynamic loading and filtering of the user's appointment list on the frontend.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 *
 * @package ClientSync
 */

(function($) {
    'use strict';

    if (typeof $ === 'undefined') {
        console.error('ClientSync: jQuery is required for clisyc-frontend-appointment-list.js');
        return;
    }

    // *** REFACTORED: Updated all element IDs ***
    var $dashboard = $('#clisyc-user-appointments-dashboard');
    var $tableBody = $dashboard.find('tbody');
    var $loadingIndicator = $dashboard.find('.clisyc-loading-indicator');
    var $errorMessage = $dashboard.find('.clisyc-error-message');
    var $emptyMessage = $dashboard.find('.clisyc-empty-message');
    var $pagination = $dashboard.find('.clisyc-pagination');
    var $statusFilter = $('#clisyc-appointment-status-filter');
    var $searchInput = $('#clisyc-appointment-search');
    var currentPage = 1;
    var itemsPerPage = 10;
    var debounceTimer;

    function fetchAppointments(status, search, page) {
        $loadingIndicator.show();
        $errorMessage.hide();
        $emptyMessage.hide();
        $tableBody.empty();
        $pagination.empty();

        // *** REFACTORED: Updated JS data object and AJAX action names ***
        $.ajax({
            url: clisycUserAppointmentsData.ajaxurl,
            type: 'POST',
            data: {
                action: 'clisyc_get_user_appointments',
                security: clisycUserAppointmentsData.nonce,
                status: status,
                search: search,
                page: page,
                per_page: itemsPerPage
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderAppointments(response.data.appointments);
                    renderPagination(response.data.total_pages, page);
                    if (response.data.appointments.length === 0) {
                        $emptyMessage.show();
                    }
                } else {
                    showError(response.data.message || clisycUserAppointmentsData.l10n.errorLoading);
                }
            },
            error: function(xhr, status, error) {
                showError(clisycUserAppointmentsData.l10n.errorLoading);
            },
            complete: function() {
                $loadingIndicator.hide();
            }
        });
    }

    function renderAppointments(appointments) {
        // --- START MODIFICATION ---
        // The original code was appending a single cell. This new version creates a proper row with multiple cells.
        // SECURITY: All dynamic values use jQuery .text()/.attr() to prevent XSS.
        $tableBody.empty(); // Clear the "Loading..." message

        $.each(appointments, function(index, appt) {
            var row = $('<tr></tr>');

            // Appointment Title (escaped via .text())
            row.append($('<td>').text(appt.title));

            // Date & Time (in separate cells for better alignment)
            row.append($('<td>').attr('data-sort', appt.sort_date).text(appt.date));
            row.append($('<td>').attr('data-sort', appt.sort_time).text(appt.time));

            // Status
            row.append($('<td>').text(appt.status));

            // Actions — build safely with jQuery DOM methods
            var actionsCell = $('<td></td>');
            var actionsList = $('<ul class="clisyc-appointment-actions"></ul>');

            if (appt.can_view) {
                var $viewLink = $('<a>').attr({ href: appt.view_url, title: clisycUserAppointmentsData.l10n.viewDetails }).addClass('clisyc-action-view');
                $viewLink.append($('<span class="dashicons dashicons-visibility"></span>'));
                actionsList.append($('<li>').append($viewLink));
            }
            if (appt.can_cancel) {
                var $cancelLink = $('<a>').attr({ href: appt.cancel_url, title: clisycUserAppointmentsData.l10n.cancelAppointment }).addClass('clisyc-action-cancel').data('confirm', clisycUserAppointmentsData.l10n.confirmCancel);
                $cancelLink.append($('<span class="dashicons dashicons-no"></span>'));
                actionsList.append($('<li>').append($cancelLink));
            }
            if (appt.can_reschedule) {
                var $rescheduleLink = $('<a>').attr({ href: appt.reschedule_url, title: clisycUserAppointmentsData.l10n.rescheduleAppointment }).addClass('clisyc-action-reschedule').data('confirm', clisycUserAppointmentsData.l10n.confirmReschedule);
                $rescheduleLink.append($('<span class="dashicons dashicons-update"></span>'));
                actionsList.append($('<li>').append($rescheduleLink));
            }

            actionsCell.append(actionsList);
            row.append(actionsCell);
            $tableBody.append(row);
        });
        // --- END MODIFICATION ---
    }

    function renderPagination(totalPages, pageNum) {
        if (totalPages <= 1) return;

        // *** REFACTORED: Updated CSS class names and data object reference ***
        // BUG FIX: Renamed parameter from 'currentPage' to 'pageNum' to avoid
        // shadowing the outer 'currentPage' variable. Click handlers now correctly
        // update the outer state variable.
        var prevButton = $('<button class="clisyc-pagination-button clisyc-prev"></button>').text('\u00AB ' + clisycUserAppointmentsData.l10n.previous).prop('disabled', currentPage === 1);
        var nextButton = $('<button class="clisyc-pagination-button clisyc-next"></button>').text(clisycUserAppointmentsData.l10n.next + ' \u00BB').prop('disabled', currentPage === totalPages);

        prevButton.on('click', function() {
            if (currentPage > 1) {
                currentPage--;
                fetchAppointments($statusFilter.val(), $searchInput.val(), currentPage);
            }
        });

        nextButton.on('click', function() {
            if (currentPage < totalPages) {
                currentPage++;
                fetchAppointments($statusFilter.val(), $searchInput.val(), currentPage);
            }
        });

        $pagination.append(prevButton).append(nextButton);
    }

    function showError(message) {
        $errorMessage.text(message).show();
    }

    // Event handlers
    $statusFilter.on('change', function() {
        currentPage = 1;
        fetchAppointments(this.value, $searchInput.val(), currentPage);
    });

    $searchInput.on('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            currentPage = 1;
            fetchAppointments($statusFilter.val(), $searchInput.val(), currentPage);
        }, 300);
    });

    // Handle action confirmations
    // *** REFACTORED: Updated CSS class selectors ***
    $dashboard.on('click', '.clisyc-action-cancel, .clisyc-action-reschedule', function(e) {
        var confirmMessage = $(this).data('confirm');
        if (!confirm(confirmMessage)) {
            e.preventDefault();
        }
    });

    // Initial load
    if ($dashboard.length) {
        fetchAppointments('all', '', 1);
    }

})(jQuery);