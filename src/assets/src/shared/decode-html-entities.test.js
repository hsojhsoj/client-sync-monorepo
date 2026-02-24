/**
 * File: src/assets/src/shared/decode-html-entities.test.js
 * Tests for the decodeHTMLEntities utility.
 */
import decodeHTMLEntities from './decode-html-entities';

describe('decodeHTMLEntities', () => {
	it('returns null/undefined unchanged', () => {
		expect(decodeHTMLEntities(null)).toBe(null);
		expect(decodeHTMLEntities(undefined)).toBe(undefined);
	});

	it('returns non-string values unchanged', () => {
		expect(decodeHTMLEntities(42)).toBe(42);
		expect(decodeHTMLEntities(true)).toBe(true);
	});

	it('returns plain strings unchanged', () => {
		expect(decodeHTMLEntities('Hello World')).toBe('Hello World');
	});

	it('decodes &amp;', () => {
		expect(decodeHTMLEntities('Tom &amp; Jerry')).toBe('Tom & Jerry');
	});

	it('decodes &lt; and &gt;', () => {
		expect(decodeHTMLEntities('&lt;div&gt;')).toBe('<div>');
	});

	it('decodes &quot;', () => {
		expect(decodeHTMLEntities('&quot;hello&quot;')).toBe('"hello"');
	});

	it('decodes &#039; (numeric apostrophe)', () => {
		expect(decodeHTMLEntities("it&#039;s")).toBe("it's");
	});

	it('decodes &#39; (short numeric apostrophe)', () => {
		expect(decodeHTMLEntities("it&#39;s")).toBe("it's");
	});

	it('decodes &apos;', () => {
		expect(decodeHTMLEntities("it&apos;s")).toBe("it's");
	});

	it('decodes &#x27; (hex apostrophe)', () => {
		expect(decodeHTMLEntities("it&#x27;s")).toBe("it's");
	});

	it('decodes &nbsp;', () => {
		expect(decodeHTMLEntities('hello&nbsp;world')).toBe('hello\u00A0world');
	});

	it('decodes en-dash &#8211;', () => {
		expect(decodeHTMLEntities('10&#8211;20')).toBe('10\u201320');
	});

	it('decodes em-dash &#8212;', () => {
		expect(decodeHTMLEntities('word&#8212;word')).toBe('word\u2014word');
	});

	it('decodes smart quotes', () => {
		expect(decodeHTMLEntities('&#8220;Hello&#8221;')).toBe('\u201CHello\u201D');
		expect(decodeHTMLEntities('&#8216;Hello&#8217;')).toBe('\u2018Hello\u2019');
	});

	it('decodes ellipsis &#8230;', () => {
		expect(decodeHTMLEntities('Wait&#8230;')).toBe('Wait\u2026');
	});

	it('decodes arbitrary numeric entities', () => {
		expect(decodeHTMLEntities('&#169;')).toBe('\u00A9'); // copyright
		expect(decodeHTMLEntities('&#8364;')).toBe('\u20AC'); // euro sign
	});

	it('decodes arbitrary hex entities', () => {
		expect(decodeHTMLEntities('&#xA9;')).toBe('\u00A9'); // copyright
		expect(decodeHTMLEntities('&#x20AC;')).toBe('\u20AC'); // euro sign
	});

	it('handles mixed entities in a single string', () => {
		const input = 'Dr. Smith&#039;s Office &amp; Clinic &lt;Open&gt;';
		const expected = "Dr. Smith's Office & Clinic <Open>";
		expect(decodeHTMLEntities(input)).toBe(expected);
	});

	it('passes through unknown named entities unchanged', () => {
		expect(decodeHTMLEntities('&unknown;')).toBe('&unknown;');
	});

	it('returns empty string unchanged', () => {
		expect(decodeHTMLEntities('')).toBe('');
	});
});
