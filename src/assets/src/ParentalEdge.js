/**
 * File: src/assets/src/ParentalEdge.js -> client-sync/assets/src/ParentalEdge.js
 * A custom React Flow edge component for rendering the non-interactive, dashed
 * line that connects a parent DimensionNode to its expanded child PostNodes.
 */
import React from 'react';
import { BaseEdge, getSmoothStepPath } from '@xyflow/react';

function ParentalEdge( {
	id,
	sourceX,
	sourceY,
	targetX,
	targetY,
	sourcePosition,
	targetPosition,
} ) {
	const [ edgePath ] = getSmoothStepPath( {
		sourceX,
		sourceY,
		sourcePosition,
		targetX,
		targetY,
		targetPosition,
	} );

	return (
		<BaseEdge
			id={ id }
			path={ edgePath }
			style={ {
				stroke: '#b1b1b7',
				strokeWidth: 1,
				strokeDasharray: '5,5', // Make it dashed
			} }
		/>
	);
}

export default React.memo( ParentalEdge );
