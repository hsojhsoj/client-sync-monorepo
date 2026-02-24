/**
 * VenueAdmin — Admin React app for the clisyc_venue CPT edit screen.
 *
 * Provides:
 *  - SVG upload (Media Library) / paste toggle
 *  - Parsing mode selector (flat_ids / nested_groups)
 *  - Live SVG preview with parsed seat map
 *  - Category override editor
 *  - Pricing tier editor (mounts in separate root)
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { Button, SelectControl, TextControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { parseSVG, SEAT_COLORS } from './seat-utils';
import {
	EXAMPLE_SVG_FLAT_IDS,
	EXAMPLE_SVG_NESTED_GROUPS,
	EXAMPLE_OVERVIEW_SVG_FLAT_IDS,
	EXAMPLE_OVERVIEW_SVG_NESTED_GROUPS,
	downloadStringAsFile,
} from './example-svgs';

// ── SVG Upload / Paste Panel ────────────────────────────────────────
function SvgInput( { svgString, onSvgChange } ) {
	const [ inputMode, setInputMode ] = useState( 'upload' ); // 'upload' | 'paste'

	const handleMediaUpload = useCallback( () => {
		const frame = wp.media( {
			title: __( 'Select Venue SVG', 'client-sync-pro' ),
			button: { text: __( 'Use this SVG', 'client-sync-pro' ) },
			multiple: false,
			library: { type: 'image/svg+xml' },
		} );

		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			if ( attachment.url ) {
				// Fetch the SVG content from the URL.
				fetch( attachment.url )
					.then( ( res ) => res.text() )
					.then( ( text ) => onSvgChange( text ) )
					.catch( () => {} );
			}
		} );

		frame.open();
	}, [ onSvgChange ] );

	return (
		<div className="clisyc-svg-input">
			<div className="clisyc-svg-input__toggle" style={ { marginBottom: '12px' } }>
				<Button
					variant={ inputMode === 'upload' ? 'primary' : 'secondary' }
					onClick={ () => setInputMode( 'upload' ) }
					size="small"
				>
					{ __( 'Media Library', 'client-sync-pro' ) }
				</Button>
				<Button
					variant={ inputMode === 'paste' ? 'primary' : 'secondary' }
					onClick={ () => setInputMode( 'paste' ) }
					size="small"
					style={ { marginLeft: '8px' } }
				>
					{ __( 'Paste SVG', 'client-sync-pro' ) }
				</Button>
			</div>

			{ inputMode === 'upload' ? (
				<div>
					<Button variant="secondary" onClick={ handleMediaUpload }>
						{ svgString ? __( 'Replace SVG', 'client-sync-pro' ) : __( 'Upload SVG', 'client-sync-pro' ) }
					</Button>
					{ svgString && (
						<span style={ { marginLeft: '12px', color: '#065f46' } }>
							{ __( 'SVG loaded', 'client-sync-pro' ) }
						</span>
					) }
				</div>
			) : (
				<TextareaControl
					label={ __( 'Paste SVG markup', 'client-sync-pro' ) }
					value={ svgString }
					onChange={ onSvgChange }
					rows={ 8 }
					help={ __( 'Paste the raw SVG XML content here.', 'client-sync-pro' ) }
				/>
			) }

			{ svgString && (
				<Button
					isDestructive
					variant="link"
					onClick={ () => onSvgChange( '' ) }
					style={ { marginTop: '8px' } }
				>
					{ __( 'Clear SVG', 'client-sync-pro' ) }
				</Button>
			) }
		</div>
	);
}

// ── SVG Preview ─────────────────────────────────────────────────────
function SvgPreview( { svgString, layout, parsingMode, onSvgChange } ) {
	const containerRef = useRef( null );

	useEffect( () => {
		if ( ! containerRef.current || ! svgString ) return;

		// Safely render the SVG.
		containerRef.current.innerHTML = '';
		const parser = new DOMParser();
		const doc = parser.parseFromString( svgString, 'image/svg+xml' );
		const svgEl = doc.querySelector( 'svg' );

		if ( ! svgEl ) return;

		// Ensure responsive sizing.
		svgEl.setAttribute( 'width', '100%' );
		svgEl.setAttribute( 'height', 'auto' );
		svgEl.style.maxHeight = '500px';

		containerRef.current.appendChild( svgEl );

		// Color the seats based on layout.
		if ( layout?.sections ) {
			for ( const section of layout.sections ) {
				for ( const row of section.rows ) {
					for ( const seat of row.seats ) {
						const el = svgEl.querySelector( `#${ CSS.escape( seat.svg_element_id ) }` );
						if ( el ) {
							el.style.fill = SEAT_COLORS.available.fill;
							el.style.stroke = SEAT_COLORS.available.stroke;
							el.style.strokeWidth = '1';
							el.style.cursor = 'pointer';

							// Add title for tooltip.
							const title = document.createElementNS( 'http://www.w3.org/2000/svg', 'title' );
							const categoryLabel = seat.category ? ` (${ seat.category })` : '';
							title.textContent = `${ seat.label }${ categoryLabel }`;
							el.appendChild( title );
						}
					}
				}
			}
		}
	}, [ svgString, layout ] );

	if ( ! svgString ) {
		const exampleSvg = parsingMode === 'nested_groups' ? EXAMPLE_SVG_NESTED_GROUPS : EXAMPLE_SVG_FLAT_IDS;
		const exampleFilename = parsingMode === 'nested_groups' ? 'example-venue-nested-groups.svg' : 'example-venue-flat-ids.svg';

		return (
			<div className="clisyc-svg-preview__empty">
				<p>{ __( 'Upload or paste an SVG to see a preview.', 'client-sync-pro' ) }</p>
				<div className="clisyc-svg-example-actions">
					<p className="clisyc-svg-example-actions__intro">
						{ __( 'Need a starting point? Download an example SVG or load it directly:', 'client-sync-pro' ) }
					</p>
					<div className="clisyc-svg-example-actions__buttons">
						<Button
							variant="secondary"
							size="small"
							onClick={ () => downloadStringAsFile( exampleSvg, exampleFilename ) }
						>
							{ __( 'Download Example SVG', 'client-sync-pro' ) }
						</Button>
						<Button
							variant="tertiary"
							size="small"
							onClick={ () => onSvgChange( exampleSvg ) }
						>
							{ __( 'Load Example into Editor', 'client-sync-pro' ) }
						</Button>
					</div>
					<p className="clisyc-svg-example-actions__hint">
						{ parsingMode === 'nested_groups'
							? __( 'The example uses nested <g> groups: section > row > seat elements.', 'client-sync-pro' )
							: __( 'The example uses flat element IDs: section-row-seat (e.g. orchestra-rowA-seat1).', 'client-sync-pro' )
						}
					</p>
				</div>
			</div>
		);
	}

	return (
		<div className="clisyc-svg-preview" ref={ containerRef } />
	);
}

// ── Layout Summary ──────────────────────────────────────────────────
function LayoutSummary( { layout } ) {
	if ( ! layout || ! Array.isArray( layout.sections ) ) return null;

	const sections = layout.sections;
	const hasSections = sections.length > 1 || ( sections.length === 1 && sections[ 0 ].id !== '_default' );
	const totalRows = sections.reduce( ( sum, s ) => sum + ( s.rows?.length || 0 ), 0 );

	return (
		<div className="clisyc-layout-summary" style={ { marginTop: '16px', padding: '12px', background: '#f0fdf4', borderRadius: '4px', border: '1px solid #bbf7d0' } }>
			<strong>{ __( 'Parsed Layout', 'client-sync-pro' ) }</strong>
			<ul style={ { margin: '8px 0 0', paddingLeft: '20px' } }>
				<li>{ __( 'Total seats:', 'client-sync-pro' ) } <strong>{ layout.total_seats }</strong></li>
				{ hasSections && (
					<li>{ __( 'Sections:', 'client-sync-pro' ) } <strong>{ sections.length }</strong></li>
				) }
				<li>
					{ __( 'Rows:', 'client-sync-pro' ) }{ ' ' }
					<strong>{ totalRows }</strong>
				</li>
			</ul>
			{ sections.map( ( section ) => (
				section.id !== '_default' && (
					<div key={ section.id } style={ { marginTop: '8px', fontSize: '13px' } }>
						<strong>{ section.label }</strong>
						{ section.category && <span style={ { marginLeft: '8px', color: '#6b7280' } }>({ section.category })</span> }
						{ ' — ' }
						{ ( section.rows || [] ).reduce( ( sum, r ) => sum + ( r.seats?.length || 0 ), 0 ) } { __( 'seats', 'client-sync-pro' ) }
					</div>
				)
			) ) }
		</div>
	);
}

// ── Category Overrides ──────────────────────────────────────────────
function CategoryOverrides( { layout, overrides, onOverridesChange } ) {
	if ( ! layout?.sections || layout.sections.length <= 1 && layout.sections[ 0 ]?.id === '_default' ) {
		return null;
	}

	const handleChange = ( sectionId, category ) => {
		const updated = [ ...overrides.filter( ( o ) => o.section_id !== sectionId ) ];
		if ( category ) {
			updated.push( { section_id: sectionId, category } );
		}
		onOverridesChange( updated );
	};

	return (
		<div style={ { marginTop: '16px' } }>
			<strong>{ __( 'Category Overrides (per section)', 'client-sync-pro' ) }</strong>
			<p className="description" style={ { marginTop: '4px' } }>
				{ __( 'Override the SVG-detected category for each section. Leave blank to use the SVG default.', 'client-sync-pro' ) }
			</p>
			{ layout.sections.map( ( section ) => {
				if ( section.id === '_default' ) return null;
				const current = overrides.find( ( o ) => o.section_id === section.id )?.category || '';
				return (
					<TextControl
						key={ section.id }
						label={ section.label + ( section.category ? ` (SVG: ${ section.category })` : '' ) }
						value={ current }
						onChange={ ( val ) => handleChange( section.id, val ) }
						placeholder={ section.category || __( 'No category', 'client-sync-pro' ) }
					/>
				);
			} ) }
		</div>
	);
}

// ── Pricing Tiers Editor ────────────────────────────────────────────
function PricingTiers( { tiers, onTiersChange } ) {
	const addTier = () => {
		onTiersChange( [ ...tiers, { category: '', price: 0, label: '' } ] );
	};

	const updateTier = ( index, field, value ) => {
		const updated = [ ...tiers ];
		updated[ index ] = { ...updated[ index ], [ field ]: value };
		onTiersChange( updated );
	};

	const removeTier = ( index ) => {
		onTiersChange( tiers.filter( ( _, i ) => i !== index ) );
	};

	return (
		<div>
			<p className="description">
				{ __( 'Define pricing for each seat category. Price is in the smallest currency unit (e.g. cents).', 'client-sync-pro' ) }
			</p>
			{ tiers.map( ( tier, i ) => (
				<div key={ i } style={ { display: 'flex', gap: '8px', alignItems: 'end', marginBottom: '8px' } }>
					<TextControl
						label={ __( 'Category', 'client-sync-pro' ) }
						value={ tier.category }
						onChange={ ( val ) => updateTier( i, 'category', val ) }
						placeholder="vip"
					/>
					<TextControl
						label={ __( 'Label', 'client-sync-pro' ) }
						value={ tier.label }
						onChange={ ( val ) => updateTier( i, 'label', val ) }
						placeholder="VIP"
					/>
					<TextControl
						label={ __( 'Price (cents)', 'client-sync-pro' ) }
						type="number"
						value={ String( tier.price ) }
						onChange={ ( val ) => updateTier( i, 'price', parseInt( val, 10 ) || 0 ) }
						min="0"
					/>
					<Button isDestructive variant="link" onClick={ () => removeTier( i ) }>
						{ __( 'Remove', 'client-sync-pro' ) }
					</Button>
				</div>
			) ) }
			<Button variant="secondary" onClick={ addTier }>
				{ __( '+ Add Pricing Tier', 'client-sync-pro' ) }
			</Button>
		</div>
	);
}

// ── Example SVG Download Link ───────────────────────────────────────
function ExampleDownloadLink( { parsingMode } ) {
	const handleDownload = useCallback( () => {
		const svg = parsingMode === 'nested_groups' ? EXAMPLE_SVG_NESTED_GROUPS : EXAMPLE_SVG_FLAT_IDS;
		const filename = parsingMode === 'nested_groups' ? 'example-venue-nested-groups.svg' : 'example-venue-flat-ids.svg';
		downloadStringAsFile( svg, filename );
	}, [ parsingMode ] );

	return (
		<p className="clisyc-example-download-link" style={ { marginTop: '4px' } }>
			<Button
				variant="link"
				size="small"
				onClick={ handleDownload }
			>
				{ __( 'Download an example SVG for this mode', 'client-sync-pro' ) }
			</Button>
		</p>
	);
}

// ── Main Venue Admin App ────────────────────────────────────────────
export default function VenueAdmin( { venueId, initialMode, initialLayout, initialOverrides } ) {
	const [ svgString, setSvgString ] = useState( '' );
	const [ overviewSvgString, setOverviewSvgString ] = useState( '' );
	const [ parsingMode, setParsingMode ] = useState( initialMode || 'flat_ids' );
	const [ layout, setLayout ] = useState( initialLayout );
	const [ overrides, setOverrides ] = useState( initialOverrides || [] );

	// Determine if layout has sections.
	const hasSections = layout?.sections && (
		layout.sections.length > 1 ||
		( layout.sections.length === 1 && layout.sections[ 0 ].id !== '_default' )
	);

	// Load initial SVGs from hidden fields.
	useEffect( () => {
		const hiddenSvg = document.getElementById( 'clisyc_venue_svg' );
		if ( hiddenSvg?.value ) {
			setSvgString( hiddenSvg.value );
		}
		const hiddenOverview = document.getElementById( 'clisyc_venue_overview_svg' );
		if ( hiddenOverview?.value ) {
			setOverviewSvgString( hiddenOverview.value );
		}
	}, [] );

	// Re-parse when SVG or mode changes.
	useEffect( () => {
		if ( ! svgString ) {
			setLayout( null );
			syncHiddenFields( '', parsingMode, null, overrides );
			return;
		}

		const parsed = parseSVG( svgString, parsingMode );
		setLayout( parsed );
		syncHiddenFields( svgString, parsingMode, parsed, overrides );
	}, [ svgString, parsingMode ] );

	// Sync overrides to hidden field.
	useEffect( () => {
		const el = document.getElementById( 'clisyc_venue_category_overrides' );
		if ( el ) el.value = JSON.stringify( overrides );
	}, [ overrides ] );

	// Sync overview SVG to hidden field.
	useEffect( () => {
		const el = document.getElementById( 'clisyc_venue_overview_svg' );
		if ( el ) el.value = overviewSvgString;
	}, [ overviewSvgString ] );

	const overviewExample = parsingMode === 'nested_groups'
		? EXAMPLE_OVERVIEW_SVG_NESTED_GROUPS
		: EXAMPLE_OVERVIEW_SVG_FLAT_IDS;

	return (
		<div className="clisyc-venue-admin">
			<SvgInput svgString={ svgString } onSvgChange={ setSvgString } />

			<div style={ { marginTop: '16px' } }>
				<SelectControl
					label={ __( 'Parsing Mode', 'client-sync-pro' ) }
					value={ parsingMode }
					options={ [
						{ value: 'flat_ids', label: __( 'Flat Element IDs (e.g. sectionA-row1-seat5)', 'client-sync-pro' ) },
						{ value: 'nested_groups', label: __( 'Nested Group IDs (Illustrator layers)', 'client-sync-pro' ) },
					] }
					onChange={ setParsingMode }
					help={ __( 'Choose based on how your SVG is structured.', 'client-sync-pro' ) }
				/>
				<ExampleDownloadLink parsingMode={ parsingMode } />
			</div>

			<SvgPreview svgString={ svgString } layout={ layout } parsingMode={ parsingMode } onSvgChange={ setSvgString } />
			<LayoutSummary layout={ layout } />
			<CategoryOverrides
				layout={ layout }
				overrides={ overrides }
				onOverridesChange={ setOverrides }
			/>

			{ /* Overview SVG — only shown when layout has sections */ }
			{ hasSections && (
				<div style={ { marginTop: '24px', borderTop: '1px solid #e5e7eb', paddingTop: '16px' } }>
					<h4 style={ { marginTop: 0 } }>{ __( 'Section Overview SVG (Optional)', 'client-sync-pro' ) }</h4>
					<p className="description" style={ { marginBottom: '12px' } }>
						{ __( 'Upload a separate high-level SVG showing the venue layout. Users will click sections in this overview to choose where to sit. Element IDs in this SVG must match your section IDs:', 'client-sync-pro' ) }
						{ ' ' }
						<code>{ layout.sections.filter( ( s ) => s.id !== '_default' ).map( ( s ) => s.id ).join( ', ' ) }</code>
					</p>

					<SvgInput svgString={ overviewSvgString } onSvgChange={ setOverviewSvgString } />

					{ ! overviewSvgString && (
						<div style={ { marginTop: '8px' } }>
							<Button variant="link" size="small" onClick={ () => setOverviewSvgString( overviewExample ) }>
								{ __( 'Load Example Overview SVG', 'client-sync-pro' ) }
							</Button>
						</div>
					) }

					{ overviewSvgString && (
						<OverviewSvgPreview svgString={ overviewSvgString } layout={ layout } />
					) }
				</div>
			) }
		</div>
	);
}

// ── Overview SVG Admin Preview ──────────────────────────────────────
function OverviewSvgPreview( { svgString, layout } ) {
	const containerRef = useRef( null );

	useEffect( () => {
		if ( ! containerRef.current || ! svgString ) return;
		containerRef.current.innerHTML = '';

		const parser = new DOMParser();
		const doc = parser.parseFromString( svgString, 'image/svg+xml' );
		const svgEl = doc.querySelector( 'svg' );
		if ( ! svgEl ) return;

		svgEl.setAttribute( 'width', '100%' );
		svgEl.setAttribute( 'height', 'auto' );
		svgEl.style.maxHeight = '400px';

		// Highlight elements matching section IDs.
		if ( layout?.sections ) {
			for ( const section of layout.sections ) {
				if ( section.id === '_default' ) continue;
				const el = svgEl.querySelector( `#${ CSS.escape( section.id ) }` );
				if ( el ) {
					const colors = getSectionColors( section.category );
					el.style.cursor = 'pointer';
					el.style.fill = colors.fill;
					el.style.stroke = colors.stroke;
					el.style.strokeWidth = '2';

					const title = document.createElementNS( 'http://www.w3.org/2000/svg', 'title' );
					title.textContent = `${ section.label }${ section.category ? ` (${ section.category })` : '' }`;
					el.appendChild( title );
				}
			}
		}

		containerRef.current.appendChild( svgEl );
	}, [ svgString, layout ] );

	return <div className="clisyc-svg-preview" style={ { marginTop: '12px' } } ref={ containerRef } />;
}

/** Category color map shared with frontend SeatMapPicker. */
function getSectionColors( category ) {
	const map = {
		vip: { fill: '#ede9fe', stroke: '#7c3aed' },
		premium: { fill: '#fef3c7', stroke: '#d97706' },
		standard: { fill: '#d1fae5', stroke: '#065f46' },
		general: { fill: '#d1fae5', stroke: '#065f46' },
	};
	return map[ category ] || { fill: '#e0f2fe', stroke: '#0284c7' };
}

function syncHiddenFields( svg, mode, layout, overrides ) {
	const svgEl = document.getElementById( 'clisyc_venue_svg' );
	const modeEl = document.getElementById( 'clisyc_venue_parsing_mode' );
	const layoutEl = document.getElementById( 'clisyc_venue_layout' );
	const overridesEl = document.getElementById( 'clisyc_venue_category_overrides' );

	if ( svgEl ) svgEl.value = svg;
	if ( modeEl ) modeEl.value = mode;
	if ( layoutEl ) layoutEl.value = JSON.stringify( layout );
	if ( overridesEl ) overridesEl.value = JSON.stringify( overrides );
}

// ── Pricing Mount (separate root) ───────────────────────────────────
export function PricingApp( { initialTiers } ) {
	const [ tiers, setTiers ] = useState( initialTiers || [] );

	useEffect( () => {
		const el = document.getElementById( 'clisyc_venue_pricing_tiers' );
		if ( el ) el.value = JSON.stringify( tiers );
	}, [ tiers ] );

	return <PricingTiers tiers={ tiers } onTiersChange={ setTiers } />;
}
