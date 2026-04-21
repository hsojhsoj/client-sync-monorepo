/**
 * File: src/assets/src/booking-form/CalendarLegend.test.js
 * Tests for the CalendarLegend component.
 */
import React from 'react';
import { render, screen } from '@testing-library/react';
import CalendarLegend from './CalendarLegend';

beforeAll( () => {
	window.clisycBookingFormData = {
		l10n: {
			legendTitle: 'Legend:',
			legendAvailable: 'Available',
			legendBooked: 'Booked',
			legendBlocked: 'Blocked / Unavailable',
			legendToday: 'Today',
			legendCurrentTime: 'Current Time',
		},
	};
} );

afterAll( () => {
	delete window.clisycBookingFormData;
} );

describe( 'CalendarLegend', () => {
	it( 'renders the legend title', () => {
		render( <CalendarLegend /> );
		expect( screen.getByText( 'Legend:' ) ).toBeInTheDocument();
	} );

	it( 'shows Available, Blocked, Today, and Current Time items', () => {
		render( <CalendarLegend /> );

		expect( screen.getByText( 'Available' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Blocked / Unavailable' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Today' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Current Time' ) ).toBeInTheDocument();
	} );

	it( 'hides Booked item when showBooked is false', () => {
		render( <CalendarLegend showBooked={ false } /> );
		expect( screen.queryByText( 'Booked' ) ).not.toBeInTheDocument();
	} );

	it( 'shows Booked item when showBooked is true', () => {
		render( <CalendarLegend showBooked={ true } /> );
		expect( screen.getByText( 'Booked' ) ).toBeInTheDocument();
	} );

	it( 'renders all legend indicators with aria-hidden', () => {
		const { container } = render( <CalendarLegend showBooked={ true } /> );

		const indicators = container.querySelectorAll(
			'.clisyc-legend-indicator'
		);
		indicators.forEach( ( indicator ) => {
			expect( indicator ).toHaveAttribute( 'aria-hidden', 'true' );
		} );
	} );

	it( 'renders current time indicator as a line type', () => {
		const { container } = render( <CalendarLegend /> );

		const line = container.querySelector( '.clisyc-legend-line' );
		expect( line ).toBeInTheDocument();
	} );

	it( 'renders today indicator as a highlight type', () => {
		const { container } = render( <CalendarLegend /> );

		const highlight = container.querySelector( '.clisyc-legend-highlight' );
		expect( highlight ).toBeInTheDocument();
	} );

	it( 'uses default labels when l10n is empty', () => {
		window.clisycBookingFormData = { l10n: {} };

		render( <CalendarLegend /> );

		expect( screen.getByText( 'Legend:' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Available' ) ).toBeInTheDocument();

		// Restore
		window.clisycBookingFormData = {
			l10n: {
				legendTitle: 'Legend:',
				legendAvailable: 'Available',
				legendBooked: 'Booked',
				legendBlocked: 'Blocked / Unavailable',
				legendToday: 'Today',
				legendCurrentTime: 'Current Time',
			},
		};
	} );
} );
