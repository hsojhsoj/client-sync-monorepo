<?php
/**
 * Handles the [clisyc_booking_wizard] shortcode.
 *
 * Renders a step-by-step, dimension-based booking wizard powered by React.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;
use DependentMedia\ClientSync\Utility\Service_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Wizard_Shortcode {

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'clisyc_booking_wizard', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the booking wizard shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		// Parse shortcode attributes.
		$atts = shortcode_atts(
			[
				'dimensions'        => '',      // Comma-separated dimension keys to include (empty = all enabled).
				'show_prices'       => 'true',  // Show prices where applicable.
				'show_descriptions' => 'true',  // Show descriptions.
			],
			$atts,
			'clisyc_booking_wizard'
		);

		// Enqueue WordPress dependencies.
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );

		// Check if asset file exists (built by webpack).
		$asset_file_path = clisyc_PLUGIN_DIR . 'assets/dist/booking-wizard/index.asset.php';

		if ( file_exists( $asset_file_path ) ) {
			$asset_file = include $asset_file_path;

			// Register and enqueue the booking wizard script.
			wp_register_script(
				'clisyc-booking-wizard',
				clisyc_PLUGIN_URL . 'assets/dist/booking-wizard/index.js',
				$asset_file['dependencies'],
				$asset_file['version'],
				true
			);

			// Register and enqueue the booking wizard styles.
			wp_register_style(
				'clisyc-booking-wizard-style',
				clisyc_PLUGIN_URL . 'assets/dist/booking-wizard/style-index.css',
				[],
				$asset_file['version']
			);

			wp_enqueue_script( 'clisyc-booking-wizard' );
			wp_enqueue_style( 'clisyc-booking-wizard-style' );

			// Get dimension registry.
			$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
			$dimension_types = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );

			// Debug: Log registry structure.
			Debug_Logger::log( 'Registry keys: ' . print_r( array_keys( $registry ), true ), 'BookingWizard' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			Debug_Logger::log( 'Dimension types: ' . print_r( array_keys( $dimension_types ), true ), 'BookingWizard' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

			// Get dimensions config - it's nested under 'dimensions' key
			$dimensions_config = $registry['dimensions'] ?? [];
			$filter_order = $registry['filter_order'] ?? array_keys( $dimensions_config );

			// Determine which dimensions to include.
			$dimension_filter = ! empty( $atts['dimensions'] )
				? array_map( 'trim', explode( ',', $atts['dimensions'] ) )
				: [];

			// Build dimensions array and data.
			$dimensions     = [];
			$dimension_data = [];
			$order_index    = 0;

			foreach ( $filter_order as $dim_key ) {
				// Get dimension config
				$dim_config = $dimensions_config[ $dim_key ] ?? null;
				if ( ! $dim_config || ! is_array( $dim_config ) ) {
					continue;
				}

				// Skip if not enabled.
				if ( empty( $dim_config['enabled'] ) ) {
					continue;
				}

				// Skip if not visible on frontend (e.g., Room might be hidden from clients).
				if ( empty( $dim_config['frontend_visible'] ) ) {
					continue;
				}

				// Skip if dimension filter is set and this dimension isn't in it.
				if ( ! empty( $dimension_filter ) && ! in_array( $dim_key, $dimension_filter, true ) ) {
					continue;
				}

				// The dimension key IS the post type (e.g., 'clisyc_service')
				$post_type = $dim_key;

				// Get labels from dimension_types option
				$type_config = $dimension_types[ $dim_key ] ?? [];
				$singular_label = $type_config['singular'] ?? ucfirst( str_replace( 'clisyc_', '', $dim_key ) );
				$plural_label = $type_config['plural'] ?? $singular_label . 's';

				// Debug: Log found dimension.
				Debug_Logger::log( 'Found dimension: ' . $dim_key . ' (' . $singular_label . ') - post_type: ' . $post_type, 'BookingWizard' );

				// Build dimension metadata.
				$dimensions[] = [
					'key'                   => $dim_key,
					'singular_label'        => $singular_label,
					'plural_label'          => $plural_label,
					'selection_prompt'      => $dim_config['selection_prompt'] ?? null,
					'selection_description' => $dim_config['selection_description'] ?? null,
					'order'                 => $order_index,
				];
				$order_index++;

				// Fetch items for this dimension - the dim_key IS the post_type.
				$posts = get_posts(
					[
						'post_type'      => $post_type,
						'posts_per_page' => 200,
						'no_found_rows'  => true,
						'post_status'    => 'publish',
						'orderby'        => 'title',
						'order'          => 'ASC',
					]
				);

				$items = [];
				foreach ( $posts as $post ) {
					$item = [
						'id'    => $post->ID,
						'name'  => $post->post_title,
						'color' => get_post_meta( $post->ID, Constants::META_COLOR, true ) ?: $this->get_default_color_for_dimension( $dim_key ),
					];

					// Add subtitle/title if available.
					$subtitle = get_post_meta( $post->ID, '_clisyc_title', true )
							 ?: get_post_meta( $post->ID, '_clisyc_subtitle', true );
					if ( $subtitle ) {
						$item['subtitle'] = $subtitle;
					}

					// Add duration if available. Previously read the wrong meta key
					// (`_clisyc_duration`) which was never written, so this branch
					// was effectively dead. Routed through Service_Helper with a 0
					// default to preserve the "omit duration when unset" behavior.
					$duration = Service_Helper::get_duration_minutes( (int) $post->ID, 0 );
					if ( $duration ) {
						$item['duration'] = $duration;
					}

					// Add price if enabled and available.
					if ( filter_var( $atts['show_prices'], FILTER_VALIDATE_BOOLEAN ) ) {
						$price = get_post_meta( $post->ID, Constants::META_PRICE, true );
						if ( $price ) {
							$item['price'] = '$' . number_format( floatval( $price ), 2 );
						}
					}

					// Add description if enabled.
					if ( filter_var( $atts['show_descriptions'], FILTER_VALIDATE_BOOLEAN ) ) {
						$excerpt = get_the_excerpt( $post );
						if ( $excerpt ) {
							$item['description'] = wp_trim_words( $excerpt, 20 );
						}
					}

					// Add avatar/image if available.
					$thumbnail = get_the_post_thumbnail_url( $post->ID, 'thumbnail' );
					if ( $thumbnail ) {
						$item['avatar'] = $thumbnail;
					}

					// Add compatibility data (which other dimension items this works with).
					$item['compatibleWith'] = $this->get_dimension_compatibility( $post->ID, $dim_key, $dimensions_config );

					$items[] = $item;
				}

				$dimension_data[ $dim_key ] = $items;
			}

			// Sort dimensions by order.
			usort(
				$dimensions,
				function ( $a, $b ) {
					return ( $a['order'] ?? 0 ) - ( $b['order'] ?? 0 );
				}
			);

			// Check if any dimensions are configured.
			$no_dimensions = empty( $dimensions );

			// Debug log dimension count.
			Debug_Logger::log( 'Total enabled dimensions found: ' . count( $dimensions ), 'BookingWizard' );

			// Prepare data to pass to JavaScript.
			$wizard_data = [
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'nonce'                  => wp_create_nonce( Constants::POST_TYPE_APPOINTMENT ),
				'calendarNonce'          => wp_create_nonce( 'clisyc_get_calendar_events_nonce' ),
				'dimensions'             => $dimensions,
				'dimensionData'          => $dimension_data,
				'noDimensionsConfigured' => $no_dimensions,
				'settingsUrl'            => admin_url( 'admin.php?page=clisyc-dimensions' ),
				'currentUser'            => is_user_logged_in() ? [
					'id'        => get_current_user_id(),
					'firstName' => wp_get_current_user()->first_name,
					'lastName'  => wp_get_current_user()->last_name,
					'email'     => wp_get_current_user()->user_email,
				] : null,
			];

			// Pre-selection from [clisyc_dimension_grid] CTA links.
			// URL params use "select_" prefix (e.g., ?select_clisyc_service=376).
			if ( ! empty( $filter_order ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$unslashed_get = wp_unslash( $_GET );
				foreach ( $filter_order as $slug ) {
					$param_key = 'select_' . $slug;
					if ( ! empty( $unslashed_get[ $param_key ] ) ) {
						$item_id = absint( $unslashed_get[ $param_key ] );
						if ( $item_id > 0 ) {
							$wizard_data['preselectedDimension'] = [
								'key'  => $slug,
								'id'   => $item_id,
								'name' => get_the_title( $item_id ),
							];
							break; // Only one pre-selection at a time.
						}
					}
				}
			}

			// Localize script with data.
			wp_localize_script( 'clisyc-booking-wizard', 'clisycBookingWizardData', $wizard_data );

		} else {
			// Asset file not found - development fallback or error.
			Debug_Logger::log( 'Asset file not found at ' . $asset_file_path, 'BookingWizard' );
			return '<!-- Client Sync: Booking wizard assets not found. Please run the build process. -->';
		}

		// Get color settings for CSS variables.
		$color_settings = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
		$inline_style   = '';

		if ( ! empty( $color_settings['accent_normal_bg'] ) ) {
			$inline_style .= '--clisyc-accent-bg: ' . esc_attr( $color_settings['accent_normal_bg'] ) . '; ';
		}
		if ( ! empty( $color_settings['accent_hover_bg'] ) ) {
			$inline_style .= '--clisyc-accent-hover-bg: ' . esc_attr( $color_settings['accent_hover_bg'] ) . '; ';
		}

		// Return the container div.
		return '<div id="clisyc-booking-wizard-root" class="clisyc-booking-wizard-app" style="' . esc_attr( $inline_style ) . '">
			<div class="clisyc-wizard-loading-placeholder" style="text-align: center; padding: 48px;">
				<p>Loading booking form...</p>
			</div>
		</div>';
	}

	/**
	 * Get default color for a dimension type.
	 *
	 * @param string $dim_key Dimension key.
	 * @return string Hex color.
	 */
	private function get_default_color_for_dimension( string $dim_key ): string {
		// Provide sensible defaults based on common dimension types.
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

		return $defaults[ $dim_key ] ?? '#6b7280'; // Default gray.
	}

	/**
	 * Get compatibility data for a dimension item.
	 *
	 * This determines which items from other dimensions this item can be paired with
	 * by querying the clisyc_relationships table.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $dim_key  Current dimension key (e.g., 'clisyc_service').
	 * @param array  $registry Dimension registry.
	 * @return array Compatibility map keyed by dimension slug.
	 */
	private function get_dimension_compatibility( int $post_id, string $dim_key, array $registry ): array {
		global $wpdb;

		$compatibility = [];
		$table_name    = $wpdb->prefix . 'clisyc_relationships';

		// Check if table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( ! $table_exists ) {
			return $compatibility;
		}

		// Query relationships where this post is the PARENT (smaller ID).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix and escaped.
		$as_parent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT child_object_id, child_object_type FROM " . esc_sql( $table_name ) . " WHERE parent_object_id = %d",
				$post_id
			),
			ARRAY_A
		);

		// Query relationships where this post is the CHILD (larger ID).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix and escaped.
		$as_child = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT parent_object_id, parent_object_type FROM " . esc_sql( $table_name ) . " WHERE child_object_id = %d",
				$post_id
			),
			ARRAY_A
		);

		// Process "as parent" results - the related item is the child.
		if ( ! empty( $as_parent ) ) {
			foreach ( $as_parent as $row ) {
				$related_type = $row['child_object_type'];
				$related_id   = (int) $row['child_object_id'];

				if ( ! isset( $compatibility[ $related_type ] ) ) {
					$compatibility[ $related_type ] = [];
				}
				$compatibility[ $related_type ][] = $related_id;
			}
		}

		// Process "as child" results - the related item is the parent.
		if ( ! empty( $as_child ) ) {
			foreach ( $as_child as $row ) {
				$related_type = $row['parent_object_type'];
				$related_id   = (int) $row['parent_object_id'];

				if ( ! isset( $compatibility[ $related_type ] ) ) {
					$compatibility[ $related_type ] = [];
				}
				$compatibility[ $related_type ] = array_values( array_unique( $compatibility[ $related_type ] ) );
				$compatibility[ $related_type ][] = $related_id;
			}
		}

		// Remove duplicates from each dimension.
		foreach ( $compatibility as $dim => $ids ) {
			$compatibility[ $dim ] = array_values( array_unique( $ids ) );
		}

		return $compatibility;
	}
}
