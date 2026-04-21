/**
 * File: src/assets/src/booking-form/TimezoneMap.js
 * A compact world map component showing UTC timezone bands with region highlighting.
 *
 * @package
 */
import React, { useMemo } from 'react';

/**
 * Map timezone identifiers to their UTC offset
 */
const TIMEZONE_UTC_OFFSETS = {
	// UTC
	UTC: 0,

	// Americas (West to East)
	'Pacific/Honolulu': -10,
	'America/Anchorage': -9,
	'America/Los_Angeles': -8,
	'America/Phoenix': -7,
	'America/Denver': -7,
	'America/Chicago': -6,
	'America/Mexico_City': -6,
	'America/New_York': -5,
	'America/Toronto': -5,
	'America/Bogota': -5,
	'America/Lima': -5,
	'America/Caracas': -4,
	'America/Halifax': -4,
	'America/Sao_Paulo': -3,
	'America/Buenos_Aires': -3,
	'America/Santiago': -4,

	// Europe & Africa
	'Atlantic/Reykjavik': 0,
	'Europe/London': 0,
	'Europe/Lisbon': 0,
	'Africa/Casablanca': 0,
	'Europe/Paris': 1,
	'Europe/Berlin': 1,
	'Europe/Rome': 1,
	'Europe/Madrid': 1,
	'Europe/Amsterdam': 1,
	'Africa/Lagos': 1,
	'Europe/Athens': 2,
	'Africa/Cairo': 2,
	'Africa/Johannesburg': 2,
	'Europe/Helsinki': 2,
	'Europe/Moscow': 3,
	'Europe/Istanbul': 3,
	'Africa/Nairobi': 3,

	// Middle East & Asia
	'Asia/Dubai': 4,
	'Asia/Karachi': 5,
	'Asia/Kolkata': 5.5,
	'Asia/Dhaka': 6,
	'Asia/Bangkok': 7,
	'Asia/Jakarta': 7,
	'Asia/Ho_Chi_Minh': 7,
	'Asia/Singapore': 8,
	'Asia/Hong_Kong': 8,
	'Asia/Shanghai': 8,
	'Asia/Taipei': 8,
	'Asia/Manila': 8,
	'Asia/Tokyo': 9,
	'Asia/Seoul': 9,

	// Australia & Pacific
	'Australia/Perth': 8,
	'Australia/Darwin': 9.5,
	'Australia/Adelaide': 9.5,
	'Australia/Brisbane': 10,
	'Australia/Sydney': 10,
	'Australia/Melbourne': 10,
	'Pacific/Guam': 10,
	'Pacific/Auckland': 12,
	'Pacific/Fiji': 12,
};

/**
 * Get UTC offset for a timezone, handling 'local' specially
 * @param timezone
 */
const getUtcOffset = ( timezone ) => {
	if ( ! timezone || timezone === 'local' ) {
		const offsetMinutes = new Date().getTimezoneOffset();
		return -offsetMinutes / 60;
	}
	return TIMEZONE_UTC_OFFSETS[ timezone ] ?? 0;
};

/**
 * Calculate the X position (0-100%) for a UTC offset on the map
 * @param offset
 */
const getPositionForOffset = ( offset ) => {
	const minOffset = -11;
	const maxOffset = 12;
	const range = maxOffset - minOffset;
	const position = ( ( offset - minOffset ) / range ) * 100;
	return Math.max( 0, Math.min( 100, position ) );
};

/**
 * Get display name for UTC offset
 * EXPORTED so App.js can use it
 * @param offset
 */
export const getOffsetLabel = ( offset ) => {
	if ( offset === 0 ) {
		return 'UTC';
	}
	const sign = offset > 0 ? '+' : '';
	const hours = Math.floor( Math.abs( offset ) );
	const minutes = ( Math.abs( offset ) % 1 ) * 60;
	if ( minutes > 0 ) {
		return `UTC${ sign }${ offset > 0 ? hours : -hours }:${ minutes
			.toString()
			.padStart( 2, '0' ) }`;
	}
	return `UTC${ sign }${ offset }`;
};

/**
 * Get the UTC offset label for a timezone string
 * EXPORTED so App.js can use it directly with a timezone
 * @param timezone
 */
export const getTimezoneOffsetLabel = ( timezone ) => {
	const offset = getUtcOffset( timezone );
	return getOffsetLabel( offset );
};

/**
 * Get the map image URL
 * @param propUrl
 */
const getMapImageUrl = ( propUrl ) => {
	if ( propUrl ) {
		return propUrl;
	}

	const phpData = window.clisycBookingFormData || {};
	if ( phpData.timezoneMapUrl ) {
		return phpData.timezoneMapUrl;
	}
	if ( phpData.pluginUrl ) {
		return `${ phpData.pluginUrl }assets/images/timezone-map.png`;
	}

	return '/wp-content/plugins/client-sync/assets/images/timezone-map.png';
};

/**
 * TimezoneMap Component
 * @param root0
 * @param root0.selectedTimezone
 * @param root0.size
 * @param root0.showLabel
 * @param root0.mapImageUrl
 */
const TimezoneMap = ( {
	selectedTimezone = 'local',
	size = 'medium',
	showLabel = false, // Changed default to false - label now shown in header
	mapImageUrl = null,
} ) => {
	const utcOffset = useMemo(
		() => getUtcOffset( selectedTimezone ),
		[ selectedTimezone ]
	);
	const highlightPosition = useMemo(
		() => getPositionForOffset( utcOffset ),
		[ utcOffset ]
	);
	const offsetLabel = useMemo(
		() => getOffsetLabel( utcOffset ),
		[ utcOffset ]
	);
	const imageUrl = useMemo(
		() => getMapImageUrl( mapImageUrl ),
		[ mapImageUrl ]
	);

	// Size configurations - larger now that label is moved
	const sizeConfig = {
		small: { width: 180, height: 100 },
		medium: { width: 220, height: 120 },
		large: { width: 280, height: 150 },
	};

	const { width, height } = sizeConfig[ size ] || sizeConfig.medium;
	const bandWidth = 100 / 23;

	return (
		<div className="clisyc-timezone-map-wrapper">
			<div
				className="clisyc-timezone-map-container"
				style={ { width, height } }
			>
				<img
					src={ imageUrl }
					alt="World timezone map"
					className="clisyc-timezone-map-image"
					loading="eager"
					onError={ ( e ) => {
						e.target.style.display = 'none';
						console.warn(
							'TimezoneMap: Failed to load map image from',
							imageUrl
						);
					} }
				/>

				<svg
					className="clisyc-timezone-map-overlay"
					viewBox="0 0 100 100"
					preserveAspectRatio="none"
				>
					<rect
						x={ highlightPosition - bandWidth / 2 }
						y="0"
						width={ bandWidth }
						height="100"
						className="clisyc-timezone-highlight"
					/>
					<line
						x1={ highlightPosition }
						y1="0"
						x2={ highlightPosition }
						y2="100"
						className="clisyc-timezone-indicator-line"
					/>
				</svg>

				<div
					className="clisyc-timezone-indicator-dot"
					style={ { left: `${ highlightPosition }%` } }
				/>
			</div>

			{ showLabel && (
				<div className="clisyc-timezone-map-label">
					<span className="clisyc-map-indicator-dot" />
					<span className="clisyc-map-offset-label">
						{ offsetLabel }
					</span>
				</div>
			) }
		</div>
	);
};

export default TimezoneMap;
