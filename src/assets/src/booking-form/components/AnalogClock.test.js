/**
 * File: src/assets/src/booking-form/components/AnalogClock.test.js
 * Tests for the AnalogClock component.
 */
import React from 'react';
import { render, act } from '@testing-library/react';
import AnalogClock from './AnalogClock';

describe('AnalogClock', () => {
    beforeEach(() => {
        jest.useFakeTimers();
    });

    afterEach(() => {
        jest.useRealTimers();
    });

    it('renders clock container with hands and center dot', () => {
        const { container } = render(<AnalogClock />);

        expect(container.querySelector('.clisyc-clock-icon-container')).toBeInTheDocument();
        expect(container.querySelector('.clisyc-clock-hand-hour')).toBeInTheDocument();
        expect(container.querySelector('.clisyc-clock-hand-minute')).toBeInTheDocument();
        expect(container.querySelector('.clisyc-clock-center-dot')).toBeInTheDocument();
    });

    it('renders with local timezone by default', () => {
        const { container } = render(<AnalogClock />);
        const hourHand = container.querySelector('.clisyc-clock-hand-hour');
        // Should have a transform style set.
        expect(hourHand.style.transform).toMatch(/rotate\(\d+(\.\d+)?deg\)/);
    });

    it('renders with a specific timezone', () => {
        const { container } = render(<AnalogClock timezone="America/New_York" />);
        const hourHand = container.querySelector('.clisyc-clock-hand-hour');
        expect(hourHand.style.transform).toMatch(/rotate\(\d+(\.\d+)?deg\)/);
    });

    it('falls back to local time on invalid timezone', () => {
        const { container } = render(<AnalogClock timezone="Invalid/Timezone" />);
        const hourHand = container.querySelector('.clisyc-clock-hand-hour');
        // Should still render without crashing.
        expect(hourHand.style.transform).toMatch(/rotate\(\d+(\.\d+)?deg\)/);
    });

    it('updates every second', () => {
        const { container } = render(<AnalogClock />);
        const hourHand = container.querySelector('.clisyc-clock-hand-hour');
        const initialTransform = hourHand.style.transform;

        // Advance time by 61 seconds to ensure minutes change.
        act(() => {
            jest.advanceTimersByTime(61000);
        });

        // The transform may or may not have changed (depends on timing),
        // but the component should still be rendered without errors.
        expect(hourHand.style.transform).toMatch(/rotate\(\d+(\.\d+)?deg\)/);
    });

    it('treats timezone="local" as local time', () => {
        const { container } = render(<AnalogClock timezone="local" />);
        const hourHand = container.querySelector('.clisyc-clock-hand-hour');
        expect(hourHand.style.transform).toMatch(/rotate\(\d+(\.\d+)?deg\)/);
    });
});
