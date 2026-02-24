/**
 * File: src/assets/src/booking-form/RecurringOptions.test.js
 * Tests for the RecurringOptions component.
 */
import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import RecurringOptions from './RecurringOptions';

describe('RecurringOptions', () => {
	const defaultProps = {
		isRecurring: false,
		onToggle: jest.fn(),
		frequency: 'weekly',
		onFrequencyChange: jest.fn(),
		count: 4,
		onCountChange: jest.fn(),
	};

	beforeEach(() => {
		jest.clearAllMocks();
	});

	it('renders the recurring toggle checkbox', () => {
		render(<RecurringOptions {...defaultProps} />);

		expect(screen.getByText('Book a recurring series?')).toBeInTheDocument();
		expect(screen.getByRole('checkbox')).toBeInTheDocument();
	});

	it('toggle is unchecked by default', () => {
		render(<RecurringOptions {...defaultProps} />);

		expect(screen.getByRole('checkbox')).not.toBeChecked();
	});

	it('hides frequency/count options when unchecked', () => {
		render(<RecurringOptions {...defaultProps} />);

		expect(screen.queryByText('Repeat')).not.toBeInTheDocument();
		expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
	});

	it('shows frequency/count options when checked', () => {
		render(<RecurringOptions {...defaultProps} isRecurring={true} />);

		expect(screen.getByText('Repeat')).toBeInTheDocument();
		expect(screen.getByText('appointments')).toBeInTheDocument();
	});

	it('calls onToggle when checkbox is clicked', async () => {
		const user = userEvent.setup();
		render(<RecurringOptions {...defaultProps} />);

		await user.click(screen.getByRole('checkbox'));
		expect(defaultProps.onToggle).toHaveBeenCalledWith(true);
	});

	it('renders frequency dropdown with Weekly and Every 2 Weeks', () => {
		render(<RecurringOptions {...defaultProps} isRecurring={true} />);

		const select = screen.getByRole('combobox');
		expect(select).toBeInTheDocument();

		const options = screen.getAllByRole('option');
		expect(options).toHaveLength(2);
		expect(options[0]).toHaveTextContent('Weekly');
		expect(options[1]).toHaveTextContent('Every 2 Weeks');
	});

	it('renders count input with correct value', () => {
		render(<RecurringOptions {...defaultProps} isRecurring={true} />);

		const input = screen.getByRole('spinbutton');
		expect(input).toHaveValue(4);
	});

	it('calls onFrequencyChange when frequency is changed', async () => {
		const user = userEvent.setup();
		render(<RecurringOptions {...defaultProps} isRecurring={true} />);

		await user.selectOptions(screen.getByRole('combobox'), 'biweekly');
		expect(defaultProps.onFrequencyChange).toHaveBeenCalledWith('biweekly');
	});

	it('count input has min=2 and max=52', () => {
		render(<RecurringOptions {...defaultProps} isRecurring={true} />);

		const input = screen.getByRole('spinbutton');
		expect(input).toHaveAttribute('min', '2');
		expect(input).toHaveAttribute('max', '52');
	});

	it('clamps count to minimum of 2', async () => {
		const user = userEvent.setup();
		render(<RecurringOptions {...defaultProps} isRecurring={true} />);

		const input = screen.getByRole('spinbutton');
		await user.clear(input);
		await user.type(input, '1');

		// The onCountChange should have been called with clamped value
		const calls = defaultProps.onCountChange.mock.calls;
		const lastCall = calls[calls.length - 1];
		expect(lastCall[0]).toBeGreaterThanOrEqual(2);
	});
});
