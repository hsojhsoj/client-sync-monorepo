// Updated assets/src/DimensionNode.js
/**
 * File: src/assets/src/DimensionNode.js -> client-sync/assets/src/DimensionNode.js
 * Dimension Type Node Component
 * REFACTORED: Now displays child count and an 'organize' button when expanded.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 * MODIFIED: Removed unused tooltip props.
 */

import React, { memo } from 'react';
import { Position } from '@xyflow/react';
import HandleWithTooltip from './HandleWithTooltip';

export default memo( ( { data, isConnectable } ) => {
	const nodeClasses = [ 'clisyc-dimension-node' ];
	if ( data.isPrimary ) {
		nodeClasses.push( 'primary' );
	}

	const handleDoubleClick = ( e ) => {
		e.stopPropagation();
		if ( ! data.isPrimary ) {
			data.onSetPrimary( data.slug );
		}
	};

	const handleExpandToggle = ( e ) => {
		e.stopPropagation();
		data.onExpandToggle( data.slug, ! data.isExpanded );
	};

	const handleOrganizeChildren = ( e ) => {
		e.stopPropagation();
		data.onOrganizeChildren( data.slug );
	};

	const childCountLabel =
		data.childCount > 0 ? ` (${ data.childCount })` : '';

	return (
		<div
			className={ nodeClasses.join( ' ' ) }
			onDoubleClick={ handleDoubleClick }
			title="Double-click to make this the primary dimension"
		>
			<HandleWithTooltip
				type="target"
				position={ Position.Left }
				isConnectable={ isConnectable }
			/>

			<div className="clisyc-node-header">
				<span
					className={ `clisyc-node-icon dashicons ${
						data.icon || 'dashicons-admin-generic'
					}` }
				></span>
				{ data.isPrimary && (
					<span
						className="clisyc-node-primary-star"
						title="Primary Dimension"
					>
						★
					</span>
				) }
				<a
					href={ data.admin_url }
					target="_blank"
					rel="noopener noreferrer"
					className="clisyc-node-label"
					onClick={ ( e ) => e.stopPropagation() }
				>
					{ data.label }
					{ childCountLabel }
				</a>

				{ data.isExpanded && data.childCount > 0 && (
					<button
						className="clisyc-node-button organize-children"
						onClick={ handleOrganizeChildren }
						title="Organize child nodes"
					>
						<span className="dashicons dashicons-layout"></span>
					</button>
				) }
				<button
					className="clisyc-node-button expand-toggle"
					onClick={ handleExpandToggle }
					title={
						data.isExpanded
							? 'Hide child items'
							: 'Show child items'
					}
				>
					<span
						className={ `dashicons ${
							data.isExpanded
								? 'dashicons-visibility'
								: 'dashicons-hidden'
						}` }
					></span>
				</button>
			</div>
			<div className="clisyc-node-slug">{ data.slug }</div>

			<HandleWithTooltip
				type="source"
				position={ Position.Right }
				isConnectable={ isConnectable }
			/>
		</div>
	);
} );
