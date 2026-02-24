<?php
/**
 * File: src/pro/includes/modules/seat-selection/class-qr-generator.php
 * Generates QR code PNG images for email embedding.
 *
 * Uses chillerlan/php-qrcode to create cached PNG files in the uploads
 * directory. Hooks into the notification placeholder system to inject
 * {ticket_qr_code} as an <img> tag pointing to the public URL.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Seat_Selection
 */

namespace ClientSyncPro\Modules\Seat_Selection;

use DependentMedia\ClientSync\Constants;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QR_Generator {

	/**
	 * Client-facing appointment events where QR code should be included.
	 */
	private const TICKET_EVENTS = [
		'new_appointment_client',
		'appointment_reminder',
	];

	/**
	 * Subdirectory inside wp-content/uploads for cached QR PNGs.
	 */
	private const UPLOAD_SUBDIR = 'clisyc-qr';

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		// Inject {ticket_qr_code} placeholder into notification data.
		add_filter( 'clisyc_notification_placeholder_data', [ $this, 'inject_qr_placeholder' ], 10, 4 );

		// Inject venue location placeholders ({venue_name}, {venue_address}, {venue_map_link}).
		add_filter( 'clisyc_notification_placeholder_data', [ $this, 'inject_venue_placeholders' ], 10, 4 );

		// Ensure the uploads sub-directory exists.
		add_action( 'init', [ $this, 'ensure_upload_directory' ], 20 );
	}

	/**
	 * Inject the {ticket_qr_code} placeholder into notification data.
	 *
	 * For client-facing appointment events, generates a cached QR code PNG
	 * and returns an <img> tag. For all other events, returns an empty string.
	 *
	 * @param array  $data       Placeholder data array.
	 * @param string $event_type Notification event type.
	 * @param int    $object_id  Related object ID (appointment ID).
	 * @param array  $extra_data Additional event data.
	 * @return array Modified placeholder data.
	 */
	public function inject_qr_placeholder( array $data, string $event_type, int $object_id, array $extra_data ): array {
		// Default: empty string so the placeholder is always defined.
		$data['{ticket_qr_code}'] = '';

		if ( ! in_array( $event_type, self::TICKET_EVENTS, true ) ) {
			return $data;
		}

		// Check if this appointment has a QR token (i.e. it has seats).
		$qr_token = get_post_meta( $object_id, Constants::META_QR_TOKEN, true );
		if ( empty( $qr_token ) ) {
			return $data;
		}

		// Build the check-in URL payload (same format as the frontend QR code).
		$payload = add_query_arg( 'clisyc_checkin', $qr_token, home_url( '/' ) );

		// Generate or retrieve cached PNG.
		$png_url = $this->get_or_create_qr_png( $payload );
		if ( empty( $png_url ) ) {
			return $data;
		}

		// Build an email-safe <img> tag.
		$data['{ticket_qr_code}'] = sprintf(
			'<div style="text-align:center;margin:20px 0;">'
			. '<img src="%s" alt="%s" width="200" height="200" style="display:inline-block;border:2px solid #e2e8f0;border-radius:12px;padding:8px;background:#ffffff;" />'
			. '<p style="margin:8px 0 0;font-size:13px;color:#64748b;">%s</p>'
			. '</div>',
			esc_url( $png_url ),
			esc_attr__( 'QR Code Ticket', 'client-sync-pro' ),
			esc_html__( 'Show this QR code at the venue for check-in.', 'client-sync-pro' )
		);

		return $data;
	}

	/**
	 * Inject venue location placeholders into notification data.
	 *
	 * Adds {venue_name}, {venue_address}, and {venue_map_link} placeholders.
	 * These resolve the appointment's linked venue and return formatted address
	 * data and a Google Maps link for email templates.
	 *
	 * @param array  $data       Placeholder data array.
	 * @param string $event_type Notification event type.
	 * @param int    $object_id  Related object ID (appointment ID).
	 * @param array  $extra_data Additional event data.
	 * @return array Modified placeholder data.
	 */
	public function inject_venue_placeholders( array $data, string $event_type, int $object_id, array $extra_data ): array {
		// Default: empty strings so placeholders are always defined.
		$data['{venue_name}']     = '';
		$data['{venue_address}']  = '';
		$data['{venue_map_link}'] = '';

		// Only inject for appointment-related events.
		$appointment_events = [
			'new_appointment_client',
			'new_appointment_manager',
			'appointment_reminder',
			'appointment_status_change',
			'appointment_cancelled',
			'appointment_rescheduled',
		];

		if ( ! in_array( $event_type, $appointment_events, true ) ) {
			return $data;
		}

		// Get venue location via the existing filter (populated by Seat_Booking_Handler).
		$venue_location = apply_filters( 'clisyc_appointment_venue_location', [], $object_id );
		if ( empty( $venue_location ) ) {
			return $data;
		}

		// {venue_name}
		if ( ! empty( $venue_location['venue_name'] ) ) {
			$data['{venue_name}'] = $venue_location['venue_name'];
		}

		// {venue_address} — formatted multi-line address.
		$address_parts = [];
		if ( ! empty( $venue_location['address'] ) ) {
			$address_parts[] = $venue_location['address'];
		}
		$city_line = array_filter( [
			$venue_location['city'] ?? '',
			$venue_location['state'] ?? '',
			$venue_location['postal_code'] ?? '',
		] );
		if ( ! empty( $city_line ) ) {
			$address_parts[] = implode( ', ', $city_line );
		}
		if ( ! empty( $venue_location['country'] ) ) {
			$address_parts[] = $venue_location['country'];
		}
		if ( ! empty( $address_parts ) ) {
			$data['{venue_address}'] = implode( "\n", $address_parts );
		}

		// {venue_map_link} — Google Maps search link based on the address.
		$map_query = implode( ', ', array_filter( [
			$venue_location['address'] ?? '',
			$venue_location['city'] ?? '',
			$venue_location['state'] ?? '',
			$venue_location['postal_code'] ?? '',
			$venue_location['country'] ?? '',
		] ) );
		if ( ! empty( $map_query ) ) {
			$data['{venue_map_link}'] = 'https://www.google.com/maps/search/' . rawurlencode( $map_query );
		}

		return $data;
	}

	/**
	 * Get or create a cached QR code PNG for the given payload.
	 *
	 * @param string $payload QR code payload (URL).
	 * @return string Public URL to the PNG, or empty string on failure.
	 */
	private function get_or_create_qr_png( string $payload ): string {
		if ( ! class_exists( QRCode::class ) ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		$dir_path   = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;
		$dir_url    = trailingslashit( $upload_dir['baseurl'] ) . self::UPLOAD_SUBDIR;

		// Filename is hash of the payload for cache keying.
		$filename = 'qr-' . md5( $payload ) . '.png';
		$filepath = trailingslashit( $dir_path ) . $filename;
		$fileurl  = trailingslashit( $dir_url ) . $filename;

		// Return cached file if it exists.
		if ( file_exists( $filepath ) ) {
			return $fileurl;
		}

		// Generate QR code PNG.
		try {
			$options = new QROptions( [
				'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
				'eccLevel'     => QRCode::ECC_M,
				'scale'        => 10,
				'imageBase64'  => false,
				'quietzoneSize' => 2,
			] );

			$qr_code = new QRCode( $options );
			$png_data = $qr_code->render( $payload );

			// Write to file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = file_put_contents( $filepath, $png_data );
			if ( false === $written ) {
				return '';
			}

			return $fileurl;
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Ensure the QR code uploads sub-directory exists with an index.php guard.
	 */
	public function ensure_upload_directory(): void {
		$upload_dir = wp_upload_dir();
		$dir_path   = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;

		if ( ! is_dir( $dir_path ) ) {
			wp_mkdir_p( $dir_path );

			// Add index.php to prevent directory listing.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir_path . '/index.php', "<?php\n// Silence is golden.\n" );
		}
	}
}
