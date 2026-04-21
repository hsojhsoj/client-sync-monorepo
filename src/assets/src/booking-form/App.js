/**
 * File: src/assets/src/booking-form/App.js
 * The main React component for the Frontend Booking Form.
 *
 * UPDATED:
 * - Supports both 'slot' and 'date_range' booking modes
 * - Handles preselectedServiceId for single CPT pages
 * - Auto-initializes calendar when service is pre-selected
 * - Added showBooked toggle support via shortcode attributes
 * - Added CalendarNotice for admin-only availability alerts
 * - Added CalendarLegend for better UX
 * - Added state-driven live clock responding to selected timezone
 * - Integrated TimezoneMap component for visual timezone feedback
 * - UPDATED Timezone Selection: Added offset badges, larger map, and improved layout.
 * - Added AnalogClock component with animated clock hands
 * - Added clock icon to "Current Time" label
 * - ADDED: Centralized Basket/Price Calculation Logic with API integration
 *
 * FIXED (2026-01-01):
 * - "Heads Up" modal now shows VERIFIED first available date from API
 * - Previously used potentially stale cached date from PHP
 */
import React, { useCallback, useMemo, useEffect, useRef } from 'react';
import FilterPanel from './FilterPanel';
import CalendarView from './CalendarView';
import DateRangeCalendar from './DateRangeCalendar';
import BookingModal from './BookingModal';
import apiFetch from '@wordpress/api-fetch';

// Integrated Components
import CalendarLegend from './CalendarLegend';
import CalendarNotice from './CalendarNotice';
import TimezoneMap, { getTimezoneOffsetLabel } from './TimezoneMap';

// Shared utilities
import decodeHTMLEntities from '../shared/decode-html-entities';

// Extracted components
import AnalogClock from './components/AnalogClock';
import EventTooltip from './components/EventTooltip';
import MiniCalendar from './components/MiniCalendar';

// Centralized state management
import { useBooking, bookingConfig, ActionTypes } from './BookingContext';

const App = () => {
	// Centralized state from BookingContext
	const { state, dispatch } = useBooking();
	const {
		selectedSlot,
		selectedDateRange,
		isLoadingFilters,
		allFilterOptions,
		selectedFilters,
		isBookingModalOpen,
		isSmartDateModalOpen,
		isNoAvailabilityModalOpen,
		smartDateModalMessage,
		timezone,
		currentTime,
		selectedPrimaryItem,
		basket,
		isCalculatingPrice,
		tooltip,
		isSidebarCollapsed,
		isHeaderCollapsed,
		mainCalendarDate,
		showBooked,
		selectedSeats,
	} = state;

	// Ref to communicate with CalendarView for navigation (not reducer state)
	const calendarNavigateRef = useRef( null );

	// Config from PHP (read once at module level via BookingContext)
	const {
		restUrl,
		restNonce,
		filterOrder,
		formNonce,
		waitlistNonce,
		isGuest,
		customFieldDefinitions,
		successPageUrl,
		bookingMode,
		dateRangeSettings,
		currencySymbol,
		availabilityDimensions,
		preselectedServiceId,
		primaryItemName,
		showBookedToggle,
		calendarOptions,
		contactPageUrl,
		colorSettings,
		l10n,
		smartStartDateEnabled,
		linkedVenueId,
	} = bookingConfig;

	// Get text size class for modal styling
	const textSizeClass = colorSettings?.text_size
		? `clisyc-text-${ colorSettings.text_size }`
		: 'clisyc-text-medium';

	const isDateRangeMode = bookingMode === 'date_range';

	const primaryDimKey = useMemo(
		() =>
			filterOrder && filterOrder.length > 0
				? filterOrder[ 0 ]
				: 'clisyc_service',
		[ filterOrder ]
	);

	// Persist sidebar state to localStorage
	useEffect( () => {
		try {
			localStorage.setItem(
				'clisyc_sidebar_collapsed',
				isSidebarCollapsed.toString()
			);
		} catch {
			// localStorage not available
		}
	}, [ isSidebarCollapsed ] );

	// Persist header state to localStorage
	useEffect( () => {
		try {
			localStorage.setItem(
				'clisyc_header_collapsed',
				isHeaderCollapsed.toString()
			);
		} catch {
			// localStorage not available
		}
	}, [ isHeaderCollapsed ] );

	// Toggle sidebar collapsed/expanded
	const handleToggleSidebar = useCallback( () => {
		dispatch( {
			type: ActionTypes.SET_IS_SIDEBAR_COLLAPSED,
			payload: ! isSidebarCollapsed,
		} );
	}, [ dispatch, isSidebarCollapsed ] );

	// Toggle header collapsed/expanded
	const handleToggleHeader = useCallback( () => {
		dispatch( {
			type: ActionTypes.SET_IS_HEADER_COLLAPSED,
			payload: ! isHeaderCollapsed,
		} );
	}, [ dispatch, isHeaderCollapsed ] );

	// Handle mini calendar date selection - navigate main calendar
	const handleMiniCalendarDateSelect = useCallback(
		( date ) => {
			dispatch( {
				type: ActionTypes.SET_MAIN_CALENDAR_DATE,
				payload: date,
			} );
			// Tell CalendarView to navigate to this date
			if ( calendarNavigateRef.current ) {
				calendarNavigateRef.current( date );
			}
		},
		[ dispatch ]
	);

	// Callback for CalendarView to report its current date
	const handleMainCalendarDateChange = useCallback(
		( date ) => {
			dispatch( {
				type: ActionTypes.SET_MAIN_CALENDAR_DATE,
				payload: date,
			} );
		},
		[ dispatch ]
	);

	// Clock Logic
	useEffect( () => {
		const timer = setInterval( () => {
			try {
				const now = new Date();
				const options = {
					timeZone: timezone === 'local' ? undefined : timezone,
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
					hour12: true,
				};
				dispatch( {
					type: ActionTypes.SET_CURRENT_TIME,
					payload: now.toLocaleTimeString( undefined, options ),
				} );
			} catch ( e ) {
				dispatch( {
					type: ActionTypes.SET_CURRENT_TIME,
					payload: 'Invalid TZ',
				} );
			}
		}, 1000 );
		return () => clearInterval( timer );
	}, [ timezone, dispatch ] );

	// Fetch filter options on mount
	const fetchAllFilterOptions = useCallback( async () => {
		dispatch( { type: ActionTypes.SET_IS_LOADING_FILTERS, payload: true } );
		try {
			const response = await apiFetch( {
				path: `/clisyc/v1/filter-options?_wpnonce=${ restNonce }`,
			} );
			dispatch( {
				type: ActionTypes.SET_ALL_FILTER_OPTIONS,
				payload: response,
			} );

			const initiallySelected = {};

			// Handle pre-selection from PHP (single CPT page)
			if ( preselectedServiceId ) {
				// Pre-select the specific item
				initiallySelected[ primaryDimKey ] = [
					preselectedServiceId.toString(),
				];

				// For date_range mode, also set the selectedPrimaryItem state
				if ( isDateRangeMode && response[ primaryDimKey ] ) {
					const item = response[ primaryDimKey ].find(
						( opt ) =>
							opt.value.toString() ===
							preselectedServiceId.toString()
					);
					if ( item ) {
						dispatch( {
							type: ActionTypes.SET_SELECTED_PRIMARY_ITEM,
							payload: item,
						} );
					} else {
						// Item not in filter options - create a minimal object
						dispatch( {
							type: ActionTypes.SET_SELECTED_PRIMARY_ITEM,
							payload: {
								value: preselectedServiceId,
								label: primaryItemName || 'Selected Item',
								price: dateRangeSettings?.pricePerNight || 0,
							},
						} );
					}
				}
			} else if ( ! isDateRangeMode ) {
				// Default slot mode behavior: select all options
				filterOrder.forEach( ( key ) => {
					if ( response[ key ] && Array.isArray( response[ key ] ) ) {
						initiallySelected[ key ] = response[ key ].map(
							( opt ) => opt.value.toString()
						);
					}
				} );
			}

			dispatch( {
				type: ActionTypes.SET_SELECTED_FILTERS,
				payload: initiallySelected,
			} );
		} catch ( error ) {
			console.error( 'Failed to fetch filter options:', error );
		} finally {
			dispatch( {
				type: ActionTypes.SET_IS_LOADING_FILTERS,
				payload: false,
			} );
		}
	}, [
		dispatch,
		restNonce,
		filterOrder,
		isDateRangeMode,
		preselectedServiceId,
		primaryDimKey,
		primaryItemName,
		dateRangeSettings,
	] );

	useEffect( () => {
		fetchAllFilterOptions();
	}, [ fetchAllFilterOptions ] );

	// Price Calculation Effect
	useEffect( () => {
		// Only calculate if we have a primary item selected
		const primaryId =
			selectedPrimaryItem?.value ||
			( isDateRangeMode && selectedFilters[ primaryDimKey ]?.[ 0 ] ) ||
			preselectedServiceId;

		// If in slot mode and no slot selected, we can't calc duration accurately yet,
		// but we can estimate base price if primary is selected.
		if ( ! primaryId ) {
			dispatch( {
				type: ActionTypes.SET_BASKET,
				payload: { total: 0, formatted: '', items: [] },
			} );
			return;
		}

		// Don't calculate if just browsing slots (optimization),
		// wait for slot select OR date range select
		if ( ! isDateRangeMode && ! selectedSlot ) {
			return;
		}

		// AbortController prevents stale responses from overwriting fresh data.
		const abortController = new AbortController();

		const calculateTotal = async () => {
			dispatch( {
				type: ActionTypes.SET_IS_CALCULATING_PRICE,
				payload: true,
			} );

			// Gather all other selected IDs (exclude primary)
			const dimIds = [];
			Object.entries( selectedFilters ).forEach( ( [ key, val ] ) => {
				if ( key !== primaryDimKey && val ) {
					const id = Array.isArray( val ) ? val[ 0 ] : val;
					if ( id ) {
						dimIds.push( id );
					}
				}
			} );

			// Collect dimensions from selected slot if available (overrides filters)
			if ( selectedSlot?.dimensions ) {
				Object.entries( selectedSlot.dimensions ).forEach(
					( [ key, val ] ) => {
						if ( key !== primaryDimKey && val ) {
							dimIds.push( val );
						}
					}
				);
			}

			const params = new URLSearchParams( {
				primary_id: primaryId,
				dimension_ids: dimIds.join( ',' ),
				_wpnonce: restNonce,
			} );

			if ( isDateRangeMode && selectedDateRange ) {
				params.append( 'start_date', selectedDateRange.startDate );
				params.append( 'end_date', selectedDateRange.endDate );
			} else if ( selectedSlot ) {
				params.append( 'duration', selectedSlot.durationMinutes || 60 );
				params.append(
					'start_date',
					selectedSlot.event.start.toISOString()
				); // For seasonal check
			}

			// Attendees handling if applicable
			const attendeesInput =
				document.getElementById( 'clisyc_attendees' );
			if ( attendeesInput ) {
				params.append( 'attendees', attendeesInput.value );
			}

			// Pass selected seats for seat pricing line items.
			if ( selectedSeats && selectedSeats.length > 0 ) {
				params.append(
					'selected_seats',
					JSON.stringify( selectedSeats )
				);
			}

			try {
				const response = await apiFetch( {
					path: `/clisyc/v1/price/calculate-basket?${ params.toString() }`,
					signal: abortController.signal,
				} );

				if ( response.success ) {
					dispatch( {
						type: ActionTypes.SET_BASKET,
						payload: {
							total: response.total,
							formatted: response.formatted_total,
							items: response.line_items,
							currency: response.currency,
						},
					} );
				}
			} catch ( e ) {
				if ( e.name !== 'AbortError' ) {
					console.error( 'Price calculation failed', e );
				}
			} finally {
				if ( ! abortController.signal.aborted ) {
					dispatch( {
						type: ActionTypes.SET_IS_CALCULATING_PRICE,
						payload: false,
					} );
				}
			}
		};

		// Debounce calculation
		const timer = setTimeout( calculateTotal, 250 );
		return () => {
			clearTimeout( timer );
			abortController.abort();
		};
	}, [
		dispatch,
		selectedFilters,
		selectedDateRange,
		selectedSlot,
		selectedPrimaryItem,
		preselectedServiceId,
		isDateRangeMode,
		primaryDimKey,
		restNonce,
		selectedSeats,
	] );

	// Handle filter changes
	const handleFilterSelection = useCallback(
		( key, value ) => {
			dispatch( {
				type: ActionTypes.UPDATE_SELECTED_FILTER,
				payload: { key, value },
			} );

			// Clear selections when filters change
			dispatch( { type: ActionTypes.SET_SELECTED_SLOT, payload: null } );
			dispatch( {
				type: ActionTypes.SET_SELECTED_DATE_RANGE,
				payload: null,
			} );

			// For date_range mode, track the selected primary item
			if ( isDateRangeMode && key === primaryDimKey && value ) {
				const selectedValue = Array.isArray( value )
					? value[ 0 ]
					: value;
				const options = allFilterOptions[ primaryDimKey ] || [];
				const item = options.find(
					( opt ) => opt.value.toString() === selectedValue.toString()
				);
				dispatch( {
					type: ActionTypes.SET_SELECTED_PRIMARY_ITEM,
					payload: item || null,
				} );
			}
		},
		[ dispatch, isDateRangeMode, primaryDimKey, allFilterOptions ]
	);

	const handleResetFilters = useCallback( () => {
		// If pre-selected, don't fully reset - keep the pre-selection
		if ( preselectedServiceId ) {
			dispatch( {
				type: ActionTypes.SET_SELECTED_DATE_RANGE,
				payload: null,
			} );
			dispatch( { type: ActionTypes.SET_SELECTED_SLOT, payload: null } );
			return;
		}

		fetchAllFilterOptions();
		dispatch( { type: ActionTypes.RESET_SELECTIONS } );
	}, [ dispatch, fetchAllFilterOptions, preselectedServiceId ] );

	// Handle slot selection (time-based bookings)
	const handleSlotSelect = useCallback(
		( event ) => {
			const props = event.extendedProps;

			if ( ! props.isBookable ) {
				return;
			}

			const startTimeLocal = event.start.toLocaleTimeString( [], {
				hour: 'numeric',
				minute: '2-digit',
				hour12: true,
			} );
			const dateLocal = event.start.toLocaleDateString( [], {
				month: 'long',
				day: 'numeric',
				year: 'numeric',
			} );

			dispatch( {
				type: ActionTypes.SET_SELECTED_SLOT,
				payload: {
					event,
					identifier: props.slotIdentifier,
					durationMinutes: props.durationMinutes,
					displayTime: `${ dateLocal } at ${ startTimeLocal }`,
					dimensions: props.dimensions, // Included to support advanced pricing logic
				},
			} );

			dispatch( {
				type: ActionTypes.SET_IS_BOOKING_MODAL_OPEN,
				payload: true,
			} );
		},
		[ dispatch ]
	);

	// Handle date range selection (date-based bookings)
	const handleDateRangeSelect = useCallback(
		( rangeData ) => {
			if ( ! rangeData ) {
				dispatch( {
					type: ActionTypes.SET_SELECTED_DATE_RANGE,
					payload: null,
				} );
				return;
			}

			dispatch( {
				type: ActionTypes.SET_SELECTED_DATE_RANGE,
				payload: {
					startDate: rangeData.start,
					endDate: rangeData.end,
					nights: rangeData.nights,
					total: rangeData.total,
					displayText: `${ rangeData.nights } night${
						rangeData.nights !== 1 ? 's' : ''
					}`,
				},
			} );
		},
		[ dispatch ]
	);

	// Handle slot/date deselection
	const handleDeselect = useCallback( () => {
		dispatch( { type: ActionTypes.SET_SELECTED_SLOT, payload: null } );
		dispatch( {
			type: ActionTypes.SET_SELECTED_DATE_RANGE,
			payload: null,
		} );
		dispatch( {
			type: ActionTypes.SET_IS_BOOKING_MODAL_OPEN,
			payload: false,
		} );
	}, [ dispatch ] );

	// Close booking modal
	const handleCloseBookingModal = useCallback( () => {
		dispatch( {
			type: ActionTypes.SET_IS_BOOKING_MODAL_OPEN,
			payload: false,
		} );
	}, [ dispatch ] );

	// Open booking modal for date range
	const handleOpenDateRangeBooking = useCallback( () => {
		if ( selectedDateRange && selectedPrimaryItem ) {
			dispatch( {
				type: ActionTypes.SET_IS_BOOKING_MODAL_OPEN,
				payload: true,
			} );
		}
	}, [ dispatch, selectedDateRange, selectedPrimaryItem ] );

	// =========================================================================
	// FIXED: Smart Start Date Modal - Now uses VERIFIED date from CalendarView
	// =========================================================================
	// Instead of showing the modal based on potentially stale PHP cached data,
	// we now wait for CalendarView to report the actual first available date
	// after it fetches slots from the API.
	//
	// This callback is passed to CalendarView and called once after initial load.
	// It receives:
	// - A Date object if slots are available (may trigger "jumped ahead" modal)
	// - null if NO slots are available (triggers "no availability" modal)
	// =========================================================================
	const handleFirstAvailableDate = useCallback(
		( verifiedDate ) => {
			// Only applies to slot mode (not date_range mode)
			if ( isDateRangeMode ) {
				return;
			}

			// Handle "no availability" case (always show this regardless of Smart Start Date setting)
			if ( verifiedDate === null ) {
				dispatch( {
					type: ActionTypes.SET_IS_NO_AVAILABILITY_MODAL_OPEN,
					payload: true,
				} );
				return;
			}

			// CRITICAL: Only show "Heads Up" modal if Smart Start Date feature is ENABLED
			// If the setting is off in WP Admin > ClientSync > Behavior, skip the modal
			if ( ! smartStartDateEnabled ) {
				return;
			}

			// Validate we received a valid date
			if ( ! verifiedDate || ! ( verifiedDate instanceof Date ) ) {
				return;
			}

			const today = new Date();
			today.setHours( 0, 0, 0, 0 );

			// Only show modal if first available date is in the future
			const verifiedDateOnly = new Date( verifiedDate );
			verifiedDateOnly.setHours( 0, 0, 0, 0 );

			if ( verifiedDateOnly.getTime() > today.getTime() ) {
				const formattedDate = verifiedDate.toLocaleDateString(
					undefined,
					{
						weekday: 'long',
						year: 'numeric',
						month: 'long',
						day: 'numeric',
					}
				);

				// Store just the formatted date for the modal date box
				dispatch( {
					type: ActionTypes.SET_SMART_DATE_MODAL_MESSAGE,
					payload: formattedDate,
				} );
				dispatch( {
					type: ActionTypes.SET_IS_SMART_DATE_MODAL_OPEN,
					payload: true,
				} );
			}
		},
		[ dispatch, isDateRangeMode, smartStartDateEnabled ]
	);

	// =========================================================================
	// EVENT TOOLTIP HANDLERS
	// These are passed to CalendarView and triggered on event mouse enter/leave
	// =========================================================================
	const handleEventMouseEnter = useCallback(
		( info ) => {
			const { event, el } = info;

			// Get event data
			const extendedProps = event.extendedProps || {};
			const startTime = event.start
				? event.start.toLocaleTimeString( [], {
						hour: '2-digit',
						minute: '2-digit',
				  } )
				: '';
			const endTime = event.end
				? event.end.toLocaleTimeString( [], {
						hour: '2-digit',
						minute: '2-digit',
				  } )
				: '';

			// Build tooltip content
			const content = {
				title: event.title || extendedProps.serviceName || 'Available',
				time:
					startTime && endTime ? `${ startTime } - ${ endTime }` : '',
				practitioner:
					extendedProps.practitionerName ||
					extendedProps.practitioner ||
					'',
				practitionerColor: extendedProps.practitionerColor || '#4285f4',
				room: extendedProps.roomName || extendedProps.room || '',
				roomColor: extendedProps.roomColor || '#34a853',
				service: extendedProps.serviceName || '',
				serviceColor: extendedProps.serviceColor || '#1a73e8',
			};

			// Calculate position - to the right of the event, or left if near edge
			const rect = el.getBoundingClientRect();
			const viewportWidth = window.innerWidth;
			const tooltipWidth = 220; // Approximate tooltip width

			let x, y;

			// Position to the right by default, left if not enough space
			if ( rect.right + tooltipWidth + 20 < viewportWidth ) {
				x = rect.right + 8;
			} else {
				x = rect.left - tooltipWidth - 8;
			}

			// Vertically center on the event
			y = rect.top + rect.height / 2;

			dispatch( {
				type: ActionTypes.SET_TOOLTIP,
				payload: { visible: true, x, y, content },
			} );
		},
		[ dispatch ]
	);

	const handleEventMouseLeave = useCallback( () => {
		dispatch( {
			type: ActionTypes.SET_TOOLTIP,
			payload: { visible: false, x: 0, y: 0, content: null },
		} );
	}, [ dispatch ] );

	// Body scroll lock for modals
	useEffect( () => {
		if ( isSmartDateModalOpen || isNoAvailabilityModalOpen ) {
			document.body.classList.add( 'clisyc-modal-open' );
		} else {
			document.body.classList.remove( 'clisyc-modal-open' );
		}
		return () => document.body.classList.remove( 'clisyc-modal-open' );
	}, [ isSmartDateModalOpen, isNoAvailabilityModalOpen ] );

	const isReady = ! isLoadingFilters;

	// Determine if we should show the filter panel
	const showFilters =
		filterOrder &&
		filterOrder.length > 0 &&
		! ( isDateRangeMode && preselectedServiceId );

	const hasValidDateRangeSelection =
		isDateRangeMode && selectedDateRange && selectedPrimaryItem;
	const primaryDimValue =
		selectedFilters[ primaryDimKey ]?.[ 0 ] ||
		selectedFilters[ primaryDimKey ];

	return (
		<div
			className={ `clisyc-booking-form-app ${
				isDateRangeMode ? 'clisyc-date-range-mode' : 'clisyc-slot-mode'
			} ${ preselectedServiceId ? 'clisyc-preselected' : '' }` }
		>
			{ /* Smart Date Modal (slot mode only) */ }
			{ isSmartDateModalOpen && ! isDateRangeMode && (
				<div
					className={ `clisyc-modal-overlay ${ textSizeClass }` }
					onClick={ () =>
						dispatch( {
							type: ActionTypes.SET_IS_SMART_DATE_MODAL_OPEN,
							payload: false,
						} )
					}
				>
					<div
						className="clisyc-modal-content"
						onClick={ ( e ) => e.stopPropagation() }
					>
						<div className="clisyc-modal-icon">
							<svg
								viewBox="0 0 24 24"
								fill="none"
								stroke="currentColor"
								strokeWidth="2"
								strokeLinecap="round"
								strokeLinejoin="round"
							>
								<rect
									x="3"
									y="4"
									width="18"
									height="18"
									rx="2"
									ry="2"
								></rect>
								<line x1="16" y1="2" x2="16" y2="6"></line>
								<line x1="8" y1="2" x2="8" y2="6"></line>
								<line x1="3" y1="10" x2="21" y2="10"></line>
								<path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"></path>
							</svg>
						</div>
						<h2>
							{ decodeHTMLEntities( l10n?.headsUp ) ||
								'Heads Up!' }
						</h2>
						<p>
							{ decodeHTMLEntities( l10n?.jumpedAheadMessage ) ||
								"We've jumped ahead to the first available date for you." }
						</p>
						{ smartDateModalMessage && (
							<span className="clisyc-modal-date">
								{ smartDateModalMessage }
							</span>
						) }
						<button
							type="button"
							className="clisyc-modal-ok-button"
							onClick={ () =>
								dispatch( {
									type: ActionTypes.SET_IS_SMART_DATE_MODAL_OPEN,
									payload: false,
								} )
							}
						>
							{ decodeHTMLEntities( l10n?.gotIt ) || 'Got it' }
						</button>
					</div>
				</div>
			) }

			{ /* No Availability Modal (slot mode only) */ }
			{ isNoAvailabilityModalOpen && ! isDateRangeMode && (
				<div
					className={ `clisyc-modal-overlay ${ textSizeClass }` }
					onClick={ () =>
						dispatch( {
							type: ActionTypes.SET_IS_NO_AVAILABILITY_MODAL_OPEN,
							payload: false,
						} )
					}
				>
					<div
						className="clisyc-modal-content"
						onClick={ ( e ) => e.stopPropagation() }
					>
						<div className="clisyc-modal-icon">
							<svg
								viewBox="0 0 24 24"
								fill="none"
								stroke="currentColor"
								strokeWidth="2"
								strokeLinecap="round"
								strokeLinejoin="round"
							>
								<rect
									x="3"
									y="4"
									width="18"
									height="18"
									rx="2"
									ry="2"
								></rect>
								<line x1="16" y1="2" x2="16" y2="6"></line>
								<line x1="8" y1="2" x2="8" y2="6"></line>
								<line x1="3" y1="10" x2="21" y2="10"></line>
								<path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"></path>
							</svg>
						</div>
						<h2>
							{ decodeHTMLEntities( l10n?.noAvailabilityTitle ) ||
								'No Availability' }
						</h2>
						<p>
							{ decodeHTMLEntities(
								l10n?.noAvailabilityMessage
							) ||
								'There are currently no available appointment times. Please check back later or contact us for assistance.' }
						</p>
						<div className="clisyc-modal-buttons">
							{ contactPageUrl && (
								<a
									href={ contactPageUrl }
									className="clisyc-modal-ok-button"
								>
									<svg
										viewBox="0 0 24 24"
										fill="none"
										stroke="currentColor"
										strokeWidth="2"
										strokeLinecap="round"
										strokeLinejoin="round"
										style={ {
											width: '18px',
											height: '18px',
										} }
									>
										<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
										<polyline points="22,6 12,13 2,6"></polyline>
									</svg>
									{ decodeHTMLEntities( l10n?.contactUs ) ||
										'Contact Us' }
								</a>
							) }
							<button
								type="button"
								className={
									contactPageUrl
										? 'clisyc-modal-button--secondary'
										: 'clisyc-modal-ok-button'
								}
								onClick={ () =>
									dispatch( {
										type: ActionTypes.SET_IS_NO_AVAILABILITY_MODAL_OPEN,
										payload: false,
									} )
								}
							>
								{ decodeHTMLEntities( l10n?.close ) || 'Close' }
							</button>
						</div>
					</div>
				</div>
			) }

			{ /* Booking Confirmation Modal */ }
			<BookingModal
				isOpen={ isBookingModalOpen }
				onClose={ handleCloseBookingModal }
				selectedSlot={ selectedSlot }
				selectedDateRange={ selectedDateRange }
				selectedFilters={ selectedFilters }
				selectedPrimaryItem={ selectedPrimaryItem }
				formNonce={ formNonce }
				waitlistNonce={ waitlistNonce }
				isGuest={ isGuest }
				customFieldDefinitions={ customFieldDefinitions }
				successPageUrl={ successPageUrl }
				bookingMode={ bookingMode }
				currencySymbol={ currencySymbol }
				basket={ basket }
				isCalculatingPrice={ isCalculatingPrice }
				selectedSeats={ selectedSeats }
				onSeatsChange={ ( seats ) =>
					dispatch( {
						type: ActionTypes.SET_SELECTED_SEATS,
						payload: seats,
					} )
				}
				linkedVenueId={ linkedVenueId }
			/>

			{ /* Main Layout */ }
			<div
				className={ `clisyc-faceted-search-layout ${
					! showFilters ? 'clisyc-no-sidebar' : ''
				} ${ isSidebarCollapsed ? 'clisyc-sidebar-collapsed' : '' }` }
			>
				{ /* Filter Panel */ }
				{ showFilters && (
					<FilterPanel
						isLoading={ isLoadingFilters }
						options={ allFilterOptions }
						selections={ selectedFilters }
						onSelect={ handleFilterSelection }
						onReset={ handleResetFilters }
						bookingMode={ bookingMode }
						showBooked={ showBooked }
						onToggleShowBooked={
							showBookedToggle
								? ( val ) =>
										dispatch( {
											type: ActionTypes.SET_SHOW_BOOKED,
											payload: val,
										} )
								: null
						}
						isSidebarCollapsed={ isSidebarCollapsed }
						onToggleSidebar={ handleToggleSidebar }
						miniCalendar={
							! isDateRangeMode ? (
								<MiniCalendar
									currentDate={ mainCalendarDate }
									onDateSelect={
										handleMiniCalendarDateSelect
									}
								/>
							) : null
						}
					/>
				) }

				{ /* Main Content Area */ }
				<div className="clisyc-main-content-area">
					{ /* Timezone, Map, and Clock Section (slot mode only) */ }
					{ ! isDateRangeMode && (
						<div
							className={ `clisyc-time-selection-wrapper ${
								isHeaderCollapsed
									? 'clisyc-header-collapsed'
									: 'clisyc-header-expanded'
							}` }
						>
							{ /* Collapsed Header - Compact Bar */ }
							{ isHeaderCollapsed && (
								<div className="clisyc-header-compact">
									<div className="clisyc-header-compact-info">
										<span
											className="dashicons dashicons-admin-site"
											aria-hidden="true"
										></span>
										<span className="clisyc-header-compact-timezone">
											{ timezone === 'local'
												? 'Local Time'
												: timezone
														.split( '/' )
														.pop()
														.replace( '_', ' ' ) }
										</span>
										<span className="clisyc-timezone-offset-badge">
											{ getTimezoneOffsetLabel(
												timezone
											) }
										</span>
										<span className="clisyc-header-compact-divider">
											|
										</span>
										<span className="clisyc-header-compact-time">
											{ currentTime }
										</span>
									</div>
									<button
										type="button"
										className="clisyc-header-toggle-btn"
										onClick={ handleToggleHeader }
										aria-label="Expand timezone settings"
										title="Click to change timezone"
									>
										<span className="dashicons dashicons-arrow-down-alt2"></span>
										<span className="clisyc-header-toggle-text">
											Change Timezone
										</span>
									</button>
								</div>
							) }

							{ /* Expanded Header - Full Controls */ }
							{ ! isHeaderCollapsed && (
								<div className="clisyc-header-expanded-content">
									{ /* Top Row: Timezone Selector + Clock + Collapse Button */ }
									<div className="clisyc-header-top-row">
										{ /* Timezone Selector - Left */ }
										<div className="clisyc-timezone-filter-wrapper">
											<label
												className="clisyc-timezone-label"
												htmlFor="clisyc-timezone-select"
											>
												<span
													className="dashicons dashicons-admin-site"
													aria-hidden="true"
												></span>
												<span>Timezone Selection</span>
												<span className="clisyc-timezone-offset-badge">
													{ getTimezoneOffsetLabel(
														timezone
													) }
												</span>
											</label>
											<div className="clisyc-timezone-selector">
												<select
													id="clisyc-timezone-select"
													value={ timezone }
													onChange={ ( e ) =>
														dispatch( {
															type: ActionTypes.SET_TIMEZONE,
															payload:
																e.target.value,
														} )
													}
													aria-label="Select timezone"
												>
													<option value="local">
														My Local Time
													</option>
													<option value="UTC">
														UTC
													</option>
													<option value="America/New_York">
														New York (ET)
													</option>
													<option value="America/Chicago">
														Chicago (CT)
													</option>
													<option value="America/Denver">
														Denver (MT)
													</option>
													<option value="America/Los_Angeles">
														Los Angeles (PT)
													</option>
													<option value="Europe/London">
														London (GMT/BST)
													</option>
													<option value="Europe/Paris">
														Paris (CET)
													</option>
													<option value="Asia/Tokyo">
														Tokyo (JST)
													</option>
													<option value="Australia/Sydney">
														Sydney (AEST)
													</option>
												</select>
											</div>
										</div>

										{ /* Live Clock - Center/Right */ }
										<div className="clisyc-live-clock-wrapper">
											<div className="clisyc-clock-label">
												<span>Current Time</span>
											</div>
											<div className="clisyc-clock-display">
												<AnalogClock
													timezone={ timezone }
												/>
												<div className="clisyc-live-clock">
													{ currentTime }
												</div>
											</div>
										</div>

										{ /* Collapse Button - Right */ }
										<button
											type="button"
											className="clisyc-header-collapse-btn"
											onClick={ handleToggleHeader }
											aria-label="Collapse timezone settings"
											title="Collapse"
										>
											<span className="dashicons dashicons-arrow-up-alt2"></span>
										</button>
									</div>

									{ /* Bottom Row: Full-Width Map */ }
									<div className="clisyc-header-map-row">
										<TimezoneMap
											selectedTimezone={ timezone }
											size="large"
											showLabel={ false }
										/>
									</div>
								</div>
							) }
						</div>
					) }

					{ /* Selected slot indicator (slot mode) */ }
					{ ! isDateRangeMode && selectedSlot && (
						<div className="clisyc-selected-slot-banner">
							<div className="clisyc-selected-slot-info">
								<span className="clisyc-selected-slot-icon">
									✓
								</span>
								<span className="clisyc-selected-slot-text">
									Selected:{ ' ' }
									<strong>
										{ selectedSlot.displayTime }
									</strong>
								</span>
							</div>
							<button
								type="button"
								className="clisyc-selected-slot-book-btn"
								onClick={ () =>
									dispatch( {
										type: ActionTypes.SET_IS_BOOKING_MODAL_OPEN,
										payload: true,
									} )
								}
							>
								Complete Booking
							</button>
						</div>
					) }

					{ /* Date Range Mode Content */ }
					{ isDateRangeMode && isReady && (
						<>
							{ ! primaryDimValue && ! preselectedServiceId && (
								<div className="clisyc-empty-state">
									<div className="clisyc-empty-state-icon">
										📅
									</div>
									<div className="clisyc-empty-state-message">
										Select a{ ' ' }
										{ availabilityDimensions?.[
											primaryDimKey
										]?.label || 'service' }
									</div>
									<div className="clisyc-empty-state-description">
										Choose from the options on the left to
										see availability.
									</div>
								</div>
							) }

							{ ( primaryDimValue || preselectedServiceId ) && (
								<div className="clisyc-date-range-tz-bar">
									<span
										className="dashicons dashicons-admin-site"
										aria-hidden="true"
									></span>
									<span>
										{ timezone === 'local'
											? l10n?.localTime || 'Local Time'
											: timezone
													.replace( '_', ' ' )
													.split( '/' )
													.pop() }
									</span>
									<span className="clisyc-timezone-offset-badge">
										{ getTimezoneOffsetLabel( timezone ) }
									</span>
								</div>
							) }

							{ ( primaryDimValue || preselectedServiceId ) && (
								<DateRangeCalendar
									dimensionId={
										primaryDimValue || preselectedServiceId
									}
									dimensionName={
										selectedPrimaryItem?.label ||
										primaryItemName
									}
									restNonce={ restNonce }
									onDateRangeSelect={ handleDateRangeSelect }
									minStay={ dateRangeSettings?.minStay || 1 }
									maxStay={
										dateRangeSettings?.maxStay || 365
									}
									bufferDays={
										dateRangeSettings?.bufferDays || 0
									}
									pricePerNight={
										selectedPrimaryItem?.price ||
										dateRangeSettings?.pricePerNight ||
										0
									}
									currencySymbol={ currencySymbol || '$' }
								/>
							) }

							{ hasValidDateRangeSelection && (
								<div className="clisyc-date-range-booking-cta">
									<button
										type="button"
										className="clisyc-date-range-book-btn"
										onClick={ handleOpenDateRangeBooking }
									>
										Book Now - { selectedDateRange.nights }{ ' ' }
										Night
										{ selectedDateRange.nights !== 1
											? 's'
											: '' }
										{ selectedDateRange.total && (
											<span className="clisyc-btn-price">
												{ currencySymbol }
												{ selectedDateRange.total }
											</span>
										) }
									</button>
								</div>
							) }
						</>
					) }

					{ /* Slot Mode Calendar */ }
					{ ! isDateRangeMode && isReady && (
						<>
							{ /* Admin Notice for slots outside visible range */ }
							<CalendarNotice
								selectedFilters={ selectedFilters }
								restNonce={ restNonce }
								calendarStartTime={
									calendarOptions?.slotMinTime || '08:00'
								}
								calendarEndTime={
									calendarOptions?.slotMaxTime || '18:00'
								}
							/>

							<CalendarView
								selectedFilters={ selectedFilters }
								restUrl={ restUrl }
								restNonce={ restNonce }
								onSlotSelect={ handleSlotSelect }
								onSlotDeselect={ handleDeselect }
								primaryDimKey={ primaryDimKey }
								timezone={ timezone }
								selectedSlotId={ selectedSlot?.identifier }
								showBooked={ showBooked }
								onFirstAvailableDate={
									handleFirstAvailableDate
								}
								onEventMouseEnter={ handleEventMouseEnter }
								onEventMouseLeave={ handleEventMouseLeave }
								onDateChange={ handleMainCalendarDateChange }
								setNavigateCallback={ ( fn ) => {
									calendarNavigateRef.current = fn;
								} }
							/>

							{ /* Calendar Legend */ }
							<CalendarLegend showBooked={ showBooked } />
						</>
					) }

					{ /* Loading state */ }
					{ ! isReady && (
						<div className="clisyc-empty-state">
							<div className="clisyc-empty-state-icon">⚙️</div>
							<div className="clisyc-empty-state-message">
								Loading...
							</div>
							<div className="clisyc-empty-state-description">
								Please wait while we load available times.
							</div>
						</div>
					) }

					{ /* Help text */ }
					<div className="clisyc-calendar-help-text">
						{ isDateRangeMode ? (
							<p>
								Select your check-in and check-out dates on the
								calendar above.
							</p>
						) : (
							<p>
								Click on an available time slot to book your
								appointment.
							</p>
						) }
					</div>
				</div>
			</div>

			{ /* Event Tooltip - rendered at root level for proper positioning */ }
			<EventTooltip
				visible={ tooltip.visible }
				x={ tooltip.x }
				y={ tooltip.y }
				content={ tooltip.content }
			/>
		</div>
	);
};

export default App;
