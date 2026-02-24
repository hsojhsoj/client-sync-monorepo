<?php
/**
 * File: src/shared/includes/rest-api/class-filter-options-controller.php
 * 
 * UPDATED: Now includes price and description for date-range mode display.
 * 
 * This shows the changes needed to your filter options endpoint.
 * Merge these changes into your existing controller.
 */

namespace DependentMedia\ClientSync\RestAPI;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Security_Helper;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Filter_Options_Controller extends WP_REST_Controller {

    protected $namespace = 'clisyc/v1';
    protected $rest_base = 'filter-options';

    /**
     * Register routes.
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_filter_options' ],
                    'permission_callback' => '__return_true',
                ],
            ]
        );
    }

    /**
     * Get filter options for all dimensions.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_filter_options( WP_REST_Request $request ) {
        $rate_check = Security_Helper::check_rate_limit( 'filter_options', Constants::RATE_LIMIT_FILTER_OPTIONS, Constants::RATE_LIMIT_WINDOW_SECS );
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        $registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
        $dimensions = $registry['dimensions'] ?? [];
        $order = $registry['order'] ?? array_keys( $dimensions );

        $options = [];

        foreach ( $order as $slug ) {
            if ( ! isset( $dimensions[ $slug ] ) ) {
                continue;
            }

            $config = $dimensions[ $slug ];
            $post_type = $config['post_type'] ?? $slug;

            // Query all published posts for this dimension
            $posts = get_posts( [
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => 200,
                'no_found_rows'  => true,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ] );

            $options[ $slug ] = [];

            // PERFORMANCE FIX: Prime meta cache for all posts in this dimension
            // to prevent N+1 get_post_meta() queries inside the loop.
            if ( $posts ) {
                update_postmeta_cache( wp_list_pluck( $posts, 'ID' ) );
            }

            foreach ( $posts as $post ) {
                $option = [
                    'value' => $post->ID,
                    'label' => $post->post_title,
                    'color' => get_post_meta( $post->ID, Constants::META_COLOR, true ) ?: null,
                ];

                // UPDATED: Include price for date-range mode display
                $price = (float) get_post_meta( $post->ID, Constants::META_PRICE, true );
                if ( $price > 0 ) {
                    $option['price'] = $price;
                }

                // UPDATED: Include short description for date-range mode
                if ( ! empty( $post->post_content ) ) {
                    $option['description'] = wp_trim_words( 
                        wp_strip_all_tags( $post->post_content ), 
                        15, 
                        '...' 
                    );
                }

                // Include featured image URL if available
                $thumbnail_id = get_post_thumbnail_id( $post->ID );
                if ( $thumbnail_id ) {
                    $thumbnail_url = wp_get_attachment_image_url( $thumbnail_id, 'thumbnail' );
                    if ( $thumbnail_url ) {
                        $option['thumbnail'] = $thumbnail_url;
                    }
                }

                // Include capacity if set (for display purposes)
                $capacity = (int) get_post_meta( $post->ID, Constants::META_CAPACITY, true );
                if ( $capacity > 0 ) {
                    $option['capacity'] = $capacity;
                }

                // Include min/max stay if set
                $min_stay = (int) get_post_meta( $post->ID, '_clisyc_min_stay', true );
                if ( $min_stay > 0 ) {
                    $option['minStay'] = $min_stay;
                }

                $max_stay = (int) get_post_meta( $post->ID, '_clisyc_max_stay', true );
                if ( $max_stay > 0 ) {
                    $option['maxStay'] = $max_stay;
                }

                $options[ $slug ][] = $option;
            }
        }

        return new WP_REST_Response( $options, 200 );
    }
}