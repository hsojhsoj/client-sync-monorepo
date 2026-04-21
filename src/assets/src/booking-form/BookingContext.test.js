/**
 * File: src/assets/src/booking-form/BookingContext.test.js
 * Tests for the BookingContext (reducer, provider, hook).
 */
import React from 'react';
import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { BookingProvider, useBooking, ActionTypes } from './BookingContext';

// Mock window.clisycBookingFormData
beforeAll( () => {
	window.clisycBookingFormData = {
		showBookedDefault: false,
	};
} );

afterAll( () => {
	delete window.clisycBookingFormData;
} );

/**
 * Helper component that exposes state and dispatch via rendered output.
 * @param root0
 * @param root0.actionToDispatch
 */
const TestConsumer = ( { actionToDispatch } = {} ) => {
	const { state, dispatch } = useBooking();

	return (
		<div>
			<span data-testid="timezone">{ state.timezone }</span>
			<span data-testid="isLoadingFilters">
				{ state.isLoadingFilters.toString() }
			</span>
			<span data-testid="isBookingModalOpen">
				{ state.isBookingModalOpen.toString() }
			</span>
			<span data-testid="showBooked">
				{ state.showBooked.toString() }
			</span>
			<span data-testid="isSidebarCollapsed">
				{ state.isSidebarCollapsed.toString() }
			</span>
			<span data-testid="basket-total">{ state.basket.total }</span>
			<button
				data-testid="dispatch"
				onClick={ () => {
					if ( actionToDispatch ) {
						dispatch( actionToDispatch );
					}
				} }
			/>
		</div>
	);
};

describe( 'BookingContext', () => {
	it( 'provides default state through the context', () => {
		render(
			<BookingProvider>
				<TestConsumer />
			</BookingProvider>
		);

		expect( screen.getByTestId( 'timezone' ) ).toHaveTextContent( 'local' );
		expect( screen.getByTestId( 'isLoadingFilters' ) ).toHaveTextContent(
			'true'
		);
		expect( screen.getByTestId( 'isBookingModalOpen' ) ).toHaveTextContent(
			'false'
		);
		expect( screen.getByTestId( 'showBooked' ) ).toHaveTextContent(
			'false'
		);
		expect( screen.getByTestId( 'basket-total' ) ).toHaveTextContent( '0' );
	} );

	it( 'throws when useBooking is used outside BookingProvider', () => {
		// Suppress console.error for this test
		const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation();

		expect( () => {
			render( <TestConsumer /> );
		} ).toThrow( 'useBooking must be used within a BookingProvider' );

		consoleSpy.mockRestore();
	} );

	it( 'dispatches SET_TIMEZONE', () => {
		const Wrapper = () => {
			const { state, dispatch } = useBooking();
			return (
				<>
					<span data-testid="tz">{ state.timezone }</span>
					<button
						onClick={ () =>
							dispatch( {
								type: ActionTypes.SET_TIMEZONE,
								payload: 'America/New_York',
							} )
						}
					>
						Change TZ
					</button>
				</>
			);
		};

		render(
			<BookingProvider>
				<Wrapper />
			</BookingProvider>
		);

		expect( screen.getByTestId( 'tz' ) ).toHaveTextContent( 'local' );

		act( () => {
			screen.getByText( 'Change TZ' ).click();
		} );

		expect( screen.getByTestId( 'tz' ) ).toHaveTextContent(
			'America/New_York'
		);
	} );

	it( 'dispatches SET_IS_BOOKING_MODAL_OPEN', () => {
		const Wrapper = () => {
			const { state, dispatch } = useBooking();
			return (
				<>
					<span data-testid="open">
						{ state.isBookingModalOpen.toString() }
					</span>
					<button
						onClick={ () =>
							dispatch( {
								type: ActionTypes.SET_IS_BOOKING_MODAL_OPEN,
								payload: true,
							} )
						}
					>
						Open
					</button>
				</>
			);
		};

		render(
			<BookingProvider>
				<Wrapper />
			</BookingProvider>
		);

		expect( screen.getByTestId( 'open' ) ).toHaveTextContent( 'false' );

		act( () => {
			screen.getByText( 'Open' ).click();
		} );

		expect( screen.getByTestId( 'open' ) ).toHaveTextContent( 'true' );
	} );

	it( 'dispatches UPDATE_SELECTED_FILTER', () => {
		const Wrapper = () => {
			const { state, dispatch } = useBooking();
			return (
				<>
					<span data-testid="filters">
						{ JSON.stringify( state.selectedFilters ) }
					</span>
					<button
						onClick={ () =>
							dispatch( {
								type: ActionTypes.UPDATE_SELECTED_FILTER,
								payload: {
									key: 'clisyc_service',
									value: [ '1', '2' ],
								},
							} )
						}
					>
						Select
					</button>
				</>
			);
		};

		render(
			<BookingProvider>
				<Wrapper />
			</BookingProvider>
		);

		act( () => {
			screen.getByText( 'Select' ).click();
		} );

		const filters = JSON.parse(
			screen.getByTestId( 'filters' ).textContent
		);
		expect( filters.clisyc_service ).toEqual( [ '1', '2' ] );
	} );

	it( 'dispatches RESET_SELECTIONS', () => {
		const Wrapper = () => {
			const { state, dispatch } = useBooking();
			return (
				<>
					<span data-testid="slot">
						{ state.selectedSlot ? 'has-slot' : 'null' }
					</span>
					<button
						onClick={ () => {
							dispatch( {
								type: ActionTypes.SET_SELECTED_SLOT,
								payload: { id: 'test' },
							} );
						} }
					>
						Set Slot
					</button>
					<button
						onClick={ () =>
							dispatch( { type: ActionTypes.RESET_SELECTIONS } )
						}
					>
						Reset
					</button>
				</>
			);
		};

		render(
			<BookingProvider>
				<Wrapper />
			</BookingProvider>
		);

		act( () => {
			screen.getByText( 'Set Slot' ).click();
		} );
		expect( screen.getByTestId( 'slot' ) ).toHaveTextContent( 'has-slot' );

		act( () => {
			screen.getByText( 'Reset' ).click();
		} );
		expect( screen.getByTestId( 'slot' ) ).toHaveTextContent( 'null' );
	} );

	it( 'dispatches SET_BASKET', () => {
		const Wrapper = () => {
			const { state, dispatch } = useBooking();
			return (
				<>
					<span data-testid="total">{ state.basket.total }</span>
					<button
						onClick={ () =>
							dispatch( {
								type: ActionTypes.SET_BASKET,
								payload: {
									total: 150,
									formatted: '$150.00',
									items: [ { label: 'Service', price: 150 } ],
								},
							} )
						}
					>
						Set Basket
					</button>
				</>
			);
		};

		render(
			<BookingProvider>
				<Wrapper />
			</BookingProvider>
		);

		act( () => {
			screen.getByText( 'Set Basket' ).click();
		} );

		expect( screen.getByTestId( 'total' ) ).toHaveTextContent( '150' );
	} );

	// ── Seat Selection Actions ──────────────────────────────────────

	it( 'dispatches SET_SELECTED_SEATS', () => {
		const Wrapper = () => {
			const { state, dispatch } = useBooking();
			return (
				<>
					<span data-testid="seats">
						{ JSON.stringify( state.selectedSeats ) }
					</span>
					<button
						onClick={ () =>
							dispatch( {
								type: ActionTypes.SET_SELECTED_SEATS,
								payload: [ 'vip-row1-s1', 'vip-row1-s2' ],
							} )
						}
					>
						Select Seats
					</button>
				</>
			);
		};

		render(
			<BookingProvider>
				<Wrapper />
			</BookingProvider>
		);

		expect( screen.getByTestId( 'seats' ) ).toHaveTextContent( '[]' );

		act( () => {
			screen.getByText( 'Select Seats' ).click();
		} );

		const seats = JSON.parse( screen.getByTestId( 'seats' ).textContent );
		expect( seats ).toEqual( [ 'vip-row1-s1', 'vip-row1-s2' ] );
	} );

	it( 'dispatches SET_SEAT_HOLD_EXPIRY', () => {
		const Wrapper = () => {
			const { state, dispatch } = useBooking();
			return (
				<>
					<span data-testid="expiry">{ state.seatHoldExpiry }</span>
					<button
						onClick={ () =>
							dispatch( {
								type: ActionTypes.SET_SEAT_HOLD_EXPIRY,
								payload: 1700000000,
							} )
						}
					>
						Set Expiry
					</button>
				</>
			);
		};

		render(
			<BookingProvider>
				<Wrapper />
			</BookingProvider>
		);

		expect( screen.getByTestId( 'expiry' ) ).toHaveTextContent( '0' );

		act( () => {
			screen.getByText( 'Set Expiry' ).click();
		} );

		expect( screen.getByTestId( 'expiry' ) ).toHaveTextContent(
			'1700000000'
		);
	} );

	it( 'RESET_SELECTIONS clears selectedSeats', () => {
		const Wrapper = () => {
			const { state, dispatch } = useBooking();
			return (
				<>
					<span data-testid="seats">
						{ JSON.stringify( state.selectedSeats ) }
					</span>
					<button
						onClick={ () =>
							dispatch( {
								type: ActionTypes.SET_SELECTED_SEATS,
								payload: [ 's1', 's2' ],
							} )
						}
					>
						Add Seats
					</button>
					<button
						onClick={ () =>
							dispatch( { type: ActionTypes.RESET_SELECTIONS } )
						}
					>
						Reset All
					</button>
				</>
			);
		};

		render(
			<BookingProvider>
				<Wrapper />
			</BookingProvider>
		);

		act( () => {
			screen.getByText( 'Add Seats' ).click();
		} );
		expect(
			JSON.parse( screen.getByTestId( 'seats' ).textContent )
		).toHaveLength( 2 );

		act( () => {
			screen.getByText( 'Reset All' ).click();
		} );
		expect(
			JSON.parse( screen.getByTestId( 'seats' ).textContent )
		).toHaveLength( 0 );
	} );

	it( 'unknown action type returns state unchanged', () => {
		const Wrapper = () => {
			const { state, dispatch } = useBooking();
			return (
				<>
					<span data-testid="tz">{ state.timezone }</span>
					<button
						onClick={ () =>
							dispatch( {
								type: 'UNKNOWN_ACTION',
								payload: 'test',
							} )
						}
					>
						Unknown
					</button>
				</>
			);
		};

		render(
			<BookingProvider>
				<Wrapper />
			</BookingProvider>
		);

		act( () => {
			screen.getByText( 'Unknown' ).click();
		} );

		// State should remain unchanged
		expect( screen.getByTestId( 'tz' ) ).toHaveTextContent( 'local' );
	} );
} );
