/**
 * File: src/assets/src/booking-form/CalendarView.js
 * Calendar component for displaying available time slots.
 *
 * MODERNIZED:
 * - Removed numbered step headers
 * - Click anywhere on available slot opens booking modal
 * - Visual selected state for clicked slots
 *
 * UPDATED:
 * - Added showBooked prop to toggle display of booked slots
 *
 * FIXED (2025-12-23):
 * - Smart Start Date now properly navigates calendar to next available date
 * - showBookedDefault now correctly shows booked slots on initial page load
 *
 * FIXED (2026-01-01):
 * - Added onFirstAvailableDate callback to report verified first available date
 * - This ensures the "Heads Up" modal shows accurate information based on actual API data
 *
 * FIXED (2026-01-07):
 * - "No Availability" modal now only shows when there are truly NO slots in the generation lookahead period
 * - Previously showed the modal when current week had no slots, even if future weeks did
 * - Now uses the Generation Lookahead setting (clisyc_auto_generate_lookahead) for search range
 * - If current view has no slots, does a broader search before declaring "no availability"
 *
 * ADDED (2026-01-10):
 * - Event tooltip on hover in week/day views
 * - onEventMouseEnter/onEventMouseLeave callbacks for App.js tooltip component
 */
import React, { useRef, useEffect, useState } from 'react';
import { Calendar } from '@fullcalendar/core';
import timeGridPlugin from '@fullcalendar/timegrid';
import dayGridPlugin from '@fullcalendar/daygrid';
import listPlugin from '@fullcalendar/list';
import apiFetch from '@wordpress/api-fetch';

const CalendarView = ( {
	selectedFilters,
	restNonce,
	onSlotSelect,
	timezone,
	selectedSlotId,
	showBooked = false, // Whether to show booked slots
	onFirstAvailableDate, // Callback to report verified first available date
	onEventMouseEnter, // Callback for event hover tooltip
	onEventMouseLeave, // Callback for event hover tooltip
	onDateChange, // NEW: Callback when main calendar date changes
	setNavigateCallback, // NEW: Callback to expose navigation function to parent
} ) => {
	const calendarRef = useRef( null );
	const calendarInstanceRef = useRef( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ , setLoadedSlotCount ] = useState( 0 );

	// Track whether we've already reported the first available date
	// This prevents reporting on every refetch (e.g., when filters change)
	const hasReportedFirstAvailableRef = useRef( false );

	// Track whether the deferred layout fix has been applied
	// Used to prevent multiple fix attempts
	const layoutFixAppliedRef = useRef( false );

	// NEW: Use refs for values needed in the events callback
	// This fixes the closure issue where the callback captures stale values
	const showBookedRef = useRef( showBooked );
	const selectedFiltersRef = useRef( selectedFilters );

	const {
		calendarOptions = {},
		filterOrder = [],
		nextAvailableDate,
		generationLookahead = 30, // Default to 30 days if not set
	} = window.clisycBookingFormData || {};

	const {
		startOfWeek = 0,
		enabledViews = [
			'dayGridMonth',
			'timeGridWeek',
			'timeGridDay',
			'listWeek',
		],
		initialView = 'timeGridWeek',
		showOverviewAvailability,
		...nativeCalendarOptions
	} = calendarOptions;

	// Keep refs updated when props change
	useEffect( () => {
		showBookedRef.current = showBooked;
	}, [ showBooked ] );

	useEffect( () => {
		selectedFiltersRef.current = selectedFilters;
	}, [ selectedFilters ] );

	// Refetch events when filters change
	useEffect( () => {
		if ( calendarInstanceRef.current ) {
			calendarInstanceRef.current.refetchEvents();
		}
	}, [ selectedFilters ] );

	// NEW: Refetch events when showBooked changes
	useEffect( () => {
		if ( calendarInstanceRef.current ) {
			calendarInstanceRef.current.refetchEvents();
		}
	}, [ showBooked ] );

	// Update timezone
	useEffect( () => {
		if ( calendarInstanceRef.current ) {
			calendarInstanceRef.current.setOption( 'timeZone', timezone );
		}
	}, [ timezone ] );

	// Update selected slot visual state
	useEffect( () => {
		if ( calendarInstanceRef.current ) {
			// Remove selected class from all events
			document
				.querySelectorAll( '.fc-event.clisyc-slot-selected' )
				.forEach( ( el ) => {
					el.classList.remove( 'clisyc-slot-selected' );
				} );

			// Add selected class to the selected slot
			if ( selectedSlotId ) {
				document.querySelectorAll( '.fc-event' ).forEach( ( el ) => {
					const event =
						calendarInstanceRef.current.getEventById(
							selectedSlotId
						);
					if (
						event &&
						el.getAttribute( 'data-slot-id' ) === selectedSlotId
					) {
						el.classList.add( 'clisyc-slot-selected' );
					}
				} );
			}
		}
	}, [ selectedSlotId ] );

	/**
	 * FIXED (2026-01-07): Do a broader search before declaring "no availability"
	 *
	 * This function searches ahead using the Generation Lookahead setting
	 * (how many days in advance slots are generated) to find available slots.
	 * Only reports "no availability" if there are truly ZERO slots in that range.
	 *
	 * @param {Object} currentFilters - Current filter selections
	 * @return {Promise<Date|null>} - First available date, or null if none
	 */
	const findFirstAvailableInRange = async ( currentFilters ) => {
		const queryParams = new URLSearchParams();
		queryParams.append( '_wpnonce', restNonce );
		queryParams.append( 'hide_booked', 'true' );
		queryParams.append( 'check_hours_only', 'true' ); // Faster query for availability check

		// Use the Generation Lookahead setting (from PHP) for search range
		// This matches how far into the future slots are actually generated
		const lookaheadDays = parseInt( generationLookahead, 10 ) || 30;

		const startDate = new Date();
		const endDate = new Date();
		endDate.setDate( endDate.getDate() + lookaheadDays );

		queryParams.append( 'start', startDate.toISOString() );
		queryParams.append( 'end', endDate.toISOString() );

		// Add current filter selections
		Object.entries( currentFilters ).forEach( ( [ key, value ] ) => {
			if ( Array.isArray( value ) ) {
				value.forEach( ( v ) => queryParams.append( `${ key }[]`, v ) );
			} else if ( value ) {
				queryParams.append( key, value );
			}
		} );

		try {
			const data = await apiFetch( {
				path: `/clisyc/v1/slots?${ queryParams.toString() }`,
			} );
			const rawEvents = data.events || [];

			// Filter to only available (bookable) slots
			const availableEvents = rawEvents.filter( ( event ) => {
				const props = event.extendedProps || {};
				return props.isBookable && ! props.is_block && ! props.isBooked;
			} );

			if ( availableEvents.length === 0 ) {
				return null; // Truly no availability
			}

			// Sort by start time to find the earliest
			availableEvents.sort(
				( a, b ) => new Date( a.start ) - new Date( b.start )
			);
			return new Date( availableEvents[ 0 ].start );
		} catch ( error ) {
			console.error( 'Error checking availability range:', error );
			return null;
		}
	};

	/**
	 * Find and report the first available date.
	 *
	 * FIXED (2026-01-07): Now does a broader search using the Generation Lookahead
	 * setting before reporting "no availability". Previously, it would show
	 * "No Availability" if the current week view had no slots, even if future
	 * weeks did have availability.
	 *
	 * Reports:
	 * - A Date object if available slots exist (triggers "jumped ahead" modal)
	 * - null if NO available slots exist in the lookahead period (triggers "no availability" modal)
	 *
	 * @param {Array} viewEvents - Events from the current calendar view
	 */
	const reportFirstAvailableDate = async ( viewEvents ) => {
		// Only report once (on initial load)
		if ( hasReportedFirstAvailableRef.current || ! onFirstAvailableDate ) {
			return;
		}

		// Mark as reported immediately to prevent duplicate calls
		hasReportedFirstAvailableRef.current = true;

		// Filter to only available (bookable) slots in current view
		const availableInView = viewEvents.filter( ( event ) => {
			const props = event.extendedProps || {};
			return props.isBookable && ! props.is_block && ! props.isBooked;
		} );

		// If current view has available slots, use those
		if ( availableInView.length > 0 ) {
			availableInView.sort(
				( a, b ) => new Date( a.start ) - new Date( b.start )
			);
			const firstDate = new Date( availableInView[ 0 ].start );
			onFirstAvailableDate( firstDate );
			return;
		}

		// =====================================================================
		// FIX: Current view has no slots - do a broader search before giving up
		// =====================================================================
		// This prevents showing "No Availability" when the current week is full
		// but future weeks have open slots.

		const firstAvailable = await findFirstAvailableInRange(
			selectedFiltersRef.current
		);

		if ( firstAvailable ) {
			// Found slots in the future - report that date (triggers "jumped ahead" modal)
			onFirstAvailableDate( firstAvailable );

			// Also navigate the calendar to that date
			if ( calendarInstanceRef.current ) {
				calendarInstanceRef.current.gotoDate( firstAvailable );
			}
		} else {
			// Truly no availability in the generation lookahead period
			onFirstAvailableDate( null );
		}
	};

	// Initialize calendar
	useEffect( () => {
		if ( calendarRef.current && ! calendarInstanceRef.current ) {
			const isMobile = window.innerWidth < 768;
			const rightToolbar = enabledViews.join( ',' );

			// Extract our custom options (not valid FullCalendar options)
			const {
				slotHeightPerHour, // Our custom option - NOT a FullCalendar option
				slotDuration: customSlotDuration, // We handle this separately
				...fcOptions // Only valid FullCalendar options
			} = nativeCalendarOptions;

			const calendar = new Calendar( calendarRef.current, {
				...fcOptions, // Only pass valid FullCalendar options
				plugins: [ timeGridPlugin, dayGridPlugin, listPlugin ],
				firstDay: startOfWeek,
				initialDate: nextAvailableDate || null,
				timeZone: timezone,

				// Pass slotDuration to FullCalendar (this IS a valid FC option)
				slotDuration: customSlotDuration || '00:30:00',

				headerToolbar: {
					left: 'prev,next today',
					center: 'title',
					right: isMobile ? 'timeGridDay,listWeek' : rightToolbar,
				},
				initialView: isMobile
					? 'timeGridDay'
					: initialView || 'timeGridWeek',
				height: 'auto',
				nowIndicator: true,
				editable: false,
				selectable: false,
				eventTimeFormat: {
					hour: isMobile ? 'numeric' : '2-digit',
					minute: '2-digit',
					meridiem: 'short',
				},
				slotLabelFormat: {
					hour: isMobile ? 'numeric' : '2-digit',
					minute: '2-digit',
					meridiem: 'short',
				},

				// Event fetching
				events: ( fetchInfo, successCallback, failureCallback ) => {
					setIsLoading( true );

					const queryParams = new URLSearchParams();
					queryParams.append( 'start', fetchInfo.startStr );
					queryParams.append( 'end', fetchInfo.endStr );
					queryParams.append( '_wpnonce', restNonce );

					// Only fetch booked slots if toggle is on
					const currentShowBooked = showBookedRef.current;
					if ( ! currentShowBooked ) {
						queryParams.append( 'hide_booked', 'true' );
					}

					// Add filter parameters from ref (always current)
					const currentFilters = selectedFiltersRef.current;
					Object.entries( currentFilters ).forEach(
						( [ key, value ] ) => {
							if ( Array.isArray( value ) ) {
								value.forEach( ( v ) =>
									queryParams.append( `${ key }[]`, v )
								);
							} else if ( value ) {
								queryParams.append( key, value );
							}
						}
					);

					apiFetch( {
						path: `/clisyc/v1/slots?${ queryParams.toString() }`,
					} )
						.then( ( data ) => {
							const rawEvents = data.events || [];

							// Count available (bookable) slots
							const availableCount = rawEvents.filter(
								( e ) => e.extendedProps?.status === 'available'
							).length;
							setLoadedSlotCount( availableCount );

							// NEW: Report the verified first available date
							// This is now async and does a broader search if needed
							reportFirstAvailableDate( rawEvents );

							successCallback( rawEvents );
							setIsLoading( false );
						} )
						.catch( ( error ) => {
							console.error(
								'Error fetching calendar events:',
								error
							);
							failureCallback( error );
							setLoadedSlotCount( 0 );
							setIsLoading( false );
						} );
				},

				// Event rendering
				eventContent: ( arg ) => {
					const props = arg.event.extendedProps || {};
					const details = props.serviceDetails || {};
					const view = arg.view.type;
					const isTimeGridView = view.includes( 'timeGrid' );

					// HTML escape helper to prevent XSS from API data.
					const esc = ( str ) => {
						if ( typeof str !== 'string' ) {
							return '';
						}
						const d = document.createElement( 'div' );
						d.textContent = str;
						return d.innerHTML;
					};
					// CSS color validator
					const safeColor = ( c ) =>
						c &&
						/^(#[0-9a-f]{3,8}|rgb|hsl|transparent|[a-z]+)/i.test(
							c
						)
							? c
							: 'transparent';

					const timeHtml = `<div class="fc-event-time">${ esc(
						arg.timeText
					) }</div>`;
					let detailsHtml = '';

					if ( isTimeGridView ) {
						const compactDetails = [];
						filterOrder.forEach( ( slug ) => {
							const jsKey = slug.replace( 'clisyc_', '' );
							const detailLabel = details[ `${ jsKey }_name` ];
							const detailColor = details[ `${ jsKey }_color` ];
							if ( detailLabel ) {
								const dotHtml = detailColor
									? `<span class="clisyc-event-color-dot" style="background-color: ${ safeColor(
											detailColor
									  ) };"></span>`
									: '';
								compactDetails.push(
									`<span class="clisyc-event-compact-item">${ dotHtml }<span class="clisyc-event-detail-label">${ esc(
										detailLabel
									) }</span></span>`
								);
							}
						} );
						if ( compactDetails.length > 0 ) {
							detailsHtml = `<div class="clisyc-event-details-compact">${ compactDetails.join(
								''
							) }</div>`;
						}
					} else {
						const fullDetails = [];
						filterOrder.forEach( ( slug ) => {
							const jsKey = slug.replace( 'clisyc_', '' );
							const detailLabel = details[ `${ jsKey }_name` ];
							const detailColor = details[ `${ jsKey }_color` ];
							if ( detailLabel ) {
								const dotHtml = detailColor
									? `<span class="clisyc-event-color-dot" style="background-color: ${ safeColor(
											detailColor
									  ) };"></span>`
									: '';
								fullDetails.push(
									`<div class="clisyc-event-detail-item">${ dotHtml }<span class="clisyc-event-detail-label">${ esc(
										detailLabel
									) }</span></div>`
								);
							}
						} );
						if ( fullDetails.length > 0 ) {
							detailsHtml = `<div class="clisyc-event-details-stack">${ fullDetails.join(
								''
							) }</div>`;
						}
					}

					// Urgency badge
					let urgencyHtml = '';
					const spotsLeft = parseInt( props.spotsLeft, 10 );
					const capacity = parseInt( props.capacity, 10 ) || 1;
					if (
						capacity > 1 &&
						spotsLeft > 0 &&
						spotsLeft <= Math.max( 2, Math.ceil( capacity * 0.25 ) )
					) {
						urgencyHtml = `<span class="clisyc-urgency-badge">Only ${ esc(
							String( spotsLeft )
						) } left</span>`;
					}

					return {
						html: `
                            <div class="fc-event-main-frame" data-slot-id="${ esc(
								props.slotIdentifier || ''
							) }">
                                ${ timeHtml }
                                ${ detailsHtml }
                                ${ urgencyHtml }
                            </div>
                        `,
					};
				},

				// Event click handler - opens booking modal
				eventClick: ( info ) => {
					info.jsEvent.preventDefault();
					info.jsEvent.stopPropagation();

					const props = info.event.extendedProps || {};

					// Only allow booking available slots
					if ( props.isBookable ) {
						onSlotSelect( info.event );
					}
				},

				// Apply CSS classes to events
				eventDidMount: ( info ) => {
					const props = info.event.extendedProps || {};
					const el = info.el;

					// Add data attribute for slot identification
					el.setAttribute(
						'data-slot-id',
						props.slotIdentifier || ''
					);

					// Calculate event duration in minutes
					const eventDurationMinutes =
						props.durationMinutes ||
						( info.event.end && info.event.start
							? Math.round(
									( info.event.end - info.event.start ) /
										60000
							  )
							: 30 );
					el.setAttribute(
						'data-duration',
						eventDurationMinutes.toString()
					);

					// Set service color as CSS variable for hover animation
					// This allows the climbing bar animation to use the service's custom color
					const details = props.serviceDetails || {};
					const serviceColor = details.service_color || '#3b82f6';
					el.style.setProperty(
						'--event-service-color',
						serviceColor
					);

					// CRITICAL: Manually fix event height in timeGrid views
					// FullCalendar calculates positions based on its internal height, not CSS
					// We override the harness element's positioning to match our slot heights
					if (
						info.view.type.includes( 'timeGrid' ) &&
						info.event.start &&
						info.event.end
					) {
						const harness = el.parentElement;
						if (
							harness &&
							harness.classList.contains(
								'fc-timegrid-event-harness'
							)
						) {
							// Get the ACTUAL slot height from the DOM
							// This ensures we match exactly what's rendered
							const slotEl =
								document.querySelector( '.fc-timegrid-slot' );
							if ( ! slotEl ) {
								return;
							}

							const actualSlotHeight =
								slotEl.getBoundingClientRect().height;

							// SAFETY CHECK: If slot height is too small, CSS hasn't loaded yet.
							// Skip manual positioning - the deferred layout fix will handle it.
							if ( actualSlotHeight < 5 ) {
								return;
							}

							// Get slot duration from calendar options
							const slotDurationStr =
								customSlotDuration || '00:30:00';
							const slotParts = slotDurationStr.split( ':' );
							const slotDurationMinutes =
								parseInt( slotParts[ 0 ] ) * 60 +
								parseInt( slotParts[ 1 ] );

							// Calculate actual pixels per minute from rendered slot
							const actualPixelsPerMinute =
								actualSlotHeight / slotDurationMinutes;

							// Get the slot min time (start of day column)
							const slotMinStr = fcOptions.slotMinTime || '08:00';
							const [ minHour, minMinute ] = slotMinStr
								.split( ':' )
								.map( Number );
							const slotMinTotalMinutes =
								minHour * 60 + ( minMinute || 0 );

							// Calculate event start as minutes from midnight in the event's local day
							const eventStart = info.event.start;
							const eventStartMinutes =
								eventStart.getHours() * 60 +
								eventStart.getMinutes();

							// Minutes from the top of the time grid
							const minutesFromTop =
								eventStartMinutes - slotMinTotalMinutes;

							// Calculate pixel position and height using ACTUAL rendered values
							const topPx =
								minutesFromTop * actualPixelsPerMinute;
							const heightPx =
								eventDurationMinutes * actualPixelsPerMinute;

							// CRITICAL FIX: FullCalendar uses 'inset' CSS property for positioning
							// The inset shorthand overrides individual top/right/bottom/left properties
							// We must completely clear all inline styles and set fresh values

							// Get current left/right values to preserve column positioning (for overlapping events)
							const computedStyle = getComputedStyle( harness );
							const currentLeft = computedStyle.left;
							const currentRight = computedStyle.right;

							// Clear ALL inline styles first, then set fresh positioning values
							harness.setAttribute( 'style', '' );
							harness.style.position = 'absolute';
							harness.style.top = topPx + 'px';
							harness.style.height = heightPx + 'px';
							harness.style.left = currentLeft;
							harness.style.right = currentRight;
							harness.style.bottom = 'auto';
							harness.style.zIndex = '1';

							// Make the event fill the harness
							el.style.position = 'absolute';
							el.style.top = '0';
							el.style.bottom = '0';
							el.style.left = '0';
							el.style.right = '0';
							el.style.height = 'auto';
						}
					}

					// Add appropriate CSS class based on booking status
					if ( props.isBooked ) {
						// NEW: Style for booked events
						el.classList.add( 'clisyc-booked-slot' );
					} else if ( props.isBookable ) {
						el.classList.add( 'clisyc-available-slot' );

						// Mark as selected if it matches
						if (
							selectedSlotId &&
							props.slotIdentifier === selectedSlotId
						) {
							el.classList.add( 'clisyc-slot-selected' );
						}
					} else {
						el.classList.add( 'clisyc-unavailable-slot' );
					}

					// Add compact class for time grid views
					if ( info.view.type.includes( 'timeGrid' ) ) {
						el.classList.add( 'fc-event-compact' );
					}
				},

				// Event hover handlers for tooltip (only in timeGrid views)
				eventMouseEnter: ( info ) => {
					// Only show tooltip in week/day views, not month/list
					if ( ! info.view.type.includes( 'timeGrid' ) ) {
						return;
					}
					if ( ! onEventMouseEnter ) {
						return;
					}

					const props = info.event.extendedProps || {};
					const details = props.serviceDetails || {};

					// Build tooltip info object matching what App.js expects
					const tooltipInfo = {
						event: {
							title: info.event.title,
							start: info.event.start,
							end: info.event.end,
							extendedProps: {
								serviceName:
									details.service_name || info.event.title,
								serviceColor:
									details.service_color || '#1a73e8',
								practitionerName:
									details.practitioner_name || '',
								practitionerColor:
									details.practitioner_color || '#4285f4',
								roomName: details.room_name || '',
								roomColor: details.room_color || '#34a853',
							},
						},
						el: info.el,
					};

					onEventMouseEnter( tooltipInfo );
				},

				eventMouseLeave: ( info ) => {
					if ( ! info.view.type.includes( 'timeGrid' ) ) {
						return;
					}
					if ( onEventMouseLeave ) {
						onEventMouseLeave( info );
					}
				},

				// Report date changes to parent for mini calendar sync
				datesSet: ( info ) => {
					if ( onDateChange ) {
						// Report the start of the current view
						onDateChange( info.view.currentStart );
					}
				},
			} );

			calendar.render();
			calendarInstanceRef.current = calendar;

			// Expose navigation function to parent via callback
			if ( setNavigateCallback ) {
				setNavigateCallback( ( date ) => {
					if ( calendarInstanceRef.current ) {
						calendarInstanceRef.current.gotoDate( date );
					}
				} );
			}

			// ============================================================
			// FIX: Deferred Layout Fix
			//
			// Problem: On initial load, CSS may not be applied yet when events
			// first render. The manual positioning in eventDidMount measures
			// slot heights, but these are wrong (0 or incorrect) before CSS loads.
			//
			// Solution: Use ResizeObserver to detect when the calendar container
			// gets its final dimensions, then trigger a re-render.
			// ============================================================

			// Navigate to initial date immediately
			if ( nextAvailableDate ) {
				calendarInstanceRef.current.gotoDate( nextAvailableDate );
			}

			// Use ResizeObserver to detect when CSS is applied and layout stabilizes
			const calendarEl = calendarRef.current;
			if ( calendarEl && typeof ResizeObserver !== 'undefined' ) {
				const resizeObserver = new ResizeObserver( () => {
					if (
						! calendarInstanceRef.current ||
						layoutFixAppliedRef.current
					) {
						return;
					}

					const slot =
						calendarEl.querySelector( '.fc-timegrid-slot' );
					const slotHeight = slot
						? slot.getBoundingClientRect().height
						: 0;

					// Once slot height is valid (CSS loaded), apply the layout fix
					if ( slotHeight >= 15 ) {
						layoutFixAppliedRef.current = true;
						resizeObserver.disconnect();

						// Force FullCalendar to recalculate dimensions
						calendarInstanceRef.current.updateSize();

						// Get current view
						const currentView =
							calendarInstanceRef.current.view.type;

						// For timeGrid views, do the view switch trick to force complete re-render
						if ( currentView.includes( 'timeGrid' ) ) {
							calendarInstanceRef.current.changeView(
								'listWeek'
							);

							requestAnimationFrame( () => {
								if ( calendarInstanceRef.current ) {
									calendarInstanceRef.current.changeView(
										currentView
									);

									if ( nextAvailableDate ) {
										calendarInstanceRef.current.gotoDate(
											nextAvailableDate
										);
									}
								}
							} );
						} else {
							calendarInstanceRef.current.refetchEvents();
						}
					}
				} );
				resizeObserver.observe( calendarEl );

				// Safety: disconnect after 5 seconds if layout fix never triggers
				setTimeout( () => {
					if ( ! layoutFixAppliedRef.current ) {
						resizeObserver.disconnect();
					}
				}, 5000 );
			}

			// ============================================================
		}

		return () => {
			if ( calendarInstanceRef.current ) {
				calendarInstanceRef.current.destroy();
				calendarInstanceRef.current = null;
			}
		};
	}, [] );

	return (
		<div className="clisyc-calendar-section">
			<div className="clisyc-calendar-wrapper">
				{ isLoading && (
					<div
						className="clisyc-loading-overlay"
						style={ {
							position: 'absolute',
							top: 0,
							left: 0,
							right: 0,
							bottom: 0,
							background: 'rgba(255, 255, 255, 0.97)',
							display: 'flex',
							alignItems: 'flex-start',
							justifyContent: 'center',
							zIndex: 100,
						} }
					>
						<div
							style={ {
								display: 'flex',
								flexDirection: 'column',
								alignItems: 'center',
								gap: '24px',
								padding: '48px 32px',
							} }
						>
							{ /* Spinner */ }
							<div
								style={ {
									width: '48px',
									height: '48px',
									flexShrink: 0,
								} }
							>
								<svg
									viewBox="0 0 50 50"
									style={ {
										width: '100%',
										height: '100%',
										animation:
											'clisyc-spinner-rotate 1.5s linear infinite',
									} }
								>
									<circle
										cx="25"
										cy="25"
										r="20"
										fill="none"
										strokeWidth="4"
										stroke="#e5e7eb"
									/>
									<circle
										cx="25"
										cy="25"
										r="20"
										fill="none"
										strokeWidth="4"
										stroke="#3b82f6"
										strokeLinecap="round"
										strokeDasharray="80, 200"
										style={ {
											animation:
												'clisyc-spinner-dash 1.5s ease-in-out infinite',
										} }
									/>
								</svg>
							</div>
							{ /* Text */ }
							<div style={ { textAlign: 'center' } }>
								<div
									style={ {
										fontSize: '17px',
										fontWeight: 600,
										color: '#1f2937',
										marginBottom: '8px',
									} }
								>
									Finding Available Times
								</div>
								<div
									style={ {
										fontSize: '14px',
										color: '#6b7280',
									} }
								>
									Searching for open appointments...
								</div>
							</div>
						</div>
					</div>
				) }
				<div id="clisyc-calendar" ref={ calendarRef }></div>
			</div>
		</div>
	);
};

export default CalendarView;
