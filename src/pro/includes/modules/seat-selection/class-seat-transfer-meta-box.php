<?php
/**
 * File: src/pro/includes/modules/seat-selection/class-seat-transfer-meta-box.php
 * Admin meta box for viewing, releasing, and reassigning seats on appointments.
 *
 * Shows current booked seats with individual release (×) buttons, and a
 * text input for adding new seat IDs. AJAX-powered save to update the
 * seat_bookings table + appointment post meta in real-time.
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

class Seat_Transfer_Meta_Box {

	/**
	 * @var Table_Schema_Manager
	 */
	private $schema;

	public function __construct( Table_Schema_Manager $schema = null ) {
		$this->schema = $schema ?: new Table_Schema_Manager();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'wp_ajax_clisyc_seat_transfer_release', [ $this, 'ajax_release_seat' ] );
		add_action( 'wp_ajax_clisyc_seat_transfer_add', [ $this, 'ajax_add_seat' ] );
	}

	/**
	 * Register the meta box on the appointment edit screen.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'clisyc-seat-transfer',
			__( 'Seat Management', 'client-sync-pro' ),
			[ $this, 'render_meta_box' ],
			Constants::POST_TYPE_APPOINTMENT,
			'side',
			'default'
		);
	}

	/**
	 * Render the seat management meta box.
	 *
	 * @param \WP_Post $post Appointment post.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		$seat_ids = get_post_meta( $post->ID, Constants::META_SELECTED_SEATS, true );
		$seat_ids = is_array( $seat_ids ) ? $seat_ids : [];

		// Resolve venue for this appointment.
		$venue_id = $this->resolve_venue_id( $post->ID );

		if ( empty( $seat_ids ) && ! $venue_id ) {
			echo '<p class="description">' . esc_html__( 'No seats assigned to this appointment.', 'client-sync-pro' ) . '</p>';
			return;
		}

		// Get seat labels from layout.
		$seat_labels = [];
		if ( $venue_id ) {
			$layout = get_post_meta( $venue_id, Constants::META_VENUE_LAYOUT, true );
			if ( is_array( $layout ) && ! empty( $layout['sections'] ) ) {
				foreach ( $layout['sections'] as $section ) {
					foreach ( $section['rows'] ?? [] as $row ) {
						foreach ( $row['seats'] ?? [] as $seat ) {
							$label = '';
							if ( ! empty( $section['label'] ) ) {
								$label .= $section['label'] . ' · ';
							}
							if ( ! empty( $row['label'] ) ) {
								$label .= $row['label'] . ' · ';
							}
							$label .= $seat['label'] ?? $seat['id'];
							$seat_labels[ $seat['id'] ] = $label;
						}
					}
				}
			}
		}

		wp_nonce_field( 'clisyc_seat_transfer', 'clisyc_seat_transfer_nonce' );
		?>
		<div id="clisyc-seat-transfer-wrap"
			 data-appointment-id="<?php echo esc_attr( $post->ID ); ?>"
			 data-venue-id="<?php echo esc_attr( $venue_id ); ?>"
			 data-nonce="<?php echo esc_attr( wp_create_nonce( 'clisyc_seat_transfer' ) ); ?>">

			<?php if ( ! empty( $seat_ids ) ) : ?>
				<p class="description" style="margin-bottom:8px;">
					<strong><?php echo count( $seat_ids ); ?></strong>
					<?php echo esc_html( _n( 'seat assigned', 'seats assigned', count( $seat_ids ), 'client-sync-pro' ) ); ?>
				</p>

				<ul id="clisyc-seat-transfer-list" style="margin:0 0 12px;padding:0;list-style:none;">
					<?php foreach ( $seat_ids as $seat_id ) : ?>
						<li class="clisyc-seat-transfer-item" data-seat-id="<?php echo esc_attr( $seat_id ); ?>"
							style="display:flex;align-items:center;justify-content:space-between;padding:6px 8px;margin-bottom:4px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
							<span class="clisyc-seat-transfer-label" style="font-weight:500;color:#1e293b;">
								<?php echo esc_html( $seat_labels[ $seat_id ] ?? $seat_id ); ?>
							</span>
							<button type="button"
									class="clisyc-seat-release-btn"
									data-seat-id="<?php echo esc_attr( $seat_id ); ?>"
									style="background:#fee2e2;border:none;color:#dc2626;cursor:pointer;border-radius:4px;padding:2px 6px;font-size:11px;font-weight:600;line-height:1.4;"
									title="<?php esc_attr_e( 'Release this seat', 'client-sync-pro' ); ?>">
								&times;
							</button>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p id="clisyc-seat-transfer-empty" class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'No seats currently assigned.', 'client-sync-pro' ); ?>
				</p>
				<ul id="clisyc-seat-transfer-list" style="margin:0 0 12px;padding:0;list-style:none;"></ul>
			<?php endif; ?>

			<?php if ( $venue_id ) : ?>
				<div style="border-top:1px solid #e2e8f0;padding-top:10px;">
					<label for="clisyc-seat-add-input" style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px;">
						<?php esc_html_e( 'Add Seat ID:', 'client-sync-pro' ); ?>
					</label>
					<div style="display:flex;gap:4px;">
						<input type="text" id="clisyc-seat-add-input"
							   placeholder="<?php esc_attr_e( 'e.g. section-a-row-1-seat-5', 'client-sync-pro' ); ?>"
							   style="flex:1;font-size:12px;padding:4px 8px;min-height:30px;"
							   class="widefat" />
						<button type="button" id="clisyc-seat-add-btn" class="button button-small"
								style="min-height:30px;">
							<?php esc_html_e( 'Add', 'client-sync-pro' ); ?>
						</button>
					</div>
					<p class="description" style="margin-top:4px;font-size:11px;">
						<?php esc_html_e( 'Enter a seat ID from the venue layout to assign it to this appointment.', 'client-sync-pro' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div id="clisyc-seat-transfer-status" style="display:none;margin-top:8px;padding:6px 10px;border-radius:4px;font-size:12px;"></div>
		</div>

		<script>
		jQuery( function( $ ) {
			var $wrap   = $( '#clisyc-seat-transfer-wrap' );
			var apptId  = $wrap.data( 'appointment-id' );
			var nonce   = $wrap.data( 'nonce' );
			var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

			function showStatus( msg, type ) {
				var bg = type === 'success' ? '#d1fae5' : '#fee2e2';
				var color = type === 'success' ? '#065f46' : '#991b1b';
				$( '#clisyc-seat-transfer-status' ).text( msg ).css( { background: bg, color: color } ).show();
				setTimeout( function() { $( '#clisyc-seat-transfer-status' ).fadeOut( 300 ); }, 3000 );
			}

			// Release seat.
			$wrap.on( 'click', '.clisyc-seat-release-btn', function() {
				var $btn = $( this );
				var seatId = $btn.data( 'seat-id' );
				if ( ! confirm( '<?php echo esc_js( __( 'Release this seat? It will become available for others.', 'client-sync-pro' ) ); ?>' ) ) return;

				$btn.prop( 'disabled', true ).text( '...' );
				$.post( ajaxUrl, {
					action: 'clisyc_seat_transfer_release',
					nonce: nonce,
					appointment_id: apptId,
					seat_id: seatId,
				}, function( resp ) {
					if ( resp.success ) {
						$btn.closest( '.clisyc-seat-transfer-item' ).slideUp( 200, function() { $( this ).remove(); } );
						showStatus( resp.data.message, 'success' );
					} else {
						showStatus( resp.data.message || '<?php echo esc_js( __( 'Error releasing seat.', 'client-sync-pro' ) ); ?>', 'error' );
						$btn.prop( 'disabled', false ).html( '&times;' );
					}
				} ).fail( function() {
					showStatus( '<?php echo esc_js( __( 'Network error.', 'client-sync-pro' ) ); ?>', 'error' );
					$btn.prop( 'disabled', false ).html( '&times;' );
				} );
			} );

			// Add seat.
			$( '#clisyc-seat-add-btn' ).on( 'click', function() {
				var $input = $( '#clisyc-seat-add-input' );
				var seatId = $.trim( $input.val() );
				if ( ! seatId ) return;

				var $btn = $( this );
				$btn.prop( 'disabled', true );

				$.post( ajaxUrl, {
					action: 'clisyc_seat_transfer_add',
					nonce: nonce,
					appointment_id: apptId,
					seat_id: seatId,
				}, function( resp ) {
					if ( resp.success ) {
						var label = resp.data.seat_label || seatId;
						var $item = $( '<li class="clisyc-seat-transfer-item" data-seat-id="' + seatId + '" style="display:flex;align-items:center;justify-content:space-between;padding:6px 8px;margin-bottom:4px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">' +
							'<span class="clisyc-seat-transfer-label" style="font-weight:500;color:#1e293b;">' + $( '<span>' ).text( label ).html() + '</span>' +
							'<button type="button" class="clisyc-seat-release-btn" data-seat-id="' + seatId + '" style="background:#fee2e2;border:none;color:#dc2626;cursor:pointer;border-radius:4px;padding:2px 6px;font-size:11px;font-weight:600;line-height:1.4;" title="<?php echo esc_attr__( 'Release this seat', 'client-sync-pro' ); ?>">&times;</button>' +
							'</li>' );
						$( '#clisyc-seat-transfer-list' ).append( $item );
						$( '#clisyc-seat-transfer-empty' ).hide();
						$input.val( '' );
						showStatus( resp.data.message, 'success' );
					} else {
						showStatus( resp.data.message || '<?php echo esc_js( __( 'Error adding seat.', 'client-sync-pro' ) ); ?>', 'error' );
					}
					$btn.prop( 'disabled', false );
				} ).fail( function() {
					showStatus( '<?php echo esc_js( __( 'Network error.', 'client-sync-pro' ) ); ?>', 'error' );
					$btn.prop( 'disabled', false );
				} );
			} );

			// Enter key in add input.
			$( '#clisyc-seat-add-input' ).on( 'keypress', function( e ) {
				if ( e.which === 13 ) {
					e.preventDefault();
					$( '#clisyc-seat-add-btn' ).trigger( 'click' );
				}
			} );
		} );
		</script>
		<?php
	}

	/**
	 * AJAX: Release a single seat from an appointment.
	 */
	public function ajax_release_seat(): void {
		check_ajax_referer( 'clisyc_seat_transfer', 'nonce' );

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
		}

		$appointment_id = isset( $_POST['appointment_id'] ) ? absint( $_POST['appointment_id'] ) : 0;
		$seat_id        = isset( $_POST['seat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['seat_id'] ) ) : '';

		if ( ! $appointment_id || empty( $seat_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'client-sync-pro' ) ] );
		}

		$post = get_post( $appointment_id );
		if ( ! $post || Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			wp_send_json_error( [ 'message' => __( 'Appointment not found.', 'client-sync-pro' ) ] );
		}

		// Remove from bookings table.
		global $wpdb;
		$bookings_table = $this->schema->get_seat_bookings_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$bookings_table,
			[
				'appointment_id' => $appointment_id,
				'seat_id'        => $seat_id,
			],
			[ '%d', '%s' ]
		);

		// Update appointment meta.
		$current_seats = get_post_meta( $appointment_id, Constants::META_SELECTED_SEATS, true );
		if ( is_array( $current_seats ) ) {
			$current_seats = array_values( array_filter( $current_seats, function ( $s ) use ( $seat_id ) {
				return $s !== $seat_id;
			} ) );
			update_post_meta( $appointment_id, Constants::META_SELECTED_SEATS, $current_seats );
		}

		wp_send_json_success( [
			'message' => __( 'Seat released successfully.', 'client-sync-pro' ),
		] );
	}

	/**
	 * AJAX: Add a seat to an appointment.
	 */
	public function ajax_add_seat(): void {
		check_ajax_referer( 'clisyc_seat_transfer', 'nonce' );

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
		}

		$appointment_id = isset( $_POST['appointment_id'] ) ? absint( $_POST['appointment_id'] ) : 0;
		$seat_id        = isset( $_POST['seat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['seat_id'] ) ) : '';

		if ( ! $appointment_id || empty( $seat_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'client-sync-pro' ) ] );
		}

		$post = get_post( $appointment_id );
		if ( ! $post || Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			wp_send_json_error( [ 'message' => __( 'Appointment not found.', 'client-sync-pro' ) ] );
		}

		// Resolve venue and slot.
		$venue_id = $this->resolve_venue_id( $appointment_id );
		if ( ! $venue_id ) {
			wp_send_json_error( [ 'message' => __( 'No venue linked to this appointment.', 'client-sync-pro' ) ] );
		}

		$time_slot = get_post_meta( $appointment_id, Constants::META_TIME_SLOT, true );
		if ( empty( $time_slot ) ) {
			wp_send_json_error( [ 'message' => __( 'No time slot found for this appointment.', 'client-sync-pro' ) ] );
		}

		// Find the slot ID from the dimensions table.
		$slot_id = $this->resolve_slot_id( $appointment_id );
		if ( ! $slot_id ) {
			wp_send_json_error( [ 'message' => __( 'Could not resolve slot ID.', 'client-sync-pro' ) ] );
		}

		// Check if seat is already booked for this slot.
		global $wpdb;
		$bookings_table = $this->schema->get_seat_bookings_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT booking_id FROM {$bookings_table} WHERE venue_id = %d AND slot_id = %d AND seat_id = %s LIMIT 1",
				$venue_id,
				$slot_id,
				$seat_id
			)
		);

		if ( $existing ) {
			wp_send_json_error( [ 'message' => __( 'This seat is already booked for this time slot.', 'client-sync-pro' ) ] );
		}

		// Validate that the seat exists in the venue layout.
		$layout = get_post_meta( $venue_id, Constants::META_VENUE_LAYOUT, true );
		$seat_label = '';
		$seat_category = '';
		$found = false;

		if ( is_array( $layout ) && ! empty( $layout['sections'] ) ) {
			foreach ( $layout['sections'] as $section ) {
				foreach ( $section['rows'] ?? [] as $row ) {
					foreach ( $row['seats'] ?? [] as $seat ) {
						if ( $seat['id'] === $seat_id ) {
							$found = true;
							$seat_category = ! empty( $seat['category'] ) ? $seat['category'] : ( $section['category'] ?? '' );
							$seat_label = '';
							if ( ! empty( $section['label'] ) ) {
								$seat_label .= $section['label'] . ' · ';
							}
							if ( ! empty( $row['label'] ) ) {
								$seat_label .= $row['label'] . ' · ';
							}
							$seat_label .= $seat['label'] ?? $seat['id'];
							break 3;
						}
					}
				}
			}
		}

		if ( ! $found ) {
			wp_send_json_error( [ 'message' => __( 'Seat ID not found in the venue layout.', 'client-sync-pro' ) ] );
		}

		// Get seat price.
		$pricing_tiers = get_post_meta( $venue_id, Constants::META_SEAT_PRICING_TIERS, true );
		$seat_price = 0;
		if ( is_array( $pricing_tiers ) ) {
			foreach ( $pricing_tiers as $tier ) {
				if ( ( $tier['category'] ?? '' ) === $seat_category ) {
					$seat_price = (int) ( $tier['price'] ?? 0 );
					break;
				}
			}
		}

		// Insert booking.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$bookings_table,
			[
				'venue_id'       => $venue_id,
				'slot_id'        => $slot_id,
				'seat_id'        => $seat_id,
				'appointment_id' => $appointment_id,
				'seat_category'  => $seat_category,
				'seat_price'     => $seat_price,
			],
			[ '%d', '%d', '%s', '%d', '%s', '%d' ]
		);

		if ( false === $inserted ) {
			wp_send_json_error( [ 'message' => __( 'Failed to assign seat.', 'client-sync-pro' ) ] );
		}

		// Update appointment meta.
		$current_seats = get_post_meta( $appointment_id, Constants::META_SELECTED_SEATS, true );
		if ( ! is_array( $current_seats ) ) {
			$current_seats = [];
		}
		if ( ! in_array( $seat_id, $current_seats, true ) ) {
			$current_seats[] = $seat_id;
			update_post_meta( $appointment_id, Constants::META_SELECTED_SEATS, $current_seats );
		}

		wp_send_json_success( [
			'message'    => __( 'Seat assigned successfully.', 'client-sync-pro' ),
			'seat_label' => $seat_label,
		] );
	}

	/**
	 * Resolve venue ID for an appointment.
	 *
	 * @param int $appointment_id
	 * @return int
	 */
	private function resolve_venue_id( int $appointment_id ): int {
		$dimensions = get_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, true );
		if ( ! is_array( $dimensions ) || empty( $dimensions ) ) {
			return 0;
		}

		$primary_dim_slug = get_option( Constants::OPTION_PRIMARY_SERVICE_DIM, '' );
		$service_id       = 0;

		if ( ! empty( $primary_dim_slug ) && isset( $dimensions[ $primary_dim_slug ] ) ) {
			$service_id = (int) $dimensions[ $primary_dim_slug ];
		} else {
			$service_id = (int) reset( $dimensions );
		}

		if ( ! $service_id ) {
			return 0;
		}

		return absint( get_post_meta( $service_id, Constants::META_LINKED_VENUE_ID, true ) );
	}

	/**
	 * Resolve the slot ID for an appointment from the availability table.
	 *
	 * @param int $appointment_id
	 * @return int
	 */
	private function resolve_slot_id( int $appointment_id ): int {
		$time_slot = get_post_meta( $appointment_id, Constants::META_TIME_SLOT, true );
		if ( empty( $time_slot ) ) {
			return 0;
		}

		global $wpdb;
		$slots_table = $this->schema->get_slots_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$slot_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT slot_id FROM {$slots_table} WHERE slot_datetime = %s LIMIT 1",
				$time_slot
			)
		);

		return $slot_id ? (int) $slot_id : 0;
	}
}
