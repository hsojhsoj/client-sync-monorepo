<?php
/**
 * File: src/pro/includes/modules/seat-selection/class-seat-booking-handler.php
 * Hooks into the booking flow to validate and confirm seat holds.
 *
 * Listens to:
 *  - clisyc_inside_reserve_slot_transaction: Confirms seats inside the booking transaction
 *  - wp_trash_post / before_delete_post: Frees seats when appointment is cancelled/deleted
 *  - ACTION_FREQUENT_MAINTENANCE: Purges expired seat holds
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Seat_Selection
 */

namespace ClientSyncPro\Modules\Seat_Selection;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Table_Schema_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seat_Booking_Handler {

	/**
	 * @var Seat_Hold_Manager
	 */
	private $hold_manager;

	/**
	 * @var Table_Schema_Manager
	 */
	private $schema;

	public function __construct( Seat_Hold_Manager $hold_manager = null, Table_Schema_Manager $schema = null ) {
		$this->hold_manager = $hold_manager ?: new Seat_Hold_Manager();
		$this->schema       = $schema ?: new Table_Schema_Manager();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		// Confirm seats inside the booking transaction.
		add_action( 'clisyc_inside_reserve_slot_transaction', [ $this, 'confirm_seats' ], 10, 2 );

		// Free seats when appointment is trashed or deleted.
		add_action( 'wp_trash_post', [ $this, 'on_appointment_trashed' ] );
		add_action( 'before_delete_post', [ $this, 'on_appointment_deleted' ] );

		// Purge expired holds during cron maintenance.
		add_action( Constants::ACTION_FREQUENT_MAINTENANCE, [ $this, 'purge_expired_holds' ] );

		// Add seat pricing to basket line items.
		add_filter( 'clisyc_basket_line_items', [ $this, 'add_seat_pricing_to_basket' ], 10, 3 );

		// Admin column: show booked seats on appointment list.
		add_filter( 'manage_clisyc_appointment_posts_columns', [ $this, 'add_seats_column' ] );
		add_action( 'manage_clisyc_appointment_posts_custom_column', [ $this, 'render_seats_column' ], 10, 2 );

		// Frontend: populate seat details on appointment detail page.
		add_filter( 'clisyc_appointment_seat_details', [ $this, 'get_seat_details_for_appointment' ], 10, 2 );

		// Frontend: populate QR code data on appointment detail page.
		add_filter( 'clisyc_appointment_qr_code', [ $this, 'get_qr_code_for_appointment' ], 10, 2 );

		// Frontend: populate venue map SVG on appointment detail page.
		add_filter( 'clisyc_appointment_venue_map', [ $this, 'get_venue_map_for_appointment' ], 10, 2 );

		// Frontend: populate venue location (address + map) on appointment detail page.
		add_filter( 'clisyc_appointment_venue_location', [ $this, 'get_venue_location_for_appointment' ], 10, 2 );
	}

	/**
	 * Confirm seat holds inside the booking transaction.
	 *
	 * Called via do_action('clisyc_inside_reserve_slot_transaction').
	 * Reads selected_seats + seat_session_token from $_POST.
	 * Moves holds to seat_bookings table. Throws exception on failure
	 * which causes the entire booking transaction to roll back.
	 *
	 * @param int $appointment_id
	 * @param int $slot_id
	 * @throws \RuntimeException If seat confirmation fails.
	 */
	public function confirm_seats( int $appointment_id, int $slot_id ): void {
		// Check if this booking includes seat selections.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked by the booking handler.
		$seats_json    = isset( $_POST['selected_seats'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_seats'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_token = isset( $_POST['seat_session_token'] ) ? sanitize_text_field( wp_unslash( $_POST['seat_session_token'] ) ) : '';

		if ( empty( $seats_json ) || empty( $session_token ) ) {
			return; // No seat selection — standard booking, nothing to do.
		}

		$seat_ids = json_decode( $seats_json, true );
		if ( ! is_array( $seat_ids ) || empty( $seat_ids ) ) {
			return;
		}

		// Determine venue ID from the slot's linked dimension item.
		$venue_id = $this->resolve_venue_id_for_slot( $slot_id );
		if ( ! $venue_id ) {
			throw new \RuntimeException( __( 'Could not determine venue for seat confirmation.', 'client-sync-pro' ) );
		}

		// Get layout for category lookup.
		$layout = get_post_meta( $venue_id, Constants::META_VENUE_LAYOUT, true );
		$seat_categories = $this->build_seat_category_map( $layout );

		// Get pricing tiers for price lookup.
		$pricing_tiers = get_post_meta( $venue_id, Constants::META_SEAT_PRICING_TIERS, true );
		$price_map     = $this->build_price_map( $pricing_tiers );

		global $wpdb;
		$holds_table    = $this->schema->get_seat_holds_table_name();
		$bookings_table = $this->schema->get_seat_bookings_table_name();

		foreach ( $seat_ids as $seat_id ) {
			$seat_id = sanitize_text_field( $seat_id );

			// Verify this session actually holds this seat.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$hold = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT hold_id FROM {$holds_table} WHERE venue_id = %d AND slot_id = %d AND seat_id = %s AND session_token = %s",
					$venue_id,
					$slot_id,
					$seat_id,
					$session_token
				)
			);

			if ( ! $hold ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: %s: seat ID */
						__( 'Seat hold expired or not found for seat %s. Please try again.', 'client-sync-pro' ),
						$seat_id
					)
				);
			}

			$category  = $seat_categories[ $seat_id ] ?? '';
			$seat_price = $price_map[ $category ] ?? 0;

			// Insert confirmed seat booking.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert(
				$bookings_table,
				[
					'venue_id'       => $venue_id,
					'slot_id'        => $slot_id,
					'seat_id'        => $seat_id,
					'appointment_id' => $appointment_id,
					'seat_category'  => $category,
					'seat_price'     => $seat_price,
				],
				[ '%d', '%d', '%s', '%d', '%s', '%d' ]
			);

			if ( false === $inserted ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: %s: seat ID */
						__( 'Failed to confirm seat %s. It may have been booked by someone else.', 'client-sync-pro' ),
						$seat_id
					)
				);
			}

			// Remove the hold now that it's confirmed.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $holds_table, [ 'hold_id' => $hold->hold_id ], [ '%d' ] );
		}

		// Save seat IDs on the appointment post for reference.
		update_post_meta( $appointment_id, Constants::META_SELECTED_SEATS, $seat_ids );

		// Generate a unique QR token for check-in if not already present.
		if ( ! get_post_meta( $appointment_id, Constants::META_QR_TOKEN, true ) ) {
			$qr_token = wp_generate_password( 32, false );
			update_post_meta( $appointment_id, Constants::META_QR_TOKEN, $qr_token );
		}
	}

	/**
	 * Free seats when an appointment is trashed.
	 *
	 * @param int $post_id
	 */
	public function on_appointment_trashed( int $post_id ): void {
		$this->free_appointment_seats( $post_id );
	}

	/**
	 * Free seats when an appointment is permanently deleted.
	 *
	 * @param int $post_id
	 */
	public function on_appointment_deleted( int $post_id ): void {
		$this->free_appointment_seats( $post_id );
	}

	/**
	 * Delete seat bookings associated with an appointment.
	 *
	 * @param int $post_id
	 */
	private function free_appointment_seats( int $post_id ): void {
		if ( Constants::POST_TYPE_APPOINTMENT !== get_post_type( $post_id ) ) {
			return;
		}

		global $wpdb;
		$table = $this->schema->get_seat_bookings_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, [ 'appointment_id' => $post_id ], [ '%d' ] );

		delete_post_meta( $post_id, Constants::META_SELECTED_SEATS );
	}

	/**
	 * Purge expired seat holds (cron callback).
	 */
	public function purge_expired_holds(): void {
		$this->hold_manager->purge_expired_holds();
	}

	/**
	 * Resolve the venue ID linked to a time slot's primary dimension item.
	 *
	 * @param int $slot_id
	 * @return int Venue ID, or 0.
	 */
	private function resolve_venue_id_for_slot( int $slot_id ): int {
		global $wpdb;

		$dims_table = $this->schema->get_dimensions_table_name();

		// Get the primary service dimension value for this slot.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$service_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT dimension_value FROM {$dims_table} WHERE slot_id = %d AND dimension_key = 'clisyc_service' LIMIT 1",
				$slot_id
			)
		);

		if ( ! $service_id ) {
			return 0;
		}

		$venue_id = (int) get_post_meta( (int) $service_id, Constants::META_LINKED_VENUE_ID, true );
		return $venue_id;
	}

	/**
	 * Build a flat map of seat_id → category from the layout.
	 *
	 * @param array|mixed $layout
	 * @return array
	 */
	private function build_seat_category_map( $layout ): array {
		$map = [];
		if ( ! is_array( $layout ) || empty( $layout['sections'] ) ) {
			return $map;
		}

		foreach ( $layout['sections'] as $section ) {
			$section_cat = $section['category'] ?? '';
			foreach ( $section['rows'] ?? [] as $row ) {
				foreach ( $row['seats'] ?? [] as $seat ) {
					$map[ $seat['id'] ] = ! empty( $seat['category'] ) ? $seat['category'] : $section_cat;
				}
			}
		}

		return $map;
	}

	/**
	 * Build a map of category → price from pricing tiers.
	 *
	 * @param array|mixed $tiers
	 * @return array
	 */
	private function build_price_map( $tiers ): array {
		$map = [];
		if ( ! is_array( $tiers ) ) {
			return $map;
		}

		foreach ( $tiers as $tier ) {
			if ( ! empty( $tier['category'] ) ) {
				$map[ $tier['category'] ] = (int) ( $tier['price'] ?? 0 );
			}
		}

		return $map;
	}

	/**
	 * Add seat pricing line items to the basket.
	 *
	 * Hooked to 'clisyc_basket_line_items' filter.
	 * Reads selected seat count from the request param 'selected_seats'.
	 *
	 * @param array            $line_items Current line items.
	 * @param float            $total_cost Current total.
	 * @param \WP_REST_Request $request    The basket request.
	 * @return array Modified line items.
	 */
	public function add_seat_pricing_to_basket( array $line_items, float $total_cost, $request ): array {
		$selected_seats_json = $request->get_param( 'selected_seats' );
		if ( empty( $selected_seats_json ) ) {
			return $line_items;
		}

		$seat_ids = json_decode( $selected_seats_json, true );
		if ( ! is_array( $seat_ids ) || empty( $seat_ids ) ) {
			return $line_items;
		}

		// Resolve venue from primary dimension.
		$primary_id = (int) $request->get_param( 'primary_id' );
		if ( ! $primary_id ) {
			return $line_items;
		}

		$venue_id = absint( get_post_meta( $primary_id, Constants::META_LINKED_VENUE_ID, true ) );
		if ( ! $venue_id ) {
			return $line_items;
		}

		$layout        = get_post_meta( $venue_id, Constants::META_VENUE_LAYOUT, true );
		$pricing_tiers = get_post_meta( $venue_id, Constants::META_SEAT_PRICING_TIERS, true );

		if ( ! is_array( $pricing_tiers ) || empty( $pricing_tiers ) ) {
			return $line_items; // No seat pricing configured.
		}

		$seat_categories = $this->build_seat_category_map( $layout );
		$price_map       = $this->build_price_map( $pricing_tiers );

		// Build label map from tiers.
		$label_map = [];
		foreach ( $pricing_tiers as $tier ) {
			if ( ! empty( $tier['category'] ) ) {
				$label_map[ $tier['category'] ] = $tier['label'] ?? ucfirst( $tier['category'] );
			}
		}

		// Group seats by category for line item display.
		$category_counts = [];
		foreach ( $seat_ids as $seat_id ) {
			$cat = $seat_categories[ $seat_id ] ?? '';
			if ( empty( $cat ) || ! isset( $price_map[ $cat ] ) ) {
				continue;
			}
			if ( ! isset( $category_counts[ $cat ] ) ) {
				$category_counts[ $cat ] = 0;
			}
			$category_counts[ $cat ]++;
		}

		foreach ( $category_counts as $category => $count ) {
			$unit_price = $price_map[ $category ] / 100; // Convert from cents.
			$line_total = $unit_price * $count;
			$label      = $label_map[ $category ] ?? ucfirst( $category );

			$line_items[] = [
				'label' => sprintf(
					/* translators: 1: seat count, 2: category label */
					__( '%1$d × %2$s seat', 'client-sync-pro' ),
					$count,
					$label
				),
				'meta'  => __( 'Seat Selection', 'client-sync-pro' ),
				'price' => $line_total,
				'type'  => 'seat',
			];
		}

		return $line_items;
	}

	/**
	 * Add "Seats" column to the appointment admin list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_seats_column( array $columns ): array {
		// Insert before the "date" column (WordPress default last column).
		$new_columns = [];
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$new_columns['clisyc_seats'] = __( 'Seats', 'client-sync-pro' );
			}
			$new_columns[ $key ] = $label;
		}

		// If 'date' wasn't found, append at the end.
		if ( ! isset( $new_columns['clisyc_seats'] ) ) {
			$new_columns['clisyc_seats'] = __( 'Seats', 'client-sync-pro' );
		}

		return $new_columns;
	}

	/**
	 * Render the "Seats" column content.
	 *
	 * Shows the number of booked seats and a tooltip with seat IDs.
	 *
	 * @param string $column_name Column key.
	 * @param int    $post_id     Appointment post ID.
	 */
	public function render_seats_column( string $column_name, int $post_id ): void {
		if ( 'clisyc_seats' !== $column_name ) {
			return;
		}

		$seat_ids = get_post_meta( $post_id, Constants::META_SELECTED_SEATS, true );

		if ( ! is_array( $seat_ids ) || empty( $seat_ids ) ) {
			echo '—';
			return;
		}

		$count = count( $seat_ids );
		$readable_ids = array_map( function ( $id ) {
			// Convert "section-a-row-1-seat-5" to "A/1/5" for compact display.
			$parts = explode( '-', str_replace( [ 'section-', 'row-', 'seat-' ], '', $id ) );
			return implode( '/', $parts );
		}, array_slice( $seat_ids, 0, 5 ) );

		$summary = implode( ', ', $readable_ids );
		if ( $count > 5 ) {
			/* translators: %d: number of additional seats */
			$summary .= sprintf( __( ' +%d more', 'client-sync-pro' ), $count - 5 );
		}

		printf(
			'<span title="%s" style="cursor:help;">%s</span>',
			esc_attr( implode( ', ', $seat_ids ) ),
			esc_html(
				sprintf(
					/* translators: 1: seat count, 2: short seat list */
					__( '%1$d (%2$s)', 'client-sync-pro' ),
					$count,
					$summary
				)
			)
		);
	}

	/**
	 * Get human-readable seat details for an appointment.
	 *
	 * Hooked to 'clisyc_appointment_seat_details' filter.
	 * Returns an array of seat detail items for template rendering.
	 *
	 * @param array $details  Current details (empty from free plugin).
	 * @param int   $appointment_id Appointment post ID.
	 * @return array Array of [ 'seat_id', 'section', 'row', 'seat', 'category' ].
	 */
	public function get_seat_details_for_appointment( array $details, int $appointment_id ): array {
		$seat_ids = get_post_meta( $appointment_id, Constants::META_SELECTED_SEATS, true );
		if ( ! is_array( $seat_ids ) || empty( $seat_ids ) ) {
			return $details;
		}

		// Resolve venue ID from the appointment's slot dimensions.
		$venue_id = $this->resolve_venue_id_for_appointment( $appointment_id );
		if ( ! $venue_id ) {
			// Fallback: return seat IDs without human labels.
			foreach ( $seat_ids as $seat_id ) {
				$details[] = [
					'seat_id' => $seat_id,
					'section' => '',
					'row'     => '',
					'seat'    => $seat_id,
					'category' => '',
				];
			}
			return $details;
		}

		$layout = get_post_meta( $venue_id, Constants::META_VENUE_LAYOUT, true );
		if ( ! is_array( $layout ) || empty( $layout['sections'] ) ) {
			return $details;
		}

		// Build a lookup map: seat_id → { section_label, row_label, seat_label, category }.
		$lookup = [];
		foreach ( $layout['sections'] as $section ) {
			$section_label = $section['label'] ?? '';
			$section_cat   = $section['category'] ?? '';
			foreach ( $section['rows'] ?? [] as $row ) {
				$row_label = $row['label'] ?? '';
				foreach ( $row['seats'] ?? [] as $seat ) {
					$lookup[ $seat['id'] ] = [
						'section'  => $section_label,
						'row'      => $row_label,
						'seat'     => $seat['label'] ?? $seat['id'],
						'category' => ! empty( $seat['category'] ) ? $seat['category'] : $section_cat,
					];
				}
			}
		}

		foreach ( $seat_ids as $seat_id ) {
			if ( isset( $lookup[ $seat_id ] ) ) {
				$info = $lookup[ $seat_id ];
				$details[] = [
					'seat_id'  => $seat_id,
					'section'  => $info['section'],
					'row'      => $info['row'],
					'seat'     => $info['seat'],
					'category' => $info['category'],
				];
			} else {
				$details[] = [
					'seat_id'  => $seat_id,
					'section'  => '',
					'row'      => '',
					'seat'     => $seat_id,
					'category' => '',
				];
			}
		}

		return $details;
	}

	/**
	 * Get the QR code payload for an appointment.
	 *
	 * Hooked to 'clisyc_appointment_qr_code' filter.
	 * Returns the URL-encoded QR payload string for JS rendering.
	 *
	 * @param string $qr_data       Current QR data (empty from free plugin).
	 * @param int    $appointment_id Appointment post ID.
	 * @return string QR payload URL or empty string.
	 */
	public function get_qr_code_for_appointment( string $qr_data, int $appointment_id ): string {
		$qr_token = get_post_meta( $appointment_id, Constants::META_QR_TOKEN, true );

		// Retroactively generate a QR token for existing bookings that have seats but no token.
		if ( empty( $qr_token ) ) {
			$seat_ids = get_post_meta( $appointment_id, Constants::META_SELECTED_SEATS, true );
			if ( is_array( $seat_ids ) && ! empty( $seat_ids ) ) {
				$qr_token = wp_generate_password( 32, false );
				update_post_meta( $appointment_id, Constants::META_QR_TOKEN, $qr_token );
			}
		}

		if ( empty( $qr_token ) ) {
			return $qr_data;
		}

		return add_query_arg(
			[
				'clisyc_checkin' => $qr_token,
			],
			home_url( '/' )
		);
	}

	/**
	 * Get the venue SVG map data for an appointment.
	 *
	 * Hooked to 'clisyc_appointment_venue_map' filter.
	 * Returns the raw SVG markup plus arrays of all seat element IDs
	 * and selected (booked) seat element IDs for JS highlighting.
	 *
	 * @param array $data           Current data (empty from free plugin).
	 * @param int   $appointment_id Appointment post ID.
	 * @return array { svg_markup: string, all_seat_element_ids: string[], selected_seat_element_ids: string[] }
	 */
	public function get_venue_map_for_appointment( array $data, int $appointment_id ): array {
		$seat_ids = get_post_meta( $appointment_id, Constants::META_SELECTED_SEATS, true );
		if ( ! is_array( $seat_ids ) || empty( $seat_ids ) ) {
			return $data;
		}

		$venue_id = $this->resolve_venue_id_for_appointment( $appointment_id );
		if ( ! $venue_id ) {
			return $data;
		}

		$svg_markup = get_post_meta( $venue_id, Constants::META_VENUE_SVG, true );
		if ( empty( $svg_markup ) ) {
			return $data;
		}

		$layout = get_post_meta( $venue_id, Constants::META_VENUE_LAYOUT, true );
		if ( ! is_array( $layout ) || empty( $layout['sections'] ) ) {
			return $data;
		}

		// Build list of ALL seat SVG element IDs from the layout.
		$all_seat_element_ids      = [];
		$selected_seat_element_ids = [];
		$selected_set              = array_flip( $seat_ids );

		foreach ( $layout['sections'] as $section ) {
			foreach ( $section['rows'] ?? [] as $row ) {
				foreach ( $row['seats'] ?? [] as $seat ) {
					$el_id = $seat['svg_element_id'] ?? $seat['id'];
					$all_seat_element_ids[] = $el_id;

					if ( isset( $selected_set[ $seat['id'] ] ) ) {
						$selected_seat_element_ids[] = $el_id;
					}
				}
			}
		}

		return [
			'svg_markup'                => $svg_markup,
			'all_seat_element_ids'      => $all_seat_element_ids,
			'selected_seat_element_ids' => $selected_seat_element_ids,
		];
	}

	/**
	 * Get venue location data (address + map embed URL) for an appointment.
	 *
	 * Populates the 'clisyc_appointment_venue_location' filter.
	 *
	 * @param array $data           Existing data (default empty).
	 * @param int   $appointment_id Appointment post ID.
	 * @return array {
	 *   @type string $venue_name   Venue display name.
	 *   @type string $address      Street address.
	 *   @type string $city         City.
	 *   @type string $state        State / Province.
	 *   @type string $postal_code  Postal / Zip code.
	 *   @type string $country      Country.
	 *   @type string $map_embed    Map embed URL.
	 * }
	 */
	public function get_venue_location_for_appointment( array $data, int $appointment_id ): array {
		$venue_id = $this->resolve_venue_id_for_appointment( $appointment_id );
		if ( ! $venue_id ) {
			return $data;
		}

		$address     = get_post_meta( $venue_id, Constants::META_VENUE_ADDRESS, true );
		$city        = get_post_meta( $venue_id, Constants::META_VENUE_CITY, true );
		$state       = get_post_meta( $venue_id, Constants::META_VENUE_STATE, true );
		$postal_code = get_post_meta( $venue_id, Constants::META_VENUE_POSTAL_CODE, true );
		$country     = get_post_meta( $venue_id, Constants::META_VENUE_COUNTRY, true );
		$map_embed   = get_post_meta( $venue_id, Constants::META_VENUE_MAP_EMBED, true );

		// Only return data if there's at least an address to show.
		if ( empty( $address ) && empty( $city ) && empty( $map_embed ) ) {
			return $data;
		}

		// Venue featured image (photo).
		$venue_photo_url = '';
		$thumbnail_id    = get_post_thumbnail_id( $venue_id );
		if ( $thumbnail_id ) {
			$img_src = wp_get_attachment_image_src( $thumbnail_id, 'large' );
			if ( $img_src ) {
				$venue_photo_url = $img_src[0];
			}
		}

		return [
			'venue_name'  => get_the_title( $venue_id ),
			'address'     => $address,
			'city'        => $city,
			'state'       => $state,
			'postal_code' => $postal_code,
			'country'     => $country,
			'map_embed'   => $map_embed,
			'venue_photo' => $venue_photo_url,
		];
	}

	/**
	 * Resolve the venue ID from an appointment's slot dimensions.
	 *
	 * @param int $appointment_id
	 * @return int Venue ID, or 0.
	 */
	private function resolve_venue_id_for_appointment( int $appointment_id ): int {
		$dimensions = get_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, true );
		if ( ! is_array( $dimensions ) || empty( $dimensions ) ) {
			return 0;
		}

		// Find the primary service dimension and look up its linked venue.
		$primary_dim_slug = get_option( Constants::OPTION_PRIMARY_SERVICE_DIM, '' );
		$service_id       = 0;

		if ( ! empty( $primary_dim_slug ) && isset( $dimensions[ $primary_dim_slug ] ) ) {
			$service_id = (int) $dimensions[ $primary_dim_slug ];
		} else {
			// Fallback: try the first dimension.
			$service_id = (int) reset( $dimensions );
		}

		if ( ! $service_id ) {
			return 0;
		}

		return absint( get_post_meta( $service_id, Constants::META_LINKED_VENUE_ID, true ) );
	}
}
