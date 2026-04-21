/**
 * File: src/assets/src/faceted-booking/index.js
 * Entry point for the Faceted Search Booking Form React application.
 *
 * @package
 * @subpackage ClientSync/Assets/JS/FacetedBooking
 */
import React from 'react';
import { createRoot } from '@wordpress/element';
import App from './App';
import ErrorBoundary from '../shared/ErrorBoundary';
import './style.css';

function initReactApp() {
	const rootEl = document.getElementById(
		'clisyc-faceted-booking-react-root'
	);

	if ( rootEl ) {
		const root = createRoot( rootEl );
		root.render(
			<ErrorBoundary>
				<App />
			</ErrorBoundary>
		);
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initReactApp );
} else {
	setTimeout( initReactApp, 0 );
}
