<?php
/**
 * File: src/shared/includes/rest-api/class-dimensions-controller.php
 * Defines REST API endpoints for fetching dimensional data.
 * FINAL SIMPLIFIED VERSION: Returns a flat list of all child nodes for a simpler graph.
 *
 * @package    ClientSync
 * @subpackage ClientSync/RestAPI
 */

namespace DependentMedia\ClientSync\RestAPI;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;
use DependentMedia\ClientSync\Utility\Security_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dimensions_Controller extends \WP_REST_Controller {

	protected $namespace = 'clisyc/v1';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/dimensions/graph',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_graph_data' ],
					'permission_callback' => [ $this, 'check_admin_permissions' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_graph_data' ],
					'permission_callback' => [ $this, 'check_admin_permissions' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/dimensions/registry',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_dimension_registry' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/dimensions/types',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_dimension_types' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/dimensions/posts',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_dimension_posts' ],
					'permission_callback' => '__return_true', // Public for frontend filters
					'args'                => [
						'type' => [
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						],
					],
				],
			]
		);

		// NEW: Endpoint for creating dimension posts
		register_rest_route(
			$this->namespace,
			'/dimensions/create-post',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_dimension_post' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
				'args'                => [
					'post_type' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'title'     => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	public function check_admin_permissions() {
		return current_user_can( 'manage_options' );
	}

	public function get_dimension_registry( $request ) {
		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		return new \WP_REST_Response( $registry, 200 );
	}

	public function get_dimension_types( $request ) {
		$types_raw = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
		
		// CRITICAL FIX: Convert associative array to numeric array for React .map()
		// The React code expects an array it can iterate over with .map()
		$types_array = [];
		
		if ( is_array( $types_raw ) && ! empty( $types_raw ) ) {
			foreach ( $types_raw as $slug => $type_data ) {
				$types_array[] = [
					'slug'     => $slug,
					'label'    => $type_data['plural'] ?? $type_data['singular'] ?? $slug,
					'singular' => $type_data['singular'] ?? $slug,
					'plural'   => $type_data['plural'] ?? $slug,
					'icon'     => $type_data['icon'] ?? 'dashicons-admin-generic',
				];
			}
		}
		
		return new \WP_REST_Response( $types_array, 200 );
	}

	public function create_dimension_post( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$title     = $request->get_param( 'title' );

		// Validate post type exists and is a dimension
		if ( ! post_type_exists( $post_type ) || strpos( $post_type, 'clisyc_' ) !== 0 ) {
			return new \WP_Error( 'invalid_post_type', __( 'Invalid dimension type.', 'client-sync' ), [ 'status' => 400 ] );
		}

		// Create the post
		$new_post_id = wp_insert_post(
			[
				'post_type'   => $post_type,
				'post_title'  => $title,
				'post_status' => 'publish',
			],
			true
		);

		if ( is_wp_error( $new_post_id ) ) {
			return new \WP_Error( 'creation_failed', $new_post_id->get_error_message(), [ 'status' => 500 ] );
		}

		// Trigger graph node index update
		if ( class_exists( '\DependentMedia\ClientSync\Admin\Graph_Node_Manager' ) ) {
			$graph_manager = new \DependentMedia\ClientSync\Admin\Graph_Node_Manager();
			$graph_manager->handle_save_post( $new_post_id, get_post( $new_post_id ), false );
		}

		return new \WP_REST_Response(
			[
				'success' => true,
				'post_id' => $new_post_id,
				/* translators: %s: The title of the newly created dimension item. */
				'message' => sprintf( __( 'Successfully created "%s".', 'client-sync' ), $title ),
			],
			201
		);
	}

	public function get_dimension_posts( $request ) {
		$rate_check = Security_Helper::check_rate_limit( 'dimension_posts', Constants::RATE_LIMIT_DIMENSION_POSTS, Constants::RATE_LIMIT_WINDOW_SECS );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$cpt_slug = $request->get_param( 'type' );

		if ( ! post_type_exists( $cpt_slug ) ) {
			return new \WP_Error( 'invalid_cpt', __( 'Invalid dimension type.', 'client-sync' ), [ 'status' => 404 ] );
		}

		// Only allow querying registered dimension CPTs (must start with clisyc_).
		if ( strpos( $cpt_slug, 'clisyc_' ) !== 0 ) {
			return new \WP_Error( 'invalid_cpt', __( 'Invalid dimension type.', 'client-sync' ), [ 'status' => 403 ] );
		}

		$posts = get_posts(
			[
				'post_type'      => $cpt_slug,
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$response_data = [];
		foreach ( $posts as $post ) {
			$response_data[] = [
				'value' => (string) $post->ID,
				'label' => $post->post_title,
				'text'  => $post->post_title, // THE FIX IS HERE
				'color' => get_post_meta( $post->ID, Constants::META_COLOR, true ),
			];
		}

		return new \WP_REST_Response( $response_data, 200 );
	}

	public function get_graph_data( $request ) {
		global $wpdb;
		$nodes_table = $wpdb->prefix . 'clisyc_graph_nodes';

		Debug_Logger::log( '=== GRAPH LOAD DEBUG START ===', 'Dimensions' );

		// 1. Get ALL nodes from the index table.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely constructed from internal property.
		$all_nodes_raw = $wpdb->get_results( "SELECT * FROM {$nodes_table}" );

		Debug_Logger::log( 'Raw nodes from database: ' . count( $all_nodes_raw ), 'Dimensions' );

		foreach ( $all_nodes_raw as $node ) {
			Debug_Logger::log( 'DB Node: ' . $node->node_hash . ' | Type: ' . $node->node_type . ' | Post ID: ' . $node->wp_post_id . ' | Label: ' . $node->label, 'Dimensions' );
		}

		$registry     = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$visual_state = get_option( Constants::OPTION_GRAPH_VISUAL_STATE, [] );
		$all_cpts     = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );

		Debug_Logger::log( 'Visual state type: ' . gettype( $visual_state ), 'Dimensions' );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		Debug_Logger::log( 'Visual state content: ' . print_r( $visual_state, true ), 'Dimensions' );

		$dimension_labels = [];
		foreach ( $all_cpts as $slug => $cpt_data ) {
			$dimension_labels[ $slug ] = $cpt_data['plural'] ?? $slug;
		}

		// 2. Process into a flat list of nodes for React Flow.
		$final_nodes   = [];
		$filter_order  = $registry['filter_order'] ?? [];
		$total_filters = count( $filter_order );

		Debug_Logger::log( 'Processing nodes for React Flow...', 'Dimensions' );

		foreach ( $all_nodes_raw as $node ) {
			if ( $node->node_type === 'postNode' ) {
				$node_state = $visual_state[ $node->node_hash ] ?? [];
				$position   = $node_state['position'] ?? [
					'x' => wp_rand( 50, 800 ),
					'y' => wp_rand( 50, 500 ),
				];
				$order      = array_search( $node->cpt_slug, $filter_order );
				$post_id    = (int) $node->wp_post_id;

				// Check if post still exists
				$post_exists = get_post( $post_id ) !== null;

				Debug_Logger::log( "Processing node {$node->node_hash}: Post exists: " . ( $post_exists ? 'YES' : 'NO' ), 'Dimensions' );

				if ( ! $post_exists ) {
					Debug_Logger::log( "SKIPPING node {$node->node_hash} - post no longer exists!", 'Dimensions' );
					continue;
				}

				$final_nodes[] = [
					'id'       => $node->node_hash,
					'type'     => 'postNode',
					'data'     => [
						'label'        => wp_specialchars_decode( $node->label, ENT_QUOTES ),
						'parentLabel'  => $dimension_labels[ $node->cpt_slug ] ?? $node->cpt_slug,
						'parentIcon'   => $all_cpts[ $node->cpt_slug ]['icon'] ?? 'dashicons-admin-generic',
						'filterOrder'  => false !== $order ? $order + 1 : 99,
						'totalFilters' => $total_filters,
						'post_id'      => $post_id,
						'post_type'    => $node->cpt_slug,
						'editUrl'      => get_edit_post_link( $post_id, 'raw' ),
					],
					'position' => $position,
				];

				Debug_Logger::log( "ADDED node {$node->node_hash} to final_nodes array", 'Dimensions' );
			}
		}

		Debug_Logger::log( 'Total final nodes to return: ' . count( $final_nodes ), 'Dimensions' );

		// 3. Get all child-to-child relationships.
		$rels_table    = $wpdb->prefix . 'clisyc_relationships';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely constructed.
		$relationships = $wpdb->get_results( "SELECT * FROM {$rels_table}" );

		Debug_Logger::log( 'Relationships from database: ' . count( $relationships ), 'Dimensions' );

		$final_edges = [];
		foreach ( $relationships as $rel ) {
			$source_id     = $rel->parent_object_type . ':' . $rel->parent_object_id;
			$target_id     = $rel->child_object_type . ':' . $rel->child_object_id;
			$final_edges[] = [
				'id'       => "e-{$source_id}-{$target_id}",
				'source'   => $source_id,
				'target'   => $target_id,
				'animated' => true,
				'style'    => [
					'stroke'          => '#9ca3af',
					'strokeWidth'     => 2,
					'strokeDasharray' => '5,5',
				],
			];

			Debug_Logger::log( "Edge: {$source_id} -> {$target_id}", 'Dimensions' );
		}

		$response_data = [
			'registry'     => $registry,
			'all_cpts'     => get_transient( 'clisyc_all_cpts_cache' ),
			'nodes'        => $final_nodes,
			'edges'        => $final_edges,
			'visual_state' => $visual_state,
		];

		Debug_Logger::log( '=== GRAPH LOAD DEBUG END ===', 'Dimensions' );
		
		return new \WP_REST_Response( $response_data, 200 );
	}

	public function update_graph_data( $request ) {
		$params = $request->get_json_params();
		global $wpdb;

		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );

		$new_visual_state = [];
		if ( isset( $params['nodes'] ) && is_array( $params['nodes'] ) ) {
			foreach ( $params['nodes'] as $node ) {
				if ( ! isset( $node['id'] ) || false === strpos( $node['id'], ':' ) ) {
					continue;
				}
				// Sanitize node ID (format: "cpt_slug:post_id").
				$safe_id = sanitize_text_field( $node['id'] );

				// Validate and sanitize position values (must be numeric).
				$position = isset( $node['position'] ) && is_array( $node['position'] ) ? $node['position'] : [];
				$safe_position = [
					'x' => isset( $position['x'] ) && is_numeric( $position['x'] ) ? (float) $position['x'] : 0,
					'y' => isset( $position['y'] ) && is_numeric( $position['y'] ) ? (float) $position['y'] : 0,
				];

				$new_visual_state[ $safe_id ] = [
					'position' => $safe_position,
				];
			}
		}

		update_option( Constants::OPTION_GRAPH_VISUAL_STATE, $new_visual_state );

		if ( isset( $params['registry']['filter_order'] ) && is_array( $params['registry']['filter_order'] ) ) {
			$registry['filter_order'] = array_map( 'sanitize_key', $params['registry']['filter_order'] );
		}

		update_option( Constants::OPTION_DIMENSION_REGISTRY, $registry );

		$rels_table_name = $wpdb->prefix . 'clisyc_relationships';

		// Wrap TRUNCATE + re-insert in a transaction so a mid-save failure
		// doesn't leave the relationships table empty.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely constructed from internal property.
			$wpdb->query( "TRUNCATE TABLE {$rels_table_name}" );

			if ( isset( $params['edges'] ) && is_array( $params['edges'] ) ) {
				$saved_relationships = []; // Track to avoid duplicates

				foreach ( $params['edges'] as $edge ) {
					if ( empty( $edge['source'] ) || empty( $edge['target'] ) ||
						false === strpos( $edge['source'], ':' ) || false === strpos( $edge['target'], ':' ) ) {
						continue;
					}

					list( $source_type, $source_id ) = explode( ':', $edge['source'] );
					list( $target_type, $target_id ) = explode( ':', $edge['target'] );

					// Sanitize type strings — only allow word characters (a-z, 0-9, underscore).
					$source_type = sanitize_key( $source_type );
					$target_type = sanitize_key( $target_type );

					$source_id = absint( $source_id );
					$target_id = absint( $target_id );

					if ( $source_id > 0 && $target_id > 0 ) {
						// Verify both posts exist
						if ( ! get_post( $source_id ) || ! get_post( $target_id ) ) {
							continue;
						}

						// FIXED: Preserve the actual direction - source is parent, target is child
						// Create unique key to check for duplicates in BOTH directions
						$key_forward = $source_id . '-' . $source_type . '-' . $target_id . '-' . $target_type;
						$key_reverse = $target_id . '-' . $target_type . '-' . $source_id . '-' . $source_type;

						// Skip if we've already saved this relationship in either direction
						if ( isset( $saved_relationships[ $key_forward ] ) || isset( $saved_relationships[ $key_reverse ] ) ) {
							continue;
						}

						// Save with source as parent, target as child (preserves direction)
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
						$wpdb->insert(
							$rels_table_name,
							[
								'parent_object_id'   => $source_id,
								'parent_object_type' => $source_type,
								'child_object_id'    => $target_id,
								'child_object_type'  => $target_type,
							],
							[ '%d', '%s', '%d', '%s' ]
						);

						$saved_relationships[ $key_forward ] = true;
					}
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'db_error', __( 'Failed to save graph relationships.', 'client-sync' ), [ 'status' => 500 ] );
		}

		return new \WP_REST_Response( [ 'success' => true, 'message' => __( 'Dimension graph updated.', 'client-sync' ) ], 200 );
	}
}