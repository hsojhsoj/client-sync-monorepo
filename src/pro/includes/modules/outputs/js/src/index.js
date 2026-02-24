/**
 * File: src/pro/includes/modules/outputs/js/src/index.js -> src/pro/includes/modules/outputs/js/dist/index.js
 * Entry point for the Client Sync Pro Output Template Builder React application.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Outputs/JS
 */
import React, { Component } from 'react';
import { createRoot } from '@wordpress/element';
import App from './App';
import './style.css';

class ErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }
    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }
    componentDidCatch(error, errorInfo) {
        console.error('[Client Sync Output Builder] Component error:', error, errorInfo);
    }
    render() {
        if (this.state.hasError) {
            return React.createElement('div', { style: { padding: '20px', textAlign: 'center', border: '1px solid #e0e0e0', borderRadius: '8px', margin: '20px 0' } },
                React.createElement('p', { style: { fontSize: '16px', color: '#666' } }, 'Something went wrong loading the output builder. Please refresh the page.'),
                React.createElement('button', { onClick: () => this.setState({ hasError: false, error: null }), style: { padding: '8px 16px', cursor: 'pointer', background: '#0073aa', color: '#fff', border: 'none', borderRadius: '4px' } }, 'Try Again')
            );
        }
        return this.props.children;
    }
}

function initReactApp() {
    // Prevent multiple initializations.
    if (window.clisycOutputBuilderInitialized) {
        return;
    }
    window.clisycOutputBuilderInitialized = true;

    const rootEl = document.getElementById('clisyc-output-builder-root');

    if (rootEl) {
        try {
            const root = createRoot(rootEl);
            root.render(<ErrorBoundary><App /></ErrorBoundary>);
        } catch (error) {
            console.error('[Client Sync Output Builder] Critical render error:', error);
            rootEl.innerHTML = '<p style="color: red;">A critical error occurred while loading the output builder. Check the browser console for details.</p>';
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initReactApp);
} else {
    initReactApp();
}