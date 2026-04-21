/**
 * File: src/assets/src/manage-slots/index.js -> (compiled)
 * Entry point for the "Manage Time Slots" React application.
 *
 * This script finds the root DOM element and mounts the main App component,
 * effectively starting the client-side application.
 *
 * @package
 * @subpackage ClientSync/Assets/JS/ManageSlots
 */
import React from 'react';
import { createRoot } from '@wordpress/element';
import App from './App';
import ErrorBoundary from '../shared/ErrorBoundary';
import './style.css';

const rootEl = document.getElementById( 'clisyc-manage-slots-react-root' );
if ( rootEl ) {
	const root = createRoot( rootEl );
	root.render(
		<ErrorBoundary>
			<App />
		</ErrorBoundary>
	);
}
