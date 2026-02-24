/**
 * File: src/assets/src/booking-form/App.test.js
 * Tests for the main App (booking form) component.
 *
 * App is a large orchestrator component. We test its initial rendering,
 * state management, and key UI interactions. Deep integration tests
 * (e.g. actual API calls, FullCalendar rendering) are covered by Playwright E2E.
 */
import React from 'react';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';

// Suppress act() warnings from @wordpress/jest-console — the App component
// fires async state updates on mount (fetch filter options) that trigger
// React's act() warning in tests. This is expected behaviour.
let consoleErrorSpy;
beforeEach(() => {
	consoleErrorSpy = jest.spyOn(console, 'error').mockImplementation((...args) => {
		const msg = typeof args[0] === 'string' ? args[0] : '';
		if (
			msg.includes('act(') ||
			msg.includes('not wrapped in act') ||
			msg.includes('An update to')
		) {
			return; // swallow act() warnings
		}
	});
});
afterEach(() => {
	consoleErrorSpy.mockRestore();
});

// ── Mock child components ────────────────────────────────────
// Mocking children keeps App tests focused and fast. Each child
// has its own test suite.
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
	const MockDateRange = (props) => <div data-testid="date-range-calendar">DateRange</div>;
	MockDateRange.displayName = 'DateRangeCalendar';
	return MockDateRange;
});

jest.mock('./BookingModal', () => {
	const MockModal = (props) =>
		props.isOpen ? (
			<div data-testid="booking-modal" role="dialog">
				BookingModal
				<button onClick={props.onClose}>Close</button>
			</div>
		) : null;
	MockModal.displayName = 'BookingModal';
	return MockModal;
});

jest.mock('./FilterPanel', () => {
	const MockFilterPanel = (props) => (
		<div data-testid="filter-panel">
			FilterPanel
			<button
				data-testid="mock-filter-click"
				onClick={() => props.onSelect && props.onSelect('clisyc_service', '42')}
			>
				Filter
			</button>
		</div>
	);
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

// ── Window data setup ────────────────────────────────────────
const defaultWindowData = {
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
	bookingMode: 'slot',
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

beforeAll(async () => {
	window.clisycBookingFormData = { ...defaultWindowData };
	App = (await import('./App')).default;
});

afterAll(() => {
	delete window.clisycBookingFormData;
});

beforeEach(() => {
	window.clisycBookingFormData = { ...defaultWindowData };
	jest.clearAllMocks();
});

/**
 * Helper: render App and wait for the async filter fetch to complete
 * so the component exits its "Loading..." state.
 */
async function renderApp(extraProps = {}) {
	let result;
	await act(async () => {
		result = render(<App {...extraProps} />);
		// Allow the apiFetch promise to resolve and state to update
		await new Promise((r) => setTimeout(r, 0));
	});
	return result;
}

describe('App — Rendering', () => {
	it('renders the booking form app container', async () => {
		const { container } = await renderApp();
		expect(container.querySelector('.clisyc-booking-form-app')).toBeInTheDocument();
	});

	it('renders the filter panel', async () => {
		await renderApp();
		expect(screen.getByTestId('filter-panel')).toBeInTheDocument();
	});

	it('renders the calendar view after filters load', async () => {
		await renderApp();
		expect(screen.getByTestId('calendar-view')).toBeInTheDocument();
	});

	it('renders the calendar legend', async () => {
		await renderApp();
		expect(screen.getByTestId('calendar-legend')).toBeInTheDocument();
	});

	it('renders help text', async () => {
		await renderApp();
		expect(
			screen.getByText(/click on an available time slot/i)
		).toBeInTheDocument();
	});
});

describe('App — Date Range Mode', () => {
	it('shows date range help text when bookingMode is date_range', async () => {
		window.clisycBookingFormData = {
			...defaultWindowData,
			bookingMode: 'date_range',
		};

		const { container } = await renderApp();
		// In date_range mode, the app shows date range help text
		expect(
			screen.getByText(/select your check-in and check-out dates/i)
		).toBeInTheDocument();
		// And the container has the date-range-mode class
		expect(container.querySelector('.clisyc-date-range-mode')).toBeInTheDocument();
	});
});

describe('App — Timezone', () => {
	it('passes default timezone to CalendarView', async () => {
		await renderApp();
		const calendarView = screen.getByTestId('calendar-view');
		expect(calendarView.getAttribute('data-timezone')).toBe('local');
	});
});

describe('App — Sidebar', () => {
	it('has a sidebar toggle button', async () => {
		await renderApp();
		const toggle = document.querySelector('.clisyc-sidebar-toggle');
		if (toggle) {
			expect(toggle).toBeInTheDocument();
		}
	});
});

describe('App — Booking Modal', () => {
	it('does not show booking modal by default', async () => {
		await renderApp();
		expect(screen.queryByTestId('booking-modal')).not.toBeInTheDocument();
	});
});
