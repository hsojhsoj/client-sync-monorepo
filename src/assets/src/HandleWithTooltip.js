/**
 * File: src/assets/src/HandleWithTooltip.js -> client-sync/assets/src/HandleWithTooltip.js
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 * FIX: Restored required React Flow classes directly to the Handle component.
 */
import React from 'react';
import { Handle } from '@xyflow/react';

// This component is now a simple wrapper to ensure all handles are consistent.
// The tooltip functionality has been removed, but the core functionality is restored.
const HandleWithTooltip = ( props ) => {
	// The Handle component from React Flow needs its classes to be positioned correctly.
	// The wrapper div was removed as it was causing the issue.
	return <Handle { ...props } />;
};

export default HandleWithTooltip;
