/**
 * File: src/assets/js/clisyc-frontend-image-marker.js -> client-sync/assets/js/clisyc-frontend-image-marker.js
 * Handles the interactive Image Map custom field on frontend forms.
 * REFACTORED: Now correctly initializes in page builder environments like Beaver Builder.
 */
jQuery(function($) {

    // Main function to initialize a specific image map instance.
    function initImageMapInstance(fieldContainer) {
        const $field = $(fieldContainer);

        if ($field.data('image-marker-initialized')) {
            return;
        }
        $field.data('image-marker-initialized', true);

        const $wrapper = $field.find('.clisyc-image-marker-wrapper');
        const $image = $wrapper.find('img');
        const $markerContainer = $wrapper.find('.clisyc-marker-container');
        const $hiddenInput = $field.find('input.clisyc-image-marker-data');
        const $undoButton = $field.find('button.clisyc-image-marker-undo');
        const $resetButton = $field.find('button.clisyc-image-marker-reset');

        if (!$image.length || !$hiddenInput.length || !$markerContainer.length) {
            return;
        }

        let coordinates = [];
        const markerSize = 12;

        const drawMarkers = () => {
            $markerContainer.empty();
            coordinates.forEach(coord => {
                if (typeof coord.x !== 'number' || typeof coord.y !== 'number') return;
                const leftPerc = Math.max(0, Math.min(100, coord.x * 100));
                const topPerc = Math.max(0, Math.min(100, coord.y * 100));
                $('<div class="clisyc-marker"></div>').css({
                    position: 'absolute', left: `${leftPerc}%`, top: `${topPerc}%`,
                    width: `${markerSize}px`, height: `${markerSize}px`,
                    marginLeft: `${-markerSize / 2}px`, marginTop: `${-markerSize / 2}px`,
                }).appendTo($markerContainer);
            });
        };

        const updateInput = () => {
            const jsonString = JSON.stringify(coordinates);
            if ($hiddenInput.val() !== jsonString) {
                $hiddenInput.val(jsonString).trigger('change');
            }
        };

        const loadInitialCoordinates = () => {
            const initialValue = $hiddenInput.val();
            try {
                const parsed = JSON.parse(initialValue);
                coordinates = Array.isArray(parsed) ? parsed.filter(c => typeof c === 'object' && c !== null && typeof c.x === 'number' && typeof c.y === 'number') : [];
            } catch (e) {
                coordinates = [];
            }
            drawMarkers();
        };

        const setupHandlers = () => {
            if ($image.data('clisyc-marker-handlers-set')) return;
            $image.data('clisyc-marker-handlers-set', true);
            loadInitialCoordinates();

            $image.on('click.clisycMarker', function(event) {
                const rect = this.getBoundingClientRect();
                if (this.offsetWidth === 0 || this.offsetHeight === 0) return;
                const clickX = event.clientX - rect.left;
                const clickY = event.clientY - rect.top;
                const percX = Math.max(0, Math.min(1, clickX / this.offsetWidth));
                const percY = Math.max(0, Math.min(1, clickY / this.offsetHeight));
                coordinates.push({ x: percX, y: percY });
                drawMarkers();
                updateInput();
            });

            $image.on('dragstart.clisycMarker', (e) => e.preventDefault());
            $undoButton.on('click.clisycMarker', function() { coordinates.pop(); drawMarkers(); updateInput(); });
            $resetButton.on('click.clisycMarker', function() { coordinates = []; drawMarkers(); updateInput(); });
        };

        // Ensure image is loaded before setting up handlers
        $image.one('load', setupHandlers).each(function() {
            if (this.complete) $(this).trigger('load');
        });

        // Listen for external changes to the hidden input value
        $hiddenInput.on('clisyc_image_map_value_changed', loadInitialCoordinates);
    }

    // A function to find and initialize all uninitialized image maps on the page.
    function initializeAllImageMaps() {
        const imageMapFields = $('.clisyc-field-type-image_map');
        if (imageMapFields.length > 0) {
            imageMapFields.each(function() {
                initImageMapInstance(this);
            });
        }
    }

    // --- INITIALIZATION LOGIC ---

    // 1. Run on standard document ready.
    initializeAllImageMaps();

    // 2. Add listeners for page builders.
    $(document).on('fl-builder.layout-rendered', initializeAllImageMaps);

    // 3. Failsafe for other dynamic content.
    $(document).on('clisyc_form_refreshed', initializeAllImageMaps);
});
