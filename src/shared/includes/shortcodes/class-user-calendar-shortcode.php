<?php
/**
 * Handles the [clisyc_user_appointments_calendar] and [clisyc_user_mini_calendar] shortcodes.
 *
 * Renders calendar views of user appointments (full and mini variants).
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class User_Calendar_Shortcode {

	/**
	 * Register the shortcodes.
	 */
	public function register() {
		add_shortcode( 'clisyc_user_appointments_calendar', [ $this, 'render_calendar' ] );
		add_shortcode( 'clisyc_user_mini_calendar', [ $this, 'render_mini_calendar' ] );
	}

	/**
	 * Render the full user appointments calendar shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_calendar( $atts ) {
		// --- START: NEW ASSET ENQUEUEING BLOCK ---
		wp_enqueue_style( 'clisyc-frontend-style' );
		wp_enqueue_script( 'clisyc-frontend-user-calendar' );
		$details_page_id = (int) get_option( Constants::OPTION_APPOINTMENT_VIEW_PAGE, 0 );
		wp_localize_script(
			'clisyc-frontend-user-calendar',
			'clisycUserCalendarData',
			[
				'ajaxurl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'clisyc_get_calendar_events_nonce' ),
				'timeZone'       => wp_timezone_string(),
				// CHANGED: elementId to mainElementId to match JS expectations
				'mainElementId'  => 'clisyc-user-appointments-calendar',
				// ADDED: miniElementId for consistency across views using this script
				'miniElementId'  => 'clisyc-user-mini-calendar',
				'enabledViews'   => get_option( Constants::OPTION_CALENDAR_ENABLED_VIEWS, [ 'dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek' ] ),
				'detailsPageUrl' => $details_page_id > 0 ? get_permalink( $details_page_id ) : null,
				'l10n'           => [
					'noEvents'        => __( 'No appointments on this day.', 'client-sync' ),
					'appointmentsFor' => __( 'Appointments for', 'client-sync' ),
				],
			]
		);
		// --- END: NEW ASSET ENQUEUEING BLOCK ---

		if ( ! is_user_logged_in() ) {
			$login_page_url = wp_login_url( get_permalink() );
			/* translators: %s: URL to the login page. */
			$login_prompt_text = __( 'Please <a href="%s">log in</a> to view your appointment calendar.', 'client-sync' );
			return '<p>' . sprintf( wp_kses( $login_prompt_text, [ 'a' => [ 'href' => [] ] ] ), esc_url( $login_page_url ) ) . '</p>';
		}

		ob_start();
		?>
		<div class="clisyc-container">
			<h2><?php esc_html_e( 'My Appointments Calendar', 'client-sync' ); ?></h2>
			<div id="clisyc-user-appointments-calendar" class="fc-initializing">
				 <p style="text-align:center; padding:50px;"><?php esc_html_e( 'Loading Calendar...', 'client-sync' ); ?></p>
			</div>
		</div>
		<?php
		$html_output = ob_get_clean();
		return $html_output;
	}

	/**
	 * Render the mini calendar shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_mini_calendar( $atts ) {
		// Re-use the existing script/style, we will just handle the logic in JS
		wp_enqueue_style( 'clisyc-frontend-style' );
		wp_enqueue_script( 'clisyc-frontend-user-calendar' );

		$details_page_id = (int) get_option( Constants::OPTION_APPOINTMENT_VIEW_PAGE, 0 );

		// Reuse the existing localization object
		wp_localize_script(
			'clisyc-frontend-user-calendar',
			'clisycUserCalendarData',
			[
				'ajaxurl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'clisyc_get_calendar_events_nonce' ),
				// We allow the JS to look for BOTH IDs now
				'mainElementId'  => 'clisyc-user-appointments-calendar',
				'miniElementId'  => 'clisyc-user-mini-calendar',
				'detailsPageUrl' => $details_page_id > 0 ? get_permalink( $details_page_id ) : null,
				'l10n'           => [
					'noEvents'        => __( 'No appointments on this day.', 'client-sync' ),
					'appointmentsFor' => __( 'Appointments for', 'client-sync' ),
				],
			]
		);

		if ( ! is_user_logged_in() ) {
			return ''; // Don't show anything if not logged in, or show a login link
		}

		ob_start();
		?>
		<div class="clisyc-mini-calendar-wrapper">
			<div id="clisyc-user-mini-calendar"></div>
			<!-- The container where details appear when a day is clicked -->
			<div id="clisyc-mini-calendar-details" class="clisyc-mini-details-list">
				<p class="clisyc-mini-hint"><?php esc_html_e( 'Select a date to view details.', 'client-sync' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
