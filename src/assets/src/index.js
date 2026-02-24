/**
 * File: src/assets/src/index.js -> client-sync/assets/src/index.js
 * React Application Entry Point (Final Initializer)
 */
import React from 'react';
import { createRoot } from '@wordpress/element';
import App from './App';
import './style.css';

function initNodeEditor() {
    const rootEl = document.getElementById('clisyc-node-editor-root');

    if (rootEl && !rootEl.getAttribute('data-clisyc-editor-initialized')) {
        rootEl.setAttribute('data-clisyc-editor-initialized', 'true');
        
        const root = createRoot(rootEl);
        root.render(<App />);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNodeEditor);
} else {
    initNodeEditor();
}