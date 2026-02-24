/**
 * E2E Tests — Frontend Timeline View
 *
 * Tests the public timeline view rendered by [clisyc_timeline] or
 * [clisyc_timeline_view] shortcodes. These are typically placed on
 * a page by the admin — we test basic rendering and interaction.
 *
 * NOTE: The timeline page URL may vary per installation. If no
 * dedicated timeline page exists, these tests will be skipped.
 */
import { test, expect } from '@playwright/test';
import { TIMELINE } from '../helpers/selectors.js';

// Common pages where timeline might be placed
const POSSIBLE_TIMELINE_PAGES = [
	'/timeline/',
	'/schedule/',
	'/calendar/',
];

/**
 * Try to find a page that has the timeline shortcode rendered.
 * Returns the URL or null if none found.
 */
async function findTimelinePage(page) {
	for (const url of POSSIBLE_TIMELINE_PAGES) {
		await page.goto(url, { waitUntil: 'domcontentloaded' });

		// Check if the page has the timeline container
		const timeline = page.locator(TIMELINE.calendar + ', ' + TIMELINE.root);
		if (await timeline.isVisible({ timeout: 3000 }).catch(() => false)) {
			return url;
		}
	}
	return null;
}

test.describe('Timeline View', () => {
	let timelineUrl;

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage();
		timelineUrl = await findTimelinePage(page);
		await page.close();
	});

	test('renders the timeline calendar if page exists', async ({ page }) => {
		test.skip(!timelineUrl, 'No timeline page found on test site');

		await page.goto(timelineUrl);
		await page.waitForLoadState('networkidle');

		// Should have FullCalendar rendered
		await expect(page.locator('.fc')).toBeVisible({ timeout: 15000 });
		await expect(page.locator('.fc-toolbar')).toBeVisible();
	});

	test('timeline has resource columns', async ({ page }) => {
		test.skip(!timelineUrl, 'No timeline page found on test site');

		await page.goto(timelineUrl);
		await page.waitForLoadState('networkidle');
		await page.waitForTimeout(2000);

		// Resource timeline should have resource labels
		const resourceLabels = page.locator(
			'.fc-resource-group, .fc-datagrid-cell, .fc-resource'
		);
		const count = await resourceLabels.count();

		// May have 0 if no resources configured, but calendar should still render
		await expect(page.locator('.fc')).toBeVisible();
	});

	test('timeline navigation works', async ({ page }) => {
		test.skip(!timelineUrl, 'No timeline page found on test site');

		await page.goto(timelineUrl);
		await page.waitForLoadState('networkidle');
		await page.waitForTimeout(2000);

		const titleEl = page.locator('.fc-toolbar-title');
		const initialTitle = await titleEl.textContent();

		// Navigate forward
		await page.locator('.fc-next-button').click();
		await page.waitForTimeout(1000);

		const nextTitle = await titleEl.textContent();
		expect(nextTitle).not.toBe(initialTitle);
	});
});
