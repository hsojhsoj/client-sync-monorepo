/**
 * File: src/assets/src/shared/SkeletonLoader.js
 * Reusable skeleton loading placeholder components for Client Sync React apps.
 *
 * Usage:
 *   import { SkeletonCard, SkeletonTable, SkeletonCalendar, SkeletonTimeline } from '../shared/SkeletonLoader';
 *
 * @package
 */

import React from 'react';

/**
 * Base skeleton pulse element.
 *
 * @param {Object} props
 * @param {string} props.width  CSS width (default '100%').
 * @param {string} props.height CSS height (default '16px').
 * @param {string} props.radius CSS border-radius (default '4px').
 * @param {Object} props.style  Extra inline styles.
 */
export function SkeletonPulse( {
	width = '100%',
	height = '16px',
	radius = '4px',
	style = {},
} ) {
	return (
		<div
			className="clisyc-skeleton-pulse"
			style={ { width, height, borderRadius: radius, ...style } }
			aria-hidden="true"
		/>
	);
}

/**
 * Skeleton card with a title bar and several text lines.
 *
 * @param {Object} props
 * @param {number} props.lines Number of text lines (default 3).
 */
export function SkeletonCard( { lines = 3 } ) {
	return (
		<div
			className="clisyc-skeleton-card"
			aria-label="Loading"
			role="status"
		>
			<SkeletonPulse
				height="20px"
				width="60%"
				style={ { marginBottom: '16px' } }
			/>
			{ Array.from( { length: lines }, ( _, i ) => (
				<SkeletonPulse
					key={ i }
					height="14px"
					width={ i === lines - 1 ? '40%' : '100%' }
					style={ { marginBottom: '10px' } }
				/>
			) ) }
		</div>
	);
}

/**
 * Skeleton table with header row and body rows.
 *
 * @param {Object} props
 * @param {number} props.rows    Number of body rows (default 5).
 * @param {number} props.columns Number of columns (default 4).
 */
export function SkeletonTable( { rows = 5, columns = 4 } ) {
	return (
		<div
			className="clisyc-skeleton-table"
			aria-label="Loading"
			role="status"
		>
			<div className="clisyc-skeleton-table-header">
				{ Array.from( { length: columns }, ( _, i ) => (
					<SkeletonPulse key={ i } height="16px" width="80%" />
				) ) }
			</div>
			{ Array.from( { length: rows }, ( _, r ) => (
				<div key={ r } className="clisyc-skeleton-table-row">
					{ Array.from( { length: columns }, ( _, c ) => (
						<SkeletonPulse
							key={ c }
							height="14px"
							width={ c === 0 ? '70%' : '50%' }
						/>
					) ) }
				</div>
			) ) }
		</div>
	);
}

/**
 * Skeleton mimicking a calendar grid (header + cells).
 */
export function SkeletonCalendar() {
	return (
		<div
			className="clisyc-skeleton-calendar"
			aria-label="Loading calendar"
			role="status"
		>
			{ /* Toolbar */ }
			<div className="clisyc-skeleton-calendar-toolbar">
				<SkeletonPulse width="120px" height="32px" radius="4px" />
				<SkeletonPulse width="180px" height="24px" radius="4px" />
				<SkeletonPulse width="120px" height="32px" radius="4px" />
			</div>
			{ /* Day headers */ }
			<div className="clisyc-skeleton-calendar-header">
				{ Array.from( { length: 7 }, ( _, i ) => (
					<SkeletonPulse key={ i } width="80%" height="16px" />
				) ) }
			</div>
			{ /* Grid cells */ }
			{ Array.from( { length: 5 }, ( _, r ) => (
				<div key={ r } className="clisyc-skeleton-calendar-row">
					{ Array.from( { length: 7 }, ( _, c ) => (
						<div
							key={ c }
							className="clisyc-skeleton-calendar-cell"
						>
							<SkeletonPulse
								width="24px"
								height="14px"
								style={ { marginBottom: '6px' } }
							/>
							{ r < 3 && (
								<SkeletonPulse width="90%" height="10px" />
							) }
						</div>
					) ) }
				</div>
			) ) }
		</div>
	);
}

/**
 * Skeleton mimicking a timeline / resource view.
 *
 * @param {Object} props
 * @param {number} props.resources Number of resource rows (default 4).
 */
export function SkeletonTimeline( { resources = 4 } ) {
	return (
		<div
			className="clisyc-skeleton-timeline"
			aria-label="Loading timeline"
			role="status"
		>
			{ /* Header row */ }
			<div className="clisyc-skeleton-timeline-header">
				<SkeletonPulse width="140px" height="20px" />
				{ Array.from( { length: 6 }, ( _, i ) => (
					<SkeletonPulse key={ i } width="60px" height="14px" />
				) ) }
			</div>
			{ /* Resource rows */ }
			{ Array.from( { length: resources }, ( _, r ) => (
				<div key={ r } className="clisyc-skeleton-timeline-row">
					<SkeletonPulse width="120px" height="16px" />
					<div className="clisyc-skeleton-timeline-blocks">
						<SkeletonPulse
							width={ `${ 20 + Math.floor( ( r * 17 ) % 40 ) }%` }
							height="28px"
							radius="4px"
							style={ {
								marginLeft: `${ 5 + ( ( r * 13 ) % 30 ) }%`,
							} }
						/>
					</div>
				</div>
			) ) }
		</div>
	);
}

/**
 * Skeleton for filter / dimension selector panels.
 *
 * @param {Object} props
 * @param {number} props.filters Number of filter groups (default 3).
 */
export function SkeletonFilters( { filters = 3 } ) {
	return (
		<div
			className="clisyc-skeleton-filters"
			aria-label="Loading filters"
			role="status"
		>
			{ Array.from( { length: filters }, ( _, i ) => (
				<div key={ i } className="clisyc-skeleton-filter-group">
					<SkeletonPulse
						width="90px"
						height="14px"
						style={ { marginBottom: '8px' } }
					/>
					<SkeletonPulse width="100%" height="36px" radius="4px" />
				</div>
			) ) }
		</div>
	);
}
