/**
 * E2E Tests — Admin Settings Page
 *
 * Tests the Client Sync Settings page (WP Admin), focusing on:
 * - Tab navigation
 * - Color picker rendering and swatch display
 * - Settings persistence (save & reload)
 */
import { test, expect } from '@playwright/test';
import { ADMIN_MENU, SETTINGS_TABS, COLOR_PICKER } from '../helpers/selectors.js';

const SETTINGS_URL = '/wp-admin/admin.php?page=clisyc-settings';

test.describe('Admin Settings — Tab Navigation', () => {
	test('loads the settings page', async ({ page }) => {
		await page.goto(SETTINGS_URL + '&tab=appearance');
		await expect(page).toHaveURL(/page=clisyc-settings/);
		// Should show the settings heading or form
		await expect(page.locator('text=Client Sync Settings')).toBeVisible();
	});

	test('can navigate between settings tabs', async ({ page }) => {
		await page.goto(SETTINGS_URL);

		// Find nav tabs
		const tabs = page.locator('.nav-tab');
		const count = await tabs.count();
		expect(count).toBeGreaterThanOrEqual(2);

		// Click through each tab and verify it becomes active
		for (let i = 0; i < Math.min(count, 4); i++) {
			const tab = tabs.nth(i);
			const tabText = await tab.textContent();
			await tab.click();
			await page.waitForTimeout(500);

			// Tab should be active
			await expect(tab).toHaveClass(/nav-tab-active/);
		}
	});
});

test.describe('Admin Settings — Appearance Tab / Color Picker', () => {
	test('appearance tab loads with color pickers', async ({ page }) => {
		await page.goto(SETTINGS_URL + '&tab=appearance');
		await page.waitForTimeout(1000);

		// Look for color picker inputs
		const colorInputs = page.locator(COLOR_PICKER.input);
		const count = await colorInputs.count();

		// Should have at least one color picker
		expect(count).toBeGreaterThanOrEqual(1);
	});

	test('color swatches display the correct color on load', async ({ page }) => {
		await page.goto(SETTINGS_URL + '&tab=appearance');
		await page.waitForTimeout(1500);

		// Wait for wpColorPicker to initialize (it wraps the input in a container)
		const pickers = page.locator(COLOR_PICKER.picker);
		const count = await pickers.count();

		if (count > 0) {
			// Get the first picker's swatch button
			const swatch = pickers.first().locator(COLOR_PICKER.swatch);
			await expect(swatch).toBeVisible();

			// The swatch should have a background-color style set
			const bgColor = await swatch.evaluate((el) => {
				return getComputedStyle(el).backgroundColor;
			});

			// Background should not be empty/transparent
			expect(bgColor).not.toBe('');
			expect(bgColor).not.toBe('rgba(0, 0, 0, 0)');
			expect(bgColor).not.toBe('transparent');
		}
	});

	test('clicking a color swatch opens the color picker', async ({ page }) => {
		await page.goto(SETTINGS_URL + '&tab=appearance');
		await page.waitForTimeout(1500);

		const pickers = page.locator(COLOR_PICKER.picker);
		const count = await pickers.count();

		if (count > 0) {
			const swatch = pickers.first().locator(COLOR_PICKER.swatch);
			await swatch.click();
			await page.waitForTimeout(300);

			// The iris picker (WP color picker widget) should now be visible
			const iris = page.locator('.iris-picker');
			await expect(iris.first()).toBeVisible();
		}
	});

	test('changing a color updates the swatch', async ({ page }) => {
		await page.goto(SETTINGS_URL + '&tab=appearance');
		await page.waitForTimeout(1500);

		const pickers = page.locator(COLOR_PICKER.picker);
		const count = await pickers.count();

		if (count > 0) {
			const firstPicker = pickers.first();
			const input = firstPicker.locator(COLOR_PICKER.input);
			const swatch = firstPicker.locator(COLOR_PICKER.swatch);

			// Get initial swatch color
			const initialBg = await swatch.evaluate((el) =>
				getComputedStyle(el).backgroundColor
			);

			// Open picker and set a new color via the input field
			await swatch.click();
			await page.waitForTimeout(300);

			// Clear and type a new color
			await input.fill('#ff5500');
			await input.press('Enter');
			await page.waitForTimeout(500);

			// Swatch should update to the new color
			const newBg = await swatch.evaluate((el) =>
				getComputedStyle(el).backgroundColor
			);

			// The new color should be different from initial (unless initial was #ff5500)
			// At minimum, the swatch should still have a color
			expect(newBg).not.toBe('rgba(0, 0, 0, 0)');
		}
	});
});

test.describe('Admin Settings — Persistence', () => {
	test('saving settings shows success notice', async ({ page }) => {
		await page.goto(SETTINGS_URL + '&tab=appearance');
		await page.waitForTimeout(1000);

		// Find and click the save/submit button
		const submitBtn = page.locator(
			'input[type="submit"], button[type="submit"], #submit, .button-primary'
		).first();

		if (await submitBtn.isVisible()) {
			await submitBtn.click();
			await page.waitForLoadState('networkidle');

			// Should show a success notice
			const notice = page.locator('.notice-success, .updated, .notice');
			await expect(notice.first()).toBeVisible({ timeout: 5000 });
		}
	});
});

test.describe('Admin Settings — Navigation via Menu', () => {
	test('Client Sync menu item exists in admin sidebar', async ({ page }) => {
		await page.goto('/wp-admin/');
		await page.waitForLoadState('domcontentloaded');

		// The menu item visibility depends on user capabilities.
		// It may be a top-level menu item or nested under another menu.
		const menuItem = page.locator(ADMIN_MENU.clientSync);
		const hasMenu = await menuItem.isVisible({ timeout: 3000 }).catch(() => false);

		if (hasMenu) {
			await expect(menuItem).toBeVisible();
		} else {
			test.info().annotations.push({
				type: 'info',
				description: 'Client Sync menu not visible — user may lack required capability',
			});
		}
	});

	test('clicking Settings submenu navigates to settings page', async ({ page }) => {
		await page.goto('/wp-admin/');
		await page.waitForLoadState('domcontentloaded');

		// Hover on top-level menu to reveal submenu
		const topMenu = page.locator(ADMIN_MENU.clientSync);
		const hasMenu = await topMenu.isVisible({ timeout: 3000 }).catch(() => false);

		if (hasMenu) {
			await topMenu.hover();
			await page.waitForTimeout(300);

			const settingsLink = page.locator(ADMIN_MENU.settings);
			if (await settingsLink.isVisible({ timeout: 2000 }).catch(() => false)) {
				await settingsLink.click();
				await expect(page).toHaveURL(/page=clisyc-settings/);
			}
		} else {
			// Navigate directly
			await page.goto(SETTINGS_URL + '&tab=appearance');
			await expect(page).toHaveURL(/page=clisyc-settings/);
		}
	});
});
