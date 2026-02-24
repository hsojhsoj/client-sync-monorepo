/**
 * File: src/assets/src/booking-form/components/EventTooltip.test.js
 * Tests for the EventTooltip component.
 */
import React from 'react';
import { render, screen } from '@testing-library/react';
import EventTooltip from './EventTooltip';

describe('EventTooltip', () => {
    it('renders nothing when not visible', () => {
        const { container } = render(
            <EventTooltip visible={false} x={100} y={200} content={{ title: 'Test' }} />
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when content is null', () => {
        const { container } = render(
            <EventTooltip visible={true} x={100} y={200} content={null} />
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders title when provided', () => {
        render(
            <EventTooltip
                visible={true}
                x={100}
                y={200}
                content={{ title: 'Deep Tissue Massage' }}
            />
        );
        expect(screen.getByText('Deep Tissue Massage')).toBeInTheDocument();
    });

    it('renders practitioner name when provided', () => {
        render(
            <EventTooltip
                visible={true}
                x={100}
                y={200}
                content={{
                    title: 'Service',
                    practitioner: 'Dr. Smith',
                    practitionerColor: '#3b82f6',
                }}
            />
        );
        expect(screen.getByText('Dr. Smith')).toBeInTheDocument();
    });

    it('renders room name when provided', () => {
        render(
            <EventTooltip
                visible={true}
                x={100}
                y={200}
                content={{
                    title: 'Service',
                    room: 'Room 101',
                    roomColor: '#10b981',
                }}
            />
        );
        expect(screen.getByText('Room 101')).toBeInTheDocument();
    });

    it('renders time when provided', () => {
        render(
            <EventTooltip
                visible={true}
                x={100}
                y={200}
                content={{
                    title: 'Service',
                    time: '10:00 AM - 11:00 AM',
                }}
            />
        );
        expect(screen.getByText('10:00 AM - 11:00 AM')).toBeInTheDocument();
    });

    it('positions the tooltip at the given coordinates', () => {
        const { container } = render(
            <EventTooltip
                visible={true}
                x={150}
                y={300}
                content={{ title: 'Test' }}
            />
        );
        const tooltip = container.firstChild;
        expect(tooltip.style.left).toBe('150px');
    });

    it('renders all sections when full content is provided', () => {
        render(
            <EventTooltip
                visible={true}
                x={100}
                y={200}
                content={{
                    title: 'Consultation',
                    service: 'Consultation',
                    serviceColor: '#ef4444',
                    practitioner: 'Dr. Jones',
                    practitionerColor: '#3b82f6',
                    room: 'Suite A',
                    roomColor: '#10b981',
                    time: '2:00 PM - 3:00 PM',
                }}
            />
        );
        expect(screen.getByText('Consultation')).toBeInTheDocument();
        expect(screen.getByText('Dr. Jones')).toBeInTheDocument();
        expect(screen.getByText('Suite A')).toBeInTheDocument();
        expect(screen.getByText('2:00 PM - 3:00 PM')).toBeInTheDocument();
    });
});
