/**
 * File: src/assets/src/BookingStory.js -> client-sync/assets/src/BookingStory.js
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 */
import React from 'react';

const BookingStory = ( { nodes, edges, filterOrder } ) => {
	const primaryDimension = nodes.find(
		( node ) => node.data.isPrimary && node.type === 'dimension'
	);

	const enabledDimensions = nodes
		.filter( ( node ) => node.type === 'dimension' )
		.map( ( node ) => ( {
			slug: node.id,
			label: node.data.label,
		} ) );

	if ( enabledDimensions.length === 0 ) {
		// *** REFACTORED: Updated CSS class ***
		return (
			<div className="clisyc-booking-story-container">
				<p>
					No dimensions are currently enabled. Add a dimension to the
					graph to begin.
				</p>
			</div>
		);
	}

	const sortedDimensions = filterOrder
		.map( ( slug ) =>
			enabledDimensions.find( ( dim ) => dim.slug === slug )
		)
		.filter( Boolean );

	enabledDimensions.forEach( ( dim ) => {
		if (
			! sortedDimensions.find(
				( sortedDim ) => sortedDim.slug === dim.slug
			)
		) {
			sortedDimensions.push( dim );
		}
	} );

	// *** REFACTORED: Updated CSS class ***
	return (
		<div className="clisyc-booking-story-container">
			<p>
				Based on your current settings, a customer&apos;s booking
				process will be:
			</p>
			{ sortedDimensions.length > 0 ? (
				<ol>
					{ sortedDimensions.map( ( dim, index ) => (
						<li key={ dim.slug }>
							{ index === 0 ? 'First, they' : 'Next, they' } will
							choose a <strong>{ dim.label }</strong>.
						</li>
					) ) }
				</ol>
			) : (
				<p>
					<em>
						Add dimensions to the graph to define the booking
						process.
					</em>
				</p>
			) }

			{ primaryDimension ? (
				<p>
					The system will then show available time slots based on the
					schedule set on the{ ' ' }
					<strong>{ primaryDimension.data.label }</strong>.
				</p>
			) : (
				// *** REFACTORED: Updated CSS class ***
				<p className="clisyc-story-warning">
					<strong>Warning:</strong> No primary dimension is set. The
					system will not know which schedule to use for generating
					time slots. Double-click a dimension node to make it
					primary.
				</p>
			) }
		</div>
	);
};

export default BookingStory;
