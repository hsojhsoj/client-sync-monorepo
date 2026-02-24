<?php
/**
 * File: src/pro/includes/modules/seat-selection/class-venue-meta-box.php
 * Admin meta boxes for the clisyc_venue CPT.
 *
 * Provides:
 *  - SVG upload (Media Library) / paste
 *  - Parsing mode toggle (flat_ids / nested_groups)
 *  - Live preview mount (React app)
 *  - Category overrides
 *  - Pricing tiers
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Seat_Selection
 */

namespace ClientSyncPro\Modules\Seat_Selection;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Venue_Meta_Box {

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . Constants::POST_TYPE_VENUE, [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_' . Constants::POST_TYPE_VENUE, [ $this, 'save_meta_data' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Register meta boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'clisyc-venue-svg',
			__( 'Venue SVG & Seat Map', 'client-sync-pro' ),
			[ $this, 'render_svg_meta_box' ],
			Constants::POST_TYPE_VENUE,
			'normal',
			'high'
		);

		add_meta_box(
			'clisyc-venue-location',
			__( 'Venue Location', 'client-sync-pro' ),
			[ $this, 'render_location_meta_box' ],
			Constants::POST_TYPE_VENUE,
			'normal',
			'default'
		);

		add_meta_box(
			'clisyc-venue-pricing',
			__( 'Seat Pricing Tiers', 'client-sync-pro' ),
			[ $this, 'render_pricing_meta_box' ],
			Constants::POST_TYPE_VENUE,
			'normal',
			'default'
		);
	}

	/**
	 * Render the SVG upload / parsing / preview meta box.
	 *
	 * The heavy lifting (preview, category overrides) is done by the React
	 * VenueAdmin app which mounts into #clisyc-venue-admin-root.
	 *
	 * @param \WP_Post $post
	 */
	public function render_svg_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'clisyc_venue_svg_save', 'clisyc_venue_svg_nonce' );

		$svg           = get_post_meta( $post->ID, Constants::META_VENUE_SVG, true );
		$overview_svg  = get_post_meta( $post->ID, Constants::META_VENUE_OVERVIEW_SVG, true );
		$parsing_mode  = get_post_meta( $post->ID, Constants::META_VENUE_PARSING_MODE, true ) ?: 'flat_ids';
		$layout        = get_post_meta( $post->ID, Constants::META_VENUE_LAYOUT, true );
		$cat_overrides = get_post_meta( $post->ID, Constants::META_SEAT_CATEGORY_OVERRIDES, true );
		?>
		<div id="clisyc-venue-admin-root"
			data-venue-id="<?php echo esc_attr( $post->ID ); ?>"
			data-parsing-mode="<?php echo esc_attr( $parsing_mode ); ?>"
			data-layout="<?php echo esc_attr( wp_json_encode( $layout ?: null ) ); ?>"
			data-category-overrides="<?php echo esc_attr( wp_json_encode( $cat_overrides ?: [] ) ); ?>"
		>
			<p class="description"><?php esc_html_e( 'Loading venue editor...', 'client-sync-pro' ); ?></p>
		</div>

		<!-- Hidden fields for server-side save -->
		<input type="hidden" id="clisyc_venue_svg" name="clisyc_venue_svg" value="<?php echo esc_attr( $svg ); ?>" />
		<input type="hidden" id="clisyc_venue_overview_svg" name="clisyc_venue_overview_svg" value="<?php echo esc_attr( $overview_svg ); ?>" />
		<input type="hidden" id="clisyc_venue_parsing_mode" name="clisyc_venue_parsing_mode" value="<?php echo esc_attr( $parsing_mode ); ?>" />
		<input type="hidden" id="clisyc_venue_layout" name="clisyc_venue_layout" value="<?php echo esc_attr( wp_json_encode( $layout ?: null ) ); ?>" />
		<input type="hidden" id="clisyc_venue_category_overrides" name="clisyc_venue_category_overrides" value="<?php echo esc_attr( wp_json_encode( $cat_overrides ?: [] ) ); ?>" />
		<?php
	}

	/**
	 * Render the pricing tiers meta box.
	 *
	 * @param \WP_Post $post
	 */
	public function render_pricing_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'clisyc_venue_pricing_save', 'clisyc_venue_pricing_nonce' );

		$tiers = get_post_meta( $post->ID, Constants::META_SEAT_PRICING_TIERS, true );
		if ( ! is_array( $tiers ) ) {
			$tiers = [];
		}
		?>
		<div id="clisyc-venue-pricing-root"
			data-tiers="<?php echo esc_attr( wp_json_encode( $tiers ) ); ?>"
		>
			<p class="description"><?php esc_html_e( 'Loading pricing editor...', 'client-sync-pro' ); ?></p>
		</div>

		<input type="hidden" id="clisyc_venue_pricing_tiers" name="clisyc_venue_pricing_tiers" value="<?php echo esc_attr( wp_json_encode( $tiers ) ); ?>" />
		<?php
	}

	/**
	 * Render the venue location / address meta box.
	 *
	 * @param \WP_Post $post
	 */
	public function render_location_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'clisyc_venue_location_save', 'clisyc_venue_location_nonce' );

		$address     = get_post_meta( $post->ID, Constants::META_VENUE_ADDRESS, true );
		$city        = get_post_meta( $post->ID, Constants::META_VENUE_CITY, true );
		$state       = get_post_meta( $post->ID, Constants::META_VENUE_STATE, true );
		$postal_code = get_post_meta( $post->ID, Constants::META_VENUE_POSTAL_CODE, true );
		$country     = get_post_meta( $post->ID, Constants::META_VENUE_COUNTRY, true );
		$map_embed   = get_post_meta( $post->ID, Constants::META_VENUE_MAP_EMBED, true );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="clisyc_venue_address"><?php esc_html_e( 'Street Address', 'client-sync-pro' ); ?></label></th>
				<td><input type="text" id="clisyc_venue_address" name="clisyc_venue_address" value="<?php echo esc_attr( $address ); ?>" class="large-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="clisyc_venue_city"><?php esc_html_e( 'City', 'client-sync-pro' ); ?></label></th>
				<td><input type="text" id="clisyc_venue_city" name="clisyc_venue_city" value="<?php echo esc_attr( $city ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="clisyc_venue_state"><?php esc_html_e( 'State / Province', 'client-sync-pro' ); ?></label></th>
				<td><input type="text" id="clisyc_venue_state" name="clisyc_venue_state" value="<?php echo esc_attr( $state ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="clisyc_venue_postal_code"><?php esc_html_e( 'Postal / Zip Code', 'client-sync-pro' ); ?></label></th>
				<td><input type="text" id="clisyc_venue_postal_code" name="clisyc_venue_postal_code" value="<?php echo esc_attr( $postal_code ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="clisyc_venue_country"><?php esc_html_e( 'Country', 'client-sync-pro' ); ?></label></th>
				<td><input type="text" id="clisyc_venue_country" name="clisyc_venue_country" value="<?php echo esc_attr( $country ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="clisyc_venue_map_embed"><?php esc_html_e( 'Map Embed URL', 'client-sync-pro' ); ?></label></th>
				<td>
					<input type="url" id="clisyc_venue_map_embed" name="clisyc_venue_map_embed" value="<?php echo esc_attr( $map_embed ); ?>" class="large-text" />
					<p class="description"><?php esc_html_e( 'Paste a Google Maps or OpenStreetMap embed URL. This will display an interactive map on the appointment detail page.', 'client-sync-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save meta data on venue post save.
	 *
	 * @param int $post_id
	 */
	public function save_meta_data( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save SVG + layout.
		if ( isset( $_POST['clisyc_venue_svg_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['clisyc_venue_svg_nonce'] ), 'clisyc_venue_svg_save' ) ) {
			$this->save_svg_data( $post_id );
		}

		// Save location data.
		if ( isset( $_POST['clisyc_venue_location_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['clisyc_venue_location_nonce'] ), 'clisyc_venue_location_save' ) ) {
			$this->save_location_data( $post_id );
		}

		// Save pricing tiers.
		if ( isset( $_POST['clisyc_venue_pricing_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['clisyc_venue_pricing_nonce'] ), 'clisyc_venue_pricing_save' ) ) {
			$this->save_pricing_data( $post_id );
		}
	}

	/**
	 * Save SVG, parsing mode, layout, and category overrides.
	 *
	 * @param int $post_id
	 */
	private function save_svg_data( int $post_id ): void {
		// SVG — unslash but do NOT use sanitize_text_field (it strips tags).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_svg = isset( $_POST['clisyc_venue_svg'] ) ? wp_unslash( $_POST['clisyc_venue_svg'] ) : '';

		// Sanitize SVG using our parser's sanitizer.
		$parser = new SVG_Parser();
		$svg    = $parser->sanitize_svg( $raw_svg );

		// Parsing mode.
		$mode = isset( $_POST['clisyc_venue_parsing_mode'] ) ? sanitize_key( $_POST['clisyc_venue_parsing_mode'] ) : 'flat_ids';
		if ( ! in_array( $mode, [ 'flat_ids', 'nested_groups' ], true ) ) {
			$mode = 'flat_ids';
		}

		update_post_meta( $post_id, Constants::META_VENUE_SVG, $svg );
		update_post_meta( $post_id, Constants::META_VENUE_PARSING_MODE, $mode );

		// Overview SVG (section selector) — sanitize but no parsing needed.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_overview_svg = isset( $_POST['clisyc_venue_overview_svg'] ) ? wp_unslash( $_POST['clisyc_venue_overview_svg'] ) : '';
		$overview_svg     = $parser->sanitize_svg( $raw_overview_svg );
		update_post_meta( $post_id, Constants::META_VENUE_OVERVIEW_SVG, $overview_svg );

		// Re-parse SVG to build layout (server is canonical).
		if ( ! empty( $svg ) ) {
			$layout = $parser->parse( $svg, $mode );
			if ( ! is_wp_error( $layout ) ) {
				// Apply category overrides.
				$overrides_json = isset( $_POST['clisyc_venue_category_overrides'] ) ? sanitize_text_field( wp_unslash( $_POST['clisyc_venue_category_overrides'] ) ) : '[]';
				$overrides      = json_decode( $overrides_json, true );
				if ( is_array( $overrides ) && ! empty( $overrides ) ) {
					$layout = $this->apply_category_overrides( $layout, $overrides );
				}

				update_post_meta( $post_id, Constants::META_VENUE_LAYOUT, $layout );
				update_post_meta( $post_id, Constants::META_SEAT_CATEGORY_OVERRIDES, $overrides ?: [] );

				// Auto-sync seat count to linked dimensions.
				$this->sync_capacity_to_linked_dimensions( $post_id, $layout['total_seats'] ?? 0 );
			} else {
				// Clear layout if SVG parse failed.
				delete_post_meta( $post_id, Constants::META_VENUE_LAYOUT );
			}
		} else {
			delete_post_meta( $post_id, Constants::META_VENUE_LAYOUT );
		}

		// Layout JSON from hidden field (client-side parse) — only used as fallback.
		// Server re-parse is canonical, so we don't save the client-side layout.
	}

	/**
	 * Save pricing tiers.
	 *
	 * @param int $post_id
	 */
	private function save_pricing_data( int $post_id ): void {
		$tiers_json = isset( $_POST['clisyc_venue_pricing_tiers'] ) ? sanitize_text_field( wp_unslash( $_POST['clisyc_venue_pricing_tiers'] ) ) : '[]';
		$tiers      = json_decode( $tiers_json, true );

		if ( ! is_array( $tiers ) ) {
			$tiers = [];
		}

		// Sanitize each tier.
		$clean_tiers = [];
		foreach ( $tiers as $tier ) {
			if ( empty( $tier['category'] ) ) {
				continue;
			}
			$clean_tiers[] = [
				'category' => sanitize_key( $tier['category'] ),
				'price'    => absint( $tier['price'] ?? 0 ),
				'label'    => sanitize_text_field( $tier['label'] ?? '' ),
			];
		}

		update_post_meta( $post_id, Constants::META_SEAT_PRICING_TIERS, $clean_tiers );
	}

	/**
	 * Save venue location / address fields.
	 *
	 * @param int $post_id
	 */
	private function save_location_data( int $post_id ): void {
		$fields = [
			'clisyc_venue_address'     => Constants::META_VENUE_ADDRESS,
			'clisyc_venue_city'        => Constants::META_VENUE_CITY,
			'clisyc_venue_state'       => Constants::META_VENUE_STATE,
			'clisyc_venue_postal_code' => Constants::META_VENUE_POSTAL_CODE,
			'clisyc_venue_country'     => Constants::META_VENUE_COUNTRY,
		];

		foreach ( $fields as $input_name => $meta_key ) {
			$value = isset( $_POST[ $input_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $input_name ] ) ) : '';
			update_post_meta( $post_id, $meta_key, $value );
		}

		// Map embed URL — validate as URL.
		$map_embed = isset( $_POST['clisyc_venue_map_embed'] ) ? esc_url_raw( wp_unslash( $_POST['clisyc_venue_map_embed'] ) ) : '';
		update_post_meta( $post_id, Constants::META_VENUE_MAP_EMBED, $map_embed );
	}

	/**
	 * Apply admin category overrides to a parsed layout.
	 *
	 * @param array $layout    Parsed layout array.
	 * @param array $overrides Array of { section_id, category } pairs.
	 * @return array Modified layout.
	 */
	private function apply_category_overrides( array $layout, array $overrides ): array {
		$override_map = [];
		foreach ( $overrides as $o ) {
			if ( ! empty( $o['section_id'] ) && ! empty( $o['category'] ) ) {
				$override_map[ $o['section_id'] ] = sanitize_key( $o['category'] );
			}
		}

		if ( empty( $override_map ) ) {
			return $layout;
		}

		foreach ( $layout['sections'] as &$section ) {
			if ( isset( $override_map[ $section['id'] ] ) ) {
				$section['category'] = $override_map[ $section['id'] ];
				// Propagate to seats that inherit section category.
				foreach ( $section['rows'] as &$row ) {
					foreach ( $row['seats'] as &$seat ) {
						if ( empty( $seat['category'] ) || $seat['category'] === $section['category'] ) {
							$seat['category'] = $override_map[ $section['id'] ];
						}
					}
				}
			}
		}

		return $layout;
	}

	/**
	 * Sync venue seat count to any dimension items linked to this venue.
	 *
	 * @param int $venue_id
	 * @param int $total_seats
	 */
	private function sync_capacity_to_linked_dimensions( int $venue_id, int $total_seats ): void {
		if ( $total_seats <= 0 ) {
			return;
		}

		// Find all posts with _clisyc_linked_venue_id = this venue.
		$linked = get_posts( [
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'meta_query'     => [
				[
					'key'   => Constants::META_LINKED_VENUE_ID,
					'value' => $venue_id,
				],
			],
			'fields' => 'ids',
		] );

		foreach ( $linked as $dim_id ) {
			update_post_meta( $dim_id, Constants::META_CAPACITY, $total_seats );
		}
	}

	/**
	 * Enqueue admin assets for the venue editor screen.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || Constants::POST_TYPE_VENUE !== $screen->post_type ) {
			return;
		}

		$asset_file_path = clisyc_PLUGIN_DIR . 'assets/dist/pro-seat-selection/index.asset.php';
		if ( ! file_exists( $asset_file_path ) ) {
			return;
		}

		$asset = include $asset_file_path;

		$dependencies = $asset['dependencies'] ?? [];
		if ( ! in_array( 'wp-element', $dependencies, true ) ) {
			$dependencies[] = 'wp-element';
		}
		$dependencies[] = 'media-editor';

		wp_enqueue_media();

		wp_enqueue_script(
			'clisyc-pro-seat-selection',
			clisyc_PLUGIN_URL . 'assets/dist/pro-seat-selection/index.js',
			$dependencies,
			$asset['version'] ?? false,
			true
		);

		wp_enqueue_style(
			'clisyc-pro-seat-selection',
			clisyc_PLUGIN_URL . 'assets/dist/pro-seat-selection/style-index.css',
			[ 'wp-components' ],
			$asset['version'] ?? false
		);
	}
}
