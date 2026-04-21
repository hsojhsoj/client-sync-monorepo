/**
 * File: src/assets/src/hybrid-booking/HybridApp.js
 * The main React component for the Hybrid Booking Form.
 *
 * FIXED (2026-01-07):
 * - "No Availability" modal now only shows when there are truly NO slots in the generation lookahead period
 * - Previously showed the modal when current week had no slots, even if future weeks did
 * - Now uses the Generation Lookahead setting (clisyc_auto_generate_lookahead) for broader search
 */
import React, {
	useState,
	useCallback,
	useMemo,
	useEffect,
	useRef,
} from 'react';
import FilterPanel from './HybridFilterPanel';
import CompactTimeSlotGrid from '../compact-timeslot-grid/CompactTimeSlotGrid';
import apiFetch from '@wordpress/api-fetch';
import '../shared/skeleton-loader.css';

// Shared utilities
import decodeHTMLEntities from '../shared/decode-html-entities';

// Seat selection (shared with booking-form)
import SeatMapPicker from '../booking-form/SeatMapPicker';

// Accept displayOptions as a prop (passed from index.js based on data attribute)
const HybridApp = ( { displayOptions: propDisplayOptions = {} } ) => {
	// Get base localized data (shared across all instances)
	const hybridData = window.clisycHybridBookingData || {};

	const {
		nextAvailableDate,
		restUrl,
		restNonce,
		filterOrder = [],
		formNonce,
		l10n = {},
		paymentRequired,
		colorSettings = {},
		calendarOptions = {},
		availabilityDimensions = {},
		contactPageUrl,
		generationLookahead = 30, // Default to 30 days if not set
	} = hybridData;

	// Parse startOfWeek as integer
	const startOfWeek = parseInt( hybridData.startOfWeek ) || 0;

	// Use display options from props (set via data attribute on container element)
	// These come directly from PHP as proper booleans via JSON.parse
	const showFilters = propDisplayOptions.showFilters !== false; // default true
	const showBooked = propDisplayOptions.showBooked === true; // default false
	const showBlocked = propDisplayOptions.showBlocked !== false; // default true

	const [ selectedSlot, setSelectedSlot ] = useState( null );
	const [ isLoadingFilters, setIsLoadingFilters ] = useState( true );
	const [ allFilterOptions, setAllFilterOptions ] = useState( {} );
	const [ selectedFilters, setSelectedFilters ] = useState( {} );
	const [ isMobileView, setIsMobileView ] = useState(
		window.innerWidth < 768
	);
	const [ dateRange, setDateRange ] = useState( { start: null, end: null } );
	const [ isConfirmationModalOpen, setIsConfirmationModalOpen ] =
		useState( false );
	const [ isSmartDateModalOpen, setIsSmartDateModalOpen ] = useState( false );
	const [ isNoAvailabilityModalOpen, setIsNoAvailabilityModalOpen ] =
		useState( false );
	const [ smartDateModalMessage, setSmartDateModalMessage ] = useState( '' );
	const [ slotCounts, setSlotCounts ] = useState( {
		total: 0,
		byFilter: {},
	} );
	const [ hasCheckedAvailability, setHasCheckedAvailability ] =
		useState( false );

	// Seat selection state
	const [ selectedSeats, setSelectedSeats ] = useState( [] );
	const [ resolvedVenueId, setResolvedVenueId ] = useState( 0 );

	// Ref to track if we're currently doing a broader availability check
	const isCheckingBroaderAvailabilityRef = useRef( false );

	// Dynamically resolve venue ID from slot_id (same logic as BookingModal).
	useEffect( () => {
		if ( ! selectedSlot ) {
			setResolvedVenueId( 0 );
			return;
		}

		const slotId = selectedSlot.slotId;
		if ( ! slotId ) {
			setResolvedVenueId( 0 );
			return;
		}

		apiFetch( { path: `/clisyc/v1/seats/resolve/${ slotId }` } )
			.then( ( res ) => setResolvedVenueId( res.venue_id || 0 ) )
			.catch( () => setResolvedVenueId( 0 ) );
	}, [ selectedSlot ] );

	// Dynamic color styles
	const appStyles = useMemo( () => {
		const styles = {};
		styles[ '--clisyc-available-bg' ] =
			colorSettings?.available_bg || '#1f2937';
		styles[ '--clisyc-available-text' ] =
			colorSettings?.available_text || '#ffffff';
		styles[ '--clisyc-unavailable-bg' ] =
			colorSettings?.booked_bg || '#e5e7eb';
		styles[ '--clisyc-unavailable-text' ] =
			colorSettings?.booked_text || '#4b5563';
		return styles;
	}, [ colorSettings ] );

	// Get text size class for modal styling
	const textSizeClass = colorSettings?.text_size
		? `clisyc-text-${ colorSettings.text_size }`
		: 'clisyc-text-medium';

	// Handle window resize for responsive view
	useEffect( () => {
		const handleResize = () => {
			const newIsMobile = window.innerWidth < 768;
			if ( newIsMobile !== isMobileView ) {
				setIsMobileView( newIsMobile );
			}
		};
		window.addEventListener( 'resize', handleResize );
		return () => window.removeEventListener( 'resize', handleResize );
	}, [ isMobileView ] );

	// Generate date range for grid display
	const generateDateRange = useCallback(
		( startDate ) => {
			let effectiveStartDate;

			if ( startDate ) {
				effectiveStartDate = new Date( startDate );
			} else if ( nextAvailableDate ) {
				effectiveStartDate = new Date(
					nextAvailableDate + 'T00:00:00'
				);
			} else {
				effectiveStartDate = new Date();
			}

			effectiveStartDate.setHours( 0, 0, 0, 0 );

			const start = new Date( effectiveStartDate );

			if ( isMobileView ) {
				start.setDate( start.getDate() - 1 );
				const end = new Date( start );
				end.setDate( end.getDate() + 3 );
				setDateRange( { start, end } );
			} else {
				const dayOfWeek = start.getDay();
				let diff = dayOfWeek - startOfWeek;
				if ( diff < 0 ) {
					diff += 7;
				}
				start.setDate( start.getDate() - diff );
				const end = new Date(
					start.getTime() + 7 * 24 * 60 * 60 * 1000
				);
				setDateRange( { start, end } );
			}
		},
		[ isMobileView, nextAvailableDate, startOfWeek ]
	);

	// Regenerate date range when mobile view changes
	useEffect( () => {
		generateDateRange( dateRange.start );
	}, [ isMobileView ] );

	// Pre-selected filters from [clisyc_dimension_grid] CTA links.
	const preselectedFilters = hybridData.preselectedFilters || null;

	// Fetch all available filter options
	const fetchAllFilterOptions = useCallback( async () => {
		setIsLoadingFilters( true );
		try {
			const response = await apiFetch( {
				path: `/clisyc/v1/filter-options?_wpnonce=${ restNonce }`,
			} );
			setAllFilterOptions( response );
			// Apply pre-selected filters from dimension grid, or start empty.
			if (
				preselectedFilters &&
				typeof preselectedFilters === 'object'
			) {
				setSelectedFilters( preselectedFilters );
			} else {
				setSelectedFilters( {} );
			}
		} catch ( error ) {
			console.error( 'Failed to fetch filter options:', error );
		} finally {
			setIsLoadingFilters( false );
		}
	}, [ restNonce, preselectedFilters ] );

	// Initialize filters and date range on mount
	useEffect( () => {
		fetchAllFilterOptions();
		generateDateRange( null );
	}, [ fetchAllFilterOptions, generateDateRange ] );

	// Smart Start Date: Show modal only if jumping to a DIFFERENT visible range
	useEffect( () => {
		if ( nextAvailableDate ) {
			const smartDate = new Date( nextAvailableDate + 'T00:00:00' );
			const today = new Date();
			today.setHours( 0, 0, 0, 0 );

			let visibleRangeStart;
			let visibleRangeEnd;

			if ( isMobileView ) {
				visibleRangeStart = new Date( today );
				visibleRangeStart.setDate( visibleRangeStart.getDate() - 1 );
				visibleRangeEnd = new Date(
					visibleRangeStart.getTime() + 3 * 24 * 60 * 60 * 1000
				);
			} else {
				visibleRangeStart = new Date( today );
				const todayDayOfWeek = visibleRangeStart.getDay();
				let diff = todayDayOfWeek - startOfWeek;
				if ( diff < 0 ) {
					diff += 7;
				}
				visibleRangeStart.setDate( visibleRangeStart.getDate() - diff );
				visibleRangeEnd = new Date(
					visibleRangeStart.getTime() + 7 * 24 * 60 * 60 * 1000
				);
			}

			if ( smartDate >= visibleRangeEnd ) {
				const formattedDate = smartDate.toLocaleDateString( undefined, {
					weekday: 'long',
					year: 'numeric',
					month: 'long',
					day: 'numeric',
				} );
				setSmartDateModalMessage( formattedDate );
				setIsSmartDateModalOpen( true );
			}
		}
	}, [ nextAvailableDate, startOfWeek, isMobileView ] );

	// Navigate to previous/next week or day
	const navigateRange = ( direction ) => {
		const increment = isMobileView ? 1 : 7;
		const newStart = new Date( dateRange.start );
		const modifier = direction === 'next' ? increment : -increment;
		newStart.setDate( newStart.getDate() + modifier );
		generateDateRange( newStart );
		setSelectedSlot( null );
	};

	// Handle filter selection (multi-select)
	const handleFilterSelection = useCallback(
		( key, values ) => {
			const newSelections = { ...selectedFilters, [ key ]: values };
			if ( values.length === 0 ) {
				delete newSelections[ key ];
			}
			setSelectedFilters( newSelections );
		},
		[ selectedFilters ]
	);

	// Reset all filters
	const handleResetFilters = useCallback( () => {
		setSelectedFilters( {} );
	}, [] );

	/**
	 * FIXED (2026-01-07): Do a broader search before declaring "no availability"
	 *
	 * This function searches ahead using the Generation Lookahead setting
	 * to find available slots. Only reports "no availability" if there are
	 * truly ZERO slots in that range.
	 *
	 * @return {Promise<Date|null>} - First available date, or null if none
	 */
	const findFirstAvailableInRange = async () => {
		const queryParams = new URLSearchParams();
		queryParams.append( '_wpnonce', restNonce );
		queryParams.append( 'hide_booked', 'true' );
		queryParams.append( 'check_hours_only', 'true' );

		// Use the Generation Lookahead setting for search range
		const lookaheadDays = parseInt( generationLookahead, 10 ) || 30;

		const startDate = new Date();
		const endDate = new Date();
		endDate.setDate( endDate.getDate() + lookaheadDays );

		queryParams.append( 'start', startDate.toISOString() );
		queryParams.append( 'end', endDate.toISOString() );

		// Add current filter selections
		Object.entries( selectedFilters ).forEach( ( [ key, value ] ) => {
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

	// Handle slot counts update from CompactTimeSlotGrid
	// FIXED (2026-01-07): Now does a broader search before showing "No Availability" modal
	const handleSlotCountsUpdate = useCallback(
		async ( counts ) => {
			setSlotCounts( counts );

			// Check for no availability on first fetch (only once)
			if (
				! hasCheckedAvailability &&
				! isCheckingBroaderAvailabilityRef.current
			) {
				setHasCheckedAvailability( true );

				// If total available slots is 0, do a broader search first
				if ( counts.total === 0 ) {
					isCheckingBroaderAvailabilityRef.current = true;

					const firstAvailable = await findFirstAvailableInRange();

					isCheckingBroaderAvailabilityRef.current = false;

					if ( firstAvailable ) {
						// Found slots in the future - show "Heads Up" modal and navigate
						const formattedDate = firstAvailable.toLocaleDateString(
							undefined,
							{
								weekday: 'long',
								year: 'numeric',
								month: 'long',
								day: 'numeric',
							}
						);
						setSmartDateModalMessage( formattedDate );
						setIsSmartDateModalOpen( true );

						// Navigate the grid to that date
						generateDateRange( firstAvailable );
					} else {
						// Truly no availability in the entire lookahead period
						setIsNoAvailabilityModalOpen( true );
					}
				}
			}
		},
		[
			hasCheckedAvailability,
			selectedFilters,
			restNonce,
			generationLookahead,
			generateDateRange,
		]
	);

	// Handle slot selection
	const handleSlotSelect = useCallback( ( event ) => {
		const newSelectedSlot = {
			event,
			identifier:
				event.extendedProps?.slotIdentifier || event.slotIdentifier,
			durationMinutes:
				event.extendedProps?.durationMinutes || event.durationMinutes,
			displayTime: event.displayTime,
			fullDescription: event.fullDescription,
			start: event.start,
			dimensions:
				event.extendedProps?.dimensions || event.dimensions || {},
			slotId: event.extendedProps?.slot_id || 0,
		};
		setSelectedSlot( newSelectedSlot );
		setSelectedSeats( [] );
		setIsConfirmationModalOpen( true );
	}, [] );

	// Handle slot deselection
	const handleSlotDeselect = useCallback( () => {
		setSelectedSlot( null );
		setSelectedSeats( [] );
		setResolvedVenueId( 0 );
		setIsConfirmationModalOpen( false );
	}, [] );

	// Handle seat selection changes
	const handleSeatsChange = useCallback( ( seats ) => {
		setSelectedSeats( seats );
	}, [] );

	const isReady = ! isLoadingFilters;

	return (
		<>
			{ /* Smart Start Date Modal */ }
			{ isSmartDateModalOpen && (
				<div
					className={ `clisyc-modal-overlay ${ textSizeClass }` }
					onClick={ ( e ) => {
						if ( e.target === e.currentTarget ) {
							setIsSmartDateModalOpen( false );
						}
					} }
					role="presentation"
				>
					<div
						className="clisyc-modal-content"
						role="dialog"
						aria-modal="true"
					>
						{ /* Calendar Icon */ }
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
							onClick={ () => setIsSmartDateModalOpen( false ) }
						>
							{ decodeHTMLEntities( l10n?.gotIt ) || 'Got it' }
						</button>
					</div>
				</div>
			) }

			{ /* No Availability Modal */ }
			{ isNoAvailabilityModalOpen && (
				<div
					className={ `clisyc-modal-overlay ${ textSizeClass }` }
					onClick={ ( e ) => {
						if ( e.target === e.currentTarget ) {
							setIsNoAvailabilityModalOpen( false );
						}
					} }
					role="presentation"
				>
					<div
						className="clisyc-modal-content"
						role="dialog"
						aria-modal="true"
					>
						{ /* Calendar Icon */ }
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
											marginRight: '8px',
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
									setIsNoAvailabilityModalOpen( false )
								}
							>
								{ decodeHTMLEntities( l10n?.close ) || 'Close' }
							</button>
						</div>
					</div>
				</div>
			) }

			<div
				className="clisyc-hybrid-booking-app clisyc-compact-booking-form-app"
				style={ appStyles }
			>
				<div
					className={ `clisyc-hybrid-layout ${
						! showFilters ? 'clisyc-hybrid-no-filters' : ''
					}` }
				>
					{ /* Filter Panel - conditionally rendered based on showFilters */ }
					{ showFilters && (
						<FilterPanel
							isLoading={ isLoadingFilters }
							options={ allFilterOptions }
							selections={ selectedFilters }
							onSelect={ handleFilterSelection }
							onReset={ handleResetFilters }
							availabilityDimensions={ availabilityDimensions }
							filterOrder={ filterOrder }
							l10n={ l10n }
							slotCounts={ slotCounts }
						/>
					) }

					{ /* Main Content Area */ }
					<div className="clisyc-hybrid-main-content">
						{ /* Selected Slot Display */ }
						<div
							id="clisyc_hybrid_selected_slot_display"
							className={
								selectedSlot
									? 'clisyc-hybrid-slot-selected'
									: 'none'
							}
						>
							<span className="clisyc-hybrid-slot-label">
								{ l10n?.selectedSlot || 'Selected Slot:' }
							</span>
							<span
								id="clisyc_hybrid_selected_slot"
								className="clisyc-hybrid-slot-value"
							>
								{ selectedSlot
									? selectedSlot.displayTime
									: l10n?.none || 'None' }
							</span>
						</div>

						{ /* Grid Header with Navigation */ }
						<div className="clisyc-hybrid-grid-header">
							<button
								type="button"
								className="clisyc-hybrid-nav-button"
								onClick={ () => navigateRange( 'prev' ) }
							>
								{ isMobileView
									? '‹ Prev Day'
									: '‹ Previous 7 Days' }
							</button>
							<div className="clisyc-hybrid-date-range-display">
								{ dateRange.start && dateRange.end && (
									<span className="clisyc-hybrid-range-text">
										{ dateRange.start.toLocaleDateString(
											[],
											{ month: 'short', day: 'numeric' }
										) }{ ' ' }
										-{ ' ' }
										{ new Date(
											dateRange.end - 86400000
										).toLocaleDateString( [], {
											month: 'short',
											day: 'numeric',
											year: 'numeric',
										} ) }
									</span>
								) }
							</div>
							<button
								type="button"
								className="clisyc-hybrid-nav-button"
								onClick={ () => navigateRange( 'next' ) }
							>
								{ isMobileView
									? 'Next Day ›'
									: 'Next 7 Days ›' }
							</button>
						</div>

						{ /* Time Slot Grid */ }
						{ isReady && dateRange.start ? (
							<CompactTimeSlotGrid
								selectedFilters={ selectedFilters }
								restUrl={ restUrl }
								selectedSlot={ selectedSlot }
								restNonce={ restNonce }
								onSlotSelect={ handleSlotSelect }
								onSlotDeselect={ handleSlotDeselect }
								onSlotCountsUpdate={ handleSlotCountsUpdate }
								dateRange={ dateRange }
								isMobileView={ isMobileView }
								filterOrder={ filterOrder }
								calendarOptions={ calendarOptions }
								l10n={ l10n }
								showBooked={ showBooked }
								showBlocked={ showBlocked }
							/>
						) : (
							// Standard centered spinner while the initial slot
							// fetch completes. Replaces a SkeletonTable that
							// rendered as a tall narrow column because the
							// grid-template-columns auto-fit fell back to a
							// single 80px column inside this wrapper.
							<div
								className="clisyc-hybrid-grid-loading"
								role="status"
								aria-label={
									l10n?.loading || 'Loading time slots'
								}
							>
								<div
									className="clisyc-spinner"
									aria-hidden="true"
								></div>
								<span className="screen-reader-text">
									{ l10n?.loading || 'Loading time slots' }
								</span>
							</div>
						) }

						{ /* Legend */ }
						<div className="clisyc-hybrid-legend">
							<span className="clisyc-hybrid-legend-item clisyc-legend-unavailable">
								{ l10n?.unavailable || 'Unavailable' }
							</span>
							{ showBlocked && (
								<span className="clisyc-hybrid-legend-item clisyc-legend-blocked">
									{ l10n?.blocked || 'Blocked' }
								</span>
							) }
							{ showBooked && (
								<span className="clisyc-hybrid-legend-item clisyc-legend-booked">
									{ l10n?.booked || 'Booked' }
								</span>
							) }
							<span className="clisyc-hybrid-legend-item clisyc-legend-available">
								{ l10n?.available || 'Available' }
							</span>
						</div>
					</div>
				</div>
			</div>

			{ /* Confirmation Modal */ }
			{ isConfirmationModalOpen && selectedSlot && (
				<div
					className="clisyc-hybrid-modal-overlay"
					onClick={ ( e ) => {
						if ( e.target === e.currentTarget ) {
							setIsConfirmationModalOpen( false );
						}
					} }
					role="presentation"
				>
					<div
						className="clisyc-hybrid-modal-content"
						role="dialog"
						aria-modal="true"
					>
						<form
							method="post"
							id="clisyc_appointment_form"
							action=""
							noValidate
						>
							<input
								type="hidden"
								name="_clisyc_frontend_booking_submit"
								value="1"
							/>
							<input
								type="hidden"
								name="booking_mode"
								value="slot"
							/>
							<input
								type="hidden"
								name="time_slot"
								value={ selectedSlot.identifier || '' }
							/>
							<input
								type="hidden"
								name="appointment_duration_minutes"
								value={ selectedSlot.durationMinutes || '' }
							/>
							<input
								type="hidden"
								name="appointment_date"
								value={
									selectedSlot.start
										? new Date( selectedSlot.start )
												.toISOString()
												.split( 'T' )[ 0 ]
										: ''
								}
							/>
							<input
								type="hidden"
								name="clisyc_appointment_nonce"
								value={ formNonce }
							/>

							{ selectedSlot.dimensions &&
								typeof selectedSlot.dimensions === 'object' &&
								Object.entries( selectedSlot.dimensions ).map(
									( [ key, value ] ) => (
										<input
											type="hidden"
											name={ key }
											value={ value }
											key={ key }
										/>
									)
								) }

							{ /* Seat selection hidden inputs */ }
							{ selectedSeats.length > 0 && (
								<>
									<input
										type="hidden"
										name="selected_seats"
										value={ JSON.stringify(
											selectedSeats
										) }
									/>
									<input
										type="hidden"
										name="seat_session_token"
										value={
											sessionStorage.getItem(
												'clisyc_seat_session_token'
											) || ''
										}
									/>
								</>
							) }

							<h3>
								{ l10n?.confirmBooking ||
									'Confirm Your Booking' }
							</h3>
							<div className="clisyc-confirmation-details">
								<p>
									<strong>You are booking:</strong>
								</p>
								<p>
									{ selectedSlot.fullDescription ||
										'No description available' }
								</p>
								<hr />
								<p>
									<strong>Time:</strong>{ ' ' }
									{ selectedSlot.displayTime ||
										'No time available' }
								</p>
							</div>

							{ /* Seat Map Picker — rendered when a venue is linked to this slot */ }
							{ resolvedVenueId > 0 &&
								selectedSlot.slotId > 0 && (
									<SeatMapPicker
										venueId={ resolvedVenueId }
										slotId={ selectedSlot.slotId }
										selectedSeats={ selectedSeats }
										onSeatsChange={ handleSeatsChange }
									/>
								) }

							<div className="clisyc-custom-fields-placeholder">
								<p>
									<em>
										Additional fields would appear here.
									</em>
								</p>
							</div>

							<button
								type="submit"
								className="clisyc-submit-button button button-primary"
								style={ { width: '100%', marginTop: '1em' } }
							>
								{ paymentRequired
									? l10n?.proceedToCheckout ||
									  'Proceed to Checkout'
									: l10n?.scheduleAppointment ||
									  'Schedule Appointment' }
							</button>
							<button
								type="button"
								className="clisyc-hybrid-modal-close"
								onClick={ () =>
									setIsConfirmationModalOpen( false )
								}
								style={ { marginTop: '10px' } }
							>
								{ l10n?.cancel || 'Cancel' }
							</button>
						</form>
					</div>
				</div>
			) }
		</>
	);
};

export default HybridApp;
