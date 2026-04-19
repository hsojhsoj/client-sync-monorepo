<?php
/**
 * File: src/shared/includes/core/class-post-type-manager.php -> client-sync/includes/core/class-post-type-manager.php
 * Manages the registration and administrative handling of Custom Post Types.
 *
 * UPDATED: Added HIPAA compliance - encryption/decryption for sensitive custom fields.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */
namespace DependentMedia\ClientSync\Core;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Admin\Relationship_Manager;
use DependentMedia\ClientSync\Services\FormRenderer;
use DependentMedia\ClientSync\Services\Encryption_Service;
use DependentMedia\ClientSync\Utility\Service_Helper;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class PostType_Manager {
	public function register_hooks() {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( 'init', [ $this, 'register_custom_statuses' ] );
		add_action( 'init', [ $this, 'register_meta_for_rest_api' ], 20 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_clisyc_appointment', [ $this, 'add_appointment_meta_boxes' ] );
			add_action( 'add_meta_boxes', [ $this, 'add_dimension_meta_boxes' ], 10, 2 );
			add_action( 'add_meta_boxes', [ $this, 'add_dynamic_attribute_meta_boxes' ], 20, 2 );
			add_filter( 'default_content', [ $this, 'set_default_editor_content' ], 10, 2 );
			add_action( 'wp_trash_post', [ $this, 'handle_appointment_deletion' ] );
			add_action( 'before_delete_post', [ $this, 'handle_appointment_deletion' ] );

			// AJAX handler for syncing price from WooCommerce product
			add_action( 'wp_ajax_clisyc_get_product_price', [ $this, 'ajax_get_product_price' ] );

			// Delegate to extracted handler classes.
			( new Appointment_Meta_Saver() )->register_hooks( $this );
			( new Bulk_Action_Handler() )->register_hooks();
			( new Appointment_Column_Handler() )->register_hooks();
			( new Pricing_Rules_Handler() )->register_hooks();
		}
	}
	// Bulk actions extracted to Bulk_Action_Handler.

	/**
	 * AJAX handler for syncing price from a WooCommerce product.
	 */
	public function ajax_get_product_price() {
		check_ajax_referer( 'clisyc_sync_price_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'client-sync' ) ] );
		}

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			wp_send_json_error( [ 'message' => __( 'WooCommerce is not active.', 'client-sync' ) ] );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'client-sync' ) ] );
		}

		$price = $product->get_price();

		wp_send_json_success( [ 'price' => $price ] );
	}

	public function register_meta_for_rest_api() {
        // ... [No changes needed here] ...
		$registry   = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$post_types = [];
		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				if ( ! empty( $settings['enabled'] ) ) {
					$post_types[] = $slug;
				}
			}
		}
		if ( empty( $post_types ) ) {
			$custom_types = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
			if ( is_array( $custom_types ) ) {
				$post_types = array_keys( $custom_types );
			}
		}
		$sanitize_callback = function( $value ) {
			if ( empty( $value ) ) {
				return '';
			}
			$decoded = json_decode( $value, true );
			return ( json_last_error() === JSON_ERROR_NONE ) ? $value : '';
		};
		foreach ( $post_types as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}
			register_post_meta(
				$post_type,
				Constants::META_SCHEDULE,
				[
					'type'              => 'string',
					'description'       => 'Weekly schedule data in JSON format',
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
					'sanitize_callback' => $sanitize_callback,
				]
			);
			register_post_meta(
				$post_type,
				Constants::META_SCHEDULE_OVERRIDES,
				[
					'type'              => 'string',
					'description'       => 'Date-specific schedule overrides in JSON format',
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
					'sanitize_callback' => $sanitize_callback,
				]
			);
		}
	}
	public function handle_appointment_deletion( int $post_id ) {
		if ( Constants::POST_TYPE_APPOINTMENT !== get_post_type( $post_id ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table cleanup required.
		$wpdb->delete( $wpdb->prefix . 'clisyc_bookings', [ 'appointment_id' => $post_id ], [ '%d' ] );
	}
	public function add_dimension_meta_boxes( string $post_type, \WP_Post $post ) {
        // ... [No changes needed in the setup logic] ...
		$registry             = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$enabled_dimensions   = $registry['dimensions'] ?? [];
		$primary_dimension_slug = null;
		foreach ( $enabled_dimensions as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_dimension_slug = $slug;
				break;
			}
		}
		if ( isset( $enabled_dimensions[ $post_type ] ) && $enabled_dimensions[ $post_type ]['enabled'] ) {
			Relationship_Manager::render_relationship_meta_boxes( $post, $registry );
			add_meta_box( 'clisyc-dimension-attributes-metabox', __( 'Attributes', 'client-sync' ), [ $this, 'render_color_attribute_meta_box' ], $post_type, 'side', 'default' );
			
			$sync_enabled = ! empty( $enabled_dimensions[ $post_type ]['enable_sync'] );
			
			$is_primary_or_resource = ( 
				$post_type === $primary_dimension_slug || 
				! empty( $enabled_dimensions[ $post_type ]['is_resource'] )
			);
			if ( $post_type === $primary_dimension_slug ) {
				add_meta_box( 'clisyc-primary-attributes-metabox', __( 'Primary Attributes', 'client-sync' ), [ $this, 'render_primary_dimension_attributes_meta_box' ], $post_type, 'normal', 'high' );
				add_meta_box( 'clisyc-linking-metabox', __( 'Direct Link & Shortcode', 'client-sync' ), [ $this, 'render_service_linking_meta_box' ], $post_type, 'side', 'low' );
			}
			// NOTE: Moved out of the $primary_dimension_slug block to support linking WC products to any dimension
			if ( class_exists( 'WooCommerce' ) && get_option( Constants::OPTION_WC_ENABLED ) ) {
				add_meta_box( 'clisyc-woocommerce-metabox', __( 'WooCommerce Integration', 'client-sync' ), [ $this, 'render_woocommerce_meta_box' ], $post_type, 'normal', 'high' );
			}
			if ( $is_primary_or_resource ) {
				$schedule_box_title = ( $post_type === $primary_dimension_slug ) ? __( 'Base Weekly Schedule', 'client-sync' ) : __( 'Resource Availability Schedule', 'client-sync' );
				add_meta_box( 'clisyc-service-schedule-metabox', $schedule_box_title, [ $this, 'render_service_schedule_meta_box' ], $post_type, 'normal', 'high' );
				add_meta_box( 'clisyc-schedule-pattern-metabox', __( 'Schedule Pattern (Rotation)', 'client-sync' ), [ $this, 'render_schedule_pattern_meta_box' ], $post_type, 'side', 'default' );
				add_meta_box( 'clisyc-schedule-overrides-metabox', __( 'Schedule Overrides', 'client-sync' ), [ $this, 'render_schedule_overrides_meta_box' ], $post_type, 'normal', 'default' );
			}
			
			if ( $sync_enabled ) {
				add_meta_box(
					'clisyc-employee-user-link-metabox',
					__( 'Link WordPress User', 'client-sync' ),
					[ $this, 'render_employee_user_link_meta_box' ],
					$post_type,
					'side',
					'high'
				);
				if ( function_exists( 'clisyc_pro_is_license_active' ) && clisyc_pro_is_license_active() ) {
					add_meta_box(
						'clisyc-google-sync-metabox',
						__( 'Calendar Sync', 'client-sync' ),
						[ $this, 'render_google_sync_meta_box' ],
						$post_type,
						'side',
						'default'
					);
				}
			}
			
			global $wpdb;
			$rels_table = $wpdb->prefix . 'clisyc_relationships';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $rels_table is safely constructed from $wpdb->prefix.
			$sql = "SELECT COUNT(*) FROM {$rels_table} WHERE parent_object_id = %d OR child_object_id = %d";
			
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query for relationship count. $sql contains valid SQL.
			$connection_count = $wpdb->get_var( $wpdb->prepare( $sql, $post->ID, $post->ID ) );
			if ( (int) $connection_count === 0 ) {
				add_meta_box(
					'clisyc-no-relationships-notice',
					__( 'Configuration Notice', 'client-sync' ),
					function() {
						$graph_url = esc_url( admin_url( 'admin.php?page=clisyc-dimensions&tab=graph' ) );
						/* translators: %s: URL to the relationship graph. */
						$notice_text = __( 'This item is not connected to any other dimensions. It may not be bookable until you link it to other items (like a Practitioner or Room) in the <a href="%s">Relationship Graph</a>.', 'client-sync' );
						echo '<p>' . wp_kses( sprintf( $notice_text, $graph_url ), [ 'a' => [ 'href' => [] ] ] ) . '</p>';
					},
					$post_type,
					'side',
					'high'
				);
			}
		}
	}
    // ... [add_dynamic_attribute_meta_boxes, render_dynamic_attribute_meta_box, render_schedule_overrides_meta_box remain unchanged] ...
	public function add_dynamic_attribute_meta_boxes( string $post_type, \WP_Post $post ) {
		$dimension_fields = get_option( Constants::OPTION_DIMENSION_FIELDS, [] );
		if ( empty( $dimension_fields ) ) {
			return;
		}
		foreach ( $dimension_fields as $key => $field ) {
			if ( in_array( $post_type, $field['applies_to'], true ) ) {
				add_meta_box(
					'clisyc_attr_' . $key,
					esc_html( $field['label'] ),
					[ $this, 'render_dynamic_attribute_meta_box' ],
					$post_type,
					'side',
					'default',
					[ 'field_key' => $key, 'field_def' => $field ]
				);
			}
		}
	}
	public function render_dynamic_attribute_meta_box( \WP_Post $post, array $metabox ) {
		$field_key    = $metabox['args']['field_key'];
		$field_def    = $metabox['args']['field_def'];
		$prefixed_key = '_clisyc_' . $field_key;
		$value        = get_post_meta( $post->ID, $prefixed_key, true );
		wp_nonce_field( 'clisyc_save_dynamic_attributes_nonce', 'clisyc_dynamic_attributes_nonce' );
		switch ( $field_def['type'] ) {
			case 'number':
				echo '<input type="number" name="' . esc_attr( $field_key ) . '" value="' . esc_attr( $value ) . '" class="widefat">';
				break;
			case 'select':
				$options = array_map( 'trim', explode( ',', $field_def['options'] ) );
				echo '<select name="' . esc_attr( $field_key ) . '" class="widefat">';
				echo '<option value="">—</option>';
				foreach ( $options as $option ) {
					echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $option ) . '</option>';
				}
				echo '</select>';
				break;
			case 'text':
			default:
				echo '<input type="text" name="' . esc_attr( $field_key ) . '" value="' . esc_attr( $value ) . '" class="widefat">';
				break;
		}
	}
	public function render_schedule_overrides_meta_box( \WP_Post $post ) {
		$overrides_json = get_post_meta( $post->ID, Constants::META_SCHEDULE_OVERRIDES, true );
		?>
		<div class="clisyc-admin-instructions-metabox">
			<p><?php esc_html_e( 'Use this calendar to override the base weekly schedule for specific dates.', 'client-sync' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Click any date to set an override.', 'client-sync' ); ?></li>
				<li><?php esc_html_e( 'You can mark the day as "Closed" or define custom hours just for that day.', 'client-sync' ); ?></li>
				<li><?php esc_html_e( 'Dates with an active override are highlighted in yellow.', 'client-sync' ); ?></li>
			</ol>
			<p><em><?php esc_html_e( 'Note: Changes made here are saved when you click the main "Update" button for this post.', 'client-sync' ); ?></em></p>
		</div>
		<div id="clisyc-override-datepicker"></div>
		<textarea name="_clisyc_schedule_overrides" id="clisyc_schedule_overrides_json" style="display:none;"><?php echo esc_textarea( $overrides_json ); ?></textarea>
		<div id="clisyc-override-dialog" title="Set Date Override" style="display:none;">
			<form id="clisyc-override-dialog-form">
				<fieldset>
					<p><strong><?php esc_html_e( 'Override for:', 'client-sync' ); ?> <span id="clisyc-override-dialog-date"></span></strong></p>
					<div class="clisyc-override-option">
						<input type="radio" name="override_type" id="override_type_default" value="default" checked="checked">
						<label for="override_type_default"><?php esc_html_e( 'Default (Use Base Weekly Schedule)', 'client-sync' ); ?></label>
					</div>
					<div class="clisyc-override-option">
						<input type="radio" name="override_type" id="override_type_closed" value="closed">
						<label for="override_type_closed"><?php esc_html_e( 'Closed / Day Off', 'client-sync' ); ?></label>
					</div>
					<div class="clisyc-override-option">
						<input type="radio" name="override_type" id="override_type_custom" value="custom">
						<label for="override_type_custom"><?php esc_html_e( 'Custom Hours', 'client-sync' ); ?></label>
					</div>
					<div id="clisyc-override-custom-hours-editor" style="display:none; margin-top: 1em;">
						<div id="clisyc-override-calendar-instance">
							<p style="text-align:center; padding: 30px;"><?php esc_html_e( 'Loading Editor...', 'client-sync' ); ?></p>
						</div>
					</div>
				</fieldset>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the Attributes meta box (sidebar) - Color only.
	 * UPDATED: Removed Display Price field - now consolidated in WooCommerce Integration meta box.
	 */
	public function render_color_attribute_meta_box( \WP_Post $post ) {
		wp_nonce_field( 'clisyc_save_color_meta_nonce_' . $post->ID, 'clisyc_color_meta_nonce' );
		$color = get_post_meta( $post->ID, Constants::META_COLOR, true );
		?>
		<p>
			<label for="clisyc_color"><?php esc_html_e( 'Calendar Color', 'client-sync' ); ?></label><br>
			<input type="text" id="clisyc_color" name="clisyc_color" value="<?php echo esc_attr( $color ); ?>" class="clisyc-color-picker">
		</p>
		<p class="description"><?php esc_html_e( 'Choose a color to represent this item on calendars and in dropdowns.', 'client-sync' ); ?></p>
		<?php
		// REMOVED: Display Price field - now in WooCommerce Integration meta box for all dimensions
	}

	/**
	 * Renders the Primary Attributes meta box (main content area for primary dimension).
	 * UPDATED: Removed Display Price field - now consolidated in WooCommerce Integration meta box.
	 */
	public function render_primary_dimension_attributes_meta_box( \WP_Post $post ) {
		wp_nonce_field( 'clisyc_save_service_meta_nonce_' . $post->ID, 'clisyc_service_meta_nonce' );
		$buffer_before      = get_post_meta( $post->ID, Constants::META_BUFFER_BEFORE, true );
		$buffer_after       = get_post_meta( $post->ID, Constants::META_BUFFER_AFTER, true );
		$capacity           = get_post_meta( $post->ID, Constants::META_CAPACITY, true );
		$booking_mode       = get_post_meta( $post->ID, Constants::META_BOOKING_MODE, true ) ?: 'slot';
		// Render the raw stored value — don't inject a 60 fallback into the input's
		// `value` attribute, which previously made the form look configured even when
		// nothing was saved. The placeholder below surfaces the 60-min default as a
		// visual hint without lying about what's persisted. Slot generation still
		// treats an unset value as 60 (see Service_Helper::get_duration_minutes).
		$duration_minutes     = get_post_meta( $post->ID, Constants::META_DURATION_MINUTES, true );
		$padding_minutes      = get_post_meta( $post->ID, Constants::META_PADDING_MINUTES, true );
		// REMOVED: Price retrieval - now in WooCommerce Integration meta box
		?>
		<div class="clisyc-admin-instructions-metabox">
			<p><?php echo wp_kses_post( __( 'Set the duration and capacity for this service. In the <strong>Base Weekly Schedule</strong> box below, you can either draw large blocks of availability (e.g., 9am-5pm) which will be auto-filled with slots of the duration you set, or draw the exact individual slots you want to offer.', 'client-sync' ) ); ?></p>
		</div>
		<table class="form-table">
			<!-- REMOVED: Display Price Field - now in WooCommerce Integration meta box -->
			<tr>
				<th><label for="clisyc_capacity"><?php esc_html_e( 'Capacity', 'client-sync' ); ?></label></th>
				<td>
					<input type="number" id="clisyc_capacity" name="clisyc_capacity" value="<?php echo esc_attr( $capacity ); ?>" class="small-text" placeholder="1" min="1" step="1">
					<p class="description"><?php esc_html_e( 'Number of simultaneous bookings for a single slot (e.g., for a class). Leave as 1 for individual appointments.', 'client-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Booking Mode', 'client-sync' ); ?></label></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><span><?php esc_html_e( 'Booking Mode', 'client-sync' ); ?></span></legend>
						<label style="display: block; margin-bottom: 5px;"><input type="radio" name="clisyc_booking_mode" value="slot" <?php checked( $booking_mode, 'slot' ); ?>> <?php esc_html_e( 'Time Slot (Fixed duration appointments)', 'client-sync' ); ?></label>
						<label style="display: block;"><input type="radio" name="clisyc_booking_mode" value="date_range" <?php checked( $booking_mode, 'date_range' ); ?>> <?php esc_html_e( 'Date Range (Per day/night bookings)', 'client-sync' ); ?></label>
						<p class="description"><?php esc_html_e( 'Choose "Date Range" for multi-day bookings like rentals or hotel stays.', 'client-sync' ); ?></p>
					</fieldset>
				</td>
			</tr>
			<tr class="clisyc-time-slot-setting">
				<th><label for="clisyc_duration_minutes"><?php esc_html_e( 'Appointment Duration', 'client-sync' ); ?></label></th>
				<td>
					<input type="number" id="clisyc_duration_minutes" name="clisyc_duration_minutes" value="<?php echo esc_attr( $duration_minutes ); ?>" class="small-text" placeholder="60" min="1" step="1">
					<p class="description"><?php esc_html_e( 'The duration of a single appointment in minutes. This is used to automatically generate slots from your weekly schedule.', 'client-sync' ); ?></p>
				</td>
			</tr>
			<tr class="clisyc-time-slot-setting">
				<th><label for="clisyc_padding_minutes"><?php esc_html_e( 'Padding / Cleanup Time', 'client-sync' ); ?></label></th>
				<td>
					<input type="number" id="clisyc_padding_minutes" name="clisyc_padding_minutes" value="<?php echo esc_attr( $padding_minutes ); ?>" class="small-text" placeholder="0" min="0" step="1">
					<p class="description"><?php esc_html_e( 'Time in minutes to block off *after* an appointment for preparation. This is not visible to clients.', 'client-sync' ); ?></p>
				</td>
			</tr>
			<tr class="clisyc-time-slot-setting">
				<th><label><?php esc_html_e( 'Buffers (minutes)', 'client-sync' ); ?></label></th>
				<td>
					<label for="clisyc_buffer_before" style="margin-right:15px;"><?php esc_html_e( 'Before:', 'client-sync' ); ?> <input type="number" id="clisyc_buffer_before" name="clisyc_buffer_before" value="<?php echo esc_attr( $buffer_before ); ?>" class="small-text" placeholder="0" min="0"></label>
					<label for="clisyc_buffer_after"><?php esc_html_e( 'After:', 'client-sync' ); ?> <input type="number" id="clisyc_buffer_after" name="clisyc_buffer_after" value="<?php echo esc_attr( $buffer_after ); ?>" class="small-text" placeholder="0" min="0"></label>
				</td>
			</tr>
			<tr class="clisyc-date-range-setting">
				<th><label><?php esc_html_e( 'Multi-Day Booking Rules', 'client-sync' ); ?></label></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><span><?php esc_html_e( 'Multi-Day Booking Rules', 'client-sync' ); ?></span></legend>
						<p style="margin-top:0;"><label for="clisyc_min_stay" style="display:inline-block; width: 150px;"><?php esc_html_e( 'Minimum Stay (nights)', 'client-sync' ); ?></label> <input type="number" id="clisyc_min_stay" name="clisyc_min_stay" value="<?php echo esc_attr( get_post_meta( $post->ID, Constants::META_MIN_STAY, true ) ); ?>" class="small-text" placeholder="1" min="0" step="1"></p>
						<p><label for="clisyc_max_stay" style="display:inline-block; width: 150px;"><?php esc_html_e( 'Maximum Stay (nights)', 'client-sync' ); ?></label> <input type="number" id="clisyc_max_stay" name="clisyc_max_stay" value="<?php echo esc_attr( get_post_meta( $post->ID, Constants::META_MAX_STAY, true ) ); ?>" class="small-text" placeholder="30" min="0" step="1"></p>
						<p><label for="clisyc_buffer_days" style="display:inline-block; width: 150px;"><?php esc_html_e( 'Turnover/Buffer Days', 'client-sync' ); ?></label> <input type="number" id="clisyc_buffer_days" name="clisyc_buffer_days" value="<?php echo esc_attr( get_post_meta( $post->ID, Constants::META_BUFFER_DAYS, true ) ); ?>" class="small-text" placeholder="0" min="0" step="1"> <span class="description"><?php esc_html_e( 'Days to block off for cleaning/prep after a booking ends.', 'client-sync' ); ?></span></p>
					</fieldset>
				</td>
			</tr>
			<tr class="clisyc-date-range-setting">
				<th><label><?php esc_html_e( 'Booking Day Rules', 'client-sync' ); ?></label></th>
				<td>
					<?php
					$days_of_week = [ 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat' ];
					$saved_checkin_days     = get_post_meta( $post->ID, Constants::META_ALLOWED_CHECKIN, true );
					$saved_checkout_days    = get_post_meta( $post->ID, Constants::META_ALLOWED_CHECKOUT, true );
					
					$allowed_checkin_days  = ( '' === $saved_checkin_days ) ? array_keys( $days_of_week ) : (array) $saved_checkin_days;
					$allowed_checkout_days = ( '' === $saved_checkout_days ) ? array_keys( $days_of_week ) : (array) $saved_checkout_days;
					?>
					<fieldset style="margin-bottom: 1em;">
						<legend><strong><?php esc_html_e( 'Allowed Start Days', 'client-sync' ); ?></strong></legend>
						<?php foreach ( $days_of_week as $day_index => $day_abbr ) : ?>
							<label style="display:inline-block; margin-right: 15px;"><input type="checkbox" name="_clisyc_allowed_checkin_days[]" value="<?php echo esc_attr( $day_index ); ?>" <?php checked( in_array( $day_index, $allowed_checkin_days, true ) ); ?>> <?php echo esc_html( $day_abbr ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<fieldset>
						<legend><strong><?php esc_html_e( 'Allowed End Days', 'client-sync' ); ?></strong></legend>
						<?php foreach ( $days_of_week as $day_index => $day_abbr ) : ?>
							<label style="display:inline-block; margin-right: 15px;"><input type="checkbox" name="_clisyc_allowed_checkout_days[]" value="<?php echo esc_attr( $day_index ); ?>" <?php checked( in_array( $day_index, $allowed_checkout_days, true ) ); ?>> <?php echo esc_html( $day_abbr ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Uncheck a day to prevent users from beginning or ending bookings on that day.', 'client-sync' ); ?></p>
				</td>
			</tr>
			<tr class="clisyc-time-slot-setting">
				<th><label for="clisyc_video_conferencing"><?php esc_html_e( 'Video Conferencing', 'client-sync' ); ?></label></th>
				<td>
					<?php
					$vc_enabled = get_post_meta( $post->ID, Constants::META_VIDEO_CONF_ENABLED, true );
					$vc_provider = get_option( Constants::OPTION_VIDEO_PROVIDER, 'none' );
					if ( 'none' === $vc_provider ) :
					?>
						<p class="description"><?php esc_html_e( 'Enable a video provider in Settings → Integrations first.', 'client-sync' ); ?></p>
					<?php else : ?>
						<label>
							<input type="checkbox" id="clisyc_video_conferencing" name="_clisyc_video_conferencing" value="1" <?php checked( $vc_enabled, '1' ); ?> />
							<?php esc_html_e( 'Auto-attach video meeting link to appointments for this service', 'client-sync' ); ?>
						</label>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}
	public function render_schedule_pattern_meta_box( \WP_Post $post ) {
		require clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-schedule-pattern.php';
	}
	/**
	 * @deprecated 3.5.0 Moved to Appointment_Meta_Saver::save_dimension_meta_data().
	 */
	public function save_dimension_meta_data( int $post_id, \WP_Post $post ) {
		_deprecated_function( __METHOD__, '3.5.0', 'Appointment_Meta_Saver::save_dimension_meta_data()' );
		( new Appointment_Meta_Saver() )->save_dimension_meta_data( $post_id, $post );
	}


	public function add_appointment_meta_boxes() {
		add_meta_box( 'clisyc-assign-client-metabox', __( 'Assign Client', 'client-sync' ), [ $this, 'render_assign_client_meta_box' ], Constants::POST_TYPE_APPOINTMENT, 'side', 'high' );
		add_meta_box( 'clisyc-time-slot-metabox', __( 'Appointment Time Slot', 'client-sync' ), [ $this, 'render_time_slot_meta_box' ], Constants::POST_TYPE_APPOINTMENT, 'side', 'high' );
        
        add_meta_box( 'clisyc-appointment-context-metabox', __( 'Appointment Context', 'client-sync' ), [ $this, 'render_appointment_context_meta_box' ], Constants::POST_TYPE_APPOINTMENT, 'normal', 'high' );
		// NEW: Add context calendar
		add_meta_box( 
			'clisyc-appointment-reference-calendar', 
			__( 'Reference Calendar', 'client-sync' ), 
			[ $this, 'render_reference_calendar' ], 
			Constants::POST_TYPE_APPOINTMENT,
			'normal',
			'default'
		);
		add_meta_box( 'clisyc-appointment-details-metabox', __( 'Appointment Custom Details', 'client-sync' ), [ $this, 'render_appointment_details_meta_box' ], Constants::POST_TYPE_APPOINTMENT, 'normal', 'high' );
	}
	public function render_reference_calendar( \WP_Post $post ) {
		// 1. Find which item is booked in this appointment
		$dimensions = get_post_meta( $post->ID, Constants::META_SLOT_DIMENSIONS, true );

		// Find primary dimension from registry to filter the calendar
		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_slug = null;
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_slug = $slug;
				break;
			}
		}
		
		$linked_item_id = $dimensions[$primary_slug] ?? 0;
		
		// Reuse the container structure so the same JS can pick it up, 
		// BUT overwrite the JS data variable for this page context.
		?>
		<div id="clisyc-item-availability-calendar">
			<div id="clisyc-admin-item-calendar" style="min-height: 500px;"></div>
		</div>
		<script>
			// Manually inject config for this specific appointment context
			window.clisycItemCalendarData = <?php echo wp_json_encode( [
				'postId'      => (int) $linked_item_id,
				'postType'    => $primary_slug,
				'restUrl'     => esc_url_raw( rest_url( 'clisyc/v1/slots' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'editUrlBase' => admin_url( 'post.php?action=edit&post=' ),
			] ); ?>;
		</script>
		<?php
	}
    public function render_appointment_context_meta_box( \WP_Post $post ) {
        $dimensions   = get_post_meta( $post->ID, Constants::META_SLOT_DIMENSIONS, true );
        $custom_types = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
        // For auto-draft posts (newly created from Slots List), read dimensions from URL params
        if ( ( empty( $dimensions ) || ! is_array( $dimensions ) ) && 'auto-draft' === $post->post_status ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL params to pre-fill form fields, not processing submission.
            $unslashed_get = wp_unslash( $_GET );
            $dimensions    = [];
            
            foreach ( $unslashed_get as $key => $value ) {
                if ( strpos( $key, 'clisyc_dim_' ) === 0 ) {
                    $dim_key = str_replace( 'clisyc_dim_', '', $key );
                    $dim_key = sanitize_key( $dim_key );
                    $dimensions[ $dim_key ] = absint( $value );
                }
            }
        }
        if ( empty( $dimensions ) || ! is_array( $dimensions ) ) {
            echo '<p><em>' . esc_html__( 'No dimension details found for this appointment.', 'client-sync' ) . '</em></p>';
            return;
        }
        echo '<table class="form-table"><tbody>';
        foreach ( $dimensions as $slug => $dim_post_id ) {
            $cpt_label  = $custom_types[ $slug ]['singular'] ?? ucfirst( str_replace( [ 'clisyc_', '_' ], [ '', ' ' ], $slug ) );
            $item_title = get_the_title( $dim_post_id );
            $edit_link  = get_edit_post_link( $dim_post_id, 'raw' );
            ?>
            <tr>
                <th scope="row"><?php echo esc_html( $cpt_label ); ?></th>
                <td>
                    <?php if ( $item_title && $edit_link ) : ?>
                        <a href="<?php echo esc_url( $edit_link ); ?>" target="_blank">
                            <?php echo esc_html( $item_title ); ?>
                            <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: text-top;"></span>
                        </a>
                    <?php elseif ( $item_title ) : ?>
                        <?php echo esc_html( $item_title ); ?>
                    <?php else : ?>
                        <em><?php esc_html_e( 'Item not found', 'client-sync' ); ?> (ID: <?php echo esc_html( $dim_post_id ); ?>)</em>
                    <?php endif; ?>
                    <!-- Hidden field to preserve dimension data for saving -->
                    <input type="hidden" name="clisyc_slot_dimensions[<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( $dim_post_id ); ?>">
                </td>
            </tr>
            <?php
        }
        echo '</tbody></table>';
    }
	/**
	 * Renders the Assign Client metabox with searchable autocomplete.
	 *
	 * @param \WP_Post $post The current post object.
	 */
	public function render_assign_client_meta_box( \WP_Post $post ) {
		// Get current author (client) ID
		$current_client_id = $post->post_author;
		$client_roles      = apply_filters( 'clisyc_client_user_roles_admin', [ 'subscriber', 'customer', 'administrator', 'editor', 'author' ] );
		
		?>
		<div class="clisyc-client-search-container">
			<!-- Selected client display (populated via JS if client is pre-selected) -->
			<div class="clisyc-client-selected" id="clisyc-client-selected"></div>
			<!-- Search input wrapper -->
			<div class="clisyc-client-search-input-wrapper">
				<span class="dashicons dashicons-search"></span>
				<input 
					type="text" 
					id="clisyc-client-search-input"
					class="clisyc-client-search-input"
					placeholder="<?php esc_attr_e( 'Type to search clients by name or email...', 'client-sync' ); ?>"
					autocomplete="off"
				/>
			</div>
			<!-- Hidden field to store the selected user ID -->
			<input 
				type="hidden" 
				id="clisyc-client-id"
				name="clisyc_client_id" 
				value="<?php echo esc_attr( $current_client_id ); ?>"
			/>
			<!-- Search results dropdown -->
			<div id="clisyc-client-search-results" class="clisyc-client-search-results"></div>
			<p class="clisyc-client-search-help">
				<?php
				/* translators: %s: A comma-separated list of user roles (e.g., "subscriber, customer"). */
				$description_text = __( 'Assign this appointment to a user with the role(s): %s.', 'client-sync' );
				echo esc_html( sprintf( $description_text, implode( ', ', $client_roles ) ) );
				?>
			</p>
		</div>
		<?php
	}
	public function render_time_slot_meta_box( \WP_Post $post ) {
		$booking_mode = get_post_meta( $post->ID, Constants::META_BOOKING_MODE, true ) ?: 'slot';
		if ( 'date_range' === $booking_mode ) {
			$start_date = get_post_meta( $post->ID, Constants::META_START_DATE, true );
			$end_date   = get_post_meta( $post->ID, Constants::META_END_DATE, true );
			?>
			<p><strong><?php esc_html_e( 'Booking Type:', 'client-sync' ); ?></strong> <?php esc_html_e( 'Multi-Day Rental', 'client-sync' ); ?></p>
			<p>
				<label><strong><?php esc_html_e( 'Check-in:', 'client-sync' ); ?></strong></label><br>
				<input type="date" name="clisyc_start_date" value="<?php echo esc_attr( $start_date ); ?>" class="widefat">
			</p>
			<p>
				<label><strong><?php esc_html_e( 'Check-out:', 'client-sync' ); ?></strong></label><br>
				<input type="date" name="clisyc_end_date" value="<?php echo esc_attr( $end_date ); ?>" class="widefat">
			</p>
			<p class="description"><?php esc_html_e( 'Note: Changing these dates manually requires ensuring the new dates are available.', 'client-sync' ); ?></p>
			<?php
		} else {
			$slot_identifier = get_post_meta( $post->ID, Constants::META_TIME_SLOT, true );
			$date_value      = '';
			$duration        = get_post_meta( $post->ID, Constants::META_APPOINTMENT_DURATION, true );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL params to pre-fill form fields, not processing submission.
			$unslashed_get   = wp_unslash( $_GET );
			if ( 'auto-draft' === $post->post_status && isset( $unslashed_get['clisyc_slot'] ) ) {
				// Use the value from the URL if this is a new post
				$slot_identifier = sanitize_text_field( $unslashed_get['clisyc_slot'] );
				$duration        = isset( $unslashed_get['clisyc_duration'] ) ? absint( $unslashed_get['clisyc_duration'] ) : $duration;
				
				// =================== START: CRITICAL FIX ===================
				// FIX: Convert the FULL UTC datetime to local time, then extract the date.
				// This handles cases where UTC date differs from local date 
				// (e.g., 2am UTC = 7pm previous day in Pacific)
				try {
					$utc_dt     = new \DateTime( $slot_identifier, new \DateTimeZone( 'UTC' ) );
					$date_value = $utc_dt->setTimezone( wp_timezone() )->format( 'Y-m-d' );
				} catch ( \Exception $e ) {
					// Fallback: try to extract just the date if full parsing fails
					if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $slot_identifier, $matches ) ) {
						$date_value = $matches[1];
					} else {
						$date_value = '';
					}
				}
				// =================== END: CRITICAL FIX ===================
				
			} else {
				$date_value = get_post_meta( $post->ID, Constants::META_APPOINTMENT_DATE, true );
			}
			?>
			<div id="clisyc-time-slot-selector">
				<p>
					<label for="clisyc_slot_selection_date"><strong><?php esc_html_e( 'Select Date:', 'client-sync' ); ?></strong></label><br>
					<input type="text" id="clisyc_slot_selection_date" name="clisyc_slot_selection_date" value="<?php echo esc_attr( $date_value ); ?>" style="width: 100%;" autocomplete="off">
				</p>
				<div id="clisyc-available-slots-container" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 5px; background: #f9f9f9; margin-bottom: 10px;">
					<p><em><?php esc_html_e( 'Please select a date above to see available slots.', 'client-sync' ); ?></em></p>
				</div>
				<p>
					<strong><?php esc_html_e( 'Selected:', 'client-sync' ); ?></strong>
					<span id="clisyc-selected-slot-time"><?php esc_html_e( 'None', 'client-sync' ); ?></span>
					<span class="spinner" style="float: none; vertical-align: middle;"></span>
				</p>
				<input type="hidden" id="clisyc_selected_slot_date_hidden" name="clisyc_selected_slot_date_hidden" value="<?php echo esc_attr( $date_value ); ?>">
				<input type="hidden" id="clisyc_selected_slot_identifier_hidden" name="clisyc_selected_slot_identifier_hidden" value="<?php echo esc_attr( $slot_identifier ); ?>">
				<input type="hidden" id="clisyc_selected_slot_duration_hidden" name="clisyc_selected_slot_duration_hidden" value="<?php echo esc_attr( $duration ); ?>">
				<?php
				// Output hidden fields for dimension data (for auto-title and context saving)
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL params to pre-fill form fields, not processing submission.
				$unslashed_get_dims = wp_unslash( $_GET );
				if ( 'auto-draft' === $post->post_status ) {
					foreach ( $unslashed_get_dims as $key => $value ) {
						if ( strpos( $key, 'clisyc_dim_' ) === 0 ) {
							$dim_key = sanitize_key( str_replace( 'clisyc_dim_', '', $key ) );
							$sanitized_value = absint( $value );
							echo '<input type="hidden" name="clisyc_slot_dimensions[' . esc_attr( $dim_key ) . ']" value="' . esc_attr( $sanitized_value ) . '">';
						}
					}
				} else {
					// For existing appointments, persist the saved dimensions
					$saved_dimensions = get_post_meta( $post->ID, Constants::META_SLOT_DIMENSIONS, true );
					if ( is_array( $saved_dimensions ) ) {
						foreach ( $saved_dimensions as $dim_key => $dim_value ) {
							echo '<input type="hidden" name="clisyc_slot_dimensions[' . esc_attr( sanitize_key( $dim_key ) ) . ']" value="' . esc_attr( absint( $dim_value ) ) . '">';
						}
					}
				}
				?>
			</div>
			<?php
		}
	}
	public function render_service_schedule_meta_box( \WP_Post $post ) {
		$schedule_meta = get_post_meta( $post->ID, Constants::META_SCHEDULE, true );
		$schedule_data = json_decode( $schedule_meta, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $schedule_data ) ) {
			$schedule_data = [];
		}
		if ( empty( $schedule_data ) ) {
			$schedule_data = [];
		}
		$start_time_setting = get_option( 'clisyc_standard_schedule_calendar_start_time', '09:00' );
		$end_time_setting   = get_option( 'clisyc_standard_schedule_calendar_end_time', '17:00' );
		$slot_max_time_fc   = ( '23:59' === $end_time_setting || '24:00' === $end_time_setting ) ? '24:00:00' : $end_time_setting . ':00';
		wp_localize_script(
			'admin-standard-schedule-fc',
			'csStandardScheduleData',
			[
				'post_id'         => $post->ID,
				'scheduleData'    => $schedule_data,
				'calendarOptions' => [
					'initialView'   => 'timeGridDay',
					'initialDate'   => '1970-01-05',
					'headerToolbar' => false,
					'dayHeaders'    => false,
					'allDaySlot'    => false,
					'height'        => 'auto',
					'slotMinTime'   => $start_time_setting . ':00',
					'slotMaxTime'   => $slot_max_time_fc,
				],
				'l10n'            => [
					'confirmDelete' => __( 'Are you sure you want to delete this time slot?', 'client-sync' ),
					'saving'        => __( 'Saving...', 'client-sync' ),
				],
			]
		);
		echo '<div class="clisyc-service-schedule-metabox-wrapper" data-service-id="' . esc_attr( $post->ID ) . '">';
		echo '<textarea name="_clisyc_schedule_json" id="clisyc_schedule_json_output" style="display:none;"></textarea>';
		include clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-schedule-editor.php';
		echo '</div>';
	}
	public function render_appointment_details_meta_box( \WP_Post $post ) {
		wp_nonce_field( 'clisyc_save_appointment_meta_nonce', 'clisyc_appointment_meta_nonce' );
		$custom_fields = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		$field_order   = get_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, array_keys( $custom_fields ) );
		if ( empty( $field_order ) ) {
			echo '<p>' . esc_html__( 'No appointment custom fields defined.', 'client-sync' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=clisyc-custom-fields&cf_tab=appointments-cf' ) ) . '">' . esc_html__( 'Define them now', 'client-sync' ) . '</a>.</p>';
			return;
		}
		$prefilled_values = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL params to pre-fill form fields, not processing submission.
		$unslashed_get    = wp_unslash( $_GET );
		if ( 'auto-draft' === $post->post_status ) {
			foreach ( $unslashed_get as $key => $value ) {
				if ( strpos( $key, 'clisyc_dim_' ) === 0 ) {
					$dim_key                            = str_replace( 'clisyc_dim_', '', $key );
					$prefilled_values[ sanitize_key( $dim_key ) ] = sanitize_text_field( $value );
				}
			}
		}
		$form_renderer = new FormRenderer();
		
		// =========================================================================
		// HIPAA COMPLIANCE: Get encryption service for decrypting sensitive fields
		// =========================================================================
		$encryption = null;
		if ( class_exists( '\DependentMedia\ClientSync\Services\Encryption_Service' ) ) {
			$encryption = Encryption_Service::get_instance();
		}
		
		echo '<table class="form-table clisyc-appointment-meta-table">';
		foreach ( $field_order as $field_key ) {
			if ( ! isset( $custom_fields[ $field_key ] ) ) {
				continue;
			}
			$field_definition = $custom_fields[ $field_key ];
			$current_value    = get_post_meta( $post->ID, $field_key, true );
			
			// =========================================================================
			// HIPAA COMPLIANCE: Decrypt encrypted field values for display
			// =========================================================================
			if ( $encryption && ! empty( $current_value ) && is_string( $current_value ) ) {
				$current_value = $encryption->maybe_decrypt( $current_value, $field_key );
			}
			
			if ( 'auto-draft' === $post->post_status && isset( $prefilled_values[ $field_key ] ) ) {
				$current_value = $prefilled_values[ $field_key ];
			}
			echo '<tr class="clisyc-meta-field-type-' . esc_attr( $field_definition['type'] ?? 'text' ) . '">';
			echo '<th scope="row"><label for="clisyc_field_' . esc_attr( $field_key ) . '">' . esc_html( $field_definition['label'] ) . '</label></th>';
			echo '<td class="clisyc-field-type-' . esc_attr( $field_definition['type'] ?? 'text' ) . '">';
			$form_renderer->render_frontend_custom_field( $field_key, $field_definition, $current_value, 'admin' );
			echo '</td>';
			echo '</tr>';
		}
		echo '</table>';
	}
	/**
	 * @deprecated 3.5.0 Moved to Appointment_Meta_Saver::save_appointment_meta_data().
	 */
	public function save_appointment_meta_data( int $post_id, \WP_Post $post ) {
		_deprecated_function( __METHOD__, '3.5.0', 'Appointment_Meta_Saver::save_appointment_meta_data()' );
		( new Appointment_Meta_Saver() )->save_appointment_meta_data( $post_id, $post );
	}

	public function register_cpt() {
		$labels = [
			'name'                  => _x( 'Appointments', 'Post type general name', 'client-sync' ),
			'singular_name'         => _x( 'Appointment', 'Post type singular name', 'client-sync' ),
			'menu_name'             => _x( 'Appointments', 'Admin Menu text', 'client-sync' ),
			'name_admin_bar'        => _x( 'Appointment', 'Add New on Toolbar', 'client-sync' ),
			'add_new'               => __( 'Add New', 'client-sync' ),
			'add_new_item'          => __( 'Add New Appointment', 'client-sync' ),
			'new_item'              => __( 'New Appointment', 'client-sync' ),
			'edit_item'             => __( 'Edit Appointment', 'client-sync' ),
			'view_item'             => __( 'View Appointment', 'client-sync' ),
			'all_items'             => __( 'All Appointments', 'client-sync' ),
			'search_items'          => __( 'Search Appointments', 'client-sync' ),
			'parent_item_colon'     => __( 'Parent Appointments:', 'client-sync' ),
			'not_found'             => __( 'No appointments found.', 'client-sync' ),
			'not_found_in_trash'    => __( 'No appointments found in Trash.', 'client-sync' ),
			'archives'              => _x( 'Appointment archives', 'The post type archive label.', 'client-sync' ),
			'insert_into_item'      => _x( 'Insert into appointment', 'Insert into post phrase.', 'client-sync' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this appointment', 'Uploaded to this post phrase.', 'client-sync' ),
			'filter_items_list'     => _x( 'Filter appointments list', 'Filter posts list phrase.', 'client-sync' ),
			'items_list_navigation' => _x( 'Appointments list navigation', 'Posts list navigation phrase.', 'client-sync' ),
			'items_list'            => _x( 'Appointments list', 'Posts list phrase.', 'client-sync' ),
		];
		$args   = [
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => null,
			'supports'           => [ 'title', 'editor', 'author', 'custom-fields' ],
			'menu_icon'          => 'dashicons-calendar-alt',
			'show_in_rest'       => true,
		];
		register_post_type( Constants::POST_TYPE_APPOINTMENT, $args );
	}
	public function register_custom_statuses() {
		$statuses = [
			Constants::STATUS_PENDING_PAYMENT => [
				'label'       => _x( 'Pending Payment', 'post status', 'client-sync' ),
				/* translators: %s: The number of posts with this status. */
				'label_count' => _n_noop( 'Pending Payment <span class="count">(%s)</span>', 'Pending Payment <span class="count">(%s)</span>', 'client-sync' ),
			],
			Constants::STATUS_PAID_ON_DAY     => [
				'label'       => _x( 'Paid (Scheduled)', 'post status', 'client-sync' ),
				/* translators: %s: The number of posts with this status. */
				'label_count' => _n_noop( 'Paid (Scheduled) <span class="count">(%s)</span>', 'Paid (Scheduled) <span class="count">(%s)</span>', 'client-sync' ),
			],
			Constants::STATUS_FAILED_ON_DAY   => [
				'label'       => _x( 'Payment Failed (Scheduled)', 'post status', 'client-sync' ),
				/* translators: %s: The number of posts with this status. */
				'label_count' => _n_noop( 'Payment Failed (Sch) <span class="count">(%s)</span>', 'Payment Failed (Sch) <span class="count">(%s)</span>', 'client-sync' ),
			],
			Constants::STATUS_WAITLISTED     => [
				'label'       => _x( 'Waitlisted', 'post status', 'client-sync' ),
				/* translators: %s: The number of posts with this status. */
				'label_count' => _n_noop( 'Waitlisted <span class="count">(%s)</span>', 'Waitlisted <span class="count">(%s)</span>', 'client-sync' ),
			],
			Constants::STATUS_CHECKED_IN     => [
				'label'       => _x( 'Checked In', 'post status', 'client-sync' ),
				/* translators: %s: The number of posts with this status. */
				'label_count' => _n_noop( 'Checked In <span class="count">(%s)</span>', 'Checked In <span class="count">(%s)</span>', 'client-sync' ),
			],
		];
		foreach ( $statuses as $status_key => $details ) {
			register_post_status(
				$status_key,
				[
					'label'                     => $details['label'],
					'public'                    => false,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => $details['label_count'],
				]
			);
		}

		// Migration moved to Plugin::run_upgrade_routines().
	}
	// add_post_status_to_display_states extracted to Appointment_Column_Handler.
	public function set_default_editor_content( string $content, \WP_Post $post ): string {
		if ( Constants::POST_TYPE_APPOINTMENT === $post->post_type && empty( $content ) ) {
			return __( 'Place your private notes for the appointment here. This content is not typically shown to the client unless explicitly configured.', 'client-sync' );
		}
		return $content;
	}
	// Column/filter methods extracted to Appointment_Column_Handler.
	public function render_service_linking_meta_box( \WP_Post $post ) {
		$booking_page_id = (int) get_option( Constants::OPTION_BOOKING_PAGE_ID, 0 );
		if ( ! $booking_page_id || 'page' !== get_post_type( $booking_page_id ) ) {
			$settings_url = esc_url( admin_url( 'admin.php?page=clisyc-settings&tab=behavior' ) );
			/* translators: %s: URL to the settings page. */
			$message = sprintf( __( 'Please <a href="%s">select a Booking Page</a> in the Behavior settings to generate direct links.', 'client-sync' ), $settings_url );
			echo '<p>' . wp_kses_post( $message ) . '</p>';
			return;
		}
		$primary_dim_key = $post->post_type;
		$booking_page_url = get_permalink( $booking_page_id );
		$direct_link_url  = add_query_arg( 'select_' . $primary_dim_key, $post->ID, $booking_page_url );
		$color            = get_post_meta( $post->ID, Constants::META_COLOR, true );
		$html_snippet     = sprintf(
			'<a href="#" class="clisyc-service-filter-link button cs-service-filter-key-item" data-service-id="%1$s">%2$s<span class="clisyc-key-item-label">%3$s</span></a>',
			esc_attr( $post->ID ),
			$color ? '<span class="clisyc-key-color-dot" style="background-color: ' . esc_attr( $color ) . ';"></span>' : '',
			esc_html( $post->post_title )
		);
		?>
		<div class="clisyc-linking-info">
			<p class="description">
				<?php esc_html_e( 'Use these links to direct users to the booking page with this item pre-selected.', 'client-sync' ); ?>
			</p>
			<p>
				<label for="clisyc-direct-link-url"><strong><?php esc_html_e( 'Direct URL', 'client-sync' ); ?></strong></label>
				<input type="text" id="clisyc-direct-link-url" value="<?php echo esc_attr( $direct_link_url ); ?>" readonly style="width:100%; margin-top: 4px;">
			</p>
			<p>
				<label for="clisyc-html-snippet"><strong><?php esc_html_e( 'HTML Snippet for Page Builders', 'client-sync' ); ?></strong></label>
				<textarea id="clisyc-html-snippet" readonly style="width:100%; margin-top: 4px; font-family: monospace; height: 5em;"><?php echo esc_textarea( $html_snippet ); ?></textarea>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the WooCommerce Integration meta box.
	 * UPDATED: Now includes Display Price field with Sync button for ALL dimension types.
	 */
	public function render_woocommerce_meta_box( \WP_Post $post ) {
		wp_nonce_field( 'clisyc_save_wc_meta_nonce_' . $post->ID, 'clisyc_wc_meta_nonce' );
		
		$product_id    = get_post_meta( $post->ID, Constants::META_WC_PRODUCT_ID, true );
		$calc_type     = get_post_meta( $post->ID, Constants::META_ADDON_CALC_TYPE, true ) ?: 'per_night';
		$additional    = get_post_meta( $post->ID, Constants::META_ADDITIONAL_PRODUCTS, true ) ?: [];
		$display_price = get_post_meta( $post->ID, Constants::META_PRICE, true );

		// Registry check to see if this is the primary dimension
		$registry   = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$is_primary = ! empty( $registry['dimensions'][ $post->post_type ]['primary'] );
		?>
		<div class="clisyc-admin-instructions-metabox">
			<p><?php esc_html_e( 'Connect this item to a WooCommerce product to handle pricing and payments.', 'client-sync' ); ?></p>
		</div>
		<table class="form-table">
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Linked Product', 'client-sync' ); ?></label></th>
				<td>
					<select id="clisyc_wc_product_id" name="clisyc_wc_product_id" class="wc-product-search" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'client-sync' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true" style="width: 50%;">
						<?php if ( $product_id && ( $product = wc_get_product( $product_id ) ) ) : ?>
							<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected"><?php echo wp_kses_post( $product->get_formatted_name() ); ?></option>
						<?php endif; ?>
					</select>
					
					<div id="clisyc-wc-product-actions" style="display: inline-block; vertical-align: middle; margin-left: 10px;">
						<?php if ( $product_id && ( $edit_url = get_edit_post_link( $product_id, 'raw' ) ) ) : ?>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-secondary">
								<?php esc_html_e( 'View / Edit Product', 'client-sync' ); ?>
								<span class="dashicons dashicons-external" style="vertical-align: text-bottom;"></span>
							</a>
						<?php else : ?>
							<button type="button" id="clisyc-create-wc-product-btn" class="button button-secondary" data-post-title="<?php echo esc_attr( $post->post_title ); ?>">
								<?php esc_html_e( 'Create & Link Product', 'client-sync' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</td>
			</tr>
			<!-- CONSOLIDATED: Display Price field now appears for ALL dimensions -->
			<tr>
				<th scope="row"><label for="clisyc_price"><?php esc_html_e( 'Display Price', 'client-sync' ); ?></label></th>
				<td>
					<div style="display: flex; gap: 10px; align-items: center;">
						<input type="number" id="clisyc_price" name="clisyc_price" value="<?php echo esc_attr( $display_price ); ?>" class="small-text" placeholder="0.00" min="0" step="0.01" style="width: 100px;">
						<button type="button" id="clisyc-sync-price-btn" class="button button-secondary" <?php echo ! $product_id ? 'disabled' : ''; ?> title="<?php esc_attr_e( 'Pull price from the linked WooCommerce product', 'client-sync' ); ?>">
							<span class="dashicons dashicons-update" style="vertical-align: text-bottom; font-size: 16px; width: 16px; height: 16px;"></span>
							<?php esc_html_e( 'Sync from Product', 'client-sync' ); ?>
						</button>
					</div>
					<p class="description"><?php esc_html_e( 'This is the price shown on the calendar. Click "Sync" to pull the current price from the linked WooCommerce product.', 'client-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Calculation Method', 'client-sync' ); ?></label></th>
				<td>
					<select name="clisyc_addon_calc_type" class="widefat" style="max-width: 300px;">
						<option value="per_night" <?php selected( $calc_type, 'per_night' ); ?>><?php esc_html_e( 'Per Night / Day', 'client-sync' ); ?></option>
						<option value="per_hour" <?php selected( $calc_type, 'per_hour' ); ?>><?php esc_html_e( 'Per Hour', 'client-sync' ); ?></option>
						<option value="per_minute" <?php selected( $calc_type, 'per_minute' ); ?>><?php esc_html_e( 'Per Minute', 'client-sync' ); ?></option>
						<option value="flat" <?php selected( $calc_type, 'flat' ); ?>><?php esc_html_e( 'Flat Fee (One-time Charge)', 'client-sync' ); ?></option>
					</select>
					<!-- NEW DOCUMENTATION BLOCK -->
					<div style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1; font-size: 12px; line-height: 1.4;">
						<strong><?php esc_html_e( 'Which should I choose?', 'client-sync' ); ?></strong><br>
						<ul style="margin: 5px 0 0 15px; list-style: disc;">
							<li>
								<strong><?php esc_html_e( 'Per Night / Day:', 'client-sync' ); ?></strong> 
								<?php esc_html_e( 'The WooCommerce price is multiplied by the number of nights (Date Range) or attendees (Time Slot). Use this for daily rentals or per-person classes.', 'client-sync' ); ?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Flat Fee:', 'client-sync' ); ?></strong> 
								<?php esc_html_e( 'The WooCommerce price is added once, regardless of the duration or quantity. Use this for cleaning fees, insurance, or fixed-price appointments.', 'client-sync' ); ?>
							</li>
						</ul>
					</div>
				</td>
			</tr>
		</table>
		<?php if ( $is_primary ) : ?>
			<hr>
			<div style="margin-top: 20px;">
				<h4><?php esc_html_e( 'Additional Required Products (Mandatory Fees)', 'client-sync' ); ?></h4>
				<p class="description"><?php esc_html_e( 'Use this to add mandatory fees like cleaning, fuel, or insurance that will be added to the cart alongside the primary booking.', 'client-sync' ); ?></p>
				
				<div id="clisyc-additional-products-list" style="margin-top: 15px; background: #f6f7f7; padding: 15px; border: 1px solid #ddd;">
					<?php 
					if ( ! empty( $additional ) ) {
						foreach ( $additional as $index => $item ) {
							$this->render_additional_product_row( $index, $item );
						}
					} else {
						echo '<p class="clisyc-no-addons-msg">' . esc_html__( 'No mandatory fees added yet.', 'client-sync' ) . '</p>';
					}
					?>
				</div>
				
				<p><button type="button" class="button button-secondary" id="clisyc-add-required-product"><?php esc_html_e( '+ Add Required Fee', 'client-sync' ); ?></button></p>
			</div>
			<!-- Template for JS to clone (script tags are not submitted by the browser) -->
			<script type="text/html" id="clisyc-addon-row-template">
				<?php $this->render_additional_product_row( '{{index}}' ); ?>
			</script>
			<!-- FIX: Only provide data - all event handlers are in clisyc-admin-wc-product-helper.js -->
			<script>
				window.clisycAddonStartIndex = <?php echo count( $additional ); ?>;
			</script>
		<?php endif; ?>

		<!-- Sync Price Button Handler -->
		<script>
			jQuery(document).ready(function($) {
				$('#clisyc-sync-price-btn').on('click', function() {
					var $btn = $(this);
					var productId = $('#clisyc_wc_product_id').val();
					
					if (!productId) {
						alert('<?php echo esc_js( __( 'Please select a linked product first.', 'client-sync' ) ); ?>');
						return;
					}
					
					$btn.prop('disabled', true).find('.dashicons').addClass('clisyc-spin');
					
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'clisyc_get_product_price',
							product_id: productId,
							nonce: '<?php echo esc_js( wp_create_nonce( 'clisyc_sync_price_nonce' ) ); ?>'
						},
						success: function(response) {
							if (response.success && response.data.price !== undefined) {
								$('#clisyc_price').val(response.data.price);
							} else {
								alert(response.data.message || '<?php echo esc_js( __( 'Could not retrieve product price.', 'client-sync' ) ); ?>');
							}
						},
						error: function() {
							alert('<?php echo esc_js( __( 'An error occurred while syncing the price.', 'client-sync' ) ); ?>');
						},
						complete: function() {
							$btn.prop('disabled', false).find('.dashicons').removeClass('clisyc-spin');
						}
					});
				});

				// Enable/disable sync button based on product selection
				$('#clisyc_wc_product_id').on('change', function() {
					$('#clisyc-sync-price-btn').prop('disabled', !$(this).val());
				});
			});
		</script>
		<style>
			.dashicons.clisyc-spin {
				animation: clisyc-spin 1s linear infinite;
			}
			@keyframes clisyc-spin {
				from { transform: rotate(0deg); }
				to { transform: rotate(360deg); }
			}
		</style>
		<?php
	}
	/**
	 * Renders a single row for an additional required product.
	 */
	private function render_additional_product_row( $index, $data = [] ) {
		$product_id = absint( $data['product_id'] ?? 0 );
		$calc_type  = $data['calc_type'] ?? 'flat';
		
		// Use a unique name attribute for the array structure
		$name_base = "_clisyc_additional_products[{$index}]";
		?>
		<div class="clisyc-additional-product-row" style="display: flex; gap: 10px; align-items: flex-start; margin-bottom: 10px; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
			<div style="flex: 1;">
				<label style="display:block; margin-bottom:4px; font-weight:600;"><?php esc_html_e( 'Product', 'client-sync' ); ?></label>
				<select name="<?php echo esc_attr( $name_base . '[product_id]' ); ?>" class="wc-product-search" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'client-sync' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width: 100%;">
					<?php if ( $product_id && ( $product = wc_get_product( $product_id ) ) ) : ?>
						<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected"><?php echo wp_kses_post( $product->get_formatted_name() ); ?></option>
					<?php endif; ?>
				</select>
			</div>
			<div style="width: 200px;">
				<label style="display:block; margin-bottom:4px; font-weight:600;"><?php esc_html_e( 'Calculation', 'client-sync' ); ?></label>
				<select name="<?php echo esc_attr( $name_base . '[calc_type]' ); ?>" class="widefat">
					<option value="flat" <?php selected( $calc_type, 'flat' ); ?>><?php esc_html_e( 'Flat Fee (Once)', 'client-sync' ); ?></option>
					<option value="per_night" <?php selected( $calc_type, 'per_night' ); ?>><?php esc_html_e( 'Per Night/Day', 'client-sync' ); ?></option>
				</select>
			</div>
			<div style="padding-top: 24px;">
				<button type="button" class="button clisyc-remove-product-row" style="color: #d63638; border-color: #d63638;">
					<span class="dashicons dashicons-trash" style="vertical-align: text-bottom;"></span>
				</button>
			</div>
		</div>
		<?php
	}
	public function render_employee_user_link_meta_box( \WP_Post $post ) {
		wp_nonce_field( 'clisyc_save_employee_user_link_nonce_' . $post->ID, 'clisyc_employee_user_link_nonce' );
		$linked_user_id = get_post_meta( $post->ID, Constants::META_EMPLOYEE_USER_ID, true );
		$users = get_users( [ 'role__in' => [ 'administrator', 'editor', 'author' ] ] );
		echo '<p>' . esc_html__( 'Select the WordPress user account for this employee.', 'client-sync' ) . '</p>';
		echo '<select name="_clisyc_employee_user_id" style="width: 100%;">';
		echo '<option value="">' . esc_html__( '-- Not Linked --', 'client-sync' ) . '</option>';
		foreach ( $users as $user ) {
			echo '<option value="' . esc_attr( $user->ID ) . '" ' . selected( $linked_user_id, $user->ID, false ) . '>' . esc_html( $user->display_name ) . ' (' . esc_html( $user->user_email ) . ')</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The selected user will be able to connect their own Google Calendar.', 'client-sync' ) . '</p>';
	}
	public function render_google_sync_meta_box( \WP_Post $post ) {
		$employee_user_id = (int) get_post_meta( $post->ID, Constants::META_EMPLOYEE_USER_ID, true );
		if ( ! $employee_user_id ) {
			echo '<p><em>' . esc_html__( 'Link this employee to a WordPress user account to enable calendar sync.', 'client-sync' ) . '</em></p>';
			return;
		}
		if ( ! current_user_can( 'edit_user', $employee_user_id ) && get_current_user_id() !== $employee_user_id ) {
			echo '<p><em>' . esc_html__( 'Only an administrator or the linked user can manage this connection.', 'client-sync' ) . '</em></p>';
			return;
		}
		$token_data = get_user_meta( $employee_user_id, \ClientSyncPro\Services\Google_Sync_Service::TOKEN_USER_META, true );
		if ( ! empty( $token_data['access_token'] ) ) {
			echo '<p>' . sprintf( '<strong>%s</strong> %s', esc_html__( 'Status:', 'client-sync' ), '<span style="color: #4CAF50;">' . esc_html__( 'Connected', 'client-sync' ) . '</span>' ) . '</p>';
			echo '<p>' . sprintf( '<strong>%s</strong> %s', esc_html__( 'Account:', 'client-sync' ), '<code>' . esc_html( $token_data['email'] ) . '</code>' ) . '</p>';
			$disconnect_url = wp_nonce_url( admin_url( 'admin-post.php?action=clisyc_google_sync_disconnect&user_id=' . $employee_user_id ), 'google_sync_disconnect_' . $employee_user_id );
			$sync_now_url = wp_nonce_url( admin_url( 'admin-post.php?action=clisyc_google_sync_now&user_id=' . $employee_user_id ), 'google_sync_now_' . $employee_user_id );
			echo '<div style="display: flex; gap: 5px; flex-wrap: wrap;">';
			echo '<a href="' . esc_url( $sync_now_url ) . '" class="button button-secondary">' . esc_html__( 'Sync Now', 'client-sync' ) . '</a>';
			echo '<a href="' . esc_url( $disconnect_url ) . '" class="button-link is-destructive" style="line-height: 2.2;">' . esc_html__( 'Disconnect', 'client-sync' ) . '</a>';
			echo '</div>';
		} else {
			echo '<p>' . sprintf( '<strong>%s</strong> %s', esc_html__( 'Status:', 'client-sync' ), '<span style="color: #d63638;">' . esc_html__( 'Not Connected', 'client-sync' ) . '</span>' ) . '</p>';
			$connect_url = wp_nonce_url( admin_url( 'admin-post.php?action=clisyc_google_sync_redirect&user_id=' . $employee_user_id ), 'google_sync_auth_' . $employee_user_id );
			echo '<a href="' . esc_url( $connect_url ) . '" class="button button-primary">' . esc_html__( 'Connect to Google Calendar', 'client-sync' ) . '</a>';
		}
	}
	// Pricing rules methods extracted to Pricing_Rules_Handler.
}