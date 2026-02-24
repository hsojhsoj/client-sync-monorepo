/** 
 * File: src/assets/js/clisyc-admin-testing-page.js -> client-sync/assets/js/clisyc-admin-testing-page.js
 * Handles interactivity for the Testing & Development Tools admin page.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 */
document.addEventListener('DOMContentLoaded', function() {
    // *** REFACTORED: Updated element ID to match the new prefixed ID in the view file ***
    var el = document.getElementById('clisyc-browser-time-display');
    
    if (el) {
        try {
            var browserDate = new Date();
            el.textContent = browserDate.toString() + ' (Offset: ' + (-browserDate.getTimezoneOffset()) + ' mins from UTC)';
        } catch (e) {
            el.textContent = 'Could not determine browser time.';
        }
    }
});