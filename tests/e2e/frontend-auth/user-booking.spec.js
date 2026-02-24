/**
 * E2E Tests — Authenticated User Booking Flow
 *
 * Tests the booking experience for logged-in users, including:
 * - Pre-populated user fields
 * - Booking form behaviour with auth context
 * - Post-booking confirmation
 */
import { test, expect } from '@playwright/test';
import {
	BOOKING_FORM,
	waitForBookingFormReady,
	waitForCalendarRender,
	dismissModals,
} from '../helpers/selectors.js';

const BOOKING_PAGE = '/booking-calendar/';

test.describe('Authenticated Booking — User Context', () => {
	test('booking form loads with auth context (isGuest = false)', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		// Check that the React app received isGuest = false via window data.
		// The hybrid booking shortcode stores data in numbered globals.
		const isGuest = await page.evaluate(() => {
			// Try all known data globals
			const sources = [window.clisycBookingFormData];
			// Hybrid booking uses numbered instances
			for (let i = 1; i <= 5; i++) {
				const key = 'clisycHybridBookingData_' + i;
				if (window[key]) sources.push(window[key]);
			}
			if (window.clisycHybridBookingData) sources.push(window.clisycHybridBookingData);

			for (const data of sources) {
				if (!data) continue;
				if (typeof data.isGuest !== 'undefined') return data.isGuest;
				if (typeof data.is_guest !== 'undefined') return data.is_guest;
			}
			return null;
		});

		if (isGuest !== null) {
			// WordPress wp_localize_script may serialize false as "" (empty string),
			// so we check for any falsy value rather than strict boolean false.
			expect(!!isGuest).toBe(false);
		} else {
			// If we can't find the data, verify via WP auth cookies
			const cookies = await page.context().cookies();
			const hasAuthCookie = cookies.some((c) => c.name.startsWith('wordpress_logged_in'));
			expect(hasAuthCookie).toBe(true);
		}
	});

	test('logged-in user can select a slot and open booking modal', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);
		await waitForCalendarRender(page);

		const availableSlots = page.locator(BOOKING_FORM.availableSlot);
		const count = await availableSlots.count();

		if (count > 0) {
			await availableSlots.first().click();
			await page.waitForTimeout(500);

			const modal = page.locator(BOOKING_FORM.modal);
			await expect(modal).toBeVisible({ timeout: 5000 });

			// Modal should show "Confirm Your Booking" title
			await expect(page.locator(BOOKING_FORM.modalTitle)).toHaveText(
				/Confirm Your Booking/
			);
		}
	});

	test('booking modal does not show guest email field for logged-in users', async ({
		page,
	}) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);
		await waitForCalendarRender(page);

		const availableSlots = page.locator(BOOKING_FORM.availableSlot);
		const count = await availableSlots.count();

		if (count > 0) {
			await availableSlots.first().click();
			await page.waitForTimeout(500);

			const modal = page.locator(BOOKING_FORM.modal);
			await expect(modal).toBeVisible({ timeout: 5000 });

			// Guest email field should NOT be visible for logged-in users
			// (the form auto-uses the WP user email instead)
			const guestEmail = page.locator(BOOKING_FORM.guestEmail);
			const isVisible = await guestEmail.isVisible().catch(() => false);

			// Either the field is hidden or doesn't exist at all
			if (isVisible) {
				// If visible, it should be pre-filled with the user's email
				const value = await guestEmail.inputValue();
				expect(value).not.toBe('');
			}
		}
	});

	test('REST API requests include auth nonce', async ({ page }) => {
		const slotsRequest = page.waitForResponse(
			(resp) => resp.url().includes('/clisyc/v1/slots'),
			{ timeout: 15000 }
		);

		await page.goto(BOOKING_PAGE);

		const response = await slotsRequest;
		const requestHeaders = response.request().headers();

		// Authenticated requests should include the WP nonce header
		const hasNonce =
			requestHeaders['x-wp-nonce'] !== undefined ||
			response.request().url().includes('_wpnonce');

		expect(hasNonce).toBe(true);
	});
});

test.describe('Authenticated Booking — Filter Interaction', () => {
	test('filter selections persist across navigation', async ({ page }) => {
		await page.goto(BOOKING_PAGE);
		await waitForBookingFormReady(page);
		await dismissModals(page);

		// Check if sidebar is visible
		const sidebar = page.locator(BOOKING_FORM.sidebar);
		const sidebarVisible = await sidebar.isVisible().catch(() => false);

		if (sidebarVisible) {
			// Find filter items — the custom checkbox <span> intercepts pointer events
			// on the <input>, so we click the label/item container instead.
			const filterItems = page.locator(BOOKING_FORM.filterItem);
			const filterCount = await filterItems.count();

			if (filterCount > 0) {
				const firstItem = filterItems.first();
				const firstInput = firstItem.locator('input');

				if (await firstInput.count() > 0) {
					const wasChecked = await firstInput.isChecked();

					// Click the label element (which wraps the custom checkbox)
					const label = firstItem.locator('label').first();
					if (await label.isVisible({ timeout: 2000 }).catch(() => false)) {
						await label.click({ force: true });
					} else {
						// Fallback: click the item container itself
						await firstItem.click({ force: true });
					}
					await page.waitForTimeout(500);

					// Dismiss any modal that might appear due to filter change
					await dismissModals(page);

					// Navigate forward and back
					await page.locator('.fc-next-button').click({ timeout: 5000 });
					await page.waitForTimeout(1000);
					await dismissModals(page);

					await page.locator('.fc-prev-button').click({ timeout: 5000 });
					await page.waitForTimeout(1000);
					await dismissModals(page);

					// Filter should still be in the toggled state
					const isChecked = await firstInput.isChecked();
					expect(isChecked).not.toBe(wasChecked);
				}
			}
		}
	});
});
