<?php
/**
 * File: src/pro/includes/modules/seat-selection/class-venue-cpt.php
 * Registers the clisyc_venue custom post type.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Seat_Selection
 */

namespace ClientSyncPro\Modules\Seat_Selection;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Venue_CPT {

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_cpt' ] );
	}

	/**
	 * Register the clisyc_venue custom post type.
	 */
	public function register_cpt(): void {
		$labels = [
			'name'               => __( 'Venues', 'client-sync-pro' ),
			'singular_name'      => __( 'Venue', 'client-sync-pro' ),
			'add_new'            => __( 'Add New Venue', 'client-sync-pro' ),
			'add_new_item'       => __( 'Add New Venue', 'client-sync-pro' ),
			'edit_item'          => __( 'Edit Venue', 'client-sync-pro' ),
			'new_item'           => __( 'New Venue', 'client-sync-pro' ),
			'view_item'          => __( 'View Venue', 'client-sync-pro' ),
			'search_items'       => __( 'Search Venues', 'client-sync-pro' ),
			'not_found'          => __( 'No venues found.', 'client-sync-pro' ),
			'not_found_in_trash' => __( 'No venues found in Trash.', 'client-sync-pro' ),
			'all_items'          => __( 'Venues', 'client-sync-pro' ),
		];

		$args = [
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'client-sync',
			'supports'            => [ 'title', 'thumbnail' ],
			'menu_icon'           => 'dashicons-location-alt',
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'show_in_rest'        => false,
		];

		register_post_type( Constants::POST_TYPE_VENUE, $args );
	}
}
