/**
 * File: src/assets/src/PostNode.js
 */
import React, { memo } from 'react';
import { Handle, Position } from '@xyflow/react';

const PostNode = memo( ( { data, isConnectable } ) => {
	const handleLinkClick = ( event ) => {
		event.stopPropagation();
	};

	return (
		<div
			className="clisyc-post-node"
			data-filterorder={ data.filterOrder }
			data-totalfilters={ data.totalFilters }
		>
			<Handle
				type="target"
				position={ Position.Top }
				isConnectable={ isConnectable }
				id="a"
				className="clisyc-target-handle"
			/>

			<div className="clisyc-post-node-header">
				<span
					className={ `clisyc-node-icon dashicons ${
						data.parentIcon || 'dashicons-admin-generic'
					}` }
				></span>
				<span className="clisyc-post-node-parent-label">
					{ data.filterOrder }. { data.parentLabel }
				</span>
			</div>
			<div className="clisyc-post-node-label">
				<a
					href={ data.editUrl }
					target="_blank"
					rel="noopener noreferrer"
					onClick={ handleLinkClick }
				>
					{ data.label }
				</a>
			</div>

			<Handle
				type="source"
				position={ Position.Bottom }
				isConnectable={ isConnectable }
				id="b"
				className="clisyc-source-handle"
			/>
		</div>
	);
} );

export default PostNode;
