/**
 * File: src/assets/src/booking-form/components/ErrorBoundary.test.js
 * Tests for the ErrorBoundary component.
 */
import React from 'react';
import { render, screen } from '@testing-library/react';
import ErrorBoundary from './ErrorBoundary';

// @wordpress/jest-console wraps console.error and adds a toHaveErrored()
// matcher. Overriding console.error directly breaks that wrapper.
// Instead, use jest.spyOn so the WP wrapper stays intact.
let consoleErrorSpy;
beforeEach(() => {
    consoleErrorSpy = jest.spyOn(console, 'error').mockImplementation((...args) => {
        // Swallow the noise React prints for error boundaries in tests
        const msg = typeof args[0] === 'string' ? args[0] : '';
        if (
            msg.includes('Error Boundary') ||
            msg.includes('The above error') ||
            msg.includes('[Client Sync]') ||
            msg.includes('Error: Uncaught')
        ) {
            return;
        }
    });
});
afterEach(() => {
    consoleErrorSpy.mockRestore();
});

// A component that throws during render.
const ThrowingChild = () => {
    throw new Error('Test render error');
};

// A normal child component.
const GoodChild = () => <div>Hello, World!</div>;

describe('ErrorBoundary', () => {
    it('renders children when there is no error', () => {
        render(
            <ErrorBoundary>
                <GoodChild />
            </ErrorBoundary>
        );

        expect(screen.getByText('Hello, World!')).toBeInTheDocument();
    });

    it('renders fallback UI when a child throws', () => {
        render(
            <ErrorBoundary>
                <ThrowingChild />
            </ErrorBoundary>
        );

        expect(screen.getByText(/something went wrong/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /refresh page/i })).toBeInTheDocument();
    });

    it('does not render children after an error', () => {
        render(
            <ErrorBoundary>
                <ThrowingChild />
            </ErrorBoundary>
        );

        expect(screen.queryByText('Hello, World!')).not.toBeInTheDocument();
    });

    it('calls componentDidCatch with error info', () => {
        const spy = jest.spyOn(ErrorBoundary.prototype, 'componentDidCatch');

        render(
            <ErrorBoundary>
                <ThrowingChild />
            </ErrorBoundary>
        );

        expect(spy).toHaveBeenCalledTimes(1);
        expect(spy.mock.calls[0][0]).toBeInstanceOf(Error);
        expect(spy.mock.calls[0][0].message).toBe('Test render error');

        spy.mockRestore();
    });
});
