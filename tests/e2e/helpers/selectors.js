/**
 * Shared selectors and helpers for Client Sync E2E tests.
 */

// ── Admin menu ──────────────────────────────────────────────
export const ADMIN_MENU = {
	clientSync: '#toplevel_page_clisyc',
	settings: 'a[href*="page=clisyc-settings"]',
	dimensions: 'a[href*="page=clisyc-dimensions"]',
	calendars: 'a[href*="page=clisyc-calendars"]',
	reports: 'a[href*="page=clisyc-reports"]',
	dashboard: 'a[href*="page=clisyc"]',
};

// ── Settings page tabs ──────────────────────────────────────
export const SETTINGS_TABS = {
	appearance: '.nav-tab[href*="tab=appearance"], a[data-tab="appearance"]',
	behavior: '.nav-tab[href*="tab=behavior"], a[data-tab="behavior"]',
	notifications: '.nav-tab[href*="tab=notifications"], a[data-tab="notifications"]',
	integrations: '.nav-tab[href*="tab=integrations"], a[data-tab="integrations"]',
};

// ── Booking form (frontend) ─────────────────────────────────
export const BOOKING_FORM = {
	// Match either the standard booking form root or the hybrid booking root
	root: '#clisyc-booking-form-react-root, [id^="clisyc-hybrid-booking-root"]',
	app: '.clisyc-booking-form-app, .clisyc-hybrid-booking-root',
	sidebar: '.clisyc-sidebar',
	sidebarToggle: '.clisyc-sidebar-toggle',
	filterGroup: '.clisyc-sidebar-group',
	filterGroupHeader: '.clisyc-sidebar-group-header',
	filterItem: '.clisyc-sidebar-item',
	calendar: '#clisyc-calendar',
	calendarEvent: '.fc-event',
	availableSlot: '.clisyc-available-slot',
	bookedSlot: '.clisyc-booked-slot',
	selectedBanner: '.clisyc-selected-slot-banner',
	loadingOverlay: '.clisyc-loading-overlay',
	legend: '.clisyc-calendar-legend',
	timezoneSelect: '#clisyc-timezone-select',
	headerCompact: '.clisyc-header-compact',
	headerExpanded: '.clisyc-header-expanded-content',
	modal: '.clisyc-booking-modal',
	modalTitle: '#clisyc-booking-modal-title',
	modalConfirm: '.clisyc-booking-modal-btn-confirm',
	modalCancel: '.clisyc-booking-modal-btn-cancel',
	modalClose: '.clisyc-booking-modal-close',
	modalOverlay: '.clisyc-booking-modal-overlay',
	modalSuccess: '.clisyc-booking-modal-success',
	modalError: '.clisyc-booking-modal-error',
	guestName: '#guest_name',
	guestEmail: '#guest_email',
	notesField: '#booking_notes',
	emptyState: '.clisyc-empty-state',
	miniCalendar: '.clisyc-mini-calendar',
};

// ── Staff Dashboard ─────────────────────────────────────────
export const STAFF_DASHBOARD = {
	table: '.clisyc-staff-table',
	row: '.clisyc-staff-table tbody tr',
	checkInBtn: '.clisyc-staff-action-checkin',
	noShowBtn: '.clisyc-staff-action-noshow',
	notesBtn: '.clisyc-staff-action-notes',
	statusFilter: '.clisyc-staff-status-filter',
	toast: '.clisyc-toast',
};

// ── Timeline View ───────────────────────────────────────────
export const TIMELINE = {
	root: '.clisyc-timeline-wrapper',
	calendar: '.clisyc-timeline-container',
	resourceColumn: '.fc-resource',
	event: '.fc-event',
	legend: '.clisyc-timeline-legend',
};

// ── Color Picker (WP admin) ─────────────────────────────────
export const COLOR_PICKER = {
	input: 'input.wp-color-picker',
	swatch: '.wp-color-result',
	picker: '.wp-picker-container',
};

/**
 * Wait for the React booking form app to finish initial load.
 */
export async function waitForBookingFormReady(page) {
	// Wait for any of the booking-related React roots to mount
	await page.waitForSelector(
		'.clisyc-booking-form-app, [id^="clisyc-hybrid-booking-root"], #clisyc-booking-form-react-root',
		{ timeout: 15000 }
	);

	// Wait for loading overlay to disappear (calendar events fetched)
	await page.waitForSelector(BOOKING_FORM.loadingOverlay, { state: 'hidden', timeout: 30000 }).catch(() => {
		// Loading overlay might already be gone
	});

	// Dismiss any modal overlays that appear after load
	await dismissModals(page);

	// Brief settle for any remaining React state updates
	await page.waitForTimeout(500);
}

/**
 * Wait for FullCalendar to render its grid.
 */
export async function waitForCalendarRender(page, selector = BOOKING_FORM.calendar) {
	await page.waitForSelector(`${selector} .fc-view`, { timeout: 15000 });
}

/**
 * Dismiss any modal overlays (smart date, no availability).
 */
export async function dismissModals(page) {
	// The plugin uses several modal patterns — some have close buttons, some
	// have an <a> that navigates to another page (e.g. the "Contact Us" CTA
	// inside the "no availability" overlay). We must NOT follow those links.
	//
	// Strategy: remove all overlays and modals via JS first (the overlay
	// element itself intercepts pointer events on its own child buttons,
	// so we can't reliably click close buttons through the overlay).

	await page.evaluate(() => {
		// Remove modal overlays that block pointer events
		document.querySelectorAll('.clisyc-modal-overlay').forEach((el) => {
			el.remove();
		});
		// Remove any lingering loading overlays
		document.querySelectorAll('.clisyc-loading-overlay').forEach((el) => {
			el.style.display = 'none';
		});
	});
	await page.waitForTimeout(200);

	// If a modal is still visible after overlay removal (e.g. positioned
	// modals without an overlay wrapper), try clicking close buttons.
	const closeButtonSelectors = [
		'.clisyc-modal-content button:has-text("Close")',
		'.clisyc-modal-content button:has-text("OK")',
		'.clisyc-modal-content button:has-text("Got It")',
		'.clisyc-modal-content button:has-text("Dismiss")',
		'button.clisyc-modal-close',
	];

	for (const selector of closeButtonSelectors) {
		const btn = page.locator(selector).first();
		if (await btn.isVisible({ timeout: 500 }).catch(() => false)) {
			await btn.click({ force: true });
			await page.waitForTimeout(200);
		}
	}
}
