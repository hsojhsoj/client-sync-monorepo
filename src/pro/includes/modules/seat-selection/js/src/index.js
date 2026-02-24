/**
 * Webpack entry for the Venue Admin app (pro-seat-selection).
 *
 * Mounts VenueAdmin into #clisyc-venue-admin-root and
 * PricingApp into #clisyc-venue-pricing-root on the clisyc_venue CPT edit screen.
 */
import { createRoot } from '@wordpress/element';
import VenueAdmin, { PricingApp } from './VenueAdmin';
import './style.css';

document.addEventListener( 'DOMContentLoaded', () => {
	// Mount venue SVG admin.
	const venueRoot = document.getElementById( 'clisyc-venue-admin-root' );
	if ( venueRoot ) {
		const venueId = parseInt( venueRoot.dataset.venueId, 10 ) || 0;
		const parsingMode = venueRoot.dataset.parsingMode || 'flat_ids';
		let initialLayout = null;
		let initialOverrides = [];

		try {
			initialLayout = JSON.parse( venueRoot.dataset.layout );
		} catch ( e ) {
			// Ignore parse errors.
		}
		try {
			initialOverrides = JSON.parse( venueRoot.dataset.categoryOverrides ) || [];
		} catch ( e ) {
			// Ignore parse errors.
		}

		const root = createRoot( venueRoot );
		root.render(
			<VenueAdmin
				venueId={ venueId }
				initialMode={ parsingMode }
				initialLayout={ initialLayout }
				initialOverrides={ initialOverrides }
			/>
		);
	}

	// Mount pricing tiers editor.
	const pricingRoot = document.getElementById( 'clisyc-venue-pricing-root' );
	if ( pricingRoot ) {
		let initialTiers = [];
		try {
			initialTiers = JSON.parse( pricingRoot.dataset.tiers ) || [];
		} catch ( e ) {
			// Ignore parse errors.
		}

		const pricingRootInstance = createRoot( pricingRoot );
		pricingRootInstance.render(
			<PricingApp initialTiers={ initialTiers } />
		);
	}
} );
