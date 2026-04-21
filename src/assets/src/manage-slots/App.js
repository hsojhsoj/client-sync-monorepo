/**
 * File: src/assets/src/manage-slots/App.js -> (compiled)
 * The main React component for the "Manage Time Slots" admin interface.
 *
 * This component replaces the legacy jQuery-based FullCalendar implementation.
 * It provides a visual editor for administrators to create, modify, and delete
 * both available and blocked time slots directly in the database.
 *
 * FIXED: Added navigation for booked events (clicking goes to appointment edit page)
 *
 * @package
 * @subpackage ClientSync/Assets/JS/ManageSlots
 */
import React, { useEffect, useRef, useState, useCallback } from 'react';
import { Calendar } from '@fullcalendar/core';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import dayGridPlugin from '@fullcalendar/daygrid';
import listPlugin from '@fullcalendar/list';
import apiFetch from '@wordpress/api-fetch';
import { SkeletonCalendar } from '../shared/SkeletonLoader';
import '../shared/skeleton-loader.css';

const clisycManageSlotsData = window.clisycManageSlotsData || { l10n: {} };
// FIX: Added editUrlBase and addNewApptUrlBase to destructuring
const {
	restUrl,
	get_nonce,
	save_nonce,
	ajax_url,
	l10n,
	editUrlBase,
	addNewApptUrlBase,
} = clisycManageSlotsData;

const ManageSlotsApp = () => {
	const calendarRef = useRef( null );
	const modalRef = useRef( null );
	const calendarInstanceRef = useRef( null );
	const initialEventsState = useRef( new Set() );
	const isInitialLoad = useRef( true );

	// Ref to hold the save function so dialog button can always call latest version
	const handleModalSaveRef = useRef( null );

	// Single ref to hold all current state values
	const currentStateRef = useRef( {} );

	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ changesMade, setChangesMade ] = useState( false );
	const [ dimensions, setDimensions ] = useState( [] );
	const [ modalSelection, setModalSelection ] = useState( null );

	const [ modalIsBlock, setModalIsBlock ] = useState( false );
	const [ modalSelectedDims, setModalSelectedDims ] = useState( {} );

	// Update ref immediately whenever any state changes
	// This runs synchronously after every render
	useEffect( () => {
		currentStateRef.current = {
			modalSelection,
			modalIsBlock,
			modalSelectedDims,
			dimensions,
		};
	}, [ modalSelection, modalIsBlock, modalSelectedDims, dimensions ] );

	const showToast = ( message, isError = false ) => {
		const statusEl = document.getElementById(
			'clisyc-manage-slots-save-status-text'
		);
		if ( statusEl ) {
			statusEl.textContent = message;
			statusEl.style.color = isError ? '#dc3232' : '#46b450';
			statusEl.style.opacity = 1;
			setTimeout( () => {
				statusEl.style.opacity = 0;
			}, 5000 );
		}
	};

	// Not wrapped in useCallback - recreated on every render with fresh closure
	// This ensures it always has access to the latest state via currentStateRef
	const handleModalSave = () => {
		// Read from ref at execution time to get absolutely latest values
		const {
			modalSelection: currentSelection,
			modalSelectedDims: currentDims,
			dimensions: currentDimensions,
		} = currentStateRef.current;

		// --- THE CRITICAL FIX IS HERE ---
		// We read the 'is_block' state directly from the DOM element at the moment of saving.
		// This bypasses any React state timing issues within the jQuery dialog.
		const currentIsBlock =
			document.getElementById( 'clisyc-dialog-slot-type' )?.value ===
			'true';
		// --- END CRITICAL FIX ---

		if ( ! currentSelection ) {
			console.warn( 'handleModalSave - No modal selection found' );
			return;
		}

		const { startStr, endStr } = currentSelection;
		let eventDataToAdd;

		if ( currentIsBlock ) {
			// This is a BLOCKED slot. Title is 'Blocked' and it has NO dimensions.
			// IMPORTANT: Forcibly ensure dimensions is empty
			eventDataToAdd = {
				start: startStr,
				end: endStr,
				title: l10n.blocked || 'Blocked',
				classNames: [ 'clisyc-blocked-slot' ],
				extendedProps: {
					is_block: true,
					isBooked: false,
					dimensions: {}, // CRITICAL: Must be empty object
				},
			};
		} else {
			// This is an AVAILABLE slot. Build the title from selected dimensions.
			const titleParts = [];
			currentDimensions.forEach( ( dim ) => {
				const selectedPostId = currentDims[ dim.slug ];
				if ( selectedPostId ) {
					const post = dim.posts.find(
						( p ) => p.value === selectedPostId
					);
					if ( post ) {
						titleParts.push( post.label );
					}
				}
			} );

			eventDataToAdd = {
				start: startStr,
				end: endStr,
				title:
					titleParts.length > 0
						? titleParts.join( ' - ' )
						: l10n.available || 'Available',
				classNames: [ 'clisyc-available-slot' ],
				extendedProps: {
					is_block: false,
					isBooked: false,
					dimensions: { ...currentDims }, // Send the selected dimensions
				},
			};
		}

		calendarInstanceRef.current.addEvent( eventDataToAdd );

		window.jQuery( modalRef.current ).dialog( 'close' );
	};

	// Update the ref on every render so dialog button always calls latest version
	useEffect( () => {
		handleModalSaveRef.current = handleModalSave;
	} );

	const getEventSignature = ( event ) => {
		const dims = event.extendedProps.dimensions || {};
		const sortedDimKeys = Object.keys( dims ).sort();
		const dimString = sortedDimKeys
			.map( ( key ) => `${ key }:${ dims[ key ] }` )
			.join( ',' );
		// FIX: Use ISO strings for dates, as startStr/endStr may not exist on manually created events
		const startStr = event.start
			? event.start.toISOString()
			: event.startStr || '';
		const endStr = event.end ? event.end.toISOString() : event.endStr || '';
		return `${ startStr }|${ endStr }|${ !! event.extendedProps
			.is_block }|${ dimString }`;
	};

	const handleSaveChanges = useCallback( () => {
		setChangesMade( ( currentChanges ) => {
			if ( ! currentChanges ) {
				return false;
			}
			setIsSaving( true );
			showToast( l10n.saving || 'Saving...' );
			const calendar = calendarInstanceRef.current;
			const eventsToSave = calendar
				.getEvents()
				.filter( ( e ) => ! e.extendedProps.isBooked )
				.map( ( e ) => ( {
					start: e.start.toISOString(),
					end: e.end.toISOString(),
					is_block: e.extendedProps.is_block || false,
					dimensions: e.extendedProps.dimensions || {},
				} ) );

			window.jQuery
				.post( ajax_url, {
					action: 'clisyc_save_available_slots',
					security: save_nonce,
					slots: eventsToSave,
					viewStart: calendar.view.activeStart.toISOString(),
					viewEnd: calendar.view.activeEnd.toISOString(),
				} )
				.done( ( response ) => {
					if ( response.success ) {
						showToast(
							response.data.message ||
								l10n.saveSuccess ||
								'Slots saved.'
						);

						// Remove all non-booked events from memory before refetch
						// This prevents duplication of manually-created events
						const allEvents = calendar.getEvents();
						allEvents.forEach( ( event ) => {
							if ( ! event.extendedProps.isBooked ) {
								event.remove();
							}
						} );

						calendar.refetchEvents();
					} else {
						showToast(
							response.data.message ||
								l10n.saveError ||
								'Error saving.',
							true
						);
					}
				} )
				.fail( () =>
					showToast( l10n.saveError || 'Error saving.', true )
				)
				.always( () => setIsSaving( false ) );
			return true;
		} );
	}, [] );

	const checkForChanges = useCallback( () => {
		if ( ! calendarInstanceRef.current ) {
			return;
		}
		const currentEvents = calendarInstanceRef.current
			.getEvents()
			.filter( ( e ) => ! e.extendedProps.isBooked );
		const currentSignatures = new Set(
			currentEvents.map( getEventSignature )
		);
		let hasChanged =
			initialEventsState.current.size !== currentSignatures.size;
		if ( ! hasChanged ) {
			for ( const sig of currentSignatures ) {
				if ( ! initialEventsState.current.has( sig ) ) {
					hasChanged = true;
					break;
				}
			}
		}
		setChangesMade( hasChanged );
	}, [] );

	useEffect( () => {
		const $ = window.jQuery;
		const $modal = $( modalRef.current );
		const calendarEl = calendarRef.current;
		let calendar;

		const fetchDimensions = async () => {
			try {
				const enabledTypes = await apiFetch( {
					path: '/clisyc/v1/dimensions/types',
				} );

				// CRITICAL FIX: Handle case where API doesn't return an array
				if ( ! Array.isArray( enabledTypes ) ) {
					console.error(
						'Dimensions API did not return an array:',
						enabledTypes
					);
					setDimensions( [] );
					showToast(
						'Warning: Could not load dimension types. Dimension fields may not be available.',
						true
					);
					return;
				}

				const dimensionData = await Promise.all(
					enabledTypes.map( async ( type ) => {
						const posts = await apiFetch( {
							path: `/clisyc/v1/dimensions/posts?type=${ type.slug }`,
						} );
						return {
							...type,
							posts: Array.isArray( posts ) ? posts : [],
						};
					} )
				);
				setDimensions( dimensionData );
			} catch ( error ) {
				console.error( 'Failed to fetch dimensions:', error );
				setDimensions( [] );
				showToast(
					'Warning: Could not load dimensions. Please refresh the page.',
					true
				);
			}
		};
		fetchDimensions();

		// Attach native event listeners to dimension dropdowns
		// This is necessary because jQuery UI moves the modal DOM, breaking React's synthetic events
		const attachDropdownListeners = () => {
			const currentDimensions = currentStateRef.current.dimensions || [];
			currentDimensions.forEach( ( dim ) => {
				const dropdown = document.getElementById(
					`clisyc-dialog-dim-${ dim.slug }`
				);
				if (
					dropdown &&
					! dropdown.hasAttribute( 'data-listener-attached' )
				) {
					dropdown.setAttribute( 'data-listener-attached', 'true' );
					dropdown.addEventListener( 'change', ( e ) => {
						const value = e.target.value;
						setModalSelectedDims( ( prev ) => ( {
							...prev,
							[ dim.slug ]: value,
						} ) );
					} );
				}
			} );
		};

		if ( calendarEl && ! calendarInstanceRef.current ) {
			calendar = new Calendar( calendarEl, {
				plugins: [
					interactionPlugin,
					dayGridPlugin,
					timeGridPlugin,
					listPlugin,
				],
				initialView: 'timeGridWeek',
				headerToolbar: {
					left: 'prev,next today',
					center: 'title',
					right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek saveAvailableSlotsButton',
				},
				customButtons: {
					saveAvailableSlotsButton: {
						text: l10n.saveButton || 'Save Calendar Slots',
						click: handleSaveChanges,
					},
				},
				editable: true,
				selectable: true,
				allDaySlot: false,
				height: 'auto',
				nowIndicator: true,
				events: ( fetchInfo, successCallback, failureCallback ) => {
					setIsLoading( true );
					isInitialLoad.current = true;
					apiFetch( {
						path: `/clisyc/v1/slots?start=${ fetchInfo.start.toISOString() }&end=${ fetchInfo.end.toISOString() }&context=admin_editable&_wpnonce=${ get_nonce }`,
					} )
						.then( ( data ) => {
							if ( data.events ) {
								successCallback( data.events || [] );
							}
							setIsLoading( false );
						} )
						.catch( ( error ) => {
							console.error(
								'[FETCH] Error fetching slots:',
								error
							);
							failureCallback( error );
							setIsLoading( false );
						} );
				},
				select: ( selectInfo ) => {
					setModalSelection( selectInfo );
					setModalIsBlock( false );

					// Initialize dims from current state ref to ensure we have the latest dimensions
					const initialDims = {};
					const currentDimensions =
						currentStateRef.current.dimensions || [];
					currentDimensions.forEach(
						( dim ) => ( initialDims[ dim.slug ] = '' )
					);

					setModalSelectedDims( initialDims );

					$modal.dialog( 'open' );

					// Attach native event listeners after dialog opens
					// Use setTimeout to ensure DOM is ready after jQuery UI moves elements
					setTimeout( () => attachDropdownListeners(), 50 );

					calendar.unselect();
				},
				eventsSet: () => {
					if ( isInitialLoad.current ) {
						const initialEvents = calendarInstanceRef.current
							.getEvents()
							.filter( ( e ) => ! e.extendedProps.isBooked );
						initialEventsState.current = new Set(
							initialEvents.map( getEventSignature )
						);
						isInitialLoad.current = false;
						setChangesMade( false );
					} else {
						checkForChanges();
					}
				},
				// =====================================================
				// FIX: Updated eventClick handler to navigate to booked appointments
				// =====================================================
				eventClick: ( info ) => {
					const props = info.event.extendedProps || {};

					// First check if the delete button was clicked
					if (
						info.jsEvent.target.classList.contains(
							'clisyc-delete-event'
						)
					) {
						// Don't allow deleting booked events
						if ( props.isBooked ) {
							return;
						}
						if (
							window.confirm(
								l10n.confirmDelete ||
									'Are you sure you want to delete this slot?'
							)
						) {
							info.event.remove();
						}
						return;
					}

					// Handle clicking on booked events - navigate to appointment edit page
					if ( props.isBooked && props.appointmentId ) {
						info.jsEvent.preventDefault();
						if ( editUrlBase ) {
							window.location.href =
								editUrlBase + props.appointmentId;
						} else {
							// Fallback if editUrlBase wasn't provided in localization
							window.location.href = `/wp-admin/post.php?action=edit&post=${ props.appointmentId }`;
						}
					}

					// For available slots (not booked, not blocked), could optionally
					// navigate to create a new appointment with this slot pre-selected
					// This is commented out but available if needed:
					/*
                    if (!props.isBooked && !props.is_block && addNewApptUrlBase) {
                        const newApptUrl = new URL(addNewApptUrlBase, window.location.origin);
                        if (props.slotIdentifier) {
                            newApptUrl.searchParams.set('clisyc_slot', props.slotIdentifier);
                        }
                        if (props.dimensions && typeof props.dimensions === 'object') {
                            for (const key in props.dimensions) {
                                newApptUrl.searchParams.set(`clisyc_dim_${key}`, props.dimensions[key]);
                            }
                        }
                        window.location.href = newApptUrl.toString();
                    }
                    */
				},
				// =====================================================
				// END FIX
				// =====================================================
				eventContent: ( arg ) => {
					const props = arg.event.extendedProps || {};
					// HTML escape to prevent XSS from dimension names in event titles.
					const esc = ( s ) => {
						const d = document.createElement( 'div' );
						d.textContent = s || '';
						return d.innerHTML;
					};
					// Don't show delete button on booked events
					const deleteBtnHtml = ! props.isBooked
						? `<span class="clisyc-delete-event" title="${ esc(
								l10n.confirmDelete || 'Delete Slot'
						  ) }">×</span>`
						: '';
					return {
						html: `<div class="fc-event-main-frame"><div class="fc-event-time">${ esc(
							arg.timeText
						) }</div><div class="fc-event-title-container"><div class="fc-event-title fc-sticky">${ esc(
							arg.event.title
						) }</div></div>${ deleteBtnHtml }</div>`,
					};
				},
				eventAllow: ( dropInfo, draggedEvent ) =>
					! draggedEvent.extendedProps.isBooked,
			} );
			calendarInstanceRef.current = calendar;
			calendar.render();
		}

		// Set up jQuery UI dialog with button that calls via ref
		if ( $modal.length && ! $modal.data( 'ui-dialog' ) ) {
			$modal.dialog( {
				autoOpen: false,
				modal: true,
				width: 350,
				title: l10n.createSlotTitle || 'Create New Slot',
				buttons: [
					{
						text: 'Create Slot',
						class: 'button button-primary',
						click() {
							// Call via ref to always get latest version
							if ( handleModalSaveRef.current ) {
								handleModalSaveRef.current();
							}
						},
					},
					{
						text: 'Cancel',
						class: 'button',
						click() {
							$( this ).dialog( 'close' );
						},
					},
				],
			} );
		}

		return () => {
			if ( calendarInstanceRef.current ) {
				calendarInstanceRef.current.destroy();
				calendarInstanceRef.current = null;
			}
			if ( $modal.data( 'ui-dialog' ) ) {
				$modal.dialog( 'destroy' );
			}
		};
	}, [ handleSaveChanges, checkForChanges ] ); // DO NOT include handleModalSave here

	useEffect( () => {
		const saveButton = calendarRef.current?.querySelector(
			'.fc-saveAvailableSlotsButton-button'
		);
		if ( saveButton ) {
			saveButton.disabled = ! changesMade || isSaving;
		}
	}, [ changesMade, isSaving, isLoading ] );

	return (
		<>
			<div className="clisyc-admin-instructions">
				<p>
					<strong>{ l10n.whatIsThis || 'What is this?' }</strong>{ ' ' }
					{ l10n.whatIsThisDesc ||
						'This is the primary editor for making direct, manual changes to your calendar for a specific date range.' }
				</p>
				<p>
					<strong>
						{ l10n.whatCanIDo || 'What can I do here?' }
					</strong>
				</p>
				<ul style={ { listStyle: 'disc', marginLeft: '20px' } }>
					<li>
						{ l10n.actionCreate ||
							'Create new slots by clicking and dragging on an empty area.' }
					</li>
					<li>
						{ l10n.actionModify ||
							'Modify existing slots by dragging them to a new time or resizing their duration.' }
					</li>
					<li>
						{ l10n.actionDelete ||
							'Delete a slot by clicking the (×) icon that appears on hover.' }
					</li>
				</ul>
				<p>
					<em>
						<strong>{ l10n.importantNote || 'Important:' }</strong>{ ' ' }
						{ l10n.importantNoteDesc ||
							'Changes are not saved until you click the "Save Calendar Slots" button.' }
					</em>
				</p>
				<div
					id="clisyc-manage-slots-save-status"
					style={ {
						textAlign: 'right',
						display: 'inline-block',
						float: 'right',
					} }
				>
					<span
						id="clisyc-manage-slots-save-status-text"
						style={ { transition: 'opacity 0.3s', opacity: 0 } }
					></span>
				</div>
			</div>
			<div id="clisyc-manage-slots-calendar-container">
				{ isLoading && (
					<div className="clisyc-loading-overlay">
						<SkeletonCalendar />
					</div>
				) }
				<div ref={ calendarRef }></div>
			</div>
			<div ref={ modalRef } style={ { display: 'none' } }>
				<div className="clisyc-slot-modal">
					<div className="clisyc-dialog-field">
						<label htmlFor="clisyc-dialog-slot-type">
							{ l10n.slotTypeLabel || 'Slot Type' }
						</label>
						<select
							id="clisyc-dialog-slot-type"
							value={ modalIsBlock }
							onChange={ ( e ) => {
								const isBlocked = e.target.value === 'true';
								setModalIsBlock( isBlocked );
								// Clear dimensions when switching to blocked
								if ( isBlocked ) {
									const clearedDims = {};
									dimensions.forEach(
										( dim ) =>
											( clearedDims[ dim.slug ] = '' )
									);
									setModalSelectedDims( clearedDims );
								}
							} }
						>
							<option value="false">Available</option>
							<option value="true">Blocked</option>
						</select>
					</div>
					{ ! modalIsBlock &&
						dimensions.map( ( dim ) => (
							<div
								key={ dim.slug }
								className="clisyc-dialog-field"
							>
								<label
									htmlFor={ `clisyc-dialog-dim-${ dim.slug }` }
								>
									{ dim.label }
								</label>
								<select
									id={ `clisyc-dialog-dim-${ dim.slug }` }
									value={
										modalSelectedDims[ dim.slug ] || ''
									}
									onChange={ ( e ) => {
										setModalSelectedDims( ( prev ) => ( {
											...prev,
											[ dim.slug ]: e.target.value,
										} ) );
									} }
								>
									<option value="">
										-- { l10n.none || 'None' } --
									</option>
									{ dim.posts.map( ( post ) => (
										<option
											key={ post.value }
											value={ post.value }
										>
											{ post.label }
										</option>
									) ) }
								</select>
							</div>
						) ) }
				</div>
			</div>
		</>
	);
};

export default ManageSlotsApp;
