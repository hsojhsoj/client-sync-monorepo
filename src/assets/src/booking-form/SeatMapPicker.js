/**
 * SeatMapPicker — Frontend seat selection component for the booking form.
 *
 * Conditionally rendered in BookingModal when a linked venue exists.
 * Fetches layout + availability, renders interactive SVG, manages holds.
 *
 * Flow:
 *  1. Sections present → show overview SVG or section cards (click to zoom)
 *  2. Only rows/seats → show seat selection directly
 *  3. Click seat → POST /seats/hold → update state
 *  4. Click held seat → DELETE /seats/hold → release
 */
import {
	useState,
	useEffect,
	useCallback,
	useRef,
	useMemo,
} from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import SeatHoldTimer from './SeatHoldTimer';
import './seat-selection.css';

// Inline utils to avoid cross-bundle import.
const SESSION_TOKEN_KEY = 'clisyc_seat_session_token';

function getSessionToken() {
	let token = sessionStorage.getItem( SESSION_TOKEN_KEY );
	if ( ! token ) {
		token = crypto.randomUUID();
		sessionStorage.setItem( SESSION_TOKEN_KEY, token );
	}
	return token;
}

const SEAT_COLORS = {
	available: { fill: '#d1fae5', stroke: '#065f46' },
	held: { fill: '#fef3c7', stroke: '#92400e' },
	mine: { fill: '#dbeafe', stroke: '#1d4ed8' },
	booked: { fill: '#fee2e2', stroke: '#991b1b' },
	disabled: { fill: '#e5e7eb', stroke: '#9ca3af' },
};

/** Category → color map for section headers and overview SVG. */
const SECTION_CATEGORY_COLORS = {
	vip: { fill: '#ede9fe', stroke: '#7c3aed' },
	premium: { fill: '#fef3c7', stroke: '#d97706' },
	standard: { fill: '#d1fae5', stroke: '#065f46' },
	general: { fill: '#d1fae5', stroke: '#065f46' },
};

function getSectionColors( category ) {
	return (
		SECTION_CATEGORY_COLORS[ category ] || {
			fill: '#e0f2fe',
			stroke: '#0284c7',
		}
	);
}

/**
 * Heatmap colour scale based on fill percentage.
 * Green → lime → amber → orange → red as section fills up.
 */
const HEATMAP_COLORS = [
	{
		max: 24,
		fill: '#d1fae5',
		stroke: '#059669',
		label: __( 'Available', 'client-sync-pro' ),
	},
	{
		max: 49,
		fill: '#d9f99d',
		stroke: '#65a30d',
		label: __( 'Filling Up', 'client-sync-pro' ),
	},
	{
		max: 74,
		fill: '#fef3c7',
		stroke: '#d97706',
		label: __( 'Almost Full', 'client-sync-pro' ),
	},
	{
		max: 99,
		fill: '#fed7aa',
		stroke: '#ea580c',
		label: __( 'Nearly Sold Out', 'client-sync-pro' ),
	},
	{
		max: 100,
		fill: '#fee2e2',
		stroke: '#dc2626',
		label: __( 'Sold Out', 'client-sync-pro' ),
	},
];

function getHeatmapColor( fillPercent ) {
	for ( const tier of HEATMAP_COLORS ) {
		if ( fillPercent <= tier.max ) {
			return { fill: tier.fill, stroke: tier.stroke };
		}
	}
	return { fill: '#fee2e2', stroke: '#dc2626' };
}

/**
 * Calculate the fill percentage for a section based on availability data.
 *
 * @param {Object} section Layout section with rows→seats.
 * @param {Object} avail   Availability: { booked: [], held: [] }.
 * @return {number} 0–100 fill percentage.
 */
function getSectionFillPercent( section, avail ) {
	const unavailable = new Set( [
		...( avail.booked || [] ),
		...( avail.held || [] ),
	] );
	let total = 0;
	let filled = 0;
	for ( const row of section.rows || [] ) {
		for ( const seat of row.seats || [] ) {
			total++;
			if ( unavailable.has( seat.id ) ) {
				filled++;
			}
		}
	}
	return total > 0 ? Math.round( ( filled / total ) * 100 ) : 0;
}

export default function SeatMapPicker( {
	venueId,
	slotId,
	selectedSeats,
	onSeatsChange,
	onHoldExpiry,
} ) {
	const [ layout, setLayout ] = useState( null );
	const [ svgString, setSvgString ] = useState( '' );
	const [ overviewSvg, setOverviewSvg ] = useState( '' );
	const [ availability, setAvailability ] = useState( {
		held: [],
		booked: [],
		mine: [],
	} );
	const [ availabilityLoaded, setAvailabilityLoaded ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ activeSection, setActiveSection ] = useState( null );
	const [ holdTtl, setHoldTtl ] = useState( 300 );
	const [ holdActive, setHoldActive ] = useState( false );
	const [ isExpanded, setIsExpanded ] = useState( false );
	const svgRef = useRef( null );
	const sessionToken = useMemo( () => getSessionToken(), [] );

	// Body scroll lock when expanded.
	useEffect( () => {
		if ( isExpanded ) {
			document.body.classList.add( 'clisyc-seat-map-lock' );
		} else {
			document.body.classList.remove( 'clisyc-seat-map-lock' );
		}
		return () => document.body.classList.remove( 'clisyc-seat-map-lock' );
	}, [ isExpanded ] );

	// Escape key closes expanded mode.
	useEffect( () => {
		if ( ! isExpanded ) {
			return;
		}
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				setIsExpanded( false );
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ isExpanded ] );

	const hasSections = useMemo( () => {
		if ( ! layout?.sections ) {
			return false;
		}
		return (
			layout.sections.length > 1 ||
			( layout.sections.length === 1 &&
				layout.sections[ 0 ].id !== '_default' )
		);
	}, [ layout ] );

	// Derive the active section data for the header.
	const activeSectionData = useMemo( () => {
		if ( ! layout?.sections || ! activeSection ) {
			return null;
		}
		return layout.sections.find( ( s ) => s.id === activeSection ) || null;
	}, [ layout, activeSection ] );

	// Build a flat lookup: seatId → { seatLabel, rowLabel, sectionLabel, category }.
	const seatLookup = useMemo( () => {
		const map = {};
		if ( ! layout?.sections ) {
			return map;
		}
		for ( const section of layout.sections ) {
			for ( const row of section.rows ) {
				for ( const seat of row.seats ) {
					map[ seat.id ] = {
						seatLabel: seat.label,
						rowLabel: row.label,
						sectionLabel: section.label,
						category: seat.category || section.category || '',
					};
				}
			}
		}
		return map;
	}, [ layout ] );

	// Fetch layout + availability.
	useEffect( () => {
		if ( ! venueId || ! slotId ) {
			return;
		}

		setLoading( true );
		setError( null );

		Promise.all( [
			apiFetch( { path: `/clisyc/v1/seats/layout/${ venueId }` } ),
			apiFetch( {
				path: `/clisyc/v1/seats/availability/${ venueId }/${ slotId }?session_token=${ encodeURIComponent(
					sessionToken
				) }`,
			} ),
		] )
			.then( ( [ layoutRes, availRes ] ) => {
				setLayout( layoutRes.layout );
				setSvgString( layoutRes.svg );
				setOverviewSvg( layoutRes.overview_svg || '' );
				setAvailability( availRes );
				setAvailabilityLoaded( true );
				setHoldTtl( layoutRes.hold_ttl || 300 );
				setLoading( false );

				// If we have existing holds from this session, mark them.
				if ( availRes.mine?.length > 0 ) {
					onSeatsChange( availRes.mine );
					setHoldActive( true );
				}
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__( 'Failed to load seat map.', 'client-sync-pro' )
				);
				setLoading( false );
			} );
	}, [ venueId, slotId, sessionToken ] );

	// Handle seat click.
	const handleSeatClick = useCallback(
		async ( seatId ) => {
			const isSelected = selectedSeats.includes( seatId );

			if ( isSelected ) {
				// Release hold.
				try {
					await apiFetch( {
						path: '/clisyc/v1/seats/hold',
						method: 'DELETE',
						data: {
							venue_id: venueId,
							slot_id: slotId,
							seat_id: seatId,
							session_token: sessionToken,
						},
					} );

					const newSeats = selectedSeats.filter(
						( s ) => s !== seatId
					);
					onSeatsChange( newSeats );

					if ( newSeats.length === 0 ) {
						setHoldActive( false );
					}

					// Update availability.
					setAvailability( ( prev ) => ( {
						...prev,
						mine: prev.mine.filter( ( s ) => s !== seatId ),
					} ) );
				} catch ( err ) {
					// Ignore release errors.
				}
			} else {
				// Check if seat is available.
				if (
					availability.booked.includes( seatId ) ||
					availability.held.includes( seatId )
				) {
					return; // Can't select.
				}

				// Hold seat.
				try {
					const result = await apiFetch( {
						path: '/clisyc/v1/seats/hold',
						method: 'POST',
						data: {
							venue_id: venueId,
							slot_id: slotId,
							seat_id: seatId,
							session_token: sessionToken,
						},
					} );

					if ( result.held ) {
						const newSeats = [ ...selectedSeats, seatId ];
						onSeatsChange( newSeats );
						setHoldTtl( result.expires_in );
						setHoldActive( true );

						setAvailability( ( prev ) => ( {
							...prev,
							mine: [ ...prev.mine, seatId ],
						} ) );
					}
				} catch ( err ) {
					// Seat likely taken — refresh availability.
					try {
						const availRes = await apiFetch( {
							path: `/clisyc/v1/seats/availability/${ venueId }/${ slotId }?session_token=${ encodeURIComponent(
								sessionToken
							) }`,
						} );
						setAvailability( availRes );
					} catch ( e ) {
						// Ignore.
					}
				}
			}
		},
		[
			venueId,
			slotId,
			sessionToken,
			selectedSeats,
			availability,
			onSeatsChange,
		]
	);

	// Render SVG with seat coloring + section dimming.
	useEffect( () => {
		if ( ! svgRef.current || ! svgString || ! layout ) {
			return;
		}

		svgRef.current.innerHTML = '';
		const parser = new DOMParser();
		const doc = parser.parseFromString( svgString, 'image/svg+xml' );
		const svgEl = doc.querySelector( 'svg' );
		if ( ! svgEl ) {
			return;
		}

		svgEl.setAttribute( 'width', '100%' );
		svgEl.setAttribute( 'height', 'auto' );
		svgEl.style.maxHeight = isExpanded ? '75vh' : '50vh';

		// Color seats and attach click handlers.
		for ( const section of layout.sections ) {
			// Dim non-active sections when a section is selected.
			if (
				hasSections &&
				activeSection &&
				section.id !== activeSection
			) {
				for ( const row of section.rows ) {
					for ( const seat of row.seats ) {
						const el = svgEl.querySelector(
							`#${ CSS.escape( seat.svg_element_id ) }`
						);
						if ( ! el ) {
							continue;
						}
						el.style.opacity = '0.15';
						el.style.pointerEvents = 'none';
					}
				}
				continue;
			}

			for ( const row of section.rows ) {
				for ( const seat of row.seats ) {
					const el = svgEl.querySelector(
						`#${ CSS.escape( seat.svg_element_id ) }`
					);
					if ( ! el ) {
						continue;
					}

					const status = getSeatVisualStatus( seat.id );
					const colors =
						SEAT_COLORS[ status ] || SEAT_COLORS.available;

					el.style.fill = colors.fill;
					el.style.stroke = colors.stroke;
					el.style.strokeWidth = '1.5';
					el.style.cursor =
						status === 'booked' || status === 'held'
							? 'not-allowed'
							: 'pointer';
					el.setAttribute( 'data-seat-id', seat.id );
					el.setAttribute( 'data-seat-status', status );

					// Add tooltip.
					const existingTitle = el.querySelector( 'title' );
					if ( existingTitle ) {
						existingTitle.remove();
					}
					const title = document.createElementNS(
						'http://www.w3.org/2000/svg',
						'title'
					);
					title.textContent =
						seat.label +
						( seat.category ? ` (${ seat.category })` : '' );
					el.appendChild( title );
				}
			}
		}

		svgRef.current.appendChild( svgEl );

		// Attach click handler via event delegation.
		const clickHandler = ( e ) => {
			const seatEl = e.target.closest( '[data-seat-id]' );
			if ( seatEl ) {
				const seatId = seatEl.getAttribute( 'data-seat-id' );
				const status = seatEl.getAttribute( 'data-seat-status' );
				if (
					status !== 'booked' &&
					status !== 'held' &&
					status !== 'disabled'
				) {
					handleSeatClick( seatId );
				}
			}
		};

		svgRef.current.addEventListener( 'click', clickHandler );
		return () => {
			if ( svgRef.current ) {
				svgRef.current.removeEventListener( 'click', clickHandler );
			}
		};
	}, [
		svgString,
		layout,
		availability,
		selectedSeats,
		activeSection,
		hasSections,
		handleSeatClick,
		isExpanded,
	] );

	function getSeatVisualStatus( seatId ) {
		if ( availability.booked.includes( seatId ) ) {
			return 'booked';
		}
		if (
			selectedSeats.includes( seatId ) ||
			availability.mine?.includes( seatId )
		) {
			return 'mine';
		}
		if ( availability.held.includes( seatId ) ) {
			return 'held';
		}
		return 'available';
	}

	const handleExpired = useCallback( () => {
		onSeatsChange( [] );
		setHoldActive( false );
		if ( onHoldExpiry ) {
			onHoldExpiry();
		}
	}, [ onSeatsChange, onHoldExpiry ] );

	if ( loading ) {
		return (
			<div style={ { textAlign: 'center', padding: '24px' } }>
				<Spinner />
				<p>{ __( 'Loading seat map…', 'client-sync-pro' ) }</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div
				style={ {
					padding: '16px',
					background: '#fee2e2',
					borderRadius: '6px',
					color: '#991b1b',
				} }
			>
				{ error }
			</div>
		);
	}

	return (
		<div
			className={ `clisyc-seat-map${
				isExpanded ? ' clisyc-seat-map--expanded' : ''
			}` }
		>
			{ /* Toolbar — title + expand/collapse toggle */ }
			<div className="clisyc-seat-map__toolbar">
				<span className="clisyc-seat-map__toolbar-label">
					{ __( 'Select Your Seats', 'client-sync-pro' ) }
				</span>
				<button
					type="button"
					className="clisyc-seat-map__expand-btn"
					onClick={ () => setIsExpanded( ( prev ) => ! prev ) }
				>
					{ isExpanded ? (
						<>
							<svg
								xmlns="http://www.w3.org/2000/svg"
								viewBox="0 0 20 20"
								fill="currentColor"
							>
								<path
									fillRule="evenodd"
									d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
									clipRule="evenodd"
								/>
							</svg>
							{ __( 'Close', 'client-sync-pro' ) }
						</>
					) : (
						<>
							<svg
								xmlns="http://www.w3.org/2000/svg"
								viewBox="0 0 20 20"
								fill="currentColor"
							>
								<path d="M3 4a1 1 0 011-1h4a1 1 0 010 2H6.414l2.293 2.293a1 1 0 01-1.414 1.414L5 6.414V8a1 1 0 01-2 0V4zm9 1a1 1 0 110-2h4a1 1 0 011 1v4a1 1 0 11-2 0V6.414l-2.293 2.293a1 1 0 11-1.414-1.414L13.586 5H12zm-9 7a1 1 0 112 0v1.586l2.293-2.293a1 1 0 011.414 1.414L6.414 15H8a1 1 0 110 2H4a1 1 0 01-1-1v-4zm13 3a1 1 0 01-1 1h-4a1 1 0 110-2h1.586l-2.293-2.293a1 1 0 011.414-1.414L15 13.586V12a1 1 0 112 0v4z" />
							</svg>
							{ __( 'Expand', 'client-sync-pro' ) }
						</>
					) }
				</button>
			</div>

			{ /* Section overview — overview SVG or text card fallback */ }
			{ hasSections && ! activeSection && (
				<div>
					<p style={ { marginBottom: '8px' } }>
						<strong>
							{ __( 'Select a section:', 'client-sync-pro' ) }
						</strong>
					</p>
					{ overviewSvg ? (
						<OverviewSvgSelector
							svgString={ overviewSvg }
							sections={ layout.sections }
							availability={ availability }
							availabilityLoaded={ availabilityLoaded }
							onSectionSelect={ setActiveSection }
						/>
					) : (
						<div className="clisyc-section-overview">
							{ layout.sections.map( ( section ) => (
								<div
									key={ section.id }
									className="clisyc-section-card"
									onClick={ () =>
										setActiveSection( section.id )
									}
									role="button"
									tabIndex={ 0 }
									onKeyDown={ ( e ) =>
										e.key === 'Enter' &&
										setActiveSection( section.id )
									}
								>
									<div className="clisyc-section-card__name">
										{ section.label }
									</div>
									<div className="clisyc-section-card__info">
										{ section.rows.reduce(
											( s, r ) => s + r.seats.length,
											0
										) }{ ' ' }
										{ __( 'seats', 'client-sync-pro' ) }
										{ section.category &&
											` \u00B7 ${ section.category }` }
									</div>
								</div>
							) ) }
						</div>
					) }
				</div>
			) }

			{ /* Section header when viewing a section */ }
			{ hasSections && activeSection && activeSectionData && (
				<div className="clisyc-section-header">
					<Button
						variant="link"
						onClick={ () => setActiveSection( null ) }
					>
						{ __( '\u2190 Back to sections', 'client-sync-pro' ) }
					</Button>
					<div className="clisyc-section-header__title">
						<span className="clisyc-section-header__name">
							{ activeSectionData.label }
						</span>
						{ activeSectionData.category && (
							<span
								className={ `clisyc-section-header__category clisyc-section-header__category--${ activeSectionData.category }` }
							>
								{ activeSectionData.category }
							</span>
						) }
					</div>
				</div>
			) }

			{ /* SVG seat map */ }
			{ ( ! hasSections || activeSection ) && (
				<div className="clisyc-seat-map__svg-wrap" ref={ svgRef } />
			) }

			{ /* Legend — only show when viewing seats */ }
			{ ( ! hasSections || activeSection ) && (
				<div className="clisyc-seat-legend">
					<div className="clisyc-seat-legend__item">
						<span
							className="clisyc-seat-legend__swatch"
							style={ {
								background: SEAT_COLORS.available.fill,
								borderColor: SEAT_COLORS.available.stroke,
							} }
						/>
						{ __( 'Available', 'client-sync-pro' ) }
					</div>
					<div className="clisyc-seat-legend__item">
						<span
							className="clisyc-seat-legend__swatch"
							style={ {
								background: SEAT_COLORS.mine.fill,
								borderColor: SEAT_COLORS.mine.stroke,
							} }
						/>
						{ __( 'Selected', 'client-sync-pro' ) }
					</div>
					<div className="clisyc-seat-legend__item">
						<span
							className="clisyc-seat-legend__swatch"
							style={ {
								background: SEAT_COLORS.held.fill,
								borderColor: SEAT_COLORS.held.stroke,
							} }
						/>
						{ __( 'Held', 'client-sync-pro' ) }
					</div>
					<div className="clisyc-seat-legend__item">
						<span
							className="clisyc-seat-legend__swatch"
							style={ {
								background: SEAT_COLORS.booked.fill,
								borderColor: SEAT_COLORS.booked.stroke,
							} }
						/>
						{ __( 'Booked', 'client-sync-pro' ) }
					</div>
				</div>
			) }

			{ /* Selection info + seat details + hold timer */ }
			{ selectedSeats.length > 0 && ! isExpanded && (
				<div className="clisyc-seat-selection-info">
					<span className="clisyc-seat-selection-info__count">
						{ selectedSeats.length }{ ' ' }
						{ selectedSeats.length === 1
							? __( 'seat selected', 'client-sync-pro' )
							: __( 'seats selected', 'client-sync-pro' ) }
					</span>
					{ holdActive && (
						<SeatHoldTimer
							venueId={ venueId }
							slotId={ slotId }
							sessionToken={ sessionToken }
							expiresIn={ holdTtl }
							onExpired={ handleExpired }
							onRefreshed={ ( newTtl ) => setHoldTtl( newTtl ) }
						/>
					) }
					<SelectedSeatsList
						seats={ selectedSeats }
						lookup={ seatLookup }
						hasSections={ hasSections }
					/>
				</div>
			) }

			{ /* Done bar — shown in expanded mode */ }
			{ isExpanded && (
				<div className="clisyc-seat-map__done-bar">
					<div className="clisyc-seat-map__done-info">
						<span className="clisyc-seat-map__done-count">
							{ selectedSeats.length > 0 ? (
								<>
									<strong>{ selectedSeats.length }</strong>{ ' ' }
									{ selectedSeats.length === 1
										? __(
												'seat selected',
												'client-sync-pro'
										  )
										: __(
												'seats selected',
												'client-sync-pro'
										  ) }
								</>
							) : (
								__( 'No seats selected yet', 'client-sync-pro' )
							) }
							{ holdActive && (
								<>
									{ ' \u00B7 ' }
									<SeatHoldTimer
										venueId={ venueId }
										slotId={ slotId }
										sessionToken={ sessionToken }
										expiresIn={ holdTtl }
										onExpired={ handleExpired }
										onRefreshed={ ( newTtl ) =>
											setHoldTtl( newTtl )
										}
									/>
								</>
							) }
						</span>
						{ selectedSeats.length > 0 && (
							<SelectedSeatsList
								seats={ selectedSeats }
								lookup={ seatLookup }
								hasSections={ hasSections }
							/>
						) }
					</div>
					<button
						type="button"
						className="clisyc-seat-map__done-btn"
						onClick={ () => setIsExpanded( false ) }
					>
						{ __( 'Done', 'client-sync-pro' ) }
					</button>
				</div>
			) }
		</div>
	);
}

/**
 * OverviewSvgSelector — renders the venue overview SVG with clickable section areas.
 *
 * Matches section IDs from the layout to elements in the overview SVG,
 * colors them by category (fallback) or heatmap (when availability loaded),
 * and fires onSectionSelect when clicked.
 * @param root0
 * @param root0.svgString
 * @param root0.sections
 * @param root0.availability
 * @param root0.availabilityLoaded
 * @param root0.onSectionSelect
 */
function OverviewSvgSelector( {
	svgString,
	sections,
	availability,
	availabilityLoaded,
	onSectionSelect,
} ) {
	const containerRef = useRef( null );
	const svgInsertedRef = useRef( false );

	// Effect 1: Insert SVG into the DOM once. Starts sections in neutral grey.
	useEffect( () => {
		if ( ! containerRef.current || ! svgString ) {
			return;
		}

		containerRef.current.innerHTML = '';
		svgInsertedRef.current = false;

		const parser = new DOMParser();
		const doc = parser.parseFromString( svgString, 'image/svg+xml' );
		const svgEl = doc.querySelector( 'svg' );
		if ( ! svgEl ) {
			return;
		}

		svgEl.setAttribute( 'width', '100%' );
		svgEl.setAttribute( 'height', 'auto' );
		svgEl.style.maxHeight = '400px';

		// Set all section elements to neutral grey initially with transition for heatmap animation.
		for ( const section of sections ) {
			if ( section.id === '_default' ) {
				continue;
			}
			const el = svgEl.querySelector( `#${ CSS.escape( section.id ) }` );
			if ( ! el ) {
				continue;
			}

			el.style.fill = '#e5e7eb';
			el.style.stroke = '#9ca3af';
			el.style.strokeWidth = '2';
			el.style.cursor = 'pointer';
			el.style.transition =
				'fill 0.8s ease, stroke 0.8s ease, opacity 0.15s ease, filter 0.15s ease';
			el.setAttribute( 'data-section-id', section.id );
		}

		containerRef.current.appendChild( svgEl );
		svgInsertedRef.current = true;

		// Click handler via event delegation.
		const clickHandler = ( e ) => {
			const sectionEl = e.target.closest( '[data-section-id]' );
			if ( sectionEl ) {
				onSectionSelect( sectionEl.getAttribute( 'data-section-id' ) );
			}
		};

		containerRef.current.addEventListener( 'click', clickHandler );
		return () => {
			if ( containerRef.current ) {
				containerRef.current.removeEventListener(
					'click',
					clickHandler
				);
			}
		};
	}, [ svgString, sections, onSectionSelect ] );

	// Effect 2: Update section colours when availability loads (animates from grey → heatmap).
	useEffect( () => {
		if ( ! svgInsertedRef.current || ! containerRef.current ) {
			return;
		}

		const svgEl = containerRef.current.querySelector( 'svg' );
		if ( ! svgEl ) {
			return;
		}

		// Use requestAnimationFrame to ensure the initial grey paint has rendered
		// before we transition to the final heatmap colours.
		requestAnimationFrame( () => {
			for ( const section of sections ) {
				if ( section.id === '_default' ) {
					continue;
				}
				const el = svgEl.querySelector(
					`#${ CSS.escape( section.id ) }`
				);
				if ( ! el ) {
					continue;
				}

				let colors;
				let fillPercent = null;
				if ( availabilityLoaded ) {
					fillPercent = getSectionFillPercent(
						section,
						availability
					);
					colors = getHeatmapColor( fillPercent );
				} else {
					colors = { fill: '#e5e7eb', stroke: '#9ca3af' }; // Stay grey.
				}

				el.style.fill = colors.fill;
				el.style.stroke = colors.stroke;

				// Update tooltip with fill percentage.
				const existingTitle = el.querySelector( 'title' );
				if ( existingTitle ) {
					existingTitle.remove();
				}

				const seatCount = section.rows.reduce(
					( sum, r ) => sum + r.seats.length,
					0
				);
				const title = document.createElementNS(
					'http://www.w3.org/2000/svg',
					'title'
				);
				const fillInfo =
					fillPercent !== null
						? ` \u2014 ${ fillPercent }% filled`
						: '';
				title.textContent = `${ section.label } \u2014 ${ seatCount } seats${ fillInfo }`;
				el.appendChild( title );
			}
		} );
	}, [ sections, availability, availabilityLoaded ] );

	return (
		<>
			<div className="clisyc-overview-svg" ref={ containerRef } />
			{ availabilityLoaded && (
				<div className="clisyc-heatmap-legend">
					{ HEATMAP_COLORS.filter(
						( _, i ) =>
							i % 2 === 0 || i === HEATMAP_COLORS.length - 1
					).map( ( tier ) => (
						<span
							key={ tier.label }
							className="clisyc-heatmap-legend__item"
						>
							<span
								className="clisyc-heatmap-legend__swatch"
								style={ {
									background: tier.fill,
									borderColor: tier.stroke,
								} }
							/>
							{ tier.label }
						</span>
					) ) }
				</div>
			) }
		</>
	);
}

/**
 * SelectedSeatsList — renders a compact list of selected seats with details.
 *
 * Shows seat number, row, and section for each selected seat.
 * @param root0
 * @param root0.seats
 * @param root0.lookup
 * @param root0.hasSections
 */
function SelectedSeatsList( { seats, lookup, hasSections } ) {
	if ( ! seats.length ) {
		return null;
	}

	return (
		<div className="clisyc-selected-seats-list">
			{ seats.map( ( seatId ) => {
				const info = lookup[ seatId ];
				if ( ! info ) {
					return null;
				}

				return (
					<span key={ seatId } className="clisyc-selected-seat-tag">
						{ hasSections && info.sectionLabel ? (
							<>
								{ info.sectionLabel } &middot; { info.rowLabel }{ ' ' }
								&middot; { info.seatLabel }
							</>
						) : (
							<>
								{ info.rowLabel } &middot; { info.seatLabel }
							</>
						) }
					</span>
				);
			} ) }
		</div>
	);
}
