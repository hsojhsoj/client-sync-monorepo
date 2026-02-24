/**
 * Admin Check-In Page — Search, Check-In, QR Scanner, Stats, Door List & Bulk Actions.
 *
 * Handles:
 * 1. Search form → AJAX search → render appointment cards / door list
 * 2. Check-in button click → AJAX confirm → update UI → refresh stats
 * 3. QR scanner toggle → camera → scan → AJAX token check-in → refresh stats
 * 4. Stats bar → fetch on load, date change, and after every check-in
 * 5. View toggle → cards / door list table
 * 6. Bulk check-in, CSV export, print
 *
 * @package ClientSyncPro
 */

/* global clisycCheckin, jQuery */

import { Html5Qrcode } from 'html5-qrcode';

( function ( $ ) {
	'use strict';

	const { ajaxUrl, searchNonce, confirmNonce, tokenNonce, statsNonce, bulkNonce, undoNonce, eventsNonce, strings } = clisycCheckin;

	let scanner = null;
	let scannerRunning = false;
	let currentView = 'cards';
	let lastAppointments = []; // Cache for view switching, CSV export, print.
	let currentPage = 1;
	let hasMorePages = false;
	let isLoadingMore = false;
	let currentEvents = []; // Cached events for the selected date.

	// ── Audio Context (Sound Feedback) ─────────────────────────────

	let audioCtx = null;

	function getAudioContext() {
		if ( ! audioCtx ) {
			try {
				audioCtx = new ( window.AudioContext || window.webkitAudioContext )();
			} catch ( e ) {
				// Audio not supported — fail silently.
			}
		}
		return audioCtx;
	}

	/**
	 * Play a short success chime (two ascending tones).
	 */
	function playSuccessSound() {
		const ctx = getAudioContext();
		if ( ! ctx ) return;

		const now = ctx.currentTime;
		const gainNode = ctx.createGain();
		gainNode.connect( ctx.destination );
		gainNode.gain.setValueAtTime( 0.25, now );
		gainNode.gain.exponentialRampToValueAtTime( 0.01, now + 0.4 );

		// First tone (C5 ~523Hz)
		const osc1 = ctx.createOscillator();
		osc1.type = 'sine';
		osc1.frequency.setValueAtTime( 523.25, now );
		osc1.connect( gainNode );
		osc1.start( now );
		osc1.stop( now + 0.15 );

		// Second tone (E5 ~659Hz)
		const osc2 = ctx.createOscillator();
		osc2.type = 'sine';
		osc2.frequency.setValueAtTime( 659.25, now + 0.15 );
		osc2.connect( gainNode );
		osc2.start( now + 0.15 );
		osc2.stop( now + 0.4 );
	}

	/**
	 * Play a short error buzz (low descending tone).
	 */
	function playErrorSound() {
		const ctx = getAudioContext();
		if ( ! ctx ) return;

		const now = ctx.currentTime;
		const gainNode = ctx.createGain();
		gainNode.connect( ctx.destination );
		gainNode.gain.setValueAtTime( 0.2, now );
		gainNode.gain.exponentialRampToValueAtTime( 0.01, now + 0.3 );

		const osc = ctx.createOscillator();
		osc.type = 'square';
		osc.frequency.setValueAtTime( 200, now );
		osc.frequency.linearRampToValueAtTime( 120, now + 0.3 );
		osc.connect( gainNode );
		osc.start( now );
		osc.stop( now + 0.3 );
	}

	/**
	 * Trigger haptic feedback (vibration) if supported.
	 *
	 * @param {string} type - 'success' or 'error'.
	 */
	function triggerHaptic( type ) {
		if ( ! navigator.vibrate ) return;

		if ( type === 'success' ) {
			navigator.vibrate( [ 50, 30, 50 ] ); // Two short pulses.
		} else {
			navigator.vibrate( [ 150 ] ); // One longer pulse.
		}
	}

	/**
	 * Flash a visual feedback overlay on the given element.
	 *
	 * @param {jQuery} $el   - The element to flash.
	 * @param {string} type  - 'success' or 'error'.
	 */
	function flashFeedback( $el, type ) {
		const cls = 'clisyc-checkin-flash--' + type;
		$el.addClass( cls );
		setTimeout( function () {
			$el.removeClass( cls );
		}, 600 );
	}

	// ── Stats Bar ──────────────────────────────────────────────────

	let statsInterval = null;
	const STATS_POLL_MS = 15000; // 15 seconds.

	function fetchStats() {
		const date = $( '#clisyc-checkin-date' ).val();

		$.post( ajaxUrl, {
			action: 'clisyc_checkin_stats',
			nonce: statsNonce,
			date,
			event_id: getSelectedEventId(),
		}, function ( resp ) {
			if ( ! resp.success ) {
				return;
			}
			const d = resp.data;
			$( '#clisyc-stat-checked-in' ).text( d.checked_in );
			$( '#clisyc-stat-expected' ).text( d.total );
			$( '#clisyc-stat-remaining' ).text( d.remaining );
			$( '#clisyc-stat-rate' ).text( d.rate_per_hour );
			$( '#clisyc-stat-progress' ).css( 'width', d.percentage + '%' );
			$( '#clisyc-checkin-stats' ).show();
		} );
	}

	function startStatsPolling() {
		stopStatsPolling();
		statsInterval = setInterval( fetchStats, STATS_POLL_MS );
	}

	function stopStatsPolling() {
		if ( statsInterval ) {
			clearInterval( statsInterval );
			statsInterval = null;
		}
	}

	// Fetch stats on page load + start auto-refresh.
	fetchStats();
	startStatsPolling();

	// Refresh stats when date changes — reset polling timer.
	$( '#clisyc-checkin-date' ).on( 'change', function () {
		fetchStats();
		startStatsPolling(); // Reset the interval from the date change moment.
	} );

	// Stop polling when the tab is hidden, resume when visible (battery-friendly).
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			stopStatsPolling();
		} else {
			fetchStats();
			startStatsPolling();
		}
	} );

	// ── Midnight Date Auto-Switch ──────────────────────────────────

	function scheduleMidnightSwitch() {
		const now = new Date();
		const midnight = new Date( now );
		midnight.setHours( 24, 0, 0, 0 ); // Next midnight.
		const msUntilMidnight = midnight.getTime() - now.getTime();

		setTimeout( function () {
			// Update the date input to the new day.
			const newDate = new Date();
			const yyyy = newDate.getFullYear();
			const mm   = String( newDate.getMonth() + 1 ).padStart( 2, '0' );
			const dd   = String( newDate.getDate() ).padStart( 2, '0' );
			$( '#clisyc-checkin-date' ).val( yyyy + '-' + mm + '-' + dd );

			// Refresh stats for the new date.
			fetchStats();
			startStatsPolling();

			// Schedule the next midnight switch.
			scheduleMidnightSwitch();
		}, msUntilMidnight + 500 ); // +500ms buffer past midnight.
	}

	scheduleMidnightSwitch();

	// ── Live Clock ────────────────────────────────────────────────

	function updateClock() {
		const now = new Date();
		const h = String( now.getHours() ).padStart( 2, '0' );
		const m = String( now.getMinutes() ).padStart( 2, '0' );
		const s = String( now.getSeconds() ).padStart( 2, '0' );
		$( '#clisyc-live-clock' ).text( h + ':' + m + ':' + s );
	}

	updateClock();
	setInterval( updateClock, 1000 );

	// ── Event Filter ──────────────────────────────────────────────

	function getSelectedEventId() {
		return parseInt( $( '#clisyc-checkin-event-filter' ).val(), 10 ) || 0;
	}

	function loadEventsForDate() {
		const date = $( '#clisyc-checkin-date' ).val();

		$.post( ajaxUrl, {
			action: 'clisyc_checkin_events',
			nonce: eventsNonce,
			date,
		}, function ( resp ) {
			if ( ! resp.success ) {
				return;
			}

			currentEvents = resp.data.events || [];
			const $select = $( '#clisyc-checkin-event-filter' );
			const previousVal = $select.val();
			$select.find( 'option:not(:first)' ).remove();

			currentEvents.forEach( function ( evt ) {
				const label = evt.title +
					( evt.time ? ' — ' + evt.time : '' ) +
					' (' + evt.count + ')';
				$select.append( '<option value="' + evt.id + '">' + escHtml( label ) + '</option>' );
			} );

			// Restore previous selection if still available.
			if ( previousVal && $select.find( 'option[value="' + previousVal + '"]' ).length ) {
				$select.val( previousVal );
			}

			// Show / hide filter depending on whether there are multiple events.
			if ( currentEvents.length > 1 ) {
				$select.show();
			} else if ( currentEvents.length === 1 ) {
				// Auto-select the single event.
				$select.val( currentEvents[0].id ).show();
			} else {
				$select.val( '' ).show();
			}

			updateEventInfoBanner();
		} );
	}

	// Load events on page load.
	loadEventsForDate();

	// Reload events when date changes.
	$( '#clisyc-checkin-date' ).on( 'change', function () {
		loadEventsForDate();
	} );

	// When event filter changes, refresh search + stats + banner.
	$( '#clisyc-checkin-event-filter' ).on( 'change', function () {
		updateEventInfoBanner();
		fetchStats();
		startStatsPolling();
		// If there are already results, re-search with the new filter.
		if ( lastAppointments.length ) {
			doSearch( 1 );
		}
	} );

	// ── Event Info Banner & Countdown ──────────────────────────────

	let countdownInterval = null;

	function updateEventInfoBanner() {
		const eventId = getSelectedEventId();
		const $banner = $( '#clisyc-event-info' );

		if ( countdownInterval ) {
			clearInterval( countdownInterval );
			countdownInterval = null;
		}

		if ( ! eventId ) {
			$banner.hide();
			return;
		}

		const evt = currentEvents.find( function ( e ) { return e.id === eventId; } );
		if ( ! evt ) {
			$banner.hide();
			return;
		}

		let html = '<div class="clisyc-event-info__details">' +
			'<span class="clisyc-event-info__name">' + escHtml( evt.title ) + '</span>';
		if ( evt.venue ) {
			html += '<span class="clisyc-event-info__venue"><span class="dashicons dashicons-location"></span> ' + escHtml( evt.venue ) + '</span>';
		}
		if ( evt.time ) {
			html += '<span class="clisyc-event-info__time"><span class="dashicons dashicons-clock"></span> ' + escHtml( evt.time ) + '</span>';
		}
		html += '</div>';

		if ( evt.time_iso ) {
			html += '<div class="clisyc-event-info__countdown" id="clisyc-event-countdown"></div>';
		}

		$banner.html( html ).show();

		// Start countdown if we have a time.
		if ( evt.time_iso ) {
			const eventTime = new Date( evt.time_iso ).getTime();
			function tickCountdown() {
				const now = Date.now();
				const diff = eventTime - now;
				const $cd = $( '#clisyc-event-countdown' );

				if ( Math.abs( diff ) < 60000 ) {
					// Within 1 minute — show "Now".
					$cd.html( '<span class="clisyc-countdown--now">' + escHtml( strings.now || 'Now' ) + '</span>' );
				} else if ( diff > 0 ) {
					// Future — count down.
					const hrs  = Math.floor( diff / 3600000 );
					const mins = Math.floor( ( diff % 3600000 ) / 60000 );
					let label = escHtml( strings.startsIn || 'Starts in' ) + ' ';
					if ( hrs > 0 ) {
						label += hrs + 'h ' + mins + 'm';
					} else {
						label += mins + 'm';
					}
					$cd.html( '<span class="clisyc-countdown--future">' + label + '</span>' );
				} else {
					// Past — show time since.
					const elapsed = Math.abs( diff );
					const hrs  = Math.floor( elapsed / 3600000 );
					const mins = Math.floor( ( elapsed % 3600000 ) / 60000 );
					let label = escHtml( strings.started || 'Started' ) + ' ';
					if ( hrs > 0 ) {
						label += hrs + 'h ' + mins + 'm';
					} else {
						label += mins + 'm';
					}
					label += ' ' + escHtml( strings.ago || 'ago' );
					$cd.html( '<span class="clisyc-countdown--past">' + label + '</span>' );
				}
			}

			tickCountdown();
			countdownInterval = setInterval( tickCountdown, 15000 ); // Update every 15s.
		}
	}

	// ── Search ──────────────────────────────────────────────────────

	function doSearch( page ) {
		page = page || 1;
		const search = $( '#clisyc-checkin-search' ).val().trim();
		const date = $( '#clisyc-checkin-date' ).val();
		const $results = $( '#clisyc-checkin-results' );
		const isFirstPage = page === 1;

		if ( isFirstPage ) {
			$results.html( '<div class="clisyc-checkin-loading">' + strings.scanning + '</div>' );
		} else {
			isLoadingMore = true;
			$( '#clisyc-load-more-btn' ).prop( 'disabled', true ).text( strings.loading || 'Loading…' );
		}

		$.post( ajaxUrl, {
			action: 'clisyc_checkin_search',
			nonce: searchNonce,
			search,
			date,
			page,
			event_id: getSelectedEventId(),
		}, function ( resp ) {
			isLoadingMore = false;

			if ( ! resp.success || ! resp.data.appointments.length ) {
				if ( isFirstPage ) {
					lastAppointments = [];
					currentPage = 1;
					hasMorePages = false;
					$results.html( '<p class="clisyc-checkin-placeholder">' + strings.noResults + '</p>' );
				}
				return;
			}

			if ( isFirstPage ) {
				lastAppointments = resp.data.appointments;
			} else {
				lastAppointments = lastAppointments.concat( resp.data.appointments );
			}

			currentPage = resp.data.page || page;
			hasMorePages = resp.data.has_more || false;

			renderCurrentView( $results );
		} ).fail( function () {
			isLoadingMore = false;
			if ( isFirstPage ) {
				lastAppointments = [];
				$results.html( '<p class="clisyc-checkin-placeholder">' + strings.error + '</p>' );
			}
		} );
	}

	$( '#clisyc-checkin-search-btn' ).on( 'click', function () { doSearch( 1 ); } );
	$( '#clisyc-checkin-search' ).on( 'keypress', function ( e ) {
		if ( e.which === 13 ) {
			e.preventDefault();
			doSearch( 1 );
		}
	} );

	// Load More button — load next page and append results.
	$( document ).on( 'click', '#clisyc-load-more-btn', function () {
		if ( isLoadingMore ) return;
		doSearch( currentPage + 1 );
	} );

	// ── View Rendering ─────────────────────────────────────────────

	function renderCurrentView( $container ) {
		if ( ! $container ) {
			$container = $( '#clisyc-checkin-results' );
		}
		if ( currentView === 'list' ) {
			renderDoorList( lastAppointments, $container );
		} else {
			renderCards( lastAppointments, $container );
		}

		// Append "Load More" button when there are more pages.
		$( '#clisyc-load-more-wrap' ).remove();
		if ( hasMorePages ) {
			$container.after(
				'<div id="clisyc-load-more-wrap" style="text-align:center;margin:16px 0;">' +
				'<button type="button" id="clisyc-load-more-btn" class="button">' +
				escHtml( strings.loadMore || 'Load More' ) +
				'</button>' +
				'</div>'
			);
		}
	}

	// ── Render Appointment Cards ────────────────────────────────────

	function renderCards( appointments, $container ) {
		let html = '';
		appointments.forEach( function ( appt ) {
			const checkedInClass = appt.is_checked_in ? ' clisyc-checkin-card--checked-in' : '';
			const statusClass = appt.is_checked_in ? 'checked-in' : ( appt.status === 'publish' || appt.status === 'confirmed' ? 'confirmed' : 'pending' );

			let seatsHtml = '';
			if ( appt.seat_details && appt.seat_details.length ) {
				seatsHtml = '<div class="clisyc-checkin-card__seats">';
				appt.seat_details.forEach( function ( seat ) {
					let label = seat.seat;
					if ( seat.row ) {
						label = seat.row + ' \u00B7 ' + seat.seat;
					}
					if ( seat.section ) {
						label = seat.section + ' \u00B7 ' + label;
					}
					seatsHtml += '<span class="clisyc-checkin-card__seat-tag">' + escHtml( label ) + '</span>';
				} );
				seatsHtml += '</div>';
			}

			let actionHtml;
			if ( appt.is_checked_in ) {
				actionHtml = '<div class="clisyc-checkin-card__action">' +
					'<span class="clisyc-checkin-card__checked-time">' +
					'<span class="dashicons dashicons-yes-alt"></span> ' +
					escHtml( strings.checkedIn ) +
					( appt.checked_in_at ? ' ' + escHtml( appt.checked_in_at ) : '' ) +
					'</span>' +
					'<button type="button" class="clisyc-undo-checkin-btn" data-appt-id="' + appt.id + '">' +
					escHtml( strings.undoCheckIn || 'Undo Check-In' ) +
					'</button></div>';
			} else {
				actionHtml = '<div class="clisyc-checkin-card__action">' +
					'<button type="button" class="clisyc-checkin-btn" data-appt-id="' + appt.id + '">' +
					'<span class="dashicons dashicons-yes"></span> ' +
					escHtml( strings.checkIn ) +
					'</button></div>';
			}

			html += '<div class="clisyc-checkin-card' + checkedInClass + '" data-appt-id="' + appt.id + '">' +
				'<div class="clisyc-checkin-card__info">' +
				'<div class="clisyc-checkin-card__name">' + escHtml( appt.client_name ) + '</div>' +
				( appt.event_name ? '<div class="clisyc-checkin-card__event">' + escHtml( appt.event_name ) + '</div>' : '' ) +
				'<div class="clisyc-checkin-card__meta">' +
				'<span class="clisyc-checkin-card__meta-item"><span class="dashicons dashicons-calendar-alt"></span> ' + escHtml( appt.date ) + '</span>' +
				'<span class="clisyc-checkin-card__meta-item"><span class="dashicons dashicons-clock"></span> ' + escHtml( appt.time ) + '</span>' +
				( appt.seat_count ? '<span class="clisyc-checkin-card__meta-item"><span class="dashicons dashicons-tickets-alt"></span> ' + appt.seat_count + ' ' + ( appt.seat_count > 1 ? strings.seats : strings.seat ) + '</span>' : '' ) +
				'</div>' +
				seatsHtml +
				'</div>' +
				'<span class="clisyc-checkin-card__status clisyc-checkin-card__status--' + statusClass + '">' + escHtml( appt.status_label ) + '</span>' +
				actionHtml +
				'</div>';
		} );
		$container.html( html );
	}

	// ── Render Door List (Table View) ──────────────────────────────

	function renderDoorList( appointments, $container ) {
		if ( ! appointments.length ) {
			$container.html( '<p class="clisyc-checkin-placeholder">' + strings.noResults + '</p>' );
			return;
		}

		// Sort by last name (approximate: split display_name, sort by last word).
		const sorted = [ ...appointments ].sort( function ( a, b ) {
			const aLast = ( a.client_name || '' ).trim().split( /\s+/ ).pop().toLowerCase();
			const bLast = ( b.client_name || '' ).trim().split( /\s+/ ).pop().toLowerCase();
			if ( aLast < bLast ) return -1;
			if ( aLast > bLast ) return 1;
			return 0;
		} );

		let html = '<table class="clisyc-door-list" id="clisyc-door-list">';
		html += '<thead><tr>' +
			'<th class="clisyc-door-list__th-check"><input type="checkbox" id="clisyc-select-all"></th>' +
			'<th>' + escHtml( strings.name ) + '</th>' +
			'<th>' + escHtml( strings.time ) + '</th>' +
			'<th>' + escHtml( strings.seats || 'Seats' ) + '</th>' +
			'<th>' + escHtml( strings.status ) + '</th>' +
			'<th></th>' +
			'</tr></thead><tbody>';

		sorted.forEach( function ( appt ) {
			const rowClass = appt.is_checked_in ? ' clisyc-door-list__row--checked-in' : '';

			// Seat summary.
			let seatText = '';
			if ( appt.seat_details && appt.seat_details.length ) {
				seatText = appt.seat_details.map( function ( s ) {
					let label = s.seat || s.seat_id || '';
					if ( s.row ) label = s.row + ' · ' + label;
					if ( s.section ) label = s.section + ' · ' + label;
					return label;
				} ).join( ', ' );
			} else if ( appt.seat_count ) {
				seatText = appt.seat_count + ' ' + ( appt.seat_count > 1 ? strings.seats : strings.seat );
			}

			// Action column.
			let actionHtml;
			if ( appt.is_checked_in ) {
				actionHtml = '<span class="clisyc-checkin-card__checked-time">' +
					'<span class="dashicons dashicons-yes-alt"></span> ' +
					( appt.checked_in_at ? escHtml( appt.checked_in_at ) : escHtml( strings.checkedIn ) ) +
					'</span>' +
					'<button type="button" class="clisyc-undo-checkin-btn clisyc-undo-checkin-btn--sm" data-appt-id="' + appt.id + '">' +
					escHtml( strings.undoCheckIn || 'Undo Check-In' ) +
					'</button>';
			} else {
				actionHtml = '<button type="button" class="clisyc-checkin-btn clisyc-checkin-btn--sm" data-appt-id="' + appt.id + '">' +
					escHtml( strings.checkIn ) +
					'</button>';
			}

			html += '<tr class="clisyc-door-list__row' + rowClass + '" data-appt-id="' + appt.id + '">' +
				'<td class="clisyc-door-list__td-check">' +
				( appt.is_checked_in ? '' : '<input type="checkbox" class="clisyc-bulk-checkbox" value="' + appt.id + '">' ) +
				'</td>' +
				'<td class="clisyc-door-list__td-name">' +
				'<strong>' + escHtml( appt.client_name ) + '</strong>' +
				( appt.event_name ? '<br><span class="clisyc-door-list__event">' + escHtml( appt.event_name ) + '</span>' : '' ) +
				( appt.client_email ? '<br><span class="clisyc-door-list__email">' + escHtml( appt.client_email ) + '</span>' : '' ) +
				'</td>' +
				'<td>' + escHtml( appt.time ) + '</td>' +
				'<td>' + escHtml( seatText ) + '</td>' +
				'<td><span class="clisyc-checkin-card__status clisyc-checkin-card__status--' +
				( appt.is_checked_in ? 'checked-in' : ( appt.status === 'publish' || appt.status === 'confirmed' ? 'confirmed' : 'pending' ) ) +
				'">' + escHtml( appt.status_label ) + '</span></td>' +
				'<td>' + actionHtml + '</td>' +
				'</tr>';
		} );

		html += '</tbody></table>';
		$container.html( html );

		updateBulkBtnState();
	}

	// ── View Toggle ────────────────────────────────────────────────

	$( document ).on( 'click', '.clisyc-view-toggle', function () {
		const view = $( this ).data( 'view' );
		if ( view === currentView ) return;

		currentView = view;
		$( '.clisyc-view-toggle' ).removeClass( 'clisyc-view-toggle--active' );
		$( this ).addClass( 'clisyc-view-toggle--active' );

		// Show/hide bulk actions.
		if ( view === 'list' ) {
			$( '#clisyc-bulk-actions' ).show();
		} else {
			$( '#clisyc-bulk-actions' ).hide();
		}

		// Re-render if we have data.
		if ( lastAppointments.length ) {
			renderCurrentView();
		}
	} );

	// ── Check-In Button Click ───────────────────────────────────────

	$( document ).on( 'click', '.clisyc-checkin-btn', function () {
		const $btn = $( this );
		const apptId = $btn.data( 'appt-id' );

		$btn.prop( 'disabled', true ).text( strings.checking );

		$.post( ajaxUrl, {
			action: 'clisyc_checkin_confirm',
			nonce: confirmNonce,
			appointment_id: apptId,
		}, function ( resp ) {
			if ( resp.success ) {
				// Sound + haptic + visual feedback.
				playSuccessSound();
				triggerHaptic( 'success' );

				// Update cached data.
				updateAppointmentInCache( resp.data.appointment );

				if ( currentView === 'cards' ) {
					const $card = $btn.closest( '.clisyc-checkin-card' );
					$card.addClass( 'clisyc-checkin-card--checked-in' );
					flashFeedback( $card, 'success' );
					$card.find( '.clisyc-checkin-card__status' )
						.removeClass( 'clisyc-checkin-card__status--confirmed clisyc-checkin-card__status--pending' )
						.addClass( 'clisyc-checkin-card__status--checked-in' )
						.text( strings.checkedIn );
					$btn.closest( '.clisyc-checkin-card__action' ).html(
						checkedInHtml( apptId, resp.data.checked_in_at )
					);
				} else {
					// In list view, re-render the row.
					const $row = $( '.clisyc-door-list__row[data-appt-id="' + apptId + '"]' );
					$row.addClass( 'clisyc-door-list__row--checked-in' );
					flashFeedback( $row, 'success' );
					$row.find( '.clisyc-door-list__td-check' ).html( '' );
					$row.find( '.clisyc-checkin-card__status' )
						.removeClass( 'clisyc-checkin-card__status--confirmed clisyc-checkin-card__status--pending' )
						.addClass( 'clisyc-checkin-card__status--checked-in' )
						.text( strings.checkedIn );
					$row.find( 'td:last' ).html(
						checkedInListHtml( apptId, resp.data.checked_in_at )
					);
					updateBulkBtnState();
				}

				// Refresh stats.
				fetchStats();
			} else {
				playErrorSound();
				triggerHaptic( 'error' );
				$btn.prop( 'disabled', false ).text( strings.checkIn );
				if ( currentView === 'cards' ) {
					flashFeedback( $btn.closest( '.clisyc-checkin-card' ), 'error' );
				}
				alert( resp.data.message || strings.error );
			}
		} ).fail( function () {
			playErrorSound();
			triggerHaptic( 'error' );
			$btn.prop( 'disabled', false ).text( strings.checkIn );
			alert( strings.error );
		} );
	} );

	// ── Undo Check-In ─────────────────────────────────────────────

	$( document ).on( 'click', '.clisyc-undo-checkin-btn', function () {
		const $btn = $( this );
		const apptId = $btn.data( 'appt-id' );

		$btn.prop( 'disabled', true ).text( strings.undoing || 'Undoing…' );

		$.post( ajaxUrl, {
			action: 'clisyc_checkin_undo',
			nonce: undoNonce,
			appointment_id: apptId,
		}, function ( resp ) {
			if ( resp.success ) {
				// Update cached data.
				updateAppointmentInCache( resp.data.appointment );

				if ( currentView === 'cards' ) {
					const $card = $btn.closest( '.clisyc-checkin-card' );
					$card.removeClass( 'clisyc-checkin-card--checked-in' );
					$card.find( '.clisyc-checkin-card__status' )
						.removeClass( 'clisyc-checkin-card__status--checked-in' )
						.addClass( 'clisyc-checkin-card__status--confirmed' )
						.text( resp.data.appointment.status_label );
					$btn.closest( '.clisyc-checkin-card__action' ).html(
						'<button type="button" class="clisyc-checkin-btn" data-appt-id="' + apptId + '">' +
						'<span class="dashicons dashicons-yes"></span> ' +
						escHtml( strings.checkIn ) +
						'</button>'
					);
				} else {
					// Re-render the whole list to restore the checkbox + button.
					renderCurrentView();
				}

				fetchStats();
			} else {
				$btn.prop( 'disabled', false ).text( strings.undoCheckIn || 'Undo Check-In' );
				alert( resp.data.message || strings.error );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( strings.undoCheckIn || 'Undo Check-In' );
			alert( strings.error );
		} );
	} );

	// ── Bulk Check-In ──────────────────────────────────────────────

	$( document ).on( 'change', '#clisyc-select-all', function () {
		const isChecked = $( this ).prop( 'checked' );
		$( '.clisyc-bulk-checkbox' ).prop( 'checked', isChecked );
		updateBulkBtnState();
	} );

	$( document ).on( 'change', '.clisyc-bulk-checkbox', function () {
		updateBulkBtnState();
	} );

	function updateBulkBtnState() {
		const count = $( '.clisyc-bulk-checkbox:checked' ).length;
		$( '#clisyc-bulk-checkin-btn' ).prop( 'disabled', count === 0 );
		if ( count > 0 ) {
			$( '#clisyc-bulk-checkin-btn' ).html(
				'<span class="dashicons dashicons-yes-alt"></span> ' +
				escHtml( strings.checkInSelected ) + ' (' + count + ')'
			);
		} else {
			$( '#clisyc-bulk-checkin-btn' ).html(
				'<span class="dashicons dashicons-yes-alt"></span> ' +
				escHtml( strings.checkInSelected )
			);
		}
	}

	$( '#clisyc-bulk-checkin-btn' ).on( 'click', function () {
		const ids = [];
		$( '.clisyc-bulk-checkbox:checked' ).each( function () {
			ids.push( parseInt( $( this ).val(), 10 ) );
		} );

		if ( ! ids.length ) return;

		const $btn = $( this );
		$btn.prop( 'disabled', true ).text( strings.checking );

		$.post( ajaxUrl, {
			action: 'clisyc_checkin_bulk',
			nonce: bulkNonce,
			appointment_ids: JSON.stringify( ids ),
		}, function ( resp ) {
			if ( resp.success ) {
				playSuccessSound();
				triggerHaptic( 'success' );

				// Update cached data for all checked-in appointments.
				ids.forEach( function ( id ) {
					const idx = lastAppointments.findIndex( function ( a ) { return a.id === id; } );
					if ( idx !== -1 ) {
						lastAppointments[ idx ].is_checked_in = true;
						lastAppointments[ idx ].status = 'clisyc_checked_in';
						lastAppointments[ idx ].status_label = strings.checkedIn;
					}
				} );

				// Re-render.
				renderCurrentView();
				fetchStats();
				alert( resp.data.message );
			} else {
				playErrorSound();
				triggerHaptic( 'error' );
				alert( resp.data.message || strings.error );
			}
			$btn.prop( 'disabled', false ).html(
				'<span class="dashicons dashicons-yes-alt"></span> ' +
				escHtml( strings.checkInSelected )
			);
		} ).fail( function () {
			alert( strings.error );
			$btn.prop( 'disabled', false ).html(
				'<span class="dashicons dashicons-yes-alt"></span> ' +
				escHtml( strings.checkInSelected )
			);
		} );
	} );

	// ── CSV Export ──────────────────────────────────────────────────

	$( '#clisyc-export-csv-btn' ).on( 'click', function () {
		if ( ! lastAppointments.length ) return;

		const date = $( '#clisyc-checkin-date' ).val() || 'all';
		const rows = [
			[ 'Name', 'Email', 'Date', 'Time', 'Seats', 'Status', 'Checked In At' ],
		];

		lastAppointments.forEach( function ( appt ) {
			let seatText = '';
			if ( appt.seat_details && appt.seat_details.length ) {
				seatText = appt.seat_details.map( function ( s ) {
					let label = s.seat || s.seat_id || '';
					if ( s.row ) label = s.row + ' · ' + label;
					if ( s.section ) label = s.section + ' · ' + label;
					return label;
				} ).join( '; ' );
			} else if ( appt.seat_count ) {
				seatText = appt.seat_count + '';
			}

			rows.push( [
				appt.client_name || '',
				appt.client_email || '',
				appt.date || '',
				appt.time || '',
				seatText,
				appt.status_label || '',
				appt.checked_in_at || '',
			] );
		} );

		// Build CSV string.
		const csv = rows.map( function ( row ) {
			return row.map( function ( val ) {
				// Escape quotes and wrap in quotes.
				return '"' + String( val ).replace( /"/g, '""' ) + '"';
			} ).join( ',' );
		} ).join( '\r\n' );

		const blob = new Blob( [ '\uFEFF' + csv ], { type: 'text/csv;charset=utf-8;' } );
		const link = document.createElement( 'a' );
		link.href = URL.createObjectURL( blob );
		link.download = 'door-list-' + date + '.csv';
		link.click();
		URL.revokeObjectURL( link.href );
	} );

	// ── Print ───────────────────────────────────────────────────────

	$( '#clisyc-print-btn' ).on( 'click', function () {
		window.print();
	} );

	// ── QR Scanner ──────────────────────────────────────────────────

	$( '#clisyc-scanner-toggle' ).on( 'click', function () {
		const $container = $( '#clisyc-scanner-container' );
		const $result = $( '#clisyc-scanner-result' );

		if ( scannerRunning ) {
			stopScanner();
			$container.slideUp( 200 );
			$( this ).html( '<span class="dashicons dashicons-camera"></span> ' + strings.startCamera );
			return;
		}

		$container.slideDown( 200 );
		$result.hide();
		$( this ).html( '<span class="dashicons dashicons-no"></span> ' + strings.stopCamera );
		startScanner();
	} );

	function startScanner() {
		const readerId = 'clisyc-scanner-reader';
		scanner = new Html5Qrcode( readerId );

		scanner.start(
			{ facingMode: 'environment' },
			{ fps: 10, qrbox: { width: 250, height: 250 } },
			onScanSuccess,
			function () {} // Ignore scan failures (continuous scanning).
		).then( function () {
			scannerRunning = true;
		} ).catch( function ( err ) {
			console.error( 'Scanner start failed:', err );
			showScanResult( strings.scanError, 'error' );
		} );
	}

	function stopScanner() {
		if ( scanner && scannerRunning ) {
			scanner.stop().then( function () {
				scanner.clear();
				scannerRunning = false;
			} ).catch( function () {
				scannerRunning = false;
			} );
		}
	}

	function onScanSuccess( decodedText ) {
		// Pause scanning briefly to prevent duplicate reads.
		if ( scanner && scannerRunning ) {
			scanner.pause( true );
		}

		// Extract token from URL: ?clisyc_checkin=TOKEN
		let token = '';
		try {
			const url = new URL( decodedText );
			token = url.searchParams.get( 'clisyc_checkin' ) || '';
		} catch ( e ) {
			// Not a URL — try using the raw text as a token.
			token = decodedText;
		}

		if ( ! token ) {
			showScanResult( strings.invalidQr, 'error' );
			resumeScannerAfterDelay();
			return;
		}

		// Check in by token.
		$.post( ajaxUrl, {
			action: 'clisyc_checkin_by_token',
			nonce: tokenNonce,
			token: token,
		}, function ( resp ) {
			if ( resp.success ) {
				playSuccessSound();
				triggerHaptic( 'success' );

				const appt = resp.data.appointment;
				showScanResult(
					'<div class="clisyc-scanner-result__name">' + escHtml( appt.client_name ) + '</div>' +
					( appt.seat_count ? '<div class="clisyc-scanner-result__seats">' + appt.seat_count + ' ' + ( appt.seat_count > 1 ? strings.seats : strings.seat ) + '</div>' : '' ) +
					'<div class="clisyc-scanner-result__message">\u2705 ' + escHtml( resp.data.message ) + '</div>',
					'success'
				);
				// Update card/row in results if visible.
				updateAppointmentInCache( appt );
				updateCardInResults( appt );
				fetchStats();
			} else {
				const appt = resp.data.appointment;
				const type = resp.data.already_checked_in ? 'already' : 'error';

				if ( type === 'already' ) {
					playErrorSound();
					triggerHaptic( 'error' );
				} else {
					playErrorSound();
					triggerHaptic( 'error' );
				}

				let html = '<div class="clisyc-scanner-result__message">' + escHtml( resp.data.message ) + '</div>';
				if ( appt ) {
					html = '<div class="clisyc-scanner-result__name">' + escHtml( appt.client_name ) + '</div>' + html;
				}
				showScanResult( html, type );
			}
			resumeScannerAfterDelay();
		} ).fail( function () {
			playErrorSound();
			triggerHaptic( 'error' );
			showScanResult( strings.error, 'error' );
			resumeScannerAfterDelay();
		} );
	}

	function showScanResult( html, type ) {
		const $result = $( '#clisyc-scanner-result' );
		$result.removeClass( 'clisyc-scanner-result--success clisyc-scanner-result--error clisyc-scanner-result--already' )
			.addClass( 'clisyc-scanner-result--' + type )
			.html( html )
			.slideDown( 200 );
	}

	function resumeScannerAfterDelay() {
		setTimeout( function () {
			if ( scanner && scannerRunning ) {
				scanner.resume();
			}
			$( '#clisyc-scanner-result' ).slideUp( 200 );
		}, 3000 );
	}

	function updateCardInResults( appt ) {
		const $card = $( '.clisyc-checkin-card[data-appt-id="' + appt.id + '"]' );
		if ( $card.length ) {
			$card.addClass( 'clisyc-checkin-card--checked-in' );
			$card.find( '.clisyc-checkin-card__status' )
				.removeClass( 'clisyc-checkin-card__status--confirmed clisyc-checkin-card__status--pending' )
				.addClass( 'clisyc-checkin-card__status--checked-in' )
				.text( strings.checkedIn );
			$card.find( '.clisyc-checkin-card__action' ).html(
				checkedInHtml( appt.id, appt.checked_in_at )
			);
		}

		// Update list row too.
		const $row = $( '.clisyc-door-list__row[data-appt-id="' + appt.id + '"]' );
		if ( $row.length ) {
			$row.addClass( 'clisyc-door-list__row--checked-in' );
			$row.find( '.clisyc-door-list__td-check' ).html( '' );
			$row.find( '.clisyc-checkin-card__status' )
				.removeClass( 'clisyc-checkin-card__status--confirmed clisyc-checkin-card__status--pending' )
				.addClass( 'clisyc-checkin-card__status--checked-in' )
				.text( strings.checkedIn );
			$row.find( 'td:last' ).html(
				checkedInListHtml( appt.id, appt.checked_in_at )
			);
		}
	}

	function updateAppointmentInCache( appt ) {
		const idx = lastAppointments.findIndex( function ( a ) { return a.id === appt.id; } );
		if ( idx !== -1 ) {
			lastAppointments[ idx ] = appt;
		}
	}

	// ── Checked-in HTML Helpers ──────────────────────────────────────

	/**
	 * Build the "Checked In at HH:MM  [Undo]" HTML used in cards and rows.
	 */
	function checkedInHtml( apptId, checkedInAt ) {
		return '<span class="clisyc-checkin-card__checked-time">' +
			'<span class="dashicons dashicons-yes-alt"></span> ' +
			escHtml( strings.checkedIn ) +
			( checkedInAt ? ' ' + escHtml( checkedInAt ) : '' ) +
			'</span>' +
			'<button type="button" class="clisyc-undo-checkin-btn" data-appt-id="' + apptId + '">' +
			escHtml( strings.undoCheckIn || 'Undo Check-In' ) +
			'</button>';
	}

	function checkedInListHtml( apptId, checkedInAt ) {
		return '<span class="clisyc-checkin-card__checked-time">' +
			'<span class="dashicons dashicons-yes-alt"></span> ' +
			( checkedInAt ? escHtml( checkedInAt ) : escHtml( strings.checkedIn ) ) +
			'</span>' +
			'<button type="button" class="clisyc-undo-checkin-btn clisyc-undo-checkin-btn--sm" data-appt-id="' + apptId + '">' +
			escHtml( strings.undoCheckIn || 'Undo Check-In' ) +
			'</button>';
	}

	// ── Helpers ──────────────────────────────────────────────────────

	function escHtml( str ) {
		if ( ! str ) return '';
		const div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}
} )( jQuery );
