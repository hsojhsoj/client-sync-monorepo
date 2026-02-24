<?php
/**
 * File: src/shared/includes/services/class-importer.php -> client-sync/includes/services/class-importer.php
 * The unified Importer service class.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Services
 */

namespace DependentMedia\ClientSync\Services;

// *** REFACTORED ***: Updated use statements for new namespaces
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Admin\Dimension_CPT_Manager;
use DependentMedia\ClientSync\Admin\Graph_Node_Manager;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Importer {

    private function log( $message ) {
        Debug_Logger::log( $message, 'Importer' );
    }

    public function install_from_data( array $data ) {
        $this->log( '--- Starting Import Process ---' );
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
        $this->log( 'Raw data received: ' . print_r( $data, true ) );

        $import_data = null;
        if ( isset( $data[0] ) && is_array( $data[0] ) ) {
            $import_data = $data[0];
            $this->log( 'Detected template file format (array with one object).' );
        } elseif ( isset( $data['options'] ) && isset( $data['cpts'] ) ) {
            $import_data = $data;
            $this->log( 'Detected direct export file format (single object).' );
        }

        if ( ! is_array( $import_data ) || ! isset( $import_data['options'], $import_data['cpts'] ) ) {
            $error_msg = 'Import data is invalid or missing required "options" or "cpts" keys.';
            $this->log( 'ERROR: ' . $error_msg );
            return new \WP_Error( 'invalid_format', $error_msg );
        }
        
        // Validate import data size to prevent memory exhaustion.
        $json_size = strlen( wp_json_encode( $import_data ) );
        $max_import_size = apply_filters( 'clisyc_max_import_size_bytes', 5 * 1024 * 1024 ); // 5MB default.
        if ( $json_size > $max_import_size ) {
            $error_msg = sprintf( 'Import data exceeds maximum allowed size (%s). Received %s.', size_format( $max_import_size ), size_format( $json_size ) );
            $this->log( 'ERROR: ' . $error_msg );
            return new \WP_Error( 'import_too_large', $error_msg );
        }

        $this->log( 'Data format appears valid and has been processed.' );

        // Create a rollback snapshot before wiping existing data.
        $this->_create_backup();
        $this->_wipe_existing_data();

        // --- START: NEW PAGE CREATION LOGIC ---
        $page_id_map = $this->_import_pages( $import_data['pages'] ?? [] );
        // --- END: NEW PAGE CREATION LOGIC ---

        $options_to_import = $import_data['options'] ?? [];
        $visual_state_to_import = $options_to_import[ Constants::OPTION_GRAPH_VISUAL_STATE ] ?? null;
        unset( $options_to_import[ Constants::OPTION_GRAPH_VISUAL_STATE ] );

        if ( isset( $options_to_import[ Constants::OPTION_CUSTOM_DIMENSION_TYPES ] ) ) {
            update_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, $options_to_import[ Constants::OPTION_CUSTOM_DIMENSION_TYPES ] );
            $this->log( 'Saved `clisyc_custom_dimension_types` option.' );
        } else {
            $this->log( 'WARNING: `clisyc_custom_dimension_types` not found in import options.' );
        }

        if ( isset( $options_to_import[ Constants::OPTION_DIMENSION_FIELDS ] ) ) {
            update_option( Constants::OPTION_DIMENSION_FIELDS, $options_to_import[ Constants::OPTION_DIMENSION_FIELDS ] );
            $this->log( 'Saved `clisyc_dimension_fields` option.' );
        }
        $cpt_manager = new Dimension_CPT_Manager();
        $cpt_manager->register_custom_dimensions_cpts();
        $this->log( 'Programmatically registered CPTs for this request.' );

        if ( is_array( $options_to_import ) ) {
            foreach ( $options_to_import as $key => $value ) {
                if ( in_array( $key, [ Constants::OPTION_CUSTOM_DIMENSION_TYPES, Constants::OPTION_DIMENSION_FIELDS ], true ) ) {
                    continue;
                }
                // Only import options with our plugin prefix to prevent overwriting unrelated options.
                if ( strpos( $key, 'clisyc_' ) !== 0 ) {
                    $this->log( "SKIPPED option '{$key}': does not have 'clisyc_' prefix." );
                    continue;
                }
                // --- START: NEW PLACEHOLDER REPLACEMENT LOGIC ---
                if ( is_string( $value ) && str_starts_with( $value, '{{page_' ) ) {
                    $page_slug = str_replace( [ '{{page_', '}}' ], '', $value );
                    if ( isset( $page_id_map[ $page_slug ] ) ) {
                        $original_placeholder = $value;
                        $value = $page_id_map[ $page_slug ];
                        $this->log( "Replaced placeholder '{$original_placeholder}' with new page ID {$value} for option '{$key}'." );
                    }
                }
                // --- END: NEW PLACEHOLDER REPLACEMENT LOGIC ---
                update_option( $key, $value );
                $this->log( "Saved option: {$key}" );
            }
        }
        
        flush_rewrite_rules();
        $this->log( 'Flushed rewrite rules.' );

        $import_maps = $this->_import_cpt_posts( $import_data['cpts'] ?? [] );

        if ( $visual_state_to_import && is_array( $visual_state_to_import ) ) {
            $translated_visual_state = [];
            $old_id_to_new_id_map = $import_maps['old_id_to_new_id'] ?? [];

            foreach ( $visual_state_to_import as $node_hash => $state ) {
                if ( strpos( $node_hash, ':' ) !== false ) {
                    list($cpt_slug, $old_id) = explode( ':', $node_hash );
                    
                    if ( isset( $old_id_to_new_id_map[ $old_id ] ) ) {
                        $new_id = $old_id_to_new_id_map[ $old_id ];
                        $new_hash = $cpt_slug . ':' . $new_id;
                        $translated_visual_state[ $new_hash ] = $state;
                        $this->log( "Translated visual state for node {$node_hash} to new hash {$new_hash}." );
                    } else {
                         $this->log( "WARNING: Could not find new post ID for old visual state key {$node_hash}." );
                    }
                } else {
                    $translated_visual_state[ $node_hash ] = $state;
                }
            }
            update_option( Constants::OPTION_GRAPH_VISUAL_STATE, $translated_visual_state );
            $this->log( 'Saved translated `clisyc_graph_visual_state` option.' );
        }

        $this->_import_relationships( $import_data['relationships'] ?? [], $import_maps['slug_to_new_id'] ?? [] );

        // Link services to venues (Seat Selection module).
        $this->_import_venue_links( $import_data['venue_links'] ?? [], $import_maps['slug_to_new_id'] ?? [] );

        $graph_manager = new Graph_Node_Manager();
        $graph_manager->rebuild_index();
        $this->log( 'Rebuilt graph node index.' );

        // Import Output Templates (Pro feature — skips gracefully if CPT not registered).
        $this->_import_output_templates( $import_data['output_templates'] ?? [] );

        $this->log( '--- Import Process Finished ---' );
        return true;
    }

    private function _wipe_existing_data() {
        global $wpdb;
        $this->log( 'Starting data wipe...' );
        $custom_types    = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
        $all_plugin_cpts = is_array( $custom_types ) ? array_keys( $custom_types ) : [];

        // Also wipe non-dimension plugin CPTs that are managed by Pro modules.
        $extra_cpts = [ 'clisyc_member_plan', 'clisyc_output_tmpl' ];
        foreach ( $extra_cpts as $extra_cpt ) {
            if ( post_type_exists( $extra_cpt ) && ! in_array( $extra_cpt, $all_plugin_cpts, true ) ) {
                $all_plugin_cpts[] = $extra_cpt;
            }
        }

        $this->log( 'Found ' . count( $all_plugin_cpts ) . ' CPTs to wipe posts from.' );

        foreach ( $all_plugin_cpts as $cpt_slug ) {
            if ( empty( $cpt_slug ) || strpos( $cpt_slug, 'clisyc_' ) !== 0 ) continue;
            $deleted_count = 0;
            do {
                $batch = get_posts( [ 'post_type' => $cpt_slug, 'posts_per_page' => 100, 'post_status' => 'any', 'fields' => 'ids' ] );
                foreach ( $batch as $post_id ) {
                    wp_delete_post( $post_id, true );
                    $deleted_count++;
                }
            } while ( ! empty( $batch ) );
            $this->log( "  - Wiped {$deleted_count} posts from {$cpt_slug}." );
        }

        delete_option( Constants::OPTION_DIMENSION_REGISTRY );
        delete_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES );
        delete_option( Constants::OPTION_GRAPH_VISUAL_STATE );
        delete_option( Constants::OPTION_DIMENSION_FIELDS );

        $clisyc_db_manager = new Database_Manager();
        $clisyc_rels_table_name = $clisyc_db_manager->get_relationships_table_name();
        
        // Verify tables exist before truncating to prevent SQL errors on partial installations.
        $clisyc_rels_table_safe = $wpdb->prefix . 'clisyc_relationships';
        $clisyc_graph_table_safe = $wpdb->prefix . 'clisyc_graph_nodes';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $clisyc_rels_table_safe ) ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely constructed from prefix.
            $wpdb->query( "TRUNCATE TABLE {$clisyc_rels_table_safe}" );
            $this->log( 'Truncated relationships table.' );
        } else {
            $this->log( 'WARNING: Relationships table does not exist. Skipping truncate.' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $clisyc_graph_table_safe ) ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name is safely constructed from prefix.
            $wpdb->query( "TRUNCATE TABLE {$clisyc_graph_table_safe}" );
            $this->log( 'Truncated graph nodes table.' );
        } else {
            $this->log( 'WARNING: Graph nodes table does not exist. Skipping truncate.' );
        }
        $this->log( 'Data wipe complete.' );
    }

    /**
     * Creates a serialized backup of current plugin data before a destructive import.
     *
     * Stores options and dimension post counts in a timestamped wp_option so that
     * data can be reviewed or restored if an import goes wrong.
     */
    private function _create_backup(): void {
        $this->log( 'Creating pre-import backup snapshot...' );

        $options_to_backup = [
            Constants::OPTION_DIMENSION_REGISTRY,
            Constants::OPTION_CUSTOM_DIMENSION_TYPES,
            Constants::OPTION_GRAPH_VISUAL_STATE,
            Constants::OPTION_DIMENSION_FIELDS,
        ];

        $snapshot = [
            'timestamp' => gmdate( 'Y-m-d H:i:s' ),
            'options'   => [],
            'post_counts' => [],
        ];

        foreach ( $options_to_backup as $opt_key ) {
            $snapshot['options'][ $opt_key ] = get_option( $opt_key, null );
        }

        // Record how many posts existed per CPT (for audit, not full post backup).
        $custom_types = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
        if ( is_array( $custom_types ) ) {
            foreach ( array_keys( $custom_types ) as $cpt_slug ) {
                if ( empty( $cpt_slug ) || strpos( $cpt_slug, 'clisyc_' ) !== 0 ) {
                    continue;
                }
                $count = wp_count_posts( $cpt_slug );
                $snapshot['post_counts'][ $cpt_slug ] = $count ? (array) $count : [];
            }
        }

        $backup_key = 'clisyc_import_backup_' . gmdate( 'Ymd_His' );
        update_option( $backup_key, $snapshot, false );
        $this->log( "Backup saved as option '{$backup_key}'." );

        // Prune old backups — keep only the 5 most recent.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $old_backups = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE 'clisyc_import_backup_%'
             ORDER BY option_name DESC
             LIMIT 100"
        );

        if ( count( $old_backups ) > 5 ) {
            foreach ( array_slice( $old_backups, 5 ) as $old_key ) {
                delete_option( $old_key );
                $this->log( "Pruned old backup: {$old_key}" );
            }
        }
    }

    // --- START: NEW METHOD TO IMPORT PAGES ---
    private function _import_pages( array $pages_data ): array {
        $id_map = [];
        $this->log( 'Starting page import...' );

        if ( empty( $pages_data ) ) {
            $this->log( 'No pages to import.' );
            return [];
        }

        foreach ( $pages_data as $page_data ) {
            $slug = $page_data['post_name'] ?? '';
            if ( empty( $slug ) ) continue;

            $existing_page = get_page_by_path( $slug, OBJECT, 'page' );
            if ( $existing_page ) {
                $id_map[ $slug ] = $existing_page->ID;
                $this->log( "  - Found existing page '{$slug}' with ID {$existing_page->ID}." );
                continue;
            }

            $new_page_id = wp_insert_post( [
                'post_type'    => 'page',
                'post_name'    => sanitize_title( $slug ),
                'post_title'   => sanitize_text_field( $page_data['post_title'] ?? ucfirst( $slug ) ),
                'post_content' => wp_kses_post( $page_data['post_content'] ?? '' ),
                'post_status'  => 'publish',
            ] );

            if ( is_wp_error( $new_page_id ) ) {
                $this->log( "  - ERROR: Failed to create page '{$slug}': " . $new_page_id->get_error_message() );
            } elseif ( $new_page_id > 0 ) {
                $id_map[ $slug ] = $new_page_id;
                $this->log( "  - SUCCESS: Created page '{$slug}' with new ID {$new_page_id}." );
            } else {
                $this->log( "  - ERROR: Failed to create page '{$slug}'." );
            }
        }
        $this->log( 'Finished page import.' );
        return $id_map;
    }
    // --- END: NEW METHOD ---

    private function _import_cpt_posts( array $cpts_data ): array {
        $slug_to_new_id_map = [];
        $old_id_to_new_id_map = [];
        $this->log( 'Starting CPT post import...' );

        foreach ( $cpts_data as $cpt_slug => $posts ) {
            $this->log( "  - Processing CPT: {$cpt_slug}" );
            // Only import our own CPTs to prevent injection of arbitrary post types.
            if ( strpos( $cpt_slug, 'clisyc_' ) !== 0 ) {
                $this->log( "    - SKIPPED: CPT '{$cpt_slug}' does not have 'clisyc_' prefix." );
                continue;
            }
            if ( ! post_type_exists( $cpt_slug ) ) {
                $this->log( "    - ERROR: Post type '{$cpt_slug}' does not exist. Skipping." );
                continue;
            }
            if ( ! is_array( $posts ) ) {
                $this->log( "    - WARNING: No posts found for '{$cpt_slug}'. Skipping." );
                continue;
            }

            foreach ( $posts as $post_data ) {
                $old_slug = $post_data['post_name'] ?? sanitize_title( $post_data['post_title'] ?? '' );
                $original_id = $post_data['original_id'] ?? null;

                $meta_input_for_post = [];
                if ( isset( $post_data['post_meta'] ) && is_array( $post_data['post_meta'] ) ) {
                    foreach ( $post_data['post_meta'] as $meta_key => $meta_values ) {
                        if ( strpos( $meta_key, '_clisyc_' ) === 0 ) {
                            $value_to_unserialize = is_array( $meta_values ) ? ( $meta_values[0] ?? null ) : $meta_values;
                            // Guard against PHP object injection via crafted serialized strings.
                            if ( is_string( $value_to_unserialize ) && preg_match( '/[OC]:\d+:"/', $value_to_unserialize ) ) {
                                $this->log( "    - WARNING: Skipped meta '{$meta_key}' — contains serialized object." );
                                continue;
                            }
                            $value = maybe_unserialize( $value_to_unserialize );

                            // Template files store complex values as JSON strings.
                            // Decode them for meta keys that downstream code expects
                            // to be PHP arrays (WordPress will re-serialize on save).
                            if ( is_string( $value ) && in_array( $meta_key, [
                                Constants::META_VENUE_LAYOUT,
                                Constants::META_SEAT_PRICING_TIERS,
                            ], true ) ) {
                                $decoded = json_decode( $value, true );
                                if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                                    $value = $decoded;
                                }
                            }

                            $meta_input_for_post[ $meta_key ] = $value;
                        }
                    }
                }

                $new_post_id = wp_insert_post( [
                    'post_type'    => $cpt_slug,
                    'post_name'    => sanitize_title( $old_slug ),
                    'post_title'   => sanitize_text_field( $post_data['post_title'] ?? '' ),
                    'post_content' => wp_kses_post( $post_data['post_content'] ?? '' ),
                    'post_excerpt' => sanitize_text_field( $post_data['post_excerpt'] ?? '' ),
                    'post_status'  => 'publish',
                    'meta_input'   => $meta_input_for_post,
                ], true );

                if ( is_wp_error( $new_post_id ) ) {
                    $this->log( "    - ERROR inserting post '{$old_slug}': " . $new_post_id->get_error_message() );
                } else {
                    $this->log( "    - SUCCESS: Inserted post '{$old_slug}' with new ID {$new_post_id} (Original ID was: {$original_id})." );
                    if ( $old_slug ) {
                        $slug_to_new_id_map[ $cpt_slug . '::' . $old_slug ] = $new_post_id;
                    }
                    if ( $original_id ) {
                        $old_id_to_new_id_map[ $original_id ] = $new_post_id;
                    }
                }
            }
        }
        $this->log( 'Finished CPT post import.' );
        return [
            'slug_to_new_id' => $slug_to_new_id_map,
            'old_id_to_new_id' => $old_id_to_new_id_map,
        ];
    }
    
    private function _import_relationships( array $relationships_data, array $slug_to_id_map ) {
        global $wpdb;
        $db_manager = new Database_Manager();
        $rels_table_name = $db_manager->get_relationships_table_name();
        $this->log( 'Starting relationship import...' );

        $inserted_relationships = []; // Track to prevent duplicates

        foreach ( $relationships_data as $rel ) {
            $parent_key = ( $rel['parent_type'] ?? '' ) . '::' . ( $rel['parent_slug'] ?? '' );
            $child_key  = ( $rel['child_type'] ?? '' ) . '::' . ( $rel['child_slug'] ?? '' );

            if ( isset( $slug_to_id_map[ $parent_key ] ) && isset( $slug_to_id_map[ $child_key ] ) ) {
                $new_parent_id = $slug_to_id_map[ $parent_key ];
                $new_child_id  = $slug_to_id_map[ $child_key ];
                
                // Create a unique key for this relationship
                $unique_key = $new_parent_id . '-' . $rel['parent_type'] . '-' . $new_child_id . '-' . $rel['child_type'];
                
                // Only insert if we haven't inserted this relationship yet
                if ( ! isset( $inserted_relationships[ $unique_key ] ) ) {
                    // Insert only ONE directional relationship (parent -> child)
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $result = $wpdb->insert( $rels_table_name, [
                        'parent_object_id'   => $new_parent_id,
                        'parent_object_type' => $rel['parent_type'],
                        'child_object_id'    => $new_child_id,
                        'child_object_type'  => $rel['child_type']
                    ]);
                    
                    if ( $result ) {
                        $inserted_relationships[ $unique_key ] = true;
                        $this->log( "  - Inserted relationship: {$rel['parent_type']}:{$new_parent_id} -> {$rel['child_type']}:{$new_child_id}" );
                    } else {
                        $this->log( "  - ERROR inserting relationship: {$wpdb->last_error}" );
                    }
                } else {
                    $this->log( "  - SKIPPED duplicate relationship: {$unique_key}" );
                }
            } else {
                $this->log( "  - SKIPPING relationship: Could not map parent '{$parent_key}' or child '{$child_key}' to a new ID." );
            }
        }
        $this->log( 'Finished relationship import.' );
    }

    /**
     * Links services (dimensions) to venues via post meta.
     *
     * Uses the slug_to_new_id map built during CPT import to resolve
     * service and venue post IDs from their slugs.
     *
     * @param array $venue_links Array of { service_slug, venue_slug } pairs.
     * @param array $slug_to_id_map Mapping of 'cpt_slug::post_slug' => new_post_id.
     */
    private function _import_venue_links( array $venue_links, array $slug_to_id_map ): void {
        if ( empty( $venue_links ) ) {
            $this->log( 'No venue links to import.' );
            return;
        }

        $this->log( 'Starting venue link import (' . count( $venue_links ) . ' links)...' );

        foreach ( $venue_links as $link ) {
            $service_slug = $link['service_slug'] ?? '';
            $venue_slug   = $link['venue_slug'] ?? '';

            if ( empty( $service_slug ) || empty( $venue_slug ) ) {
                $this->log( '  - SKIPPED: Venue link with missing service_slug or venue_slug.' );
                continue;
            }

            // Find the service — try the primary dimension type first, fall back to common patterns.
            $service_new_id = null;
            $service_cpt    = '';
            foreach ( $slug_to_id_map as $key => $id ) {
                if ( str_ends_with( $key, '::' . $service_slug ) && ! str_contains( $key, 'clisyc_venue' ) ) {
                    $service_new_id = $id;
                    $service_cpt    = explode( '::', $key )[0];
                    break;
                }
            }

            // Find the venue.
            $venue_key    = 'clisyc_venue::' . $venue_slug;
            $venue_new_id = $slug_to_id_map[ $venue_key ] ?? null;

            if ( ! $service_new_id ) {
                $this->log( "  - SKIPPED: Could not find service '{$service_slug}' in slug map." );
                continue;
            }
            if ( ! $venue_new_id ) {
                $this->log( "  - SKIPPED: Could not find venue '{$venue_slug}' in slug map." );
                continue;
            }

            update_post_meta( $service_new_id, Constants::META_LINKED_VENUE_ID, $venue_new_id );
            $this->log( "  - SUCCESS: Linked {$service_cpt}:{$service_new_id} ('{$service_slug}') to venue:{$venue_new_id} ('{$venue_slug}')." );
        }

        $this->log( 'Finished venue link import.' );
    }

    /**
     * Imports Output Template posts from the template data.
     *
     * Requires the Pro plugin to be active (clisyc_output_tmpl CPT registered).
     * Skips templates that already exist (matched by title) to prevent duplicates.
     *
     * @param array $templates Array of output template definitions.
     */
    private function _import_output_templates( array $templates ): void {
        if ( empty( $templates ) ) {
            $this->log( 'No output templates to import.' );
            return;
        }

        if ( ! post_type_exists( 'clisyc_output_tmpl' ) ) {
            $this->log( 'Output Template CPT not registered (Pro not active). Skipping output template import.' );
            return;
        }

        $this->log( 'Starting output template import (' . count( $templates ) . ' templates)...' );

        foreach ( $templates as $tmpl ) {
            $title = $tmpl['post_title'] ?? '';
            if ( empty( $title ) ) {
                $this->log( '  - SKIPPED: Output template with no title.' );
                continue;
            }

            // Check for existing template with same title to prevent duplicates.
            $existing = get_posts( [
                'post_type'      => 'clisyc_output_tmpl',
                'title'          => $title,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ] );

            if ( ! empty( $existing ) ) {
                $this->log( "  - SKIPPED: Output template '{$title}' already exists (ID: {$existing[0]})." );
                continue;
            }

            $new_post_id = wp_insert_post( [
                'post_type'   => 'clisyc_output_tmpl',
                'post_title'  => $title,
                'post_status' => 'publish',
            ] );

            if ( is_wp_error( $new_post_id ) ) {
                $this->log( "  - ERROR: Failed to create output template '{$title}': " . $new_post_id->get_error_message() );
                continue;
            }

            // Save template JSON.
            if ( ! empty( $tmpl['clisyc_template_json'] ) ) {
                update_post_meta( $new_post_id, 'clisyc_template_json', $tmpl['clisyc_template_json'] );
            }

            // Save triggers.
            if ( ! empty( $tmpl['clisyc_triggers'] ) && is_array( $tmpl['clisyc_triggers'] ) ) {
                update_post_meta( $new_post_id, 'clisyc_triggers', $tmpl['clisyc_triggers'] );
            }

            // Save channels.
            if ( ! empty( $tmpl['clisyc_channels'] ) && is_array( $tmpl['clisyc_channels'] ) ) {
                update_post_meta( $new_post_id, 'clisyc_channels', $tmpl['clisyc_channels'] );
            }

            $this->log( "  - SUCCESS: Created output template '{$title}' with ID {$new_post_id}." );
        }

        $this->log( 'Finished output template import.' );
    }
}