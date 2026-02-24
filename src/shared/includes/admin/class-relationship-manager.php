<?php
/**
 * File: src/shared/includes/admin/class-relationship-manager.php -> client-sync/includes/admin/class-relationship-manager.php
 * Handles the rendering and saving of relationships between dimension CPTs.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class Relationship_Manager {

    /**
     * Renders meta boxes for any defined relationships on a CPT edit screen.
     *
     * @param \WP_Post $post The post object being edited.
     * @param array $registry The dimension registry settings.
     */
    public static function render_relationship_meta_boxes( \WP_Post $post, array $registry ) {
        $parent_cpt = $post->post_type;
        $defined_relationships = $registry['relationships'][ $parent_cpt ] ?? [];

        if ( empty( $defined_relationships ) ) {
            return; // This CPT has no defined child relationships.
        }

        foreach ( $defined_relationships as $child_cpt_slug ) {
            $child_cpt_object = get_post_type_object( $child_cpt_slug );
            if ( ! $child_cpt_object ) continue;

            // *** REFACTORED ***: Prefixed meta box ID
            add_meta_box(
                'clisyc-relationship-' . $child_cpt_slug,
                /* translators: %s: The plural name of a content type, like "Services" or "Practitioners". */
                sprintf( __( 'Linked %s', 'client-sync' ), $child_cpt_object->labels->name ),
                function( $post ) use ( $child_cpt_slug, $child_cpt_object ) {
                    self::render_checklist_meta_box_content( $post, $child_cpt_slug, $child_cpt_object );
                },
                $parent_cpt,
                'normal',
                'default'
            );
        }
    }

    /**
     * Renders the actual checklist of posts for a given relationship.
     *
     * @param \WP_Post $post The parent post object.
     * @param string $child_cpt_slug The slug of the child CPT.
     * @param \WP_Post_Type $child_cpt_object The object for the child CPT.
     */
    public static function render_checklist_meta_box_content( \WP_Post $post, string $child_cpt_slug, \WP_Post_Type $child_cpt_object ) {
        // *** REFACTORED ***: Prefixed nonce name
        wp_nonce_field( 'clisyc_save_relationships_' . $post->ID, 'clisyc_relationships_nonce' );

        $tooltip_text = __( 'You must explicitly link this item to the other items it works with. Only items that are checked will be considered a valid pairing for booking.', 'client-sync' ) . "\n\n" .
                        __( '• Example: To make "Dr. Smith" available for "Heart Surgery", you must edit Dr. Smith\'s profile and check the box for "Heart Surgery".', 'client-sync' );
        ?>
        <p>
            <?php esc_html_e( 'Select the items that can be booked with this post. Check a box to create a link.', 'client-sync' ); ?>
            
            <?php // *** REFACTORED ***: Prefixed CSS class ?>
            <span class="clisyc-help-tip dashicons dashicons-editor-help" title="<?php echo esc_attr( $tooltip_text ); ?>"></span>
        </p>
        <?php

        $child_posts = get_posts([
            'post_type' => $child_cpt_slug,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $linked_ids = self::get_linked_child_ids( $post->ID, $child_cpt_slug );

        if ( empty( $child_posts ) ) {
            /* translators: %s: The lowercase plural name of a content type, like "services" or "practitioners". */
            printf( '<p><em>' . esc_html__( 'No %s have been created yet.', 'client-sync' ) . '</em></p>', esc_html( strtolower( $child_cpt_object->labels->name ) ) );
            return;
        }

        echo '<div class="clisyc-relationship-checklist-wrapper" style="max-height: 250px; overflow-y: auto; margin-top: 10px;">';
        foreach ( $child_posts as $child_post ) {
            $is_checked = in_array( $child_post->ID, $linked_ids, true );
            
            echo '<label style="display: block; margin-bottom: 5px;">';
            // *** REFACTORED ***: Prefixed input name
            echo '<input type="checkbox" name="clisyc_relationships[' . esc_attr( $child_cpt_slug ) . '][]" value="' . esc_attr( $child_post->ID ) . '" ' . checked( $is_checked, true, false ) . '>';
            echo ' ' . esc_html( $child_post->post_title );
            echo '</label>';
        }
        echo '</div>';
    }

    /**
     * Saves all relationship data from a CPT edit screen.
     *
     * @param int $post_id The ID of the post being saved.
     * @param array $registry The dimension registry settings.
     */
    public static function save_relationships( int $post_id, array $registry ) {
        // *** REFACTORED ***: Prefixed nonce check
        if ( ! isset( $_POST['clisyc_relationships_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['clisyc_relationships_nonce'] ) ), 'clisyc_save_relationships_' . $post_id ) ) {
            return;
        }

        global $wpdb;
        // *** REFACTORED ***: Prefixed table name
        $table_name = $wpdb->prefix . 'clisyc_relationships';
        $parent_cpt = get_post_type( $post_id );
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table operations.
        $wpdb->delete( $table_name, [ 'parent_object_id' => $post_id ], [ '%d' ] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $table_name, [ 'child_object_id' => $post_id ], [ '%d' ] );

        $unslashed_post = wp_unslash( $_POST );
        
        // *** REFACTORED ***: Prefixed $_POST key
        $submitted_relationships = $unslashed_post['clisyc_relationships'] ?? [];
        
        if ( ! empty( $submitted_relationships ) && is_array( $submitted_relationships ) ) {
            foreach ( $submitted_relationships as $child_cpt_slug => $child_ids ) {
                $child_cpt_slug = sanitize_key( $child_cpt_slug );
                $child_ids = is_array( $child_ids ) ? array_map( 'absint', $child_ids ) : [];

                foreach ( $child_ids as $child_id ) {
                    if ( $child_id <= 0 || empty( $parent_cpt ) || empty( $child_cpt_slug ) ) {
                        continue;
                    }

                    // --- START: NORMALIZATION FIX ---
                    // Always store the relationship with the smaller ID as the parent to ensure consistency.
                    $parent_object_id   = min( $post_id, $child_id );
                    $child_object_id    = max( $post_id, $child_id );
                    $parent_object_type = ( $parent_object_id === $post_id ) ? $parent_cpt : $child_cpt_slug;
                    $child_object_type  = ( $child_object_id === $child_id ) ? $child_cpt_slug : $parent_cpt;

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
                    $wpdb->insert( $table_name, [
						'parent_object_id'   => $parent_object_id,
						'parent_object_type' => $parent_object_type,
						'child_object_id'    => $child_object_id,
						'child_object_type'  => $child_object_type,
					] );
                    // --- END: NORMALIZATION FIX ---
                }
            }
        }
    }

    /**
     * Retrieves an array of linked child post IDs for a given parent.
     *
     * @param int $parent_id The ID of the parent post.
     * @param string $child_cpt_slug The slug of the child CPT to look for.
     * @return array
     */
    public static function get_linked_child_ids( int $parent_id, string $child_cpt_slug ): array {
        global $wpdb;
        // *** REFACTORED ***: Prefixed table name
        $table_name = $wpdb->prefix . 'clisyc_relationships';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from internal variable.
        $results = $wpdb->get_col( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
            "SELECT child_object_id FROM {$table_name} WHERE parent_object_id = %d AND child_object_type = %s",
            $parent_id,
            $child_cpt_slug
        ) );

        return array_map( 'absint', $results );
    }
}