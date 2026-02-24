/**
 * File: src/assets/src/ChildPostNode.js -> client-sync/assets/src/ChildPostNode.js
*/

import React, { memo } from 'react';
import { Handle, Position } from 'reactflow';

export default memo(({ data, isConnectable }) => {
    return (
        <div className="clisyc-child-post-node">
            <Handle type="target" position={Position.Top} isConnectable={isConnectable} />
            <div className="clisyc-child-node-label">{data.label}</div>
            <Handle type="source" position={Position.Bottom} isConnectable={isConnectable} />
        </div>
    );
});