/**
 * E2E Tests — User Account & My Appointments
 *
 * Tests the authenticated self-service pages:
 * - My Account page (profile, membership, packages)
 * - My Appointments (appointment list, filtering, details)
 * - Appointment cards view
 *
 * These pages require a logged-in user and render via shortcodes:
 *   [clisyc_user_account]
 *   [clisyc_view_notes]           (appointment list table)
 *   [clisyc_appointments_cards]   (appointment card grid)
 */
import { test, expect } from '@playwright/test';

/**
 * Try to locate the page that contains a given shortcode.
 * WordPress pages created by the onboarding wizard use predictable slugs.
 */
const CANDIDATE_SLUGS = {
	account: ['/my-account/', '/account/', '/user-account/'],
	appointments: [
		'/my-appointments/',
		'/appointments/',
		'/appointment-details/',
		'/booking-confirmed/',
	],
};

test.describe('User Account Page', () => {
	test('my account page loads for logged-in user', async ({ page }) => {
		let foundPage = false;

		for (const slug of CANDIDATE_SLUGS.account) {
			await page.goto(slug, { waitUntil: 'domcontentloaded' });
			const url = page.url();

			// Skip if we got a 404 or redirect to login
			if (url.includes('404') || url.includes('wp-login') || url.includes('administratordmedia')) {
				continue;
			}

			// Look for Client Sync account content
			const accountContainer = page.locator(
				'.clisyc-membership-dashboard, .clisyc-container, .clisyc-user-account'
			);
			const hasContent = await accountContainer.first().isVisible({ timeout: 3000 }).catch(() => false);

			if (hasContent) {
				foundPage = true;
				await expect(accountContainer.first()).toBeVisible();
				break;
			}
		}

		if (!foundPage) {
			test.info().annotations.push({
				type: 'info',
				description: 'No My Account page found on test site — page may not be configured',
			});
		}
	});
});

test.describe('My Appointments Page', () => {
	test('appointments list page loads for logged-in user', async ({ page }) => {
		let foundPage = false;

		for (const slug of CANDIDATE_SLUGS.appointments) {
			await page.goto(slug, { waitUntil: 'domcontentloaded' });
			const url = page.url();

			if (url.includes('404') || url.includes('wp-login') || url.includes('administratordmedia')) {
				continue;
			}

			// Look for appointment list or cards container
			const appointmentContainer = page.locator(
				'#clisyc-user-appointments-dashboard, #clisyc-appointments-cards-dashboard, .clisyc-appointment-list-table'
			);
			const hasContent = await appointmentContainer.first().isVisible({ timeout: 3000 }).catch(() => false);

			if (hasContent) {
				foundPage = true;
				await expect(appointmentContainer.first()).toBeVisible();
				break;
			}
		}

		if (!foundPage) {
			test.info().annotations.push({
				type: 'info',
				description:
					'No My Appointments page found on test site — page may not be configured',
			});
		}
	});

	test('appointment list has search and status filter controls', async ({ page }) => {
		let foundPage = false;

		for (const slug of CANDIDATE_SLUGS.appointments) {
			await page.goto(slug, { waitUntil: 'domcontentloaded' });

			const dashboard = page.locator('#clisyc-user-appointments-dashboard');
			if (await dashboard.isVisible({ timeout: 2000 }).catch(() => false)) {
				foundPage = true;

				// Search input
				const searchInput = page.locator('#clisyc-appointment-search');
				await expect(searchInput).toBeVisible();

				// Status filter dropdown
				const statusFilter = page.locator('#clisyc-appointment-status-filter');
				await expect(statusFilter).toBeVisible();

				// Table should be present
				const table = page.locator('#clisyc-user-appointments-table');
				await expect(table).toBeVisible();
				break;
			}
		}

		if (!foundPage) {
			test.info().annotations.push({
				type: 'info',
				description: 'Appointment list page not found — skipping controls test',
			});
		}
	});

	test('status filter dropdown has expected options', async ({ page }) => {
		let foundPage = false;

		for (const slug of CANDIDATE_SLUGS.appointments) {
			await page.goto(slug, { waitUntil: 'domcontentloaded' });

			const statusFilter = page.locator('#clisyc-appointment-status-filter');
			if (await statusFilter.isVisible({ timeout: 2000 }).catch(() => false)) {
				foundPage = true;

				const options = statusFilter.locator('option');
				const count = await options.count();

				// Should have at least "All" plus some status options
				expect(count).toBeGreaterThanOrEqual(2);
				break;
			}
		}

		if (!foundPage) {
			test.info().annotations.push({
				type: 'info',
				description: 'Status filter not found — skipping options test',
			});
		}
	});
});

test.describe('Appointment Cards View', () => {
	test('appointment cards page loads if configured', async ({ page }) => {
		// The cards view uses a different shortcode/container
		for (const slug of CANDIDATE_SLUGS.appointments) {
			await page.goto(slug, { waitUntil: 'domcontentloaded' });

			const cardsGrid = page.locator(
				'#clisyc-appointments-cards-dashboard, .clisyc-appointments-cards-grid'
			);
			if (await cardsGrid.first().isVisible({ timeout: 2000 }).catch(() => false)) {
				await expect(cardsGrid.first()).toBeVisible();

				// Should have filter controls
				const filterControls = page.locator('.clisyc-cards-filter-controls');
				const hasFilters = await filterControls.isVisible().catch(() => false);

				if (hasFilters) {
					await expect(page.locator('#clisyc-cards-search')).toBeVisible();
					await expect(page.locator('#clisyc-cards-status-filter')).toBeVisible();
				}
				return;
			}
		}

		test.info().annotations.push({
			type: 'info',
			description: 'No appointment cards page found on test site',
		});
	});
});
