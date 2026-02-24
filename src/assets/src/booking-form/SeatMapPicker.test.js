/**
 * File: src/assets/src/booking-form/SeatMapPicker.test.js
 * Tests for the SeatMapPicker interactive SVG seat selection component.
 */
import React from 'react';
import { render, screen, waitFor, act, fireEvent } from '@testing-library/react';
import SeatMapPicker from './SeatMapPicker';

import apiFetch from '@wordpress/api-fetch';

// Mock SeatHoldTimer to avoid timer complexity in these tests.
jest.mock('./SeatHoldTimer', () => {
	return function MockSeatHoldTimer() {
		return <div data-testid="hold-timer">Timer</div>;
	};
});

// Mock crypto.randomUUID for sessionStorage token.
beforeAll(() => {
	Object.defineProperty(global, 'crypto', {
		value: { randomUUID: () => 'mock-uuid-token' },
	});
});

const mockLayout = {
	parsing_mode: 'flat_ids',
	total_seats: 3,
	sections: [
		{
			id: 'vip',
			label: 'VIP',
			category: 'vip',
			svg_element_id: 'vip',
			rows: [
				{
					id: 'row1',
					label: 'Row 1',
					svg_element_id: 'row1',
					seats: [
						{ id: 'vip-row1-s1', label: 'S1', svg_element_id: 'vip-row1-s1', category: 'vip', status: 'active' },
						{ id: 'vip-row1-s2', label: 'S2', svg_element_id: 'vip-row1-s2', category: 'vip', status: 'active' },
					],
				},
			],
		},
		{
			id: 'general',
			label: 'General',
			category: '',
			svg_element_id: 'general',
			rows: [
				{
					id: 'row2',
					label: 'Row 2',
					svg_element_id: 'row2',
					seats: [
						{ id: 'gen-row2-s1', label: 'S1', svg_element_id: 'gen-row2-s1', category: '', status: 'active' },
					],
				},
			],
		},
	],
};

const mockSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100">
	<rect id="vip-row1-s1" x="10" y="10" width="20" height="20" />
	<rect id="vip-row1-s2" x="40" y="10" width="20" height="20" />
	<rect id="gen-row2-s1" x="10" y="50" width="20" height="20" />
</svg>`;

const mockAvailability = { held: [], booked: [], mine: [] };

const defaultProps = {
	venueId: 1,
	slotId: 42,
	selectedSeats: [],
	onSeatsChange: jest.fn(),
	onHoldExpiry: jest.fn(),
};

/**
 * Set up apiFetch to return layout + availability for the standard case.
 */
function setupSuccessfulFetch() {
	apiFetch.mockImplementation((opts) => {
		if (opts.path?.includes('/seats/layout/')) {
			return Promise.resolve({ layout: mockLayout, svg: mockSvg, hold_ttl: 300 });
		}
		if (opts.path?.includes('/seats/availability/')) {
			return Promise.resolve(mockAvailability);
		}
		if (opts.method === 'POST' && opts.path?.includes('/seats/hold')) {
			return Promise.resolve({ held: true, expires_in: 300 });
		}
		if (opts.method === 'DELETE' && opts.path?.includes('/seats/hold')) {
			return Promise.resolve({ released: true });
		}
		return Promise.resolve({});
	});
}

describe('SeatMapPicker', () => {
	beforeEach(() => {
		jest.clearAllMocks();
		sessionStorage.clear();
	});

	it('renders nothing when venueId is 0', () => {
		const { container } = render(
			<SeatMapPicker {...defaultProps} venueId={0} />
		);
		// Should show nothing — no loading, no error.
		expect(container.querySelector('.clisyc-seat-map')).toBeNull();
	});

	it('shows loading spinner while fetching layout', () => {
		// Make fetch hang indefinitely.
		apiFetch.mockReturnValue(new Promise(() => {}));

		render(<SeatMapPicker {...defaultProps} />);

		expect(screen.getByText(/Loading seat map/i)).toBeInTheDocument();
	});

	it('shows error message on fetch failure', async () => {
		apiFetch.mockRejectedValue(new Error('Network error'));

		render(<SeatMapPicker {...defaultProps} />);

		await waitFor(() => {
			expect(screen.getByText(/Network error/i)).toBeInTheDocument();
		});
	});

	it('renders section overview when layout has multiple sections', async () => {
		setupSuccessfulFetch();

		render(<SeatMapPicker {...defaultProps} />);

		await waitFor(() => {
			expect(screen.getByText(/Select a section/i)).toBeInTheDocument();
		});

		// Section cards should be visible.
		expect(screen.getByText('VIP')).toBeInTheDocument();
		expect(screen.getByText('General')).toBeInTheDocument();
	});

	it('renders legend with all statuses', async () => {
		setupSuccessfulFetch();

		render(<SeatMapPicker {...defaultProps} />);

		await waitFor(() => {
			expect(screen.getByText('Available')).toBeInTheDocument();
		});

		expect(screen.getByText('Selected')).toBeInTheDocument();
		expect(screen.getByText('Held')).toBeInTheDocument();
		expect(screen.getByText('Booked')).toBeInTheDocument();
	});

	it('shows seat count on section cards', async () => {
		setupSuccessfulFetch();

		render(<SeatMapPicker {...defaultProps} />);

		await waitFor(() => {
			expect(screen.getByText('VIP')).toBeInTheDocument();
		});

		// VIP section has 2 seats, General has 1.
		expect(screen.getByText(/2 seats/i)).toBeInTheDocument();
		expect(screen.getByText(/1 seats/i)).toBeInTheDocument();
	});

	it('shows selected seat count', async () => {
		setupSuccessfulFetch();

		render(
			<SeatMapPicker
				{...defaultProps}
				selectedSeats={['vip-row1-s1', 'vip-row1-s2']}
			/>
		);

		await waitFor(() => {
			expect(screen.getByText(/2 seats selected/i)).toBeInTheDocument();
		});
	});

	it('shows singular "seat selected" for single selection', async () => {
		setupSuccessfulFetch();

		render(
			<SeatMapPicker {...defaultProps} selectedSeats={['vip-row1-s1']} />
		);

		await waitFor(() => {
			expect(screen.getByText(/1 seat selected/i)).toBeInTheDocument();
		});
	});

	it('renders hold timer when seats are selected and holdActive', async () => {
		// Return a layout response with existing holds for this session.
		apiFetch.mockImplementation((opts) => {
			if (opts.path?.includes('/seats/layout/')) {
				return Promise.resolve({ layout: mockLayout, svg: mockSvg, hold_ttl: 300 });
			}
			if (opts.path?.includes('/seats/availability/')) {
				return Promise.resolve({ held: [], booked: [], mine: ['vip-row1-s1'] });
			}
			return Promise.resolve({});
		});

		render(
			<SeatMapPicker
				{...defaultProps}
				selectedSeats={['vip-row1-s1']}
			/>
		);

		await waitFor(() => {
			expect(screen.getByTestId('hold-timer')).toBeInTheDocument();
		});
	});

	it('fetches layout and availability on mount', async () => {
		setupSuccessfulFetch();

		render(<SeatMapPicker {...defaultProps} />);

		await waitFor(() => {
			expect(apiFetch).toHaveBeenCalledWith(
				expect.objectContaining({
					path: expect.stringContaining('/seats/layout/1'),
				})
			);
			expect(apiFetch).toHaveBeenCalledWith(
				expect.objectContaining({
					path: expect.stringContaining('/seats/availability/1/42'),
				})
			);
		});
	});

	it('restores existing session holds on mount', async () => {
		apiFetch.mockImplementation((opts) => {
			if (opts.path?.includes('/seats/layout/')) {
				return Promise.resolve({ layout: mockLayout, svg: mockSvg, hold_ttl: 300 });
			}
			if (opts.path?.includes('/seats/availability/')) {
				return Promise.resolve({ held: [], booked: [], mine: ['vip-row1-s1', 'vip-row1-s2'] });
			}
			return Promise.resolve({});
		});

		render(<SeatMapPicker {...defaultProps} />);

		await waitFor(() => {
			expect(defaultProps.onSeatsChange).toHaveBeenCalledWith([
				'vip-row1-s1',
				'vip-row1-s2',
			]);
		});
	});
});
