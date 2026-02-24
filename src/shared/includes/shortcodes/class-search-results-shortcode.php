<?php
/**
 * Handles the [clisyc_search_results] shortcode.
 *
 * Renders filtered search results for the primary dimension.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Search_Results_Shortcode {

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'clisyc_search_results', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the search results shortcode.
	 *
	 * This is self-contained and runs its own query to prevent infinite loops.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		ob_start();

		// Find the primary dimension slug to query
		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_dim_slug = $slug;
				break;
			}
		}

		if ( ! $primary_dim_slug ) {
			return '<!-- Client Sync: Cannot render search results without a primary dimension. -->';
		}

		// --- START: Build the new, independent WP_Query ---
		global $wp_query; // We need to temporarily replace this.

		$paged = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;
		$args  = [
			'post_type'      => $primary_dim_slug,
			'post_status'    => 'publish',
			'posts_per_page' => get_option( 'posts_per_page', 10 ),
			'paged'          => $paged,
		];

		// This logic is moved from pre_get_posts to here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This reads URL filter parameters for query purposes, not a form submission.
		$unslashed_get     = wp_unslash( $_GET );
		$checkin           = isset( $unslashed_get['checkin'] ) ? sanitize_text_field( $unslashed_get['checkin'] ) : '';
		$checkout          = isset( $unslashed_get['checkout'] ) ? sanitize_text_field( $unslashed_get['checkout'] ) : '';
		$attribute_filters = isset( $unslashed_get['clisyc_attr'] ) && is_array( $unslashed_get['clisyc_attr'] ) ? array_map( 'sanitize_text_field', $unslashed_get['clisyc_attr'] ) : [];

		$meta_query           = $args['meta_query'] ?? [];
		$all_dimension_fields = get_option( Constants::OPTION_DIMENSION_FIELDS, [] );

		if ( ! empty( $attribute_filters ) ) {
			foreach ( $attribute_filters as $key => $value ) {
				if ( ! empty( $value ) ) {
					$field_def  = $all_dimension_fields[ $key ] ?? null;
					$comparison = '=';
					$type       = 'CHAR';

					if ( $field_def && 'number' === $field_def['type'] ) {
						$type       = 'NUMERIC';
						$comparison = $field_def['comparison_operator'] ?? '=';
					}
					$meta_query[] = [
						'key'     => '_clisyc_' . sanitize_key( $key ),
						'value'   => $value,
						'compare' => $comparison,
						'type'    => $type,
					];
				}
			}
		}

		if ( count( $meta_query ) > 1 ) {
			$meta_query['relation'] = 'AND';
		}
		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		if ( ! empty( $checkin ) && ! empty( $checkout ) && strtotime( $checkout ) > strtotime( $checkin ) ) {
			$db_manager       = new \DependentMedia\ClientSync\Core\Database_Manager();
			$available_items  = $db_manager->get_available_dimension_items_for_range( $checkin, $checkout, [] );
			$args['post__in'] = ! empty( $available_items ) ? wp_list_pluck( $available_items, 'ID' ) : [ 0 ];
		}

		$sort_param = isset( $unslashed_get['orderby'] ) ? sanitize_text_field( $unslashed_get['orderby'] ) : '';
		if ( ! empty( $sort_param ) && strpos( $sort_param, ':' ) !== false ) {
			list( $orderby_key, $order ) = explode( ':', $sort_param, 2 );
			$args['orderby'] = in_array( $orderby_key, [ 'title', 'date' ] ) ? $orderby_key : 'title';
			$args['order']   = in_array( strtoupper( $order ), [ 'ASC', 'DESC' ] ) ? strtoupper( $order ) : 'ASC';
		}

		// Create the new query and temporarily replace the global one
		$search_query = new \WP_Query( $args );
		$temp_query   = $wp_query;
		$wp_query     = $search_query;

		// Now, include the template file. It will use our new query.
		$results_template = clisyc_PLUGIN_DIR . 'includes/frontend/views/view-archive-primary-dimension.php';
		if ( file_exists( $results_template ) ) {
			include $results_template;
		} else {
			echo '<!-- Client Sync: Search results template file not found. -->';
		}

		// VERY IMPORTANT: Restore the original global query.
		$wp_query = $temp_query;
		wp_reset_postdata();

		// --- END: New Query Logic ---

		return ob_get_clean();
	}
}
