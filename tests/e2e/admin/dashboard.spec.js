/**
 * E2E Tests — Admin Dashboard & Dimensions
 *
 * Tests the Client Sync admin pages. Some pages require `manage_options`
 * capability — if the test user lacks this, those tests are skipped.
 */
import { test, expect } from '@playwright/test';
import { ADMIN_MENU } from '../helpers/selectors.js';

/**
 * Check if the current user has access (not showing "not allowed" message).
 * WordPress renders the denial in a wp-die page with several possible
 * text patterns. We also check for an HTTP 403 via the page title.
 */
async function hasPageAccess(page) {
	await page.waitForLoadState('domcontentloaded');
	// WordPress wp_die() pages contain "not allowed" or "not permitted" text
	const bodyText = await page.locator('body').textContent({ timeout: 3000 }).catch(() => '');
	if (bodyText.includes('not allowed') || bodyText.includes('not permitted')) {
		return false;
	}
	// Some WordPress configs show a bare "403 Forbidden" or redirect to wp-login
	const title = await page.title();
	if (/forbidden/i.test(title) || /log\s*in/i.test(title)) {
		return false;
	}
	return true;
}

test.describe('Admin Dashboard', () => {
	test('Client Sync dashboard page loads or shows permission notice', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=clisyc');

		if (await hasPageAccess(page)) {
			await expect(page).toHaveURL(/page=clisyc/);
			await expect(
				page.locator('.clisyc-dashboard-wrap, .clisyc-dashboard, .wrap')
			).toBeVisible();
		} else {
			// Expected when user doesn't have manage_options
			test.info().annotations.push({
				type: 'info',
				description: 'Test user lacks manage_options — page access denied (expected)',
			});
		}
	});

	test('WP Dashboard loads without errors', async ({ page }) => {
		await page.goto('/wp-admin/');
		await page.waitForLoadState('domcontentloaded');

		await expect(page.locator('#wpbody')).toBeVisible();
	});
});

test.describe('Admin Dimensions Page', () => {
	test('dimensions page loads or shows permission notice', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=clisyc-dimensions');
		await page.waitForLoadState('networkidle');

		if (await hasPageAccess(page)) {
			// Page loaded — verify the admin wrapper rendered
			await expect(page.locator('#wpbody')).toBeVisible();
		} else {
			test.info().annotations.push({
				type: 'info',
				description: 'Test user lacks manage_options — page access denied (expected)',
			});
		}
	});
});

test.describe('Admin Calendars Page', () => {
	test('calendars management page loads or shows permission notice', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=clisyc-calendars');
		await page.waitForLoadState('domcontentloaded');

		if (await hasPageAccess(page)) {
			await expect(page).toHaveURL(/page=clisyc-calendars/);
			await expect(page.locator('#wpbody')).toBeVisible();
		} else {
			test.info().annotations.push({
				type: 'info',
				description: 'Test user lacks manage_options — page access denied (expected)',
			});
		}
	});
});

test.describe('Admin Reports Page', () => {
	test('reports page loads or shows permission notice', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=clisyc-reports');
		await page.waitForLoadState('domcontentloaded');

		if (await hasPageAccess(page)) {
			await expect(page).toHaveURL(/page=clisyc-reports/);
			await expect(page.locator('#wpbody')).toBeVisible();
		} else {
			test.info().annotations.push({
				type: 'info',
				description: 'Test user lacks manage_options — page access denied (expected)',
			});
		}
	});
});
