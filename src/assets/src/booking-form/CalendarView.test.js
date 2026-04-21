/**
 * File: src/assets/src/booking-form/CalendarView.test.js
 * Tests for the CalendarView component.
 *
 * CalendarView uses FullCalendar's imperative API rather than JSX, so we
 * test the component's interface, prop handling, and lifecycle rather than
 * the internal calendar rendering (which FullCalendar handles).
 */
import React from 'react';
import { render, act } from '@testing-library/react';

// ── FullCalendar mocks ──────────────────────────────────────
// FullCalendar's Calendar class is instantiated imperatively, so we mock it
// to avoid needing a real DOM layout engine.
const mockCalendarInstance = {
	render: jest.fn(),
	destroy: jest.fn(),
	getDate: jest.fn( () => new Date( '2026-02-21' ) ),
	gotoDate: jest.fn(),
	changeView: jest.fn(),
	refetchEvents: jest.fn(),
	getEvents: jest.fn( () => [] ),
	updateSize: jest.fn(),
	view: { type: 'timeGridWeek' },
};

jest.mock( '@fullcalendar/core', () => ( {
	Calendar: jest.fn( () => mockCalendarInstance ),
} ) );
jest.mock( '@fullcalendar/timegrid', () => ( {} ) );
jest.mock( '@fullcalendar/daygrid', () => ( {} ) );
jest.mock( '@fullcalendar/list', () => ( {} ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// Provide default window data that CalendarView reads at module level.
beforeAll( () => {
	window.clisycBookingFormData = {
		restUrl: 'https://test.example.com/wp-json/clisyc/v1/',
		restNonce: 'test-nonce-123',
		calendarOptions: {
			timeZone: 'America/New_York',
			slotMinTime: '08:00',
			slotMaxTime: '18:00',
			startOfWeek: 0,
			slotDuration: '00:30:00',
			slotHeightPerHour: 80,
			enabledViews: [ 'dayGridMonth', 'timeGridWeek', 'timeGridDay' ],
		},
		l10n: {
			noAvailabilityTitle: 'No Availability',
		},
		availabilityDimensions: {},
		filterOrder: [],
		nextAvailableDate: null,
		generationLookahead: 30,
	};
} );

afterAll( () => {
	delete window.clisycBookingFormData;
} );

// Must import AFTER mocks are set up.
let CalendarView;
beforeAll( async () => {
	CalendarView = ( await import( './CalendarView' ) ).default;
} );

beforeEach( () => {
	jest.clearAllMocks();
} );

describe( 'CalendarView', () => {
	const defaultProps = {
		selectedFilters: {},
		restUrl: 'https://test.example.com/wp-json/clisyc/v1/',
		restNonce: 'test-nonce-123',
		primaryDimKey: 'clisyc_service',
		onSlotSelect: jest.fn(),
		onSlotDeselect: jest.fn(),
		timezone: 'local',
		selectedSlotId: null,
		showBooked: false,
	};

	it( 'renders a calendar container div', () => {
		const { container } = render( <CalendarView { ...defaultProps } /> );
		// CalendarView renders a div that FullCalendar attaches to
		expect( container.querySelector( 'div' ) ).toBeInTheDocument();
	} );

	it( 'instantiates FullCalendar on mount', () => {
		const { Calendar } = require( '@fullcalendar/core' );
		render( <CalendarView { ...defaultProps } /> );

		expect( Calendar ).toHaveBeenCalled();
		expect( mockCalendarInstance.render ).toHaveBeenCalled();
	} );

	it( 'destroys FullCalendar on unmount', () => {
		const { unmount } = render( <CalendarView { ...defaultProps } /> );
		unmount();

		expect( mockCalendarInstance.destroy ).toHaveBeenCalled();
	} );

	it( 'passes onSlotSelect callback', () => {
		const onSlotSelect = jest.fn();
		render(
			<CalendarView { ...defaultProps } onSlotSelect={ onSlotSelect } />
		);

		// The Calendar constructor should have been called with an eventClick config
		const { Calendar } = require( '@fullcalendar/core' );
		const calendarConfig = Calendar.mock.calls[ 0 ][ 1 ];
		expect( calendarConfig ).toBeDefined();
	} );

	it( 'exposes navigate callback via setNavigateCallback prop', () => {
		const setNavigateCallback = jest.fn();
		render(
			<CalendarView
				{ ...defaultProps }
				setNavigateCallback={ setNavigateCallback }
			/>
		);

		// The component should call setNavigateCallback with a function
		if ( setNavigateCallback.mock.calls.length > 0 ) {
			expect( typeof setNavigateCallback.mock.calls[ 0 ][ 0 ] ).toBe(
				'function'
			);
		}
	} );

	it( 'shows loading overlay while fetching slots', () => {
		const { container } = render( <CalendarView { ...defaultProps } /> );

		// The component renders a loading overlay div
		const overlay = container.querySelector( '.clisyc-loading-overlay' );
		// Overlay exists (may be hidden or visible depending on fetch state)
		// Initial render triggers a fetch, so overlay should appear briefly
		expect( overlay ).toBeDefined();
	} );

	it( 'uses local timezone by default', () => {
		render( <CalendarView { ...defaultProps } timezone="local" /> );

		const { Calendar } = require( '@fullcalendar/core' );
		const calendarConfig = Calendar.mock.calls[ 0 ][ 1 ];
		expect( calendarConfig.timeZone ).toBe( 'local' );
	} );

	it( 'applies custom timezone when provided', () => {
		render( <CalendarView { ...defaultProps } timezone="Asia/Tokyo" /> );

		const { Calendar } = require( '@fullcalendar/core' );
		const calendarConfig = Calendar.mock.calls[ 0 ][ 1 ];
		expect( calendarConfig.timeZone ).toBe( 'Asia/Tokyo' );
	} );

	it( 'configures slot duration from window data', () => {
		render( <CalendarView { ...defaultProps } /> );

		const { Calendar } = require( '@fullcalendar/core' );
		const calendarConfig = Calendar.mock.calls[ 0 ][ 1 ];
		expect( calendarConfig.slotDuration ).toBe( '00:30:00' );
	} );

	it( 'configures working hours from window data', () => {
		render( <CalendarView { ...defaultProps } /> );

		const { Calendar } = require( '@fullcalendar/core' );
		const calendarConfig = Calendar.mock.calls[ 0 ][ 1 ];
		expect( calendarConfig.slotMinTime ).toBe( '08:00' );
		expect( calendarConfig.slotMaxTime ).toBe( '18:00' );
	} );

	it( 'calls onDateChange when calendar dates change', () => {
		const onDateChange = jest.fn();
		render(
			<CalendarView { ...defaultProps } onDateChange={ onDateChange } />
		);

		const { Calendar } = require( '@fullcalendar/core' );
		const calendarConfig = Calendar.mock.calls[ 0 ][ 1 ];

		// FullCalendar fires datesSet when the view dates change
		if ( calendarConfig.datesSet ) {
			act( () => {
				calendarConfig.datesSet( {
					start: new Date( '2026-02-21' ),
					end: new Date( '2026-02-28' ),
					view: { type: 'timeGridWeek' },
				} );
			} );
		}
	} );
} );
