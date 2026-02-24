/**
 * File: src/assets/src/hybrid-booking/index.js
 * Entry point for the Hybrid Booking React application.
 * Supports multiple shortcode instances on the same page.
 */
import { createRoot } from '@wordpress/element';
import HybridApp from './HybridApp';
import ErrorBoundary from '../shared/ErrorBoundary';
import './style.css';

/**
 * Initialize all hybrid booking instances on the page.
 * Each shortcode renders a <div class="clisyc-hybrid-booking-root" data-display-options="...">
 * We find all of them and render a React app for each.
 */
function initializeHybridBooking() {
    // Find all container elements (supports multiple shortcodes on same page)
    const containers = document.querySelectorAll('.clisyc-hybrid-booking-root');

    if (containers.length === 0) {
        // Fallback: try the old single-instance ID
        const legacyContainer = document.getElementById('clisyc-hybrid-booking-root');
        if (legacyContainer) {
            renderInstance(legacyContainer);
        }
        return;
    }

    // Render a React app for each container
    containers.forEach((container) => {
        renderInstance(container);
    });
}

/**
 * Render a single HybridApp instance into a container.
 *
 * @param {HTMLElement} container The container element to render into.
 */
function renderInstance(container) {
    // Read display options from data attribute
    const dataAttr = container.getAttribute('data-display-options');
    let displayOptions = {};

    if (dataAttr) {
        try {
            displayOptions = JSON.parse(dataAttr);
        } catch (e) {
            displayOptions = {};
        }
    }

    // Create React root and render
    const root = createRoot(container);
    root.render(<ErrorBoundary><HybridApp displayOptions={displayOptions} /></ErrorBoundary>);
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeHybridBooking);
} else {
    // DOM already loaded
    initializeHybridBooking();
}
