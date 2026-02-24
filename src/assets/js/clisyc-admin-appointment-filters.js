/** 
 * File: src/assets/js/clisyc-admin-appointment-filters.js -> client-sync/assets/js/clisyc-admin-appointment-filters.js 
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 */
jQuery(document).ready(function($) {
    // Check if the jQuery UI datepicker function exists.
    if (typeof $.fn.datepicker === 'function') {
        // *** REFACTORED: Updated CSS class selector ***
        $('.clisyc-admin-datepicker').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            showButtonPanel: true
        });
    } else {
        console.warn('Client Sync: jQuery UI Datepicker not loaded. Admin date filters will be text-only.');
    }
});