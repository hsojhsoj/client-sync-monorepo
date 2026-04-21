/**
 * File: src/assets/src/shared/ErrorBoundary.js
 * Shared React ErrorBoundary component for all Client Sync apps.
 * Catches rendering errors in children and displays a user-friendly fallback UI.
 */
import React, { Component } from 'react';

class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false, error: null };
	}

	static getDerivedStateFromError( error ) {
		return { hasError: true, error };
	}

	componentDidCatch( error, errorInfo ) {
		console.error( '[Client Sync] Component error:', error, errorInfo );
	}

	render() {
		if ( this.state.hasError ) {
			return (
				<div
					style={ {
						padding: '20px',
						textAlign: 'center',
						border: '1px solid #e0e0e0',
						borderRadius: '8px',
						margin: '20px 0',
					} }
				>
					<p style={ { fontSize: '16px', color: '#666' } }>
						Something went wrong loading this section. Please
						refresh the page to try again.
					</p>
					<button
						onClick={ () => window.location.reload() }
						style={ {
							padding: '8px 16px',
							cursor: 'pointer',
							background: '#0073aa',
							color: '#fff',
							border: 'none',
							borderRadius: '4px',
						} }
					>
						Refresh Page
					</button>
				</div>
			);
		}
		return this.props.children;
	}
}

export default ErrorBoundary;
