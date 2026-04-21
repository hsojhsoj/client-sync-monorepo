/**
 * File: src/assets/src/faceted-booking/App.js
 * The main React component for the Faceted Search Booking Form.
 *
 * FIXES:
 * - Correct time_slot format for PHP backend (UTC datetime string)
 * - Added guest registration fields when user is not logged in
 * - Added isGuest detection from localized data
 *
 * FEATURES:
 * - Faceted Search Interface filtering (show all, narrow with selections)
 * - Result counts on filter options
 * - Total available slots counter
 * - Timezone display with current time
 * - Toggle to show/hide booked slots
 * - Booking mode detection (slot vs date_range)
 *
 * @package
 * @subpackage ClientSync/Assets/JS/FacetedBooking
 */
import React, {
	useState,
	useCallback,
	useEffect,
	useMemo,
	useRef,
} from 'react';
import FacetedFilterPanel from './FacetedFilterPanel';
import TimeSlotGrid from './TimeSlotGrid';
import DateRangeBooking from './DateRangeBooking';
import apiFetch from '@wordpress/api-fetch';
import { SkeletonFilters, SkeletonCard } from '../shared/SkeletonLoader';
import '../shared/skeleton-loader.css';

const App = () => {
	const [ isLoading, setIsLoading ] = useState( true );
	const [ allFilterOptions, setAllFilterOptions ] = useState( {} );
	const [ selectedFilters, setSelectedFilters ] = useState( {} );
	const [ availableSlots, setAvailableSlots ] = useState( [] );
	const [ allSlots, setAllSlots ] = useState( [] );
	const [ selectedSlot, setSelectedSlot ] = useState( null );
	const [ isConfirmationModalOpen, setIsConfirmationModalOpen ] =
		useState( false );
	const [ showBookedSlots, setShowBookedSlots ] = useState( false );
	const [ currentTime, setCurrentTime ] = useState( new Date() );
	const fetchAbortRef = useRef( null );

	// Guest registration form state
	const [ guestForm, setGuestForm ] = useState( {
		firstName: '',
		lastName: '',
		email: '',
		password: '',
	} );
	const [ formErrors, setFormErrors ] = useState( [] );

	const {
		restUrl,
		restNonce,
		filterOrder,
		formNonce,
		l10n,
		paymentRequired,
		siteTimezone,
		isGuest, // From PHP: whether user is logged in
		calendarOptions,
	} = window.clisycFacetedBookingData;

	// Get timezone from calendarOptions or browser
	const timezone =
		calendarOptions?.timeZone ||
		Intl.DateTimeFormat().resolvedOptions().timeZone;

	// Update current time every minute
	useEffect( () => {
		const timer = setInterval( () => {
			setCurrentTime( new Date() );
		}, 60000 );
		return () => clearInterval( timer );
	}, [] );

	// Determine the primary dimension key from registry
	const primaryDimKey = useMemo( () => {
		const registry = window.clisycFacetedBookingData?.registry;
		if ( registry?.dimensions ) {
			for ( const [ slug, settings ] of Object.entries(
				registry.dimensions
			) ) {
				if ( settings.primary ) {
					return slug;
				}
			}
		}
		return filterOrder?.[ 0 ] || null;
	}, [ filterOrder ] );

	// Get currently selected primary dimension item info
	const selectedPrimaryItem = useMemo( () => {
		if ( ! primaryDimKey || ! selectedFilters[ primaryDimKey ]?.length ) {
			return null;
		}

		const selectedId = selectedFilters[ primaryDimKey ][ 0 ];
		const options = allFilterOptions[ primaryDimKey ] || [];
		return (
			options.find(
				( opt ) => String( opt.value ) === String( selectedId )
			) || null
		);
	}, [ primaryDimKey, selectedFilters, allFilterOptions ] );

	// Determine booking mode
	const bookingMode = useMemo( () => {
		if ( selectedPrimaryItem?.booking_mode ) {
			return selectedPrimaryItem.booking_mode;
		}
		const primaryOptions = allFilterOptions[ primaryDimKey ] || [];
		if ( primaryOptions.length > 0 ) {
			const allDateRange = primaryOptions.every(
				( opt ) => opt.booking_mode === 'date_range'
			);
			if ( allDateRange ) {
				return 'date_range';
			}
		}
		return 'slot';
	}, [ selectedPrimaryItem, allFilterOptions, primaryDimKey ] );

	// Check if any filters are currently selected
	const hasActiveFilters = useMemo( () => {
		return Object.values( selectedFilters ).some(
			( val ) => Array.isArray( val ) && val.length > 0
		);
	}, [ selectedFilters ] );

	// Calculate slot counts for each filter option
	const slotCountsByFilter = useMemo( () => {
		const counts = {};

		filterOrder.forEach( ( dimKey ) => {
			counts[ dimKey ] = {};
			const options = allFilterOptions[ dimKey ] || [];

			options.forEach( ( opt ) => {
				const matchingSlots = allSlots.filter( ( slot ) => {
					if ( slot.extendedProps?.is_block ) {
						return false;
					}
					if ( ! showBookedSlots && slot.extendedProps?.isBooked ) {
						return false;
					}
					if (
						! showBookedSlots &&
						slot.extendedProps?.spotsLeft === 0
					) {
						return false;
					}

					const slotDimensions = slot.extendedProps?.dimensions || {};
					const dimValue = slotDimensions[ dimKey ];

					if ( String( dimValue ) !== String( opt.value ) ) {
						return false;
					}

					for ( const [ filterKey, filterValues ] of Object.entries(
						selectedFilters
					) ) {
						if ( filterKey === dimKey ) {
							continue;
						}
						if ( ! filterValues || filterValues.length === 0 ) {
							continue;
						}

						const slotValue = slotDimensions[ filterKey ];
						if ( ! filterValues.includes( String( slotValue ) ) ) {
							return false;
						}
					}

					return true;
				} );

				counts[ dimKey ][ opt.value ] = matchingSlots.length;
			} );
		} );

		return counts;
	}, [
		allSlots,
		allFilterOptions,
		filterOrder,
		selectedFilters,
		showBookedSlots,
	] );

	const fetchData = useCallback(
		async ( currentSelections ) => {
			// Abort any in-flight request so stale responses don't overwrite fresh data.
			if ( fetchAbortRef.current ) {
				fetchAbortRef.current.abort();
			}
			const controller = new AbortController();
			fetchAbortRef.current = controller;

			setIsLoading( true );
			try {
				const queryParams = new URLSearchParams( {
					_wpnonce: restNonce,
				} );
				Object.entries( currentSelections ).forEach(
					( [ key, values ] ) => {
						if ( Array.isArray( values ) ) {
							values.forEach( ( v ) =>
								queryParams.append( `${ key }[]`, v )
							);
						}
					}
				);

				const optionsResponse = await apiFetch( {
					path: `/clisyc/v1/filter-options?${ queryParams.toString() }`,
					signal: controller.signal,
				} );
				setAllFilterOptions( optionsResponse );

				const primaryKey = filterOrder?.[ 0 ];
				const selectedPrimaryId =
					currentSelections[ primaryKey ]?.[ 0 ];

				let itemBookingMode = 'slot';
				if ( selectedPrimaryId && optionsResponse[ primaryKey ] ) {
					const item = optionsResponse[ primaryKey ].find(
						( opt ) =>
							String( opt.value ) === String( selectedPrimaryId )
					);
					itemBookingMode = item?.booking_mode || 'slot';
				} else {
					const primaryOptions = optionsResponse[ primaryKey ] || [];
					if ( primaryOptions.length > 0 ) {
						const allDateRange = primaryOptions.every(
							( opt ) => opt.booking_mode === 'date_range'
						);
						if ( allDateRange ) {
							itemBookingMode = 'date_range';
						}
					}
				}

				if ( itemBookingMode === 'slot' ) {
					const isFirstLoad =
						Object.keys( currentSelections ).length === 0;

					const slotsResponse = await apiFetch( {
						path: `/clisyc/v1/slots?${ queryParams.toString() }&start=${ new Date().toISOString() }&end=${ new Date(
							new Date().setDate( new Date().getDate() + 90 )
						).toISOString() }`,
						signal: controller.signal,
					} );

					const slots = slotsResponse.events || [];
					setAvailableSlots( slots );

					if ( isFirstLoad ) {
						setAllSlots( slots );
					}
				} else {
					setAvailableSlots( [] );
					setAllSlots( [] );
				}
			} catch ( error ) {
				if ( error.name === 'AbortError' ) {
					return;
				}
				console.error( 'Failed to fetch booking data:', error );
				setAllFilterOptions( {} );
				setAvailableSlots( [] );
			} finally {
				setIsLoading( false );
			}
		},
		[ restNonce, filterOrder ]
	);

	useEffect( () => {
		fetchData( {} );
	}, [ fetchData ] );

	const handleFilterSelection = useCallback(
		( key, values ) => {
			const newSelections = { ...selectedFilters, [ key ]: values };
			if ( values.length === 0 ) {
				delete newSelections[ key ];
			}
			setSelectedFilters( newSelections );
			fetchData( newSelections );
		},
		[ selectedFilters, fetchData ]
	);

	const handleResetFilters = useCallback( () => {
		setSelectedFilters( {} );
		setSelectedSlot( null );
		fetchData( {} );
	}, [ fetchData ] );

	const handleSlotSelect = useCallback( ( slotData ) => {
		setSelectedSlot( slotData );
		setIsConfirmationModalOpen( true );
		setFormErrors( [] );
	}, [] );

	const handleToggleBookedSlots = useCallback( () => {
		setShowBookedSlots( ( prev ) => ! prev );
	}, [] );

	const handleGuestFormChange = useCallback( ( field, value ) => {
		setGuestForm( ( prev ) => ( { ...prev, [ field ]: value } ) );
	}, [] );

	// Validate guest form before submission
	const validateGuestForm = useCallback( () => {
		const errors = [];
		if ( ! guestForm.firstName.trim() ) {
			errors.push( l10n?.firstNameRequired || 'First name is required.' );
		}
		if ( ! guestForm.lastName.trim() ) {
			errors.push( l10n?.lastNameRequired || 'Last name is required.' );
		}
		if ( ! guestForm.email.trim() ) {
			errors.push( l10n?.emailRequired || 'Email is required.' );
		} else if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( guestForm.email ) ) {
			errors.push(
				l10n?.emailInvalid || 'Please enter a valid email address.'
			);
		}
		if ( ! guestForm.password ) {
			errors.push( l10n?.passwordRequired || 'Password is required.' );
		} else if ( guestForm.password.length < 8 ) {
			errors.push(
				l10n?.passwordTooShort ||
					'Password must be at least 8 characters.'
			);
		}
		setFormErrors( errors );
		return errors.length === 0;
	}, [ guestForm, l10n ] );

	const handleFormSubmit = useCallback(
		( e ) => {
			// If guest, validate the registration form
			if ( isGuest && ! validateGuestForm() ) {
				e.preventDefault();
				return false;
			}
			return true;
		},
		[ isGuest, validateGuestForm ]
	);

	// Format timezone display
	const timezoneDisplay = useMemo( () => {
		const tz = timezone;
		const timeString = currentTime.toLocaleTimeString( [], {
			hour: 'numeric',
			minute: '2-digit',
			timeZone: tz,
		} );
		const tzAbbr = currentTime
			.toLocaleTimeString( 'en-US', {
				timeZoneName: 'short',
				timeZone: tz,
			} )
			.split( ' ' )
			.pop();

		return { time: timeString, timezone: tzAbbr, fullTimezone: tz };
	}, [ currentTime, timezone ] );

	/**
	 * CRITICAL FIX: Format the time_slot value for PHP backend.
	 * The backend expects UTC datetime string format: "2025-12-29 17:00:00"
	 * The API returns ISO format: "2025-12-29T17:00:00.000Z"
	 */
	const formatTimeSlotForBackend = useCallback( ( isoDateString ) => {
		if ( ! isoDateString ) {
			return '';
		}
		// Convert ISO to UTC datetime format expected by PHP
		const date = new Date( isoDateString );
		return date
			.toISOString()
			.replace( 'T', ' ' )
			.replace( /\.\d{3}Z$/, '' );
	}, [] );

	// Render the appropriate booking interface
	const renderBookingInterface = () => {
		if ( bookingMode === 'date_range' ) {
			if ( ! selectedPrimaryItem ) {
				return (
					<div className="clisyc-booking-placeholder">
						<p>
							{ l10n?.selectPropertyForCalendar ||
								'Please select a property to view the availability calendar.' }
						</p>
					</div>
				);
			}
			return (
				<DateRangeBooking
					isLoading={ isLoading }
					primaryItem={ selectedPrimaryItem }
					selectedFilters={ selectedFilters }
					formNonce={ formNonce }
					l10n={ l10n }
					paymentRequired={ paymentRequired }
				/>
			);
		}

		return (
			<TimeSlotGrid
				isLoading={ isLoading }
				slots={ availableSlots }
				onSlotSelect={ handleSlotSelect }
				hasActiveFilters={ hasActiveFilters }
				showBookedSlots={ showBookedSlots }
				onToggleBookedSlots={ handleToggleBookedSlots }
				timezoneDisplay={ timezoneDisplay }
			/>
		);
	};

	return (
		<>
			<div className="clisyc-faceted-booking-app">
				<div className="clisyc-faceted-search-layout">
					<FacetedFilterPanel
						isLoading={ isLoading }
						options={ allFilterOptions }
						selections={ selectedFilters }
						onSelect={ handleFilterSelection }
						onReset={ handleResetFilters }
						slotCounts={ slotCountsByFilter }
					/>
					<div className="clisyc-main-content-area">
						{ renderBookingInterface() }
					</div>
				</div>
			</div>

			{ /* Slot-based confirmation modal */ }
			{ isConfirmationModalOpen &&
				selectedSlot &&
				bookingMode === 'slot' && (
					<div
						className="clisyc-compact-modal-overlay"
						onClick={ () => setIsConfirmationModalOpen( false ) }
					>
						<div
							className="clisyc-compact-modal-content clisyc-booking-modal"
							onClick={ ( e ) => e.stopPropagation() }
						>
							<form
								method="post"
								id="clisyc_appointment_form"
								action=""
								noValidate
								onSubmit={ handleFormSubmit }
							>
								{ /* Hidden fields for booking */ }
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
								{ /* CRITICAL FIX: Format time_slot correctly for PHP backend */ }
								<input
									type="hidden"
									name="time_slot"
									value={ formatTimeSlotForBackend(
										selectedSlot.start
									) }
								/>
								<input
									type="hidden"
									name="appointment_duration_minutes"
									value={ selectedSlot.durationMinutes || 60 }
								/>
								<input
									type="hidden"
									name="appointment_date"
									value={
										new Date( selectedSlot.start )
											.toISOString()
											.split( 'T' )[ 0 ]
									}
								/>
								<input
									type="hidden"
									name="clisyc_appointment_nonce"
									value={ formNonce }
								/>

								{ /* Dimension fields */ }
								{ selectedSlot.dimensions &&
									Object.entries(
										selectedSlot.dimensions
									).map( ( [ key, value ] ) => (
										<input
											type="hidden"
											name={ key }
											value={ value }
											key={ key }
										/>
									) ) }

								<h3>
									{ l10n?.confirmBooking ||
										'Confirm Your Booking' }
								</h3>

								<div className="clisyc-confirmation-details">
									<p>
										<strong>
											{ l10n?.youAreBooking ||
												'You are booking:' }
										</strong>
									</p>
									<p>{ selectedSlot.fullDescription }</p>
									<hr />
									<p>
										<strong>
											{ l10n?.time || 'Time' }:
										</strong>{ ' ' }
										{ new Date(
											selectedSlot.start
										).toLocaleDateString( [], {
											weekday: 'long',
											month: 'long',
											day: 'numeric',
										} ) }{ ' ' }
										at{ ' ' }
										{ new Date(
											selectedSlot.start
										).toLocaleTimeString( [], {
											hour: 'numeric',
											minute: '2-digit',
										} ) }
									</p>
								</div>

								{ /* CRITICAL FIX: Guest registration fields */ }
								{ isGuest && (
									<div className="clisyc-guest-registration-section">
										<h4>
											{ l10n?.createAccount ||
												'Create Your Account' }
										</h4>
										<p className="clisyc-guest-info">
											{ l10n?.guestAccountInfo ||
												'Enter your details to create an account and complete your booking.' }
										</p>

										{ formErrors.length > 0 && (
											<div className="clisyc-form-errors">
												{ formErrors.map(
													( error, index ) => (
														<p
															key={ index }
															className="clisyc-error-message"
														>
															{ error }
														</p>
													)
												) }
											</div>
										) }

										<div className="clisyc-form-row">
											<div className="clisyc-form-field">
												<label htmlFor="clisyc_reg_first_name">
													{ l10n?.firstName ||
														'First Name' }{ ' ' }
													<span className="required">
														*
													</span>
												</label>
												<input
													type="text"
													id="clisyc_reg_first_name"
													name="clisyc_reg_first_name"
													value={
														guestForm.firstName
													}
													onChange={ ( e ) =>
														handleGuestFormChange(
															'firstName',
															e.target.value
														)
													}
													required
												/>
											</div>
											<div className="clisyc-form-field">
												<label htmlFor="clisyc_reg_last_name">
													{ l10n?.lastName ||
														'Last Name' }{ ' ' }
													<span className="required">
														*
													</span>
												</label>
												<input
													type="text"
													id="clisyc_reg_last_name"
													name="clisyc_reg_last_name"
													value={ guestForm.lastName }
													onChange={ ( e ) =>
														handleGuestFormChange(
															'lastName',
															e.target.value
														)
													}
													required
												/>
											</div>
										</div>

										<div className="clisyc-form-field">
											<label htmlFor="clisyc_reg_email">
												{ l10n?.email ||
													'Email Address' }{ ' ' }
												<span className="required">
													*
												</span>
											</label>
											<input
												type="email"
												id="clisyc_reg_email"
												name="clisyc_reg_email"
												value={ guestForm.email }
												onChange={ ( e ) =>
													handleGuestFormChange(
														'email',
														e.target.value
													)
												}
												required
											/>
										</div>

										<div className="clisyc-form-field">
											<label htmlFor="clisyc_reg_password">
												{ l10n?.password || 'Password' }{ ' ' }
												<span className="required">
													*
												</span>
											</label>
											<input
												type="password"
												id="clisyc_reg_password"
												name="clisyc_reg_password"
												value={ guestForm.password }
												onChange={ ( e ) =>
													handleGuestFormChange(
														'password',
														e.target.value
													)
												}
												minLength={ 8 }
												required
											/>
											<span className="clisyc-field-hint">
												{ l10n?.passwordHint ||
													'Minimum 8 characters' }
											</span>
										</div>
									</div>
								) }

								<div className="clisyc-modal-actions">
									<button
										type="submit"
										className="clisyc-submit-button button button-primary"
									>
										{ paymentRequired
											? l10n?.proceedToCheckout ||
											  'Proceed to Checkout'
											: l10n?.scheduleAppointment ||
											  'Schedule Appointment' }
									</button>
									<button
										type="button"
										className="clisyc-compact-modal-close"
										onClick={ () =>
											setIsConfirmationModalOpen( false )
										}
									>
										{ l10n?.cancel || 'Cancel' }
									</button>
								</div>
							</form>
						</div>
					</div>
				) }
		</>
	);
};

export default App;
