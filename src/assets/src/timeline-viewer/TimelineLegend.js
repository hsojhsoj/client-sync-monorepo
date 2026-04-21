/**
 * File: src/assets/src/timeline-viewer/TimelineLegend.js
 * Timeline Legend Component - Displays a key explaining timeline elements
 *
 * UPDATED: Changed Booked color from blue to light yellow for better distinction
 */
import React from 'react';

const TimelineLegend = ( { dimensionTypes = [], l10n = {} } ) => {
	// Status indicators - 5 types
	const statusIndicators = [
		{
			key: 'always',
			label: l10n.alwaysAvailable || 'Always Available',
			description: l10n.alwaysAvailableDesc || 'No schedule restrictions',
			bgColor: '#bfdbfe', // Light blue
			borderColor: '#60a5fa',
		},
		{
			key: 'schedule',
			label: l10n.openHours || 'Open Hours',
			description: l10n.openHoursDesc || 'Resource availability schedule',
			bgColor: '#e0f2fe', // Even lighter blue
			borderColor: '#7dd3fc',
		},
		{
			key: 'slot',
			label: l10n.bookableSlot || 'Bookable Slot',
			description: l10n.bookableSlotDesc || 'Available for booking',
			bgColor: '#10b981',
			borderColor: '#059669',
		},
		{
			key: 'booked',
			label: l10n.booked || 'Booked',
			description: l10n.bookedDesc || 'Confirmed appointment',
			bgColor: '#fffbc0', // Light yellow - rgb(255, 251, 192)
			borderColor: '#e5d85c',
		},
		{
			key: 'blocked',
			label: l10n.blocked || 'Blocked',
			description: l10n.blockedDesc || 'Unavailable',
			bgColor: '#ef4444',
			borderColor: '#dc2626',
		},
	];

	return (
		<div className="clisyc-timeline-legend">
			{ /* Legend Header */ }
			<div className="clisyc-timeline-legend-header">
				<span className="clisyc-timeline-legend-title">
					<span className="dashicons dashicons-info-outline"></span>
					{ l10n.legendTitle || 'Timeline Key' }
				</span>
			</div>

			{ /* Legend Content - Horizontal Layout */ }
			<div className="clisyc-timeline-legend-content">
				{ /* Status Indicators */ }
				<div className="clisyc-timeline-legend-section">
					<span className="clisyc-timeline-legend-section-label">
						{ l10n.statusSection || 'Status' }:
					</span>
					<div className="clisyc-timeline-legend-items">
						{ statusIndicators.map( ( status ) => (
							<div
								key={ status.key }
								className="clisyc-timeline-legend-item"
								title={ status.description }
							>
								<span
									className="clisyc-timeline-legend-swatch"
									style={ {
										backgroundColor: status.bgColor,
										borderColor: status.borderColor,
									} }
								></span>
								<span className="clisyc-timeline-legend-label">
									{ status.label }
								</span>
							</div>
						) ) }
					</div>
				</div>

				{ /* Dimension Types - only show if more than one type */ }
				{ dimensionTypes.length > 1 && (
					<div className="clisyc-timeline-legend-section">
						<span className="clisyc-timeline-legend-section-label">
							{ l10n.dimensionTypesSection || 'Types' }:
						</span>
						<div className="clisyc-timeline-legend-items">
							{ dimensionTypes.map( ( dimType ) => (
								<div
									key={ dimType.slug }
									className="clisyc-timeline-legend-item"
								>
									<span
										className={ `dashicons ${
											dimType.icon ||
											'dashicons-calendar-alt'
										}` }
									></span>
									<span className="clisyc-timeline-legend-label">
										{ dimType.label_plural ||
											dimType.label }
										{ dimType.is_primary && (
											<span className="clisyc-timeline-legend-badge">
												Primary
											</span>
										) }
									</span>
								</div>
							) ) }
						</div>
					</div>
				) }
			</div>
		</div>
	);
};

export default TimelineLegend;
