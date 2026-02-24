<?php
/**
 * File: src/shared/includes/admin/class-graph-node-manager.php -> client-sync/includes/admin/class-graph-node-manager.php
 * Manages the custom database table that indexes all nodes for the visual graph.
 *
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Graph_Node_Manager {

    private $nodes_table;

    public function __construct() {
        global $wpdb;
        // *** REFACTORED ***: Updated table name prefix from cs_ to clisyc_.
        $this->nodes_table = $wpdb->prefix . 'clisyc_graph_nodes';
    }

    public function register_hooks() {
        // When a post in a dimension CPT is saved or updated.
        add_action( 'save_post', [ $this, 'handle_save_post' ], 99, 2 );
        
        // When a post in a dimension CPT is deleted.
        add_action( 'before_delete_post', [ $this, 'handle_delete_post' ], 10, 1 );

        // When the dimension registry itself is updated (e.g., enabling/disabling a type).
        // *** REFACTORED ***: Updated option name in the hook.
        add_action( 'update_option_clisyc_dimension_registry', [ $this, 'handle_registry_update' ], 10, 3 );
    }

    /**
     * Rebuilds the entire node index from scratch.
     * Useful for initial setup, after imports, or as a repair tool.
     */
    public function rebuild_index() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely constructed from prefix.
        $wpdb->query( "TRUNCATE TABLE {$this->nodes_table}" );

        // *** REFACTORED ***: Updated option name.
        $registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
        $all_cpts = get_post_types( [ 'show_ui' => true ], 'objects' );
        $enabled_dimensions = $registry['dimensions'] ?? [];

        foreach ( $enabled_dimensions as $slug => $settings ) {
            if ( ! empty( $settings['enabled'] ) && isset( $all_cpts[ $slug ] ) ) {
                // Add the main dimension type node.
                $this->update_dimension_node( $slug, $all_cpts[ $slug ]->labels->name );

                // Add all child post nodes for this dimension.
                $posts = get_posts([
                    'post_type'      => $slug,
                    'posts_per_page' => -1,
                    'post_status'    => 'publish',
                    'fields'         => 'ids',
                ]);
                foreach ( $posts as $post_id ) {
                    $this->update_post_node( $post_id, get_the_title( $post_id ), $slug );
                }
            }
        }
    }

    /**
     * Hook callback for when a post is saved.
     */
    public function handle_save_post( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // *** REFACTORED ***: Updated option name.
        $registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
        $enabled_dimensions = $registry['dimensions'] ?? [];

        if ( isset( $enabled_dimensions[ $post->post_type ] ) && $enabled_dimensions[ $post->post_type ]['enabled'] ) {
            if ( 'publish' === $post->post_status ) {
                $this->update_post_node( $post_id, $post->post_title, $post->post_type );
            } else {
                // If it's not published (e.g., moved to trash, draft), remove it from the index.
                $this->delete_post_node( $post_id );
            }
        }
    }

    /**
     * Hook callback for when a post is about to be deleted.
     */
    public function handle_delete_post( $post_id ) {
        $post_type = get_post_type( $post_id );
        // *** REFACTORED ***: Updated option name.
        $registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
        $enabled_dimensions = $registry['dimensions'] ?? [];

        if ( isset( $enabled_dimensions[ $post_type ] ) && $enabled_dimensions[ $post_type ]['enabled'] ) {
            $this->delete_post_node( $post_id );
        }
    }

    /**
     * Hook callback for when the dimension settings are changed.
     */
    public function handle_registry_update( $old_value, $value, $option ) {
        $this->rebuild_index(); // The simplest and most reliable way is to just rebuild the whole index.
    }

    private function update_dimension_node( $cpt_slug, $label ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->replace(
            $this->nodes_table,
            [
                'node_hash'    => $cpt_slug,
                'cpt_slug'     => $cpt_slug,
                'wp_post_id'   => 0,
                'node_type'    => 'dimension',
                'label'        => $label,
                'parent_hash'  => null,
                'last_updated' => current_time( 'mysql' ),
            ]
        );
    }

    private function update_post_node( $post_id, $title, $cpt_slug ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->replace(
            $this->nodes_table,
            [
                'node_hash'    => $cpt_slug . ':' . $post_id,
                'cpt_slug'     => $cpt_slug,
                'wp_post_id'   => $post_id,
                'node_type'    => 'postNode',
                'label'        => $title,
                'parent_hash'  => $cpt_slug,
                'last_updated' => current_time( 'mysql' ),
            ]
        );
    }

    private function delete_post_node( $post_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $this->nodes_table, [ 'wp_post_id' => $post_id ] );
    }
}