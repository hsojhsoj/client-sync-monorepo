/**
 * File: src/assets/js/clisyc-admin-user-progress-charts.js -> client-sync/assets/js/clisyc-admin-user-progress-charts.js
 * 
 * Renders progress charts on the WordPress user profile screen.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 *
 * This script uses the Chart.js library to create line charts that visualize
 * the history of specific numeric appointment custom fields for a client.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Assets/JS
 */

document.addEventListener('DOMContentLoaded', function () {
    // --- Prerequisite Checks ---
    if (typeof Chart === 'undefined') {
        console.error('Client Sync User Charts: Chart.js library is not loaded. Charts cannot be rendered.');
        return;
    }
    // REFACTORED: Updated JS data object name
    if (typeof clisycUserProfileProgressData === 'undefined' || typeof clisycUserProfileProgressData.chartData === 'undefined') {
        console.error('Client Sync User Charts: Localization data (clisycUserProfileProgressData) is not available.');
        return;
    }

    // REFACTORED: Updated JS data object name
    const chartDataSets = clisycUserProfileProgressData.chartData;
    const l10n = clisycUserProfileProgressData.l10n || {};

    /**
     * Generates a pleasant, non-clashing color for a chart.
     * @param {number} opacity - The alpha transparency for the color.
     * @returns {string} An rgba color string.
     */
    function getRandomColor(opacity = 0.7) {
        // Using a limited palette for more pleasant, less random colors
        const colors = [
            [54, 162, 235], // Blue
            [75, 192, 192], // Green
            [255, 159, 64], // Orange
            [153, 102, 255], // Purple
            [255, 99, 132], // Red
        ];
        const selectedColor = colors[Math.floor(Math.random() * colors.length)];
        return `rgba(${selectedColor[0]}, ${selectedColor[1]}, ${selectedColor[2]}, ${opacity})`;
    }

    // --- Chart Rendering Loop ---
    for (const fieldKey in chartDataSets) {
        if (Object.prototype.hasOwnProperty.call(chartDataSets, fieldKey)) {
            const chartConfig = chartDataSets[fieldKey];
            // REFACTORED: Updated element ID prefix
            const canvasId = 'client-sync-progress-chart-' + fieldKey;
            const ctx = document.getElementById(canvasId);

            if (ctx && chartConfig.labels && chartConfig.data && chartConfig.labels.length > 0) {
                
                const pointColor = getRandomColor(1);
                const areaColor = getRandomColor(0.3);

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartConfig.labels, // Appointment Dates (formatted by PHP)
                        datasets: [{
                            label: chartConfig.fieldLabel || fieldKey,
                            data: chartConfig.data,
                            borderColor: pointColor,
                            backgroundColor: areaColor,
                            fill: true,
                            tension: 0.2, // Smoothens the line slightly
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointBackgroundColor: pointColor
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            title: {
                                display: false, // The title is an <h3> element above the canvas
                            },
                            legend: {
                                display: false // Only one dataset, so legend is redundant
                            },
                            tooltip: {
                                callbacks: {
                                    title: function(tooltipItems) {
                                        // The label is already the formatted date from PHP
                                        return tooltipItems[0].label;
                                    },
                                    label: function(tooltipItem) {
                                        return `${chartConfig.fieldLabel || fieldKey}: ${tooltipItem.formattedValue}`;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                title: {
                                    display: true,
                                    text: l10n.appointmentDate || 'Appointment Date'
                                },
                                ticks: {
                                     maxRotation: 45,
                                     minRotation: 20,
                                }
                            },
                            y: {
                                beginAtZero: false, // Let chart.js determine the best starting point
                                title: {
                                    display: true,
                                    text: l10n.value || 'Value'
                                }
                            }
                        }
                    }
                });
            } else if (ctx) {
                // If canvas exists but no data, replace it with a message.
                const noDataMessage = l10n.noDataForChart || 'No data available for this chart.';
                const chartWrapper = ctx.parentElement;
                if (chartWrapper) {
                    // REFACTORED: Updated CSS class name
                    chartWrapper.innerHTML = `<p class="clisyc-chart-no-data"><em>${noDataMessage}</em></p>`;
                }
            }
        }
    }
});