/**
 * File: src/assets/src/booking-form/FilterPanel.test.js
 * Tests for the FilterPanel component.
 */
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import FilterPanel from './FilterPanel';

// Mock window.clisycBookingFormData
const mockFormData = {
	availabilityDimensions: {
		clisyc_service: { label: 'Service', options_meta: {} },
		clisyc_practitioner: { label: 'Practitioner', options_meta: {} },
	},
	filterOrder: [ 'clisyc_service', 'clisyc_practitioner' ],
	l10n: {
		showFilters: 'Show filters',
		hideFilters: 'Hide filters',
		resetFilters: 'Reset',
		resetAllFilters: 'Reset All Filters',
		showBookedSlots: 'Show booked slots',
		viewOptions: 'View Options',
		dateRangeInfo: 'Select an option to view availability',
	},
	currencySymbol: '$',
};

beforeAll( () => {
	window.clisycBookingFormData = mockFormData;
} );

afterAll( () => {
	delete window.clisycBookingFormData;
} );

const defaultOptions = {
	clisyc_service: [
		{ value: '1', label: 'Massage', color: '#4285f4' },
		{ value: '2', label: 'Facial', color: '#34a853' },
	],
	clisyc_practitioner: [
		{ value: '10', label: 'Alice', color: '#ea4335' },
		{ value: '11', label: 'Bob', color: '#fbbc04' },
	],
};

const defaultProps = {
	isLoading: false,
	options: defaultOptions,
	selections: {
		clisyc_service: [ '1', '2' ],
		clisyc_practitioner: [ '10', '11' ],
	},
	onSelect: jest.fn(),
	onReset: jest.fn(),
	bookingMode: 'slot',
	showBooked: false,
	onToggleShowBooked: jest.fn(),
	isSidebarCollapsed: false,
	onToggleSidebar: jest.fn(),
	miniCalendar: null,
};

describe( 'FilterPanel', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders filter groups from filterOrder', () => {
		render( <FilterPanel { ...defaultProps } /> );

		// Should show both dimension labels as group headers
		expect( screen.getByText( 'Service' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Practitioner' ) ).toBeInTheDocument();
	} );

	it( 'renders filter options within groups', () => {
		render( <FilterPanel { ...defaultProps } /> );

		expect( screen.getByText( 'Massage' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Facial' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Alice' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Bob' ) ).toBeInTheDocument();
	} );

	it( 'renders checkboxes in slot mode', () => {
		render( <FilterPanel { ...defaultProps } /> );

		const checkboxes = screen.getAllByRole( 'checkbox' );
		// 4 filter checkboxes + 1 "show booked slots" checkbox
		expect( checkboxes.length ).toBe( 5 );
	} );

	it( 'renders radio buttons in date_range mode', () => {
		render(
			<FilterPanel
				{ ...defaultProps }
				bookingMode="date_range"
				onToggleShowBooked={ null }
			/>
		);

		const radios = screen.getAllByRole( 'radio' );
		// Only primary dimension (Service) shown in date_range mode
		expect( radios.length ).toBe( 2 );
	} );

	it( 'calls onSelect when a checkbox is toggled', async () => {
		const user = userEvent.setup();
		render( <FilterPanel { ...defaultProps } /> );

		// Find the Massage checkbox (it's currently selected)
		const massageLabel = screen.getByText( 'Massage' ).closest( 'label' );
		const checkbox = massageLabel.querySelector( 'input' );
		await user.click( checkbox );

		expect( defaultProps.onSelect ).toHaveBeenCalledWith(
			'clisyc_service',
			[ '2' ] // Massage (1) removed, Facial (2) remains
		);
	} );

	it( 'collapses and expands filter groups', async () => {
		const user = userEvent.setup();
		render( <FilterPanel { ...defaultProps } /> );

		// Find the "Service" group header
		const serviceHeader = screen.getByText( 'Service' ).closest( 'button' );
		expect( serviceHeader ).toHaveAttribute( 'aria-expanded', 'true' );

		// Click to collapse
		await user.click( serviceHeader );
		expect( serviceHeader ).toHaveAttribute( 'aria-expanded', 'false' );

		// Click to expand
		await user.click( serviceHeader );
		expect( serviceHeader ).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	it( 'shows "Show booked slots" toggle in slot mode', () => {
		render( <FilterPanel { ...defaultProps } /> );

		expect( screen.getByText( 'Show booked slots' ) ).toBeInTheDocument();
	} );

	it( 'hides "Show booked slots" in date_range mode', () => {
		render(
			<FilterPanel
				{ ...defaultProps }
				bookingMode="date_range"
				onToggleShowBooked={ null }
			/>
		);

		expect(
			screen.queryByText( 'Show booked slots' )
		).not.toBeInTheDocument();
	} );

	it( 'calls onToggleShowBooked when booked toggle is clicked', async () => {
		const user = userEvent.setup();
		render( <FilterPanel { ...defaultProps } /> );

		const bookedLabel = screen
			.getByText( 'Show booked slots' )
			.closest( 'label' );
		const checkbox = bookedLabel.querySelector( 'input' );
		await user.click( checkbox );

		expect( defaultProps.onToggleShowBooked ).toHaveBeenCalledWith( true );
	} );

	it( 'calls onToggleSidebar when toggle button is clicked', async () => {
		const user = userEvent.setup();
		render( <FilterPanel { ...defaultProps } /> );

		const toggleBtn = screen.getByLabelText( 'Hide filters' );
		await user.click( toggleBtn );

		expect( defaultProps.onToggleSidebar ).toHaveBeenCalled();
	} );

	it( 'renders collapsed state with hamburger icon only', () => {
		render(
			<FilterPanel { ...defaultProps } isSidebarCollapsed={ true } />
		);

		// Should show the collapsed strip with a toggle button
		expect( screen.getByLabelText( 'Show filters' ) ).toBeInTheDocument();

		// Filter options should not be visible
		expect( screen.queryByText( 'Massage' ) ).not.toBeInTheDocument();
	} );

	it( 'shows loading dots when options are not yet available', () => {
		render(
			<FilterPanel
				{ ...defaultProps }
				options={ {} }
				isLoading={ true }
			/>
		);

		// Should show loading indicators (dots)
		const loadingDots = document.querySelectorAll( '.clisyc-loading-dot' );
		expect( loadingDots.length ).toBeGreaterThan( 0 );
	} );

	it( 'does not show reset button when all filters are selected', () => {
		render( <FilterPanel { ...defaultProps } /> );

		// All filters are selected, so Reset should not appear
		expect( screen.queryByText( 'Reset' ) ).not.toBeInTheDocument();
	} );

	it( 'shows reset button when filters are partially selected', () => {
		render(
			<FilterPanel
				{ ...defaultProps }
				selections={ {
					clisyc_service: [ '1' ],
					clisyc_practitioner: [ '10', '11' ],
				} }
			/>
		);

		expect( screen.getByText( 'Reset' ) ).toBeInTheDocument();
	} );

	it( 'renders mini calendar when provided', () => {
		render(
			<FilterPanel
				{ ...defaultProps }
				miniCalendar={ <div data-testid="mini-cal">Mini Cal</div> }
			/>
		);

		expect( screen.getByTestId( 'mini-cal' ) ).toBeInTheDocument();
	} );

	it( 'shows price hints when dimension options have price metadata', () => {
		const priceFormData = {
			...mockFormData,
			availabilityDimensions: {
				clisyc_service: {
					label: 'Service',
					options_meta: {
						1: { price: 50, calc_type: 'per_hour' },
					},
				},
			},
		};
		window.clisycBookingFormData = priceFormData;

		render( <FilterPanel { ...defaultProps } /> );

		expect( screen.getByText( /\+\$50\/hr/ ) ).toBeInTheDocument();

		// Restore
		window.clisycBookingFormData = mockFormData;
	} );
} );
