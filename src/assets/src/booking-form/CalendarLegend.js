/**
 * File: src/assets/src/booking-form/CalendarLegend.js
 * A legend component showing what each calendar color represents.
 *
 * Uses CSS variables injected by class-frontend.php from the
 * Appearance tab color settings (clisyc_calendar_color_settings).
 *
 * CSS Variables (from inject_css_variables()):
 *   --clisyc-available-bg / --clisyc-available-text
 *   --clisyc-booked-bg / --clisyc-booked-text
 *   --clisyc-blocked-bg / --clisyc-blocked-text
 *
 * @package
 */
import React, { useMemo } from 'react';

const CalendarLegend = React.memo( ( { showBooked = false } ) => {
	const { l10n = {} } = window.clisycBookingFormData || {};

	/**
	 * Legend items configuration (memoized to avoid recreation on every render).
	 *
	 * Colors use CSS variables injected by inject_css_variables() in class-frontend.php.
	 * These match the colors defined on Settings → Appearance tab.
	 */
	const legendItems = useMemo(
		() => [
			{
				key: 'available',
				label: l10n.legendAvailable || 'Available',
				bgVar: 'var(--clisyc-available-bg, #d1fae5)',
				textVar: 'var(--clisyc-available-text, #065f46)',
				show: true,
				type: 'slot',
			},
			{
				key: 'booked',
				label: l10n.legendBooked || 'Booked',
				bgVar: 'var(--clisyc-booked-bg, #fee2e2)',
				textVar: 'var(--clisyc-booked-text, #991b1b)',
				show: showBooked,
				type: 'slot',
			},
			{
				key: 'blocked',
				label: l10n.legendBlocked || 'Blocked / Unavailable',
				bgVar: 'var(--clisyc-blocked-bg, #e5e7eb)',
				textVar: 'var(--clisyc-blocked-text, #374151)',
				show: true,
				type: 'slot',
			},
			{
				key: 'today',
				label: l10n.legendToday || 'Today',
				bgVar: '#fffbeb', // FullCalendar's default today highlight
				textVar: '#92400e',
				show: true,
				type: 'highlight',
			},
			{
				key: 'currentTime',
				label: l10n.legendCurrentTime || 'Current Time',
				bgVar: '#ef4444', // FullCalendar's default now indicator (red line)
				textVar: '#ffffff',
				show: true,
				type: 'line',
			},
		],
		[ showBooked, l10n ]
	);

	const visibleItems = useMemo(
		() => legendItems.filter( ( item ) => item.show ),
		[ legendItems ]
	);

	return (
		<div className="clisyc-calendar-legend">
			<span className="clisyc-legend-title">
				{ l10n.legendTitle || 'Legend:' }
			</span>
			<div className="clisyc-legend-items">
				{ visibleItems.map( ( item ) => (
					<div key={ item.key } className="clisyc-legend-item">
						{ ( () => {
							if ( item.type === 'line' ) {
								// Current time indicator (red horizontal line)
								return (
									<span
										className="clisyc-legend-indicator clisyc-legend-line"
										style={ {
											backgroundColor: item.bgVar,
										} }
										aria-hidden="true"
									/>
								);
							}
							if ( item.type === 'highlight' ) {
								// Today highlight (background tint on day column)
								return (
									<span
										className="clisyc-legend-indicator clisyc-legend-highlight"
										style={ {
											backgroundColor: item.bgVar,
											borderColor: item.textVar,
										} }
										aria-hidden="true"
									/>
								);
							}
							// Regular slot color (matches calendar event appearance)
							return (
								<span
									className="clisyc-legend-indicator clisyc-legend-slot"
									style={ {
										backgroundColor: item.bgVar,
										borderColor: item.bgVar,
										color: item.textVar,
									} }
									aria-hidden="true"
								/>
							);
						} )() }
						<span className="clisyc-legend-label">
							{ item.label }
						</span>
					</div>
				) ) }
			</div>
		</div>
	);
} );

CalendarLegend.displayName = 'CalendarLegend';

export default CalendarLegend;
