/**
 * File: /client-sync-monorepo/src/assets/src/booking-wizard/index.js
 * Client Sync - Booking Wizard (Dimension Agnostic)
 * A step-by-step form-based booking experience
 * Shortcode: [clisyc_booking_wizard]
 *
 * This wizard dynamically adapts to whatever dimensions are configured
 * in the Client Sync dimension registry. It could be:
 * - Service + Practitioner + Room
 * - Vehicle + Driver + Route
 * - Equipment + Technician + Location
 * - Or any combination of 1-N dimensions
 *
 * @package
 * @subpackage ClientSync/Assets/BookingWizard
 */

import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import ErrorBoundary from '../shared/ErrorBoundary';
import { bootstrapApiFetch } from '../shared/bootstrap-api-fetch';

// Import styles - webpack will extract this to style-index.css
import './style.css';

// Register the wp_rest nonce on apiFetch — see bootstrap-api-fetch.js.
bootstrapApiFetch();

// Seat selection (shared with booking-form)
import SeatMapPicker from '../booking-form/SeatMapPicker';

// Icons as simple SVG components
const CheckIcon = () => (
	<svg
		width="20"
		height="20"
		viewBox="0 0 20 20"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
	>
		<polyline points="4,10 8,14 16,6" />
	</svg>
);

const ChevronRight = () => (
	<svg
		width="20"
		height="20"
		viewBox="0 0 20 20"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
	>
		<polyline points="7,4 13,10 7,16" />
	</svg>
);

const ChevronLeft = () => (
	<svg
		width="20"
		height="20"
		viewBox="0 0 20 20"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
	>
		<polyline points="13,4 7,10 13,16" />
	</svg>
);

const CalendarIcon = () => (
	<svg
		width="20"
		height="20"
		viewBox="0 0 20 20"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
	>
		<rect x="3" y="4" width="14" height="13" rx="2" />
		<line x1="3" y1="8" x2="17" y2="8" />
		<line x1="7" y1="2" x2="7" y2="5" />
		<line x1="13" y1="2" x2="13" y2="5" />
	</svg>
);

const ClockIcon = () => (
	<svg
		width="20"
		height="20"
		viewBox="0 0 20 20"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
	>
		<circle cx="10" cy="10" r="7" />
		<polyline points="10,6 10,10 13,12" />
	</svg>
);

const UserIcon = () => (
	<svg
		width="20"
		height="20"
		viewBox="0 0 20 20"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
	>
		<circle cx="10" cy="6" r="3" />
		<path d="M4,17 C4,13 7,11 10,11 C13,11 16,13 16,17" />
	</svg>
);

const GridIcon = () => (
	<svg
		width="20"
		height="20"
		viewBox="0 0 20 20"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
	>
		<rect x="3" y="3" width="6" height="6" rx="1" />
		<rect x="11" y="3" width="6" height="6" rx="1" />
		<rect x="3" y="11" width="6" height="6" rx="1" />
		<rect x="11" y="11" width="6" height="6" rx="1" />
	</svg>
);

const SpinnerIcon = () => (
	<svg
		width="20"
		height="20"
		viewBox="0 0 20 20"
		className="clisyc-wizard-spinner"
	>
		<circle
			cx="10"
			cy="10"
			r="8"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			opacity="0.25"
		/>
		<path
			d="M10,2 A8,8 0 0,1 18,10"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
		/>
	</svg>
);

// Step indicator component
const StepIndicator = ( { steps, currentStep, onStepClick } ) => {
	return (
		<div className="clisyc-wizard-steps">
			{ steps.map( ( step, index ) => {
				const isCompleted = index < currentStep;
				const isCurrent = index === currentStep;
				const isClickable = index < currentStep;

				return (
					<React.Fragment key={ step.id }>
						<div
							className={ `clisyc-wizard-step ${
								isCompleted ? 'completed' : ''
							} ${ isCurrent ? 'current' : '' } ${
								isClickable ? 'clickable' : ''
							}` }
							onClick={ () =>
								isClickable && onStepClick( index )
							}
						>
							<div className="clisyc-wizard-step-number">
								{ isCompleted ? <CheckIcon /> : index + 1 }
							</div>
							<div className="clisyc-wizard-step-label">
								{ step.label }
							</div>
						</div>
						{ index < steps.length - 1 && (
							<div
								className={ `clisyc-wizard-step-connector ${
									isCompleted ? 'completed' : ''
								}` }
							/>
						) }
					</React.Fragment>
				);
			} ) }
		</div>
	);
};

// Generic Dimension Selection Step
// This component adapts to any dimension type
const DimensionStep = ( {
	dimension,
	items,
	selectedItem,
	onSelect,
	previousSelections,
} ) => {
	const singularLabel = dimension.singular_label || dimension.key;
	const pluralLabel = dimension.plural_label || dimension.key + 's';

	// Filter items based on compatibility with previous selections
	const availableItems = useMemo( () => {
		if ( ! items || items.length === 0 ) {
			return [];
		}

		// If no previous selections, show all items
		if (
			! previousSelections ||
			Object.keys( previousSelections ).length === 0
		) {
			return items;
		}

		// Filter items that are compatible with previous selections
		return items.filter( ( item ) => {
			// Check if this item has any restrictions
			if (
				! item.compatibleWith ||
				Object.keys( item.compatibleWith ).length === 0
			) {
				return true; // No restrictions defined, show item
			}

			// Check compatibility with each previous selection
			for ( const [ dimKey, selectedId ] of Object.entries(
				previousSelections
			) ) {
				const compatList = item.compatibleWith[ dimKey ];

				// If no compatibility list for this dimension, assume compatible
				if ( ! compatList || compatList.length === 0 ) {
					continue;
				}

				// Check if selected ID is in the compatibility list (with type coercion)
				const selectedIdNum = Number( selectedId );
				const isCompatible = compatList.some(
					( id ) => Number( id ) === selectedIdNum
				);

				if ( ! isCompatible ) {
					return false; // Not compatible with this selection
				}
			}
			return true; // Compatible with all previous selections
		} );
	}, [ items, previousSelections ] );

	// REACT HOOKS FIX: useEffect must be called unconditionally (before any early return)
	// to comply with React's Rules of Hooks. Moved above the conditional return.
	useEffect( () => {
		if ( availableItems && availableItems.length === 1 && ! selectedItem ) {
			onSelect( availableItems[ 0 ] );
		}
	}, [ availableItems, selectedItem, onSelect ] );

	if ( ! availableItems || availableItems.length === 0 ) {
		return (
			<div className="clisyc-wizard-step-content">
				<h3 className="clisyc-wizard-step-title">
					Select { singularLabel }
				</h3>
				<div className="clisyc-wizard-empty">
					No { pluralLabel.toLowerCase() } available for your current
					selections.
				</div>
			</div>
		);
	}

	return (
		<div className="clisyc-wizard-step-content">
			<h3 className="clisyc-wizard-step-title">
				{ dimension.selection_prompt || `Select a ${ singularLabel }` }
			</h3>
			<p className="clisyc-wizard-step-subtitle">
				{ dimension.selection_description ||
					`Choose your preferred ${ singularLabel.toLowerCase() }` }
			</p>

			<div className="clisyc-wizard-dimension-grid">
				{ availableItems.map( ( item ) => (
					<div
						key={ item.id }
						className={ `clisyc-wizard-dimension-card ${
							selectedItem?.id === item.id ? 'selected' : ''
						}` }
						onClick={ () => onSelect( item ) }
					>
						<div
							className="clisyc-wizard-dimension-color"
							style={ {
								backgroundColor: item.color || '#3b82f6',
							} }
						>
							{ item.avatar ? (
								<img src={ item.avatar } alt={ item.name } />
							) : (
								<span className="clisyc-wizard-dimension-initial">
									{ item.name?.charAt( 0 )?.toUpperCase() ||
										'?' }
								</span>
							) }
						</div>
						<div className="clisyc-wizard-dimension-content">
							<h4 className="clisyc-wizard-dimension-name">
								{ item.name }
							</h4>
							{ item.subtitle && (
								<span className="clisyc-wizard-dimension-subtitle">
									{ item.subtitle }
								</span>
							) }
							{ item.duration && (
								<span className="clisyc-wizard-dimension-meta">
									<ClockIcon /> { item.duration } min
								</span>
							) }
							{ item.price && (
								<span className="clisyc-wizard-dimension-meta">
									{ item.price }
								</span>
							) }
							{ item.description && (
								<p className="clisyc-wizard-dimension-description">
									{ item.description }
								</p>
							) }
						</div>
						{ selectedItem?.id === item.id && (
							<div className="clisyc-wizard-dimension-check">
								<CheckIcon />
							</div>
						) }
					</div>
				) ) }
			</div>
		</div>
	);
};

// Date Selection Step
const DateStep = ( { selectedDate, onSelect, availableDates, isLoading } ) => {
	const [ currentMonth, setCurrentMonth ] = useState( () => {
		// Start with selected date's month or today
		return selectedDate ? new Date( selectedDate ) : new Date();
	} );

	// Compute available months and their range from the availableDates array.
	const { availableMonths, minMonth, maxMonth } = useMemo( () => {
		if ( ! availableDates || availableDates.length === 0 ) {
			return {
				availableMonths: new Set(),
				minMonth: null,
				maxMonth: null,
			};
		}
		const months = new Set();
		availableDates.forEach( ( dateStr ) => {
			// dateStr is "YYYY-MM-DD"
			months.add( dateStr.substring( 0, 7 ) ); // "YYYY-MM"
		} );
		const sorted = Array.from( months ).sort();
		return {
			availableMonths: months,
			minMonth: sorted[ 0 ],
			maxMonth: sorted[ sorted.length - 1 ],
		};
	}, [ availableDates ] );

	// Auto-navigate to the first month with availability when data loads.
	useEffect( () => {
		if ( selectedDate ) {
			return;
		} // Don't override if user already picked a date
		if ( minMonth ) {
			const [ year, month ] = minMonth.split( '-' ).map( Number );
			setCurrentMonth( new Date( year, month - 1, 1 ) );
		}
	}, [ minMonth, selectedDate ] );

	// Helper: get "YYYY-MM" key for a Date object.
	const getMonthKey = ( date ) => {
		const y = date.getFullYear();
		const m = String( date.getMonth() + 1 ).padStart( 2, '0' );
		return `${ y }-${ m }`;
	};

	// Check if navigation in a given direction is allowed.
	const canNavigate = ( direction ) => {
		if ( ! availableDates || availableDates.length === 0 ) {
			return true;
		} // No restriction data
		const targetMonth = new Date( currentMonth );
		targetMonth.setMonth( targetMonth.getMonth() + direction );
		const targetKey = getMonthKey( targetMonth );
		if ( direction < 0 && minMonth ) {
			return targetKey >= minMonth;
		}
		if ( direction > 0 && maxMonth ) {
			return targetKey <= maxMonth;
		}
		return true;
	};

	const getDaysInMonth = ( date ) => {
		const year = date.getFullYear();
		const month = date.getMonth();
		const firstDay = new Date( year, month, 1 );
		const lastDay = new Date( year, month + 1, 0 );
		const daysInMonth = lastDay.getDate();
		const startingDay = firstDay.getDay();

		const days = [];

		// Previous month days
		for ( let i = 0; i < startingDay; i++ ) {
			const prevDate = new Date( year, month, -startingDay + i + 1 );
			days.push( { date: prevDate, isCurrentMonth: false } );
		}

		// Current month days
		for ( let i = 1; i <= daysInMonth; i++ ) {
			days.push( {
				date: new Date( year, month, i ),
				isCurrentMonth: true,
			} );
		}

		// Next month days to fill grid
		const remaining = 42 - days.length;
		for ( let i = 1; i <= remaining; i++ ) {
			days.push( {
				date: new Date( year, month + 1, i ),
				isCurrentMonth: false,
			} );
		}

		return days;
	};

	const formatDateKey = ( date ) => {
		return date.toISOString().split( 'T' )[ 0 ];
	};

	const isDateAvailable = ( date ) => {
		if ( ! availableDates ) {
			return true;
		} // If no restriction data, allow all
		const key = formatDateKey( date );
		return availableDates.includes( key );
	};

	const isDatePast = ( date ) => {
		const today = new Date();
		today.setHours( 0, 0, 0, 0 );
		return date < today;
	};

	const isDateSelected = ( date ) => {
		if ( ! selectedDate ) {
			return false;
		}
		return formatDateKey( date ) === formatDateKey( selectedDate );
	};

	const handleDateClick = ( date, isCurrentMonth ) => {
		if ( ! isCurrentMonth || isDatePast( date ) ) {
			return;
		}
		if ( availableDates && ! isDateAvailable( date ) ) {
			return;
		}
		onSelect( date );
	};

	const navigateMonth = ( direction ) => {
		if ( ! canNavigate( direction ) ) {
			return;
		}
		setCurrentMonth( ( prev ) => {
			const newMonth = new Date( prev );
			newMonth.setMonth( newMonth.getMonth() + direction );
			return newMonth;
		} );
	};

	const days = getDaysInMonth( currentMonth );
	const monthNames = [
		'January',
		'February',
		'March',
		'April',
		'May',
		'June',
		'July',
		'August',
		'September',
		'October',
		'November',
		'December',
	];
	const dayNames = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];

	// When availableDates is explicitly empty (not null/loading), show a clear message.
	const hasNoAvailability =
		Array.isArray( availableDates ) && availableDates.length === 0;

	return (
		<div className="clisyc-wizard-step-content">
			<h3 className="clisyc-wizard-step-title">Select a Date</h3>
			<p className="clisyc-wizard-step-subtitle">
				{ availableDates === null
					? 'Choose your preferred appointment date'
					: hasNoAvailability
					? 'No available dates found for your selections. Please go back and try different options.'
					: `${ availableDates.length } date${
							availableDates.length === 1 ? '' : 's'
					  } with availability` }
			</p>

			{ hasNoAvailability ? (
				<div
					className="clisyc-wizard-empty"
					style={ { textAlign: 'center', padding: '32px 16px' } }
				>
					<CalendarIcon />
					<p>
						No dates with availability. Try selecting different
						options in the previous steps.
					</p>
				</div>
			) : (
				<div className="clisyc-wizard-calendar">
					<div className="clisyc-wizard-calendar-header">
						<button
							type="button"
							className={ `clisyc-wizard-calendar-nav ${
								! canNavigate( -1 ) ? 'disabled' : ''
							}` }
							onClick={ () => navigateMonth( -1 ) }
							disabled={ ! canNavigate( -1 ) }
						>
							<ChevronLeft />
						</button>
						<span className="clisyc-wizard-calendar-month">
							{ monthNames[ currentMonth.getMonth() ] }{ ' ' }
							{ currentMonth.getFullYear() }
						</span>
						<button
							type="button"
							className={ `clisyc-wizard-calendar-nav ${
								! canNavigate( 1 ) ? 'disabled' : ''
							}` }
							onClick={ () => navigateMonth( 1 ) }
							disabled={ ! canNavigate( 1 ) }
						>
							<ChevronRight />
						</button>
					</div>

					<div className="clisyc-wizard-calendar-weekdays">
						{ dayNames.map( ( day ) => (
							<div
								key={ day }
								className="clisyc-wizard-calendar-weekday"
							>
								{ day }
							</div>
						) ) }
					</div>

					<div className="clisyc-wizard-calendar-days">
						{ isLoading ? (
							<div className="clisyc-wizard-calendar-loading">
								<SpinnerIcon /> Loading available dates...
							</div>
						) : (
							days.map( ( { date, isCurrentMonth }, index ) => {
								const isPast = isDatePast( date );
								const isAvailable = isDateAvailable( date );
								const isSelected = isDateSelected( date );
								const isToday =
									formatDateKey( date ) ===
									formatDateKey( new Date() );
								const isDisabled =
									! isCurrentMonth ||
									isPast ||
									( availableDates && ! isAvailable );

								return (
									<div
										key={ index }
										className={ `clisyc-wizard-calendar-day
                                        ${
											! isCurrentMonth
												? 'other-month'
												: ''
										}
                                        ${ isPast ? 'past' : '' }
                                        ${
											isDisabled
												? 'disabled'
												: 'available'
										}
                                        ${ isSelected ? 'selected' : '' }
                                        ${ isToday ? 'today' : '' }` }
										onClick={ () =>
											! isDisabled &&
											handleDateClick(
												date,
												isCurrentMonth
											)
										}
									>
										{ date.getDate() }
									</div>
								);
							} )
						) }
					</div>
				</div>
			) }

			{ selectedDate && (
				<div className="clisyc-wizard-selected-date">
					<CalendarIcon />
					<span>
						{ selectedDate.toLocaleDateString( 'en-US', {
							weekday: 'long',
							year: 'numeric',
							month: 'long',
							day: 'numeric',
						} ) }
					</span>
				</div>
			) }
		</div>
	);
};

// Time Selection Step
const TimeStep = ( {
	timeSlots,
	selectedTime,
	onSelect,
	isLoading,
	selectedDate,
} ) => {
	if ( isLoading ) {
		return (
			<div className="clisyc-wizard-step-content">
				<h3 className="clisyc-wizard-step-title">Select a Time</h3>
				<div className="clisyc-wizard-loading">
					<SpinnerIcon /> Loading available times...
				</div>
			</div>
		);
	}

	if ( ! timeSlots || timeSlots.length === 0 ) {
		return (
			<div className="clisyc-wizard-step-content">
				<h3 className="clisyc-wizard-step-title">Select a Time</h3>
				<div className="clisyc-wizard-empty">
					No available times on this date. Please select a different
					date.
				</div>
			</div>
		);
	}

	// Group time slots by morning/afternoon/evening
	const groupSlots = ( slots ) => {
		const groups = {
			morning: { label: 'Morning', slots: [] },
			afternoon: { label: 'Afternoon', slots: [] },
			evening: { label: 'Evening', slots: [] },
		};

		slots.forEach( ( slot ) => {
			const hour = parseInt( slot.time.split( ':' )[ 0 ] );
			if ( hour < 12 ) {
				groups.morning.slots.push( slot );
			} else if ( hour < 17 ) {
				groups.afternoon.slots.push( slot );
			} else {
				groups.evening.slots.push( slot );
			}
		} );

		return groups;
	};

	const groupedSlots = groupSlots( timeSlots );

	const formatTime = ( timeStr ) => {
		const [ hours, minutes ] = timeStr.split( ':' );
		const hour = parseInt( hours );
		const ampm = hour >= 12 ? 'PM' : 'AM';
		const hour12 = hour % 12 || 12;
		return `${ hour12 }:${ minutes } ${ ampm }`;
	};

	return (
		<div className="clisyc-wizard-step-content">
			<h3 className="clisyc-wizard-step-title">Select a Time</h3>
			<p className="clisyc-wizard-step-subtitle">
				Available times for{ ' ' }
				{ selectedDate?.toLocaleDateString( 'en-US', {
					weekday: 'long',
					month: 'long',
					day: 'numeric',
				} ) }
			</p>

			<div className="clisyc-wizard-time-groups">
				{ Object.entries( groupedSlots ).map( ( [ key, group ] ) => {
					if ( group.slots.length === 0 ) {
						return null;
					}

					return (
						<div key={ key } className="clisyc-wizard-time-group">
							<h4 className="clisyc-wizard-time-group-label">
								{ group.label }
							</h4>
							<div className="clisyc-wizard-time-grid">
								{ group.slots.map( ( slot ) => (
									<button
										key={ slot.id || slot.time }
										type="button"
										className={ `clisyc-wizard-time-slot ${
											selectedTime?.time === slot.time
												? 'selected'
												: ''
										}` }
										onClick={ () => onSelect( slot ) }
									>
										{ formatTime( slot.time ) }
									</button>
								) ) }
							</div>
						</div>
					);
				} ) }
			</div>
		</div>
	);
};

// Contact Details Step
const DetailsStep = ( { details, onChange, errors, currentUser } ) => {
	// Pre-fill with current user data if available.
	// FIX: Spread details FIRST so user data takes precedence, and include
	// onChange + details.firstName in dependency array to avoid stale closures.
	useEffect( () => {
		if ( currentUser && ! details.firstName ) {
			onChange( {
				...details,
				firstName: currentUser.firstName || '',
				lastName: currentUser.lastName || '',
				email: currentUser.email || '',
			} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ currentUser, details.firstName ] );

	const handleChange = ( field ) => ( e ) => {
		onChange( { ...details, [ field ]: e.target.value } );
	};

	return (
		<div className="clisyc-wizard-step-content">
			<h3 className="clisyc-wizard-step-title">Your Details</h3>
			<p className="clisyc-wizard-step-subtitle">
				Please provide your contact information
			</p>

			<div className="clisyc-wizard-form">
				<div className="clisyc-wizard-form-row">
					<div className="clisyc-wizard-form-field">
						<label htmlFor="wizard-first-name">First Name *</label>
						<input
							id="wizard-first-name"
							type="text"
							value={ details.firstName || '' }
							onChange={ handleChange( 'firstName' ) }
							placeholder="Enter your first name"
							className={ errors?.firstName ? 'error' : '' }
						/>
						{ errors?.firstName && (
							<span className="clisyc-wizard-field-error">
								{ errors.firstName }
							</span>
						) }
					</div>
					<div className="clisyc-wizard-form-field">
						<label htmlFor="wizard-last-name">Last Name *</label>
						<input
							id="wizard-last-name"
							type="text"
							value={ details.lastName || '' }
							onChange={ handleChange( 'lastName' ) }
							placeholder="Enter your last name"
							className={ errors?.lastName ? 'error' : '' }
						/>
						{ errors?.lastName && (
							<span className="clisyc-wizard-field-error">
								{ errors.lastName }
							</span>
						) }
					</div>
				</div>

				<div className="clisyc-wizard-form-field">
					<label htmlFor="wizard-email">Email Address *</label>
					<input
						id="wizard-email"
						type="email"
						value={ details.email || '' }
						onChange={ handleChange( 'email' ) }
						placeholder="your@email.com"
						className={ errors?.email ? 'error' : '' }
					/>
					{ errors?.email && (
						<span className="clisyc-wizard-field-error">
							{ errors.email }
						</span>
					) }
				</div>

				<div className="clisyc-wizard-form-field">
					<label htmlFor="wizard-phone">Phone Number</label>
					<input
						id="wizard-phone"
						type="tel"
						value={ details.phone || '' }
						onChange={ handleChange( 'phone' ) }
						placeholder="(555) 123-4567"
					/>
				</div>

				<div className="clisyc-wizard-form-field">
					<label htmlFor="wizard-notes">Additional Notes</label>
					<textarea
						id="wizard-notes"
						value={ details.notes || '' }
						onChange={ handleChange( 'notes' ) }
						placeholder="Any special requests or information we should know?"
						rows={ 3 }
					/>
				</div>
			</div>
		</div>
	);
};

// Confirmation Step (Dynamic based on selected dimensions)
const ConfirmationStep = ( {
	dimensions,
	selections,
	date,
	time,
	details,
	isSubmitting,
	error,
} ) => {
	const formatTime = ( timeStr ) => {
		if ( ! timeStr ) {
			return '';
		}
		const [ hours, minutes ] = timeStr.split( ':' );
		const hour = parseInt( hours );
		const ampm = hour >= 12 ? 'PM' : 'AM';
		const hour12 = hour % 12 || 12;
		return `${ hour12 }:${ minutes } ${ ampm }`;
	};

	return (
		<div className="clisyc-wizard-step-content">
			<h3 className="clisyc-wizard-step-title">Review Your Booking</h3>
			<p className="clisyc-wizard-step-subtitle">
				Please confirm all details are correct
			</p>

			{ error && (
				<div className="clisyc-wizard-error-message">{ error }</div>
			) }

			<div className="clisyc-wizard-summary">
				{ /* Dynamic dimension summaries */ }
				{ dimensions.map( ( dim ) => {
					const selected = selections[ dim.key ];
					if ( ! selected ) {
						return null;
					}

					return (
						<div
							key={ dim.key }
							className="clisyc-wizard-summary-item"
						>
							<div
								className="clisyc-wizard-summary-icon"
								style={ {
									backgroundColor:
										selected.color || '#3b82f6',
								} }
							>
								{ selected.avatar ? (
									<img src={ selected.avatar } alt="" />
								) : (
									<span>
										{ selected.name
											?.charAt( 0 )
											?.toUpperCase() }
									</span>
								) }
							</div>
							<div className="clisyc-wizard-summary-content">
								<span className="clisyc-wizard-summary-label">
									{ dim.singular_label }
								</span>
								<span className="clisyc-wizard-summary-value">
									{ selected.name }
								</span>
								{ selected.duration && (
									<span className="clisyc-wizard-summary-detail">
										{ selected.duration } minutes
									</span>
								) }
							</div>
						</div>
					);
				} ) }

				{ /* Date & Time */ }
				<div className="clisyc-wizard-summary-item">
					<div className="clisyc-wizard-summary-icon">
						<CalendarIcon />
					</div>
					<div className="clisyc-wizard-summary-content">
						<span className="clisyc-wizard-summary-label">
							Date & Time
						</span>
						<span className="clisyc-wizard-summary-value">
							{ date?.toLocaleDateString( 'en-US', {
								weekday: 'long',
								month: 'long',
								day: 'numeric',
								year: 'numeric',
							} ) }
						</span>
						<span className="clisyc-wizard-summary-detail">
							{ formatTime( time?.time ) }
						</span>
					</div>
				</div>

				{ /* Contact Info */ }
				<div className="clisyc-wizard-summary-item">
					<div className="clisyc-wizard-summary-icon">
						<UserIcon />
					</div>
					<div className="clisyc-wizard-summary-content">
						<span className="clisyc-wizard-summary-label">
							Your Information
						</span>
						<span className="clisyc-wizard-summary-value">
							{ details?.firstName } { details?.lastName }
						</span>
						<span className="clisyc-wizard-summary-detail">
							{ details?.email }
						</span>
						{ details?.phone && (
							<span className="clisyc-wizard-summary-detail">
								{ details.phone }
							</span>
						) }
					</div>
				</div>

				{ details?.notes && (
					<div className="clisyc-wizard-summary-notes">
						<span className="clisyc-wizard-summary-label">
							Notes
						</span>
						<p>{ details.notes }</p>
					</div>
				) }
			</div>

			{ isSubmitting && (
				<div className="clisyc-wizard-submitting">
					<SpinnerIcon /> Submitting your booking...
				</div>
			) }
		</div>
	);
};

// Success Screen
const SuccessScreen = ( {
	dimensions,
	selections,
	date,
	time,
	details,
	confirmationNumber,
} ) => {
	// Find the primary dimension (usually the first one, like "Service")
	const primaryDim = dimensions[ 0 ];
	const primarySelection = primaryDim ? selections[ primaryDim.key ] : null;

	return (
		<div className="clisyc-wizard-success">
			<div className="clisyc-wizard-success-icon">
				<CheckIcon />
			</div>
			<h2 className="clisyc-wizard-success-title">Booking Confirmed!</h2>
			<p className="clisyc-wizard-success-message">
				Your appointment has been successfully booked.
			</p>
			{ confirmationNumber && (
				<div className="clisyc-wizard-confirmation-number">
					Confirmation #: <strong>{ confirmationNumber }</strong>
				</div>
			) }
			<div className="clisyc-wizard-success-details">
				{ primarySelection && (
					<p>
						<strong>{ primarySelection.name }</strong>
					</p>
				) }
				{ dimensions.slice( 1 ).map( ( dim ) => {
					const sel = selections[ dim.key ];
					return sel ? (
						<p key={ dim.key }>
							with <strong>{ sel.name }</strong>
						</p>
					) : null;
				} ) }
				<p>
					{ date?.toLocaleDateString( 'en-US', {
						weekday: 'long',
						month: 'long',
						day: 'numeric',
						year: 'numeric',
					} ) }
				</p>
			</div>
			<p className="clisyc-wizard-success-email">
				A confirmation email has been sent to{ ' ' }
				<strong>{ details?.email }</strong>
			</p>
		</div>
	);
};

// Selection Summary Component - shows current selections at bottom of wizard
const SelectionSummary = ( {
	dimensions,
	selections,
	selectedDate,
	selectedTime,
} ) => {
	const hasSelections =
		Object.keys( selections ).length > 0 || selectedDate || selectedTime;

	if ( ! hasSelections ) {
		return null;
	}

	const formatDate = ( date ) => {
		if ( ! date ) {
			return null;
		}
		return date.toLocaleDateString( 'en-US', {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			year: 'numeric',
		} );
	};

	return (
		<div className="clisyc-wizard-selection-summary">
			<div className="clisyc-wizard-selection-summary-title">
				Your Selections
			</div>
			<div className="clisyc-wizard-selection-summary-items">
				{ dimensions.map( ( dim ) => {
					const selection = selections[ dim.key ];
					if ( ! selection ) {
						return null;
					}
					return (
						<div
							key={ dim.key }
							className="clisyc-wizard-selection-summary-item"
						>
							<span className="clisyc-wizard-selection-summary-label">
								{ dim.singular_label }:
							</span>
							<span className="clisyc-wizard-selection-summary-value">
								<span
									className="clisyc-wizard-selection-summary-dot"
									style={ {
										backgroundColor: selection.color,
									} }
								/>
								{ selection.name }
							</span>
						</div>
					);
				} ) }
				{ selectedDate && (
					<div className="clisyc-wizard-selection-summary-item">
						<span className="clisyc-wizard-selection-summary-label">
							Date:
						</span>
						<span className="clisyc-wizard-selection-summary-value">
							{ formatDate( selectedDate ) }
						</span>
					</div>
				) }
				{ selectedTime && (
					<div className="clisyc-wizard-selection-summary-item">
						<span className="clisyc-wizard-selection-summary-label">
							Time:
						</span>
						<span className="clisyc-wizard-selection-summary-value">
							{ selectedTime.time }
						</span>
					</div>
				) }
			</div>
		</div>
	);
};

// Main Booking Wizard Component
const BookingWizard = ( { config } ) => {
	const [ currentStep, setCurrentStep ] = useState( 0 );
	const [ dimensionSelections, setDimensionSelections ] = useState( {} );
	const [ selectedDate, setSelectedDate ] = useState( null );
	const [ selectedTime, setSelectedTime ] = useState( null );
	const [ contactDetails, setContactDetails ] = useState( {} );
	const [ formErrors, setFormErrors ] = useState( {} );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ submitError, setSubmitError ] = useState( null );
	const [ isComplete, setIsComplete ] = useState( false );
	const [ confirmationNumber, setConfirmationNumber ] = useState( null );
	const [ timeSlots, setTimeSlots ] = useState( [] );
	const [ availableDates, setAvailableDates ] = useState( null );

	// Seat selection state
	const [ selectedSeats, setSelectedSeats ] = useState( [] );
	const [ resolvedVenueId, setResolvedVenueId ] = useState( 0 );

	// Get data from config
	const dimensions = config?.dimensions || [];
	const dimensionData = config?.dimensionData || {};
	const ajaxUrl = config?.ajaxUrl || '/wp-admin/admin-ajax.php';
	const nonce = config?.nonce || '';
	const calendarNonce = config?.calendarNonce || '';
	const currentUser = config?.currentUser || null;
	const noDimensionsConfigured = config?.noDimensionsConfigured || false;
	const settingsUrl =
		config?.settingsUrl || '/wp-admin/admin.php?page=clisyc-dimensions';

	// Show message if no dimensions are configured
	if ( noDimensionsConfigured || dimensions.length === 0 ) {
		return (
			<div className="clisyc-booking-wizard">
				<div className="clisyc-wizard-no-dimensions">
					<div className="clisyc-wizard-no-dimensions-icon">
						<svg
							width="48"
							height="48"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							strokeWidth="1.5"
						>
							<circle cx="12" cy="12" r="10" />
							<line x1="12" y1="8" x2="12" y2="12" />
							<line x1="12" y1="16" x2="12.01" y2="16" />
						</svg>
					</div>
					<h3 className="clisyc-wizard-no-dimensions-title">
						Setup Required
					</h3>
					<p className="clisyc-wizard-no-dimensions-message">
						The booking wizard requires at least one dimension (such
						as Service, Practitioner, or Room) to be configured and
						enabled.
					</p>
					<p className="clisyc-wizard-no-dimensions-instructions">
						Please go to <strong>Client Sync → Dimensions</strong>{ ' ' }
						in the WordPress admin to set up your booking
						dimensions.
					</p>
				</div>
			</div>
		);
	}

	// Build dynamic steps based on configured dimensions
	const steps = useMemo( () => {
		const dynamicSteps = dimensions.map( ( dim ) => ( {
			id: dim.key,
			label: dim.singular_label || dim.key,
			type: 'dimension',
			dimension: dim,
		} ) );

		// Add fixed steps: Date, Time, Details, Confirm
		return [
			...dynamicSteps,
			{ id: 'date', label: 'Date', type: 'date' },
			{ id: 'time', label: 'Time', type: 'time' },
			{ id: 'details', label: 'Details', type: 'details' },
			{ id: 'confirm', label: 'Confirm', type: 'confirm' },
		];
	}, [ dimensions ] );

	// Pre-selection from [clisyc_dimension_grid] CTA links.
	// If a dimension was pre-selected via URL (e.g., ?select_clisyc_service=376),
	// auto-select it and advance past that step.
	useEffect( () => {
		const preselected = config?.preselectedDimension;
		if ( ! preselected || ! preselected.key || ! preselected.id ) {
			return;
		}

		// Find the matching item in dimensionData.
		const items = dimensionData[ preselected.key ] || [];
		const matchedItem = items.find(
			( item ) => Number( item.id ) === Number( preselected.id )
		);
		if ( ! matchedItem ) {
			return;
		}

		// Only apply once — check if already selected.
		if ( dimensionSelections[ preselected.key ]?.id === matchedItem.id ) {
			return;
		}

		// Set the selection.
		setDimensionSelections( ( prev ) => ( {
			...prev,
			[ preselected.key ]: matchedItem,
		} ) );

		// Find the step index for this dimension and advance past it.
		const stepIndex = steps.findIndex(
			( s ) =>
				s.type === 'dimension' && s.dimension.key === preselected.key
		);
		if ( stepIndex >= 0 && currentStep <= stepIndex ) {
			setCurrentStep( stepIndex + 1 );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ config?.preselectedDimension, dimensionData, steps ] );

	// Fetch available dates when entering the date step (after all dimensions selected)
	useEffect( () => {
		const dateStepIndex = steps.findIndex( ( s ) => s.type === 'date' );
		if ( currentStep === dateStepIndex && dimensions.length > 0 ) {
			const allSelected = dimensions.every(
				( dim ) => dimensionSelections[ dim.key ]
			);
			if ( allSelected ) {
				fetchAvailableDates();
			}
		}
	}, [ currentStep, dimensionSelections ] );

	const fetchAvailableDates = async () => {
		setIsLoading( true );

		try {
			// Fetch the next 60 days of availability
			const startDate = new Date();
			const endDate = new Date();
			endDate.setDate( endDate.getDate() + 60 );

			const formData = new FormData();
			formData.append( 'action', 'clisyc_get_calendar_events' );
			formData.append( 'security', calendarNonce );
			formData.append(
				'start',
				startDate.toISOString().split( 'T' )[ 0 ]
			);
			formData.append( 'end', endDate.toISOString().split( 'T' )[ 0 ] );
			formData.append( 'hide_booked', 'true' );

			// Add dimension filters using the dimension key (e.g., clisyc_service[])
			dimensions.forEach( ( dim ) => {
				const selected = dimensionSelections[ dim.key ];
				if ( selected ) {
					formData.append( `${ dim.key }[]`, selected.id );
				}
			} );

			const response = await fetch( ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			} );

			const data = await response.json();

			if ( data.success && data.data?.events ) {
				// Extract unique dates that have bookable slots
				const datesWithSlots = new Set();
				data.data.events
					.filter( ( event ) => event.extendedProps?.isBookable )
					.forEach( ( event ) => {
						const date = new Date( event.start )
							.toISOString()
							.split( 'T' )[ 0 ];
						datesWithSlots.add( date );
					} );

				setAvailableDates( Array.from( datesWithSlots ) );
			} else {
				// No events returned - no dates available
				setAvailableDates( [] );
			}
		} catch ( error ) {
			console.error( 'Error fetching available dates:', error );
			// On error, allow all dates
			setAvailableDates( null );
		} finally {
			setIsLoading( false );
		}
	};

	// Fetch time slots when date changes
	useEffect( () => {
		if ( selectedDate && dimensions.length > 0 ) {
			// Check if all dimensions are selected
			const allSelected = dimensions.every(
				( dim ) => dimensionSelections[ dim.key ]
			);
			if ( allSelected ) {
				fetchTimeSlots();
			}
		}
	}, [ selectedDate, dimensionSelections ] );

	const fetchTimeSlots = async () => {
		setIsLoading( true );
		setTimeSlots( [] );

		try {
			const formData = new FormData();
			formData.append( 'action', 'clisyc_get_calendar_events' );
			formData.append( 'security', calendarNonce );
			formData.append(
				'start',
				selectedDate.toISOString().split( 'T' )[ 0 ]
			);
			formData.append(
				'end',
				new Date( selectedDate.getTime() + 86400000 )
					.toISOString()
					.split( 'T' )[ 0 ]
			);
			formData.append( 'hide_booked', 'true' );

			// Add dimension filters using the dimension key (e.g., clisyc_service[])
			dimensions.forEach( ( dim ) => {
				const selected = dimensionSelections[ dim.key ];
				if ( selected ) {
					formData.append( `${ dim.key }[]`, selected.id );
				}
			} );

			const response = await fetch( ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			} );

			const data = await response.json();

			if ( data.success && data.data?.events ) {
				// Filter for available (bookable) slots
				const slots = data.data.events
					.filter( ( event ) => event.extendedProps?.isBookable )
					.map( ( event ) => ( {
						id: event.extendedProps?.slotIdentifier || event.id,
						time: new Date( event.start )
							.toTimeString()
							.slice( 0, 5 ),
						start: event.start,
						end: event.end,
						slotId: event.extendedProps?.slot_id || 0,
						...event.extendedProps,
					} ) );

				setTimeSlots( slots );
			} else {
				setTimeSlots( [] );
			}
		} catch ( error ) {
			console.error( 'Error fetching time slots:', error );
		} finally {
			setIsLoading( false );
		}
	};

	// Dynamically resolve venue ID from the selected time slot's slot_id.
	useEffect( () => {
		if ( ! selectedTime || ! selectedTime.slotId ) {
			setResolvedVenueId( 0 );
			return;
		}

		apiFetch( {
			path: `/clisyc/v1/seats/resolve/${ selectedTime.slotId }`,
		} )
			.then( ( res ) => setResolvedVenueId( res.venue_id || 0 ) )
			.catch( () => setResolvedVenueId( 0 ) );
	}, [ selectedTime ] );

	// Reset seats when the time slot changes.
	useEffect( () => {
		setSelectedSeats( [] );
	}, [ selectedTime ] );

	// Handle seat selection changes.
	const handleSeatsChange = useCallback( ( seats ) => {
		setSelectedSeats( seats );
	}, [] );

	const handleDimensionSelect = ( dimensionKey, item ) => {
		setDimensionSelections( ( prev ) => ( {
			...prev,
			[ dimensionKey ]: item,
		} ) );

		// Clear downstream selections if this affects them
		// (e.g., changing service might need to clear practitioner)
		const dimIndex = dimensions.findIndex(
			( d ) => d.key === dimensionKey
		);
		if ( dimIndex >= 0 && dimIndex < dimensions.length - 1 ) {
			// Clear later dimension selections
			const keysToCheck = dimensions
				.slice( dimIndex + 1 )
				.map( ( d ) => d.key );
			const updatedSelections = {
				...dimensionSelections,
				[ dimensionKey ]: item,
			};

			keysToCheck.forEach( ( key ) => {
				const items = dimensionData[ key ] || [];
				const selected = dimensionSelections[ key ];
				if ( selected ) {
					// Check if still compatible
					const isCompatible = items.some(
						( i ) =>
							i.id === selected.id &&
							( ! i.compatibleWith ||
								! i.compatibleWith[ dimensionKey ] ||
								i.compatibleWith[ dimensionKey ].includes(
									item.id
								) )
					);
					if ( ! isCompatible ) {
						updatedSelections[ key ] = null;
					}
				}
			} );

			setDimensionSelections( updatedSelections );
		}
	};

	const validateDetails = () => {
		const errors = {};

		if ( ! contactDetails.firstName?.trim() ) {
			errors.firstName = 'First name is required';
		}
		if ( ! contactDetails.lastName?.trim() ) {
			errors.lastName = 'Last name is required';
		}
		if ( ! contactDetails.email?.trim() ) {
			errors.email = 'Email is required';
		} else if (
			! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( contactDetails.email )
		) {
			errors.email = 'Please enter a valid email address';
		}

		setFormErrors( errors );
		return Object.keys( errors ).length === 0;
	};

	const canProceed = () => {
		const step = steps[ currentStep ];

		switch ( step.type ) {
			case 'dimension':
				return (
					dimensionSelections[ step.dimension.key ] !== undefined &&
					dimensionSelections[ step.dimension.key ] !== null
				);
			case 'date':
				return selectedDate !== null;
			case 'time':
				return selectedTime !== null;
			case 'details':
				return (
					contactDetails.firstName &&
					contactDetails.lastName &&
					contactDetails.email
				);
			case 'confirm':
				return true;
			default:
				return false;
		}
	};

	const handleNext = () => {
		const step = steps[ currentStep ];

		if ( step.type === 'details' && ! validateDetails() ) {
			return;
		}

		if ( step.type === 'confirm' ) {
			handleSubmit();
		} else if ( canProceed() ) {
			setCurrentStep( ( prev ) =>
				Math.min( prev + 1, steps.length - 1 )
			);
		}
	};

	const handleBack = () => {
		setCurrentStep( ( prev ) => Math.max( prev - 1, 0 ) );
	};

	const handleStepClick = ( stepIndex ) => {
		if ( stepIndex < currentStep ) {
			setCurrentStep( stepIndex );
		}
	};

	const handleSubmit = async () => {
		setIsSubmitting( true );
		setSubmitError( null );

		try {
			const formData = new FormData();
			formData.append( 'action', 'clisyc_submit_booking' );
			formData.append( 'clisyc_appointment_nonce', nonce );
			formData.append( 'booking_mode', 'slot' );

			// Time slot - Use slotIdentifier which is already in UTC MySQL format (Y-m-d H:i:s)
			// This comes from CalendarDataProvider and matches what's stored in the slots table
			const timeSlotUtc = selectedTime.slotIdentifier;
			formData.append( 'time_slot', timeSlotUtc );
			formData.append(
				'time_slot_iso',
				selectedTime.start || timeSlotUtc
			);

			// Appointment date in Y-m-d format (from the UTC slot identifier)
			const appointmentDate = timeSlotUtc
				? timeSlotUtc.split( ' ' )[ 0 ]
				: selectedDate.toISOString().split( 'T' )[ 0 ];
			formData.append( 'appointment_date', appointmentDate );

			// Duration from selected service if available
			const primaryDim = dimensions[ 0 ];
			const primarySelection = primaryDim
				? dimensionSelections[ primaryDim.key ]
				: null;
			const duration =
				primarySelection?.duration ||
				selectedTime.durationMinutes ||
				60;
			formData.append( 'appointment_duration_minutes', duration );

			// Add dimension IDs - PHP expects the full dimension key (e.g., 'clisyc_service')
			dimensions.forEach( ( dim ) => {
				const selected = dimensionSelections[ dim.key ];
				if ( selected ) {
					formData.append( dim.key, selected.id );
				}
			} );

			// Guest info (for non-logged-in users)
			const isLoggedIn = config?.currentUser?.id;
			if ( ! isLoggedIn ) {
				formData.append(
					'guest_name',
					`${ contactDetails.firstName } ${ contactDetails.lastName }`
				);
				formData.append( 'guest_email', contactDetails.email );
			}

			// Notes as custom field
			if ( contactDetails.notes ) {
				formData.append(
					'clisyc_custom_field[notes]',
					contactDetails.notes
				);
			}

			// Seat selection data
			if ( selectedSeats && selectedSeats.length > 0 ) {
				formData.append(
					'selected_seats',
					JSON.stringify( selectedSeats )
				);
				const seatToken =
					sessionStorage.getItem( 'clisyc_seat_session_token' ) || '';
				formData.append( 'seat_session_token', seatToken );
			}

			const response = await fetch( ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			} );

			const data = await response.json();

			if ( data.success ) {
				// Check for redirect URL (WooCommerce payment flow)
				if ( data.data?.redirect_url ) {
					window.location.href = data.data.redirect_url;
					return;
				}

				// No redirect - show success screen
				setConfirmationNumber(
					data.data?.confirmation_number || data.data?.appointment_id
				);
				setIsComplete( true );
			} else {
				setSubmitError(
					data.data?.message ||
						'Failed to submit booking. Please try again.'
				);
			}
		} catch ( error ) {
			console.error( 'Error submitting booking:', error );
			setSubmitError( 'An error occurred. Please try again.' );
		} finally {
			setIsSubmitting( false );
		}
	};

	// Get previous selections for filtering
	const getPreviousSelections = ( currentDimKey ) => {
		const result = {};
		for ( const dim of dimensions ) {
			if ( dim.key === currentDimKey ) {
				break;
			}
			if ( dimensionSelections[ dim.key ] ) {
				result[ dim.key ] = dimensionSelections[ dim.key ].id;
			}
		}
		return result;
	};

	if ( isComplete ) {
		return (
			<div className="clisyc-booking-wizard">
				<SuccessScreen
					dimensions={ dimensions }
					selections={ dimensionSelections }
					date={ selectedDate }
					time={ selectedTime }
					details={ contactDetails }
					confirmationNumber={ confirmationNumber }
				/>
			</div>
		);
	}

	const renderCurrentStep = () => {
		const step = steps[ currentStep ];

		switch ( step.type ) {
			case 'dimension':
				return (
					<DimensionStep
						dimension={ step.dimension }
						items={ dimensionData[ step.dimension.key ] || [] }
						selectedItem={
							dimensionSelections[ step.dimension.key ]
						}
						onSelect={ ( item ) =>
							handleDimensionSelect( step.dimension.key, item )
						}
						previousSelections={ getPreviousSelections(
							step.dimension.key
						) }
					/>
				);
			case 'date':
				return (
					<DateStep
						selectedDate={ selectedDate }
						onSelect={ setSelectedDate }
						availableDates={ availableDates }
						isLoading={ isLoading && ! selectedDate }
					/>
				);
			case 'time':
				return (
					<TimeStep
						timeSlots={ timeSlots }
						selectedTime={ selectedTime }
						onSelect={ setSelectedTime }
						isLoading={ isLoading }
						selectedDate={ selectedDate }
					/>
				);
			case 'details':
				return (
					<DetailsStep
						details={ contactDetails }
						onChange={ setContactDetails }
						errors={ formErrors }
						currentUser={ currentUser }
					/>
				);
			case 'confirm':
				return (
					<>
						<ConfirmationStep
							dimensions={ dimensions }
							selections={ dimensionSelections }
							date={ selectedDate }
							time={ selectedTime }
							details={ contactDetails }
							isSubmitting={ isSubmitting }
							error={ submitError }
						/>
						{ resolvedVenueId > 0 && selectedTime?.slotId > 0 && (
							<SeatMapPicker
								venueId={ resolvedVenueId }
								slotId={ selectedTime.slotId }
								selectedSeats={ selectedSeats }
								onSeatsChange={ handleSeatsChange }
							/>
						) }
					</>
				);
			default:
				return null;
		}
	};

	return (
		<div className="clisyc-booking-wizard">
			<StepIndicator
				steps={ steps }
				currentStep={ currentStep }
				onStepClick={ handleStepClick }
			/>

			<div className="clisyc-wizard-content">{ renderCurrentStep() }</div>

			{ /* Selection summary - show on steps before confirmation */ }
			{ steps[ currentStep ].type !== 'confirm' && (
				<SelectionSummary
					dimensions={ dimensions }
					selections={ dimensionSelections }
					selectedDate={ selectedDate }
					selectedTime={ selectedTime }
				/>
			) }

			<div className="clisyc-wizard-navigation">
				<button
					type="button"
					className="clisyc-wizard-btn clisyc-wizard-btn-back"
					onClick={ handleBack }
					disabled={ currentStep === 0 }
				>
					<ChevronLeft /> Back
				</button>

				<button
					type="button"
					className="clisyc-wizard-btn clisyc-wizard-btn-next"
					onClick={ handleNext }
					disabled={ ! canProceed() || isSubmitting }
				>
					{ steps[ currentStep ].type === 'confirm' ? (
						isSubmitting ? (
							<>
								<SpinnerIcon /> Booking...
							</>
						) : (
							'Confirm Booking'
						)
					) : (
						<>
							Continue <ChevronRight />
						</>
					) }
				</button>
			</div>
		</div>
	);
};

// Initialize the wizard
document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'clisyc-booking-wizard-root' );
	if ( container && window.clisycBookingWizardData ) {
		const root = createRoot( container );
		root.render(
			<ErrorBoundary>
				<BookingWizard config={ window.clisycBookingWizardData } />
			</ErrorBoundary>
		);
	}
} );

export default BookingWizard;
