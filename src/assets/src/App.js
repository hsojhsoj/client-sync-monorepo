/**
 * File: src/assets/src/App.js -> client-sync/assets/src/App.js
 * Added: "Add Node" button with modal for creating new dimension posts
 */
import React, { useState, useEffect, useCallback } from 'react';
import {
	ReactFlow,
	useNodesState,
	useEdgesState,
	addEdge,
	Controls,
	Background,
	useReactFlow,
	ReactFlowProvider,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import PostNode from './PostNode';
import apiFetch from '@wordpress/api-fetch';

const nodeTypes = { postNode: PostNode };

const NodeEditor = () => {
	const [ nodes, setNodes, onNodesChange ] = useNodesState( [] );
	const [ edges, setEdges, onEdgesChange ] = useEdgesState( [] );
	const { getNodes, getEdges, fitView } = useReactFlow();

	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ toast, setToast ] = useState( { show: false, message: '' } );

	// Add Node Modal State
	const [ showAddModal, setShowAddModal ] = useState( false );
	const [ newNodeTitle, setNewNodeTitle ] = useState( '' );
	const [ selectedDimension, setSelectedDimension ] = useState( '' );
	const [ availableDimensions, setAvailableDimensions ] = useState( [] );
	const [ isCreatingNode, setIsCreatingNode ] = useState( false );

	const onConnect = useCallback(
		( params ) => {
			const newEdge = {
				...params,
				animated: true,
				style: {
					stroke: '#9ca3af',
					strokeWidth: 2,
					strokeDasharray: '5,5',
				},
			};
			setEdges( ( eds ) => addEdge( newEdge, eds ) );
		},
		[ setEdges ]
	);

	const handleConnectAll = useCallback( () => {
		const newEdges = [];
		const currentNodes = getNodes();
		const postNodes = currentNodes.filter(
			( node ) => node.type === 'postNode'
		);
		for ( let i = 0; i < postNodes.length; i++ ) {
			for ( let j = i + 1; j < postNodes.length; j++ ) {
				const nodeA = postNodes[ i ];
				const nodeB = postNodes[ j ];
				if ( nodeA.data.post_type !== nodeB.data.post_type ) {
					newEdges.push( {
						id: `e-${ nodeA.id }-${ nodeB.id }`,
						source: nodeA.id,
						target: nodeB.id,
						animated: true,
						style: {
							stroke: '#9ca3af',
							strokeWidth: 2,
							strokeDasharray: '5,5',
						},
					} );
				}
			}
		}
		setEdges( newEdges );
	}, [ getNodes, setEdges ] );

	const handleDisconnectAll = useCallback( () => {
		setEdges( [] );
	}, [ setEdges ] );

	const handleSaveChanges = useCallback( async () => {
		setIsSaving( true );
		try {
			const currentNodes = getNodes();
			const currentEdges = getEdges();

			await apiFetch( {
				path: '/clisyc/v1/dimensions/graph',
				method: 'POST',
				data: {
					nodes: currentNodes,
					edges: currentEdges,
				},
			} );
			setToast( { show: true, message: 'Changes saved successfully!' } );
		} catch ( error ) {
			console.error( 'Save error:', error );
			setToast( { show: true, message: 'Failed to save changes.' } );
		} finally {
			setIsSaving( false );
		}
	}, [ getNodes, getEdges ] );

	const handleAutoLayout = useCallback( () => {
		const currentNodes = getNodes();
		const nodesByFilterOrder = {};

		currentNodes.forEach( ( node ) => {
			const order = node.data.filterOrder;
			if ( order ) {
				if ( ! nodesByFilterOrder[ order ] ) {
					nodesByFilterOrder[ order ] = [];
				}
				nodesByFilterOrder[ order ].push( node );
			}
		} );

		const PADDING_X = 100;
		const ROW_SPACING = 150;
		const NODE_WIDTH = 180;
		const updatedNodes = [];
		const sortedOrders = Object.keys( nodesByFilterOrder ).sort(
			( a, b ) => a - b
		);

		sortedOrders.forEach( ( order, rowIndex ) => {
			const rowNodes = nodesByFilterOrder[ order ];
			const totalWidth =
				rowNodes.length * NODE_WIDTH +
				( rowNodes.length - 1 ) * PADDING_X;
			let currentX = -( totalWidth / 2 );

			rowNodes.forEach( ( node ) => {
				updatedNodes.push( {
					...node,
					position: { x: currentX, y: rowIndex * ROW_SPACING },
				} );
				currentX += NODE_WIDTH + PADDING_X;
			} );
		} );

		const unpositionedNodes = currentNodes.filter(
			( n ) => ! n.data.filterOrder
		);
		updatedNodes.push( ...unpositionedNodes );

		setNodes( updatedNodes );
		setTimeout( () => fitView( { duration: 400, padding: 0.2 } ), 50 );
	}, [ getNodes, setNodes, fitView ] );

	const handleZoomToFit = useCallback(
		() => fitView( { duration: 300 } ),
		[ fitView ]
	);

	// Load dimension types for the Add Node modal
	const loadDimensionTypes = useCallback( async () => {
		try {
			const registry = await apiFetch( {
				path: '/clisyc/v1/dimensions/registry',
			} );
			const types = await apiFetch( {
				path: '/clisyc/v1/dimensions/types',
			} );

			const dimensions = [];
			if ( registry && types ) {
				Object.keys( registry.dimensions || {} ).forEach( ( slug ) => {
					if (
						registry.dimensions[ slug ].enabled &&
						registry.dimensions[ slug ].frontend_visible
					) {
						dimensions.push( {
							slug,
							label: types[ slug ]?.plural || slug,
						} );
					}
				} );
			}
			setAvailableDimensions( dimensions );
		} catch ( error ) {
			console.error( 'Failed to load dimension types:', error );
		}
	}, [] );

	const handleAddNodeClick = useCallback( () => {
		loadDimensionTypes();
		setShowAddModal( true );
		setNewNodeTitle( '' );
		setSelectedDimension( '' );
	}, [ loadDimensionTypes ] );

	const handleCreateNode = useCallback( async () => {
		if ( ! newNodeTitle.trim() || ! selectedDimension ) {
			setToast( {
				show: true,
				message: 'Please enter a title and select a dimension type.',
			} );
			return;
		}

		setIsCreatingNode( true );
		try {
			// Create the new post using our custom endpoint
			const result = await apiFetch( {
				path: '/clisyc/v1/dimensions/create-post',
				method: 'POST',
				data: {
					post_type: selectedDimension,
					title: newNodeTitle.trim(),
				},
			} );

			if ( result.success ) {
				// Reload the graph data to include the new node
				const response = await apiFetch( {
					path: '/clisyc/v1/dimensions/graph',
				} );
				const { nodes: fetchedNodes, edges: fetchedEdges } = response;

				setNodes( fetchedNodes || [] );
				setTimeout( () => {
					setEdges( fetchedEdges || [] );
					setTimeout( () => {
						fitView( { duration: 300 } );
					}, 100 );
				}, 100 );

				setShowAddModal( false );
				setNewNodeTitle( '' );
				setSelectedDimension( '' );
				setToast( {
					show: true,
					message:
						result.message ||
						`Successfully created "${ newNodeTitle }"!`,
				} );
			} else {
				throw new Error( 'Failed to create node' );
			}
		} catch ( error ) {
			console.error( 'Failed to create node:', error );
			const errorMessage =
				error.message || 'Failed to create node. Please try again.';
			setToast( { show: true, message: errorMessage } );
		} finally {
			setIsCreatingNode( false );
		}
	}, [ newNodeTitle, selectedDimension, setNodes, setEdges, fitView ] );

	useEffect( () => {
		const fetchData = async () => {
			setIsLoading( true );
			try {
				const response = await apiFetch( {
					path: '/clisyc/v1/dimensions/graph',
				} );
				const { nodes: fetchedNodes, edges: fetchedEdges } = response;

				setNodes( fetchedNodes || [] );

				setTimeout( () => {
					setEdges( fetchedEdges || [] );

					setTimeout( () => {
						fitView( { duration: 300 } );
					}, 100 );
				}, 100 );
			} catch ( error ) {
				console.error( 'Failed to load graph data:', error );
			} finally {
				setIsLoading( false );
			}
		};
		fetchData();
	}, [ setNodes, setEdges, fitView ] );

	useEffect( () => {
		if ( toast.show ) {
			const timer = setTimeout( () => {
				setToast( { show: false, message: '' } );
			}, 3000 );
			return () => clearTimeout( timer );
		}
	}, [ toast.show ] );

	return (
		<div
			className="clisyc_node-editor-container"
			style={ { flexDirection: 'column' } }
		>
			{ toast.show && (
				<div className="clisyc_toast-notice">{ toast.message }</div>
			) }

			{ /* Add Node Modal */ }
			{ showAddModal && (
				<div
					className="clisyc-modal-overlay"
					onClick={ () => setShowAddModal( false ) }
				>
					<div
						className="clisyc-modal-content"
						onClick={ ( e ) => e.stopPropagation() }
					>
						<h2>Add New Node</h2>
						<div className="clisyc-modal-body">
							<p>
								<label htmlFor="dimension-select">
									<strong>Dimension Type:</strong>
								</label>
								<select
									id="dimension-select"
									value={ selectedDimension }
									onChange={ ( e ) =>
										setSelectedDimension( e.target.value )
									}
									disabled={ isCreatingNode }
								>
									<option value="">
										-- Select Dimension --
									</option>
									{ availableDimensions.map( ( dim ) => (
										<option
											key={ dim.slug }
											value={ dim.slug }
										>
											{ dim.label }
										</option>
									) ) }
								</select>
							</p>
							<p>
								<label htmlFor="node-title">
									<strong>Title:</strong>
								</label>
								<input
									type="text"
									id="node-title"
									value={ newNodeTitle }
									onChange={ ( e ) =>
										setNewNodeTitle( e.target.value )
									}
									onKeyPress={ ( e ) =>
										e.key === 'Enter' && handleCreateNode()
									}
									placeholder="Enter node title..."
									disabled={ isCreatingNode }
									autoFocus
								/>
							</p>
						</div>
						<div className="clisyc-modal-footer">
							<button
								className="button button-primary"
								onClick={ handleCreateNode }
								disabled={
									isCreatingNode ||
									! newNodeTitle.trim() ||
									! selectedDimension
								}
							>
								{ isCreatingNode
									? 'Creating...'
									: 'Create Node' }
							</button>
							<button
								className="button button-secondary"
								onClick={ () => setShowAddModal( false ) }
								disabled={ isCreatingNode }
							>
								Cancel
							</button>
						</div>
					</div>
				</div>
			) }

			<div
				className="clisyc_node-editor-wrapper"
				style={ { height: '73vh', position: 'relative' } }
			>
				<div className="clisyc_node-toolbar">
					<div className="clisyc_toolbar_group">
						<button
							className="button button-secondary"
							onClick={ handleAutoLayout }
						>
							Auto-Layout
						</button>
						<button
							className="button button-secondary"
							onClick={ handleZoomToFit }
						>
							Zoom to Fit
						</button>
						<button className="button" onClick={ handleConnectAll }>
							Connect All
						</button>
						<button
							className="button"
							onClick={ handleDisconnectAll }
						>
							Disconnect All
						</button>
						<button
							className="button"
							onClick={ handleAddNodeClick }
						>
							Add Node
						</button>
					</div>
					<button
						className="button button-primary"
						onClick={ handleSaveChanges }
						disabled={ isSaving }
					>
						{ isSaving ? 'Saving...' : 'Save Changes' }
					</button>
				</div>

				{ ( () => {
					if ( isLoading ) {
						return (
							<div className="clisyc-loading-spinner">
								<span className="dashicons dashicons-update spin"></span>
								<p>Loading graph data...</p>
							</div>
						);
					}
					if ( nodes.length === 0 ) {
						return (
							<div
								className="clisyc-empty-state"
								style={ {
									textAlign: 'center',
									padding: '50px',
								} }
							>
								<p>
									<em>
										Multiple dimensions with child posts are
										required for the Relationship Graph to
										display relationships.
									</em>
								</p>
								<p>
									Go to the{ ' ' }
									<a href="?page=clisyc-dimensions&tab=setup">
										System Setup
									</a>{ ' ' }
									tab to add dimensions and create child posts
									within them.
								</p>
							</div>
						);
					}
					return ( () => {
						const uniquePostTypes = new Set(
							nodes.map( ( node ) => node.data.post_type )
						);
						const dimensionCount = uniquePostTypes.size;
						if ( dimensionCount <= 1 ) {
							return (
								<div
									className="clisyc-empty-state"
									style={ {
										textAlign: 'center',
										padding: '50px',
									} }
								>
									<p>
										<em>
											With only one dimension registered,
											the Relationship Graph isn&apos;t
											useful as there are no hierarchies
											to display.
										</em>
									</p>
									<p>
										Add more dimensions on the{ ' ' }
										<a href="?page=clisyc-dimensions&tab=setup">
											System Setup
										</a>{ ' ' }
										tab to define relationships.
									</p>
									<p>
										<strong>Note:</strong> Only dimensions
										marked as &quot;Frontend Visible&quot;
										in System Setup will appear here and
										enable hierarchy configuration. Ensure
										at least two are visible if you expect
										to see the graph.
									</p>
								</div>
							);
						}
						return (
							<ReactFlow
								nodes={ nodes }
								edges={ edges }
								onNodesChange={ onNodesChange }
								onEdgesChange={ onEdgesChange }
								onConnect={ onConnect }
								nodeTypes={ nodeTypes }
								fitView
								deleteKeyCode={ [ 'Backspace', 'Delete' ] }
								style={ { border: '1px solid #ddd' } }
							>
								<Background />
								<Controls showFitView={ false } />
							</ReactFlow>
						);
					} )();
				} )() }
			</div>
		</div>
	);
};

const NodeEditorWrapper = () => (
	<ReactFlowProvider>
		<NodeEditor />
	</ReactFlowProvider>
);

export default NodeEditorWrapper;
