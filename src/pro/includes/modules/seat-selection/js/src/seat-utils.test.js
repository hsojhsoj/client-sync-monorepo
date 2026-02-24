/**
 * File: src/pro/includes/modules/seat-selection/js/src/seat-utils.test.js
 * Tests for seat selection utilities: SVG parsing, session tokens, seat status.
 */
import {
	getSessionToken,
	SEAT_COLORS,
	getSeatStatus,
	parseSVG,
} from './seat-utils';

// Mock crypto.randomUUID for jsdom (not available in older Node).
const origCrypto = global.crypto;
beforeAll(() => {
	Object.defineProperty(global, 'crypto', {
		value: {
			...(origCrypto || {}),
			randomUUID: jest.fn(() => '00000000-0000-4000-8000-000000000001'),
		},
		writable: true,
		configurable: true,
	});
});
afterAll(() => {
	Object.defineProperty(global, 'crypto', {
		value: origCrypto,
		writable: true,
		configurable: true,
	});
});

// ── Session Token ────────────────────────────────────────────────────

describe('getSessionToken', () => {
	beforeEach(() => {
		sessionStorage.clear();
	});

	it('generates a token and stores it in sessionStorage', () => {
		const token = getSessionToken();
		expect(token).toBeTruthy();
		expect(typeof token).toBe('string');
		expect(sessionStorage.getItem('clisyc_seat_session_token')).toBe(token);
	});

	it('returns the same token on repeated calls', () => {
		const first = getSessionToken();
		const second = getSessionToken();
		expect(first).toBe(second);
	});

	it('returns existing token from sessionStorage', () => {
		sessionStorage.setItem('clisyc_seat_session_token', 'pre-existing-token');
		const token = getSessionToken();
		expect(token).toBe('pre-existing-token');
	});
});

// ── Seat Status Colors ──────────────────────────────────────────────

describe('SEAT_COLORS', () => {
	it('maps every known status to fill and stroke', () => {
		const statuses = ['available', 'held', 'mine', 'booked', 'disabled', 'hover'];
		for (const status of statuses) {
			expect(SEAT_COLORS[status]).toBeDefined();
			expect(SEAT_COLORS[status].fill).toMatch(/^#/);
			expect(SEAT_COLORS[status].stroke).toMatch(/^#/);
		}
	});
});

// ── getSeatStatus ───────────────────────────────────────────────────

describe('getSeatStatus', () => {
	it('returns "booked" for a booked seat', () => {
		const availability = { booked: ['s1'], held: [], mine: [] };
		expect(getSeatStatus('s1', availability, [])).toBe('booked');
	});

	it('returns "mine" when seat is in myHolds', () => {
		const availability = { booked: [], held: [], mine: [] };
		expect(getSeatStatus('s1', availability, ['s1'])).toBe('mine');
	});

	it('returns "mine" when seat is in availability.mine', () => {
		const availability = { booked: [], held: [], mine: ['s1'] };
		expect(getSeatStatus('s1', availability, [])).toBe('mine');
	});

	it('returns "held" when seat is held by another session', () => {
		const availability = { booked: [], held: ['s1'], mine: [] };
		expect(getSeatStatus('s1', availability, [])).toBe('held');
	});

	it('returns "available" when seat is not in any list', () => {
		const availability = { booked: [], held: [], mine: [] };
		expect(getSeatStatus('s1', availability, [])).toBe('available');
	});

	it('prioritises "booked" over "mine"', () => {
		const availability = { booked: ['s1'], held: [], mine: ['s1'] };
		expect(getSeatStatus('s1', availability, ['s1'])).toBe('booked');
	});

	it('prioritises "mine" over "held"', () => {
		const availability = { booked: [], held: ['s1'], mine: ['s1'] };
		expect(getSeatStatus('s1', availability, ['s1'])).toBe('mine');
	});

	it('handles undefined mine/myHolds gracefully', () => {
		const availability = { booked: [], held: [] };
		expect(getSeatStatus('s1', availability, undefined)).toBe('available');
	});
});

// ── parseSVG — flat_ids mode ────────────────────────────────────────

describe('parseSVG — flat_ids', () => {
	it('returns null for empty string', () => {
		expect(parseSVG('')).toBeNull();
	});

	it('returns null for whitespace-only string', () => {
		expect(parseSVG('   ')).toBeNull();
	});

	it('returns null for invalid XML', () => {
		expect(parseSVG('<not valid xml>>>')).toBeNull();
	});

	it('returns null when SVG has no parseable seats', () => {
		const svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect id="standalone" /></svg>';
		// "standalone" is 1-part → skipped
		expect(parseSVG(svg)).toBeNull();
	});

	it('parses 3-part IDs into sections/rows/seats', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<rect id="vip-row1-seat1" />
			<rect id="vip-row1-seat2" />
			<rect id="general-row1-seat1" />
		</svg>`;

		const layout = parseSVG(svg, 'flat_ids');

		expect(layout).not.toBeNull();
		expect(layout.parsing_mode).toBe('flat_ids');
		expect(layout.total_seats).toBe(3);
		expect(layout.sections).toHaveLength(2);

		const vip = layout.sections.find((s) => s.id === 'vip');
		expect(vip).toBeDefined();
		expect(vip.label).toBe('Vip');
		expect(vip.rows).toHaveLength(1);
		expect(vip.rows[0].seats).toHaveLength(2);
	});

	it('parses 2-part IDs into _default section', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<circle id="row1-seat1" />
			<circle id="row1-seat2" />
			<circle id="row2-seat1" />
		</svg>`;

		const layout = parseSVG(svg, 'flat_ids');

		expect(layout.total_seats).toBe(3);
		expect(layout.sections).toHaveLength(1);
		expect(layout.sections[0].id).toBe('_default');
		expect(layout.sections[0].rows).toHaveLength(2);
	});

	it('uses custom delimiter when provided', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<rect id="sectionA_row1_seat1" />
			<rect id="sectionA_row1_seat2" />
		</svg>`;

		const layout = parseSVG(svg, 'flat_ids', '_');

		expect(layout.total_seats).toBe(2);
		expect(layout.sections[0].id).toBe('sectionA');
	});

	it('detects category from data-category attribute', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<rect id="a-row1-seat1" data-category="VIP" />
		</svg>`;

		const layout = parseSVG(svg, 'flat_ids');
		const seat = layout.sections[0].rows[0].seats[0];
		expect(seat.category).toBe('vip');
	});

	it('detects category from CSS class, ignoring SVG utility classes', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<rect id="a-row1-seat1" class="st0 premium" />
		</svg>`;

		const layout = parseSVG(svg, 'flat_ids');
		const seat = layout.sections[0].rows[0].seats[0];
		expect(seat.category).toBe('premium');
	});

	it('propagates category from first seat to section', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<rect id="vip-row1-seat1" data-category="gold" />
			<rect id="vip-row1-seat2" />
		</svg>`;

		const layout = parseSVG(svg, 'flat_ids');
		expect(layout.sections[0].category).toBe('gold');
	});

	it('sets status to "active" on all parsed seats', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<rect id="a-r1-s1" />
		</svg>`;

		const layout = parseSVG(svg, 'flat_ids');
		expect(layout.sections[0].rows[0].seats[0].status).toBe('active');
	});
});

// ── parseSVG — nested_groups mode ───────────────────────────────────

describe('parseSVG — nested_groups', () => {
	it('parses 3-level nested groups: section > row > seat (g elements)', () => {
		// For 3-level depth detection, seat level must also use <g> with IDs
		// so that detectGroupDepth returns ≥ 3.
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<g id="orchestra">
				<g id="row-a">
					<g id="seat-1"><circle cx="10" cy="10" r="5" /></g>
					<g id="seat-2"><circle cx="20" cy="10" r="5" /></g>
				</g>
				<g id="row-b">
					<g id="seat-3"><circle cx="10" cy="20" r="5" /></g>
				</g>
			</g>
			<g id="balcony">
				<g id="row-c">
					<g id="seat-4"><circle cx="10" cy="30" r="5" /></g>
				</g>
			</g>
		</svg>`;

		const layout = parseSVG(svg, 'nested_groups');

		expect(layout).not.toBeNull();
		expect(layout.parsing_mode).toBe('nested_groups');
		expect(layout.total_seats).toBe(4);
		expect(layout.sections).toHaveLength(2);

		const orchestra = layout.sections.find((s) => s.id === 'orchestra');
		expect(orchestra.rows).toHaveLength(2);
		expect(orchestra.rows[0].seats).toHaveLength(2);

		const balcony = layout.sections.find((s) => s.id === 'balcony');
		expect(balcony.rows).toHaveLength(1);
		expect(balcony.rows[0].seats).toHaveLength(1);
	});

	it('parses 2-level groups into _default section', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<g id="row-1">
				<rect id="seat-a" width="10" height="10" />
				<rect id="seat-b" width="10" height="10" />
			</g>
			<g id="row-2">
				<rect id="seat-c" width="10" height="10" />
			</g>
		</svg>`;

		const layout = parseSVG(svg, 'nested_groups');

		expect(layout.total_seats).toBe(3);
		expect(layout.sections).toHaveLength(1);
		expect(layout.sections[0].id).toBe('_default');
		expect(layout.sections[0].rows).toHaveLength(2);
	});

	it('detects data-category on section group', () => {
		// Use <g> seats so depth ≥ 3 triggers 3-level parsing.
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<g id="vip-section" data-category="VIP">
				<g id="row-1">
					<g id="s1"><circle cx="10" cy="10" r="5" /></g>
				</g>
			</g>
		</svg>`;

		const layout = parseSVG(svg, 'nested_groups');
		expect(layout.sections[0].category).toBe('vip');
		// Seat inherits section category.
		expect(layout.sections[0].rows[0].seats[0].category).toBe('vip');
	});

	it('returns null for SVG with no groups', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<circle cx="10" cy="10" r="5" />
		</svg>`;

		expect(parseSVG(svg, 'nested_groups')).toBeNull();
	});

	it('finds groups inside a wrapper <g> (one level deep)', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<g>
				<g id="row-1">
					<rect id="seat-1" width="10" height="10" />
				</g>
			</g>
		</svg>`;

		const layout = parseSVG(svg, 'nested_groups');
		expect(layout).not.toBeNull();
		expect(layout.total_seats).toBe(1);
	});

	it('treats <g> with id and no child groups as a seat', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<g id="row-1">
				<g id="complex-seat-1">
					<rect width="10" height="10" />
					<text>1</text>
				</g>
			</g>
		</svg>`;

		const layout = parseSVG(svg, 'nested_groups');
		expect(layout.total_seats).toBe(1);
		expect(layout.sections[0].rows[0].seats[0].id).toBe('complex-seat-1');
	});
});

// ── parseSVG — humanizeLabel ────────────────────────────────────────

describe('parseSVG — label humanization', () => {
	it('converts IDs to Title Case via humanizeLabel', () => {
		// 3-part: section=sectionA, row=row1, seatLabel=seat5
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<rect id="sectionA-row1-seat5" />
		</svg>`;

		const layout = parseSVG(svg, 'flat_ids');
		// Section ID is first part: "sectionA" → "SectionA" (\b only at start)
		expect(layout.sections[0].id).toBe('sectionA');
		expect(layout.sections[0].label).toBe('SectionA');
		// Row ID is second part: "row1" → "Row1"
		expect(layout.sections[0].rows[0].label).toBe('Row1');
		// Seat label is last part: "seat5" → "Seat5"
		expect(layout.sections[0].rows[0].seats[0].label).toBe('Seat5');
	});

	it('converts underscore IDs to Title Case labels', () => {
		// With "_" delimiter: parts = [main, floor, rowA, s1] — 4 parts
		// sectionId = "main", rowId = "floor", seatLabel = "s1"
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<rect id="main_floor_rowA_s1" />
		</svg>`;

		const layout = parseSVG(svg, 'flat_ids', '_');
		expect(layout.sections[0].id).toBe('main');
		expect(layout.sections[0].label).toBe('Main');
	});

	it('_default section gets empty label', () => {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg">
			<rect id="r1-s1" />
		</svg>`;

		const layout = parseSVG(svg, 'flat_ids');
		expect(layout.sections[0].id).toBe('_default');
		expect(layout.sections[0].label).toBe('');
	});
});
