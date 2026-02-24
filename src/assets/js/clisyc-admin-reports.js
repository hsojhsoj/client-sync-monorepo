/**
 * File: src/assets/js/clisyc-admin-reports.js -> client-sync/assets/js/clisyc-admin-reports.js
 * Client Sync Admin Reports
 *
 * This script initializes Chart.js instances for the Client Sync reports page,
 * using data passed from WordPress via wp_localize_script.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 */
document.addEventListener('DOMContentLoaded', function () {
    // --- 1. PRE-FLIGHT CHECKS ---
    // Ensure the required libraries and data objects are available before proceeding.
    if (typeof Chart === 'undefined') {
        console.error('Client Sync Reports: Chart.js library is not loaded. Cannot create charts.');
        return;
    }
    if (typeof clisycReportsData === 'undefined') {
        console.error('Client Sync Reports: Localization object `clisycReportsData` is not available.');
        return;
    }

    const l10n = clisycReportsData.l10n || {}; // Localization strings for labels and titles

    // --- 2. HELPER FUNCTIONS ---

    /**
     * Generates a palette of distinct, accessible colors for charts.
     * @param {number} count The number of colors needed.
     * @param {number} opacity The opacity for the colors (0.0 to 1.0).
     * @returns {string[]} An array of RGBA color strings.
     */
    function generateColorPalette(count, opacity = 0.7) {
        // A predefined, accessible color palette
        const colors = [
            `rgba(54, 162, 235, ${opacity})`,  // Blue
            `rgba(255, 99, 132, ${opacity})`,  // Red
            `rgba(75, 192, 192, ${opacity})`,  // Green
            `rgba(255, 206, 86, ${opacity})`,  // Yellow
            `rgba(153, 102, 255, ${opacity})`, // Purple
            `rgba(255, 159, 64, ${opacity})`,  // Orange
            `rgba(101, 186, 101, ${opacity})`, // Light Green
            `rgba(235, 136, 235, ${opacity})`, // Pink
            `rgba(136, 235, 235, ${opacity})`, // Cyan
            `rgba(235, 235, 136, ${opacity})`  // Light Yellow
        ];
        const palette = [];
        for (let i = 0; i < count; i++) {
            palette.push(colors[i % colors.length]); // Loop through the palette if more colors are needed
        }
        return palette;
    }

    /**
     * Replaces a canvas element with a "No data" message.
     * @param {HTMLElement} canvasElement The canvas element to replace.
     * @param {string} [message='No data available for this report.'] The message to display.
     */
    function displayNoDataMessage(canvasElement, message = 'No data available for this report.') {
        if (!canvasElement || !canvasElement.parentElement) return;
        const p = document.createElement('p');
        p.textContent = message;
        canvasElement.parentElement.appendChild(p);
        canvasElement.remove();
    }

    // --- 3. CHART INITIALIZATION ---

    // Chart 1: Appointments per Month (Line Chart)
    const ctxAppointmentsPerMonth = document.getElementById('clisycAppointmentsPerMonthChart');
    if (ctxAppointmentsPerMonth) {
        const monthData = clisycReportsData.appointmentsPerMonth;
        if (monthData && monthData.labels.length > 0) {
            new Chart(ctxAppointmentsPerMonth, {
                type: 'line',
                data: {
                    labels: monthData.labels,
                    datasets: [{
                        label: l10n.numberOfAppointments || 'Number of Appointments',
                        data: monthData.data,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.5)',
                        fill: true,
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        title: { display: true, text: l10n.appointmentsPerMonthTitle || 'Appointments per Month' },
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0 // Ensure only whole numbers for appointment counts
                            }
                        }
                    }
                }
            });
        } else {
            displayNoDataMessage(ctxAppointmentsPerMonth, 'No data for Appointments per Month chart.');
        }
    }

    // Chart 2: Appointments by Status (Pie Chart)
    const ctxAppointmentsByStatus = document.getElementById('clisycAppointmentsByStatusChart');
    if (ctxAppointmentsByStatus) {
        const statusData = clisycReportsData.appointmentsByStatus;
        if (statusData && statusData.labels.length > 0) {
            new Chart(ctxAppointmentsByStatus, {
                type: 'pie',
                data: {
                    labels: statusData.labels,
                    datasets: [{
                        label: l10n.numberOfAppointments || 'Number of Appointments',
                        data: statusData.data,
                        backgroundColor: generateColorPalette(statusData.labels.length),
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        title: { display: true, text: l10n.appointmentsByStatusTitle || 'Appointments by Status' },
                        legend: { position: 'top' }
                    }
                }
            });
        } else {
            displayNoDataMessage(ctxAppointmentsByStatus, 'No data for Appointments by Status chart.');
        }
    }

    // Chart 3: Popular Appointment Times (Bar Chart)
    const ctxPopularTimes = document.getElementById('clisycPopularTimesChart');
    if (ctxPopularTimes) {
        const timesData = clisycReportsData.popularTimes;
        if (timesData && timesData.labels.length > 0) {
            new Chart(ctxPopularTimes, {
                type: 'bar',
                data: {
                    labels: timesData.labels,
                    datasets: [{
                        label: l10n.numberOfAppointments || 'Number of Appointments',
                        data: timesData.data,
                        backgroundColor: generateColorPalette(timesData.labels.length, 0.8),
                        borderColor: generateColorPalette(timesData.labels.length, 1),
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'y', // Horizontal bar chart is often better for long labels
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        title: { display: true, text: l10n.popularTimesTitle || 'Popular Appointment Times' },
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0 // Ensure only whole numbers for appointment counts
                            }
                        }
                    }
                }
            });
        } else {
            displayNoDataMessage(ctxPopularTimes, 'No data for Popular Appointment Times chart.');
        }
    }

    // Chart 4: Popular Days of the Week (Bar Chart)
    const ctxPopularDays = document.getElementById('clisycPopularDaysChart');
    if (ctxPopularDays) {
        const daysData = clisycReportsData.popularDays;
        if (daysData && daysData.labels.length > 0) {
            new Chart(ctxPopularDays, {
                type: 'bar',
                data: {
                    labels: daysData.labels,
                    datasets: [{
                        label: l10n.numberOfAppointments || 'Number of Appointments',
                        data: daysData.data,
                        backgroundColor: generateColorPalette(daysData.labels.length, 0.8),
                        borderColor: generateColorPalette(daysData.labels.length, 1),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        title: { display: true, text: l10n.popularDaysTitle || 'Popular Days of the Week' },
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0 // Ensure only whole numbers for appointment counts
                            }
                        }
                    }
                }
            });
        } else {
            displayNoDataMessage(ctxPopularDays, 'No data for Popular Days of the Week chart.');
        }
    }

    // Chart 5: Revenue per Month (Line Chart)
    const ctxRevenuePerMonth = document.getElementById('clisycRevenuePerMonthChart');
    if (ctxRevenuePerMonth) {
        const revenueData = clisycReportsData.revenuePerMonth;
        if (revenueData && revenueData.labels && revenueData.labels.length > 0) {
            new Chart(ctxRevenuePerMonth, {
                type: 'line',
                data: {
                    labels: revenueData.labels,
                    datasets: [{
                        label: l10n.revenue || 'Revenue',
                        data: revenueData.data,
                        borderColor: 'rgba(75, 192, 192, 1)',
                        backgroundColor: 'rgba(75, 192, 192, 0.3)',
                        fill: true,
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        title: { display: true, text: l10n.revenuePerMonthTitle || 'Revenue by Month' },
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        } else {
            displayNoDataMessage(ctxRevenuePerMonth, 'No data for Revenue by Month chart.');
        }
    }

    // Chart 6: Appointments by Service (Doughnut Chart)
    const ctxByService = document.getElementById('clisycAppointmentsByServiceChart');
    if (ctxByService) {
        const serviceData = clisycReportsData.appointmentsByService;
        if (serviceData && serviceData.labels && serviceData.labels.length > 0) {
            new Chart(ctxByService, {
                type: 'doughnut',
                data: {
                    labels: serviceData.labels,
                    datasets: [{
                        data: serviceData.data,
                        backgroundColor: generateColorPalette(serviceData.labels.length),
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        title: { display: true, text: l10n.appointmentsByServiceTitle || 'Appointments by Service' },
                        legend: { position: 'top' }
                    }
                }
            });
        } else {
            displayNoDataMessage(ctxByService, 'No data for Appointments by Service chart.');
        }
    }

    // Chart 7: Client Retention (Stacked Bar Chart)
    const ctxRetention = document.getElementById('clisycClientRetentionChart');
    if (ctxRetention) {
        const retentionData = clisycReportsData.clientRetention;
        if (retentionData && retentionData.labels && retentionData.labels.length > 0) {
            new Chart(ctxRetention, {
                type: 'bar',
                data: {
                    labels: retentionData.labels,
                    datasets: [
                        {
                            label: l10n.newClients || 'New Clients',
                            data: retentionData['new'],
                            backgroundColor: 'rgba(54, 162, 235, 0.7)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 1
                        },
                        {
                            label: l10n.returningClients || 'Returning Clients',
                            data: retentionData.returning,
                            backgroundColor: 'rgba(75, 192, 192, 0.7)',
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        title: { display: true, text: l10n.clientRetentionTitle || 'Client Retention' },
                        legend: { position: 'top' }
                    },
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });
        } else {
            displayNoDataMessage(ctxRetention, 'No data for Client Retention chart.');
        }
    }

    // Chart 8: Cancellation & No-Show Trends (Line Chart)
    const ctxCancellation = document.getElementById('clisycCancellationTrendsChart');
    if (ctxCancellation) {
        const cancelData = clisycReportsData.cancellationTrends;
        if (cancelData && cancelData.labels && cancelData.labels.length > 0) {
            new Chart(ctxCancellation, {
                type: 'line',
                data: {
                    labels: cancelData.labels,
                    datasets: [
                        {
                            label: l10n.cancelled || 'Cancelled',
                            data: cancelData.cancelled,
                            borderColor: 'rgba(255, 99, 132, 1)',
                            backgroundColor: 'rgba(255, 99, 132, 0.2)',
                            fill: true,
                            tension: 0.1
                        },
                        {
                            label: l10n.noShow || 'No-Show',
                            data: cancelData.noShow,
                            borderColor: 'rgba(255, 159, 64, 1)',
                            backgroundColor: 'rgba(255, 159, 64, 0.2)',
                            fill: true,
                            tension: 0.1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        title: { display: true, text: l10n.cancellationTrendsTitle || 'Cancellation & No-Show Trends' },
                        legend: { position: 'top' }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });
        } else {
            displayNoDataMessage(ctxCancellation, 'No data for Cancellation & No-Show Trends chart.');
        }
    }
});