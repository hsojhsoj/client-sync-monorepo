/**
 * SeatHoldTimer — Countdown timer for seat holds with auto-refresh.
 *
 * Shows a countdown to hold expiry. Automatically calls the refresh endpoint
 * when 30 seconds remain. Fires onExpired callback when time runs out.
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export default function SeatHoldTimer( { venueId, slotId, sessionToken, expiresIn, onExpired, onRefreshed } ) {
	const [ secondsLeft, setSecondsLeft ] = useState( expiresIn || 300 );
	const refreshedRef = useRef( false );
	const intervalRef = useRef( null );

	const refreshHolds = useCallback( async () => {
		if ( refreshedRef.current ) return;
		refreshedRef.current = true;

		try {
			const result = await apiFetch( {
				path: '/clisyc/v1/seats/refresh',
				method: 'POST',
				data: {
					venue_id: venueId,
					slot_id: slotId,
					session_token: sessionToken,
				},
			} );

			if ( result.expires_in ) {
				setSecondsLeft( result.expires_in );
				refreshedRef.current = false;
				if ( onRefreshed ) onRefreshed( result.expires_in );
			}
		} catch ( err ) {
			// Refresh failed — timer continues counting down.
			refreshedRef.current = false;
		}
	}, [ venueId, slotId, sessionToken, onRefreshed ] );

	useEffect( () => {
		intervalRef.current = setInterval( () => {
			setSecondsLeft( ( prev ) => {
				const next = prev - 1;

				if ( next <= 0 ) {
					clearInterval( intervalRef.current );
					if ( onExpired ) onExpired();
					return 0;
				}

				// Auto-refresh at 30 seconds remaining.
				if ( next === 30 ) {
					refreshHolds();
				}

				return next;
			} );
		}, 1000 );

		return () => clearInterval( intervalRef.current );
	}, [ onExpired, refreshHolds ] );

	// Reset timer when expiresIn prop changes (e.g. after refresh).
	useEffect( () => {
		if ( expiresIn && expiresIn > 0 ) {
			setSecondsLeft( expiresIn );
			refreshedRef.current = false;
		}
	}, [ expiresIn ] );

	const minutes = Math.floor( secondsLeft / 60 );
	const seconds = secondsLeft % 60;
	const isExpiring = secondsLeft <= 60;

	return (
		<div className={ `clisyc-hold-timer${ isExpiring ? ' clisyc-hold-timer--expiring' : '' }` }>
			<span>
				{ __( 'Seats held for:', 'client-sync-pro' ) }{ ' ' }
				<strong>{ minutes }:{ String( seconds ).padStart( 2, '0' ) }</strong>
			</span>
		</div>
	);
}
