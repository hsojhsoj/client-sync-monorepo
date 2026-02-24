<?php
/**
 * File: src/shared/includes/core/notification-channels/class-email-channel.php
 * Implements the default notification channel for sending standard WordPress emails.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core/NotificationChannels
 */
namespace DependentMedia\ClientSync\Core\NotificationChannels;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Interfaces\Notification_Channel_Interface;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Channel implements Notification_Channel_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'email';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Email', 'client-sync' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function send( string $event_type, array $placeholder_data, array $recipients ): bool {
		$all_settings   = get_option( Constants::OPTION_NOTIFICATION_SETTINGS, [] );
		$event_settings = $all_settings[ $event_type ] ?? [];
		$email_sent     = false;

		foreach ( $recipients as $recipient ) {
			if ( empty( $recipient['email'] ) || empty( $recipient['type'] ) ) {
				continue;
			}

			$recipient_type = $recipient['type'];
			if ( empty( $event_settings[ $recipient_type . '_enabled' ] ) ) {
				continue;
			}

			$subject_template = $event_settings[ $recipient_type . '_subject' ] ?? '';
			$body_template    = $event_settings[ $recipient_type . '_body' ] ?? '';

			$subject   = $this->process_placeholders( $subject_template, $placeholder_data );
			$body_html = wpautop( $this->process_placeholders( $body_template, $placeholder_data ) );
			$body      = $this->wrap_in_template( $body_html, $subject );
			$headers   = $this->get_email_headers();

			if ( ! empty( $subject ) && ! empty( trim( $body ) ) ) {
				$max_attempts = 3;
				for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
					if ( wp_mail( $recipient['email'], $subject, $body, $headers ) ) {
						$email_sent = true;
						break;
					}
					if ( $attempt < $max_attempts ) {
						Debug_Logger::log( 'wp_mail failed for recipient on event ' . $event_type . ' (attempt ' . $attempt . '/' . $max_attempts . '). Retrying...', 'Email' );
						sleep( 1 );
					} else {
						Debug_Logger::log( 'wp_mail failed for recipient on event ' . $event_type . ' after ' . $max_attempts . ' attempts.', 'Email' );
					}
				}
			}
		}
		return $email_sent;
	}

	/**
	 * Replaces placeholder tags with actual data.
	 */
	private function process_placeholders( string $template, array $data ): string {
		$clean_data = [];
		foreach ( $data as $key => $value ) {
			if ( is_scalar( $value ) || ( is_object( $value ) && method_exists( $value, '__toString' ) ) ) {
				$clean_data[ $key ] = (string) $value;
			}
		}

		if ( ! empty( $clean_data ) ) {
			$template = str_replace( array_keys( $clean_data ), array_values( $clean_data ), $template );
		}

		// Clean up any remaining, un-replaced placeholders.
		$template = preg_replace( '/\{[a-zA-Z0-9_]+\}/', '', $template );
		return $template;
	}

	/**
	 * Gets the standard email headers.
	 */
	private function get_email_headers(): array {
		$from_name  = get_option( 'clisyc_email_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'clisyc_email_from_address', '' ) ?: get_option( 'admin_email' );
		if ( ! is_email( $from_email ) ) {
			$from_email = get_option( 'admin_email' );
		}
		return [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		];
	}

	/**
	 * Wraps email body content in a responsive HTML template.
	 *
	 * Uses table-based layout with inline CSS for cross-client compatibility
	 * (Gmail, Outlook, Apple Mail, mobile). The accent color is pulled from the
	 * calendar color settings so the email branding matches the booking calendar.
	 *
	 * @param string $body_html The processed email body (already through wpautop).
	 * @param string $subject   The email subject line (used in preheader).
	 * @return string Complete HTML document ready for wp_mail().
	 */
	private function wrap_in_template( string $body_html, string $subject ): string {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$site_url  = esc_url( home_url() );

		// Reuse the calendar accent color for brand consistency.
		$color_settings = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
		$accent         = $color_settings['available_bg'] ?? '#4f46e5';
		$accent_text    = $color_settings['available_text'] ?? '#ffffff';

		$preheader = esc_html( wp_strip_all_tags( $subject ) );

		$html = <<<HTML
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$preheader}</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
<!-- Preheader text (hidden, shows in inbox preview) -->
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{$preheader}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f7;">
<tr><td align="center" style="padding:24px 16px;">

<!-- Email Container -->
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">

	<!-- Header -->
	<tr>
		<td style="background-color:{$accent};padding:24px 32px;text-align:center;">
			<a href="{$site_url}" style="color:{$accent_text};text-decoration:none;font-size:22px;font-weight:600;letter-spacing:-0.02em;">{$site_name}</a>
		</td>
	</tr>

	<!-- Body -->
	<tr>
		<td style="background-color:#ffffff;padding:32px;font-size:15px;line-height:1.6;color:#374151;">
			{$body_html}
		</td>
	</tr>

	<!-- Footer -->
	<tr>
		<td style="background-color:#f9fafb;padding:20px 32px;text-align:center;font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb;">
			&copy; {$site_name} &middot; <a href="{$site_url}" style="color:#9ca3af;text-decoration:underline;">{$site_url}</a>
		</td>
	</tr>

</table>
<!-- /Email Container -->

</td></tr>
</table>
</body>
</html>
HTML;

		/**
		 * Filters the complete HTML email template.
		 *
		 * @param string $html      The full HTML document.
		 * @param string $body_html The inner body content (pre-template).
		 * @param string $subject   The email subject line.
		 */
		return apply_filters( 'clisyc_email_template', $html, $body_html, $subject );
	}
}