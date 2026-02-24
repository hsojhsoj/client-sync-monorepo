<?php
/**
 * File: src/shared/includes/admin/class-dimension-cpt-manager.php -> client-sync/includes/admin/class-dimension-cpt-manager.php
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 * FIXED: Added 'show_in_rest' => true to CPT registration to enable block editor integration.
 * FIXED V2: Added configuration notice meta box for unconfigured dimension items.
 * FIXED V3: Added Availability Calendar meta box for Primary Dimensions.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the registration and handling of custom post types for "Dimensions".
 */
class Dimension_CPT_Manager {

	/**
	 * The option key where custom dimension types are stored.
	 *
	 * @var string
	 */
	const OPTION_KEY = Constants::OPTION_CUSTOM_DIMENSION_TYPES;

	/**
	 * Registers the necessary WordPress hooks.
	 */
	public function register_hooks() {
		// Register the user-defined CPTs early on every page load.
		add_action( 'init', [ $this, 'register_custom_dimensions_cpts' ] );

		if ( is_admin() ) {
			// Hooks for adding the "Linked Product" column to dimension CPT list tables.
			add_filter( 'manage_posts_columns', [ $this, 'add_wc_product_column_to_dimensions' ], 10, 2 );
			add_action( 'manage_posts_custom_column', [ $this, 'render_wc_product_column_content' ], 10, 2 );
			
			// General hook for adding meta boxes to dimension CPTs
			add_action( 'add_meta_boxes', [ $this, 'add_dimension_meta_boxes' ], 10, 2 );
		}
	}

	/**
	 * Reads the saved option and registers all user-defined CPTs.
	 */
	public function register_custom_dimensions_cpts() {
		$custom_dimensions = get_option( self::OPTION_KEY, [] );

		if ( empty( $custom_dimensions ) || ! is_array( $custom_dimensions ) ) {
			return;
		}

		foreach ( $custom_dimensions as $slug => $config ) {
			if ( empty( $slug ) || empty( $config['singular'] ) || empty( $config['plural'] ) ) {
				continue;
			}

			$singular  = esc_html( $config['singular'] );
			$plural    = esc_html( $config['plural'] );
			$is_public = ! empty( $config['public'] );

			$labels = [
				'name'                  => $plural,
				'singular_name'         => $singular,
				'menu_name'             => $plural,
				'name_admin_bar'        => $singular,
				/* translators: %s: Singular name of the custom post type (e.g., "Service"). */
				'add_new'               => sprintf( __( 'Add New %s', 'client-sync' ), $singular ),
				/* translators: %s: Singular name of the custom post type (e.g., "Service"). */
				'add_new_item'          => sprintf( __( 'Add New %s', 'client-sync' ), $singular ),
				/* translators: %s: Singular name of the custom post type (e.g., "Service"). */
				'new_item'              => sprintf( __( 'New %s', 'client-sync' ), $singular ),
				/* translators: %s: Singular name of the custom post type (e.g., "Service"). */
				'edit_item'             => sprintf( __( 'Edit %s', 'client-sync' ), $singular ),
				/* translators: %s: Singular name of the custom post type (e.g., "Service"). */
				'view_item'             => sprintf( __( 'View %s', 'client-sync' ), $singular ),
				/* translators: %s: Plural name of the custom post type (e.g., "Services"). */
				'all_items'             => sprintf( __( 'All %s', 'client-sync' ), $plural ),
				/* translators: %s: Plural name of the custom post type (e.g., "Services"). */
				'search_items'          => sprintf( __( 'Search %s', 'client-sync' ), $plural ),
				/* translators: %s: Lowercase plural name of the custom post type (e.g., "services"). */
				'not_found'             => sprintf( __( 'No %s found.', 'client-sync' ), strtolower( $plural ) ),
				/* translators: %s: Lowercase plural name of the custom post type (e.g., "services"). */
				'not_found_in_trash'    => sprintf( __( 'No %s found in Trash.', 'client-sync' ), strtolower( $plural ) ),
			];

			register_post_type(
				$slug,
				[
					'labels'             => $labels,
					'public'             => $is_public,
					'publicly_queryable' => $is_public,
					'show_ui'            => true, // Always show in admin UI
					'show_in_menu'       => false, // We will add this manually to control order and icon
					'has_archive'        => $is_public,
					'rewrite'            => $is_public ? [ 'slug' => sanitize_title( $plural ) ] : false,
					'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'author' ],
					'show_in_rest'       => true,
					'menu_icon'          => esc_attr( $config['icon'] ?? 'dashicons-admin-generic' ),
					'capability_type'    => 'post',
					'map_meta_cap'       => true,
				]
			);
		}
	}

	/**
	 * Adds meta boxes to Dimension Custom Post Types.
	 * Handles:
	 * 1. Configuration warnings for items with no relationships.
	 * 2. Availability Calendar for the Primary Dimension.
	 * 
	 * @param string $post_type The post type of the current admin screen.
	 * @param \WP_Post $post The post object.
	 */
	public function add_dimension_meta_boxes( $post_type, $post ) {
		// Only proceed if we have a valid post object with an ID
		if ( ! $post || ! isset( $post->ID ) || ! $post->ID ) {
			return;
		}

		// Check if this post type is an enabled dimension
		$registry           = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$enabled_dimensions = $registry['dimensions'] ?? [];
		
		if ( empty( $enabled_dimensions[ $post_type ]['enabled'] ) ) {
			return; // Not an enabled dimension, skip
		}

		$is_primary_dimension = ! empty( $enabled_dimensions[ $post_type ]['primary'] );

		// --- 1. Configuration Notice (No Relationships) ---

		// Check if this dimension item has any relationships
		global $wpdb;
		$rels_table = $wpdb->prefix . 'clisyc_relationships';
		
		// Query for any relationship where this post is either parent or child
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix.
		$sql = "SELECT COUNT(*) FROM {$rels_table} WHERE parent_object_id = %d OR child_object_id = %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$relationship_count = $wpdb->get_var(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$post->ID,
				$post->ID
			)
		);

		// If no relationships exist, add the warning meta box
		if ( 0 === (int) $relationship_count ) {
			$post_type_object = get_post_type_object( $post_type );
			$singular_name    = $post_type_object ? $post_type_object->labels->singular_name : __( 'Item', 'client-sync' );
			
			add_meta_box(
				'clisyc_configuration_notice',
				__( 'Configuration Required', 'client-sync' ),
				[ $this, 'render_configuration_notice_meta_box' ],
				$post_type,
				'side',
				'high',
				[ 'singular_name' => $singular_name ]
			);
		}

		// --- 2. Availability Calendar (Primary Dimension Only) ---
		if ( $is_primary_dimension ) {
			add_meta_box(
				'clisyc-item-availability-calendar',
				__( 'Availability Calendar', 'client-sync' ),
				[ $this, 'render_item_availability_calendar' ],
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Renders the content of the configuration notice meta box.
	 *
	 * @param \WP_Post $post The current post object.
	 * @param array    $args Additional arguments passed to the meta box.
	 */
	public function render_configuration_notice_meta_box( $post, $args ) {
		$singular_name = $args['args']['singular_name'] ?? __( 'item', 'client-sync' );
		$graph_url     = admin_url( 'admin.php?page=clisyc-dimensions' );
		?>
		<div class="notice notice-warning inline" style="margin: 0; padding: 10px;">
			<p style="margin: 0 0 10px 0;">
				<strong><?php esc_html_e( 'No Relationships Configured', 'client-sync' ); ?></strong>
			</p>
			<p style="margin: 0 0 10px 0;">
				<?php
				/* translators: %s: Singular name of the dimension (e.g., "Service", "Practitioner"). */
				$notice_text = __( 'This %s has not been connected to any other dimensions. It will not appear in booking forms until relationships are configured.', 'client-sync' );

				printf(
					esc_html( $notice_text ),
					esc_html( strtolower( $singular_name ) )
				);
				?>
			</p>
			<p style="margin: 0;">
				<a href="<?php echo esc_url( $graph_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Configure Relationships', 'client-sync' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the Availability Calendar container for the Primary Dimension item.
	 *
	 * @param \WP_Post $post The current post object.
	 */
	public function render_item_availability_calendar( \WP_Post $post ) {
		?>
		<div id="clisyc-item-calendar-wrapper" class="clisyc-admin-instructions-metabox">
			<div class="clisyc-item-calendar-controls" style="margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between;">
				<div>
					<span class="clisyc-legend-color clisyc-booked-event" style="display:inline-block; width:12px; height:12px; margin-right:5px;"></span> 
					<strong><?php esc_html_e( 'Booked', 'client-sync' ); ?></strong>
				</div>
				<p class="description" style="margin:0;">
					<?php esc_html_e( 'Click an event to edit the appointment.', 'client-sync' ); ?>
				</p>
			</div>
			<div id="clisyc-admin-item-calendar" style="min-height: 500px;">
				<p style="text-align:center; padding: 40px; color: #666;">
					<span class="spinner is-active" style="float:none;"></span> 
					<?php esc_html_e( 'Loading availability...', 'client-sync' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Adds a "Linked Product" column to the list tables of enabled Primary Dimensions.
	 *
	 * @param array  $columns   The existing array of columns.
	 * @param string $post_type The post type of the current list table.
	 * @return array The modified array of columns.
	 */
	public function add_wc_product_column_to_dimensions( array $columns, string $post_type ): array {
		if ( ! get_option( Constants::OPTION_WC_ENABLED, false ) || ! class_exists( 'WooCommerce' ) ) {
			return $columns;
		}

		$registry           = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$enabled_dimensions = $registry['dimensions'] ?? [];

		$is_primary_dimension = ! empty( $enabled_dimensions[ $post_type ]['enabled'] ) && ! empty( $enabled_dimensions[ $post_type ]['primary'] );

		if ( $is_primary_dimension ) {
			$date_column_pos = array_search( 'date', array_keys( $columns ), true );
			$position        = ( false !== $date_column_pos ) ? $date_column_pos : 2;

			$new_columns                           = array_slice( $columns, 0, $position, true );
			$new_columns['clisyc_wc_product_link'] = __( 'Linked Product', 'client-sync' );

			return array_merge( $new_columns, array_slice( $columns, $position, null, true ) );
		}

		return $columns;
	}

	/**
	 * Renders the content for the custom "Linked Product" column.
	 *
	 * @param string $column_name The name of the current column.
	 * @param int    $post_id     The ID of the current post.
	 */
	public function render_wc_product_column_content( string $column_name, int $post_id ) {
		if ( 'clisyc_wc_product_link' !== $column_name ) {
			return;
		}

		$product_id = get_post_meta( $post_id, Constants::META_WC_PRODUCT_ID, true );

		if ( ! $product_id ) {
			echo '—';
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			echo '<em>' . esc_html__( 'Invalid Product ID', 'client-sync' ) . '</em>';
			return;
		}

		$edit_url = get_edit_post_link( $product_id );

		if ( ! $edit_url ) {
			echo esc_html( $product->get_name() );
			return;
		}

		printf(
			'<a href="%s" target="_blank" title="%s">%s <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: middle;"></span></a>',
			esc_url( $edit_url ),
			esc_attr__( 'Edit this product in a new tab', 'client-sync' ),
			esc_html( $product->get_name() )
		);
	}

	/**
	 * Saves or updates a custom dimension type definition.
	 *
	 * @param string $singular The singular name for the dimension.
	 * @param string $plural   The plural name for the dimension.
	 * @param string $slug     The slug for the dimension.
	 * @param string $icon     The Dashicon class for the menu icon.
	 * @param bool   $public   Whether the CPT should be public.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function save_custom_dimension( $singular, $plural, $slug, $icon, $public = false ) {
		$custom_dimensions = get_option( self::OPTION_KEY, [] );
		$slug              = sanitize_key( 'clisyc_' . str_replace( [ 'cs_', 'clisyc_' ], '', $slug ) );

		if ( empty( $singular ) || empty( $plural ) || empty( $slug ) ) {
			return new \WP_Error( 'missing_data', 'Singular, Plural, and Slug are required.' );
		}

		if ( strlen( $slug ) > 20 ) {
			/* translators: %s: The CPT slug that is too long. */
			$error_message = sprintf( __( 'The slug "%s" is too long. Post type slugs cannot exceed 20 characters.', 'client-sync' ), $slug );
			return new \WP_Error( 'slug_too_long', $error_message );
		}

		$custom_dimensions[ $slug ] = [
			'singular' => sanitize_text_field( $singular ),
			'plural'   => sanitize_text_field( $plural ),
			'icon'     => sanitize_text_field( $icon ),
			'public'   => (bool) $public,
		];

		update_option( self::OPTION_KEY, $custom_dimensions );
		flush_rewrite_rules();

		return true;
	}

	/**
	 * Deletes a custom dimension type definition.
	 *
	 * @param string $slug The slug of the dimension to delete.
	 */
	public static function delete_custom_dimension( $slug ) {
		$custom_dimensions = get_option( self::OPTION_KEY, [] );
		unset( $custom_dimensions[ sanitize_key( $slug ) ] );
		update_option( self::OPTION_KEY, $custom_dimensions );
		flush_rewrite_rules();
	}
}