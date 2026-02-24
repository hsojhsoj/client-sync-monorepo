/**
 * Auth Setup — logs into WordPress once and saves the session.
 *
 * All subsequent test projects that depend on "auth-setup" will reuse the
 * cached cookie / localStorage state from `.auth/admin.json`.
 */
import { test as setup, expect } from '@playwright/test';

const ADMIN_USER = 'claude';
const ADMIN_PASS = 'ZhQMjOhtUkzHS2G(hem)DTrD';
const AUTH_FILE = './tests/e2e/.auth/admin.json';

setup('authenticate as admin', async ({ page }) => {
	// WPS Hide Login plugin moves wp-login.php to a custom slug.
	// The custom login URL for this site is /administratordmedia/.
	await page.goto('/administratordmedia/', { waitUntil: 'networkidle' });

	// Wait for whichever login field the theme renders
	const loginField = page.locator('#user_login, input[name="log"]').first();
	await loginField.waitFor({ state: 'visible', timeout: 30000 });

	await loginField.fill(ADMIN_USER);
	await page.locator('#user_pass, input[name="pwd"]').first().fill(ADMIN_PASS);
	await page.locator('#wp-submit, input[type="submit"]').first().click();

	// Wait until we land on the dashboard
	await expect(page).toHaveURL(/wp-admin/, { timeout: 30000 });

	// Persist signed-in state
	await page.context().storageState({ path: AUTH_FILE });
});
