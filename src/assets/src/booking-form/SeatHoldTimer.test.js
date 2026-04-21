/**
 * File: src/assets/src/booking-form/SeatHoldTimer.test.js
 * Tests for the SeatHoldTimer countdown component.
 */
import React from 'react';
import { render, screen, act } from '@testing-library/react';
import SeatHoldTimer from './SeatHoldTimer';

import apiFetch from '@wordpress/api-fetch';

beforeEach( () => {
	jest.useFakeTimers();
	jest.clearAllMocks();
} );

afterEach( () => {
	jest.useRealTimers();
} );

const defaultProps = {
	venueId: 1,
	slotId: 42,
	sessionToken: 'test-token',
	expiresIn: 120,
	onExpired: jest.fn(),
	onRefreshed: jest.fn(),
};

describe( 'SeatHoldTimer', () => {
	it( 'renders the initial countdown', () => {
		render( <SeatHoldTimer { ...defaultProps } /> );

		expect( screen.getByText( /Seats held for/i ) ).toBeInTheDocument();
		// 120 seconds = 2:00
		expect( screen.getByText( '2:00' ) ).toBeInTheDocument();
	} );

	it( 'decrements each second', () => {
		render( <SeatHoldTimer { ...defaultProps } /> );

		act( () => {
			jest.advanceTimersByTime( 3000 );
		} );

		// 120 - 3 = 117 seconds = 1:57
		expect( screen.getByText( '1:57' ) ).toBeInTheDocument();
	} );

	it( 'pads single-digit seconds with leading zero', () => {
		render( <SeatHoldTimer { ...defaultProps } expiresIn={ 65 } /> );

		// 65 seconds = 1:05
		expect( screen.getByText( '1:05' ) ).toBeInTheDocument();

		act( () => {
			jest.advanceTimersByTime( 5000 );
		} );

		// 60 seconds = 1:00
		expect( screen.getByText( '1:00' ) ).toBeInTheDocument();
	} );

	it( 'fires refresh callback at T-30s', async () => {
		apiFetch.mockResolvedValueOnce( { expires_in: 300 } );

		render( <SeatHoldTimer { ...defaultProps } expiresIn={ 60 } /> );

		// Advance to 30 seconds remaining — use async act to flush the
		// apiFetch promise that resolves inside refreshHolds().
		await act( async () => {
			jest.advanceTimersByTime( 30000 );
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/clisyc/v1/seats/refresh',
				method: 'POST',
				data: expect.objectContaining( {
					venue_id: 1,
					slot_id: 42,
					session_token: 'test-token',
				} ),
			} )
		);
	} );

	it( 'calls onExpired when timer reaches zero', () => {
		render( <SeatHoldTimer { ...defaultProps } expiresIn={ 3 } /> );

		act( () => {
			jest.advanceTimersByTime( 3000 );
		} );

		expect( defaultProps.onExpired ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows 0:00 at expiry', () => {
		render( <SeatHoldTimer { ...defaultProps } expiresIn={ 2 } /> );

		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );

		expect( screen.getByText( '0:00' ) ).toBeInTheDocument();
	} );

	it( 'applies --expiring class when under 60 seconds', () => {
		const { container } = render(
			<SeatHoldTimer { ...defaultProps } expiresIn={ 59 } />
		);

		expect(
			container.querySelector( '.clisyc-hold-timer--expiring' )
		).toBeInTheDocument();
	} );

	it( 'does not apply --expiring class when above 60 seconds', () => {
		const { container } = render(
			<SeatHoldTimer { ...defaultProps } expiresIn={ 120 } />
		);

		expect(
			container.querySelector( '.clisyc-hold-timer--expiring' )
		).toBeNull();
	} );

	it( 'resets timer when expiresIn prop changes', () => {
		const { rerender } = render(
			<SeatHoldTimer { ...defaultProps } expiresIn={ 10 } />
		);

		act( () => {
			jest.advanceTimersByTime( 5000 );
		} );

		// 10 - 5 = 5 seconds = 0:05
		expect( screen.getByText( '0:05' ) ).toBeInTheDocument();

		// Simulate prop change (e.g. after refresh).
		rerender( <SeatHoldTimer { ...defaultProps } expiresIn={ 300 } /> );

		// Timer should reset to 5:00.
		expect( screen.getByText( '5:00' ) ).toBeInTheDocument();
	} );
} );
