/**
 * File: src/assets/src/booking-form/index.js
 * Entry point for the Frontend Booking Form React application.
 */
import React from 'react';
import { createRoot } from '@wordpress/element';
import App from './App';
import { BookingProvider } from './BookingContext';
import ErrorBoundary from './components/ErrorBoundary';
import { bootstrapApiFetch } from '../shared/bootstrap-api-fetch';
import './style.css';

// Register the wp_rest nonce on the shared apiFetch instance so every
// REST call (seat holds, filter options, price lookups, etc.) includes
// X-WP-Nonce. Required for the seat-hold write endpoints after they were
// hardened to reject anonymous requests without a valid nonce.
bootstrapApiFetch();

function initReactApp() {
	const rootEl = document.getElementById( 'clisyc-booking-form-react-root' );

	if ( rootEl ) {
		// Check for required data
		if ( ! window.clisycBookingFormData ) {
			console.error(
				'[Client Sync] Booking form data not found. The form may not work correctly.'
			);
		}

		const root = createRoot( rootEl );
		root.render(
			<ErrorBoundary>
				<BookingProvider>
					<App />
				</BookingProvider>
			</ErrorBoundary>
		);
	}
}

// Initialize when DOM is ready
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initReactApp );
} else {
	// DOM is already ready
	setTimeout( initReactApp, 0 );
}
