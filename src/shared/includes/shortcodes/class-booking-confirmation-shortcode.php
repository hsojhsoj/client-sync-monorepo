<?php
/**
 * File: src/shared/includes/shortcodes/class-booking-confirmation-shortcode.php
 * Handles the [clisyc_booking_confirmation] shortcode.
 *
 * UPDATED: Integrated with explicit ID support and removed pending status restrictions
 * to ensure users see confirmation details even during payment processing.
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

class Booking_Confirmation_Shortcode {

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'clisyc_booking_confirmation', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		// 1. Parse attributes first
		$atts = shortcode_atts( [
			'appointment_id'      => 0,
			'order_id'            => 0,
			'timeout'             => 10,
			'show_details'        => 'true',
			'show_calendar_links' => 'true',
			'show_confetti'       => 'true',
			'show_actions'        => 'true',
			'success_title'       => __( 'Booking Confirmed!', 'client-sync' ),
			'success_message'     => __( 'Your appointment has been successfully scheduled.', 'client-sync' ),
			'fallback_content'    => '',
			'style'               => 'card',
		], $atts, 'clisyc_booking_confirmation' );

		// 2. Identify the Appointment ID
		$appointment_id = 0;

		if ( ! empty( $atts['appointment_id'] ) ) {
			// PRIORITY 1: Explicit ID from attribute (This is what the WC hook uses)
			$appointment_id = absint( $atts['appointment_id'] );
		} elseif ( ! empty( $atts['order_id'] ) && function_exists( 'wc_get_order' ) ) {
			// PRIORITY 2: Explicit Order ID
			$order = wc_get_order( absint( $atts['order_id'] ) );
			if ( $order ) {
				foreach ( $order->get_items() as $item ) {
					$appt_id = $item->get_meta( '_clisyc_appointment_id', true );
					if ( $appt_id ) {
						$appointment_id = absint( $appt_id );
						break;
					}
				}
			}
		} else {
			// PRIORITY 3: Auto-detection (URL params or recent history)
			
			// Safety: If auto-detecting, block on the main checkout page
			if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
				return '';
			}
			
			$appointment_id = $this->get_recent_appointment_id( absint( $atts['timeout'] ) );
		}

		// 3. Final validation: If no ID found, show fallback or nothing
		if ( ! $appointment_id ) {
			return do_shortcode( $atts['fallback_content'] );
		}

		// Enqueue styles
		wp_enqueue_style( 'clisyc-frontend-style' );
		wp_enqueue_style( 'dashicons' );
		
		if ( ! wp_style_is( 'clisyc-booking-confirmation', 'registered' ) ) {
			wp_register_style(
				'clisyc-booking-confirmation',
				clisyc_PLUGIN_URL . 'assets/css/clisyc-booking-confirmation.css',
				[ 'clisyc-frontend-style', 'dashicons' ],
				clisyc_VERSION
			);
		}
		wp_enqueue_style( 'clisyc-booking-confirmation' );

		return $this->render_confirmation_ui( $appointment_id, $atts );
	}

	/**
	 * Detects if a booking just happened using multiple detection methods.
	 *
	 * @param int $timeout_minutes Minutes to look back for recent appointments.
	 * @return int|false Appointment ID or false if not found.
	 */
	private function get_recent_appointment_id( $timeout_minutes ) {
		// A. Check for WooCommerce "Order Received" Page
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			global $wp;
			$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					foreach ( $order->get_items() as $item ) {
						$appt_id = $item->get_meta( '_clisyc_appointment_id', true );
						if ( $appt_id ) {
							return absint( $appt_id );
						}
					}
				}
			}
		}

		// B. Check URL Parameters (Direct redirect from Free booking or specific view link)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['clisyc_booking_status'] ) && isset( $_GET['appt_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$appt_id = absint( $_GET['appt_id'] );
			if ( $this->validate_appointment_access( $appt_id ) ) {
				return $appt_id;
			}
		}

		// Check for view_id in URL (Direct link to confirmation)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['view_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$appt_id = absint( $_GET['view_id'] );
			if ( $this->validate_appointment_access( $appt_id ) ) {
				return $appt_id;
			}
		}

		// C. Fallback to session/transient or recently logged-in users
		$transient_key     = 'clisyc_booking_success_' . ( is_user_logged_in() ? get_current_user_id() : md5( $this->get_client_ip() ) );
		$transient_appt_id = get_transient( $transient_key );
		if ( $transient_appt_id ) {
			delete_transient( $transient_key );
			if ( $this->validate_appointment_access( $transient_appt_id ) ) {
				return $transient_appt_id;
			}
		}

		if ( is_user_logged_in() ) {
			return $this->query_most_recent_user_appointment( $timeout_minutes );
		}

		return false;
	}

	/**
	 * Validate that the current user can access the appointment.
	 *
	 * @param int $appointment_id Appointment post ID.
	 * @return bool True if accessible.
	 */
	private function validate_appointment_access( $appointment_id ) {
		$appointment = get_post( $appointment_id );
		if ( ! $appointment || Constants::POST_TYPE_APPOINTMENT !== $appointment->post_type ) {
			return false;
		}

		// Admin can access any
		if ( current_user_can( 'edit_others_posts' ) ) {
			return true;
		}

		// Owner can access their own
		if ( is_user_logged_in() && (int) $appointment->post_author === get_current_user_id() ) {
			return true;
		}

		// Check email match for guest bookings
		$client_email = get_post_meta( $appointment_id, '_clisyc_client_email', true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $client_email && isset( $_GET['email'] ) && sanitize_email( wp_unslash( $_GET['email'] ) ) === $client_email ) {
			return true;
		}

		return false;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string IP address.
	 */
	private function get_client_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return $ip;
	}

	/**
	 * Query for the most recent appointment by the current user.
	 *
	 * @param int $timeout_minutes Minutes to look back.
	 * @return int|false Appointment ID or false.
	 */
	private function query_most_recent_user_appointment( $timeout_minutes ) {
		$cutoff_time = gmdate( 'Y-m-d H:i:s', strtotime( "-{$timeout_minutes} minutes" ) );
		$args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => [ 'publish', 'pending', 'confirmed', Constants::STATUS_PENDING_PAYMENT ],
			'author'         => get_current_user_id(),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => [ [ 'after' => $cutoff_time, 'inclusive' => true ] ],
		];
		$query = new \WP_Query( $args );
		return $query->have_posts() ? $query->posts[0] : false;
	}

	/**
	 * Get status display information.
	 *
	 * @param string $status Post status.
	 * @return array Label and CSS class.
	 */
	private function get_status_info( $status ) {
		$statuses = [
			'publish'                => [ 'label' => __( 'Confirmed', 'client-sync' ), 'class' => 'confirmed', 'icon' => 'yes-alt' ],
			'confirmed'              => [ 'label' => __( 'Confirmed', 'client-sync' ), 'class' => 'confirmed', 'icon' => 'yes-alt' ],
			'pending'                => [ 'label' => __( 'Pending', 'client-sync' ), 'class' => 'pending', 'icon' => 'clock' ],
			Constants::STATUS_PENDING_PAYMENT => [ 'label' => __( 'Awaiting Payment', 'client-sync' ), 'class' => 'pending-payment', 'icon' => 'money-alt' ],
			'cancelled'              => [ 'label' => __( 'Cancelled', 'client-sync' ), 'class' => 'cancelled', 'icon' => 'dismiss' ],
			'completed'              => [ 'label' => __( 'Completed', 'client-sync' ), 'class' => 'completed', 'icon' => 'awards' ],
		];

		return $statuses[ $status ] ?? [ 
			'label' => ucfirst( str_replace( [ 'clisyc_', '_' ], [ '', ' ' ], $status ) ), 
			'class' => 'default',
			'icon'  => 'marker',
		];
	}

	/**
	 * Build appointment details array from meta.
	 *
	 * @param int $appointment_id Appointment post ID.
	 * @return array Appointment details.
	 */
	private function get_appointment_details( $appointment_id ) {
		$details = [];

		// Get time slot
		$time_slot = get_post_meta( $appointment_id, Constants::META_TIME_SLOT, true );
		if ( $time_slot ) {
			try {
				$datetime = new \DateTime( $time_slot, wp_timezone() );
				$details['date'] = [
					'icon'  => 'calendar-alt',
					'label' => __( 'Date', 'client-sync' ),
					'value' => wp_date( get_option( 'date_format' ), $datetime->getTimestamp() ),
					'raw'   => $datetime->format( 'Y-m-d' ),
				];
				$details['time'] = [
					'icon'  => 'clock',
					'label' => __( 'Time', 'client-sync' ),
					'value' => wp_date( get_option( 'time_format' ), $datetime->getTimestamp() ),
					'raw'   => $datetime->format( 'H:i' ),
				];
			} catch ( \Exception $e ) {
				Debug_Logger::log( 'Booking confirmation date parse failed: ' . $e->getMessage(), 'Confirmation' );
			}
		}

		// Get duration. Pass 0 as default so we can preserve the existing "hide the
		// duration line when unset" behavior via the truthy check below.
		$duration = Service_Helper::get_duration_minutes( (int) $appointment_id, 0 );
		if ( $duration ) {
			$hours = floor( $duration / 60 );
			$mins  = $duration % 60;
			$duration_text = '';
			if ( $hours > 0 ) {
				/* translators: %d: Number of hours */
				$duration_text .= sprintf( _n( '%d hour', '%d hours', $hours, 'client-sync' ), $hours );
			}
			if ( $mins > 0 ) {
				if ( $hours > 0 ) {
					$duration_text .= ' ';
				}
				/* translators: %d: Number of minutes */
				$duration_text .= sprintf( _n( '%d min', '%d mins', $mins, 'client-sync' ), $mins );
			}
			if ( $duration_text ) {
				$details['duration'] = [
					'icon'  => 'backup',
					'label' => __( 'Duration', 'client-sync' ),
					'value' => $duration_text,
				];
			}
		}

		// Get dimension details (Service, Practitioner, Room, etc.)
		$slot_dims = get_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, true );
		if ( is_array( $slot_dims ) && ! empty( $slot_dims ) ) {
			$dimension_types = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
			$registry        = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
			$filter_order    = $registry['filter_order'] ?? array_keys( $slot_dims );

			foreach ( $filter_order as $dim_slug ) {
				if ( ! isset( $slot_dims[ $dim_slug ] ) ) {
					continue;
				}
				$dim_id   = $slot_dims[ $dim_slug ];
				$dim_post = get_post( $dim_id );
				if ( ! $dim_post ) {
					continue;
				}

				$dim_type  = $dimension_types[ $dim_slug ] ?? [];
				$label     = $dim_type['singular'] ?? ucfirst( str_replace( [ 'clisyc_', '_' ], [ '', ' ' ], $dim_slug ) );
				$raw_icon  = $dim_type['icon'] ?? 'dashicons-admin-generic';

				// Build full CSS class string for the icon.
				// Dashicons stored as "dashicons-clipboard" need the base class prepended.
				// FontAwesome stored as "fas fa-briefcase" already have both classes.
				if ( 0 === strpos( $raw_icon, 'dashicons-' ) ) {
					$icon_classes = 'dashicons ' . $raw_icon;
				} else {
					$icon_classes = $raw_icon;
				}

				$details[ 'dim_' . $dim_slug ] = [
					'icon'         => str_replace( 'dashicons-', '', $raw_icon ),
					'icon_classes' => $icon_classes,
					'label'        => $label,
					'value'        => $dim_post->post_title,
				];
			}
		}

		return $details;
	}

	/**
	 * Generate calendar links.
	 *
	 * @param int $appointment_id Appointment post ID.
	 * @return array Calendar link data.
	 */
	private function get_calendar_links( $appointment_id ) {
		$time_slot = get_post_meta( $appointment_id, Constants::META_TIME_SLOT, true );
		$duration  = Service_Helper::get_duration_minutes( (int) $appointment_id );
		$title     = get_the_title( $appointment_id );

		if ( ! $time_slot ) {
			return [];
		}

		try {
			$start_dt = new \DateTime( $time_slot, wp_timezone() );
			$end_dt   = clone $start_dt;
			$end_dt->modify( '+' . absint( $duration ) . ' minutes' );

			// Convert to UTC for calendar links
			$start_utc = clone $start_dt;
			$start_utc->setTimezone( new \DateTimeZone( 'UTC' ) );
			$end_utc = clone $end_dt;
			$end_utc->setTimezone( new \DateTimeZone( 'UTC' ) );

			$description = sprintf(
				/* translators: %s: Site name */
				__( 'Appointment booked via %s', 'client-sync' ),
				get_bloginfo( 'name' )
			);

			$location = get_bloginfo( 'name' );

			// Google Calendar
			$google_dates = $start_utc->format( 'Ymd\THis\Z' ) . '/' . $end_utc->format( 'Ymd\THis\Z' );
			
			$links = [
				'google' => [
					'url'   => 'https://calendar.google.com/calendar/render?' . http_build_query( [
						'action'   => 'TEMPLATE',
						'text'     => $title,
						'dates'    => $google_dates,
						'details'  => $description,
						'location' => $location,
					] ),
					'label' => __( 'Google Calendar', 'client-sync' ),
					'icon'  => 'google',
				],
				'outlook' => [
					'url'   => 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query( [
						'path'    => '/calendar/action/compose',
						'rru'     => 'addevent',
						'subject' => $title,
						'startdt' => $start_utc->format( 'Y-m-d\TH:i:s\Z' ),
						'enddt'   => $end_utc->format( 'Y-m-d\TH:i:s\Z' ),
						'body'    => $description,
						'location'=> $location,
					] ),
					'label' => __( 'Outlook', 'client-sync' ),
					'icon'  => 'email-alt',
				],
				'ical' => [
					'url'   => $this->generate_ical_data_uri( $title, $start_utc, $end_utc, $description, $location ),
					'label' => __( 'Apple Calendar', 'client-sync' ),
					'icon'  => 'smartphone',
					'download' => 'appointment.ics',
				],
			];

			return $links;
		} catch ( \Exception $e ) {
			return [];
		}
	}

	/**
	 * Generate iCal data URI for download.
	 *
	 * @param string    $title       Event title.
	 * @param \DateTime $start       Start time (UTC).
	 * @param \DateTime $end         End time (UTC).
	 * @param string    $description Event description.
	 * @param string    $location    Event location.
	 * @return string Data URI.
	 */
	private function generate_ical_data_uri( $title, $start, $end, $description, $location ) {
		$uid = uniqid( 'clisyc-', true ) . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
		
		$ical = "BEGIN:VCALENDAR\r\n";
		$ical .= "VERSION:2.0\r\n";
		$ical .= "PRODID:-//ClientSync//Booking//EN\r\n";
		$ical .= "BEGIN:VEVENT\r\n";
		$ical .= "UID:{$uid}\r\n";
		$ical .= "DTSTAMP:" . gmdate( 'Ymd\THis\Z' ) . "\r\n";
		$ical .= "DTSTART:" . $start->format( 'Ymd\THis\Z' ) . "\r\n";
		$ical .= "DTEND:" . $end->format( 'Ymd\THis\Z' ) . "\r\n";
		$ical .= "SUMMARY:" . $this->escape_ical_text( $title ) . "\r\n";
		$ical .= "DESCRIPTION:" . $this->escape_ical_text( $description ) . "\r\n";
		$ical .= "LOCATION:" . $this->escape_ical_text( $location ) . "\r\n";
		$ical .= "END:VEVENT\r\n";
		$ical .= "END:VCALENDAR\r\n";

		return 'data:text/calendar;charset=utf-8,' . rawurlencode( $ical );
	}

	/**
	 * Escape text for iCal format.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	private function escape_ical_text( $text ) {
		$text = str_replace( [ '\\', ';', ',', "\n" ], [ '\\\\', '\\;', '\\,', '\\n' ], $text );
		return $text;
	}

	/**
	 * Render the confirmation UI.
	 *
	 * @param int   $appointment_id Appointment post ID.
	 * @param array $atts           Shortcode attributes.
	 * @return string HTML output.
	 */
	private function render_confirmation_ui( $appointment_id, $atts ) {
		$appointment = get_post( $appointment_id );
		if ( ! $appointment ) {
			return '';
		}

		$status_info    = $this->get_status_info( $appointment->post_status );
		$details        = $this->get_appointment_details( $appointment_id );
		$show_details   = filter_var( $atts['show_details'], FILTER_VALIDATE_BOOLEAN );
		$show_confetti  = filter_var( $atts['show_confetti'], FILTER_VALIDATE_BOOLEAN );
		$show_actions   = filter_var( $atts['show_actions'], FILTER_VALIDATE_BOOLEAN );
		$show_cal_links = filter_var( $atts['show_calendar_links'], FILTER_VALIDATE_BOOLEAN );
		$cal_links      = $show_cal_links ? $this->get_calendar_links( $appointment_id ) : [];
		$style_class    = 'clisyc-confirmation--' . sanitize_html_class( $atts['style'] );

		// Is this a "success" status? Include 'pending' because on WooCommerce thank you page,
		// payment is complete but appointment status may not have updated yet
		$is_success = in_array( $appointment->post_status, [ 'publish', 'confirmed', 'pending' ], true );

		ob_start();
		?>
		<div class="clisyc-booking-confirmation <?php echo esc_attr( $style_class ); ?>" data-status="<?php echo esc_attr( $status_info['class'] ); ?>">
			
			<?php if ( $show_confetti && $is_success ) : ?>
				<!-- Confetti Animation -->
				<div class="clisyc-confetti" aria-hidden="true">
					<?php for ( $i = 0; $i < 50; $i++ ) : ?>
						<div class="clisyc-confetti__piece"></div>
					<?php endfor; ?>
				</div>
			<?php endif; ?>

			<!-- Success Header -->
			<header class="clisyc-confirmation__header">
				<div class="clisyc-confirmation__icon clisyc-confirmation__icon--<?php echo esc_attr( $status_info['class'] ); ?>">
					<svg class="clisyc-confirmation__checkmark" viewBox="0 0 52 52" aria-hidden="true">
						<circle class="clisyc-confirmation__checkmark-circle" cx="26" cy="26" r="25" fill="none"/>
						<path class="clisyc-confirmation__checkmark-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
					</svg>
				</div>
				<h1 class="clisyc-confirmation__title"><?php echo esc_html( $atts['success_title'] ); ?></h1>
				<p class="clisyc-confirmation__message"><?php echo esc_html( $atts['success_message'] ); ?></p>
			</header>

			<?php if ( $show_details ) : ?>
				<!-- Appointment Card -->
				<article class="clisyc-confirmation__card">
					<!-- Card Header -->
					<div class="clisyc-confirmation__card-header">
						<h2 class="clisyc-confirmation__card-title">
							<?php echo esc_html( $appointment->post_title ); ?>
						</h2>
						<span class="clisyc-confirmation__status clisyc-confirmation__status--<?php echo esc_attr( $status_info['class'] ); ?>">
							<span class="dashicons dashicons-<?php echo esc_attr( $status_info['icon'] ); ?>"></span>
							<?php echo esc_html( $status_info['label'] ); ?>
						</span>
					</div>

					<!-- Date & Time Highlight -->
					<?php if ( isset( $details['date'] ) || isset( $details['time'] ) ) : ?>
						<div class="clisyc-confirmation__datetime">
							<?php if ( isset( $details['date'] ) ) : ?>
								<div class="clisyc-confirmation__datetime-item">
									<span class="clisyc-confirmation__datetime-icon">
										<span class="dashicons dashicons-<?php echo esc_attr( $details['date']['icon'] ); ?>"></span>
									</span>
									<div class="clisyc-confirmation__datetime-content">
										<span class="clisyc-confirmation__datetime-label"><?php echo esc_html( $details['date']['label'] ); ?></span>
										<span class="clisyc-confirmation__datetime-value"><?php echo esc_html( $details['date']['value'] ); ?></span>
									</div>
								</div>
							<?php endif; ?>
							<?php if ( isset( $details['time'] ) ) : ?>
								<div class="clisyc-confirmation__datetime-item">
									<span class="clisyc-confirmation__datetime-icon">
										<span class="dashicons dashicons-<?php echo esc_attr( $details['time']['icon'] ); ?>"></span>
									</span>
									<div class="clisyc-confirmation__datetime-content">
										<span class="clisyc-confirmation__datetime-label"><?php echo esc_html( $details['time']['label'] ); ?></span>
										<span class="clisyc-confirmation__datetime-value"><?php echo esc_html( $details['time']['value'] ); ?></span>
									</div>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<!-- Details List -->
					<?php
					// Remove date/time from details as they're shown above
					unset( $details['date'], $details['time'] );
					if ( ! empty( $details ) ) :
					?>
						<div class="clisyc-confirmation__details">
							<h3 class="clisyc-confirmation__details-heading">
								<?php esc_html_e( 'Appointment Details', 'client-sync' ); ?>
							</h3>
							<dl class="clisyc-confirmation__details-list">
								<?php foreach ( $details as $key => $detail ) : ?>
									<div class="clisyc-confirmation__details-item">
										<?php if ( ! empty( $detail['icon_classes'] ) ) : ?>
											<span class="clisyc-dim-icon"><span class="<?php echo esc_attr( $detail['icon_classes'] ); ?>"></span></span>
										<?php endif; ?>
										<dt class="clisyc-confirmation__details-label">
											<?php echo esc_html( $detail['label'] ); ?>
										</dt>
										<dd class="clisyc-confirmation__details-value">
											<?php echo esc_html( $detail['value'] ); ?>
										</dd>
									</div>
								<?php endforeach; ?>
							</dl>
						</div>
					<?php endif; ?>

					<!-- Calendar Links -->
					<?php if ( ! empty( $cal_links ) ) : ?>
						<div class="clisyc-confirmation__calendar">
							<span class="clisyc-confirmation__calendar-label">
								<span class="dashicons dashicons-calendar"></span>
								<?php esc_html_e( 'Add to Calendar', 'client-sync' ); ?>
							</span>
							<div class="clisyc-confirmation__calendar-buttons">
								<?php foreach ( $cal_links as $key => $link ) : ?>
									<a href="<?php echo esc_url( $link['url'] ); ?>" 
									   class="clisyc-confirmation__calendar-btn clisyc-confirmation__calendar-btn--<?php echo esc_attr( $key ); ?>"
									   target="_blank" 
									   rel="noopener noreferrer"
									   <?php echo isset( $link['download'] ) ? 'download="' . esc_attr( $link['download'] ) . '"' : ''; ?>>
										<?php echo esc_html( $link['label'] ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				</article>
			<?php endif; ?>

			<!-- Action Buttons -->
			<?php if ( $show_actions ) : ?>
				<footer class="clisyc-confirmation__actions">
					<?php
					$my_appointments_page = get_option( Constants::OPTION_MY_APPOINTMENTS_PAGE );
					if ( $my_appointments_page ) :
					?>
						<a href="<?php echo esc_url( get_permalink( $my_appointments_page ) ); ?>" 
						   class="clisyc-confirmation__btn clisyc-confirmation__btn--primary">
							<span class="dashicons dashicons-list-view"></span>
							<?php esc_html_e( 'View My Appointments', 'client-sync' ); ?>
						</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( home_url() ); ?>" 
					   class="clisyc-confirmation__btn clisyc-confirmation__btn--secondary">
						<?php esc_html_e( 'Return Home', 'client-sync' ); ?>
					</a>
				</footer>
			<?php endif; ?>

		</div>
		<?php
		return ob_get_clean();
	}
}