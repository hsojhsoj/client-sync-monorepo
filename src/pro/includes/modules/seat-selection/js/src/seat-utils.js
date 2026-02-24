/**
 * Seat selection utilities.
 *
 * Client-side SVG parser (mirrors PHP SVG_Parser), status colors,
 * and session token management.
 */

// ── Session Token ────────────────────────────────────────────────────
const SESSION_TOKEN_KEY = 'clisyc_seat_session_token';

export function getSessionToken() {
	let token = sessionStorage.getItem( SESSION_TOKEN_KEY );
	if ( ! token ) {
		token = crypto.randomUUID();
		sessionStorage.setItem( SESSION_TOKEN_KEY, token );
	}
	return token;
}

// ── Seat Status Colors ──────────────────────────────────────────────
export const SEAT_COLORS = {
	available: { fill: '#d1fae5', stroke: '#065f46' },
	held:      { fill: '#fef3c7', stroke: '#92400e' },
	mine:      { fill: '#dbeafe', stroke: '#1d4ed8' },
	booked:    { fill: '#fee2e2', stroke: '#991b1b' },
	disabled:  { fill: '#e5e7eb', stroke: '#9ca3af' },
	hover:     { fill: '#a7f3d0', stroke: '#047857' },
};

export function getSeatStatus( seatId, availability, myHolds ) {
	if ( availability.booked?.includes( seatId ) ) return 'booked';
	if ( myHolds?.includes( seatId ) || availability.mine?.includes( seatId ) ) return 'mine';
	if ( availability.held?.includes( seatId ) ) return 'held';
	return 'available';
}

// ── Client-side SVG Parser ──────────────────────────────────────────

/**
 * Parse an SVG string into venue layout JSON.
 *
 * Mirrors the PHP SVG_Parser for instant admin preview.
 *
 * @param {string} svgString Raw SVG markup.
 * @param {string} mode      'flat_ids' or 'nested_groups'.
 * @param {string} delimiter Delimiter for flat_ids mode.
 * @return {object|null} Layout object or null on failure.
 */
export function parseSVG( svgString, mode = 'flat_ids', delimiter = '-' ) {
	if ( ! svgString?.trim() ) return null;

	const parser = new DOMParser();
	const doc = parser.parseFromString( svgString, 'image/svg+xml' );

	const errorNode = doc.querySelector( 'parsererror' );
	if ( errorNode ) return null;

	let sections;
	if ( mode === 'nested_groups' ) {
		sections = parseNestedGroups( doc );
	} else {
		sections = parseFlatIds( doc, delimiter );
	}

	if ( ! sections || sections.length === 0 ) return null;

	let totalSeats = 0;
	for ( const section of sections ) {
		for ( const row of section.rows ) {
			totalSeats += row.seats.length;
		}
	}

	if ( totalSeats === 0 ) return null;

	return {
		parsing_mode: mode,
		total_seats: totalSeats,
		sections,
	};
}

function parseFlatIds( doc, delimiter ) {
	const sections = {};
	const elements = doc.querySelectorAll( '[id]' );

	for ( const el of elements ) {
		const id = el.getAttribute( 'id' );
		const parts = id.split( delimiter );

		if ( parts.length < 2 ) continue;

		let sectionId, rowId, seatLabel;

		if ( parts.length >= 3 ) {
			sectionId = parts[ 0 ];
			rowId = parts[ 1 ];
			seatLabel = parts[ parts.length - 1 ];
		} else {
			sectionId = '_default';
			rowId = parts[ 0 ];
			seatLabel = parts[ 1 ];
		}

		const category = detectCategory( el );

		if ( ! sections[ sectionId ] ) {
			sections[ sectionId ] = {
				id: sectionId,
				label: humanizeLabel( sectionId ),
				category: '',
				svg_element_id: sectionId !== '_default' ? sectionId : '',
				rows: {},
			};
		}

		if ( category && ! sections[ sectionId ].category ) {
			sections[ sectionId ].category = category;
		}

		if ( ! sections[ sectionId ].rows[ rowId ] ) {
			sections[ sectionId ].rows[ rowId ] = {
				id: rowId,
				label: humanizeLabel( rowId ),
				svg_element_id: rowId,
				seats: [],
			};
		}

		sections[ sectionId ].rows[ rowId ].seats.push( {
			id,
			label: humanizeLabel( seatLabel ),
			svg_element_id: id,
			category: category || '',
			status: 'active',
		} );
	}

	return Object.values( sections ).map( ( section ) => ( {
		...section,
		rows: Object.values( section.rows ),
	} ) );
}

function parseNestedGroups( doc ) {
	const svgRoot = doc.querySelector( 'svg' );
	if ( ! svgRoot ) return [];

	let topGroups = getChildGroupsWithIds( svgRoot );

	// Try one level deeper if no top-level groups found.
	if ( topGroups.length === 0 ) {
		for ( const child of svgRoot.children ) {
			if ( child.tagName === 'g' ) {
				topGroups = getChildGroupsWithIds( child );
				if ( topGroups.length > 0 ) break;
			}
		}
	}

	if ( topGroups.length === 0 ) return [];

	const maxDepth = detectGroupDepth( topGroups );

	if ( maxDepth >= 3 ) {
		return parseThreeLevelGroups( topGroups );
	}

	return parseTwoLevelGroups( topGroups );
}

function parseThreeLevelGroups( sectionGroups ) {
	const sections = [];

	for ( const sectionEl of sectionGroups ) {
		const sectionId = sectionEl.getAttribute( 'id' );
		const category = detectCategory( sectionEl );

		const section = {
			id: sectionId,
			label: humanizeLabel( sectionId ),
			category,
			svg_element_id: sectionId,
			rows: [],
		};

		const rowGroups = getChildGroupsWithIds( sectionEl );
		for ( const rowEl of rowGroups ) {
			const rowId = rowEl.getAttribute( 'id' );
			const row = {
				id: rowId,
				label: humanizeLabel( rowId ),
				svg_element_id: rowId,
				seats: [],
			};

			const seatElements = getSeatElements( rowEl );
			for ( const seatEl of seatElements ) {
				const seatId = seatEl.getAttribute( 'id' );
				const seatCategory = detectCategory( seatEl );
				row.seats.push( {
					id: seatId,
					label: humanizeLabel( seatId ),
					svg_element_id: seatId,
					category: seatCategory || category,
					status: 'active',
				} );
			}

			if ( row.seats.length > 0 ) section.rows.push( row );
		}

		if ( section.rows.length > 0 ) sections.push( section );
	}

	return sections;
}

function parseTwoLevelGroups( rowGroups ) {
	const section = {
		id: '_default',
		label: '',
		category: '',
		svg_element_id: '',
		rows: [],
	};

	for ( const rowEl of rowGroups ) {
		const rowId = rowEl.getAttribute( 'id' );
		const row = {
			id: rowId,
			label: humanizeLabel( rowId ),
			svg_element_id: rowId,
			seats: [],
		};

		const seatElements = getSeatElements( rowEl );
		for ( const seatEl of seatElements ) {
			const seatId = seatEl.getAttribute( 'id' );
			const category = detectCategory( seatEl );
			row.seats.push( {
				id: seatId,
				label: humanizeLabel( seatId ),
				svg_element_id: seatId,
				category: category || '',
				status: 'active',
			} );
		}

		if ( row.seats.length > 0 ) section.rows.push( row );
	}

	return section.rows.length > 0 ? [ section ] : [];
}

function getChildGroupsWithIds( parent ) {
	return Array.from( parent.children ).filter(
		( el ) => el.tagName === 'g' && el.hasAttribute( 'id' )
	);
}

function getSeatElements( parent ) {
	const shapeTags = [ 'rect', 'circle', 'ellipse', 'path', 'polygon', 'polyline', 'line' ];
	const seats = [];

	for ( const child of parent.children ) {
		if ( ! child.hasAttribute( 'id' ) ) continue;

		if ( shapeTags.includes( child.tagName ) ) {
			seats.push( child );
		} else if ( child.tagName === 'g' ) {
			const subGroups = getChildGroupsWithIds( child );
			if ( subGroups.length === 0 ) {
				seats.push( child );
			}
		}
	}

	return seats;
}

function detectGroupDepth( groups ) {
	let maxDepth = 1;
	for ( const group of groups ) {
		const childGroups = getChildGroupsWithIds( group );
		if ( childGroups.length > 0 ) {
			const childDepth = detectGroupDepth( childGroups );
			maxDepth = Math.max( maxDepth, 1 + childDepth );
		}
	}
	return maxDepth;
}

function detectCategory( element ) {
	if ( element.hasAttribute( 'data-category' ) ) {
		return element.getAttribute( 'data-category' ).trim().toLowerCase();
	}

	if ( element.hasAttribute( 'class' ) ) {
		const classes = element.getAttribute( 'class' ).trim().split( /\s+/ );
		const ignorePattern = /^(st|cls-|fil)\d+$/;
		for ( const cls of classes ) {
			if ( cls && ! ignorePattern.test( cls ) ) {
				return cls.toLowerCase();
			}
		}
	}

	return '';
}

function humanizeLabel( id ) {
	if ( id === '_default' || ! id ) return '';
	return id.replace( /[-_]/g, ' ' ).replace( /\b\w/g, ( c ) => c.toUpperCase() ).trim();
}
