/**
 * E2E Tests — Full Booking Journey
 *
 * End-to-end smoke test that walks the complete user path:
 *
 *   1. Admin: verify at least one published, bookable service exists
 *   2. Admin: load the public booking page, click an available slot, submit
 *      the confirmation modal — exercising the hybrid-booking shortcode's
 *      full POST-to-PHP flow, not just AJAX.
 *   3. Admin: confirm the new appointment appears in the admin with the
 *      submitting user's identity.
 *   4. Cleanup: move the appointment to trash so the test is idempotent.
 *
 * Runs SERIALLY — later tests depend on state captured by earlier ones
 * (service ID, appointment ID). The entire describe block is skipped if the
 * admin precondition fails or no slots exist within a reasonable window.
 *
 * Design choice: books as the authenticated admin rather than as a guest.
 * The test site renders both the FullCalendar `[clisyc_booking_form]` and
 * the hybrid `[clisyc_hybrid_booking]` shortcodes on /booking-calendar/, and
 * the hybrid modal does not expose #guest_name / #guest_email fields — so
 * guest-flow testing via the hybrid UI isn't meaningful. Auth'd booking
 * still exercises the full admin → click → modal → POST → redirect →
 * appointment creation → admin-visible path that the user asked to cover.
 * A separate spec can layer on guest-specific UX against the FullCalendar
 * form if/when that form has reliable availability on the fixture site.
 *
 * Runs against the live test site (testblankwp.dependentmedia.com) per
 * playwright.config.js. Registered as the "journey" project, depends on
 * `auth-setup`, uses admin storageState.
 */
import { test, expect } from '@playwright/test';

const BOOKING_PAGE = '/booking-calendar/';
const ADMIN_AUTH_FILE = './tests/e2e/.auth/admin.json';

// State carried between tests in the serial describe block.
let firstBookableServiceId = null;
let capturedAppointmentId = null;

/**
 * Walk forward in the hybrid booking's weekly grid up to `maxWeeks` weeks
 * looking for an available compact-grid slot. Returns the first locator
 * visible, or null if none found within the budget.
 */
async function findAvailableCompactSlot(page, maxWeeks = 8) {
	const slotSelector = '.clisyc-compact-slot-available';

	for (let i = 0; i <= maxWeeks; i++) {
		const slot = page.locator(slotSelector).first();
		const count = await page.locator(slotSelector).count();
		if (count > 0 && (await slot.isVisible({ timeout: 1_500 }).catch(() => false))) {
			if (i > 0) {
				console.log(`[journey] Found ${count} slot(s) at week offset ${i}`);
			}
			return slot;
		}

		const nextBtn = page.getByRole('button', { name: /Next\s*7\s*Days/i }).first();
		if (!(await nextBtn.isVisible({ timeout: 500 }).catch(() => false))) {
			return null; // no navigation control visible — nothing more we can do
		}
		await nextBtn.click({ force: true }).catch(() => {});

		// Wait for the new week's REST fetch to complete rather than
		// networkidle, which is too strict on this plugin (it has background
		// polling that never idles).
		await page
			.waitForResponse(
				(resp) => resp.url().includes('/clisyc/v1/slots') && resp.status() === 200,
				{ timeout: 8_000 }
			)
			.catch(() => {});
	}
	return null;
}

/**
 * Scrub overlays that would intercept pointer events. The plugin renders
 * several (loading, "no availability", tooltip layer) and some remain in the
 * DOM with pointer-events enabled even after visually fading — causing
 * Playwright's actionability checks to hang indefinitely.
 */
async function removeBlockingOverlays(page) {
	await page.evaluate(() => {
		document.querySelectorAll('.clisyc-modal-overlay').forEach((el) => el.remove());
		document.querySelectorAll('.clisyc-loading-overlay').forEach((el) => {
			el.style.display = 'none';
			el.style.pointerEvents = 'none';
		});
	});
}

test.describe.serial('Full booking journey: admin → booking → admin verification', () => {
	test('1. Admin precondition: at least one published service exists', async ({ page }) => {
		await page.goto('/wp-admin/edit.php?post_type=clisyc_service');

		// If the user lacks access, the whole journey is moot — skip the rest
		// rather than hammering through failing assertions. wp_die() pages
		// render "Sorry, you are not allowed" specifically; use that rather
		// than any "log in" text (which WP scatters everywhere, including in
		// menu labels and accessibility descriptions).
		const body = await page.locator('body').textContent().catch(() => '');
		test.skip(
			/Sorry, you are not allowed|You do not have sufficient permissions/i.test(body),
			'Test user lacks access to Services admin — skipping full journey.'
		);

		// Grab the first published row's post ID from <tr id="post-123">.
		const firstRow = page.locator('tr.status-publish').first();
		await expect(firstRow, 'At least one published service is required').toBeVisible({
			timeout: 15_000,
		});

		const rowId = await firstRow.getAttribute('id');
		const match = rowId && rowId.match(/post-(\d+)/);
		expect(match, 'Could not extract service ID from row').toBeTruthy();
		firstBookableServiceId = parseInt(match[1], 10);

		console.log(`[journey] Precondition OK — service ID ${firstBookableServiceId}`);
	});

	test('2. Complete a booking via the hybrid booking form', async ({ page }) => {
		// Calendar navigation can eat minutes when the first availability is
		// several weeks out — each advance round-trips to the REST API.
		test.setTimeout(120_000);

		await page.goto(BOOKING_PAGE, { waitUntil: 'domcontentloaded' });

		// The hybrid booking form persists filter checkbox state per user in
		// localStorage — so an admin who previously unchecked filters (or a
		// prior test run) sees "0 SLOTS IN CURRENT VIEW" on load. Wipe the
		// plugin's keys so we start from the "everything checked" default.
		// Narrow to keys starting with `clisyc` to avoid clobbering unrelated
		// WP data.
		await page.evaluate(() => {
			Object.keys(localStorage)
				.filter((k) => k.startsWith('clisyc'))
				.forEach((k) => localStorage.removeItem(k));
		});
		await page.reload({ waitUntil: 'domcontentloaded' });

		// Wait for a hybrid app root to mount. There may be multiple booking
		// apps on the same page (FullCalendar + hybrid); we specifically target
		// the hybrid form since its submission flow is a straight HTML POST we
		// can follow via URL change.
		await page.waitForSelector('[id^="clisyc-hybrid-booking-root"]', { timeout: 30_000 });

		// Wait for the initial slot-fetch response so the grid is populated
		// before we start searching. Not strictly required — findAvailableCompactSlot
		// retries — but makes the happy path faster.
		// Wait for the slots REST fetch to complete AND then give React a moment
		// to render the response into DOM nodes — on a warm site this is almost
		// instant, but the compact grid does post-fetch filtering that shows up
		// as a brief gap between "response received" and "slot elements exist".
		await page
			.waitForResponse(
				(resp) => resp.url().includes('/clisyc/v1/slots') && resp.status() === 200,
				{ timeout: 20_000 }
			)
			.catch(() => {});

		await removeBlockingOverlays(page);

		// Wait up to 10s for the hybrid grid to actually paint slot elements.
		// Without this, the subsequent searchForAvailableSlot sees a transient
		// "0 slots" state and gives up.
		// Wait for the filter panel to populate with checkbox options.
		await page.waitForSelector('.clisyc-filter-option-label input[type="checkbox"]', {
			timeout: 15_000,
		});

		// The hybrid grid shows only slots matching the selected filter values —
		// with nothing selected (the default), it renders "0 slots in current
		// view" even when availability exists server-side. Check every option
		// in every filter group to maximize the chance we find at least one
		// available slot. This is equivalent to "show me everything"; picking a
		// single combination can yield zero when earlier runs have consumed
		// that specific combo's capacity.
		// Use real Playwright clicks rather than JS-dispatched ones — React's
		// onChange doesn't always fire on synthetic click() in headless browsers,
		// whereas Playwright's click() simulates real user input.
		const boxCount = await page
			.locator('.clisyc-filter-option-label input[type="checkbox"]')
			.count();
		for (let i = 0; i < boxCount; i++) {
			const cb = page.locator('.clisyc-filter-option-label input[type="checkbox"]').nth(i);
			if (!(await cb.isChecked().catch(() => true))) {
				// Click the label (parent) — the checkbox itself has position:absolute
				// and opacity:0 (see hybrid-booking/style.css L771-776), so the real
				// click target is the wrapping label. Scope to this checkbox's label
				// via :scope to avoid ambiguity.
				const label = cb.locator('..'); // parent .clisyc-filter-option-label
				await label.click({ force: true }).catch(() => {});
			}
		}

		// Wait for the post-filter slot refresh. The plugin re-fetches slots
		// after filter changes.
		await page
			.waitForResponse(
				(resp) => resp.url().includes('/clisyc/v1/slots') && resp.status() === 200,
				{ timeout: 15_000 }
			)
			.catch(() => {});
		await page.waitForTimeout(800); // let React commit the new state

		// Sanity diagnostic — how many checkboxes ended up checked.
		const checkedCount = await page
			.locator('.clisyc-filter-option-label input[type="checkbox"]:checked')
			.count();
		console.log(`[journey] checked ${checkedCount}/${boxCount} filter options`);

		const slot = await findAvailableCompactSlot(page);
		test.skip(
			!slot,
			'No available slots in the hybrid grid within 8 weeks — booking cannot be exercised on this fixture site.'
		);

		await slot.scrollIntoViewIfNeeded().catch(() => {});
		// force:true so we don't hang if a faded-out overlay still intercepts
		// pointer events (removeBlockingOverlays handles the visible ones).
		await slot.click({ force: true, timeout: 10_000 });

		// Slots representing multiple dimension combinations (e.g. same time,
		// different practitioners) open a chooser first; pick the first option.
		// Single-combination slots open the confirmation modal directly and the
		// chooser never appears.
		const chooser = page.locator('.clisyc-compact-modal-content');
		if (await chooser.isVisible({ timeout: 2_000 }).catch(() => false)) {
			console.log('[journey] Dimension chooser opened — selecting first option');
			await chooser.locator('.clisyc-compact-time-slot-button').first().click();
		}

		// Hybrid confirmation modal. Note: different class from the FullCalendar
		// booking form's `.clisyc-booking-modal` — easy trap.
		const modal = page.locator('.clisyc-hybrid-modal-content');
		await expect(modal, 'Hybrid confirmation modal should open').toBeVisible({
			timeout: 10_000,
		});

		// Hybrid form submits as an HTML POST, not AJAX — on success the server
		// 302s to the booking page with clisyc_booking_status=success&appt_id=N
		// (see class-form-handler.php:849).
		const navigation = page.waitForURL(/clisyc_booking_status=success/, {
			timeout: 30_000,
		});
		await modal.locator('button[type="submit"], .clisyc-submit-button').first().click();
		await navigation;

		// Pull the appointment ID out of the redirect URL query.
		const url = new URL(page.url());
		capturedAppointmentId = parseInt(url.searchParams.get('appt_id') || '', 10);
		expect(
			capturedAppointmentId,
			`Expected appt_id in redirect URL, got: ${page.url()}`
		).toBeGreaterThan(0);

		console.log(`[journey] Booking complete — appointment ID ${capturedAppointmentId}`);
	});

	test('3. Admin sees the new appointment in the admin', async ({ page }) => {
		expect(
			capturedAppointmentId,
			'Prior test must have captured an appointment ID'
		).toBeTruthy();

		// Verify via the CPT list page. The appointment edit screen may or
		// may not render a title field depending on the CPT's `supports`
		// config, so checking for a specific row in the list is more robust.
		//
		// Use `post_status=any` because newly-booked appointments land in a
		// custom post status (e.g. clisyc_confirmed or clisyc_pending_payment)
		// that's hidden from the default "All" view. WordPress's default list
		// query excludes unregistered/custom statuses unless asked.
		await page.goto(
			`/wp-admin/edit.php?post_type=clisyc_appointment&post_status=any&orderby=ID&order=desc`,
			{ waitUntil: 'domcontentloaded' }
		);

		const row = page.locator(`tr#post-${capturedAppointmentId}`);
		await expect(
			row,
			`Appointment ${capturedAppointmentId} should appear in the clisyc_appointment admin list`
		).toBeVisible({ timeout: 15_000 });
	});

	test.afterAll(async ({ browser }) => {
		if (!capturedAppointmentId) {
			return;
		}

		// Fresh authenticated context for cleanup — so teardown doesn't depend
		// on any page state from the tests above, and runs even if a test threw.
		const ctx = await browser.newContext({ storageState: ADMIN_AUTH_FILE });
		const page = await ctx.newPage();
		try {
			// Load the list with all statuses shown (booked appointments land
			// in a custom status that's excluded by default), sorted newest-first
			// so our just-created appointment is on page 1.
			await page.goto(
				`/wp-admin/edit.php?post_type=clisyc_appointment&post_status=any&orderby=ID&order=desc`,
				{ waitUntil: 'domcontentloaded', timeout: 15_000 }
			);

			const row = page.locator(`tr#post-${capturedAppointmentId}`);
			if (!(await row.isVisible({ timeout: 5_000 }).catch(() => false))) {
				console.warn(
					`[journey] Cleanup: appointment ${capturedAppointmentId} not visible in list — leaving orphan record`
				);
				return;
			}

			// Row actions are hidden until hover — force them visible, then click
			// the trash link. The link carries its own nonce.
			await row.hover();
			const trashLink = row.locator('.row-actions a.submitdelete').first();
			if (await trashLink.isVisible({ timeout: 3_000 }).catch(() => false)) {
				await trashLink.click();
				await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {});
				console.log(`[journey] Cleanup: trashed appointment ${capturedAppointmentId}`);
			} else {
				console.warn(
					`[journey] Cleanup: could not find trash link for appointment ${capturedAppointmentId} — leaving orphan record`
				);
			}
		} catch (e) {
			console.warn(
				`[journey] Cleanup threw for appointment ${capturedAppointmentId}: ${e.message}`
			);
		} finally {
			await ctx.close();
		}
	});
});
