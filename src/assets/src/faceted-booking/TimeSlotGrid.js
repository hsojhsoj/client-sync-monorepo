/**
 * File: src/assets/src/faceted-booking/TimeSlotGrid.js
 * Time slot grid with enhanced features.
 *
 * FEATURES:
 * - Total available slots counter
 * - Timezone display with current time
 * - Toggle to show/hide booked slots
 * - Booked slots displayed as grayed out (when toggled on)
 * - Context-aware empty states
 *
 * @package
 * @subpackage ClientSync/Assets/JS/FacetedBooking
 */
import React, { useMemo, useCallback } from 'react';
import { SkeletonCard } from '../shared/SkeletonLoader';
import EmptyState from '../shared/EmptyState';
import '../shared/skeleton-loader.css';
import '../shared/empty-state.css';

const TimeSlotGrid = React.memo(
	( {
		isLoading,
		slots,
		onSlotSelect,
		hasActiveFilters = false,
		showBookedSlots = false,
		onToggleBookedSlots,
		timezoneDisplay,
	} ) => {
		const { filterOrder, l10n } = window.clisycFacetedBookingData;

		// Separate available and booked slots
		const { availableSlots, bookedSlots } = useMemo( () => {
			const available = [];
			const booked = [];

			slots.forEach( ( slot ) => {
				// Skip blocked slots always
				if ( slot.extendedProps?.is_block ) {
					return;
				}

				// Check if booked
				const isBooked =
					slot.extendedProps?.isBooked ||
					slot.extendedProps?.spotsLeft === 0;

				if ( isBooked ) {
					booked.push( slot );
				} else {
					available.push( slot );
				}
			} );

			return { availableSlots: available, bookedSlots: booked };
		}, [ slots ] );

		// Combine slots based on toggle
		const displaySlots = useMemo( () => {
			if ( showBookedSlots ) {
				// Combine and sort by start time
				return [ ...availableSlots, ...bookedSlots ].sort(
					( a, b ) => new Date( a.start ) - new Date( b.start )
				);
			}
			return availableSlots;
		}, [ availableSlots, bookedSlots, showBookedSlots ] );

		if ( isLoading ) {
			return (
				<div className="clisyc-compact-grid-loading">
					<SkeletonCard lines={ 4 } />
					<SkeletonCard lines={ 3 } />
					<SkeletonCard lines={ 2 } />
				</div>
			);
		}

		const getFullSlotDescription = useCallback(
			( slot ) => {
				const details = slot.extendedProps.serviceDetails || {};
				const descriptionParts = filterOrder
					.map( ( slug ) => {
						const jsKey = slug.replace( 'clisyc_', '' );
						return details[ `${ jsKey }_name` ];
					} )
					.filter( Boolean );

				return descriptionParts.join( ' • ' );
			},
			[ filterOrder ]
		);

		const getSlotColors = useCallback(
			( slot ) => {
				const details = slot.extendedProps.serviceDetails || {};
				const colors = [];

				filterOrder.forEach( ( slug ) => {
					const jsKey = slug.replace( 'clisyc_', '' );
					const colorKey = `${ jsKey }_color`;
					if ( details[ colorKey ] ) {
						colors.push( details[ colorKey ] );
					}
				} );

				return colors;
			},
			[ filterOrder ]
		);

		const isSlotBooked = useCallback( ( slot ) => {
			return (
				slot.extendedProps?.isBooked ||
				slot.extendedProps?.spotsLeft === 0
			);
		}, [] );

		// Group slots by date (memoized to avoid recalculation on every render)
		const slotsByDate = useMemo(
			() =>
				displaySlots.reduce( ( acc, slot ) => {
					const dateKey = new Date( slot.start ).toLocaleDateString(
						[],
						{
							weekday: 'long',
							month: 'long',
							day: 'numeric',
						}
					);
					if ( ! acc[ dateKey ] ) {
						acc[ dateKey ] = [];
					}
					acc[ dateKey ].push( slot );
					return acc;
				}, {} ),
			[ displaySlots ]
		);

		// Render header with timezone and controls
		const renderHeader = () => (
			<div className="clisyc-timeslot-header">
				<div className="clisyc-header-left">
					<span className="clisyc-slots-count">
						<strong>{ availableSlots.length }</strong>{ ' ' }
						{ availableSlots.length === 1 ? 'time' : 'times' }{ ' ' }
						available
					</span>
				</div>
				<div className="clisyc-header-right">
					{ /* Timezone display */ }
					{ timezoneDisplay && (
						<div
							className="clisyc-timezone-display"
							title={ timezoneDisplay.fullTimezone }
						>
							<span className="dashicons dashicons-clock"></span>
							<span className="clisyc-current-time">
								{ timezoneDisplay.time }
							</span>
							<span className="clisyc-timezone-abbr">
								{ timezoneDisplay.timezone }
							</span>
						</div>
					) }

					{ /* Show booked toggle */ }
					{ bookedSlots.length > 0 && onToggleBookedSlots && (
						<label className="clisyc-booked-toggle">
							<input
								type="checkbox"
								checked={ showBookedSlots }
								onChange={ onToggleBookedSlots }
							/>
							<span className="clisyc-toggle-slider"></span>
							<span className="clisyc-toggle-label">
								{ l10n?.showBooked || 'Show booked' } (
								{ bookedSlots.length })
							</span>
						</label>
					) }
				</div>
			</div>
		);

		// Context-aware empty state
		if ( availableSlots.length === 0 && ! showBookedSlots ) {
			return (
				<div className="clisyc-timeslot-grid-wrapper">
					{ bookedSlots.length > 0 && renderHeader() }
					{ hasActiveFilters ? (
						<EmptyState
							icon="search"
							title={
								l10n?.noMatchingSlots ||
								'No available times match your selected filters.'
							}
							message={
								l10n?.tryDifferentFilters ||
								'Try selecting different options or reset filters to see all availability.'
							}
						/>
					) : (
						<EmptyState
							icon="calendar"
							title={
								l10n?.noSlotsAvailable ||
								'No appointment times are currently available.'
							}
							message={
								l10n?.checkBackLater ||
								'Please check back later or contact us for assistance.'
							}
						/>
					) }
				</div>
			);
		}

		return (
			<div className="clisyc-timeslot-grid-wrapper">
				{ renderHeader() }

				<div className="clisyc-faceted-timeslot-grid">
					{ Object.entries( slotsByDate ).map(
						( [ date, dateSlots ] ) => (
							<div key={ date } className="clisyc-date-group">
								<h4 className="clisyc-date-header">{ date }</h4>
								<div className="clisyc-slots-container">
									{ dateSlots.map( ( slot ) => {
										const slotColors =
											getSlotColors( slot );
										const booked = isSlotBooked( slot );

										return (
											<button
												key={ slot.id }
												type="button"
												className={ `clisyc-slot-button ${
													booked
														? 'clisyc-slot-booked'
														: ''
												}` }
												onClick={ () =>
													! booked &&
													onSlotSelect( {
														...slot.extendedProps,
														start: slot.start,
														fullDescription:
															getFullSlotDescription(
																slot
															),
													} )
												}
												disabled={ booked }
												title={
													booked
														? l10n?.slotBooked ||
														  'This time is already booked'
														: ''
												}
											>
												<span className="clisyc-slot-button-time">
													{ new Date(
														slot.start
													).toLocaleTimeString( [], {
														hour: 'numeric',
														minute: '2-digit',
													} ) }
													{ slotColors.length > 0 && (
														<span className="clisyc-slot-color-indicators">
															{ slotColors.map(
																(
																	color,
																	index
																) => (
																	<span
																		key={
																			index
																		}
																		className="clisyc-slot-color-dot"
																		style={ {
																			backgroundColor:
																				color,
																		} }
																		title={ `Dimension ${
																			index +
																			1
																		}` }
																	></span>
																)
															) }
														</span>
													) }
												</span>
												<span className="clisyc-slot-button-details">
													{ booked && (
														<span className="clisyc-booked-badge">
															{ l10n?.booked ||
																'Booked' }
														</span>
													) }
													{ getFullSlotDescription(
														slot
													) }
												</span>
											</button>
										);
									} ) }
								</div>
							</div>
						)
					) }
				</div>
			</div>
		);
	}
);

TimeSlotGrid.displayName = 'TimeSlotGrid';

export default TimeSlotGrid;
