/**
 * File: src/assets/src/booking-form/CalendarNotice.js
 * Displays contextual notices to admins about calendar configuration issues.
 *
 * This component checks if there are available slots that fall outside
 * the visible calendar hours and warns administrators.
 *
 * @package
 */
import React, { useState, useEffect, useRef } from 'react';
import apiFetch from '@wordpress/api-fetch';

const CalendarNotice = ( {
	selectedFilters,
	restNonce,
	calendarStartTime = '08:00',
	calendarEndTime = '18:00',
} ) => {
	const [ notice, setNotice ] = useState( null );
	const [ dismissed, setDismissed ] = useState( false );
	const activeRequestRef = useRef( 0 );

	const { isAdmin = false, l10n = {} } = window.clisycBookingFormData || {};

	// Hooks must be called unconditionally (Rules of Hooks).
	// Use the isAdmin flag inside the effect and render instead.
	useEffect( () => {
		if ( ! isAdmin ) {
			return;
		}

		// Reset dismissed state when filters change
		setDismissed( false );

		// Race condition guard — discard stale responses.
		const requestId = ++activeRequestRef.current;
		checkForOutOfRangeSlots( requestId );
	}, [ selectedFilters ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const checkForOutOfRangeSlots = async ( requestId ) => {
		try {
			// Get a wider date range to check
			const now = new Date();
			const futureDate = new Date();
			futureDate.setDate( now.getDate() + 30 );

			const queryParams = new URLSearchParams( {
				start: now.toISOString(),
				end: futureDate.toISOString(),
				_wpnonce: restNonce,
				hide_booked: 'true',
				check_hours_only: 'true', // Custom param for this check
			} );

			Object.entries( selectedFilters ).forEach( ( [ key, value ] ) => {
				if ( Array.isArray( value ) ) {
					value.forEach( ( v ) =>
						queryParams.append( `${ key }[]`, v )
					);
				} else if ( value ) {
					queryParams.append( key, value );
				}
			} );

			const response = await apiFetch( {
				path: `/clisyc/v1/slots?${ queryParams.toString() }`,
			} );

			// Discard stale response if filters changed while in-flight.
			if ( requestId !== activeRequestRef.current ) {
				return;
			}

			const events = response.events || [];

			// Parse calendar hours
			const [ startHour, startMin ] = calendarStartTime
				.split( ':' )
				.map( Number );
			const [ endHour, endMin ] = calendarEndTime
				.split( ':' )
				.map( Number );
			const calStartMinutes = startHour * 60 + startMin;
			const calEndMinutes = endHour * 60 + endMin;

			// Check each available slot
			let slotsOutsideRange = 0;
			let totalAvailableSlots = 0;

			events.forEach( ( event ) => {
				const props = event.extendedProps || {};

				// Only check available (non-booked, non-blocked) slots
				if ( ! props.isBooked && ! props.is_block ) {
					totalAvailableSlots++;

					// Convert to local time and check hours
					const eventDate = new Date( event.start );
					const eventHours = eventDate.getHours();
					const eventMinutes = eventDate.getMinutes();
					const eventTotalMinutes = eventHours * 60 + eventMinutes;

					if (
						eventTotalMinutes < calStartMinutes ||
						eventTotalMinutes >= calEndMinutes
					) {
						slotsOutsideRange++;
					}
				}
			} );

			// Show notice if ALL available slots are outside visible range
			if (
				slotsOutsideRange > 0 &&
				slotsOutsideRange === totalAvailableSlots
			) {
				setNotice( {
					type: 'warning',
					title:
						l10n.noticeOutOfRangeTitle ||
						'Slots Outside Calendar Hours',
					message:
						l10n.noticeOutOfRangeMessage ||
						`All ${ slotsOutsideRange } available slot(s) fall outside your visible calendar hours (${ calendarStartTime } - ${ calendarEndTime }). ` +
							`Visitors won't see these slots. Adjust your calendar hours in Settings → Calendars, or regenerate schedules within business hours.`,
				} );
			} else if ( slotsOutsideRange > 0 ) {
				setNotice( {
					type: 'info',
					title:
						l10n.noticeSomeOutOfRangeTitle ||
						'Some Slots Outside Calendar Hours',
					message:
						l10n.noticeSomeOutOfRangeMessage ||
						`${ slotsOutsideRange } of ${ totalAvailableSlots } available slot(s) fall outside your visible calendar hours (${ calendarStartTime } - ${ calendarEndTime }). ` +
							`These slots won't be visible to visitors.`,
				} );
			} else {
				setNotice( null );
			}
		} catch ( error ) {
			console.error( '[CalendarNotice] Error checking slots:', error );
			setNotice( null );
		}
	};

	if ( ! isAdmin || ! notice || dismissed ) {
		return null;
	}

	return (
		<div
			className={ `clisyc-calendar-notice clisyc-notice-${ notice.type } clisyc-admin-notice` }
		>
			<span className="clisyc-notice-icon" aria-hidden="true">
				{ notice.type === 'warning' ? '⚠️' : 'ℹ️' }
			</span>
			<div className="clisyc-notice-content">
				<div className="clisyc-notice-title">
					{ notice.title }
					<span className="clisyc-notice-badge">Admin Only</span>
				</div>
				<div className="clisyc-notice-message">{ notice.message }</div>
			</div>
			<button
				type="button"
				className="clisyc-notice-dismiss"
				onClick={ () => setDismissed( true ) }
				aria-label={ l10n.dismissNotice || 'Dismiss notice' }
			>
				✕
			</button>
		</div>
	);
};

export default CalendarNotice;
