/**
 * File: src/assets/src/booking-form/DateRangeCalendar.js
 * Calendar component for date-range bookings (rentals, accommodations, etc.)
 *
 * Features:
 * - Visual calendar showing booked/available dates
 * - Date range selection (check-in to check-out)
 * - Min/max stay validation
 * - Buffer day support
 * - Real-time availability checking
 */
import React, { useRef, useEffect, useState, useCallback } from 'react';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import apiFetch from '@wordpress/api-fetch';

const DateRangeCalendar = ( {
	dimensionId,
	dimensionName,
	restNonce,
	onDateRangeSelect,
	minStay = 1,
	maxStay = 365,
	bufferDays = 0,
	pricePerNight = 0,
	currencySymbol = '$',
} ) => {
	const calendarRef = useRef( null );
	const calendarInstanceRef = useRef( null );
	const [ bookedDates, setBookedDates ] = useState( [] );
	const [ selectedRange, setSelectedRange ] = useState( {
		start: null,
		end: null,
		nights: 0,
	} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ currentViewRange, setCurrentViewRange ] = useState( {
		start: null,
		end: null,
	} );

	// Fetch booked dates for the current view range
	const fetchBookedDates = useCallback(
		async ( start, end ) => {
			if ( ! dimensionId ) {
				setIsLoading( false );
				return;
			}

			setIsLoading( true );
			setError( null );

			try {
				const params = new URLSearchParams( {
					start: start.toISOString(),
					end: end.toISOString(),
					dimension_id: dimensionId,
					_wpnonce: restNonce,
				} );

				const response = await apiFetch( {
					path: `/clisyc/v1/slots/booked-dates?${ params.toString() }`,
				} );

				setBookedDates( response || [] );
			} catch ( err ) {
				console.error( 'Failed to fetch booked dates:', err );
				setError(
					'Failed to load availability. Please refresh the page.'
				);
			} finally {
				setIsLoading( false );
			}
		},
		[ dimensionId, restNonce ]
	);

	// Initial fetch - 6 months of data
	useEffect( () => {
		const start = new Date();
		start.setHours( 0, 0, 0, 0 );
		const end = new Date();
		end.setMonth( end.getMonth() + 6 );

		setCurrentViewRange( { start, end } );
		fetchBookedDates( start, end );
	}, [ dimensionId, fetchBookedDates ] );

	// Check if a specific date is booked
	const isDateBooked = useCallback(
		( dateStr ) => {
			return bookedDates.includes( dateStr );
		},
		[ bookedDates ]
	);

	// Check if any date in a range is booked
	const isRangeBooked = useCallback(
		( startStr, endStr ) => {
			const start = new Date( startStr );
			const end = new Date( endStr );

			// For date range bookings, end date is checkout - don't check it
			for (
				let d = new Date( start );
				d < end;
				d.setDate( d.getDate() + 1 )
			) {
				const dateStr = d.toISOString().split( 'T' )[ 0 ];
				if ( isDateBooked( dateStr ) ) {
					return true;
				}
			}
			return false;
		},
		[ isDateBooked ]
	);

	// Calculate total price
	const calculateTotal = useCallback(
		( nights ) => {
			if ( ! pricePerNight || pricePerNight <= 0 ) {
				return null;
			}
			return ( pricePerNight * nights ).toFixed( 2 );
		},
		[ pricePerNight ]
	);

	// Format date for display
	const formatDate = ( dateStr ) => {
		const date = new Date( dateStr + 'T00:00:00' );
		return date.toLocaleDateString( undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			year: 'numeric',
		} );
	};

	// Initialize FullCalendar
	useEffect( () => {
		if ( ! calendarRef.current || calendarInstanceRef.current ) {
			return;
		}

		const today = new Date();
		today.setHours( 0, 0, 0, 0 );

		const calendar = new Calendar( calendarRef.current, {
			plugins: [ dayGridPlugin, interactionPlugin ],
			initialView: 'dayGridMonth',
			headerToolbar: {
				left: 'prev,next today',
				center: 'title',
				right: '',
			},
			height: 'auto',
			fixedWeekCount: false,
			showNonCurrentDates: true,

			// Selection settings
			selectable: true,
			selectMirror: true,
			unselectAuto: false,
			selectOverlap: false,

			// Can't select past dates
			validRange: {
				start: today,
			},

			// Handle date range selection
			select: ( info ) => {
				const startDate = info.startStr;
				const endDate = info.endStr;

				// Check for conflicts
				if ( isRangeBooked( startDate, endDate ) ) {
					calendar.unselect();
					setError(
						'Some dates in your selection are unavailable. Please choose different dates.'
					);
					setTimeout( () => setError( null ), 4000 );
					return;
				}

				// Calculate nights (FullCalendar end date is exclusive, which is correct for checkout)
				const nights = Math.ceil(
					( new Date( endDate ) - new Date( startDate ) ) /
						( 1000 * 60 * 60 * 24 )
				);

				// Validate min stay
				if ( nights < minStay ) {
					calendar.unselect();
					setError(
						`Minimum stay is ${ minStay } night${
							minStay !== 1 ? 's' : ''
						}.`
					);
					setTimeout( () => setError( null ), 4000 );
					return;
				}

				// Validate max stay
				if ( nights > maxStay ) {
					calendar.unselect();
					setError(
						`Maximum stay is ${ maxStay } night${
							maxStay !== 1 ? 's' : ''
						}.`
					);
					setTimeout( () => setError( null ), 4000 );
					return;
				}

				setError( null );
				const rangeData = {
					start: startDate,
					end: endDate,
					nights,
					total: calculateTotal( nights ),
				};

				setSelectedRange( rangeData );
				onDateRangeSelect?.( rangeData );
			},

			// Prevent selecting booked dates
			selectAllow: ( selectInfo ) => {
				// Don't allow selection starting on a booked date
				const startStr = selectInfo.startStr;
				if ( isDateBooked( startStr ) ) {
					return false;
				}
				return true;
			},

			// Custom day cell rendering
			dayCellDidMount: ( info ) => {
				const dateStr = info.date.toISOString().split( 'T' )[ 0 ];
				const isPast = info.date < today;

				if ( isPast ) {
					info.el.classList.add( 'clisyc-date-past' );
				} else if ( isDateBooked( dateStr ) ) {
					info.el.classList.add( 'clisyc-date-unavailable' );

					// Add visual indicator
					const indicator = document.createElement( 'div' );
					indicator.className = 'clisyc-booked-indicator';
					indicator.innerHTML = '×';
					info.el
						.querySelector( '.fc-daygrid-day-frame' )
						?.appendChild( indicator );
				} else {
					info.el.classList.add( 'clisyc-date-available' );
				}
			},

			// Handle view changes to fetch more data if needed
			datesSet: ( info ) => {
				const viewEnd = info.end;

				// If viewing beyond our current data range, fetch more
				if ( currentViewRange.end && viewEnd > currentViewRange.end ) {
					const newEnd = new Date( viewEnd );
					newEnd.setMonth( newEnd.getMonth() + 3 ); // Fetch 3 more months

					setCurrentViewRange( ( prev ) => ( {
						...prev,
						end: newEnd,
					} ) );
					fetchBookedDates( currentViewRange.start, newEnd );
				}
			},
		} );

		calendar.render();
		calendarInstanceRef.current = calendar;

		return () => {
			if ( calendarInstanceRef.current ) {
				calendarInstanceRef.current.destroy();
				calendarInstanceRef.current = null;
			}
		};
	}, [
		bookedDates,
		minStay,
		maxStay,
		isRangeBooked,
		isDateBooked,
		calculateTotal,
		onDateRangeSelect,
	] );

	// Update calendar when booked dates change
	useEffect( () => {
		if ( calendarInstanceRef.current ) {
			calendarInstanceRef.current.render();
		}
	}, [ bookedDates ] );

	// Clear selection handler
	const handleClearSelection = () => {
		if ( calendarInstanceRef.current ) {
			calendarInstanceRef.current.unselect();
		}
		setSelectedRange( { start: null, end: null, nights: 0 } );
		onDateRangeSelect?.( null );
	};

	return (
		<div className="clisyc-date-range-calendar">
			{ /* Header with item name */ }
			{ dimensionName && (
				<div className="clisyc-date-range-header">
					<h3 className="clisyc-date-range-title">
						Select dates for: <strong>{ dimensionName }</strong>
					</h3>
					{ pricePerNight > 0 && (
						<div className="clisyc-price-per-night">
							{ currencySymbol }
							{ pricePerNight.toFixed( 2 ) } / night
						</div>
					) }
				</div>
			) }

			{ /* Legend */ }
			<div className="clisyc-calendar-legend">
				<span className="clisyc-legend-item">
					<span className="clisyc-legend-dot clisyc-legend-available"></span>
					Available
				</span>
				<span className="clisyc-legend-item">
					<span className="clisyc-legend-dot clisyc-legend-booked"></span>
					Booked
				</span>
				<span className="clisyc-legend-item">
					<span className="clisyc-legend-dot clisyc-legend-selected"></span>
					Your Selection
				</span>
			</div>

			{ /* Instructions */ }
			<div className="clisyc-date-range-instructions">
				<span className="dashicons dashicons-info"></span>
				Click and drag to select your check-in and check-out dates
				{ minStay > 1 && (
					<span className="clisyc-min-stay-note">
						{ ' ' }
						(min. { minStay } nights)
					</span>
				) }
			</div>

			{ /* Error message */ }
			{ error && (
				<div className="clisyc-date-range-error">
					<span className="dashicons dashicons-warning"></span>
					{ error }
				</div>
			) }

			{ /* Loading overlay */ }
			{ isLoading && (
				<div className="clisyc-loading-overlay">
					<span className="clisyc-spinner"></span>
					<span>Loading availability...</span>
				</div>
			) }

			{ /* Calendar */ }
			<div className="clisyc-calendar-container">
				<div ref={ calendarRef }></div>
			</div>

			{ /* Selected range summary */ }
			{ selectedRange.start && selectedRange.end && (
				<div className="clisyc-selected-range-summary">
					<div className="clisyc-range-details">
						<div className="clisyc-range-dates">
							<div className="clisyc-range-date">
								<span className="clisyc-range-label">
									Check-in
								</span>
								<span className="clisyc-range-value">
									{ formatDate( selectedRange.start ) }
								</span>
							</div>
							<div className="clisyc-range-arrow">→</div>
							<div className="clisyc-range-date">
								<span className="clisyc-range-label">
									Check-out
								</span>
								<span className="clisyc-range-value">
									{ formatDate( selectedRange.end ) }
								</span>
							</div>
						</div>
						<div className="clisyc-range-nights">
							<strong>{ selectedRange.nights }</strong> night
							{ selectedRange.nights !== 1 ? 's' : '' }
						</div>
						{ selectedRange.total && (
							<div className="clisyc-range-total">
								Total:{ ' ' }
								<strong>
									{ currencySymbol }
									{ selectedRange.total }
								</strong>
							</div>
						) }
					</div>
					<button
						type="button"
						className="clisyc-clear-selection-btn"
						onClick={ handleClearSelection }
					>
						<span className="dashicons dashicons-no-alt"></span>
						Clear
					</button>
				</div>
			) }
		</div>
	);
};

export default DateRangeCalendar;
