/**
 * File: src/assets/src/shared/decode-html-entities.js
 * Safely decode HTML entities (e.g., &#039; -> ') without using innerHTML.
 *
 * Uses a static character entity map instead of DOM manipulation to avoid
 * any XSS risk from user-controlled or translation-injected strings.
 *
 * @param {string} text - The string containing HTML entities.
 * @returns {string} The decoded string.
 */
const ENTITY_MAP = {
    '&amp;':  '&',
    '&lt;':   '<',
    '&gt;':   '>',
    '&quot;': '"',
    '&#039;': "'",
    '&#39;':  "'",
    '&apos;': "'",
    '&#x27;': "'",
    '&nbsp;': '\u00A0',
    '&#8211;': '\u2013', // en-dash
    '&#8212;': '\u2014', // em-dash
    '&#8216;': '\u2018', // left single quote
    '&#8217;': '\u2019', // right single quote / apostrophe
    '&#8220;': '\u201C', // left double quote
    '&#8221;': '\u201D', // right double quote
    '&#8230;': '\u2026', // ellipsis
};

// Match named entities (&amp;) or numeric entities (&#039; / &#x27;)
const ENTITY_REGEX = /&(?:#(?:x[0-9a-fA-F]+|\d+)|[a-zA-Z]+);/g;

export default function decodeHTMLEntities( text ) {
    if ( ! text || typeof text !== 'string' ) {
        return text;
    }

    return text.replace( ENTITY_REGEX, ( match ) => {
        // Check the static map first (covers the most common cases).
        if ( ENTITY_MAP[ match ] !== undefined ) {
            return ENTITY_MAP[ match ];
        }

        // Numeric decimal entity: &#123;
        if ( match.startsWith( '&#' ) && ! match.startsWith( '&#x' ) ) {
            const code = parseInt( match.slice( 2, -1 ), 10 );
            return isNaN( code ) ? match : String.fromCharCode( code );
        }

        // Numeric hex entity: &#x7B;
        if ( match.startsWith( '&#x' ) ) {
            const code = parseInt( match.slice( 3, -1 ), 16 );
            return isNaN( code ) ? match : String.fromCharCode( code );
        }

        // Unknown named entity — return as-is (safe).
        return match;
    } );
}
