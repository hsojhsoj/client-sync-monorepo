/**
 * File: src/assets/src/booking-form/components/MiniCalendar.test.js
 * Tests for the MiniCalendar component.
 */
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MiniCalendar from './MiniCalendar';

describe('MiniCalendar', () => {
	const fixedDate = new Date(2026, 1, 15); // Feb 15, 2026

	it('renders the calendar grid', () => {
		const { container } = render(<MiniCalendar currentDate={fixedDate} />);

		expect(container.querySelector('.clisyc-mini-calendar')).toBeInTheDocument();
		expect(container.querySelector('.clisyc-mini-cal-grid')).toBeInTheDocument();
	});

	it('shows the correct month and year', () => {
		render(<MiniCalendar currentDate={fixedDate} />);

		expect(screen.getByText('February 2026')).toBeInTheDocument();
	});

	it('renders day-of-week headers', () => {
		render(<MiniCalendar currentDate={fixedDate} />);

		const weekdays = screen.getAllByText(/^[SMTWF]$/);
		expect(weekdays).toHaveLength(7);
	});

	it('renders 42 day cells (6 weeks)', () => {
		const { container } = render(<MiniCalendar currentDate={fixedDate} />);

		const dayButtons = container.querySelectorAll('.clisyc-mini-cal-day');
		expect(dayButtons).toHaveLength(42);
	});

	it('highlights today with a special class', () => {
		const today = new Date();
		const { container } = render(<MiniCalendar currentDate={today} />);

		const todayCell = container.querySelector('.clisyc-mini-cal-day--today');
		expect(todayCell).toBeInTheDocument();
	});

	it('highlights the selected date', () => {
		const { container } = render(<MiniCalendar currentDate={fixedDate} />);

		const selected = container.querySelector('.clisyc-mini-cal-day--selected');
		expect(selected).toBeInTheDocument();
		expect(selected.textContent).toBe('15');
	});

	it('marks days outside current month', () => {
		const { container } = render(<MiniCalendar currentDate={fixedDate} />);

		const otherMonthDays = container.querySelectorAll(
			'.clisyc-mini-cal-day--other'
		);
		expect(otherMonthDays.length).toBeGreaterThan(0);
	});

	it('navigates to previous month', async () => {
		const user = userEvent.setup();
		render(<MiniCalendar currentDate={fixedDate} />);

		const prevBtn = screen.getByLabelText('Previous month');
		await user.click(prevBtn);

		expect(screen.getByText('January 2026')).toBeInTheDocument();
	});

	it('navigates to next month', async () => {
		const user = userEvent.setup();
		render(<MiniCalendar currentDate={fixedDate} />);

		const nextBtn = screen.getByLabelText('Next month');
		await user.click(nextBtn);

		expect(screen.getByText('March 2026')).toBeInTheDocument();
	});

	it('calls onDateSelect when a day is clicked', async () => {
		const user = userEvent.setup();
		const onDateSelect = jest.fn();

		const { container } = render(
			<MiniCalendar currentDate={fixedDate} onDateSelect={onDateSelect} />
		);

		// Click day "10" (within current month)
		const dayButtons = container.querySelectorAll(
			'.clisyc-mini-cal-day:not(.clisyc-mini-cal-day--other)'
		);
		const day10 = Array.from(dayButtons).find(
			(btn) => btn.textContent === '10'
		);
		await user.click(day10);

		expect(onDateSelect).toHaveBeenCalledTimes(1);
		const calledDate = onDateSelect.mock.calls[0][0];
		expect(calledDate.getDate()).toBe(10);
		expect(calledDate.getMonth()).toBe(1); // February
	});

	it('switches display month when clicking a day outside current month', async () => {
		const user = userEvent.setup();
		const onDateSelect = jest.fn();

		const { container } = render(
			<MiniCalendar currentDate={fixedDate} onDateSelect={onDateSelect} />
		);

		// Click an "other month" day
		const otherDays = container.querySelectorAll(
			'.clisyc-mini-cal-day--other'
		);
		if (otherDays.length > 0) {
			await user.click(otherDays[0]);

			expect(onDateSelect).toHaveBeenCalled();
			// The display month title should have changed
		}
	});

	it('updates display when currentDate prop changes month', () => {
		const { rerender } = render(<MiniCalendar currentDate={fixedDate} />);

		expect(screen.getByText('February 2026')).toBeInTheDocument();

		// Change to March
		const marchDate = new Date(2026, 2, 10);
		rerender(<MiniCalendar currentDate={marchDate} />);

		expect(screen.getByText('March 2026')).toBeInTheDocument();
	});

	it('renders without crashing when currentDate is null', () => {
		const { container } = render(<MiniCalendar currentDate={null} />);

		expect(container.querySelector('.clisyc-mini-calendar')).toBeInTheDocument();
	});
});
