/**
 * File: src/assets/js/clisyc-admin-standard-schedule-interactions.js -> client-sync/assets/js/clisyc-admin-standard-schedule-interactions.js
 *
 * Handles interactions for the dimension/value dropdowns on the Standard Day Schedule tab.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Assets/JS
 */
jQuery(document).ready(function($) {
    // Ensure the localized data object is available.
    if (typeof clisycStdScheduleInteractData === 'undefined') {
        return;
    }

    const { selectableDimensions, currentDimKey, currentDimValue, l10n } = clisycStdScheduleInteractData;

    function populateValueSelect(dimKey) {
        var $valueSelect = $('#dimension_value_select');
        var $valueRow = $('#dimension_value_row');
        $valueSelect.empty().append($('<option>').val('').text(l10n.selectValue));

        if (dimKey && selectableDimensions[dimKey] && selectableDimensions[dimKey].options) {
            $.each(selectableDimensions[dimKey].options, function(i, option) {
                $valueSelect.append($('<option>').val(option).text(option));
            });
            if (dimKey === currentDimKey && currentDimValue) {
                $valueSelect.val(currentDimValue);
            }
            $valueRow.show();
        } else {
            $valueRow.hide();
        }
    }

    $('#dimension_key_select').on('change', function() {
        populateValueSelect($(this).val());
    });
    
    $('#dimension_value_select').on('change', function() {
        var dimKey = $('#dimension_key_select').val();
        var dimValue = $(this).val();
        if (dimKey && dimValue) {
            var url = new URL(window.location.href);
            url.searchParams.set('page', 'clisyc-calendars');
            url.searchParams.set('tab', 'day-schedule');
            url.searchParams.set('dimension_key', dimKey);
            url.searchParams.set('dimension_value', dimValue);
            window.location.href = url.toString();
        }
    });
    
    if (currentDimKey) {
        populateValueSelect(currentDimKey);
    }
});