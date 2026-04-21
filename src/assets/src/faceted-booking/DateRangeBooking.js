/**
 * File: src/assets/src/faceted-booking/DateRangeBooking.js
 * Component for date-range booking mode (vacation rentals, etc.)
 * Shows a date picker calendar with booked dates disabled.
 *
 * @package
 * @subpackage ClientSync/Assets/JS/FacetedBooking
 */
import React, { useState, useEffect, useCallback, useRef } from 'react';
import apiFetch from '@wordpress/api-fetch';

const DateRangeBooking = ( {
	isLoading: parentLoading,
	primaryItem,
	selectedFilters,
	formNonce,
	l10n,
	paymentRequired,
} ) => {
	const [ bookedDates, setBookedDates ] = useState( [] );
	const [ isLoadingDates, setIsLoadingDates ] = useState( false );
	const [ checkInDate, setCheckInDate ] = useState( '' );
	const [ checkOutDate, setCheckOutDate ] = useState( '' );
	const [ validationError, setValidationError ] = useState( '' );
	const [ isConfirmationOpen, setIsConfirmationOpen ] = useState( false );
	const pickerRef = useRef( null );
	const pickerInstanceRef = useRef( null );

	// Extract item configuration
	const itemId = primaryItem?.value;
	const itemTitle = primaryItem?.label || 'Property';
	const minStay = primaryItem?.min_stay || 1;
	const maxStay = primaryItem?.max_stay || 0;
	const allowedCheckinDays = primaryItem?.checkin_days || [
		0, 1, 2, 3, 4, 5, 6,
	];
	const allowedCheckoutDays = primaryItem?.checkout_days || [
		0, 1, 2, 3, 4, 5, 6,
	];

	// Fetch booked dates when primary item changes
	useEffect( () => {
		if ( ! itemId ) {
			return;
		}

		const fetchBookedDates = async () => {
			setIsLoadingDates( true );
			try {
				const startDate = new Date().toISOString();
				const endDate = new Date(
					Date.now() + 365 * 24 * 60 * 60 * 1000
				).toISOString(); // 1 year ahead

				const response = await apiFetch( {
					path: `/clisyc/v1/slots/booked-dates?dimension_id=${ itemId }&start=${ startDate }&end=${ endDate }`,
				} );

				setBookedDates( response || [] );
			} catch ( error ) {
				console.error( 'Failed to fetch booked dates:', error );
				setBookedDates( [] );
			} finally {
				setIsLoadingDates( false );
			}
		};

		fetchBookedDates();
	}, [ itemId ] );

	// Initialize Litepicker when booked dates are loaded
	useEffect( () => {
		if ( ! pickerRef.current || isLoadingDates || ! window.Litepicker ) {
			return;
		}

		// Destroy existing instance
		if ( pickerInstanceRef.current ) {
			pickerInstanceRef.current.destroy();
		}

		const today = new Date();
		today.setHours( 0, 0, 0, 0 );

		// Create set of booked dates for quick lookup
		const bookedSet = new Set( bookedDates );

		pickerInstanceRef.current = new window.Litepicker( {
			element: pickerRef.current,
			singleMode: false,
			numberOfMonths: 2,
			numberOfColumns: 2,
			minDate: today,
			minDays: minStay + 1, // +1 because Litepicker counts nights
			maxDays: maxStay > 0 ? maxStay + 1 : null,
			autoApply: true,
			showTooltip: true,
			tooltipNumber: ( totalDays ) => totalDays - 1, // Show nights instead of days
			format: 'YYYY-MM-DD',

			// Lock booked dates
			lockDays: bookedDates,

			// Disable ranges that would overlap booked dates
			disallowLockDaysInRange: true,

			// Custom day rendering for allowed check-in/check-out days
			lockDaysFilter: ( date1, date2, pickedDates ) => {
				// If this is for check-in selection (no dates picked yet)
				if ( pickedDates.length === 0 ) {
					const dayOfWeek = date1.getDay();
					// Lock if not an allowed check-in day
					if ( ! allowedCheckinDays.includes( dayOfWeek ) ) {
						return true;
					}
				}
				// If this is for check-out selection (one date picked)
				if ( pickedDates.length === 1 ) {
					const dayOfWeek = date1.getDay();
					// Lock if not an allowed check-out day
					if ( ! allowedCheckoutDays.includes( dayOfWeek ) ) {
						return true;
					}
				}
				return false;
			},

			setup: ( picker ) => {
				picker.on( 'selected', ( startDate, endDate ) => {
					if ( startDate && endDate ) {
						const startStr = startDate.format( 'YYYY-MM-DD' );
						const endStr = endDate.format( 'YYYY-MM-DD' );

						// Validate the selection
						const error = validateDateRange(
							startStr,
							endStr,
							bookedSet
						);
						if ( error ) {
							setValidationError( error );
							setCheckInDate( '' );
							setCheckOutDate( '' );
						} else {
							setValidationError( '' );
							setCheckInDate( startStr );
							setCheckOutDate( endStr );
						}
					}
				} );

				picker.on( 'clear:selection', () => {
					setCheckInDate( '' );
					setCheckOutDate( '' );
					setValidationError( '' );
				} );
			},
		} );

		return () => {
			if ( pickerInstanceRef.current ) {
				pickerInstanceRef.current.destroy();
			}
		};
	}, [
		bookedDates,
		isLoadingDates,
		minStay,
		maxStay,
		allowedCheckinDays,
		allowedCheckoutDays,
	] );

	// Validate date range against booked dates
	const validateDateRange = useCallback(
		( startStr, endStr, bookedSet ) => {
			const start = new Date( startStr );
			const end = new Date( endStr );

			// Check each day in the range for conflicts
			const current = new Date( start );
			while ( current < end ) {
				const dateStr = current.toISOString().split( 'T' )[ 0 ];
				if ( bookedSet.has( dateStr ) ) {
					return (
						l10n?.dateRangeConflict ||
						'Selected dates overlap with an existing booking.'
					);
				}
				current.setDate( current.getDate() + 1 );
			}

			// Calculate nights
			const nights = Math.round(
				( end - start ) / ( 1000 * 60 * 60 * 24 )
			);

			if ( nights < minStay ) {
				return `${
					l10n?.minStayRequired || 'Minimum stay is'
				} ${ minStay } ${ minStay === 1 ? 'night' : 'nights' }.`;
			}

			if ( maxStay > 0 && nights > maxStay ) {
				return `${
					l10n?.maxStayExceeded || 'Maximum stay is'
				} ${ maxStay } ${ maxStay === 1 ? 'night' : 'nights' }.`;
			}

			return null;
		},
		[ minStay, maxStay, l10n ]
	);

	const handleBookNow = () => {
		if ( ! checkInDate || ! checkOutDate ) {
			setValidationError(
				l10n?.selectDates ||
					'Please select check-in and check-out dates.'
			);
			return;
		}
		setIsConfirmationOpen( true );
	};

	const formatDateDisplay = ( dateStr ) => {
		if ( ! dateStr ) {
			return '';
		}
		const date = new Date( dateStr + 'T12:00:00' ); // Noon to avoid timezone issues
		return date.toLocaleDateString( undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			year: 'numeric',
		} );
	};

	const calculateNights = () => {
		if ( ! checkInDate || ! checkOutDate ) {
			return 0;
		}
		const start = new Date( checkInDate );
		const end = new Date( checkOutDate );
		return Math.round( ( end - start ) / ( 1000 * 60 * 60 * 24 ) );
	};

	// Get the primary dimension key for form submission
	const primaryDimKey =
		Object.keys( selectedFilters ).find(
			( key ) =>
				selectedFilters[ key ]?.includes( parseInt( itemId ) ) ||
				selectedFilters[ key ]?.includes( String( itemId ) )
		) || 'clisyc_property';

	const isLoading = parentLoading || isLoadingDates;

	return (
		<div className="clisyc-date-range-booking">
			<div className="clisyc-date-range-header">
				<h3>{ itemTitle }</h3>
				{ minStay > 1 && (
					<p className="clisyc-stay-requirement">
						{ l10n?.minimumStay || 'Minimum stay:' } { minStay }{ ' ' }
						{ minStay === 1 ? 'night' : 'nights' }
					</p>
				) }
			</div>

			{ isLoading ? (
				<div className="clisyc-loading-indicator">
					<span
						className="spinner is-active"
						style={ { float: 'none', margin: '0 10px 0 0' } }
					></span>
					{ l10n?.loadingAvailability || 'Loading availability...' }
				</div>
			) : (
				<>
					<div className="clisyc-date-picker-container">
						<label>
							{ l10n?.selectDates || 'Select your dates:' }
						</label>
						<input
							ref={ pickerRef }
							type="text"
							className="clisyc-date-range-input"
							placeholder={
								l10n?.checkInCheckOut || 'Check-in → Check-out'
							}
							readOnly
						/>
					</div>

					{ validationError && (
						<div className="clisyc-validation-error">
							<span className="dashicons dashicons-warning"></span>
							{ validationError }
						</div>
					) }

					{ checkInDate && checkOutDate && ! validationError && (
						<div className="clisyc-selected-dates-summary">
							<div className="clisyc-date-summary-row">
								<span className="clisyc-date-label">
									{ l10n?.checkIn || 'Check-in:' }
								</span>
								<span className="clisyc-date-value">
									{ formatDateDisplay( checkInDate ) }
								</span>
							</div>
							<div className="clisyc-date-summary-row">
								<span className="clisyc-date-label">
									{ l10n?.checkOut || 'Check-out:' }
								</span>
								<span className="clisyc-date-value">
									{ formatDateDisplay( checkOutDate ) }
								</span>
							</div>
							<div className="clisyc-date-summary-row clisyc-nights-total">
								<span className="clisyc-date-label">
									{ l10n?.totalNights || 'Total:' }
								</span>
								<span className="clisyc-date-value">
									{ calculateNights() }{ ' ' }
									{ calculateNights() === 1
										? 'night'
										: 'nights' }
								</span>
							</div>
						</div>
					) }

					<button
						type="button"
						className="clisyc-submit-button button button-primary"
						onClick={ handleBookNow }
						disabled={
							! checkInDate ||
							! checkOutDate ||
							!! validationError
						}
					>
						{ l10n?.bookNow || 'Book Now' }
					</button>

					<div className="clisyc-calendar-legend">
						<span className="clisyc-legend-item clisyc-legend-available">
							<span className="clisyc-legend-dot"></span>
							{ l10n?.available || 'Available' }
						</span>
						<span className="clisyc-legend-item clisyc-legend-booked">
							<span className="clisyc-legend-dot"></span>
							{ l10n?.booked || 'Booked' }
						</span>
					</div>
				</>
			) }

			{ /* Confirmation Modal */ }
			{ isConfirmationOpen && (
				<div
					className="clisyc-compact-modal-overlay"
					onClick={ () => setIsConfirmationOpen( false ) }
				>
					<div
						className="clisyc-compact-modal-content"
						onClick={ ( e ) => e.stopPropagation() }
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
								value="date_range"
							/>
							<input
								type="hidden"
								name="start_date"
								value={ checkInDate }
							/>
							<input
								type="hidden"
								name="end_date"
								value={ checkOutDate }
							/>
							<input
								type="hidden"
								name={ primaryDimKey }
								value={ itemId }
							/>
							<input
								type="hidden"
								name="clisyc_appointment_nonce"
								value={ formNonce }
							/>

							{ /* Include other selected filters */ }
							{ Object.entries( selectedFilters ).map(
								( [ key, values ] ) => {
									if ( key === primaryDimKey ) {
										return null;
									}
									return values.map( ( value, idx ) => (
										<input
											type="hidden"
											name={ key }
											value={ value }
											key={ `${ key }-${ idx }` }
										/>
									) );
								}
							) }

							<h3>
								{ l10n?.confirmBooking ||
									'Confirm Your Booking' }
							</h3>
							<div className="clisyc-confirmation-details">
								<p>
									<strong>{ itemTitle }</strong>
								</p>
								<hr />
								<div className="clisyc-confirmation-dates">
									<p>
										<strong>
											{ l10n?.checkIn || 'Check-in:' }
										</strong>{ ' ' }
										{ formatDateDisplay( checkInDate ) }
									</p>
									<p>
										<strong>
											{ l10n?.checkOut || 'Check-out:' }
										</strong>{ ' ' }
										{ formatDateDisplay( checkOutDate ) }
									</p>
									<p className="clisyc-nights-highlight">
										<strong>{ calculateNights() }</strong>{ ' ' }
										{ calculateNights() === 1
											? 'night'
											: 'nights' }
									</p>
								</div>
							</div>

							<button
								type="submit"
								className="clisyc-submit-button button button-primary"
								style={ { width: '100%', marginTop: '1em' } }
							>
								{ paymentRequired
									? l10n?.proceedToCheckout ||
									  'Proceed to Checkout'
									: l10n?.confirmReservation ||
									  'Confirm Reservation' }
							</button>
							<button
								type="button"
								className="clisyc-compact-modal-close"
								onClick={ () => setIsConfirmationOpen( false ) }
								style={ { marginTop: '10px' } }
							>
								{ l10n?.cancel || 'Cancel' }
							</button>
						</form>
					</div>
				</div>
			) }
		</div>
	);
};

export default DateRangeBooking;
