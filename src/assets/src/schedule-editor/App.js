/**
 * File: src/assets/src/schedule-editor/App.js
 * The main React component for the "Base Weekly Schedule" editor.
 * FINAL WORKING VERSION: Fixes infinite loop and calendar rendering
 */
import React, {
	useState,
	useEffect,
	useMemo,
	useCallback,
	useRef,
} from 'react';
import { Calendar } from '@fullcalendar/core';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import './style.css';

const ScheduleEditorApp = () => {
	const editorData = window.clisycScheduleEditorData;
	const startOfWeek = parseInt( editorData.startOfWeek, 10 );
	const weekdays = editorData.weekdays;
	const initialScheduleJson =
		editorData.scheduleJson || '{"templates":{"A":{}}}';

	// Reference to the hidden textarea that stores the schedule JSON
	const outputTextareaRef = useRef( null );

	// Initialize the textarea ref after mount
	useEffect( () => {
		outputTextareaRef.current = document.getElementById(
			'clisyc_schedule_json_output'
		);
	}, [] );

	const [ scheduleJson, setScheduleJson ] = useState( initialScheduleJson );

	// Sync changes back to the hidden textarea
	useEffect( () => {
		if ( outputTextareaRef.current ) {
			outputTextareaRef.current.value = scheduleJson;
		}
	}, [ scheduleJson ] );

	const scheduleData = useMemo( () => {
		try {
			const parsed = JSON.parse( scheduleJson || '{}' );
			return typeof parsed === 'object' && parsed !== null
				? parsed
				: { templates: { A: {} } };
		} catch ( e ) {
			console.error(
				'[Schedule Editor] Error parsing schedule JSON:',
				e
			);
			return { templates: { A: {} } };
		}
	}, [ scheduleJson ] );

	const [ activeTemplateKey, setActiveTemplateKey ] = useState( 'A' );
	const [ activeDayIndex, setActiveDayIndex ] = useState( startOfWeek );

	const calendarRefs = useRef( {} );
	// FIX: Track if we're currently collecting data to prevent infinite loops
	const isCollectingRef = useRef( false );

	const updateSchedule = useCallback( ( newScheduleData ) => {
		setScheduleJson( JSON.stringify( newScheduleData ) );
	}, [] );

	// FIX: Only collect data when explicitly called, not during render
	const collectAndSaveAllCalendarData = useCallback( () => {
		// Prevent recursive calls
		if ( isCollectingRef.current ) {
			return;
		}

		isCollectingRef.current = true;

		const currentSchedule = JSON.parse( scheduleJson || '{}' );
		const newSchedule = JSON.parse( JSON.stringify( currentSchedule ) );

		if ( ! newSchedule.templates ) {
			newSchedule.templates = {};
		}
		if ( ! newSchedule.templates[ activeTemplateKey ] ) {
			newSchedule.templates[ activeTemplateKey ] = {};
		}

		// Loop through all calendar instances and collect their data
		Object.entries( calendarRefs.current ).forEach(
			( [ dayIndex, calendar ] ) => {
				if ( calendar ) {
					const events = calendar.getEvents();
					if ( events.length > 0 ) {
						const slots = events
							.map( ( event ) => ( {
								start: event.start
									.toTimeString()
									.substring( 0, 5 ),
								end: event.end.toTimeString().substring( 0, 5 ),
							} ) )
							.sort( ( a, b ) =>
								a.start.localeCompare( b.start )
							);
						newSchedule.templates[ activeTemplateKey ][ dayIndex ] =
							{ slots };
					} else {
						delete newSchedule.templates[ activeTemplateKey ][
							dayIndex
						];
					}
				}
			}
		);

		updateSchedule( newSchedule );
		isCollectingRef.current = false;
	}, [ scheduleJson, activeTemplateKey, updateSchedule ] );

	const handleAddTemplate = () => {
		collectAndSaveAllCalendarData();
		const newKey = String.fromCharCode(
			65 + Object.keys( scheduleData.templates || {} ).length
		);
		const newTemplates = { ...scheduleData.templates, [ newKey ]: {} };
		updateSchedule( { ...scheduleData, templates: newTemplates } );
		setActiveTemplateKey( newKey );
	};

	const handleDeleteTemplate = ( keyToDelete ) => {
		if (
			! window.confirm(
				`Are you sure you want to delete "Week ${ keyToDelete }"? This cannot be undone.`
			)
		) {
			return;
		}

		collectAndSaveAllCalendarData();
		const { [ keyToDelete ]: _, ...remainingTemplates } =
			scheduleData.templates;
		const newTemplates = {};
		Object.keys( remainingTemplates )
			.sort()
			.forEach( ( oldKey, index ) => {
				const newKey = String.fromCharCode( 65 + index );
				newTemplates[ newKey ] = remainingTemplates[ oldKey ];
			} );

		updateSchedule( { ...scheduleData, templates: newTemplates } );
		setActiveTemplateKey( 'A' );
	};

	// Main calendar initialization and update effect
	useEffect( () => {
		const dayIndex = activeDayIndex;
		const calendarEl = document.getElementById(
			`clisyc-schedule-day-calendar-${ dayIndex }`
		);

		// Only create calendar if it doesn't exist yet
		if ( calendarEl && ! calendarRefs.current[ dayIndex ] ) {
			const { calendarOptions } = editorData;
			const currentTemplateData =
				scheduleData.templates?.[ activeTemplateKey ]?.[ dayIndex ]
					?.slots || [];

			const calendar = new Calendar( calendarEl, {
				...calendarOptions,
				plugins: [ timeGridPlugin, interactionPlugin ],
				events: currentTemplateData.map( ( slot ) => ( {
					start: `${ calendarOptions.initialDate }T${ slot.start }:00`,
					end: `${ calendarOptions.initialDate }T${ slot.end }:00`,
					classNames: [ 'clisyc-standard-slot' ],
				} ) ),
				selectable: true,
				editable: true,
				select: ( info ) => {
					calendar.addEvent( {
						start: info.startStr,
						end: info.endStr,
						classNames: [ 'clisyc-standard-slot' ],
					} );
					calendar.unselect();
					// FIX: Use setTimeout to allow event to be added before collecting
					setTimeout( () => collectAndSaveAllCalendarData(), 50 );
				},
				eventChange: () => {
					setTimeout( () => collectAndSaveAllCalendarData(), 50 );
				},
				eventRemove: () => {
					setTimeout( () => collectAndSaveAllCalendarData(), 50 );
				},
				eventContent: ( arg ) => ( {
					html: `<div class="fc-event-main-frame">
                             <div class="fc-event-time">${ arg.timeText }</div>
                             <button type="button" class="clisyc-delete-event-btn">&times;</button>
                           </div>`,
				} ),
				eventClick: ( info ) => {
					if (
						info.jsEvent.target.classList.contains(
							'clisyc-delete-event-btn'
						)
					) {
						if ( window.confirm( 'Delete this slot?' ) ) {
							info.event.remove();
						}
					}
				},
			} );

			calendar.render();
			calendarRefs.current[ dayIndex ] = calendar;

			// FIX: Force calendar to resize after render to fix missing lines issue
			setTimeout( () => {
				calendar.updateSize();
			}, 100 );
		} else if ( calendarRefs.current[ dayIndex ] ) {
			// Calendar exists, just update its events when switching templates
			const calendar = calendarRefs.current[ dayIndex ];
			const currentTemplateData =
				scheduleData.templates?.[ activeTemplateKey ]?.[ dayIndex ]
					?.slots || [];

			// FIX: Don't trigger events during programmatic updates
			isCollectingRef.current = true;

			// Remove all events
			calendar.getEvents().forEach( ( event ) => event.remove() );

			// Add new events from the current template
			currentTemplateData.forEach( ( slot ) => {
				calendar.addEvent( {
					start: `${ editorData.calendarOptions.initialDate }T${ slot.start }:00`,
					end: `${ editorData.calendarOptions.initialDate }T${ slot.end }:00`,
					classNames: [ 'clisyc-standard-slot' ],
				} );
			} );

			isCollectingRef.current = false;

			setTimeout( () => calendar.updateSize(), 10 );
		}
	}, [
		activeDayIndex,
		activeTemplateKey,
		scheduleData,
		collectAndSaveAllCalendarData,
		editorData,
		weekdays,
	] );

	const handleTemplateSwitch = ( newKey ) => {
		collectAndSaveAllCalendarData();
		setActiveTemplateKey( newKey );
	};

	const handleDaySwitch = ( index ) => {
		setActiveDayIndex( index );
	};

	const orderedWeekdays = useMemo( () => {
		const days = [];
		for ( let i = 0; i < 7; i++ ) {
			const dayIndex = ( startOfWeek + i ) % 7;
			days.push( { index: dayIndex, name: weekdays[ dayIndex ] } );
		}
		return days;
	}, [ startOfWeek, weekdays ] );

	const templateKeys = Object.keys(
		scheduleData.templates || { A: {} }
	).sort();

	return (
		<div className="clisyc-weekly-schedule-container">
			<div className="clisyc-template-nav-container">
				{ templateKeys.map( ( key ) => (
					<span
						key={ key }
						className={ `clisyc-template-tab ${
							key === activeTemplateKey ? 'active' : ''
						}` }
						onClick={ () => handleTemplateSwitch( key ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								handleTemplateSwitch( key );
							}
						} }
						role="tab"
						tabIndex={ 0 }
					>
						Week { key }
						{ templateKeys.length > 1 && (
							<button
								type="button"
								className="button-link clisyc-delete-template"
								title="Delete Template"
								onClick={ ( e ) => {
									e.stopPropagation();
									handleDeleteTemplate( key );
								} }
							>
								&times;
							</button>
						) }
					</span>
				) ) }
				<button
					type="button"
					className="button button-secondary button-small clisyc-add-template-btn"
					onClick={ handleAddTemplate }
				>
					+ Add Week
				</button>
			</div>

			<nav className="nav-tab-wrapper clisyc-schedule-day-tabs">
				{ orderedWeekdays.map( ( { index, name } ) => {
					const hasSlots =
						scheduleData.templates?.[ activeTemplateKey ]?.[ index ]
							?.slots?.length > 0;
					return (
						<button
							key={ index }
							type="button"
							className={ `nav-tab clisyc-day-tab ${
								index === activeDayIndex ? 'nav-tab-active' : ''
							} ${ hasSlots ? 'has-slots' : 'no-slots' }` }
							onClick={ () => handleDaySwitch( index ) }
						>
							<span className="clisyc-day-tab-indicator"></span>{ ' ' }
							{ name }
						</button>
					);
				} ) }
			</nav>

			<div className="clisyc-schedule-calendars-wrapper">
				{ orderedWeekdays.map( ( { index } ) => (
					<div
						key={ index }
						id={ `clisyc-schedule-day-panel-${ index }` }
						className="clisyc-schedule-day-panel"
						style={ {
							display:
								index === activeDayIndex ? 'block' : 'none',
						} }
					>
						<div
							id={ `clisyc-schedule-day-calendar-${ index }` }
						></div>
					</div>
				) ) }
			</div>
		</div>
	);
};

export default ScheduleEditorApp;
