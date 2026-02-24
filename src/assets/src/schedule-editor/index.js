/**
 * File: src/assets/src/schedule-editor/index.js
 * Entry point for the Schedule Editor React application.
 */
import React from 'react';
import { createRoot } from '@wordpress/element';
import App from './App';
import ErrorBoundary from '../shared/ErrorBoundary';


function initReactApp() {
    // Prevent multiple initializations, which can happen in the block editor.
    if (window.clisycScheduleEditorInitialized) {
        return;
    }

    const rootEl = document.getElementById('clisyc-schedule-editor-root');
    
    if (rootEl) {
        
        // Check if the localized data object is available.
        if (typeof window.clisycScheduleEditorData === 'undefined') {
            console.error('[Client Sync Schedule Editor] Configuration data missing. Check wp_localize_script.');
            rootEl.innerHTML = '<p style="color: red; text-align: center;"><strong>Error:</strong> Missing required configuration data. Cannot load editor.</p>';
            return;
        }

        try {
            window.clisycScheduleEditorInitialized = true; // Set flag to prevent re-initialization
            const root = createRoot(rootEl);
            root.render(<ErrorBoundary><App /></ErrorBoundary>);
        } catch (error) {
            console.error('[Client Sync Schedule Editor] Critical render error:', error);
            rootEl.innerHTML = '<p style="color: red;">A critical error occurred while rendering the editor. Check the browser console for details.</p>';
        }
    }
}

// Gutenberg/Block editor can be tricky. It doesn't always fire DOMContentLoaded reliably for meta boxes.
// We need a more robust way to initialize.
if (document.readyState === 'loading') {
    // Standard browser behavior
    document.addEventListener('DOMContentLoaded', initReactApp);
} else {
    // DOM is already ready ('interactive' or 'complete'). This is common in the block editor.
    // A small timeout can sometimes help with race conditions in the block editor where the DOM is ready but React meta boxes haven't been added yet.
    setTimeout(initReactApp, 100);
}
