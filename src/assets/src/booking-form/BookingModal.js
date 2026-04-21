/**
 * File: src/assets/src/booking-form/BookingModal.js
 * Modal component for confirming and submitting bookings.
 *
 * UPDATED: Now supports both booking modes:
 * - 'slot' mode: Time-based bookings
 * - 'date_range' mode: Date-based bookings (rentals, accommodations)
 *
 * FIX: Enhanced close button handler with stopPropagation to ensure reliable closing.
 * FIX: Redirect URL now includes query params required by [clisyc_booking_confirmation] shortcode.
 *
 * FIXED (2026-01-07): Success message now shows appropriate text based on WooCommerce payment status:
 * - When payment required: "Proceeding to Checkout" / "Your slot is reserved. Complete payment to confirm."
 * - When payment NOT required: "Booking Confirmed!" / "Your appointment has been successfully booked."
 *
 * UPDATED: Added Detailed Invoice Breakdown with inline styles.
 * UPDATED: Added recurring series support (Pro only).
 */
import React, { useState, useEffect, useRef } from 'react';
import apiFetch from '@wordpress/api-fetch';
import RecurringOptions from './RecurringOptions';
import SeatMapPicker from './SeatMapPicker';

const BookingModal = ( {
	isOpen,
	onClose,
	selectedSlot,
	selectedDateRange,
	selectedFilters,
	selectedPrimaryItem,
	formNonce,
	waitlistNonce,
	isGuest,
	customFieldDefinitions,
	successPageUrl,
	bookingMode = 'slot',
	basket,
	isCalculatingPrice,
	selectedSeats = [],
	onSeatsChange,
	linkedVenueId = 0,
} ) => {
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ submitStatus, setSubmitStatus ] = useState( null );
	const [ errorMessage, setErrorMessage ] = useState( '' );
	const [ customFieldValues, setCustomFieldValues ] = useState( {} );
	const [ guestEmail, setGuestEmail ] = useState( '' );
	const [ guestName, setGuestName ] = useState( '' );
	const [ waitlistOffer, setWaitlistOffer ] = useState( null ); // { slot_id, position }
	const [ isJoiningWaitlist, setIsJoiningWaitlist ] = useState( false );
	const [ isRecurring, setIsRecurring ] = useState( false );
	const [ recurringFrequency, setRecurringFrequency ] = useState( 'weekly' );
	const [ recurringCount, setRecurringCount ] = useState( 4 );
	const [ resolvedVenueId, setResolvedVenueId ] = useState( 0 );
	const modalRef = useRef( null );

	const {
		availabilityDimensions,
		filterOrder,
		wc_integration_enabled: wcIntegrationEnabled,
		stripe_integration_enabled: stripeIntegrationEnabled,
		waitlist_enabled: waitlistEnabled,
		proRecurringEnabled,
		restRootUrl,
		restNonce,
	} = window.clisycBookingFormData || {};
	const isDateRangeMode = bookingMode === 'date_range';

	// The effective venue ID: prefer prop from shortcode config, fall back to dynamic resolution.
	const effectiveVenueId = linkedVenueId || resolvedVenueId;

	// Dynamically resolve venue ID from slot when not pre-configured.
	useEffect( () => {
		if ( linkedVenueId > 0 || ! selectedSlot || isDateRangeMode ) {
			setResolvedVenueId( 0 );
			return;
		}

		const slotId = selectedSlot.event?.extendedProps?.slot_id;
		if ( ! slotId ) {
			setResolvedVenueId( 0 );
			return;
		}

		apiFetch( { path: `/clisyc/v1/seats/resolve/${ slotId }` } )
			.then( ( res ) => setResolvedVenueId( res.venue_id || 0 ) )
			.catch( () => setResolvedVenueId( 0 ) );
	}, [ selectedSlot, linkedVenueId, isDateRangeMode ] );

	// Determine if payment is required (WooCommerce or Stripe integration enabled)
	const paymentRequired = Boolean(
		wcIntegrationEnabled || stripeIntegrationEnabled
	);

	// Reset state when modal opens + move focus into modal
	useEffect( () => {
		if ( isOpen ) {
			setSubmitStatus( null );
			setErrorMessage( '' );
			setIsSubmitting( false );
			setWaitlistOffer( null );
			setIsJoiningWaitlist( false );
			setIsRecurring( false );
			setRecurringFrequency( 'weekly' );
			setRecurringCount( 4 );
			document.body.classList.add( 'clisyc-modal-open' );

			// Move focus into the modal once it renders
			requestAnimationFrame( () => {
				if ( modalRef.current ) {
					const firstFocusable = modalRef.current.querySelector(
						'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
					);
					if ( firstFocusable ) {
						firstFocusable.focus();
					} else {
						modalRef.current.focus();
					}
				}
			} );
		} else {
			document.body.classList.remove( 'clisyc-modal-open' );
		}
		return () => document.body.classList.remove( 'clisyc-modal-open' );
	}, [ isOpen ] );

	// Handle escape key + focus trap
	useEffect( () => {
		const handleKeyDown = ( e ) => {
			if ( ! isOpen ) {
				return;
			}

			if ( e.key === 'Escape' && ! isSubmitting ) {
				onClose();
				return;
			}

			// Focus trap: keep Tab cycling within the modal
			if ( e.key === 'Tab' && modalRef.current ) {
				const focusableEls = modalRef.current.querySelectorAll(
					'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
				);
				if ( focusableEls.length === 0 ) {
					return;
				}

				const first = focusableEls[ 0 ];
				const last = focusableEls[ focusableEls.length - 1 ];

				if ( e.shiftKey ) {
					if ( document.activeElement === first ) {
						e.preventDefault();
						last.focus();
					}
				} else if ( document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		};
		document.addEventListener( 'keydown', handleKeyDown );
		return () => document.removeEventListener( 'keydown', handleKeyDown );
	}, [ isOpen, isSubmitting, onClose ] );

	// Handle click outside modal
	const handleBackdropClick = ( e ) => {
		if ( e.target === e.currentTarget && ! isSubmitting ) {
			onClose();
		}
	};

	// Handle custom field changes
	const handleCustomFieldChange = ( fieldKey, value ) => {
		setCustomFieldValues( ( prev ) => ( {
			...prev,
			[ fieldKey ]: value,
		} ) );
	};

	// Format date for display
	const formatDate = ( dateStr ) => {
		if ( ! dateStr ) {
			return '';
		}
		const date = new Date( dateStr + 'T00:00:00' );
		return date.toLocaleDateString( undefined, {
			weekday: 'long',
			month: 'long',
			day: 'numeric',
			year: 'numeric',
		} );
	};

	/**
	 * Build the redirect URL with required query parameters for the confirmation shortcode.
	 *
	 * @param {string|null}   baseUrl       - The base URL (from server response or successPageUrl prop)
	 * @param {number|string} appointmentId - The appointment ID from the server response
	 * @return {string} The complete redirect URL with confirmation params
	 */
	const buildRedirectUrl = ( baseUrl, appointmentId ) => {
		if ( ! baseUrl ) {
			return null;
		}

		try {
			// Handle both absolute and relative URLs
			const url = new URL( baseUrl, window.location.origin );

			// Add the parameters required by [clisyc_booking_confirmation] shortcode
			if ( appointmentId ) {
				url.searchParams.set( 'clisyc_booking_status', 'success' );
				url.searchParams.set( 'appt_id', appointmentId );
			}

			return url.toString();
		} catch ( e ) {
			// If URL parsing fails, fall back to simple string concatenation
			console.warn( '[CLISYC] URL parsing failed, using fallback:', e );
			const separator = baseUrl.includes( '?' ) ? '&' : '?';
			return `${ baseUrl }${ separator }clisyc_booking_status=success&appt_id=${ appointmentId }`;
		}
	};

	// Handle form submission via AJAX
	const handleSubmit = async ( e ) => {
		e.preventDefault();

		// Validate we have required data
		if ( isDateRangeMode ) {
			if ( ! selectedDateRange || ! selectedPrimaryItem ) {
				return;
			}
		} else if ( ! selectedSlot ) {
			return;
		}

		// Validate required custom fields (belt-and-suspenders for AJAX forms).
		if ( customFieldDefinitions && customFieldDefinitions.length > 0 ) {
			const missingFields = customFieldDefinitions
				.filter(
					( field ) =>
						field.required &&
						! customFieldValues[ field.key ]?.toString().trim()
				)
				.map( ( field ) => field.label );
			if ( missingFields.length > 0 ) {
				setErrorMessage(
					`Please fill in the required fields: ${ missingFields.join(
						', '
					) }`
				);
				return;
			}
		}

		setIsSubmitting( true );
		setErrorMessage( '' );

		// ── Recurring series submission (Pro REST endpoint) ─────────
		if (
			isRecurring &&
			proRecurringEnabled &&
			! isDateRangeMode &&
			selectedSlot
		) {
			try {
				const slotDimensions =
					selectedSlot.event?.extendedProps?.dimensions || {};
				const payload = {
					initial_slot_identifier: selectedSlot.identifier,
					dimensions: slotDimensions,
					frequency: recurringFrequency,
					count: recurringCount,
					custom_fields: customFieldValues,
				};

				const seriesResponse = await fetch(
					`${
						restRootUrl || '/wp-json/'
					}client-sync-pro/v1/appointments/series`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': restNonce || '',
						},
						body: JSON.stringify( payload ),
						credentials: 'same-origin',
					}
				);

				if ( ! seriesResponse.ok && seriesResponse.status >= 500 ) {
					throw new Error( 'HTTP ' + seriesResponse.status );
				}

				const seriesResult = await seriesResponse.json();

				if ( seriesResult.success || seriesResponse.status === 201 ) {
					setSubmitStatus( 'success' );
					setTimeout( () => {
						if ( seriesResult.redirect_url ) {
							window.location.href = seriesResult.redirect_url;
						} else {
							const redirectUrl = buildRedirectUrl(
								successPageUrl,
								seriesResult.appointment_ids?.[ 0 ]
							);
							if ( redirectUrl ) {
								window.location.href = redirectUrl;
							} else {
								window.location.reload();
							}
						}
					}, 1500 );
				} else {
					setSubmitStatus( 'error' );
					setErrorMessage(
						seriesResult.message ||
							'Failed to create recurring series.'
					);
					setIsSubmitting( false );
				}
			} catch ( error ) {
				console.error( 'Recurring series submission error:', error );
				setSubmitStatus( 'error' );
				setErrorMessage(
					'Network error. Please check your connection and try again.'
				);
				setIsSubmitting( false );
			}
			return;
		}

		// ── Standard single booking submission ─────────────────────
		// Build form data
		const formData = new FormData();
		formData.append( 'action', 'clisyc_submit_booking' );
		formData.append( 'clisyc_appointment_nonce', formNonce );
		formData.append( '_clisyc_frontend_booking_submit', '1' );
		formData.append( 'booking_mode', bookingMode );
		formData.append( 'clisyc_timestamp', Math.floor( Date.now() / 1000 ) );

		if ( isDateRangeMode ) {
			// Date range booking data
			formData.append( 'start_date', selectedDateRange.startDate );
			formData.append( 'end_date', selectedDateRange.endDate );
			formData.append( 'nights', selectedDateRange.nights );

			// Add the primary dimension (the item being booked)
			const primaryDimKey = filterOrder?.[ 0 ] || 'clisyc_service';
			formData.append( primaryDimKey, selectedPrimaryItem.value );

			// Add any other selected dimensions
			Object.entries( selectedFilters ).forEach( ( [ key, value ] ) => {
				if ( key !== primaryDimKey && value ) {
					const val = Array.isArray( value ) ? value[ 0 ] : value;
					if ( val ) {
						formData.append( key, val );
					}
				}
			} );
		} else {
			// Time slot booking data
			formData.append( 'time_slot', selectedSlot.identifier );
			formData.append(
				'time_slot_iso',
				selectedSlot.event.start.toISOString()
			);
			formData.append(
				'appointment_duration_minutes',
				selectedSlot.durationMinutes || 30
			);
			formData.append(
				'appointment_date',
				selectedSlot.event.start.toISOString().split( 'T' )[ 0 ]
			);

			// FIXED: Use the slot's actual dimensions, not the filter selections
			const slotDimensions =
				selectedSlot.event?.extendedProps?.dimensions || {};

			Object.entries( slotDimensions ).forEach( ( [ key, value ] ) => {
				if ( value ) {
					formData.append( key, value );
				}
			} );
		}

		// Add guest info if not logged in
		if ( isGuest ) {
			formData.append( 'guest_email', guestEmail );
			formData.append( 'guest_name', guestName );
		}

		// Add custom field values
		Object.entries( customFieldValues ).forEach( ( [ key, value ] ) => {
			formData.append( `clisyc_custom_field[${ key }]`, value );
		} );

		// Add seat selection data if applicable
		if ( selectedSeats && selectedSeats.length > 0 ) {
			formData.append(
				'selected_seats',
				JSON.stringify( selectedSeats )
			);
			const seatToken =
				sessionStorage.getItem( 'clisyc_seat_session_token' ) || '';
			formData.append( 'seat_session_token', seatToken );
		}

		try {
			const response = await fetch(
				window.clisycBookingFormData?.ajaxUrl ||
					'/wp-admin/admin-ajax.php',
				{
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
				}
			);

			const result = await response.json();

			if ( result.success ) {
				setSubmitStatus( 'success' );

				setTimeout( () => {
					// Get the appointment ID from the response
					const appointmentId = result.data?.appointment_id;

					// Determine base redirect URL - prioritize server response, fall back to prop
					// The server response may already have some params, but we ensure confirmation params are added
					let redirectUrl;

					if ( result.data?.redirect_url ) {
						// Server provided a redirect URL - use it as base
						redirectUrl = buildRedirectUrl(
							result.data.redirect_url,
							appointmentId
						);
					} else if ( successPageUrl ) {
						// Fall back to the prop-provided success page URL
						redirectUrl = buildRedirectUrl(
							successPageUrl,
							appointmentId
						);
					}

					if ( redirectUrl ) {
						window.location.href = redirectUrl;
					} else {
						// Last resort: reload current page
						window.location.reload();
					}
				}, 1500 );
			} else {
				// Check if this is a waitlist offer
				if (
					result.data?.code === 'slot_unavailable' &&
					result.data?.waitlist_available &&
					waitlistEnabled
				) {
					setWaitlistOffer( {
						slot_id: result.data.slot_id,
						position: result.data.waitlist_position,
					} );
					setSubmitStatus( null );
					setErrorMessage( '' );
				} else {
					setSubmitStatus( 'error' );
					setErrorMessage(
						result.data?.message ||
							'An error occurred. Please try again.'
					);
				}
				setIsSubmitting( false );
			}
		} catch ( error ) {
			console.error( 'Booking submission error:', error );
			setSubmitStatus( 'error' );
			setErrorMessage(
				'Network error. Please check your connection and try again.'
			);
			setIsSubmitting( false );
		}
	};

	// Handle joining the waitlist
	const handleJoinWaitlist = async () => {
		if ( ! waitlistOffer ) {
			return;
		}
		setIsJoiningWaitlist( true );
		setErrorMessage( '' );

		const formData = new FormData();
		formData.append( 'action', 'clisyc_join_waitlist' );
		// Use the waitlist-specific nonce when present; fall back to the
		// booking formNonce so in-flight sessions during a plugin upgrade
		// don't 403. The server accepts either action transitionally (see
		// ajax_join_waitlist).
		formData.append(
			'clisyc_appointment_nonce',
			waitlistNonce || formNonce
		);
		formData.append( 'clisyc_timestamp', Math.floor( Date.now() / 1000 ) );
		formData.append( 'slot_id', waitlistOffer.slot_id );

		if ( ! isDateRangeMode && selectedSlot ) {
			formData.append( 'time_slot', selectedSlot.identifier );
			formData.append(
				'time_slot_iso',
				selectedSlot.event.start.toISOString()
			);
			formData.append(
				'appointment_duration_minutes',
				selectedSlot.durationMinutes || 30
			);
			formData.append(
				'appointment_date',
				selectedSlot.event.start.toISOString().split( 'T' )[ 0 ]
			);

			const slotDimensions =
				selectedSlot.event?.extendedProps?.dimensions || {};
			Object.entries( slotDimensions ).forEach( ( [ key, value ] ) => {
				if ( value ) {
					formData.append( key, value );
				}
			} );
		}

		if ( isGuest ) {
			formData.append( 'guest_email', guestEmail );
			formData.append( 'guest_name', guestName );
		}

		Object.entries( customFieldValues ).forEach( ( [ key, value ] ) => {
			formData.append( `clisyc_custom_field[${ key }]`, value );
		} );

		try {
			const response = await fetch(
				window.clisycBookingFormData?.ajaxUrl ||
					'/wp-admin/admin-ajax.php',
				{
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
				}
			);
			const result = await response.json();
			if ( result.success ) {
				setSubmitStatus( 'waitlist_success' );
				setWaitlistOffer( ( prev ) => ( {
					...prev,
					position: result.data?.position || prev?.position,
				} ) );
			} else {
				setSubmitStatus( 'error' );
				setErrorMessage(
					result.data?.message || 'Could not join the waitlist.'
				);
			}
		} catch ( error ) {
			setSubmitStatus( 'error' );
			setErrorMessage( 'Network error. Please try again.' );
		}
		setIsJoiningWaitlist( false );
	};

	// Don't render if not open or no data
	if ( ! isOpen ) {
		return null;
	}
	if ( isDateRangeMode && ( ! selectedDateRange || ! selectedPrimaryItem ) ) {
		return null;
	}
	if ( ! isDateRangeMode && ! selectedSlot ) {
		return null;
	}

	const slotDetails =
		selectedSlot?.event?.extendedProps?.serviceDetails || {};

	return (
		<div
			className="clisyc-booking-modal-overlay"
			onClick={ handleBackdropClick }
		>
			<div
				className="clisyc-booking-modal"
				ref={ modalRef }
				role="dialog"
				aria-modal="true"
				aria-labelledby="clisyc-booking-modal-title"
				tabIndex="-1"
			>
				{ /* Close button - UPDATED with explicit handler */ }
				<button
					type="button"
					className="clisyc-booking-modal-close"
					onClick={ ( e ) => {
						e.preventDefault();
						e.stopPropagation(); // Prevents click from reaching the backdrop
						onClose(); // Closes the modal
					} }
					disabled={ isSubmitting }
					aria-label="Close modal"
				>
					<span className="dashicons dashicons-no-alt"></span>
				</button>

				{ /* Waitlist offer screen */ }
				{ waitlistOffer && ! submitStatus ? (
					<div className="clisyc-booking-modal-waitlist-offer">
						<div
							className="clisyc-waitlist-icon"
							style={ {
								fontSize: '2.5rem',
								textAlign: 'center',
								marginBottom: '0.75rem',
							} }
						>
							⏳
						</div>
						<h2
							style={ {
								textAlign: 'center',
								margin: '0 0 0.5rem',
							} }
						>
							This Slot is Full
						</h2>
						<p
							style={ {
								textAlign: 'center',
								color: '#6b7280',
								margin: '0 0 1.25rem',
							} }
						>
							Would you like to join the waitlist? You&apos;ll be
							notified automatically if a spot opens up.
						</p>
						<div
							style={ {
								background: '#eff6ff',
								borderRadius: '8px',
								padding: '1rem',
								textAlign: 'center',
								marginBottom: '1.25rem',
							} }
						>
							<div
								style={ {
									fontSize: '0.85rem',
									color: '#6b7280',
								} }
							>
								Your waitlist position
							</div>
							<div
								style={ {
									fontSize: '1.75rem',
									fontWeight: '700',
									color: '#1e40af',
								} }
							>
								#{ waitlistOffer.position }
							</div>
						</div>
						<div className="clisyc-booking-modal-actions">
							<button
								type="button"
								className="clisyc-booking-modal-btn-cancel"
								onClick={ () => {
									setWaitlistOffer( null );
									onClose();
								} }
							>
								No Thanks
							</button>
							<button
								type="button"
								className="clisyc-booking-modal-btn-confirm"
								onClick={ handleJoinWaitlist }
								disabled={ isJoiningWaitlist }
							>
								{ isJoiningWaitlist ? (
									<>
										<span className="clisyc-btn-spinner"></span>{ ' ' }
										Joining...
									</>
								) : (
									'Join Waitlist'
								) }
							</button>
						</div>
					</div>
				) : submitStatus === 'waitlist_success' ? (
					<div className="clisyc-booking-modal-success">
						<div className="clisyc-success-icon">✓</div>
						<h2>You&apos;re on the Waitlist!</h2>
						<p>
							You&apos;re #{ waitlistOffer?.position } on the
							waitlist. We&apos;ll notify you as soon as a spot
							opens up.
						</p>
						<div
							className="clisyc-booking-modal-actions"
							style={ {
								marginTop: '1.5rem',
								justifyContent: 'center',
							} }
						>
							<button
								type="button"
								className="clisyc-booking-modal-btn-confirm"
								onClick={ onClose }
							>
								Got It
							</button>
						</div>
					</div>
				) : submitStatus === 'success' ? (
					<div className="clisyc-booking-modal-success">
						<div className="clisyc-success-icon">
							{ paymentRequired ? '🛒' : '✓' }
						</div>
						<h2>
							{ paymentRequired
								? 'Proceeding to Checkout'
								: 'Booking Confirmed!' }
						</h2>
						<p>
							{ paymentRequired
								? `Your ${
										isDateRangeMode
											? 'reservation'
											: 'appointment'
								  } slot is reserved. Complete payment to confirm your booking.`
								: `Your ${
										isDateRangeMode
											? 'reservation'
											: 'appointment'
								  } has been successfully booked.` }
						</p>
						<p className="clisyc-redirect-notice">
							{ paymentRequired
								? 'Redirecting to checkout...'
								: 'Redirecting you now...' }
						</p>
					</div>
				) : (
					<>
						{ /* Header */ }
						<div className="clisyc-booking-modal-header">
							<h2
								id="clisyc-booking-modal-title"
								className="clisyc-booking-modal-title"
							>
								{ isDateRangeMode
									? 'Confirm Your Reservation'
									: 'Confirm Your Booking' }
							</h2>
						</div>

						{ /* Booking summary */ }
						<div className="clisyc-booking-modal-summary">
							{ isDateRangeMode ? (
								/* Date Range Summary */
								<div className="clisyc-date-range-summary">
									<div className="clisyc-summary-item-name">
										{ selectedPrimaryItem.label }
									</div>
									<div className="clisyc-date-range-details">
										<div className="clisyc-date-block">
											<span className="clisyc-date-label">
												Check-in
											</span>
											<span className="clisyc-date-value">
												{ formatDate(
													selectedDateRange.startDate
												) }
											</span>
										</div>
										<div className="clisyc-date-arrow">
											→
										</div>
										<div className="clisyc-date-block">
											<span className="clisyc-date-label">
												Check-out
											</span>
											<span className="clisyc-date-value">
												{ formatDate(
													selectedDateRange.endDate
												) }
											</span>
										</div>
									</div>
									<div className="clisyc-stay-summary">
										<span className="clisyc-nights-count">
											{ selectedDateRange.nights } night
											{ selectedDateRange.nights !== 1
												? 's'
												: '' }
										</span>
									</div>
								</div>
							) : (
								/* Time Slot Summary */
								<div className="clisyc-slot-summary">
									<div className="clisyc-booking-modal-datetime">
										<div className="clisyc-booking-modal-date-icon">
											<span className="dashicons dashicons-calendar-alt"></span>
										</div>
										<div className="clisyc-booking-modal-datetime-text">
											<div className="clisyc-booking-modal-date">
												{ selectedSlot.event.start.toLocaleDateString(
													[],
													{
														weekday: 'long',
														month: 'long',
														day: 'numeric',
														year: 'numeric',
													}
												) }
											</div>
											<div className="clisyc-booking-modal-time">
												{ selectedSlot.event.start.toLocaleTimeString(
													[],
													{
														hour: 'numeric',
														minute: '2-digit',
														hour12: true,
													}
												) }
												{ selectedSlot.durationMinutes && (
													<span className="clisyc-booking-modal-duration">
														{ ' ' }
														·{ ' ' }
														{
															selectedSlot.durationMinutes
														}{ ' ' }
														min
													</span>
												) }
											</div>
										</div>
									</div>
								</div>
							) }

							{ /* NEW: Invoice Table / Detailed Charges */ }
							{ ( basket?.items?.length > 0 ||
								isCalculatingPrice ) && (
								<div
									className="clisyc-invoice-container"
									style={ {
										marginTop: '1rem',
										borderTop: '1px solid #eee',
										paddingTop: '1rem',
									} }
								>
									<h4>Charges</h4>

									{ isCalculatingPrice ? (
										<p className="clisyc-calculating">
											<span className="spinner is-active"></span>{ ' ' }
											Updating price...
										</p>
									) : (
										<table
											className="clisyc-invoice-table"
											style={ {
												width: '100%',
												borderCollapse: 'collapse',
											} }
										>
											<tbody>
												{ basket.items.map(
													( item, idx ) => (
														<tr
															key={ idx }
															style={ {
																borderBottom:
																	'1px solid #f0f0f0',
															} }
														>
															<td
																style={ {
																	padding:
																		'6px 0',
																} }
															>
																<strong>
																	{
																		item.label
																	}
																</strong>
																{ item.type ===
																	'fee' && (
																	<small
																		style={ {
																			display:
																				'block',
																			color: '#666',
																		} }
																	>
																		{
																			item.meta
																		}
																	</small>
																) }
															</td>
															<td
																style={ {
																	textAlign:
																		'right',
																	padding:
																		'6px 0',
																} }
															>
																{
																	basket.currency
																}
																{ parseFloat(
																	item.price
																).toFixed( 2 ) }
															</td>
														</tr>
													)
												) }
												<tr
													className="clisyc-invoice-total"
													style={ {
														borderTop:
															'2px solid #ddd',
														fontSize: '1.1em',
													} }
												>
													<td
														style={ {
															padding: '10px 0',
															fontWeight: 'bold',
														} }
													>
														Total Due
													</td>
													<td
														style={ {
															padding: '10px 0',
															fontWeight: 'bold',
															textAlign: 'right',
															color: '#16a34a',
														} }
													>
														{ basket.formatted }
													</td>
												</tr>
											</tbody>
										</table>
									) }
								</div>
							) }
						</div>

						{ /* Booking details (for slot mode) */ }
						{ ! isDateRangeMode && (
							<div className="clisyc-booking-modal-details">
								{ filterOrder &&
									filterOrder.map( ( slug ) => {
										const dim =
											availabilityDimensions?.[ slug ];
										const jsKey = slug.replace(
											'clisyc_',
											''
										);
										const detailName =
											slotDetails[ `${ jsKey }_name` ];
										const detailColor =
											slotDetails[ `${ jsKey }_color` ];

										if ( ! detailName || ! dim ) {
											return null;
										}

										return (
											<div
												key={ slug }
												className="clisyc-booking-modal-detail-row"
											>
												{ detailColor && (
													<span
														className="clisyc-booking-modal-color-dot"
														style={ {
															backgroundColor:
																detailColor,
														} }
													></span>
												) }
												<div className="clisyc-booking-modal-detail-content">
													<span className="clisyc-booking-modal-detail-label">
														{ dim.label }
													</span>
													<span className="clisyc-booking-modal-detail-value">
														{ detailName }
													</span>
												</div>
											</div>
										);
									} ) }
							</div>
						) }

						{ /* Recurring series options (Pro only, slot mode only) */ }
						{ ! isDateRangeMode && proRecurringEnabled && (
							<RecurringOptions
								isRecurring={ isRecurring }
								onToggle={ setIsRecurring }
								frequency={ recurringFrequency }
								onFrequencyChange={ setRecurringFrequency }
								count={ recurringCount }
								onCountChange={ setRecurringCount }
							/>
						) }

						{ /* Seat selection (Pro only, when venue is linked) */ }
						{ effectiveVenueId > 0 && selectedSlot && (
							<SeatMapPicker
								venueId={ effectiveVenueId }
								slotId={
									selectedSlot.event?.extendedProps
										?.slot_id || 0
								}
								selectedSeats={ selectedSeats }
								onSeatsChange={ onSeatsChange }
							/>
						) }

						{ /* Error message */ }
						{ /* Error message — aria-live ensures screen readers announce dynamically */ }
						<div
							role="alert"
							aria-live="assertive"
							aria-atomic="true"
						>
							{ submitStatus === 'error' && (
								<div className="clisyc-booking-modal-error">
									<span className="dashicons dashicons-warning"></span>
									{ errorMessage }
								</div>
							) }
						</div>

						{ /* Form fields */ }
						<form
							onSubmit={ handleSubmit }
							className="clisyc-booking-modal-form"
						>
							{ /* Guest fields */ }
							{ isGuest && (
								<div className="clisyc-booking-modal-guest-fields">
									<div className="clisyc-booking-modal-field">
										<label htmlFor="guest_name">
											Your Name
										</label>
										<input
											type="text"
											id="guest_name"
											value={ guestName }
											onChange={ ( e ) =>
												setGuestName( e.target.value )
											}
											required
											placeholder="Enter your name"
										/>
									</div>
									<div className="clisyc-booking-modal-field">
										<label htmlFor="guest_email">
											Email Address
										</label>
										<input
											type="email"
											id="guest_email"
											value={ guestEmail }
											onChange={ ( e ) =>
												setGuestEmail( e.target.value )
											}
											required
											placeholder="you@example.com"
										/>
									</div>
								</div>
							) }

							{ /* Custom fields */ }
							{ customFieldDefinitions &&
								customFieldDefinitions.length > 0 && (
									<div className="clisyc-booking-modal-custom-fields">
										{ customFieldDefinitions.map(
											( field ) => (
												<div
													key={ field.key }
													className="clisyc-booking-modal-field"
												>
													<label
														htmlFor={ `custom_${ field.key }` }
													>
														{ field.label }
														{ field.required && (
															<span className="required">
																*
															</span>
														) }
													</label>
													{ field.type ===
													'textarea' ? (
														<textarea
															id={ `custom_${ field.key }` }
															value={
																customFieldValues[
																	field.key
																] || ''
															}
															onChange={ ( e ) =>
																handleCustomFieldChange(
																	field.key,
																	e.target
																		.value
																)
															}
															required={
																field.required
															}
															placeholder={
																field.placeholder ||
																''
															}
															rows={ 3 }
														/>
													) : field.type ===
													  'select' ? (
														<select
															id={ `custom_${ field.key }` }
															value={
																customFieldValues[
																	field.key
																] || ''
															}
															onChange={ ( e ) =>
																handleCustomFieldChange(
																	field.key,
																	e.target
																		.value
																)
															}
															required={
																field.required
															}
														>
															<option value="">
																Select...
															</option>
															{ field.options?.map(
																( opt ) => (
																	<option
																		key={
																			opt
																		}
																		value={
																			opt
																		}
																	>
																		{ opt }
																	</option>
																)
															) }
														</select>
													) : (
														<input
															type={
																field.type ||
																'text'
															}
															id={ `custom_${ field.key }` }
															value={
																customFieldValues[
																	field.key
																] || ''
															}
															onChange={ ( e ) =>
																handleCustomFieldChange(
																	field.key,
																	e.target
																		.value
																)
															}
															required={
																field.required
															}
															placeholder={
																field.placeholder ||
																''
															}
														/>
													) }
												</div>
											)
										) }
									</div>
								) }

							{ /* Notes field */ }
							<div className="clisyc-booking-modal-field">
								<label htmlFor="booking_notes">
									{ isDateRangeMode
										? 'Special Requests (optional)'
										: 'Notes (optional)' }
								</label>
								<textarea
									id="booking_notes"
									value={ customFieldValues.notes || '' }
									onChange={ ( e ) =>
										handleCustomFieldChange(
											'notes',
											e.target.value
										)
									}
									placeholder={
										isDateRangeMode
											? 'Any special requests for your stay...'
											: 'Any special requests or information...'
									}
									rows={ 2 }
								/>
							</div>

							{ /* Action buttons */ }
							<div className="clisyc-booking-modal-actions">
								<button
									type="button"
									className="clisyc-booking-modal-btn-cancel"
									onClick={ onClose }
									disabled={ isSubmitting }
								>
									Cancel
								</button>
								<button
									type="submit"
									className="clisyc-booking-modal-btn-confirm"
									disabled={
										isSubmitting ||
										( isGuest &&
											( ! guestEmail || ! guestName ) )
									}
								>
									{ isSubmitting ? (
										<>
											<span className="clisyc-btn-spinner"></span>
											{ isRecurring
												? 'Validating Series...'
												: paymentRequired
												? 'Processing...'
												: isDateRangeMode
												? 'Reserving...'
												: 'Booking...' }
										</>
									) : isRecurring ? (
										`Book ${ recurringCount } Appointments`
									) : paymentRequired ? (
										'Proceed to Checkout'
									) : isDateRangeMode ? (
										'Confirm Reservation'
									) : (
										'Confirm Booking'
									) }
								</button>
							</div>
						</form>
					</>
				) }
			</div>
		</div>
	);
};

export default BookingModal;
