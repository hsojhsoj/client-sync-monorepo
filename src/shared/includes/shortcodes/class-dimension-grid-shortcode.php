<?php
/**
 * Handles the [clisyc_dimension_grid] shortcode.
 *
 * Renders a responsive card grid of published dimension items
 * (services, rooms, practitioners, etc.) with CTA buttons that link to the
 * booking page with the item pre-selected for a seamless browse-to-book flow.
 *
 * Each card displays the item's assigned color (from the backend color picker)
 * as accent color on the CTA button, hover border, and placeholder.
 *
 * Also registered as [clisyc_services_grid] for backward compatibility.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Service_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dimension_Grid_Shortcode {

	/**
	 * Register the shortcode (canonical + backward-compat alias).
	 */
	public function register() {
		add_shortcode( 'clisyc_dimension_grid', [ $this, 'render_shortcode' ] );
		add_shortcode( 'clisyc_services_grid', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the dimension grid shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( [
			'dimension'     => '',         // dimension slug (e.g., "clisyc_room"); empty = primary
			'columns'       => '3',
			'limit'         => '-1',
			'orderby'       => 'title',
			'order'         => 'ASC',
			'show_image'    => 'true',
			'show_excerpt'  => 'true',
			'show_price'    => 'true',
			'show_duration' => 'true',
			'link_to'       => 'booking',  // 'booking' or 'single'
			'booking_page'  => '',         // custom URL override
			'button_text'   => 'auto',     // auto-generates from dimension label
		], $atts, 'clisyc_dimension_grid' );

		// Enqueue assets. The `clisyc-dimension-grid` handle is registered
		// inline here (rather than in Frontend::register_assets) so the
		// shortcode stays self-contained and can be dropped into sites that
		// haven't enqueued plugin-wide styles yet.
		wp_enqueue_style( 'clisyc-frontend-style' );
		if ( ! wp_style_is( 'clisyc-dimension-grid', 'registered' ) ) {
			wp_register_style(
				'clisyc-dimension-grid',
				clisyc_PLUGIN_URL . 'assets/css/clisyc-dimension-grid.css',
				[ 'dashicons', 'clisyc-frontend-style' ],
				CLISYC_VERSION
			);
		}
		wp_enqueue_style( 'clisyc-dimension-grid' );
		wp_enqueue_style( 'dashicons' );

		// Resolve the requested (or primary) dimension.
		$dimension = $this->resolve_dimension( sanitize_key( $atts['dimension'] ) );
		if ( ! $dimension ) {
			$msg = '' !== $atts['dimension']
				? 'Dimension "' . esc_html( $atts['dimension'] ) . '" not found or not enabled.'
				: 'No primary dimension configured.';
			return '<!-- Client Sync: ' . $msg . ' -->';
		}

		// Query published items.
		$order_value = in_array( strtoupper( $atts['order'] ), [ 'ASC', 'DESC' ], true )
			? strtoupper( $atts['order'] )
			: 'ASC';

		$items = get_posts( [
			'post_type'      => $dimension['slug'],
			'post_status'    => 'publish',
			'posts_per_page' => intval( $atts['limit'] ),
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'order'          => $order_value,
			'no_found_rows'  => true,
		] );

		if ( empty( $items ) ) {
			return '<p class="clisyc-no-items">' .
				sprintf(
					/* translators: %s: plural dimension label (e.g., "Services", "Rooms"). */
					esc_html__( 'No %s are currently available.', 'client-sync' ),
					esc_html( $dimension['plural'] )
				) .
				'</p>';
		}

		// Build data array for the view.
		$items_data = [];
		foreach ( $items as $item ) {
			$items_data[] = [
				'id'            => $item->ID,
				'title'         => $item->post_title,
				'excerpt'       => has_excerpt( $item->ID )
					? get_the_excerpt( $item )
					: wp_trim_words( $item->post_content, 25 ),
				'permalink'     => get_permalink( $item->ID ),
				'has_thumbnail' => has_post_thumbnail( $item->ID ),
				'thumbnail_id'  => get_post_thumbnail_id( $item->ID ),
				// Pass 0 as default so the view's `! empty()` check continues to hide
				// the duration line for items that don't have one configured (the
				// dimension grid is used for non-service dimensions too).
				'duration'      => Service_Helper::get_duration_minutes( (int) $item->ID, 0 ),
				'price'         => $this->get_item_price( $item->ID ),
				'booking_url'   => $this->get_booking_url( $item->ID, $dimension['slug'], $atts ),
				'color'         => get_post_meta( $item->ID, Constants::META_COLOR, true )
				                   ?: $this->get_default_color_for_dimension( $dimension['slug'] ),
			];
		}

		// Prepare template variables.
		$columns_count = max( 1, min( 6, absint( $atts['columns'] ) ) );
		$show_image    = filter_var( $atts['show_image'], FILTER_VALIDATE_BOOLEAN );
		$show_excerpt  = filter_var( $atts['show_excerpt'], FILTER_VALIDATE_BOOLEAN );
		$show_price    = filter_var( $atts['show_price'], FILTER_VALIDATE_BOOLEAN );
		$show_duration = filter_var( $atts['show_duration'], FILTER_VALIDATE_BOOLEAN );
		$dim_icon      = $dimension['icon'];
		$dim_slug      = $dimension['slug'];
		$link_to       = $atts['link_to'];

		$button_text = ( 'auto' === $atts['button_text'] )
			? $this->generate_button_text( $dimension['singular'] )
			: sanitize_text_field( $atts['button_text'] );

		ob_start();

		$view_file = clisyc_PLUGIN_DIR . 'includes/frontend/views/view-dimension-grid.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}

		return ob_get_clean();
	}

	/**
	 * Resolves a dimension from the registry.
	 *
	 * If a slug is provided, looks up that specific dimension.
	 * Otherwise, falls back to the primary dimension.
	 *
	 * @param string $requested_slug Optional dimension slug to look up.
	 * @return array|null Array with slug, singular, plural, icon keys, or null.
	 */
	private function resolve_dimension( string $requested_slug = '' ): ?array {
		$registry     = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$custom_types = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );

		// If a specific dimension was requested, look it up directly.
		if ( '' !== $requested_slug ) {
			$settings = $registry['dimensions'][ $requested_slug ] ?? null;
			if ( $settings && ! empty( $settings['enabled'] ) ) {
				return [
					'slug'     => $requested_slug,
					'singular' => $custom_types[ $requested_slug ]['singular']
					              ?? ucfirst( str_replace( 'clisyc_', '', $requested_slug ) ),
					'plural'   => $custom_types[ $requested_slug ]['plural']
					              ?? ucfirst( str_replace( 'clisyc_', '', $requested_slug ) ) . 's',
					'icon'     => $custom_types[ $requested_slug ]['icon']
					              ?? 'dashicons-admin-generic',
				];
			}
			return null;
		}

		// Fall back to primary dimension.
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) && ! empty( $settings['enabled'] ) ) {
				return [
					'slug'     => $slug,
					'singular' => $custom_types[ $slug ]['singular'] ?? ucfirst( str_replace( 'clisyc_', '', $slug ) ),
					'plural'   => $custom_types[ $slug ]['plural'] ?? ucfirst( str_replace( 'clisyc_', '', $slug ) ) . 's',
					'icon'     => $custom_types[ $slug ]['icon'] ?? 'dashicons-admin-generic',
				];
			}
		}

		return null;
	}

	/**
	 * Gets the default accent color for a dimension type.
	 *
	 * Provides sensible defaults based on common dimension naming conventions.
	 *
	 * @param string $dim_slug Full dimension slug (e.g., "clisyc_service").
	 * @return string Hex color code.
	 */
	private function get_default_color_for_dimension( string $dim_slug ): string {
		$defaults = [
			'service'      => '#3b82f6', // Blue.
			'practitioner' => '#8b5cf6', // Purple.
			'room'         => '#10b981', // Green.
			'location'     => '#f59e0b', // Amber.
			'vehicle'      => '#6366f1', // Indigo.
			'equipment'    => '#ec4899', // Pink.
			'instructor'   => '#8b5cf6', // Purple.
			'therapist'    => '#8b5cf6', // Purple.
			'technician'   => '#14b8a6', // Teal.
		];

		// Strip the clisyc_ prefix for lookup.
		$short_key = str_replace( 'clisyc_', '', $dim_slug );
		return $defaults[ $short_key ] ?? '#6b7280'; // Default gray.
	}

	/**
	 * Gets the display price for a dimension item.
	 *
	 * Checks for a linked WooCommerce product first, then falls back
	 * to the raw _clisyc_price meta field.
	 *
	 * @param int $post_id The dimension item post ID.
	 * @return array Array with 'raw' (float|null) and 'formatted' (string) keys.
	 */
	private function get_item_price( int $post_id ): array {
		// Try WooCommerce product first.
		$product_id = get_post_meta( $post_id, Constants::META_WC_PRODUCT_ID, true );
		if ( $product_id && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$price = (float) $product->get_price();
				return [
					'raw'       => $price,
					'formatted' => $price > 0 ? wp_strip_all_tags( wc_price( $price ) ) : '',
				];
			}
		}

		// Fall back to raw price meta.
		$raw_price = get_post_meta( $post_id, Constants::META_PRICE, true );
		if ( '' !== $raw_price && null !== $raw_price ) {
			$price = (float) $raw_price;
			if ( $price > 0 ) {
				$formatted = function_exists( 'wc_price' )
					? wp_strip_all_tags( wc_price( $price ) )
					: '$' . number_format( $price, 2 );
				return [
					'raw'       => $price,
					'formatted' => $formatted,
				];
			}
		}

		return [
			'raw'       => null,
			'formatted' => '',
		];
	}

	/**
	 * Builds the booking URL for an item.
	 *
	 * When link_to is 'booking', appends the dimension item ID as a query
	 * parameter so the booking form pre-selects it. Falls back to the
	 * single post permalink if no booking page is configured.
	 *
	 * @param int    $item_id  The dimension item post ID.
	 * @param string $dim_slug The dimension CPT slug.
	 * @param array  $atts     Shortcode attributes.
	 * @return string The URL to link to.
	 */
	private function get_booking_url( int $item_id, string $dim_slug, array $atts ): string {
		if ( 'single' === $atts['link_to'] ) {
			return get_permalink( $item_id );
		}

		// Determine booking page URL.
		if ( ! empty( $atts['booking_page'] ) ) {
			$base_url = esc_url( $atts['booking_page'] );
		} else {
			$booking_page_id = (int) get_option( Constants::OPTION_BOOKING_PAGE_ID, 0 );
			$base_url        = $booking_page_id ? get_permalink( $booking_page_id ) : '';
		}

		// Fall back to single post if no booking page configured.
		if ( ! $base_url ) {
			return get_permalink( $item_id );
		}

		// Prefix with "select_" to avoid conflict with the CPT query var.
		// WordPress interprets ?clisyc_service=376 as a CPT query (404).
		return add_query_arg( 'select_' . $dim_slug, $item_id, $base_url );
	}

	/**
	 * Generates the CTA button text from the dimension singular label.
	 *
	 * @param string $singular_label The singular label (e.g., "Service", "Room").
	 * @return string Translated button text (e.g., "Book Service").
	 */
	private function generate_button_text( string $singular_label ): string {
		/* translators: %s: The singular dimension label (e.g., "Service", "Room", "Property"). */
		return sprintf( __( 'Book %s', 'client-sync' ), $singular_label );
	}
}
