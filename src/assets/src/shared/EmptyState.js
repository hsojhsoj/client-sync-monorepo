/**
 * File: src/assets/src/shared/EmptyState.js
 * Reusable empty state placeholder for Client Sync React apps.
 *
 * Usage:
 *   import EmptyState from '../shared/EmptyState';
 *   <EmptyState icon="calendar" title="No slots found" message="Try adjusting your filters." />
 *
 * @package
 */

import React from 'react';

/**
 * SVG icon presets keyed by name.
 * Keeps the component self-contained without requiring Dashicons or external assets.
 */
const ICONS = {
	calendar: (
		<svg
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
			width="48"
			height="48"
		>
			<rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
			<line x1="16" y1="2" x2="16" y2="6" />
			<line x1="8" y1="2" x2="8" y2="6" />
			<line x1="3" y1="10" x2="21" y2="10" />
		</svg>
	),
	search: (
		<svg
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
			width="48"
			height="48"
		>
			<circle cx="11" cy="11" r="8" />
			<line x1="21" y1="21" x2="16.65" y2="16.65" />
		</svg>
	),
	clock: (
		<svg
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
			width="48"
			height="48"
		>
			<circle cx="12" cy="12" r="10" />
			<polyline points="12 6 12 12 16 14" />
		</svg>
	),
	chart: (
		<svg
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
			width="48"
			height="48"
		>
			<line x1="18" y1="20" x2="18" y2="10" />
			<line x1="12" y1="20" x2="12" y2="4" />
			<line x1="6" y1="20" x2="6" y2="14" />
		</svg>
	),
	list: (
		<svg
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
			width="48"
			height="48"
		>
			<line x1="8" y1="6" x2="21" y2="6" />
			<line x1="8" y1="12" x2="21" y2="12" />
			<line x1="8" y1="18" x2="21" y2="18" />
			<line x1="3" y1="6" x2="3.01" y2="6" />
			<line x1="3" y1="12" x2="3.01" y2="12" />
			<line x1="3" y1="18" x2="3.01" y2="18" />
		</svg>
	),
};

/**
 * Empty state component.
 *
 * @param {Object}        props
 * @param {string}        props.icon    Icon name from ICONS map (default 'calendar').
 * @param {string}        props.title   Heading text.
 * @param {string}        props.message Body text.
 * @param {React.Element} props.action  Optional action button / link element.
 */
const EmptyState = ( { icon = 'calendar', title, message, action } ) => {
	return (
		<div className="clisyc-empty-state" role="status">
			<div className="clisyc-empty-state-icon">
				{ ICONS[ icon ] || ICONS.calendar }
			</div>
			{ title && <h3 className="clisyc-empty-state-title">{ title }</h3> }
			{ message && (
				<p className="clisyc-empty-state-message">{ message }</p>
			) }
			{ action && (
				<div className="clisyc-empty-state-action">{ action }</div>
			) }
		</div>
	);
};

export default EmptyState;
