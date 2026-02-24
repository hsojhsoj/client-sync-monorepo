/**
 * File: src/assets/src/timeline-viewer/index.js
 * Entry point for the Timeline Viewer React application
 */
import React from 'react';
import { createRoot } from '@wordpress/element';
import TimelineApp from './TimelineApp';
import TimelineLegend from './TimelineLegend';
import ErrorBoundary from '../shared/ErrorBoundary';
import './style.css';

document.addEventListener('DOMContentLoaded', () => {
    const containers = document.querySelectorAll('.clisyc-timeline-container');
    
    containers.forEach(container => {
        const containerId = container.id;
        
        if (containerId) {
            const loadingEl = container.querySelector('.clisyc-timeline-loading');
            if (loadingEl) {
                loadingEl.style.display = 'none';
            }
            
            const root = createRoot(container);
            root.render(<ErrorBoundary><TimelineApp containerId={containerId} /></ErrorBoundary>);
        }
    });
});

export { TimelineApp, TimelineLegend };