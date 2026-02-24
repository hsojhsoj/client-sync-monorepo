<?php
/**
 * File: src/pro/includes/modules/forms/class-form-cpt.php -> client-sync-pro/includes/modules/forms/class-form-cpt.php
 * Registers the 'clisyc_form' Custom Post Type.
 *
 * This version adds a shortcode display meta box and custom columns to the list table.
 * MODIFIED: Simplified menu registration.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Forms
 */

namespace ClientSyncPro\Modules\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Form_CPT {

	/**
	 * Registers the hooks for CPT, meta boxes, and admin columns.
	 */
	public function register_hooks() {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( 'add_meta_boxes_clisyc_form', [ $this, 'add_shortcode_metabox' ] );
		add_filter( 'manage_clisyc_form_posts_columns', [ $this, 'add_admin_columns' ] );
		add_action( 'manage_clisyc_form_posts_custom_column', [ $this, 'render_admin_columns' ], 10, 2 );
	}

	/**
	 * Registers the 'clisyc_form' custom post type.
	 */
	public function register_cpt() {
		$labels = [
			'name'                  => _x( 'Forms', 'Post type general name', 'client-sync-pro' ),
			'singular_name'         => _x( 'Form', 'Post type singular name', 'client-sync-pro' ),
			'menu_name'             => _x( 'Forms', 'Admin Menu text', 'client-sync-pro' ),
			'name_admin_bar'        => _x( 'Form', 'Add New on Toolbar', 'client-sync-pro' ),
			'add_new'               => __( 'Add New', 'client-sync-pro' ),
			'add_new_item'          => __( 'Add New Form', 'client-sync-pro' ),
			'new_item'              => __( 'New Form', 'client-sync-pro' ),
			'edit_item'             => __( 'Edit Form', 'client-sync-pro' ),
			'view_item'             => __( 'View Form', 'client-sync-pro' ),
			'all_items'             => __( 'All Forms', 'client-sync-pro' ),
			'search_items'          => __( 'Search Forms', 'client-sync-pro' ),
			'not_found'             => __( 'No forms found.', 'client-sync-pro' ),
			'not_found_in_trash'    => __( 'No forms found in Trash.', 'client-sync-pro' ),
		];

		$args = [
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => 'client-sync',
			'query_var'           => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'has_archive'         => false,
			'hierarchical'        => false,
			'menu_position'       => null,
			'supports'            => [ 'title' ],
			'show_in_rest'        => false, // Not needed for the REST API at this time.
			'menu_icon'           => 'dashicons-list-view',
		];

		register_post_type( 'clisyc_form', $args );
	}

	/**
	 * Adds the shortcode meta box to the form editor screen.
	 */
	public function add_shortcode_metabox() {
		add_meta_box(
			'clisyc-form-shortcode-display',
			__( 'Shortcode', 'client-sync-pro' ),
			[ $this, 'render_shortcode_metabox' ],
			'clisyc_form',
			'side',
			'default'
		);
	}

	/**
	 * Renders the content of the shortcode meta box.
	 *
	 * @param \WP_Post $post The current post object.
	 */
	public function render_shortcode_metabox( \WP_Post $post ) {
		if ( 'publish' !== $post->post_status ) {
			echo '<p>' . esc_html__( 'Publish this form to generate its shortcode.', 'client-sync-pro' ) . '</p>';
			return;
		}
		$shortcode = '[clisyc_form id="' . $post->ID . '"]';
		?>
		<div style="position: relative;">
			<input type="text" value="<?php echo esc_attr( $shortcode ); ?>" readonly style="width: 100%; padding-right: 35px;">
			<button type="button" class="button button-secondary button-small" style="position: absolute; top: 3px; right: 4px;" onclick="navigator.clipboard.writeText('<?php echo esc_attr( $shortcode ); ?>'); this.innerText = 'Copied!'; setTimeout(() => { this.innerHTML = '<span class=\'dashicons dashicons-admin-page\'></span>'; }, 2000);" title="<?php esc_attr_e( 'Copy to clipboard', 'client-sync-pro' ); ?>">
				<span class="dashicons dashicons-admin-page"></span>
			</button>
		</div>
		<?php
	}

	/**
	 * Adds custom columns to the 'clisyc_form' post type list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_admin_columns( $columns ) {
		$new_columns = [];
		$new_columns['cb'] = $columns['cb'];
		$new_columns['title'] = $columns['title'];
		$new_columns['shortcode'] = __( 'Shortcode', 'client-sync-pro' );
		$new_columns['submissions'] = __( 'Submissions', 'client-sync-pro' );
		$new_columns['date'] = $columns['date'];
		return $new_columns;
	}

	/**
	 * Renders the content for custom admin columns.
	 *
	 * @param string $column_name The name of the column to render.
	 * @param int    $post_id     The ID of the current post.
	 */
	public function render_admin_columns( $column_name, $post_id ) {
		switch ( $column_name ) {
			case 'shortcode':
				$shortcode = '[clisyc_form id="' . $post_id . '"]';
				echo '<code>' . esc_html( $shortcode ) . '</code>';
				break;
			case 'submissions':
				$count = get_post_meta( $post_id, '_clisyc_form_submission_count', true ) ?: 0;
				// The link to view entries is a placeholder for a future feature.
				$view_link = admin_url( 'edit.php?post_type=clisyc_form&page=clisyc-form-entries&form_id=' . $post_id );
				printf( '<a href="%s">%d</a>', esc_url( $view_link ), absint( $count ) );
				break;
		}
	}
}