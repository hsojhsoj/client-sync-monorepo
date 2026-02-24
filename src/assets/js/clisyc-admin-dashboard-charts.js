/**
 * File: src/assets/js/clisyc-admin-dashboard-charts.js -> client-sync/assets/js/clisyc-admin-dashboard-charts.js
 * Initializes Chart.js instances for the main Client Sync admin dashboard.
 */
document.addEventListener('DOMContentLoaded', function () {
    // --- 1. PRE-FLIGHT CHECKS ---
    if (typeof Chart === 'undefined' || typeof clisycDashboardData === 'undefined') {
        console.error('Client Sync Dashboard: Chart.js or clisycDashboardData is missing.');
        return;
    }

    const l10n = clisycDashboardData.l10n || {};

    // --- 2. HELPER FUNCTIONS ---
    function generateColorPalette(count, opacity = 0.7) {
        const colors = [
            `rgba(54, 162, 235, ${opacity})`,  // Blue
            `rgba(75, 192, 192, ${opacity})`,  // Green
            `rgba(255, 159, 64, ${opacity})`,  // Orange
            `rgba(153, 102, 255, ${opacity})`, // Purple
            `rgba(255, 99, 132, ${opacity})`,  // Red
        ];
        const palette = [];
        for (let i = 0; i < count; i++) {
            palette.push(colors[i % colors.length]);
        }
        return palette;
    }

    function displayNoDataMessage(canvasElement) {
        if (!canvasElement || !canvasElement.parentElement) return;
        const p = document.createElement('p');
        p.textContent = 'No data available to display.';
        p.style.textAlign = 'center';
        p.style.padding = '20px';
        canvasElement.parentElement.appendChild(p);
        canvasElement.remove();
    }

    // --- 3. CHART INITIALIZATION ---

    // Chart 1: Bookings This Week (Bar Chart)
    const ctxBookingsThisWeek = document.getElementById('clisyc-bookings-this-week-chart');
    if (ctxBookingsThisWeek) {
        const weekData = clisycDashboardData.bookingsThisWeek;
        if (weekData && weekData.labels.length > 0) {
            new Chart(ctxBookingsThisWeek, {
                type: 'bar',
                data: {
                    labels: weekData.labels,
                    datasets: [{
                        label: l10n.bookings || 'Bookings',
                        data: weekData.data,
                        backgroundColor: generateColorPalette(weekData.labels.length, 0.5),
                        borderColor: generateColorPalette(weekData.labels.length, 1),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        } else {
            displayNoDataMessage(ctxBookingsThisWeek);
        }
    }

    // Chart 2: Busiest Services (Doughnut Chart)
    const ctxBusiestServices = document.getElementById('clisyc-busiest-services-chart');
    if (ctxBusiestServices) {
        const serviceData = clisycDashboardData.busiestServices;
        if (serviceData && serviceData.labels.length > 0) {
            new Chart(ctxBusiestServices, {
                type: 'doughnut',
                data: {
                    labels: serviceData.labels,
                    datasets: [{
                        label: l10n.bookings || 'Bookings',
                        data: serviceData.data,
                        backgroundColor: generateColorPalette(serviceData.labels.length),
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } }
                }
            });
        } else {
            displayNoDataMessage(ctxBusiestServices);
        }
    }
});