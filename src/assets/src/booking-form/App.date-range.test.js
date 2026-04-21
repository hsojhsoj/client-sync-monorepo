/**
 * File: src/assets/src/booking-form/App.date-range.test.js
 * Tests for App in date-range booking mode.
 *
 * Separated from App.test.js because BookingContext.js reads
 * window.clisycBookingFormData ONCE at module load (IIFE), so each
 * distinct config permutation needs its own test file with a dedicated
 * beforeAll that sets the window data before the dynamic import.
 */
import React from 'react';
import { render, screen, act } from '@testing-library/react';

// ── Mock child components (kept in sync with App.test.js) ────
jest.mock('./CalendarView', () => {
	const MockCalendarView = (props) => (
		<div data-testid="calendar-view" data-timezone={props.timezone}>
			CalendarView
		</div>
	);
	MockCalendarView.displayName = 'CalendarView';
	return MockCalendarView;
});

jest.mock('./DateRangeCalendar', () => {
	const MockDateRange = () => <div data-testid="date-range-calendar">DateRange</div>;
	MockDateRange.displayName = 'DateRangeCalendar';
	return MockDateRange;
});

jest.mock('./BookingModal', () => {
	const MockModal = (props) =>
		props.isOpen ? (
			<div data-testid="booking-modal" role="dialog">
				BookingModal
			</div>
		) : null;
	MockModal.displayName = 'BookingModal';
	return MockModal;
});

jest.mock('./FilterPanel', () => {
	const MockFilterPanel = () => <div data-testid="filter-panel">FilterPanel</div>;
	MockFilterPanel.displayName = 'FilterPanel';
	return MockFilterPanel;
});

jest.mock('./CalendarLegend', () => {
	const MockLegend = () => <div data-testid="calendar-legend">Legend</div>;
	MockLegend.displayName = 'CalendarLegend';
	return MockLegend;
});

jest.mock('./CalendarNotice', () => {
	const MockNotice = () => null;
	MockNotice.displayName = 'CalendarNotice';
	return MockNotice;
});

jest.mock('./TimezoneMap', () => {
	const MockTzMap = () => <div data-testid="timezone-map">TimezoneMap</div>;
	MockTzMap.getTimezoneOffsetLabel = () => '+00:00';
	MockTzMap.displayName = 'TimezoneMap';
	return MockTzMap;
});

jest.mock('./components/AnalogClock', () => {
	const MockClock = () => <div data-testid="analog-clock">Clock</div>;
	MockClock.displayName = 'AnalogClock';
	return MockClock;
});

jest.mock('./components/EventTooltip', () => {
	const MockTooltip = () => null;
	MockTooltip.displayName = 'EventTooltip';
	return MockTooltip;
});

jest.mock('./components/MiniCalendar', () => {
	const MockMini = () => <div data-testid="mini-calendar">MiniCalendar</div>;
	MockMini.displayName = 'MiniCalendar';
	return MockMini;
});

jest.mock('../shared/decode-html-entities', () => (val) => val);

jest.mock('@wordpress/api-fetch', () =>
	jest.fn(() =>
		Promise.resolve({
			filters: {},
			events: [],
		})
	)
);

// Suppress expected act() warnings from async mount-time state updates.
let consoleErrorSpy;
beforeEach(() => {
	consoleErrorSpy = jest.spyOn(console, 'error').mockImplementation((...args) => {
		const msg = typeof args[0] === 'string' ? args[0] : '';
		if (
			msg.includes('act(') ||
			msg.includes('not wrapped in act') ||
			msg.includes('An update to')
		) {
			return;
		}
	});
});
afterEach(() => {
	consoleErrorSpy.mockRestore();
});

const dateRangeWindowData = {
	restUrl: 'https://test.example.com/wp-json/clisyc/v1/',
	restNonce: 'test-nonce',
	filterOrder: ['clisyc_service'],
	formNonce: 'form-nonce',
	availabilityDimensions: {
		clisyc_service: {
			label: 'Service',
			icon: '',
			options_meta: [],
		},
	},
	isGuest: true,
	isAdmin: false,
	bookingMode: 'date_range',
	preselectedServiceId: 0,
	primaryItemName: '',
	showBookedToggle: true,
	showBookedDefault: false,
	currencySymbol: '$',
	wc_integration_enabled: false,
	stripe_integration_enabled: false,
	customFieldDefinitions: [],
	calendarOptions: {
		timeZone: 'local',
		slotMinTime: '08:00',
		slotMaxTime: '18:00',
		startOfWeek: 0,
		slotDuration: '00:30:00',
		slotHeightPerHour: 80,
		enabledViews: ['timeGridWeek'],
	},
	colorSettings: {},
	successPageUrl: '/booking-confirmed/',
	contactPageUrl: '/contact/',
	smartStartDateEnabled: false,
	nextAvailableDate: null,
	generationLookahead: 30,
	l10n: {
		headsUp: 'Heads Up',
		jumpedAheadMessage: 'We jumped ahead',
		noAvailabilityTitle: 'No Availability',
	},
};

let App;
let BookingProvider;

beforeAll(async () => {
	window.clisycBookingFormData = { ...dateRangeWindowData };
	BookingProvider = (await import('./BookingContext')).BookingProvider;
	App = (await import('./App')).default;
});

afterAll(() => {
	delete window.clisycBookingFormData;
});

beforeEach(() => {
	window.clisycBookingFormData = { ...dateRangeWindowData };
	jest.clearAllMocks();
});

async function renderApp(extraProps = {}) {
	let result;
	await act(async () => {
		result = render(
			<BookingProvider>
				<App {...extraProps} />
			</BookingProvider>
		);
		await new Promise((r) => setTimeout(r, 0));
	});
	return result;
}

describe('App — Date Range Mode', () => {
	it('shows date range help text when bookingMode is date_range', async () => {
		const { container } = await renderApp();
		expect(
			screen.getByText(/select your check-in and check-out dates/i)
		).toBeInTheDocument();
		expect(container.querySelector('.clisyc-date-range-mode')).toBeInTheDocument();
	});
});
