/**
 * File: src/assets/src/timeline-viewer/TimelineApp.js
 * Renders the Timeline using FullCalendar Scheduler.
 *
 * ADDED: Draggable resize handle for height adjustment
 * ADDED: Zoom controls (slot duration + slot width) like video editing software
 * ADDED: Keyboard shortcuts (Ctrl+scroll to zoom)
 * FIXED: Improved tooltips with dimension details
 * FIXED: Expanded view now shows dimension color dots and names
 */
import React, {
	useRef,
	useState,
	useCallback,
	useMemo,
	useEffect,
} from 'react';
import FullCalendar from '@fullcalendar/react';
import resourceTimelinePlugin from '@fullcalendar/resource-timeline';
import interactionPlugin from '@fullcalendar/interaction';
import apiFetch from '@wordpress/api-fetch';
import TimelineLegend from './TimelineLegend';
import { SkeletonTimeline } from '../shared/SkeletonLoader';
import '../shared/skeleton-loader.css';

/**
 * Zoom level presets - each level defines slot duration and minimum width
 * Lower index = more zoomed out, Higher index = more zoomed in
 */
const ZOOM_LEVELS = [
	{
		label: '6h',
		slotDuration: '06:00:00',
		slotMinWidth: 80,
		labelInterval: '12:00:00',
	},
	{
		label: '3h',
		slotDuration: '03:00:00',
		slotMinWidth: 60,
		labelInterval: '06:00:00',
	},
	{
		label: '1h',
		slotDuration: '01:00:00',
		slotMinWidth: 50,
		labelInterval: '02:00:00',
	},
	{
		label: '30m',
		slotDuration: '00:30:00',
		slotMinWidth: 45,
		labelInterval: '01:00:00',
	},
	{
		label: '15m',
		slotDuration: '00:15:00',
		slotMinWidth: 40,
		labelInterval: '00:30:00',
	},
	{
		label: '10m',
		slotDuration: '00:10:00',
		slotMinWidth: 35,
		labelInterval: '00:30:00',
	},
	{
		label: '5m',
		slotDuration: '00:05:00',
		slotMinWidth: 30,
		labelInterval: '00:15:00',
	},
];

const DEFAULT_ZOOM_INDEX = 2; // 1 hour slots by default

/**
 * Format dimension type slug to readable label
 * e.g., "clisyc_service" -> "Service", "clisyc_practitioner" -> "Practitioner"
 * @param slug
 */
const formatDimensionLabel = ( slug ) => {
	if ( ! slug ) {
		return '';
	}
	return slug
		.replace( 'clisyc_', '' )
		.replace( /_/g, ' ' )
		.replace( /\b\w/g, ( c ) => c.toUpperCase() );
};

const TimelineApp = ( { containerId } ) => {
	const calendarRef = useRef( null );
	const wrapperRef = useRef( null );
	const configKey = `clisycTimelineData_${ containerId.replace(
		/-/g,
		'_'
	) }`;
	const config = window[ configKey ] || {};

	const {
		restUrl,
		restNonce,
		resources = [],
		colorSettings = {},
		l10n = {},
		height = 700,
		viewMode = 'week',
		startOfWeek = 0,
		showLegend = true,
		adminUrl = '',
	} = config;

	// State for zoom level
	const [ zoomIndex, setZoomIndex ] = useState( DEFAULT_ZOOM_INDEX );

	// State for resizable height
	const [ containerHeight, setContainerHeight ] = useState(
		parseInt( height ) || 700
	);
	const [ isResizing, setIsResizing ] = useState( false );

	// State for compact view (hides overflow text)
	const [ isCompactView, setIsCompactView ] = useState( true ); // Default to compact

	// State for initial loading
	const [ isInitialLoading, setIsInitialLoading ] = useState( true );

	const [ dimensionTypes, setDimensionTypes ] = useState( [] );

	const currentZoom = ZOOM_LEVELS[ zoomIndex ];

	const initialViewMap = {
		day: 'resourceTimelineDay',
		week: 'resourceTimelineWeek',
		month: 'resourceTimelineMonth',
	};

	// Build a color lookup map from resources
	const resourceColorMap = useMemo( () => {
		const map = {};
		resources.forEach( ( r ) => {
			map[ r.id ] = r.color || '#3b82f6';
		} );
		return map;
	}, [ resources ] );

	// Persist height to localStorage
	useEffect( () => {
		try {
			const savedHeight = localStorage.getItem(
				'clisyc_timeline_height'
			);
			if ( savedHeight ) {
				setContainerHeight(
					Math.max( 300, Math.min( 2000, parseInt( savedHeight ) ) )
				);
			}
		} catch ( e ) {
			/* localStorage unavailable */
		}
	}, [] );

	useEffect( () => {
		try {
			localStorage.setItem(
				'clisyc_timeline_height',
				containerHeight.toString()
			);
		} catch ( e ) {
			/* localStorage unavailable or full */
		}
	}, [ containerHeight ] );

	// Persist zoom level to localStorage
	useEffect( () => {
		const savedZoom = localStorage.getItem( 'clisyc_timeline_zoom' );
		if ( savedZoom !== null ) {
			const idx = parseInt( savedZoom );
			if ( idx >= 0 && idx < ZOOM_LEVELS.length ) {
				setZoomIndex( idx );
			}
		}
	}, [] );

	useEffect( () => {
		localStorage.setItem( 'clisyc_timeline_zoom', zoomIndex.toString() );
	}, [ zoomIndex ] );

	// Persist compact view preference to localStorage
	useEffect( () => {
		const savedCompact = localStorage.getItem( 'clisyc_timeline_compact' );
		if ( savedCompact !== null ) {
			setIsCompactView( savedCompact === 'true' );
		}
	}, [] );

	useEffect( () => {
		localStorage.setItem(
			'clisyc_timeline_compact',
			isCompactView.toString()
		);
	}, [ isCompactView ] );

	/**
	 * Toggle compact view
	 */
	const handleToggleCompact = useCallback( () => {
		setIsCompactView( ( prev ) => ! prev );
	}, [] );

	// Memoize fcResources to prevent recreation on every render
	const fcResources = useMemo( () => {
		return resources.map( ( resource ) => ( {
			id: String( resource.id ),
			title: resource.name,
			eventColor: resource.color || '#3b82f6',
			extendedProps: {
				cptColor: resource.color || '#3b82f6',
				cptType: resource.type,
				cptTypeLabel: resource.type
					? resource.type
							.replace( 'clisyc_', '' )
							.replace( /_/g, ' ' )
					: '',
				editUrl: `${ adminUrl }post.php?post=${ resource.id }&action=edit`,
			},
		} ) );
	}, [ resources, adminUrl ] );

	// Memoize resource IDs string
	const resourceIdsString = useMemo( () => {
		return resources.map( ( r ) => r.id ).join( ',' );
	}, [ resources ] );

	/**
	 * Zoom In - increase detail (smaller time slots)
	 */
	const handleZoomIn = useCallback( () => {
		setZoomIndex( ( prev ) =>
			Math.min( ZOOM_LEVELS.length - 1, prev + 1 )
		);
	}, [] );

	/**
	 * Zoom Out - decrease detail (larger time slots)
	 */
	const handleZoomOut = useCallback( () => {
		setZoomIndex( ( prev ) => Math.max( 0, prev - 1 ) );
	}, [] );

	/**
	 * Reset zoom to default
	 */
	const handleZoomReset = useCallback( () => {
		setZoomIndex( DEFAULT_ZOOM_INDEX );
	}, [] );

	/**
	 * Handle Ctrl+Scroll for zoom
	 */
	useEffect( () => {
		const wrapper = wrapperRef.current;
		if ( ! wrapper ) {
			return;
		}

		const handleWheel = ( e ) => {
			if ( e.ctrlKey || e.metaKey ) {
				e.preventDefault();
				if ( e.deltaY < 0 ) {
					handleZoomIn();
				} else {
					handleZoomOut();
				}
			}
		};

		wrapper.addEventListener( 'wheel', handleWheel, { passive: false } );
		return () => wrapper.removeEventListener( 'wheel', handleWheel );
	}, [ handleZoomIn, handleZoomOut ] );

	/**
	 * Resize handle - drag to adjust height
	 */
	const handleResizeStart = useCallback(
		( e ) => {
			e.preventDefault();
			setIsResizing( true );

			const startY = e.clientY || e.touches?.[ 0 ]?.clientY;
			const startHeight = containerHeight;

			const handleMove = ( moveEvent ) => {
				const currentY =
					moveEvent.clientY || moveEvent.touches?.[ 0 ]?.clientY;
				const delta = currentY - startY;
				const newHeight = Math.max(
					300,
					Math.min( 2000, startHeight + delta )
				);
				setContainerHeight( newHeight );
			};

			const handleEnd = () => {
				setIsResizing( false );
				document.removeEventListener( 'mousemove', handleMove );
				document.removeEventListener( 'mouseup', handleEnd );
				document.removeEventListener( 'touchmove', handleMove );
				document.removeEventListener( 'touchend', handleEnd );
			};

			document.addEventListener( 'mousemove', handleMove );
			document.addEventListener( 'mouseup', handleEnd );
			document.addEventListener( 'touchmove', handleMove );
			document.addEventListener( 'touchend', handleEnd );
		},
		[ containerHeight ]
	);

	/**
	 * Custom Renderer for Resource Labels - clickable to edit
	 */
	const renderResourceLabel = useCallback(
		( arg ) => {
			const resource = arg.resource;
			const color = resource.extendedProps.cptColor || '#3b82f6';
			const cptTypeLabel = resource.extendedProps.cptTypeLabel || '';
			const editUrl = resource.extendedProps.editUrl;

			const handleClick = ( e ) => {
				e.preventDefault();
				if ( editUrl ) {
					window.open( editUrl, '_blank' );
				}
			};

			return (
				<div
					className="clisyc-timeline-resource-label clisyc-timeline-resource-clickable"
					onClick={ handleClick }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' || e.key === ' ' ) {
							e.preventDefault();
							handleClick();
						}
					} }
					role="button"
					tabIndex={ 0 }
					title={
						l10n.clickToEdit || 'Click to edit resource settings'
					}
					style={ { cursor: 'pointer' } }
				>
					<span
						className="clisyc-timeline-resource-dot"
						style={ { backgroundColor: color } }
					/>
					<span className="clisyc-timeline-resource-name">
						{ resource.title }
					</span>
					{ cptTypeLabel && (
						<span className="clisyc-timeline-resource-type-badge">
							{ cptTypeLabel }
						</span>
					) }
					<span className="dashicons dashicons-external clisyc-timeline-resource-edit-icon"></span>
				</div>
			);
		},
		[ l10n.clickToEdit ]
	);

	/**
	 * Fetch events from the REST API
	 */
	const fetchEventsSource = useCallback(
		async ( fetchInfo, successCallback, failureCallback ) => {
			try {
				const params = new URLSearchParams( {
					start: fetchInfo.start.toISOString(),
					end: fetchInfo.end.toISOString(),
					resources: resourceIdsString,
					_wpnonce: restNonce,
				} );

				const apiPath = `/${ restUrl }/timeline-appointments?${ params.toString() }`;

				const response = await apiFetch( {
					path: apiPath,
				} );

				const appointments = response.appointments || [];

				if (
					response.dimension_types &&
					response.dimension_types.length > 0
				) {
					setDimensionTypes( response.dimension_types );
				}

				// Map appointments to FullCalendar events
				// Use consistent colors from colorSettings (matching the Timeline Key)
				const events = appointments.map( ( appt ) => {
					let backgroundColor;
					let borderColor;
					let textColor;
					const classNames = [];

					if ( appt.is_blocked ) {
						// BLOCKED = Red (from settings or default)
						backgroundColor = colorSettings.blocked_bg || '#ef4444';
						borderColor = colorSettings.blocked_bg || '#ef4444';
						textColor = colorSettings.blocked_text || '#ffffff';
						classNames.push( 'clisyc-timeline-blocked' );
					} else if ( appt.is_booked ) {
						// BOOKED = Light yellow for visibility - consistent with Timeline Key
						backgroundColor = '#fffbc0'; // rgb(255, 251, 192)
						borderColor = '#e5d85c';
						textColor = '#5c4d0b'; // Dark text for readability on yellow
						classNames.push( 'clisyc-timeline-booked' );
					} else if ( appt.is_availability ) {
						// AVAILABLE/BOOKABLE SLOT = Green (from settings or default)
						backgroundColor =
							colorSettings.available_bg || '#10b981';
						borderColor = colorSettings.available_bg || '#10b981';
						textColor = colorSettings.available_text || '#ffffff';
						classNames.push( 'clisyc-timeline-slot' );
					} else if ( appt.is_schedule ) {
						// SCHEDULE (Open Hours) = Very light blue background
						backgroundColor = '#e0f2fe';
						borderColor = '#7dd3fc';
						textColor = '#0c4a6e';
						classNames.push( 'clisyc-timeline-schedule' );
					} else if ( appt.is_always_available ) {
						// ALWAYS AVAILABLE = Light blue background (slightly more saturated)
						backgroundColor = '#bfdbfe';
						borderColor = '#60a5fa';
						textColor = '#1e40af';
						classNames.push( 'clisyc-timeline-always-available' );
					} else {
						// Fallback (unknown type)
						backgroundColor = '#94a3b8';
						borderColor = '#64748b';
						textColor = '#ffffff';
					}

					return {
						id: appt.id,
						resourceId: String( appt.resource_id ),
						title: appt.title,
						start: appt.start_time,
						end: appt.end_time,
						backgroundColor,
						borderColor,
						textColor,
						classNames,
						extendedProps: {
							...appt,
						},
					};
				} );

				successCallback( events );
				setIsInitialLoading( false );
			} catch ( error ) {
				console.error( '[Timeline] Error fetching events:', error );
				failureCallback( error );
				setIsInitialLoading( false );
			}
		},
		[ resourceIdsString, restNonce, restUrl, colorSettings ]
	);

	/**
	 * Handle event clicks
	 */
	const handleEventClick = useCallback(
		( info ) => {
			const props = info.event.extendedProps;

			if ( props.is_booked && props.edit_link ) {
				window.open( props.edit_link, '_blank' );
			} else if ( props.resource_id && adminUrl ) {
				window.open(
					`${ adminUrl }post.php?post=${ props.resource_id }&action=edit`,
					'_blank'
				);
			}
		},
		[ adminUrl ]
	);

	/**
	 * Get color for a dimension ID from the resources list
	 */
	const getDimensionColor = useCallback(
		( dimId ) => {
			const id = parseInt( dimId );
			return resourceColorMap[ id ] || '#6b7280';
		},
		[ resourceColorMap ]
	);

	/**
	 * Custom event content renderer - adds tooltip with full details
	 * IMPROVED: Shows dimension details in tooltip and expanded view
	 */
	const renderEventContent = useCallback(
		( eventInfo ) => {
			const { event } = eventInfo;
			const props = event.extendedProps;
			const dimensions = props.dimensions || {};

			// Build tooltip text with comprehensive event details
			const tooltipLines = [];

			// Title/Status
			if ( props.is_booked ) {
				tooltipLines.push( `📅 ${ event.title || 'Booked' }` );
			} else if ( props.is_availability ) {
				tooltipLines.push( '✅ Available Slot' );
			} else if ( props.is_schedule ) {
				tooltipLines.push( '🕐 Open Hours' );
			} else if ( props.is_blocked ) {
				tooltipLines.push( '🚫 Blocked' );
			} else if ( props.is_always_available ) {
				tooltipLines.push( '🔓 Always Available' );
			} else {
				tooltipLines.push( event.title );
			}

			// Time range
			if ( props.start_time && props.end_time ) {
				const startDate = new Date( props.start_time );
				const endDate = new Date( props.end_time );
				const dateStr = startDate.toLocaleDateString( [], {
					weekday: 'short',
					month: 'short',
					day: 'numeric',
				} );
				const timeStr = `${ startDate.toLocaleTimeString( [], {
					hour: 'numeric',
					minute: '2-digit',
				} ) } - ${ endDate.toLocaleTimeString( [], {
					hour: 'numeric',
					minute: '2-digit',
				} ) }`;
				tooltipLines.push( `${ dateStr }` );
				tooltipLines.push( `${ timeStr }` );
			}

			// Duration
			if ( props.duration ) {
				const hours = Math.floor( props.duration / 60 );
				const mins = props.duration % 60;
				let durationStr = '';
				if ( hours > 0 ) {
					durationStr += `${ hours }h `;
				}
				if ( mins > 0 ) {
					durationStr += `${ mins }m`;
				}
				if ( durationStr ) {
					tooltipLines.push( `Duration: ${ durationStr.trim() }` );
				}
			}

			// Dimensions (Service, Practitioner, Room, etc.)
			if ( Object.keys( dimensions ).length > 0 ) {
				tooltipLines.push( '---' );
				Object.entries( dimensions ).forEach(
					( [ dimType, dimName ] ) => {
						const label = formatDimensionLabel( dimType );
						tooltipLines.push( `${ label }: ${ dimName }` );
					}
				);
			}

			// Client name for booked appointments
			if ( props.is_booked && props.client_name ) {
				tooltipLines.push( '---' );
				tooltipLines.push( `Client: ${ props.client_name }` );
			}

			// Capacity info if available
			if ( props.capacity && props.capacity > 1 ) {
				tooltipLines.push(
					`Capacity: ${ props.booked_count || 0 }/${ props.capacity }`
				);
			}

			const tooltipText = tooltipLines.join( '\n' );

			// Render content based on compact/expanded mode
			// In compact mode: just show title
			// In expanded mode: show dimension dots and details

			if ( isCompactView ) {
				// Compact view - simple title
				return (
					<div
						className="clisyc-timeline-event-content"
						title={ tooltipText }
					>
						<span className="fc-event-title">{ event.title }</span>
					</div>
				);
			}

			// Expanded view - show dimension details with color dots
			const hasDimensions = Object.keys( dimensions ).length > 0;

			return (
				<div
					className="clisyc-timeline-event-content clisyc-timeline-event-expanded"
					title={ tooltipText }
				>
					<div className="clisyc-timeline-event-header">
						<span className="fc-event-title">{ event.title }</span>
						{ props.time_label && (
							<span className="clisyc-timeline-event-time">
								{ props.time_label }
							</span>
						) }
					</div>
					{ hasDimensions && (
						<div className="clisyc-timeline-event-dimensions">
							{ Object.entries( dimensions ).map(
								( [ dimType, dimName ] ) => {
									// Find the dimension ID from the raw data to get its color
									// The dimensions object has type -> name mapping
									// We need to find the actual resource to get its color
									const dimResource = resources.find(
										( r ) =>
											r.type === dimType &&
											r.name === dimName
									);
									const dimColor =
										dimResource?.color ||
										getDimensionColor( dimResource?.id ) ||
										'#6b7280';

									return (
										<span
											key={ dimType }
											className="clisyc-timeline-dimension-item"
										>
											<span
												className="clisyc-timeline-dimension-dot"
												style={ {
													backgroundColor: dimColor,
												} }
											/>
											<span className="clisyc-timeline-dimension-name">
												{ dimName }
											</span>
										</span>
									);
								}
							) }
						</div>
					) }
				</div>
			);
		},
		[ isCompactView, resources, getDimensionColor ]
	);

	// Calculate calendar height (subtract space for legend and controls)
	const calendarHeight = Math.max( 300, containerHeight - 120 );

	return (
		<div
			className={ `clisyc-timeline-wrapper ${
				isResizing ? 'clisyc-timeline-resizing' : ''
			} ${
				isCompactView
					? 'clisyc-timeline-compact'
					: 'clisyc-timeline-expanded'
			}` }
			ref={ wrapperRef }
			style={ { height: containerHeight } }
		>
			{ /* Timeline Legend - ABOVE the calendar */ }
			{ showLegend && (
				<TimelineLegend
					dimensionTypes={ dimensionTypes }
					colorSettings={ colorSettings }
					l10n={ l10n }
				/>
			) }

			{ /* Zoom Controls Toolbar */ }
			<div className="clisyc-timeline-zoom-toolbar">
				<div className="clisyc-timeline-zoom-controls">
					<span className="clisyc-timeline-zoom-label">
						<span className="dashicons dashicons-search"></span>
						{ l10n.zoom || 'Zoom' }:
					</span>
					<button
						type="button"
						className="clisyc-timeline-zoom-btn"
						onClick={ handleZoomOut }
						disabled={ zoomIndex === 0 }
						title={ l10n.zoomOut || 'Zoom Out (larger time slots)' }
					>
						<span className="dashicons dashicons-minus"></span>
					</button>
					<span
						className="clisyc-timeline-zoom-level"
						title={ l10n.currentZoom || 'Current zoom level' }
					>
						{ currentZoom.label }
					</span>
					<button
						type="button"
						className="clisyc-timeline-zoom-btn"
						onClick={ handleZoomIn }
						disabled={ zoomIndex === ZOOM_LEVELS.length - 1 }
						title={ l10n.zoomIn || 'Zoom In (smaller time slots)' }
					>
						<span className="dashicons dashicons-plus"></span>
					</button>
					<button
						type="button"
						className="clisyc-timeline-zoom-btn clisyc-timeline-zoom-reset"
						onClick={ handleZoomReset }
						title={
							l10n.zoomReset || 'Reset zoom to default (1 hour)'
						}
					>
						<span className="dashicons dashicons-image-rotate"></span>
					</button>
				</div>

				{ /* Compact View Toggle */ }
				<div className="clisyc-timeline-view-toggle">
					<button
						type="button"
						className={ `clisyc-timeline-compact-btn ${
							isCompactView ? 'is-active' : ''
						}` }
						onClick={ handleToggleCompact }
						title={
							isCompactView
								? l10n.expandView ||
								  'Expand rows to show full text'
								: l10n.compactView ||
								  'Compact rows (show details on hover)'
						}
					>
						<span
							className={ `dashicons ${
								isCompactView
									? 'dashicons-editor-expand'
									: 'dashicons-editor-contract'
							}` }
						></span>
						<span className="clisyc-timeline-toggle-label">
							{ isCompactView
								? l10n.compact || 'Compact'
								: l10n.expanded || 'Expanded' }
						</span>
					</button>
				</div>

				<div className="clisyc-timeline-zoom-hint">
					<span className="dashicons dashicons-info-outline"></span>
					{ l10n.zoomHint || 'Tip: Ctrl + scroll to zoom' }
				</div>
			</div>

			{ /* Skeleton while loading */ }
			{ isInitialLoading && (
				<div style={ { padding: '16px' } }>
					<SkeletonTimeline resources={ resources.length || 4 } />
				</div>
			) }

			{ /* FullCalendar Timeline */ }
			<div
				className="clisyc-timeline-calendar-container"
				style={ {
					height: calendarHeight,
					opacity: isInitialLoading ? 0 : 1,
					transition: 'opacity 0.3s',
				} }
			>
				<FullCalendar
					ref={ calendarRef }
					plugins={ [ resourceTimelinePlugin, interactionPlugin ] }
					schedulerLicenseKey="GPL-My-Project-Is-Open-Source"
					initialView={
						initialViewMap[ viewMode ] || 'resourceTimelineWeek'
					}
					timeZone="local"
					height="100%"
					firstDay={ startOfWeek }
					headerToolbar={ {
						left: 'prev,next today',
						center: 'title',
						right: 'resourceTimelineDay,resourceTimelineWeek,resourceTimelineMonth',
					} }
					resourceAreaHeaderContent={
						l10n.selectResource || 'Resources'
					}
					resourceAreaWidth="20%"
					resources={ fcResources }
					resourceLabelContent={ renderResourceLabel }
					events={ fetchEventsSource }
					eventClick={ handleEventClick }
					eventContent={ renderEventContent }
					// Dynamic zoom settings
					slotDuration={ currentZoom.slotDuration }
					slotMinWidth={ currentZoom.slotMinWidth }
					slotLabelInterval={ currentZoom.labelInterval }
					nowIndicator={ true }
					editable={ false }
					resourceOrder="title"
					// Smooth scrolling
					scrollTime="08:00:00"
					scrollTimeReset={ false }
				/>
			</div>

			{ /* Resize Handle — drag-only separator, no keyboard resize. */ }
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="clisyc-timeline-resize-handle"
				onMouseDown={ handleResizeStart }
				onTouchStart={ handleResizeStart }
				role="separator"
				aria-orientation="horizontal"
				title={ l10n.resizeHandle || 'Drag to resize timeline height' }
			>
				<div className="clisyc-timeline-resize-grip">
					<span></span>
					<span></span>
					<span></span>
				</div>
			</div>
		</div>
	);
};

export default TimelineApp;
