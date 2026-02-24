<?php
/**
 * File: src/pro/includes/modules/seat-selection/class-venue-link-meta-box.php
 * Adds a "Linked Venue" meta box to primary dimension CPTs (e.g. services).
 *
 * Allows admins to associate a service/dimension item with a venue for
 * seat selection during the booking flow.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Seat_Selection
 */

namespace ClientSyncPro\Modules\Seat_Selection;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Venue_Link_Meta_Box {

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ], 10, 2 );
		add_action( 'save_post', [ $this, 'save_meta_data' ] );
	}

	/**
	 * Add the venue link meta box to primary dimension CPTs.
	 *
	 * @param string   $post_type
	 * @param \WP_Post $post
	 */
	public function add_meta_box( string $post_type, $post ): void {
		if ( ! $post || ! isset( $post->ID ) ) {
			return;
		}

		// Only add to primary dimension CPTs.
		$registry           = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$enabled_dimensions = $registry['dimensions'] ?? [];

		if ( empty( $enabled_dimensions[ $post_type ]['enabled'] ) || empty( $enabled_dimensions[ $post_type ]['primary'] ) ) {
			return;
		}

		// Verify at least one venue exists.
		$venues = get_posts( [
			'post_type'      => Constants::POST_TYPE_VENUE,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );

		if ( empty( $venues ) ) {
			return; // No venues created yet — don't show an empty meta box.
		}

		add_meta_box(
			'clisyc-venue-link',
			__( 'Seat Selection — Linked Venue', 'client-sync-pro' ),
			[ $this, 'render_meta_box' ],
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Render the venue link meta box.
	 *
	 * @param \WP_Post $post
	 */
	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'clisyc_venue_link_save', 'clisyc_venue_link_nonce' );

		$current_venue_id = (int) get_post_meta( $post->ID, Constants::META_LINKED_VENUE_ID, true );

		$venues = get_posts( [
			'post_type'      => Constants::POST_TYPE_VENUE,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		?>
		<p>
			<select name="clisyc_linked_venue_id" id="clisyc_linked_venue_id" style="width:100%;">
				<option value="0"><?php esc_html_e( '— No venue (no seat selection) —', 'client-sync-pro' ); ?></option>
				<?php foreach ( $venues as $venue ) : ?>
					<?php
					$layout     = get_post_meta( $venue->ID, Constants::META_VENUE_LAYOUT, true );
					$seat_count = is_array( $layout ) ? ( $layout['total_seats'] ?? 0 ) : 0;
					$label      = $venue->post_title;
					if ( $seat_count > 0 ) {
						$label .= sprintf( ' (%d %s)', $seat_count, __( 'seats', 'client-sync-pro' ) );
					}
					if ( 'draft' === $venue->post_status ) {
						$label .= ' [' . __( 'Draft', 'client-sync-pro' ) . ']';
					}
					?>
					<option value="<?php echo esc_attr( $venue->ID ); ?>" <?php selected( $current_venue_id, $venue->ID ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">
			<?php esc_html_e( 'Link a venue to enable seat selection when this item is booked.', 'client-sync-pro' ); ?>
		</p>
		<?php

		// Show current venue summary if linked.
		if ( $current_venue_id ) {
			$layout = get_post_meta( $current_venue_id, Constants::META_VENUE_LAYOUT, true );
			if ( is_array( $layout ) && ! empty( $layout['total_seats'] ) ) {
				$sections_count = count( $layout['sections'] ?? [] );
				$has_sections   = $sections_count > 1 || ( $sections_count === 1 && ( $layout['sections'][0]['id'] ?? '' ) !== '_default' );
				?>
				<div style="margin-top:8px;padding:8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;font-size:12px;">
					<strong><?php esc_html_e( 'Venue Summary', 'client-sync-pro' ); ?></strong><br>
					<?php
					/* translators: %d: number of seats */
					printf( esc_html__( '%d total seats', 'client-sync-pro' ), $layout['total_seats'] );
					if ( $has_sections ) {
						echo ' · ';
						/* translators: %d: number of sections */
						printf( esc_html__( '%d sections', 'client-sync-pro' ), $sections_count );
					}
					?>
				</div>
				<?php
			}
		}
	}

	/**
	 * Save the linked venue ID.
	 *
	 * @param int $post_id
	 */
	public function save_meta_data( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['clisyc_venue_link_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['clisyc_venue_link_nonce'] ), 'clisyc_venue_link_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$venue_id = isset( $_POST['clisyc_linked_venue_id'] ) ? absint( $_POST['clisyc_linked_venue_id'] ) : 0;

		if ( $venue_id > 0 ) {
			update_post_meta( $post_id, Constants::META_LINKED_VENUE_ID, $venue_id );
		} else {
			delete_post_meta( $post_id, Constants::META_LINKED_VENUE_ID );
		}
	}
}
