/**
 * File: src/assets/src/booking-form/BookingModal.test.js
 * Tests for the BookingModal component.
 */
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import BookingModal from './BookingModal';

// Mock window.clisycBookingFormData
const mockFormData = {
	availabilityDimensions: {
		clisyc_service: { label: 'Service' },
		clisyc_practitioner: { label: 'Practitioner' },
	},
	filterOrder: [ 'clisyc_service', 'clisyc_practitioner' ],
	wc_integration_enabled: false,
	stripe_integration_enabled: false,
	waitlist_enabled: false,
	proRecurringEnabled: false,
	restRootUrl: '/wp-json/',
	restNonce: 'test-nonce',
	ajaxUrl: '/wp-admin/admin-ajax.php',
};

beforeAll( () => {
	window.clisycBookingFormData = mockFormData;
} );

afterAll( () => {
	delete window.clisycBookingFormData;
} );

// Create a mock selected slot
const mockSlot = {
	identifier: 'slot-123',
	durationMinutes: 60,
	displayTime: 'February 15, 2026 at 10:00 AM',
	event: {
		start: new Date( 2026, 1, 15, 10, 0 ),
		end: new Date( 2026, 1, 15, 11, 0 ),
		extendedProps: {
			dimensions: { clisyc_service: '1', clisyc_practitioner: '10' },
			serviceDetails: {
				service_name: 'Massage',
				service_color: '#4285f4',
				practitioner_name: 'Alice',
				practitioner_color: '#ea4335',
			},
		},
	},
};

const defaultProps = {
	isOpen: true,
	onClose: jest.fn(),
	selectedSlot: mockSlot,
	selectedDateRange: null,
	selectedFilters: { clisyc_service: [ '1' ], clisyc_practitioner: [ '10' ] },
	selectedPrimaryItem: null,
	formNonce: 'test-form-nonce',
	isGuest: false,
	customFieldDefinitions: [],
	successPageUrl: '/booking-confirmed/',
	bookingMode: 'slot',
	currencySymbol: '$',
	basket: { total: 0, formatted: '', items: [] },
	isCalculatingPrice: false,
};

describe( 'BookingModal', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders nothing when isOpen is false', () => {
		const { container } = render(
			<BookingModal { ...defaultProps } isOpen={ false } />
		);

		expect( container.innerHTML ).toBe( '' );
	} );

	it( 'renders the modal when isOpen is true', () => {
		render( <BookingModal { ...defaultProps } /> );

		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Confirm Your Booking' )
		).toBeInTheDocument();
	} );

	it( 'has correct ARIA attributes', () => {
		render( <BookingModal { ...defaultProps } /> );

		const dialog = screen.getByRole( 'dialog' );
		expect( dialog ).toHaveAttribute( 'aria-modal', 'true' );
		expect( dialog ).toHaveAttribute(
			'aria-labelledby',
			'clisyc-booking-modal-title'
		);
	} );

	it( 'shows appointment date and time', () => {
		render( <BookingModal { ...defaultProps } /> );

		// Should show the date
		expect( screen.getByText( /February/ ) ).toBeInTheDocument();

		// Should show duration
		expect( screen.getByText( /60 min/ ) ).toBeInTheDocument();
	} );

	it( 'shows dimension details from slot', () => {
		render( <BookingModal { ...defaultProps } /> );

		expect( screen.getByText( 'Massage' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Alice' ) ).toBeInTheDocument();
	} );

	it( 'shows Confirm Booking button (no payment)', () => {
		render( <BookingModal { ...defaultProps } /> );

		expect( screen.getByText( 'Confirm Booking' ) ).toBeInTheDocument();
	} );

	it( 'shows Proceed to Checkout button (WooCommerce enabled)', () => {
		window.clisycBookingFormData = {
			...mockFormData,
			wc_integration_enabled: true,
		};

		render( <BookingModal { ...defaultProps } /> );
		expect( screen.getByText( 'Proceed to Checkout' ) ).toBeInTheDocument();

		window.clisycBookingFormData = mockFormData;
	} );

	it( 'shows Cancel button', () => {
		render( <BookingModal { ...defaultProps } /> );

		expect( screen.getByText( 'Cancel' ) ).toBeInTheDocument();
	} );

	it( 'shows Close (X) button with aria-label', () => {
		render( <BookingModal { ...defaultProps } /> );

		expect( screen.getByLabelText( 'Close modal' ) ).toBeInTheDocument();
	} );

	it( 'calls onClose when Cancel is clicked', async () => {
		const user = userEvent.setup();
		render( <BookingModal { ...defaultProps } /> );

		await user.click( screen.getByText( 'Cancel' ) );
		expect( defaultProps.onClose ).toHaveBeenCalled();
	} );

	it( 'calls onClose when X button is clicked', async () => {
		const user = userEvent.setup();
		render( <BookingModal { ...defaultProps } /> );

		await user.click( screen.getByLabelText( 'Close modal' ) );
		expect( defaultProps.onClose ).toHaveBeenCalled();
	} );

	it( 'calls onClose on Escape key', () => {
		render( <BookingModal { ...defaultProps } /> );

		fireEvent.keyDown( document, { key: 'Escape' } );
		expect( defaultProps.onClose ).toHaveBeenCalled();
	} );

	it( 'calls onClose on backdrop click', async () => {
		const user = userEvent.setup();
		const { container } = render( <BookingModal { ...defaultProps } /> );

		const overlay = container.querySelector(
			'.clisyc-booking-modal-overlay'
		);
		// Click the overlay itself (not a child)
		await user.click( overlay );
		expect( defaultProps.onClose ).toHaveBeenCalled();
	} );

	it( 'shows notes field', () => {
		render( <BookingModal { ...defaultProps } /> );

		const notes = screen.getByLabelText( 'Notes (optional)' );
		expect( notes ).toBeInTheDocument();
		expect( notes.tagName.toLowerCase() ).toBe( 'textarea' );
	} );

	it( 'shows guest fields when isGuest is true', () => {
		render( <BookingModal { ...defaultProps } isGuest={ true } /> );

		expect( screen.getByLabelText( 'Your Name' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Email Address' ) ).toBeInTheDocument();
	} );

	it( 'disables confirm button when guest fields are empty', () => {
		render( <BookingModal { ...defaultProps } isGuest={ true } /> );

		const confirmBtn = screen.getByText( 'Confirm Booking' );
		expect( confirmBtn ).toBeDisabled();
	} );

	it( 'enables confirm button when guest fields are filled', async () => {
		const user = userEvent.setup();
		render( <BookingModal { ...defaultProps } isGuest={ true } /> );

		await user.type( screen.getByLabelText( 'Your Name' ), 'John Doe' );
		await user.type(
			screen.getByLabelText( 'Email Address' ),
			'john@example.com'
		);

		const confirmBtn = screen.getByText( 'Confirm Booking' );
		expect( confirmBtn ).not.toBeDisabled();
	} );

	it( 'renders custom fields when provided', () => {
		const customFields = [
			{
				key: 'reason',
				label: 'Reason for Visit',
				type: 'text',
				required: true,
				placeholder: 'Enter reason',
			},
			{
				key: 'notes_field',
				label: 'Additional Info',
				type: 'textarea',
				required: false,
				placeholder: '',
			},
		];

		render(
			<BookingModal
				{ ...defaultProps }
				customFieldDefinitions={ customFields }
			/>
		);

		expect(
			screen.getByLabelText( /Reason for Visit/ )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'Additional Info' )
		).toBeInTheDocument();
	} );

	it( 'shows invoice breakdown when basket has items', () => {
		const basket = {
			total: 100,
			formatted: '$100.00',
			currency: '$',
			items: [
				{ label: 'Massage (60 min)', price: 80, type: 'base' },
				{
					label: 'Premium Room',
					price: 20,
					type: 'fee',
					meta: 'Add-on',
				},
			],
		};

		render( <BookingModal { ...defaultProps } basket={ basket } /> );

		expect( screen.getByText( 'Charges' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Massage (60 min)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Premium Room' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Total Due' ) ).toBeInTheDocument();
		expect( screen.getByText( '$100.00' ) ).toBeInTheDocument();
	} );

	it( 'shows price calculating state', () => {
		render(
			<BookingModal
				{ ...defaultProps }
				basket={ {
					total: 0,
					formatted: '',
					items: [ { label: 'x', price: 0 } ],
				} }
				isCalculatingPrice={ true }
			/>
		);

		expect( screen.getByText( /Updating price/ ) ).toBeInTheDocument();
	} );

	// --- Date Range Mode ---
	it( 'shows reservation title in date_range mode', () => {
		render(
			<BookingModal
				{ ...defaultProps }
				bookingMode="date_range"
				selectedSlot={ null }
				selectedDateRange={ {
					startDate: '2026-02-15',
					endDate: '2026-02-18',
					nights: 3,
					total: 300,
				} }
				selectedPrimaryItem={ { label: 'Beach Cabin', value: '5' } }
			/>
		);

		expect(
			screen.getByText( 'Confirm Your Reservation' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Beach Cabin' ) ).toBeInTheDocument();
		expect( screen.getByText( /3 night/ ) ).toBeInTheDocument();
	} );
} );
