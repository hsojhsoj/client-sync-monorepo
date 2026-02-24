/**
 * Frontend Venue Map Highlighter.
 *
 * Colours the SVG venue map on the appointment detail page:
 * – Selected (booked) seats → blue highlight with a subtle pulse.
 * – All other seats         → dimmed grey.
 *
 * Reads `data-all-seats` and `data-selected-seats` JSON arrays
 * from the #clisyc-venue-map container element.
 *
 * @package ClientSync
 */

/* Seat colour palette — matches booking-form SEAT_COLORS. */
const COLORS = {
	selected: { fill: '#3b82f6', stroke: '#1d4ed8' }, // Blue — "your seats"
	other:    { fill: '#e5e7eb', stroke: '#9ca3af' },  // Grey — dimmed
};

document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'clisyc-venue-map' );
	if ( ! container ) {
		return;
	}

	let allSeatIds, selectedSeatIds;

	try {
		allSeatIds      = JSON.parse( container.dataset.allSeats || '[]' );
		selectedSeatIds = JSON.parse( container.dataset.selectedSeats || '[]' );
	} catch ( e ) {
		return;
	}

	if ( ! allSeatIds.length ) {
		return;
	}

	const selectedSet = new Set( selectedSeatIds );

	// Find the SVG element inside the container (rendered server-side).
	const svg = container.querySelector( 'svg' );
	if ( ! svg ) {
		return;
	}

	// Make SVG responsive.
	svg.setAttribute( 'width', '100%' );
	svg.setAttribute( 'height', '100%' );
	svg.style.display = 'block';

	// Ensure SVG has a viewBox so it scales properly.
	if ( ! svg.getAttribute( 'viewBox' ) ) {
		const w = svg.getAttribute( 'width' ) || svg.getBBox().width;
		const h = svg.getAttribute( 'height' ) || svg.getBBox().height;
		if ( w && h ) {
			svg.setAttribute( 'viewBox', `0 0 ${ parseFloat( w ) } ${ parseFloat( h ) }` );
		}
	}

	// Remove explicit width/height so viewBox governs sizing.
	svg.removeAttribute( 'width' );
	svg.removeAttribute( 'height' );
	svg.style.width = '100%';
	svg.style.height = 'auto';

	// Create a <style> element for the pulse animation (keeps DOM clean).
	const styleEl = document.createElementNS( 'http://www.w3.org/2000/svg', 'style' );
	styleEl.textContent = `
		@keyframes clisyc-seat-pulse {
			0%, 100% { opacity: 1; }
			50% { opacity: 0.7; }
		}
		.clisyc-seat--selected {
			animation: clisyc-seat-pulse 2s ease-in-out 3;
		}
	`;
	svg.prepend( styleEl );

	// Colour each seat element.
	allSeatIds.forEach( ( id ) => {
		const el = svg.querySelector( `#${ CSS.escape( id ) }` );
		if ( ! el ) {
			return;
		}

		const isSelected = selectedSet.has( id );
		const colors     = isSelected ? COLORS.selected : COLORS.other;

		el.style.fill        = colors.fill;
		el.style.stroke      = colors.stroke;
		el.style.strokeWidth = '1.5';
		el.style.transition  = 'fill 0.2s ease, stroke 0.2s ease';

		if ( isSelected ) {
			el.classList.add( 'clisyc-seat--selected' );

			// Add an SVG <title> tooltip for accessibility.
			const title = document.createElementNS( 'http://www.w3.org/2000/svg', 'title' );
			title.textContent = el.getAttribute( 'id' ).replace( /-/g, ' ' );
			el.prepend( title );
		}
	} );
} );
