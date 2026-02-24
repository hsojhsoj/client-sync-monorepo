<?php
/**
 * File: src/shared/includes/core/class-appointment-column-handler.php
 * Handles custom admin list columns, filters, and query modifications for appointments.
 *
 * Extracted from PostType_Manager to reduce class size.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */
namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Appointment_Column_Handler {

	/**
	 * Register WordPress hooks for admin columns and filters.
	 */
	public function register_hooks() {
		add_filter( 'display_post_states', [ $this, 'add_post_status_to_display_states' ], 10, 2 );
		add_filter( 'manage_clisyc_appointment_posts_columns', [ $this, 'add_custom_columns' ] );
		add_action( 'manage_clisyc_appointment_posts_custom_column', [ $this, 'render_custom_column_content' ], 10, 2 );
		add_filter( 'manage_edit-clisyc_appointment_sortable_columns', [ $this, 'make_columns_sortable' ] );
		add_action( 'restrict_manage_posts', [ $this, 'add_admin_list_filters' ] );
		add_action( 'pre_get_posts', [ $this, 'modify_admin_list_query' ] );
		add_filter( 'post_row_actions', [ $this, 'add_resend_email_row_action' ], 10, 2 );
		add_action( 'wp_ajax_clisyc_resend_confirmation', [ $this, 'ajax_resend_confirmation' ] );
		add_action( 'admin_footer-edit.php', [ $this, 'resend_email_inline_script' ] );

		// Check-in row action.
		add_filter( 'post_row_actions', [ $this, 'add_checkin_row_action' ], 10, 2 );
		add_action( 'wp_ajax_clisyc_checkin_appointment', [ $this, 'ajax_checkin_appointment' ] );
		add_action( 'admin_footer-edit.php', [ $this, 'checkin_inline_script' ] );
	}

	public function add_post_status_to_display_states( array $states, \WP_Post $post ): array {
		if ( Constants::POST_TYPE_APPOINTMENT === get_post_type( $post ) ) {
			$status_obj = get_post_status_object( $post->post_status );
			if ( $status_obj && ! $status_obj->_builtin ) {
				$states[ $post->post_status ] = $status_obj->label;
			}
		}
		return $states;
	}

	public function add_custom_columns( array $columns ): array {
		$new_columns                          = [];
		$new_columns['clisyc_appt_date']      = __( 'Appt Date', 'client-sync' );
		$new_columns['clisyc_appt_time']      = __( 'Appt Time', 'client-sync' );
		$new_columns['clisyc_payment_status'] = __( 'Payment', 'client-sync' );
		$registry        = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug  = null;
		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				if ( ! empty( $settings['primary'] ) ) {
					$primary_dim_slug = $slug;
					break;
				}
			}
		}
		if ( $primary_dim_slug ) {
			$cpt_object               = get_post_type_object( $primary_dim_slug );
			$primary_dim_label        = $cpt_object ? $cpt_object->labels->singular_name : __( 'Primary Item', 'client-sync' );
			$new_columns[ $primary_dim_slug ] = esc_html( $primary_dim_label );
		}
		$position = array_search( 'author', array_keys( $columns ), true );
		if ( false !== $position ) {
			return array_slice( $columns, 0, $position + 1, true ) + $new_columns + array_slice( $columns, $position + 1, null, true );
		}
		return array_merge( $columns, $new_columns );
	}

	public function render_custom_column_content( string $column_name, int $post_id ) {
		if ( 'clisyc_appt_date' === $column_name || 'clisyc_appt_time' === $column_name ) {
			$booking_mode = get_post_meta( $post_id, Constants::META_BOOKING_MODE, true );
			if ( 'date_range' === $booking_mode ) {
				if ( 'clisyc_appt_date' === $column_name ) {
					$start = get_post_meta( $post_id, Constants::META_START_DATE, true );
					$end   = get_post_meta( $post_id, Constants::META_END_DATE, true );
					if ( $start && $end ) {
						echo esc_html( $start . ' to ' . $end );
					} else {
						echo '—';
					}
				} elseif ( 'clisyc_appt_time' === $column_name ) {
					esc_html_e( 'Multi-Day', 'client-sync' );
				}
				return;
			}
			$time_slot_utc_str = get_post_meta( $post_id, Constants::META_TIME_SLOT, true );
			if ( empty( $time_slot_utc_str ) ) {
				echo '—';
				return;
			}
			try {
				$datetime_utc  = new \DateTime( $time_slot_utc_str, new \DateTimeZone( 'UTC' ) );
				$datetime_site = $datetime_utc->setTimezone( wp_timezone() );
				$timestamp     = $datetime_site->getTimestamp();
				if ( 'clisyc_appt_date' === $column_name ) {
					echo esc_html( wp_date( get_option( 'date_format' ), $timestamp ) );
				} else {
					echo esc_html( wp_date( get_option( 'time_format' ), $timestamp ) );
				}
			} catch ( \Exception $e ) {
				echo '—';
			}
		} elseif ( 'clisyc_payment_status' === $column_name ) {
			$status = get_post_meta( $post_id, Constants::META_PAYMENT_STATUS, true );
			if ( empty( $status ) ) {
				echo '<span class="clisyc-payment-badge clisyc-payment-none">—</span>';
			} else {
				$labels = [
					'paid_via_stripe'      => __( 'Paid', 'client-sync' ),
					'paid_via_wc'          => __( 'Paid', 'client-sync' ),
					'pending'              => __( 'Pending', 'client-sync' ),
					'failed'               => __( 'Failed', 'client-sync' ),
					'refunded'             => __( 'Refunded', 'client-sync' ),
					'partially_refunded'   => __( 'Partial Refund', 'client-sync' ),
				];
				$colors = [
					'paid_via_stripe'      => '#00a32a',
					'paid_via_wc'          => '#00a32a',
					'pending'              => '#dba617',
					'failed'               => '#d63638',
					'refunded'             => '#72aee6',
					'partially_refunded'   => '#72aee6',
				];
				$label = $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
				$color = $colors[ $status ] ?? '#787c82';
				printf(
					'<span class="clisyc-payment-badge" style="background:%s;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;white-space:nowrap;">%s</span>',
					esc_attr( $color ),
					esc_html( $label )
				);
			}
		} elseif ( strpos( $column_name, 'clisyc_' ) === 0 ) {
			$dimensions = get_post_meta( $post_id, Constants::META_SLOT_DIMENSIONS, true );
			if ( is_array( $dimensions ) && isset( $dimensions[ $column_name ] ) ) {
				$item_id = $dimensions[ $column_name ];
				$title   = get_the_title( $item_id );
				echo $title ? esc_html( $title ) : '—';
			} else {
				echo '—';
			}
		}
	}

	public function make_columns_sortable( array $columns ): array {
		$columns['clisyc_appt_date'] = 'clisyc_time_slot';
		$columns['clisyc_appt_time'] = 'clisyc_time_slot';
		return $columns;
	}

	public function add_admin_list_filters() {
		if ( Constants::POST_TYPE_APPOINTMENT !== ( $GLOBALS['typenow'] ?? null ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL filters.
		$unslashed_get = wp_unslash( $_GET );
		$start_date = isset( $unslashed_get['clisyc_filter_date_start'] ) ? sanitize_text_field( $unslashed_get['clisyc_filter_date_start'] ) : '';
		$end_date   = isset( $unslashed_get['clisyc_filter_date_end'] ) ? sanitize_text_field( $unslashed_get['clisyc_filter_date_end'] ) : '';
		echo '<input type="text" name="clisyc_filter_date_start" class="clisyc-admin-datepicker" placeholder="' . esc_attr__( 'Start Date', 'client-sync' ) . '" value="' . esc_attr( $start_date ) . '" autocomplete="off">';
		echo '<input type="text" name="clisyc_filter_date_end" class="clisyc-admin-datepicker" placeholder="' . esc_attr__( 'End Date', 'client-sync' ) . '" value="' . esc_attr( $end_date ) . '" autocomplete="off">';

		$current_payment = isset( $unslashed_get['clisyc_filter_payment'] ) ? sanitize_key( $unslashed_get['clisyc_filter_payment'] ) : '';
		$payment_options = [
			''                     => __( 'All Payments', 'client-sync' ),
			'paid_via_stripe'      => __( 'Paid (Stripe)', 'client-sync' ),
			'paid_via_wc'          => __( 'Paid (WC)', 'client-sync' ),
			'pending'              => __( 'Pending', 'client-sync' ),
			'failed'               => __( 'Failed', 'client-sync' ),
			'refunded'             => __( 'Refunded', 'client-sync' ),
			'partially_refunded'   => __( 'Partial Refund', 'client-sync' ),
			'_none'                => __( 'No Payment', 'client-sync' ),
		];
		echo '<select name="clisyc_filter_payment">';
		foreach ( $payment_options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current_payment, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public function modify_admin_list_query( \WP_Query $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== ( $GLOBALS['pagenow'] ?? null ) || Constants::POST_TYPE_APPOINTMENT !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL filters.
		$unslashed_get = wp_unslash( $_GET );
		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = [];
		}
		if ( ! empty( $unslashed_get['clisyc_filter_date_start'] ) ) {
			$start_date_local_str = sanitize_text_field( $unslashed_get['clisyc_filter_date_start'] );
			try {
				$start_dt_utc = ( new \DateTime( $start_date_local_str . ' 00:00:00', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
				$meta_query[] = [
					'key'     => 'clisyc_time_slot',
					'value'   => $start_dt_utc->format( 'Y-m-d H:i:s' ),
					'compare' => '>=',
				];
			} catch ( \Exception $e ) {
				Debug_Logger::log( 'Invalid admin date filter start value: ' . $e->getMessage(), 'Admin' );
			}
		}
		if ( ! empty( $unslashed_get['clisyc_filter_date_end'] ) ) {
			$end_date_local_str = sanitize_text_field( $unslashed_get['clisyc_filter_date_end'] );
			try {
				$end_dt_utc   = ( new \DateTime( $end_date_local_str . ' 23:59:59', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
				$meta_query[] = [
					'key'     => 'clisyc_time_slot',
					'value'   => $end_dt_utc->format( 'Y-m-d H:i:s' ),
					'compare' => '<=',
				];
			} catch ( \Exception $e ) {
				Debug_Logger::log( 'Invalid admin date filter end value: ' . $e->getMessage(), 'Admin' );
			}
		}
		if ( ! empty( $unslashed_get['clisyc_filter_payment'] ) ) {
			$payment_filter = sanitize_key( $unslashed_get['clisyc_filter_payment'] );
			if ( '_none' === $payment_filter ) {
				$meta_query[] = [
					'relation' => 'OR',
					[
						'key'     => Constants::META_PAYMENT_STATUS,
						'compare' => 'NOT EXISTS',
					],
					[
						'key'   => Constants::META_PAYMENT_STATUS,
						'value' => '',
					],
				];
			} else {
				$meta_query[] = [
					'key'   => Constants::META_PAYMENT_STATUS,
					'value' => $payment_filter,
				];
			}
		}
		if ( count( $meta_query ) > 1 && ! isset( $meta_query['relation'] ) ) {
			$meta_query['relation'] = 'AND';
		}
		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}
		$orderby             = $query->get( 'orderby' );
		$primary_service_key = get_option( 'clisyc_primary_service_dimension_key', '' );
		if ( 'clisyc_time_slot' === $orderby ) {
			$query->set( 'meta_key', 'clisyc_time_slot' );
			$query->set( 'orderby', 'meta_value' );
		} elseif ( ! empty( $primary_service_key ) && $primary_service_key === $orderby ) {
			$query->set( 'meta_key', $primary_service_key );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Add "Resend Confirmation" to appointment row actions.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    Current post.
	 * @return array
	 */
	public function add_resend_email_row_action( array $actions, \WP_Post $post ): array {
		if ( Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		$nonce = wp_create_nonce( 'clisyc_resend_confirmation_' . $post->ID );

		$actions['clisyc_resend_email'] = sprintf(
			'<a href="#" class="clisyc-resend-email" data-post-id="%d" data-nonce="%s">%s</a>',
			$post->ID,
			$nonce,
			esc_html__( 'Resend Confirmation', 'client-sync' )
		);

		return $actions;
	}

	/**
	 * AJAX handler: Resend the client confirmation email for an appointment.
	 */
	public function ajax_resend_confirmation() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! check_ajax_referer( 'clisyc_resend_confirmation_' . $post_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'client-sync' ) ] );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
		}

		$post = get_post( $post_id );
		if ( ! $post || Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			wp_send_json_error( [ 'message' => __( 'Appointment not found.', 'client-sync' ) ] );
		}

		$notifications = new Notifications();
		$result = $notifications->send( 'new_appointment_client', $post_id );

		if ( $result ) {
			wp_send_json_success( [ 'message' => __( 'Confirmation email resent.', 'client-sync' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'No notification channels sent the email. Check your output template configuration.', 'client-sync' ) ] );
		}
	}

	/**
	 * Print inline JS for the resend email row action on the appointment list screen.
	 */
	public function resend_email_inline_script() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . Constants::POST_TYPE_APPOINTMENT !== $screen->id ) {
			return;
		}
		?>
		<script>
		jQuery(function($){
			$('.clisyc-resend-email').on('click',function(e){
				e.preventDefault();
				var $link=$(this),postId=$link.data('post-id'),nonce=$link.data('nonce'),origText=$link.text();
				$link.text('<?php echo esc_js( __( 'Sending…', 'client-sync' ) ); ?>').css('pointer-events','none');
				$.post(ajaxurl,{action:'clisyc_resend_confirmation',post_id:postId,nonce:nonce},function(resp){
					$link.text(resp.success?'<?php echo esc_js( __( 'Sent!', 'client-sync' ) ); ?>':resp.data.message||'<?php echo esc_js( __( 'Failed', 'client-sync' ) ); ?>');
					setTimeout(function(){$link.text(origText).css('pointer-events','');},3000);
				}).fail(function(){
					$link.text('<?php echo esc_js( __( 'Failed', 'client-sync' ) ); ?>');
					setTimeout(function(){$link.text(origText).css('pointer-events','');},3000);
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Add "Check In" to appointment row actions.
	 *
	 * Only shown for confirmed/published/paid appointments that haven't been checked in yet.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    Current post.
	 * @return array
	 */
	public function add_checkin_row_action( array $actions, \WP_Post $post ): array {
		if ( Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		// Only show "Check In" for eligible statuses (not already checked in, cancelled, etc.).
		$eligible_statuses = [
			'publish',
			Constants::STATUS_CONFIRMED,
			Constants::STATUS_PAID_ON_DAY,
			Constants::STATUS_PENDING_PAYMENT,
		];

		if ( ! in_array( $post->post_status, $eligible_statuses, true ) ) {
			return $actions;
		}

		$nonce = wp_create_nonce( 'clisyc_checkin_' . $post->ID );

		$actions['clisyc_checkin'] = sprintf(
			'<a href="#" class="clisyc-checkin-action" data-post-id="%d" data-nonce="%s" style="color:#059669;font-weight:600;">%s</a>',
			$post->ID,
			$nonce,
			esc_html__( 'Check In', 'client-sync' )
		);

		return $actions;
	}

	/**
	 * AJAX handler: Check in an appointment.
	 */
	public function ajax_checkin_appointment() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! check_ajax_referer( 'clisyc_checkin_' . $post_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'client-sync' ) ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync' ) ] );
		}

		$post = get_post( $post_id );
		if ( ! $post || Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			wp_send_json_error( [ 'message' => __( 'Appointment not found.', 'client-sync' ) ] );
		}

		// Already checked in?
		if ( Constants::STATUS_CHECKED_IN === $post->post_status ) {
			$checked_in_at = get_post_meta( $post_id, Constants::META_CHECKED_IN_AT, true );
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: check-in time */
					__( 'Already checked in at %s.', 'client-sync' ),
					$checked_in_at ? wp_date( get_option( 'time_format' ), strtotime( $checked_in_at ) ) : '—'
				),
			] );
		}

		$update_result = wp_update_post( [
			'ID'          => $post_id,
			'post_status' => Constants::STATUS_CHECKED_IN,
		] );

		if ( is_wp_error( $update_result ) ) {
			wp_send_json_error( [ 'message' => __( 'Failed to check in appointment.', 'client-sync' ) ] );
		}

		// Record the check-in timestamp.
		$now = current_time( 'mysql' );
		update_post_meta( $post_id, Constants::META_CHECKED_IN_AT, $now );

		wp_send_json_success( [
			'message'      => __( 'Checked in successfully!', 'client-sync' ),
			'checked_in_at' => wp_date( get_option( 'time_format' ), strtotime( $now ) ),
		] );
	}

	/**
	 * Print inline JS for the check-in row action on the appointment list screen.
	 */
	public function checkin_inline_script() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . Constants::POST_TYPE_APPOINTMENT !== $screen->id ) {
			return;
		}
		?>
		<script>
		jQuery(function($){
			$('.clisyc-checkin-action').on('click',function(e){
				e.preventDefault();
				var $link=$(this),postId=$link.data('post-id'),nonce=$link.data('nonce'),origText=$link.text();
				if(!confirm('<?php echo esc_js( __( 'Check in this appointment?', 'client-sync' ) ); ?>'))return;
				$link.text('<?php echo esc_js( __( 'Checking in…', 'client-sync' ) ); ?>').css('pointer-events','none');
				$.post(ajaxurl,{action:'clisyc_checkin_appointment',post_id:postId,nonce:nonce},function(resp){
					if(resp.success){
						$link.text('<?php echo esc_js( __( 'Checked In', 'client-sync' ) ); ?> ✓').css({color:'#059669','pointer-events':'none'});
						// Update the status display in the row.
						var $row=$link.closest('tr');
						$row.find('.post_status_display,.column-status').text('<?php echo esc_js( __( 'Checked In', 'client-sync' ) ); ?>');
					}else{
						$link.text(resp.data.message||'<?php echo esc_js( __( 'Failed', 'client-sync' ) ); ?>');
						setTimeout(function(){$link.text(origText).css('pointer-events','');},3000);
					}
				}).fail(function(){
					$link.text('<?php echo esc_js( __( 'Failed', 'client-sync' ) ); ?>');
					setTimeout(function(){$link.text(origText).css('pointer-events','');},3000);
				});
			});
		});
		</script>
		<?php
	}
}
