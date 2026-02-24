/**
 * Playwright E2E Test Configuration
 *
 * Runs browser-level tests against the live WordPress test site.
 * Auth state is cached to avoid logging in before every test.
 *
 * Usage:
 *   npm run test:e2e          # headless
 *   npm run test:e2e:headed   # visible browser
 *   npm run test:e2e:ui       # interactive Playwright UI
 */
import { defineConfig, devices } from '@playwright/test';

const BASE_URL = 'https://testblankwp.dependentmedia.com';
const AUTH_FILE = './tests/e2e/.auth/admin.json';

export default defineConfig({
	testDir: './tests/e2e',
	fullyParallel: false, // sequential — one WordPress instance
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [['html', { open: 'never' }], ['list']],
	timeout: 60_000,
	expect: { timeout: 10_000 },

	use: {
		baseURL: BASE_URL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},

	projects: [
		// ── Auth setup (runs first, no storageState) ──────────
		{
			name: 'auth-setup',
			testMatch: /auth\.setup\.js$/,
		},

		// ── Admin tests (logged-in) ──────────────────────────
		{
			name: 'admin',
			testMatch: /admin\/.+\.spec\.js$/,
			dependencies: ['auth-setup'],
			use: {
				...devices['Desktop Chrome'],
				storageState: AUTH_FILE,
			},
		},

		// ── Frontend tests (public pages, no auth needed) ────
		{
			name: 'frontend',
			testMatch: /frontend\/.+\.spec\.js$/,
			use: {
				...devices['Desktop Chrome'],
			},
		},

		// ── Frontend tests that need auth (logged-in client) ─
		{
			name: 'frontend-auth',
			testMatch: /frontend-auth\/.+\.spec\.js$/,
			dependencies: ['auth-setup'],
			use: {
				...devices['Desktop Chrome'],
				storageState: AUTH_FILE,
			},
		},
	],
});
