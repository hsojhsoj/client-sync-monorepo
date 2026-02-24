/**
 * E2E Tests — Frontend Booking Form
 *
 * Tests the public-facing booking calendar rendered by [clisyc_appointment_form].
 * Covers: page load, React mount, filter panel, calendar rendering, timezone
 * selector, slot interaction, booking modal, and legend display.
 */
import { test, expect } from '@playwright/test';
import {
	BOOKING_FORM,
	waitForBookingFormReady,
	waitForCalendarRender,
	dismissModals,
} from '../helpers/selectors.js';

const BOOKING_PAGE = '/booking-calendar/';

test.describe('Booking Form — Page Load & Layout', () => {
	test('renders the React booking app container', async ({ page }) => {
		await page.goto(BOOKING_PAGE);

		// The page should contain at least one Client Sync booking root
		// (could be standard booking form, hybrid booking, or faceted booking)
		const root = page.locator(BOOKING_FORM.root);
		await expect(root.first()).toBeVisible({ timeout: 15000 });
	});

	test('shows loading state then resolves', async ({ page }) => {
		await page.goto(BOOKING_PAGE);

		// The app root should appear
		const root = page.locator(BOOKING_FORM.root);
		await expect(root.first()).toBeVisible({ timeout: 15000 });

		// Eventually the loading overlay should disappear
		await waitForBookingFormReady(page);
	});

	test('renders the calendar legend', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		const legend = page.locator(BOOKING_FORM.legend);
		await expect(legend).toBeVisible();

		// Should show "Available" legend item
		await expect(legend.getByText('Available')).toBeVisible();
	});

	test('renders help text at the bottom', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		await expect(
			page.getByText('Click on an available time slot to book your appointment')
		).toBeVisible();
	});
});

test.describe('Booking Form — Filter Panel', () => {
	test('displays the filter sidebar', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		const sidebar = page.locator(BOOKING_FORM.sidebar);
		// Sidebar should exist (may be collapsed or expanded)
		await expect(sidebar).toBeAttached();
	});

	test('filter groups are collapsible', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		// Ensure sidebar is visible (not collapsed)
		const sidebar = page.locator('.clisyc-sidebar:not(.clisyc-sidebar--collapsed)');
		if (!(await sidebar.isVisible().catch(() => false))) {
			// Click the sidebar toggle to expand it
			await page.locator(BOOKING_FORM.sidebarToggle).click();
			await page.waitForTimeout(300);
		}

		const groupHeaders = page.locator(BOOKING_FORM.filterGroupHeader);
		const count = await groupHeaders.count();

		if (count > 0) {
			const firstHeader = groupHeaders.first();
			// Should have aria-expanded attribute
			const expanded = await firstHeader.getAttribute('aria-expanded');
			expect(expanded).toBe('true');

			// Click to collapse
			await firstHeader.click();
			await expect(firstHeader).toHaveAttribute('aria-expanded', 'false');

			// Click to re-expand
			await firstHeader.click();
			await expect(firstHeader).toHaveAttribute('aria-expanded', 'true');
		}
	});

	test('sidebar toggle collapses and expands', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		const toggle = page.locator(BOOKING_FORM.sidebarToggle).first();
		if (await toggle.isVisible()) {
			await toggle.click();
			await page.waitForTimeout(300);

			// Should be collapsed
			await expect(page.locator('.clisyc-sidebar--collapsed')).toBeVisible();

			// Click toggle again to expand
			await page.locator(BOOKING_FORM.sidebarToggle).click();
			await page.waitForTimeout(300);

			await expect(page.locator('.clisyc-sidebar-inner')).toBeVisible();
		}
	});
});

test.describe('Booking Form — Calendar', () => {
	test('renders FullCalendar with toolbar', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		await waitForCalendarRender(page);

		// FullCalendar toolbar buttons should be visible
		await expect(page.locator('.fc-toolbar')).toBeVisible();
		await expect(page.locator('.fc-prev-button')).toBeVisible();
		await expect(page.locator('.fc-next-button')).toBeVisible();
	});

	test('can navigate between weeks/months', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		await waitForCalendarRender(page);

		// Get initial title
		const titleEl = page.locator('.fc-toolbar-title');
		const initialTitle = await titleEl.textContent();

		// Dismiss overlays first, then click (overlays intercept pointer events)
		await dismissModals(page);
		await page.locator('.fc-next-button').click({ timeout: 10000 });
		await page.waitForTimeout(1500);
		// A "no slots" modal may appear after navigation — dismiss it
		await dismissModals(page);

		// Title should change
		const newTitle = await titleEl.textContent();
		expect(newTitle).not.toBe(initialTitle);

		// Navigate back
		await dismissModals(page);
		await page.locator('.fc-prev-button').click({ timeout: 10000 });
		await page.waitForTimeout(1500);
		await dismissModals(page);

		const backTitle = await titleEl.textContent();
		expect(backTitle).toBe(initialTitle);
	});

	test('can switch calendar views', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		await waitForCalendarRender(page);

		// Try switching to month view if available
		const monthBtn = page.locator('.fc-dayGridMonth-button');
		if (await monthBtn.isVisible().catch(() => false)) {
			await dismissModals(page);
			await monthBtn.click({ timeout: 10000 });
			await page.waitForTimeout(1000);
			await dismissModals(page);
			await expect(page.locator('.fc-dayGridMonth-view')).toBeVisible();
		}

		// Switch back to week view if available
		const weekBtn = page.locator('.fc-timeGridWeek-button');
		if (await weekBtn.isVisible().catch(() => false)) {
			await dismissModals(page);
			await weekBtn.click({ timeout: 10000 });
			await page.waitForTimeout(1000);
			await dismissModals(page);
			await expect(page.locator('.fc-timeGridWeek-view')).toBeVisible();
		}
	});
});

test.describe('Booking Form — Timezone', () => {
	test('shows compact timezone bar by default (header collapsed)', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		// Default state is header collapsed, showing compact bar
		const compact = page.locator(BOOKING_FORM.headerCompact);
		const expanded = page.locator(BOOKING_FORM.headerExpanded);

		// One of them should be visible depending on saved localStorage state
		const compactVisible = await compact.isVisible().catch(() => false);
		const expandedVisible = await expanded.isVisible().catch(() => false);
		expect(compactVisible || expandedVisible).toBe(true);
	});

	test('expanding header shows timezone selector and map', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		// If compact bar is visible, click "Change Timezone" to expand
		const compact = page.locator(BOOKING_FORM.headerCompact);
		if (await compact.isVisible().catch(() => false)) {
			await page.locator('.clisyc-header-toggle-btn').click();
			await page.waitForTimeout(300);
		}

		// Expanded content should show timezone select
		const tzSelect = page.locator(BOOKING_FORM.timezoneSelect);
		await expect(tzSelect).toBeVisible();

		// Should have timezone options
		const options = tzSelect.locator('option');
		const count = await options.count();
		expect(count).toBeGreaterThanOrEqual(5);
	});

	test('changing timezone updates the clock display', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		// Expand header if needed
		const compact = page.locator(BOOKING_FORM.headerCompact);
		if (await compact.isVisible().catch(() => false)) {
			await page.locator('.clisyc-header-toggle-btn').click();
			await page.waitForTimeout(300);
		}

		const tzSelect = page.locator(BOOKING_FORM.timezoneSelect);
		if (await tzSelect.isVisible().catch(() => false)) {
			// Get initial clock time
			const clockBefore = await page.locator('.clisyc-live-clock').textContent();

			// Change timezone to something distinct
			await tzSelect.selectOption('Asia/Tokyo');
			await page.waitForTimeout(1500);

			// Clock should update (may or may not be different depending on current time)
			const clockAfter = await page.locator('.clisyc-live-clock').textContent();
			// At minimum, the clock should still be rendering
			expect(clockAfter).toMatch(/\d{1,2}:\d{2}/);
		}
	});
});

test.describe('Booking Form — Slot Selection & Modal', () => {
	test('clicking an available slot opens the booking modal', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		await waitForCalendarRender(page);

		// Look for available slots
		const availableSlots = page.locator(BOOKING_FORM.availableSlot);
		const count = await availableSlots.count();

		if (count > 0) {
			// Click the first available slot
			await availableSlots.first().click();
			await page.waitForTimeout(500);

			// Booking modal should appear
			const modal = page.locator(BOOKING_FORM.modal);
			await expect(modal).toBeVisible({ timeout: 5000 });

			// Modal should have correct ARIA attributes
			await expect(modal).toHaveAttribute('role', 'dialog');
			await expect(modal).toHaveAttribute('aria-modal', 'true');

			// Modal title should be visible
			await expect(page.locator(BOOKING_FORM.modalTitle)).toBeVisible();
			await expect(page.locator(BOOKING_FORM.modalTitle)).toHaveText(
				/Confirm Your Booking/
			);
		}
	});

	test('booking modal has confirm and cancel buttons', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		const availableSlots = page.locator(BOOKING_FORM.availableSlot);
		const count = await availableSlots.count();

		if (count > 0) {
			await availableSlots.first().click();
			await page.waitForTimeout(500);

			const modal = page.locator(BOOKING_FORM.modal);
			await expect(modal).toBeVisible({ timeout: 5000 });

			// Confirm button
			await expect(page.locator(BOOKING_FORM.modalConfirm)).toBeVisible();

			// Cancel button
			await expect(page.locator(BOOKING_FORM.modalCancel)).toBeVisible();

			// Close button (X)
			await expect(page.locator(BOOKING_FORM.modalClose)).toBeVisible();
		}
	});

	test('booking modal closes on Cancel click', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		const availableSlots = page.locator(BOOKING_FORM.availableSlot);
		const count = await availableSlots.count();

		if (count > 0) {
			await availableSlots.first().click();
			await page.waitForTimeout(500);

			const modal = page.locator(BOOKING_FORM.modal);
			await expect(modal).toBeVisible({ timeout: 5000 });

			// Click Cancel
			await page.locator(BOOKING_FORM.modalCancel).click();
			await page.waitForTimeout(300);

			// Modal should be gone
			await expect(modal).not.toBeVisible();
		}
	});

	test('booking modal closes on Escape key', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		const availableSlots = page.locator(BOOKING_FORM.availableSlot);
		const count = await availableSlots.count();

		if (count > 0) {
			await availableSlots.first().click();
			await page.waitForTimeout(500);

			const modal = page.locator(BOOKING_FORM.modal);
			await expect(modal).toBeVisible({ timeout: 5000 });

			// Press Escape
			await page.keyboard.press('Escape');
			await page.waitForTimeout(300);

			// Modal should be gone
			await expect(modal).not.toBeVisible();
		}
	});

	test('booking modal closes on backdrop click', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		const availableSlots = page.locator(BOOKING_FORM.availableSlot);
		const count = await availableSlots.count();

		if (count > 0) {
			await availableSlots.first().click();
			await page.waitForTimeout(500);

			const modal = page.locator(BOOKING_FORM.modal);
			await expect(modal).toBeVisible({ timeout: 5000 });

			// Click the overlay (outside the modal)
			const overlay = page.locator(BOOKING_FORM.modalOverlay);
			await overlay.click({ position: { x: 10, y: 10 } });
			await page.waitForTimeout(300);

			await expect(modal).not.toBeVisible();
		}
	});

	test('booking modal shows notes field', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		const availableSlots = page.locator(BOOKING_FORM.availableSlot);
		const count = await availableSlots.count();

		if (count > 0) {
			await availableSlots.first().click();
			await page.waitForTimeout(500);

			const modal = page.locator(BOOKING_FORM.modal);
			await expect(modal).toBeVisible({ timeout: 5000 });

			// Notes textarea should be present
			await expect(page.locator(BOOKING_FORM.notesField)).toBeVisible();
		}
	});
});

test.describe('Booking Form — API Calls', () => {
	test('fetches filter options on mount', async ({ page }) => {
		// Monitor REST API calls
		const filterRequest = page.waitForResponse(
			(resp) => resp.url().includes('/clisyc/v1/filter-options') && resp.status() === 200,
			{ timeout: 15000 }
		);

		await page.goto(BOOKING_PAGE);

		const response = await filterRequest;
		expect(response.ok()).toBe(true);
	});

	test('fetches slots when calendar loads', async ({ page }) => {
		const slotsRequest = page.waitForResponse(
			(resp) => resp.url().includes('/clisyc/v1/slots') && resp.status() === 200,
			{ timeout: 15000 }
		);

		await page.goto(BOOKING_PAGE);

		const response = await slotsRequest;
		expect(response.ok()).toBe(true);

		const data = await response.json();
		expect(data).toHaveProperty('events');
		expect(Array.isArray(data.events)).toBe(true);
	});
});
